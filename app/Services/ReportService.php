<?php

namespace App\Services;

use App\Exceptions\ContractError;
use App\Foundation\MediaJournal;
use App\Foundation\UploadStager;
use App\Foundation\WriteContext;
use App\Foundation\WriteCoordinator;
use App\Repositories\ItemReadRepository;
use App\Support\Contract;
use App\Support\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ReportService
{
    public function __construct(private ItemReadRepository $repo, private MediaJournal $journal, private UploadStager $stager, private WriteCoordinator $writes, private ReportEffects $effects) {}

    private function field(string $key, string $message): never
    {
        throw new ContractError($message, 422, 'validation_error', [$key => $message]);
    }

    private function values(array $data): array
    {
        $title = Contract::text($data, 'title', 120);
        $description = Contract::text($data, 'description', 2000);
        $clue = Contract::text($data, 'clue', 1000);
        if (mb_strlen($title) < 3) {
            $this->field('title', 'Use at least 3 characters for the item name.');
        }
        if (mb_strlen($clue) < 8) {
            $this->field('clue', 'Give at least 8 characters so Building Management can verify the owner.');
        }
        $category = Contract::id($data['category_id'] ?? null);
        $location = Contract::id($data['location_id'] ?? null);
        $db = DB::connection();
        if (! $db->selectOne('SELECT category_id FROM categories WHERE category_id=? AND is_active=1', [$category])) {
            $this->field('category_id', 'Choose an active category.');
        }
        if (! $db->selectOne("SELECT location_id FROM campus_locations WHERE location_id=? AND location_kind='AREA' AND is_active=1", [$location])) {
            $this->field('location_id', 'Choose an active campus area.');
        }
        $date = Contract::text($data, 'date', 10);
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (! $parsed || $parsed->format('Y-m-d') !== $date || substr($date, 0, 4) < '1000') {
            $this->field('date', 'Enter a valid event date.');
        }
        if ($date > $this->repo->campusToday()) {
            $this->field('date', 'The event date cannot be in the future.');
        }
        $time = Contract::text($data, 'time', 5, false);
        if ($time !== '' && ! preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/D', $time)) {
            $this->field('time', 'Enter a valid time or leave it unknown.');
        }

        return [$title, $description, $category, $location, Contract::text($data, 'location_detail', 500, false), $date, $time !== '' ? $time.':00' : null, $clue];
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
            }if (! $file instanceof UploadedFile || ! $file->isValid()) {
                $this->field($key, 'Upload incomplete. Please choose the image again.');
            }$out[] = $file;
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

    /** Fault collaborators are PHP-only test seams; no request field selects them. */
    public function mutate(array $user, Request $request, ?callable $fault = null, ?callable $commitTransport = null): array
    {
        if (! in_array(Workspace::profile()['profile'], config('refind.report_write_profiles'), true)) {
            throw new ContractError('Reporting is not available in this checkpoint.', 501, 'feature_pending');
        }
        if (($user['role'] ?? null) !== 'STUDENT') {
            throw new ContractError('This action is not available to your role.', 403, 'forbidden');
        }
        $data = $request->request->all();
        $action = Contract::text($data, 'action', 20);
        if (! in_array($action, ['create', 'update', 'delete'], true)) {
            throw new ContractError('Invalid report action.', 400);
        }
        $id = $action === 'create' ? null : Contract::id($data['id'] ?? null);
        $original = $id ? $this->repo->findItem($id) : null;
        if ($original) {
            $this->repo->requireEditableItem($user, $original);
        }
        Contract::allowed($data, ['action', 'id', 'type', 'title', 'description', 'category_id', 'location_id', 'location_detail', 'date', 'time', 'clue', 'keep_photo_ids', 'keep_ownership_ids', 'visual_opt_in', '_csrf']);
        Contract::allowed($request->files->all(), ['photos', 'ownershipPhotos']);
        $type = $original['item_type'] ?? strtoupper(Contract::text($data, 'type', 10));
        if (! in_array($type, ['LOST', 'FOUND'], true)) {
            $this->field('type', 'Choose Lost or Found.');
        }
        if ($original && isset($data['type']) && strtoupper(Contract::text($data, 'type', 10)) !== $type) {
            throw new ContractError('Report type cannot change during edit.', 409);
        }
        $values = $action === 'delete' ? null : $this->values($data);
        $public = $action === 'delete' ? [] : $this->files($request, 'photos', 4);
        $proof = $action === 'delete' ? [] : $this->files($request, 'ownershipPhotos', 2);
        if (isset($data['visual_opt_in'])) {
            $this->field('visual_opt_in', 'Visual indexing is unavailable.');
        }
        if ($type === 'FOUND' && $proof) {
            $this->field('ownershipPhotos', 'Found reports use a private clue, not Lost ownership photos.');
        }
        $operation = $this->journal->create();
        $started = false;
        try {
            $staged = ['ITEM' => [], 'OWNERSHIP' => []];
            foreach ([['ITEM', $public, 'items', 'photos'], ['OWNERSHIP', $proof, 'evidence', 'ownershipPhotos']] as [$kind,$files,$collection,$key]) {
                foreach ($files as $file) {
                    try {
                        $staged[$kind][] = $this->stager->stage($file, $collection, $operation);
                    } catch (ContractError$e) {
                        throw new ContractError($e->getMessage(), $e->status, $e->errorCode, $e->fields ?: [$key => $e->getMessage()]);
                    }
                }
            }
            $started = true;

            return $this->writes->run($operation, function (WriteContext $context) use ($user, $data, $action, $id, $type, $values, $staged, $operation) {
                $db = $context->connection;
                $ids = array_map(fn ($x) => (int) $x->item_id, $db->select('SELECT item_id FROM items WHERE item_type<>?', [$type]));
                if ($id) {
                    $ids[] = $id;
                }
                $locked = array_map(fn ($row) => (array) $row, $context->lockItems($ids));
                if ($id) {
                    $this->repo->requireEditableItem($user, $locked[$id]);
                }
                if ($action === 'delete') {
                    $item = $locked[$id];
                    $removed = $db->select('SELECT file_path FROM item_photos WHERE item_id=? UNION ALL SELECT p.file_path FROM claim_photos p JOIN claims c ON c.claim_id=p.claim_id WHERE c.item_id=?', [$id, $id]);
                    foreach ($removed as $photo) {
                        $operation->removeAfterCommit($photo->file_path);
                    }
                    $db->delete('DELETE FROM items WHERE item_id=?', [$id]);
                    unset($locked[$id]);
                    $this->effects->deleted($item, $locked, (int) $user['user_id']);
                } else {
                    [$title,$description,$category,$location,$detail,$date,$time,$clue] = $values;
                    $old = $id ? $db->select('SELECT photo_id,photo_kind,file_path FROM item_photos WHERE item_id=? FOR UPDATE', [$id]) : [];
                    $publicIds = array_map(fn ($p) => (int) $p->photo_id, array_filter($old, fn ($p) => $p->photo_kind === 'ITEM'));
                    $proofIds = array_map(fn ($p) => (int) $p->photo_id, array_filter($old, fn ($p) => $p->photo_kind === 'OWNERSHIP'));
                    $keepPublic = $this->retained($data, 'keep_photo_ids', array_values($publicIds), 4);
                    $keepProof = $this->retained($data, 'keep_ownership_ids', array_values($proofIds), 2);
                    if (array_diff($keepPublic, $publicIds) || array_diff($keepProof, $proofIds)) {
                        throw new ContractError('A retained photo does not belong to this report.', 403, 'forbidden');
                    }
                    if (count($keepPublic) + count($staged['ITEM']) > 4 || count($keepProof) + count($staged['OWNERSHIP']) > 2) {
                        $this->field('photos', 'Use at most 4 public photos and 2 ownership photos including retained files.');
                    }
                    if ($type === 'FOUND' && $keepProof) {
                        $this->field('ownershipPhotos', 'Found reports do not contain Lost evidence.');
                    }
                    $created = ! $id;
                    if ($created) {
                        $open = (int) $db->selectOne("SELECT status_id FROM item_statuses WHERE status_code='OPEN'")->status_id;
                        $db->insert('INSERT INTO items(reporter_user_id,category_id,location_id,status_id,item_type,title,description,location_detail,event_date,event_time,verification_clue)VALUES(?,?,?,?,?,?,?,?,?,?,?)', [(int) $user['user_id'], $category, $location, $open, $type, $title, $description, $detail ?: null, $date, $time, $clue]);
                        $id = (int) $db->getPdo()->lastInsertId();
                    } else {
                        $db->update('UPDATE items SET title=?,description=?,category_id=?,location_id=?,location_floor_id=NULL,location_detail=?,event_date=?,event_time=?,verification_clue=? WHERE item_id=?', [$title, $description, $category, $location, $detail ?: null, $date, $time, $clue, $id]);
                        foreach ($old as $photo) {
                            if (! in_array((int) $photo->photo_id, array_merge($keepPublic, $keepProof), true)) {
                                $db->delete('DELETE FROM item_photos WHERE photo_id=?', [(int) $photo->photo_id]);
                                $operation->removeAfterCommit($photo->file_path);
                            }
                        }
                    }
                    foreach ($staged as $kind => $paths) {
                        foreach ($paths as $path) {
                            $db->insert('INSERT INTO item_photos(item_id,photo_kind,file_path)VALUES(?,?,?)', [$id, $kind, $path]);
                        }
                    }
                    $db->update("UPDATE item_photos SET is_primary=0 WHERE item_id=? AND photo_kind='ITEM'", [$id]);
                    $primary = $db->selectOne("SELECT photo_id FROM item_photos WHERE item_id=? AND photo_kind='ITEM' ORDER BY photo_id LIMIT 1", [$id]);
                    if ($primary) {
                        $db->update('UPDATE item_photos SET is_primary=1 WHERE photo_id=?',[(int) $primary->photo_id]);
                    }
                    $locked[$id] = $this->repo->findItem($id);
                    $this->effects->saved($locked[$id],$locked,(int) $user['user_id'],$created);
                }

                return ['id' => (string) $id, 'action' => $action];
            }, $fault, $commitTransport);
        } catch (Throwable$error) {
            if (! $started) {
                try {
                    $this->writes->reconcile($operation);
                } catch (Throwable) {/* Preserve pending manifest when recovery cannot be proved. */
                }
            }
            throw $error;
        }
    }
}
