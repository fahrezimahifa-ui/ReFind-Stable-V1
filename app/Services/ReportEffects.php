<?php

namespace App\Services;

use App\Database\PinnedMariaDbConnection;
use RuntimeException;

/** All effects use the coordinator-owned connection and existing ordered parent locks. */
final class ReportEffects
{
    public function __construct(private PinnedMariaDbConnection $db, private RuleMatcher $matcher) {}

    private function confirmed(int $id): bool
    {
        return (bool) $this->db->selectOne("SELECT match_id FROM item_matches WHERE match_status='CONFIRMED' AND (lost_item_id=? OR found_item_id=?) LIMIT 1", [$id, $id]);
    }

    private function suggested(int $id): bool
    {
        return (bool) $this->db->selectOne("SELECT match_id FROM item_matches WHERE match_status='SUGGESTED' AND (lost_item_id=? OR found_item_id=?) LIMIT 1", [$id, $id]);
    }

    private function history(array $item, int $actor, string $note): void
    {
        $this->db->insert('INSERT INTO item_status_history(item_id,status_id,changed_by_user_id,note) VALUES (?,?,?,?)', [(int) $item['item_id'], (int) $item['status_id'], $actor, mb_substr($note, 0, 500)]);
    }

    private function notify(int $owner, string $type, string $title, string $message, ?int $itemId = null): void
    {
        $this->db->insert('INSERT INTO notifications(user_id,notification_type,title,message,item_id,claim_id) VALUES (?,?,?,?,?,?)', [$owner, $type, $title, mb_substr($message, 0, 500), $itemId, null]);
    }

    private function status(array &$item, string $code, int $actor, string $note, bool $notify): void
    {
        if ($item['status_code'] === $code) {
            return;
        }
        // Automatic reporting effects can only move between the native idle states.
        if (! in_array($code, ['OPEN', 'POTENTIAL_MATCH'], true)) {
            throw new RuntimeException('Unsupported automatic report status.');
        }
        $status = $this->db->selectOne('SELECT status_id FROM item_statuses WHERE status_code=?', [$code]);
        if (! $status) {
            throw new RuntimeException('Unknown status.');
        }
        $this->db->update('UPDATE items SET status_id=?,resolved_at=? WHERE item_id=?', [(int) $status->status_id, null, (int) $item['item_id']]);
        $item['status_id'] = $status->status_id;
        $item['status_code'] = $code;
        $this->history($item, $actor, $note);
        if ($notify) {
            $this->notify((int) $item['reporter_user_id'], 'STATUS_CHANGED', 'Possible match found', 'A report that may match yours was added. Open it to compare the details; ownership is not verified yet.', (int) $item['item_id']);
        }
    }

    public function saved(array $item, array &$locked, int $actor, bool $created): void
    {
        if (! config('refind.report_effects')) {
            return;
        }
        $this->db->assertOwnedTransaction();
        $id = (int) $item['item_id'];
        $this->history($item, $actor, $created ? 'Report submitted.' : 'Report details updated by the reporter.');
        if ($created) {
            $this->notify((int) $item['reporter_user_id'], 'REPORT_SUBMITTED', 'Report submitted', 'Your '.strtolower($item['item_type']).' report has been saved.', $id);
        }
        $this->db->delete("DELETE FROM item_matches WHERE match_status='SUGGESTED' AND (lost_item_id=? OR found_item_id=?)", [$id, $id]);
        $paired = [];
        if (in_array($item['status_code'], RuleMatcher::ACTIVE_CODES, true) && ! $this->confirmed($id)) {
            foreach ($locked as $candidate) {
                $other = (int) $candidate['item_id'];
                if ($other === $id || $candidate['item_type'] === $item['item_type'] || ! in_array($candidate['status_code'], RuleMatcher::ACTIVE_CODES, true) || $this->confirmed($other)) {
                    continue;
                }
                $lost = $item['item_type'] === 'LOST' ? $item : $candidate;
                $found = $item['item_type'] === 'FOUND' ? $item : $candidate;
                $score = $this->matcher->suggestionScore($lost, $found);
                if ($score === null) {
                    continue;
                }
                $pair = [(int) $lost['item_id'], (int) $found['item_id']];
                // MariaDB's duplicate-key path requires identity-column UPDATE privileges.
                // Parent rows and the advisory lock are already owned; keep those columns immutable.
                $existing = $this->db->selectOne('SELECT match_status FROM item_matches WHERE lost_item_id=? AND found_item_id=? FOR UPDATE', $pair);
                if (! $existing) {
                    $this->db->insert('INSERT INTO item_matches(lost_item_id,found_item_id,match_score) VALUES (?,?,?)', [...$pair, $score]);
                } elseif ($existing->match_status === 'SUGGESTED') {
                    $this->db->update("UPDATE item_matches SET match_score=? WHERE lost_item_id=? AND found_item_id=? AND match_status='SUGGESTED'", [$score, ...$pair]);
                }
                $paired[$other] = true;
            }
        }
        foreach ($locked as &$report) {
            $other = (int) $report['item_id'];
            if (! in_array($report['status_code'], ['OPEN', 'POTENTIAL_MATCH'], true) || $this->confirmed($other)) {
                continue;
            }
            $has = $this->suggested($other);
            $notify = $has && ($other === $id || isset($paired[$other]));
            $this->status($report, $has ? 'POTENTIAL_MATCH' : 'OPEN', $actor, $has ? 'Rule-based possible matches are available. Ownership is not verified.' : 'No current matching suggestions.', $notify);
        }
        unset($report);
    }

    public function deleted(array $item, array &$locked, int $actor): void
    {
        if (! config('refind.report_effects')) {
            return;
        }
        $this->db->assertOwnedTransaction();
        $this->notify($actor, 'REPORT_DELETED', 'Report deleted', 'Your report "'.mb_substr($item['title'], 0, 120).'" was deleted.');
        foreach ($locked as &$candidate) {
            $id = (int) $candidate['item_id'];
            if ($candidate['status_code'] === 'POTENTIAL_MATCH' && ! $this->confirmed($id) && ! $this->suggested($id)) {
                $this->status($candidate, 'OPEN', $actor, 'No current matching suggestions.', false);
            }
        }
        unset($candidate);
    }
}
