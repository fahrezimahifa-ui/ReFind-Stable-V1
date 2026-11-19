<?php

namespace App\Services;

use App\Exceptions\ContractError;
use App\Foundation\MediaJournal;
use App\Foundation\WriteContext;
use App\Foundation\WriteCoordinator;
use App\Repositories\StaffReportRepository;
use App\Support\ClaimStages;
use App\Support\Contract;
use Illuminate\Http\Request;
use Throwable;

/**
 * Phase 8 BM manual report status (native app/workflow.php staff_status at 47eea86). Reports with an active
 * claim, confirmed pair or pickup are blocked; final states remove suggestions and settle neighbours; the reporter
 * receives one OPERATIONAL_NOTE carrying the status and BM note.
 */
final class StaffStatusService
{
    public function __construct(private MediaJournal $journal, private WriteCoordinator $writes, private WorkflowEffects $effects) {}

    private function field(string $key, string $message): never
    {
        throw new ContractError($message, 422, 'validation_error', [$key => $message]);
    }

    public function mutate(array $user, Request $request, ?callable $fault = null, ?callable $commitTransport = null): array
    {
        if (! ClaimStages::has('status')) {
            throw new ContractError('This operation is not available in the proof of concept.', 501, 'feature_pending');
        }
        if (($user['role'] ?? null) !== 'BUILDING_MANAGEMENT') {
            throw new ContractError('This action is not available to your role.', 403, 'forbidden');
        }
        $data = $request->request->all();
        Contract::allowed($data, ['action', 'id', 'status', 'note', 'revision', 'confirm', '_csrf']);
        $id = Contract::id($data['id'] ?? null);
        $status = strtoupper(Contract::text($data, 'status', 40));
        $note = Contract::text($data, 'note', 500);
        if (mb_strlen($note) < 8) {
            $this->field('note', 'Provide a reason of at least 8 characters.');
        }
        if (($data['confirm'] ?? '') !== '1') {
            $this->field('confirm', 'Confirm this operation explicitly.');
        }
        $actor = (int) $user['user_id'];
        $operation = $this->journal->create();
        try {
            return $this->writes->run($operation, function (WriteContext $context) use ($data, $id, $status, $note, $actor) {
                $db = $context->connection;
                $locked = array_map(fn ($row) => (array) $row, $context->lockItems($this->effects->matchingRelatedIds([$id])));
                $item = &$locked[$id];
                $history = (int) $db->selectOne('SELECT COALESCE(MAX(history_id),0) AS id FROM item_status_history WHERE item_id=?', [$id])->id;
                $expected = $data['revision'] ?? '';
                if (! is_string($expected) || ! preg_match('/^[a-f0-9]{64}$/D', $expected) || ! hash_equals(StaffReportRepository::revision($item, $history), $expected)) {
                    throw new ContractError('This record changed. Refresh the detail before deciding again.', 409, 'state_conflict');
                }
                $blocked = $this->effects->confirmedMatch($id) !== null || (bool) $db->selectOne("SELECT claim_id FROM claims WHERE (item_id=? OR related_lost_item_id=?) AND claim_status IN ('PENDING','NEED_MORE_INFO','APPROVED') LIMIT 1", [$id, $id]);
                $allowed = in_array($item['status_code'], ['RESOLVED', 'UNCLAIMED_CLOSED', 'HANDOVER'], true) ? [] : StaffReportRepository::choices($item, $blocked, ! $blocked && $this->effects->hasSuggestion($id));
                if (! in_array($status, $allowed, true)) {
                    throw new ContractError('Finish the active claim/handover or choose an eligible transition.', 409, 'state_conflict');
                }
                $this->effects->statusChange($item, $status, $actor, $status === 'RESOLVED' ? 'Building Management recorded recovery of this Lost report.' : 'Operational status updated by Building Management.', false);
                if (in_array($status, ['RESOLVED', 'UNCLAIMED_CLOSED'], true)) {
                    $db->delete("DELETE FROM item_matches WHERE match_status='SUGGESTED' AND (lost_item_id=? OR found_item_id=?)", [$id, $id]);
                    unset($item);
                    $this->effects->settlePotentialStates($locked, $actor);
                    $item = &$locked[$id];
                }
                // One update per decision: the new status and Building Management's note travel together.
                $this->effects->notify((int) $item['reporter_user_id'], 'OPERATIONAL_NOTE', 'Report updated: '.WorkflowEffects::NAMES[$status], $note, $id);

                return ['id' => (string) $id, 'status' => $status];
            }, $fault, $commitTransport);
        } catch (Throwable $error) {
            throw ClaimService::nativeConflict($error, 'The workflow changed while saving. Refresh and retry.', 'Another workflow is saving. Please retry.');
        }
    }
}
