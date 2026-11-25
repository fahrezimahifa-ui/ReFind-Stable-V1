<?php

namespace App\Visual;

use App\Exceptions\ContractError;
use App\Repositories\ItemReadRepository;
use App\Support\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Photo search over public report photos (owner decisions, 25 Nov 2026): 1–3 query photos, each report ranked by
 * its best match to any of them, honest "no close match", similarity labels only (never scores, never ownership).
 * Query photos are read from PHP's temporary upload, prepared in memory and discarded; nothing is stored.
 * The catalogue is never embedded here: photos the indexer has not reached are simply not searchable yet.
 */
final class VisualSearch
{
    public const MAX_PHOTOS = 3;

    public function __construct(private ItemReadRepository $items) {}

    /**
     * @param  'explore'|'staff'  $scope
     * @return array{state:string,matches:list<array{item_id:int,photo_id:int,label:string}>}
     */
    public function run(Request $r, array $user, string $scope, ?Provider $provider = null): array
    {
        $config = VisualConfig::require();
        $jpegs = $this->queryPhotos($r);
        [$clause, $values] = $scope === 'staff' ? $this->staffFilter($r->request->all()) : $this->exploreFilter($r->request->all());
        $provider ??= app()->bound(Provider::class) ? app(Provider::class) : VisualConfig::makeProvider($config);
        $calibration = Calibration::load(Calibration::dir(), $provider->modelKey());
        if ($calibration === null) {
            throw new ContractError('Photo search is unavailable. You can still search by keyword.', 503, 'visual_uncalibrated');
        }
        $store = new IndexStore(VisualConfig::indexDir());
        $meta = $store->load($provider->modelKey());
        $photos = [];
        $owner = [];
        foreach (DB::select('SELECT p.photo_id,p.item_id,p.file_path FROM item_photos p JOIN v_items_overview v ON v.item_id=p.item_id'.$clause.' ORDER BY p.photo_id', $values) as $row) {
            $photos[(int) $row->photo_id] = $row->file_path;
            $owner[(int) $row->photo_id] = (int) $row->item_id;
        }
        $indexed = $meta ? array_filter($photos, fn ($path, $id) => ($meta['entries'][(string) $id]['path'] ?? null) === $path, ARRAY_FILTER_USE_BOTH) : [];
        if ($indexed === []) {
            return ['state' => 'empty_index', 'matches' => []];
        }
        $budget = Budget::forProfile($config);
        $wait = $budget->reserve((int) $user['user_id'], count($jpegs));
        if ($wait > 0) {
            throw new ContractError('Photo search has reached a temporary limit. Try again later or search by keyword.', 429, 'visual_rate_limited', [], ['Retry-After' => (string) $wait]);
        }
        try {
            $queries = $provider->embed($jpegs);
        } catch (ProviderError $error) {
            if (in_array($error->reason, ['quota', 'project'], true)) {
                $budget->pause($error->retryAfter > 0 ? $error->retryAfter : 900);
            }
            $headers = $error->retryAfter > 0 ? ['Retry-After' => (string) $error->retryAfter] : [];
            if (in_array($error->reason, ['quota', 'rate_limited'], true)) {
                throw new ContractError('Photo search has reached a temporary limit. Try again later or search by keyword.', 429, 'visual_rate_limited', [], $headers);
            }
            if ($error->reason === 'timeout') {
                throw new ContractError('Photo search timed out. Try again or search by keyword.', 503, 'visual_timeout');
            }
            throw new ContractError('Photo search is unavailable. You can still search by keyword.', 503, 'visual_unavailable', [], $headers);
        }
        $candidates = (function () use ($store, $meta, $indexed, $owner) {
            foreach ($store->vectors($meta, $indexed) as [$photo, $vector]) {
                yield ['item_id' => $owner[$photo], 'photo_id' => $photo, 'vector' => $vector];
            }
        })();
        $ranked = Scorer::rank($candidates, $queries);
        $matches = Scorer::select($ranked, $calibration['cutoff'], $calibration['strong_cutoff'], $scope === 'staff' ? 24 : Scorer::LIMIT);

        return ['state' => $matches ? 'matches' : 'no_match', 'matches' => $matches];
    }

    /** @return list<string> prepared JPEGs */
    private function queryPhotos(Request $r): array
    {
        $files = $r->allFiles();
        if (array_diff(array_keys($files), ['photo'])) {
            throw new ContractError('Unsupported request field.', 400, 'invalid_request');
        }
        $list = $files['photo'] ?? [];
        $list = $list instanceof UploadedFile ? [$list] : $list;
        if (! is_array($list) || ! array_is_list($list) || count($list) < 1 || count($list) > self::MAX_PHOTOS) {
            throw new ContractError('Please check the highlighted fields.', 422, 'validation_error', ['photo' => 'Choose 1 to '.self::MAX_PHOTOS.' photos.']);
        }
        $out = [];
        foreach ($list as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid() || ! $file->getRealPath()) {
                throw new ContractError('Please check the highlighted fields.', 422, 'validation_error', ['photo' => 'Upload incomplete. Please choose the photo again.']);
            }
            try {
                $out[] = Preprocess::prepare((string) file_get_contents($file->getRealPath(), false, null, 0, Preprocess::MAX_BYTES + 1));
            } catch (\InvalidArgumentException $error) {
                throw new ContractError('Please check the highlighted fields.', 422, 'validation_error', ['photo' => $error->getMessage()]);
            }
        }

        return $out;
    }

    /** Explore: the same structured filters as keyword search; closed reports only when a status filter asks. */
    private function exploreFilter(array $data): array
    {
        Contract::allowed($data, ['type', 'category_id', 'location_id', 'status', 'date', '_csrf']);
        [$where, $values] = $this->items->structuredFilters($data);
        if (($data['status'] ?? '') === '') {
            $where[] = "v.status_code NOT IN ('RESOLVED','UNCLAIMED_CLOSED')";
        }
        $where[] = "p.photo_kind='ITEM'";

        return [' WHERE '.implode(' AND ', $where), $values];
    }

    /** BM Reports tab: type (default all; the page sends LOST first) and the queue's state filter ('all' or a code). */
    private function staffFilter(array $data): array
    {
        Contract::allowed($data, ['type', 'status', '_csrf']);
        $where = ["p.photo_kind='ITEM'"];
        $values = [];
        $status = Contract::text($data, 'status', 40, false);
        if ($status !== '' && $status !== 'all') {
            if (! DB::selectOne('SELECT status_id FROM item_statuses WHERE status_code=?', [$status])) {
                throw new ContractError('Invalid report state.', 400);
            }
            $where[] = 'v.status_code=?';
            $values[] = $status;
        }
        $type = strtoupper(Contract::text($data, 'type', 10, false));
        if ($type !== '') {
            if (! in_array($type, ['LOST', 'FOUND'], true)) {
                throw new ContractError('Invalid report type.', 400);
            }
            $where[] = 'v.item_type=?';
            $values[] = $type;
        }

        return [' WHERE '.implode(' AND ', $where), $values];
    }
}
