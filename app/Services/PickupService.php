<?php

namespace App\Services;

use App\Exceptions\ContractError;
use App\Foundation\MediaJournal;
use App\Foundation\WriteContext;
use App\Foundation\WriteCoordinator;
use App\Support\ClaimStages;
use App\Support\Contract;
use App\Support\PickupEvent;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/**
 * Phase 7 BM pickup operations, ported from native app/workflow.php mutate_handover, release_approved_pickup and
 * pickup_audit at 47eea86: schedule, reschedule, cancel the appointment (ownership stays approved), confirm the
 * physical return (RESOLVED), and release (ownership revoked, reports available again).
 */
final class PickupService
{
    private const CONFLICT = 'The workflow changed while saving. Refresh and retry.';

    private const BUSY = 'Another workflow is saving. Please retry.';

    private const TITLES = ['schedule' => 'Pickup scheduled', 'reschedule' => 'Pickup rescheduled', 'cancel_schedule' => 'Pickup schedule cancelled', 'complete' => 'Item returned', 'release' => 'Pickup released'];

    public function __construct(private MediaJournal $journal, private WriteCoordinator $writes, private WorkflowEffects $effects) {}

    private function field(string $key, string $message): never
    {
        throw new ContractError($message, 422, 'validation_error', [$key => $message]);
    }

    private static function conflict(string $message): ContractError
    {
        return new ContractError($message, 409, 'state_conflict');
    }

    /** Native valid_schedule: a future Asia/Jakarta 'Y-m-d\TH:i' converted to a UTC DATETIME. */
    private function schedule(string $value): string
    {
        $zone = new DateTimeZone('Asia/Jakarta');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, $zone);
        if (! $date || $date->format('Y-m-d\TH:i') !== $value || substr($value, 0, 4) < '1000' || $date->getTimestamp() <= time()) {
            $this->field('scheduled_at', 'Choose a valid future pickup date/time in Asia/Jakarta.');
        }

        return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** Fault collaborators are PHP-only test seams; no request field selects them. */
    public function mutate(array $user, Request $request, ?callable $fault = null, ?callable $commitTransport = null): array
    {
        if (! ClaimStages::has('pickup')) {
            throw new ContractError('This operation is not available in the proof of concept.', 501, 'feature_pending');
        }
        if (($user['role'] ?? null) !== 'BUILDING_MANAGEMENT') {
            throw new ContractError('This action is not available to your role.', 403, 'forbidden');
        }
        $data = $request->request->all();
        Contract::allowed($data, ['action', 'id', 'pickup_location_id', 'scheduled_at', 'note', 'revision', 'confirm', '_csrf']);
        $id = Contract::id($data['id'] ?? null);
        $action = Contract::text($data, 'action', 30);
        if (! in_array($action, ['schedule', 'reschedule', 'cancel_schedule', 'complete', 'release'], true)) {
            throw new ContractError('Invalid pickup operation.', 400);
        }
        if (($data['confirm'] ?? '') !== '1') {
            $this->field('confirm', 'Confirm this operation explicitly.');
        }
        $noteRequired = in_array($action, ['reschedule', 'cancel_schedule', 'release'], true);
        $note = Contract::text($data, 'note', 250, $noteRequired);
        if ($noteRequired && mb_strlen($note) < 8) {
            $this->field('note', 'Provide a reason of at least 8 characters.');
        }
        $location = null;
        $scheduledAt = null;
        if (in_array($action, ['schedule', 'reschedule'], true)) {
            if (trim((string) ($data['pickup_location_id'] ?? '')) === '') {
                $this->field('pickup_location_id', 'Choose a pickup area.');
            }
            if (trim((string) ($data['scheduled_at'] ?? '')) === '') {
                $this->field('scheduled_at', 'Choose a pickup date and time.');
            }
            $location = Contract::id($data['pickup_location_id']);
            if (! \Illuminate\Support\Facades\DB::selectOne("SELECT location_id FROM campus_locations WHERE location_id=? AND location_kind='AREA' AND is_active=1", [$location])) {
                $this->field('pickup_location_id', 'Choose an active campus pickup area.');
            }
            $scheduledAt = $this->schedule(Contract::text($data, 'scheduled_at', 16));
        }
        $actor = (int) $user['user_id'];
        $operation = $this->journal->create();
        try {
            return $this->writes->run($operation, fn (WriteContext $context) => $this->apply($context, $data, $id, $action, $note, $location, $scheduledAt, $actor), $fault, $commitTransport);
        } catch (Throwable $error) {
            throw ClaimService::nativeConflict($error, self::CONFLICT, self::BUSY);
        }
    }

    private function apply(WriteContext $context, array $data, int $id, string $action, string $note, ?int $location, ?string $scheduledAt, int $actor): array
    {
        $db = $context->connection;
        // Discovery read under the shared workflow advisory lock held by every Laravel writer.
        $original = $db->selectOne('SELECT h.claim_id,c.item_id,c.related_lost_item_id FROM handovers h JOIN claims c ON c.claim_id=h.claim_id WHERE h.handover_id=?', [$id]);
        if (! $original) {
            throw new ContractError('Pickup record not found.', 404, 'not_found');
        }
        $ids = [(int) $original->item_id];
        if ($original->related_lost_item_id !== null) {
            $ids[] = (int) $original->related_lost_item_id;
        }
        $locked = array_map(fn ($row) => (array) $row, $context->lockItems($ids));
        $row = $db->selectOne('SELECT h.*,c.item_id,c.related_lost_item_id,c.claimant_user_id,c.claim_status FROM handovers h JOIN claims c ON c.claim_id=h.claim_id WHERE h.handover_id=? FOR UPDATE', [$id]);
        $handover = $row ? (array) $row : null;
        if (! $handover || (int) $handover['claim_id'] !== (int) $original->claim_id || (int) $handover['item_id'] !== (int) $original->item_id || $handover['related_lost_item_id'] !== $original->related_lost_item_id) {
            throw self::conflict('Pickup relation changed. Refresh first.');
        }
        $expected = $data['revision'] ?? '';
        if (! is_string($expected) || ! preg_match('/^[a-f0-9]{64}$/D', $expected) || ! hash_equals(PickupEvent::revision($handover), $expected)) {
            throw self::conflict('This record changed. Refresh the detail before deciding again.');
        }
        if ($handover['claim_status'] !== 'APPROVED' || $handover['handover_status'] === 'COMPLETED') {
            throw self::conflict('Only an active approved pickup can be changed.');
        }
        $foundId = (int) $handover['item_id'];
        $lostId = $handover['related_lost_item_id'] !== null ? (int) $handover['related_lost_item_id'] : null;
        if ($locked[$foundId]['item_type'] !== 'FOUND' || $locked[$foundId]['status_code'] !== 'HANDOVER') {
            throw self::conflict('The item is not ready for pickup.');
        }
        if ($lostId) {
            $pair = $this->effects->confirmedMatch($foundId);
            if (! $pair || (int) $pair->lost_item_id !== $lostId || $locked[$lostId]['status_code'] !== 'HANDOVER') {
                throw self::conflict('The confirmed pair is inconsistent.');
            }
        }
        $status = $handover['handover_status'];
        if (($action === 'schedule' && $status !== 'PENDING') || (in_array($action, ['reschedule', 'cancel_schedule'], true) && $status !== 'SCHEDULED') || (in_array($action, ['complete', 'release'], true) && ! in_array($status, ['PENDING', 'SCHEDULED'], true))) {
            throw self::conflict('That operation does not match the current pickup state.');
        }
        // Time may pass while waiting for locks: repeat the future-time check here.
        if ($scheduledAt && (new DateTimeImmutable($scheduledAt, new DateTimeZone('UTC')))->getTimestamp() <= time()) {
            $this->field('scheduled_at', 'Choose a future pickup date/time.');
        }
        if ($action === 'reschedule' && (int) $handover['pickup_location_id'] === $location && $handover['scheduled_at'] === $scheduledAt) {
            throw self::conflict('Choose a different pickup time or location before rescheduling.');
        }

        if (in_array($action, ['schedule', 'reschedule'], true)) {
            $db->update("UPDATE handovers SET handover_status='SCHEDULED',pickup_location_id=?,scheduled_at=?,handled_by_user_id=?,notes=? WHERE handover_id=?", [$location, $scheduledAt, $actor, $note !== '' ? $note : 'Pickup scheduled by BM.', $id]);
        } elseif ($action === 'cancel_schedule') {
            $db->update("UPDATE handovers SET handover_status='PENDING',scheduled_at=NULL,handled_by_user_id=?,notes=? WHERE handover_id=?", [$actor, $note, $id]);
        } elseif ($action === 'complete') {
            $db->update("UPDATE handovers SET handover_status='COMPLETED',completed_at=UTC_TIMESTAMP(),handled_by_user_id=?,notes=? WHERE handover_id=?", [$actor, $note !== '' ? $note : 'Physical return confirmed by BM.', $id]);
        } else {
            // Release revokes ownership; cancelling an appointment never reaches this branch.
            $db->update("UPDATE claims SET claim_status='REJECTED',reviewed_by_user_id=?,review_note=?,reviewed_at=UTC_TIMESTAMP() WHERE claim_id=?", [$actor, 'Pickup released: '.$note, (int) $handover['claim_id']]);
            if ($lostId) {
                $db->update("UPDATE item_matches SET match_status='REJECTED',confirmed_by_user_id=?,confirmed_at=UTC_TIMESTAMP() WHERE lost_item_id=? AND found_item_id=? AND match_status='CONFIRMED'", [$actor, $lostId, $foundId]);
            }
            $db->delete('DELETE FROM handovers WHERE handover_id=?', [$id]);
        }
        $claimant = (int) $handover['claimant_user_id'];
        foreach ($locked as &$item) {
            $notifyFinder = (int) $item['reporter_user_id'] !== $claimant;
            if ($action === 'complete') {
                $this->effects->statusChange($item, 'RESOLVED', $actor, 'Verified item returned; handover completed.', $notifyFinder);
            } elseif ($action === 'release') {
                $this->effects->settleIdle($item, $actor, 'Pickup released by Building Management; the report is available again.', $notifyFinder);
            } else {
                $this->effects->history($item, $actor, $action === 'cancel_schedule' ? 'Pickup appointment cancelled; verified ownership retained.' : 'Pickup appointment scheduled or updated.');
            }
        }
        unset($item);
        $after = $action === 'release'
            ? ['handover_status' => 'RELEASED', 'pickup_location_id' => null, 'scheduled_at' => null, 'claim_id' => $handover['claim_id']]
            : (array) $db->selectOne('SELECT * FROM handovers WHERE handover_id=?', [$id]);
        $this->audit($handover, $after, $action, $note, $actor, $claimant, $foundId);

        return ['id' => (string) $id, 'status' => $after['handover_status']];
    }

    /** Native pickup_audit: one private message to the claimant and every other active BM. */
    private function audit(array $before, array $after, string $action, string $note, int $actor, int $claimant, int $itemId): void
    {
        $summary = fn (array $h) => $h['handover_status'].' / location '.($h['pickup_location_id'] ?? 'none').' / '.($h['scheduled_at'] ? (new DateTimeImmutable($h['scheduled_at'], new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z') : 'unscheduled');
        $message = 'BM #'.$actor.' '.$action.'. Before: '.$summary($before).'. After: '.$summary($after).'.'.($note !== '' ? ' Reason: '.$note : '');
        if (mb_strlen($message) > 500) {
            throw new RuntimeException('Pickup audit exceeds storage contract.');
        }
        // The acting BM already sees the result on screen; other BMs and the claimant are told.
        $recipients = array_values(array_filter($this->effects->activeBmIds(), fn ($bm) => $bm !== $actor));
        $recipients[] = $claimant;
        foreach (array_unique($recipients) as $target) {
            $this->effects->notify($target, 'HANDOVER_'.strtoupper($action), self::TITLES[$action], $message, $itemId, (int) $after['claim_id']);
        }
    }
}
