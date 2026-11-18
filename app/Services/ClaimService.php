<?php

namespace App\Services;

use App\Exceptions\ContractError;
use App\Foundation\MediaJournal;
use App\Foundation\MediaOperation;
use App\Foundation\UploadStager;
use App\Foundation\WriteContext;
use App\Foundation\WriteCoordinator;
use App\Foundation\WriteFailure;
use App\Repositories\ItemReadRepository;
use App\Support\ClaimStages;
use App\Support\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Phase 6C Student claim commands, ported from native app/claims.php (mutate_claim / withdraw_claim) at
 * 47eea86. Validation order, status codes, messages, history and notifications follow native exactly.
 * Intentional strengthening: every write takes the shared per-schema workflow advisory lock through the
 * coordinator (native Student commands rely on row locks only).
 */
final class ClaimService
{
    public const ACTIVE_ITEM_CODES = ['OPEN', 'UNDER_REVIEW', 'POTENTIAL_MATCH', 'VERIFICATION'];

    public const RESUBMITTABLE = ['NEED_MORE_INFO', 'WITHDRAWN'];

    private const CONFLICT = 'The claim changed while saving. Reload and retry.';

    public function __construct(private ItemReadRepository $items, private MediaJournal $journal, private UploadStager $stager, private WriteCoordinator $writes, private WorkflowEffects $effects) {}

    private function field(string $key, string $message): never
    {
        throw new ContractError($message, 422, 'validation_error', [$key => $message]);
    }

    /** Fault collaborators are PHP-only test seams; no request field selects them. */
    public function mutate(array $user, Request $request, ?callable $fault = null, ?callable $commitTransport = null): array
    {
        if (! ClaimStages::has('student')) {
            throw new ContractError('This operation is not available in the proof of concept.', 501, 'feature_pending');
        }
        if (($user['role'] ?? null) !== 'STUDENT') {
            throw new ContractError('This action is not available to your role.', 403, 'forbidden');
        }
        $data = $request->request->all();
        if (($data['action'] ?? '') === 'withdraw') {
            return $this->withdraw($user, $data, $fault, $commitTransport);
        }
        $actor = (int) $user['user_id'];
        // Ownership checks precede general field validation, without trusting a target user ID.
        $lostId = isset($data['lostItemId']) && $data['lostItemId'] !== '' ? Contract::id($data['lostItemId']) : null;
        if ($lostId) {
            $lost = $this->items->findItem($lostId);
            if ($lost['item_type'] !== 'LOST' || (int) $lost['reporter_user_id'] !== $actor) {
                throw new ContractError('Choose one of your own Lost reports.', 403, 'forbidden');
            }
        }
        $itemId = Contract::id($data['itemId'] ?? null);
        $found = $this->items->findItem($itemId);
        if ($found['item_type'] !== 'FOUND' || (int) $found['reporter_user_id'] === $actor) {
            throw new ContractError('You cannot claim this report.', 409, 'state_conflict');
        }
        Contract::allowed($data, ['action', 'itemId', 'lostItemId', 'answer', 'keep_photo_ids', '_csrf']);
        Contract::allowed($request->files->all(), ['ownershipPhotos']);
        $action = $data['action'] ?? 'submit';
        if (! in_array($action, ['submit', 'resubmit'], true)) {
            throw new ContractError('Invalid claim action.', 400);
        }
        $answer = Contract::text($data, 'answer', 1000);
        if (mb_strlen($answer) < 8) {
            $this->field('answer', 'Provide at least 8 characters of identifying detail.');
        }
        $uploads = $this->files($request, 'ownershipPhotos', 2);
        $operation = $this->journal->create();
        $started = false;
        try {
            $staged = [];
            foreach ($uploads as $file) {
                $staged[] = $this->stage($file, $operation);
            }
            $started = true;

            return $this->writes->run($operation, function (WriteContext $context) use ($data, $actor, $action, $answer, $itemId, $lostId, $staged, $operation) {
                $db = $context->connection;
                $locked = array_map(fn ($row) => (array) $row, $context->lockItems($lostId ? [$itemId, $lostId] : [$itemId]));
                $found = &$locked[$itemId];
                if ($found['item_type'] !== 'FOUND' || (int) $found['reporter_user_id'] === $actor || ! in_array($found['status_code'], self::ACTIVE_ITEM_CODES, true) || $this->effects->confirmedMatch($itemId)) {
                    throw new ContractError('This item is no longer eligible for your claim.', 409, 'state_conflict');
                }
                if ($lostId) {
                    $lost = $locked[$lostId];
                    if ($lost['item_type'] !== 'LOST' || (int) $lost['reporter_user_id'] !== $actor) {
                        throw new ContractError('Choose one of your own Lost reports.', 403, 'forbidden');
                    }
                    if (! in_array($lost['status_code'], self::ACTIVE_ITEM_CODES, true) || $this->effects->confirmedMatch($lostId)) {
                        throw new ContractError('That Lost report is already assigned or closed.', 409, 'state_conflict');
                    }
                    // One Lost report can back only one claim under review at a time.
                    if ($db->selectOne("SELECT claim_id FROM claims WHERE related_lost_item_id=? AND item_id<>? AND claim_status IN ('PENDING','NEED_MORE_INFO') LIMIT 1 FOR UPDATE", [$lostId, $itemId])) {
                        $this->field('lostItemId', 'This Lost report is already linked to another claim under review. Withdraw that claim first or choose another report.');
                    }
                }
                $existing = $db->selectOne('SELECT * FROM claims WHERE item_id=? AND claimant_user_id=? FOR UPDATE', [$itemId, $actor]);
                if ($existing && $existing->claim_status === 'REJECTED') {
                    throw new ContractError('Your claim for this item was rejected. A rejection is final for this item.', 409, 'claim_final');
                }
                if ($existing && ! in_array($existing->claim_status, self::RESUBMITTABLE, true)) {
                    throw new ContractError('You already have an active or approved claim for this item.', 409, 'duplicate_claim');
                }
                if ($action === 'resubmit' && ! $existing) {
                    throw new ContractError('Claim to update not found.', 404, 'not_found');
                }
                $old = $existing ? $db->select('SELECT claim_photo_id,file_path FROM claim_photos WHERE claim_id=? ORDER BY claim_photo_id FOR UPDATE', [(int) $existing->claim_id]) : [];
                $oldIds = array_map(fn ($p) => (int) $p->claim_photo_id, $old);
                $keep = $this->retained($data, 'keep_photo_ids', $oldIds, 2);
                if (array_diff($keep, $oldIds)) {
                    throw new ContractError('A retained photo does not belong to your claim.', 403, 'forbidden');
                }
                if (count($keep) + count($staged) > 2) {
                    $this->field('ownershipPhotos', 'Use at most 2 ownership photos including retained evidence.');
                }
                if ($existing) {
                    $claimId = (int) $existing->claim_id;
                    foreach ($old as $photo) {
                        if (! in_array((int) $photo->claim_photo_id, $keep, true)) {
                            $db->delete('DELETE FROM claim_photos WHERE claim_photo_id=?', [(int) $photo->claim_photo_id]);
                            $operation->removeAfterCommit($photo->file_path);
                        }
                    }
                    $db->update("UPDATE claims SET ownership_answer=?,related_lost_item_id=?,claim_status='PENDING',reviewed_by_user_id=NULL,review_note=NULL,reviewed_at=NULL WHERE claim_id=?", [$answer, $lostId, $claimId]);
                } else {
                    $db->insert('INSERT INTO claims(item_id,related_lost_item_id,claimant_user_id,ownership_answer) VALUES (?,?,?,?)', [$itemId, $lostId, $actor, $answer]);
                    $claimId = (int) $db->getPdo()->lastInsertId();
                }
                foreach ($staged as $path) {
                    $db->insert('INSERT INTO claim_photos(claim_id,file_path) VALUES (?,?)', [$claimId, $path]);
                }
                $prior = $found['status_code'];
                $this->effects->statusChange($found, 'VERIFICATION', $actor, 'Ownership claims are awaiting manual review by Building Management.');
                if ($prior === 'VERIFICATION') {
                    $this->effects->history($found, $actor, $existing ? 'Ownership claim updated for review.' : 'Ownership claim submitted for review.');
                }
                $this->effects->notify($actor, 'CLAIM_SUBMITTED', $existing ? 'Claim resubmitted' : 'Claim submitted', 'Your identifying detail was sent to Building Management for manual review.', $itemId, $claimId);
                foreach ($this->effects->activeBmIds() as $staff) {
                    $this->effects->notify($staff, 'CLAIM_QUEUED', $existing ? 'Claim resubmitted for review' : 'New claim to review', $existing ? 'A claimant updated their ownership details.' : 'An ownership claim is waiting for review.', $itemId, $claimId);
                }

                return ['id' => (string) $claimId, 'resubmitted' => $existing !== null];
            }, $fault, $commitTransport);
        } catch (Throwable $error) {
            if (! $started) {
                try {
                    $this->writes->reconcile($operation);
                } catch (Throwable) {/* Preserve the pending manifest when recovery cannot be proved. */
                }
            }
            throw self::nativeConflict($error);
        }
    }

    private function withdraw(array $user, array $data, ?callable $fault, ?callable $commitTransport): array
    {
        Contract::allowed($data, ['action', 'itemId', '_csrf']);
        $itemId = Contract::id($data['itemId'] ?? null);
        $actor = (int) $user['user_id'];
        $operation = $this->journal->create();
        try {
            return $this->writes->run($operation, function (WriteContext $context) use ($itemId, $actor) {
                $db = $context->connection;
                $locked = array_map(fn ($row) => (array) $row, $context->lockItems([$itemId]));
                $found = &$locked[$itemId];
                $claim = $db->selectOne('SELECT * FROM claims WHERE item_id=? AND claimant_user_id=? FOR UPDATE', [$itemId, $actor]);
                if (! $claim) {
                    throw new ContractError('Claim not found.', 404, 'not_found');
                }
                if (! in_array($claim->claim_status, ['PENDING', 'NEED_MORE_INFO'], true)) {
                    throw new ContractError('Only a claim that is still under review can be withdrawn.', 409, 'state_conflict');
                }
                $db->update("UPDATE claims SET claim_status='WITHDRAWN' WHERE claim_id=?", [(int) $claim->claim_id]);
                $remaining = (int) $db->selectOne("SELECT COUNT(*) AS n FROM claims WHERE item_id=? AND claim_status IN ('PENDING','NEED_MORE_INFO')", [$itemId])->n;
                if ($found['status_code'] === 'VERIFICATION' && $remaining === 0) {
                    $this->effects->settleIdle($found, $actor, 'Ownership claim withdrawn by the claimant.');
                } else {
                    $this->effects->history($found, $actor, 'Ownership claim withdrawn; other claims remain under review.');
                }

                return ['id' => (string) $claim->claim_id, 'status' => 'WITHDRAWN', 'resubmitted' => false];
            }, $fault, $commitTransport);
        } catch (Throwable $error) {
            throw self::nativeConflict($error);
        }
    }

    /**
     * A rolled-back recoverable conflict keeps the native message; only scalar data is copied so the caught
     * failure (and its media operation) is released. Uncertain outcomes keep the reconciliation contract.
     */
    public static function nativeConflict(Throwable $error, string $message = self::CONFLICT): Throwable
    {
        if ($error instanceof WriteFailure && ! $error->uncertain && $error->status === 409) {
            return new ContractError($message, 409, 'state_conflict');
        }

        return $error;
    }

    private function stage(UploadedFile $file, MediaOperation $operation): string
    {
        try {
            return $this->stager->stage($file, 'claims', $operation);
        } catch (ContractError $e) {
            $message = match ($e->getMessage()) {
                'Upload incomplete.' => 'Upload incomplete. Please choose the image again.',
                'Use a valid prepared JPEG, PNG or WebP image.' => 'Use a valid JPEG, PNG or WebP image with a maximum side of 1200px.',
                default => $e->getMessage(),
            };
            throw new ContractError($message, $e->status, $e->errorCode, ['ownershipPhotos' => $message]);
        }
    }

    private function files(Request $r, string $key, int $limit): array
    {
        $files = $r->allFiles()[$key] ?? [];
        if (! is_array($files) || ! array_is_list($files)) {
            $this->field($key, 'Invalid photo collection.');
        }
        if (count($files) > $limit) {
            $this->field($key, 'Use at most '.$limit.' photos.');
        }
        $out = [];
        foreach ($files as $file) {
            if ($file === null) {
                continue;
            }
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                $this->field($key, 'Upload incomplete. Please choose the image again.');
            }
            $out[] = $file;
        }

        return $out;
    }

    private function retained(array $data, string $key, array $default, int $limit): array
    {
        if (! isset($data[$key])) {
            return array_map('intval', $default);
        }
        if (! is_string($data[$key])) {
            $this->field($key, 'Invalid retained-photo selection.');
        }
        try {
            $ids = json_decode($data[$key], true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->field($key, 'Invalid retained-photo selection.');
        }
        if (! is_array($ids) || ! array_is_list($ids) || count($ids) > $limit) {
            $this->field($key, 'Invalid retained-photo selection.');
        }
        $ids = array_map(fn ($id) => Contract::id($id), $ids);
        if (count(array_unique($ids)) !== count($ids)) {
            $this->field($key, 'Do not select the same photo twice.');
        }

        return $ids;
    }
}
