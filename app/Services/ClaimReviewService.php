<?php

namespace App\Services;

use App\Exceptions\ContractError;
use App\Foundation\MediaJournal;
use App\Foundation\WriteContext;
use App\Foundation\WriteCoordinator;
use App\Support\ClaimRevision;
use App\Support\ClaimStages;
use App\Support\Contract;
use Illuminate\Http\Request;
use Throwable;

/**
 * Phase 6D/6E BM claim review, ported from native app/workflow.php review_claim and create_approved_pickup at
 * 47eea86: request more information, reject, and approve with competing-claim closure, confirmed pair and one
 * pending handover. Pickup scheduling, release and physical return stay outside Phase 6.
 */
final class ClaimReviewService
{
    private const CONFLICT = 'The workflow changed while saving. Refresh and retry.';

    private const BUSY = 'Another workflow is saving. Please retry.';

    private const SHARED = "SELECT claim_id,item_id,claimant_user_id FROM claims WHERE related_lost_item_id=? AND claim_id<>? AND claim_status IN ('PENDING','NEED_MORE_INFO') ORDER BY claim_id";

    public function __construct(private MediaJournal $journal, private WriteCoordinator $writes, private WorkflowEffects $effects) {}

    private function field(string $key, string $message): never
    {
        throw new ContractError($message, 422, 'validation_error', [$key => $message]);
    }

    private static function conflict(string $message): ContractError
    {
        return new ContractError($message, 409, 'state_conflict');
    }

    /** Fault collaborators are PHP-only test seams; no request field selects them. */
    public function review(array $user, Request $request, ?callable $fault = null, ?callable $commitTransport = null): array
    {
        if (($user['role'] ?? null) !== 'BUILDING_MANAGEMENT') {
            throw new ContractError('This action is not available to your role.', 403, 'forbidden');
        }
        $data = $request->request->all();
        Contract::allowed($data, ['action', 'id', 'decision', 'note', 'revision', 'confirm', '_csrf']);
        $id = Contract::id($data['id'] ?? null);
        $decision = Contract::text($data, 'decision', 30);
        if (! in_array($decision, ['approved', 'rejected', 'need_more_info'], true)) {
            $this->field('decision', 'Choose Approve, Reject or Request more info.');
        }
        $note = Contract::text($data, 'note', 500);
        if (mb_strlen($note) < 8) {
            $this->field('note', 'Provide a reason of at least 8 characters.');
        }
        if (($data['confirm'] ?? '') !== '1') {
            $this->field('confirm', 'Confirm this operation explicitly.');
        }
        if (! in_array($decision, ClaimStages::decisions(), true)) {
            throw new ContractError('This operation is not available in the proof of concept.', 501, 'feature_pending');
        }
        $actor = (int) $user['user_id'];
        $operation = $this->journal->create();
        try {
            return $this->writes->run($operation, fn (WriteContext $context) => $this->decide($context, $data, $id, $decision, $note, $actor), $fault, $commitTransport);
        } catch (Throwable $error) {
            throw ClaimService::nativeConflict($error, self::CONFLICT, self::BUSY);
        }
    }

    private function decide(WriteContext $context, array $data, int $id, string $decision, string $note, int $actor): array
    {
        $db = $context->connection;
        // Discovery reads run under the shared workflow advisory lock that every Laravel writer holds.
        $original = $db->selectOne('SELECT item_id,related_lost_item_id FROM claims WHERE claim_id=?', [$id]);
        if (! $original) {
            throw new ContractError('Claim not found.', 404, 'not_found');
        }
        $ids = [(int) $original->item_id];
        if ($original->related_lost_item_id !== null) {
            $ids[] = (int) $original->related_lost_item_id;
        }
        // Older data can hold other claims that reuse this Lost report; approval closes them, so their Found reports are locked too.
        $shared = $decision === 'approved' && $original->related_lost_item_id !== null ? $db->select(self::SHARED, [(int) $original->related_lost_item_id, $id]) : [];
        foreach ($shared as $other) {
            $ids[] = (int) $other->item_id;
        }
        $ids = $this->effects->matchingRelatedIds($ids);
        // Parent reports are locked in numeric order before rereading the claim.
        $locked = array_map(fn ($row) => (array) $row, $context->lockItems($ids));
        $row = $db->selectOne('SELECT * FROM claims WHERE claim_id=? FOR UPDATE', [$id]);
        $claim = $row ? (array) $row : null;
        if (! $claim || (int) $claim['item_id'] !== (int) $original->item_id || $claim['related_lost_item_id'] !== $original->related_lost_item_id) {
            throw self::conflict('Claim relation changed. Refresh the queue.');
        }
        $expected = $data['revision'] ?? '';
        if (! is_string($expected) || ! preg_match('/^[a-f0-9]{64}$/D', $expected) || ! hash_equals(ClaimRevision::compute($db, $claim), $expected)) {
            throw self::conflict('This record changed. Refresh the detail before deciding again.');
        }
        if (! in_array($claim['claim_status'], ['PENDING', 'NEED_MORE_INFO'], true) || ($decision === 'need_more_info' && $claim['claim_status'] !== 'PENDING')) {
            throw self::conflict('This claim is no longer eligible for that decision.');
        }
        $foundId = (int) $claim['item_id'];
        $found = &$locked[$foundId];
        $lostId = $claim['related_lost_item_id'] !== null ? (int) $claim['related_lost_item_id'] : null;
        if ($found['item_type'] !== 'FOUND' || ! in_array($found['status_code'], ClaimService::ACTIVE_ITEM_CODES, true) || $this->effects->confirmedMatch($foundId)) {
            throw self::conflict('This item has already been assigned or closed.');
        }
        if ($lostId && ($locked[$lostId]['item_type'] !== 'LOST' || (int) $locked[$lostId]['reporter_user_id'] !== (int) $claim['claimant_user_id'])) {
            throw self::conflict('Linked Lost relation is invalid.');
        }
        $active = $db->select("SELECT * FROM claims WHERE item_id=? AND claim_status IN ('PENDING','NEED_MORE_INFO') ORDER BY claim_id FOR UPDATE", [$foundId]);
        $status = ['approved' => 'APPROVED', 'rejected' => 'REJECTED', 'need_more_info' => 'NEED_MORE_INFO'][$decision];
        if ($decision === 'approved') {
            if ($lostId && (! in_array($locked[$lostId]['status_code'], ClaimService::ACTIVE_ITEM_CODES, true) || $this->effects->confirmedMatch($lostId))) {
                throw self::conflict('The linked Lost report already has an owner or terminal outcome.');
            }
            if ($db->selectOne("SELECT claim_id FROM claims WHERE item_id=? AND claim_status='APPROVED' LIMIT 1", [$foundId])) {
                throw self::conflict('Another claim has already been approved.');
            }
        }
        $db->update('UPDATE claims SET claim_status=?,reviewed_by_user_id=?,review_note=?,reviewed_at=UTC_TIMESTAMP() WHERE claim_id=?', [$status, $actor, $note, $id]);
        $handoverId = null;
        if ($decision === 'approved') {
            $handoverId = $this->createApprovedPickup($context, $locked, $claim, $actor);
            foreach ($active as $other) {
                if ((int) $other->claim_id === $id) {
                    continue;
                }
                $reason = 'Another ownership claim was verified by Building Management.';
                $db->update("UPDATE claims SET claim_status='REJECTED',reviewed_by_user_id=?,review_note=?,reviewed_at=UTC_TIMESTAMP() WHERE claim_id=?", [$actor, $reason, (int) $other->claim_id]);
                $this->effects->notify((int) $other->claimant_user_id, 'CLAIM_REJECTED', 'Claim closed', $reason, $foundId, (int) $other->claim_id);
            }
            if ($lostId) {
                $sharedNow = $db->select(str_replace('ORDER BY claim_id', 'ORDER BY claim_id FOR UPDATE', self::SHARED), [$lostId, $id]);
                // Recheck the discovered set after locking; never update an unlocked parent report.
                if (array_diff(array_map(fn ($c) => (int) $c->claim_id, $sharedNow), array_map(fn ($c) => (int) $c->claim_id, $shared))) {
                    throw self::conflict('Claims linked to this Lost report changed. Refresh and retry.');
                }
                $reason = 'Closed because your linked Lost report was matched to another approved claim.';
                foreach ($sharedNow as $other) {
                    $db->update("UPDATE claims SET claim_status='REJECTED',reviewed_by_user_id=?,review_note=?,reviewed_at=UTC_TIMESTAMP() WHERE claim_id=?", [$actor, $reason, (int) $other->claim_id]);
                    $otherFound = &$locked[(int) $other->item_id];
                    if (! $db->selectOne("SELECT claim_id FROM claims WHERE item_id=? AND claim_status IN ('PENDING','NEED_MORE_INFO') LIMIT 1", [(int) $other->item_id]) && $otherFound['status_code'] === 'VERIFICATION') {
                        $this->effects->settleIdle($otherFound, $actor, 'Ownership claim closed after its Lost report was matched elsewhere.');
                    }
                    unset($otherFound);
                }
                if ($sharedNow) {
                    $this->effects->notify((int) $claim['claimant_user_id'], 'CLAIM_REJECTED', 'Other claims closed', count($sharedNow).' other claim(s) that used the same Lost report were closed because this claim was approved.', $foundId, (int) $sharedNow[0]->claim_id);
                }
            }
            $assigned = $lostId ? [$foundId, $lostId] : [$foundId];
            $marks = implode(',', array_fill(0, count($assigned), '?'));
            $db->delete("DELETE FROM item_matches WHERE match_status='SUGGESTED' AND (lost_item_id IN ($marks) OR found_item_id IN ($marks))", [...$assigned, ...$assigned]);
            unset($found);
            $this->effects->settlePotentialStates($locked, $actor);
            $this->effects->notify((int) $claim['claimant_user_id'], 'CLAIM_APPROVED', 'Claim approved', $note, $foundId, $id);
        } elseif ($decision === 'rejected') {
            $previous = $found['status_code'];
            if (count($active) > 1) {
                $this->effects->history($found, $actor, 'Ownership claim rejected; other claims remain under review.');
            } else {
                $this->effects->settleIdle($found, $actor, 'Ownership claim rejected after manual review.');
            }
            if ($previous === $found['status_code'] && count($active) <= 1) {
                $this->effects->history($found, $actor, 'Ownership claim rejected after manual review.');
            }
            $this->effects->notify((int) $claim['claimant_user_id'], 'CLAIM_REJECTED', 'Claim rejected', $note, $foundId, $id);
        } else {
            $previous = $found['status_code'];
            $this->effects->statusChange($found, 'VERIFICATION', $actor, 'Ownership review requested additional detail.');
            if ($previous === 'VERIFICATION') {
                $this->effects->history($found, $actor, 'Ownership review requested additional detail.');
            }
            $this->effects->notify((int) $claim['claimant_user_id'], 'CLAIM_NEEDS_INFO', 'More info requested', $note, $foundId, $id);
        }

        return ['id' => (string) $id, 'status' => $status, 'handover_id' => $handoverId];
    }

    /**
     * Confirms the optional pair at the native recomputed score, moves the reports to HANDOVER and creates the one
     * pending handover. A locked read with explicit INSERT or UPDATE replaces the native upsert so no pair identity
     * column needs an UPDATE grant. Like native, an existing same-pair REJECTED row becomes CONFIRMED (flagged for
     * sign-off); automatic matching keeps its terminal-pair protection.
     */
    private function createApprovedPickup(WriteContext $context, array &$locked, array $claim, int $actor): string
    {
        $db = $context->connection;
        $foundId = (int) $claim['item_id'];
        $found = &$locked[$foundId];
        $lostId = $claim['related_lost_item_id'] !== null ? (int) $claim['related_lost_item_id'] : null;
        if ($lostId) {
            $lost = &$locked[$lostId];
            $score = $this->effects->score($lost, $found);
            $pair = $db->selectOne('SELECT match_id FROM item_matches WHERE lost_item_id=? AND found_item_id=? FOR UPDATE', [$lostId, $foundId]);
            if ($pair) {
                $db->update("UPDATE item_matches SET match_score=?,match_status='CONFIRMED',confirmed_by_user_id=?,confirmed_at=UTC_TIMESTAMP() WHERE match_id=?", [$score, $actor, (int) $pair->match_id]);
            } else {
                $db->insert("INSERT INTO item_matches(lost_item_id,found_item_id,match_score,match_status,confirmed_by_user_id,confirmed_at) VALUES (?,?,?,'CONFIRMED',?,UTC_TIMESTAMP())", [$lostId, $foundId, $score, $actor]);
            }
            $this->effects->statusChange($lost, 'HANDOVER', $actor, 'Ownership verified; matched item is ready for pickup.', false);
            unset($lost);
        }
        $this->effects->statusChange($found, 'HANDOVER', $actor, 'Ownership verified by Building Management. Item is ready for pickup.');
        $db->insert("INSERT INTO handovers(claim_id,pickup_location_id,handled_by_user_id,notes) VALUES (?,?,?,'Awaiting a pickup appointment.')", [(int) $claim['claim_id'], $found['location_id'] ? (int) $found['location_id'] : null, $actor]);
        // Later history/notification inserts also change the insert ID, so capture it immediately.
        $handoverId = (string) $db->getPdo()->lastInsertId();
        unset($found);

        return $handoverId;
    }
}
