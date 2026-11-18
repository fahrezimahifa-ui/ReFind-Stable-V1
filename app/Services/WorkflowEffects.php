<?php

namespace App\Services;

use App\Database\PinnedMariaDbConnection;
use RuntimeException;

/**
 * Native v1.0 claim/review status, history and notification effects (native app/repository.php and
 * app/workflow.php helpers). Every write uses the coordinator-owned pinned connection inside its owned
 * transaction and relies on the caller's ordered parent locks. Report effects stay in ReportEffects.
 */
final class WorkflowEffects
{
    /** Phase 6 transitions only; RESOLVED/UNCLAIMED_CLOSED belong to the later return/closure slices. */
    private const ALLOWED = ['OPEN', 'POTENTIAL_MATCH', 'VERIFICATION', 'HANDOVER'];

    public const NAMES = ['OPEN' => 'Reported', 'UNDER_REVIEW' => 'Under review', 'POTENTIAL_MATCH' => 'Possible match', 'VERIFICATION' => 'Claim review',
        'HANDOVER' => 'Ready for pickup', 'RESOLVED' => 'Returned', 'UNCLAIMED_CLOSED' => 'Never retrieved'];

    public function __construct(private PinnedMariaDbConnection $db, private RuleMatcher $matcher) {}

    public function confirmedMatch(int $id): ?object
    {
        return $this->db->selectOne("SELECT * FROM item_matches WHERE match_status='CONFIRMED' AND (lost_item_id=? OR found_item_id=?) LIMIT 1", [$id, $id]);
    }

    public function hasSuggestion(int $id): bool
    {
        return (bool) $this->db->selectOne("SELECT match_id FROM item_matches WHERE match_status='SUGGESTED' AND (lost_item_id=? OR found_item_id=?) LIMIT 1", [$id, $id]);
    }

    public function history(array $item, int $actor, string $note): void
    {
        $this->db->assertOwnedTransaction();
        $this->db->insert('INSERT INTO item_status_history(item_id,status_id,changed_by_user_id,note) VALUES (?,?,?,?)', [(int) $item['item_id'], (int) $item['status_id'], $actor, mb_substr($note, 0, 500)]);
    }

    public function notify(int $user, string $type, string $title, string $message, ?int $itemId = null, ?int $claimId = null): void
    {
        $this->db->assertOwnedTransaction();
        $this->db->insert('INSERT INTO notifications(user_id,notification_type,title,message,item_id,claim_id) VALUES (?,?,?,?,?,?)', [$user, $type, $title, mb_substr($message, 0, 500), $itemId, $claimId]);
    }

    public function statusChange(array &$item, string $code, int $actor, string $note, bool $notify = true): void
    {
        if ($item['status_code'] === $code) {
            return;
        }
        if (! in_array($code, self::ALLOWED, true)) {
            throw new RuntimeException('Unsupported Phase 6 report status.');
        }
        $this->db->assertOwnedTransaction();
        $status = $this->db->selectOne('SELECT status_id FROM item_statuses WHERE status_code=?', [$code]);
        if (! $status) {
            throw new RuntimeException('Unknown status.');
        }
        $this->db->update('UPDATE items SET status_id=?,resolved_at=? WHERE item_id=?', [(int) $status->status_id, null, (int) $item['item_id']]);
        $item['status_id'] = $status->status_id;
        $item['status_code'] = $code;
        $this->history($item, $actor, $note);
        if (! $notify) {
            return;
        }
        if ($code === 'POTENTIAL_MATCH') {
            $this->notify((int) $item['reporter_user_id'], 'STATUS_CHANGED', 'Possible match found', 'A report that may match yours was added. Open it to compare the details; ownership is not verified yet.', (int) $item['item_id']);
        } else {
            $this->notify((int) $item['reporter_user_id'], 'STATUS_CHANGED', self::NAMES[$code], 'Your report is now: '.self::NAMES[$code].'.', (int) $item['item_id']);
        }
    }

    /** Moves an active report back to its idle state once no claim is reviewing it. */
    public function settleIdle(array &$item, int $actor, string $note, bool $notify = false): void
    {
        $this->statusChange($item, $this->hasSuggestion((int) $item['item_id']) ? 'POTENTIAL_MATCH' : 'OPEN', $actor, $note, $notify);
    }

    public function settlePotentialStates(array &$locked, int $actor): void
    {
        foreach ($locked as &$item) {
            if ($item['status_code'] !== 'POTENTIAL_MATCH' || $this->confirmedMatch((int) $item['item_id'])) {
                continue;
            }
            if (! $this->hasSuggestion((int) $item['item_id'])) {
                $this->statusChange($item, 'OPEN', $actor, 'No current matching suggestions.', false);
            }
        }
        unset($item);
    }

    /** One hop of SUGGESTED neighbours, so cleanup never touches an unlocked parent report. */
    public function matchingRelatedIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (! $ids) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        foreach ($this->db->select("SELECT lost_item_id,found_item_id FROM item_matches WHERE match_status='SUGGESTED' AND (lost_item_id IN ($marks) OR found_item_id IN ($marks))", [...$ids, ...$ids]) as $match) {
            $ids[] = (int) $match->lost_item_id;
            $ids[] = (int) $match->found_item_id;
        }

        return array_values(array_unique($ids));
    }

    public function activeBmIds(): array
    {
        return array_map(fn ($r) => (int) $r->user_id, $this->db->select("SELECT user_id FROM users WHERE role='BUILDING_MANAGEMENT' AND is_active=1"));
    }

    public function score(array $lost, array $found): int
    {
        return $this->matcher->score($lost, $found);
    }
}
