<?php

namespace App\Repositories;

use App\Exceptions\ContractError;
use App\Services\AuthService;
use App\Support\ClaimRevision;
use App\Support\Contract;
use Illuminate\Support\Facades\DB;

final class ClaimReadRepository
{
    private const ACTIVE_CLAIMS = ['PENDING', 'NEED_MORE_INFO'];

    public function __construct(private ItemReadRepository $items, private AuthService $auth) {}

    public function listing(array $query, array $user): array
    {
        $where = [];
        $bindings = [];
        if ($user['role'] === 'STUDENT') {
            $where[] = 'c.claimant_user_id=?';
            $bindings[] = (int) $user['id'];
        }
        foreach (['id' => 'claim_id', 'itemId' => 'item_id'] as $filter => $column) {
            if (isset($query[$filter])) {
                $where[] = "c.$column=?";
                $bindings[] = Contract::id($query[$filter]);
            }
        }
        [$page, $limit, $offset] = $this->items->pagination($query, 24);
        $clause = $where ? ' WHERE '.implode(' AND ', $where) : '';
        $total = (int) DB::selectOne('SELECT COUNT(*) AS n FROM claims c'.$clause, $bindings)->n;
        $claims = $this->rows(
            'SELECT c.claim_id,c.item_id,c.related_lost_item_id,c.claimant_user_id,c.ownership_answer,c.claim_status,c.review_note,c.created_at,c.updated_at,c.reviewed_at,i.title,i.item_type,s.status_code AS item_status,u.full_name AS claimant_name'
            .' FROM claims c JOIN items i ON i.item_id=c.item_id JOIN item_statuses s ON s.status_id=i.status_id JOIN users u ON u.user_id=c.claimant_user_id'
            .$clause.' ORDER BY c.updated_at DESC,c.claim_id DESC LIMIT ? OFFSET ?',
            [...$bindings, $limit, $offset],
        );
        if (isset($query['id']) && ! $claims) {
            throw new ContractError('Claim not found.', 404, 'not_found');
        }
        if ($claims) {
            $claimIds = array_column($claims, 'claim_id');
            $itemIds = array_values(array_unique(array_column($claims, 'item_id')));
            $claimMarks = implode(',', array_fill(0, count($claimIds), '?'));
            $itemMarks = implode(',', array_fill(0, count($itemIds), '?'));
            $photos = [];
            foreach ($this->rows("SELECT claim_id,claim_photo_id FROM claim_photos WHERE claim_id IN ($claimMarks) ORDER BY claim_photo_id", $claimIds) as $photo) {
                $photos[$photo['claim_id']][] = $photo['claim_photo_id'];
            }
            $confirmed = [];
            foreach ($this->rows("SELECT lost_item_id,found_item_id FROM item_matches WHERE match_status='CONFIRMED' AND (lost_item_id IN ($itemMarks) OR found_item_id IN ($itemMarks))", [...$itemIds, ...$itemIds]) as $pair) {
                $confirmed[$pair['lost_item_id']] = $confirmed[$pair['found_item_id']] = true;
            }
            foreach ($claims as &$claim) {
                $ids = $photos[$claim['claim_id']] ?? [];
                $claim['ownership_photo_count'] = count($ids);
                $claim['ownership_photo_ids'] = $ids;
                if ($user['role'] === 'BUILDING_MANAGEMENT') {
                    $claim['ownership_photos'] = $this->photoUrls($ids, 'claim');
                }
                $claim['can_resubmit'] = in_array($claim['claim_status'], ['NEED_MORE_INFO', 'WITHDRAWN'], true)
                    && in_array($claim['item_status'], ItemReadRepository::ACTIVE_ITEM_CODES, true)
                    && ! isset($confirmed[$claim['item_id']]);
                $claim['can_withdraw'] = $user['role'] === 'STUDENT' && in_array($claim['claim_status'], self::ACTIVE_CLAIMS, true);
                foreach (['claim_id', 'item_id', 'related_lost_item_id', 'claimant_user_id'] as $key) {
                    $claim[$key] = $claim[$key] === null ? null : (string) $claim[$key];
                }
                $claim = $this->dates($claim);
            }
            unset($claim);
        }

        return $this->page($claims, $total, $page, $limit);
    }

    public function queue(array $query): array
    {
        [$page, $limit, $offset] = $this->items->pagination($query, 24);
        $search = Contract::text($query, 'q', 200, false);
        $status = Contract::text($query, 'status', 40, false);
        $where = [];
        $bindings = [];
        if ($status === '' || $status === 'active') {
            $where[] = "c.claim_status IN ('PENDING','NEED_MORE_INFO')";
        } elseif ($status !== 'all') {
            if (! in_array($status, [...self::ACTIVE_CLAIMS, 'APPROVED', 'REJECTED', 'WITHDRAWN'], true)) {
                throw new ContractError('Invalid claim state.', 400);
            }
            $where[] = 'c.claim_status=?';
            $bindings[] = $status;
        }
        if ($search !== '') {
            $where[] = "LOCATE(?,CONCAT_WS(' ',i.title,i.location_detail,l.location_name,u.full_name))>0";
            $bindings[] = $search;
        }
        $from = ' FROM claims c JOIN items i ON i.item_id=c.item_id JOIN users u ON u.user_id=c.claimant_user_id LEFT JOIN campus_locations l ON l.location_id=i.location_id';
        $clause = $from.($where ? ' WHERE '.implode(' AND ', $where) : '');
        $total = (int) DB::selectOne('SELECT COUNT(*) AS n'.$clause, $bindings)->n;
        $claims = $this->rows(
            'SELECT c.claim_id,c.item_id,c.claim_status,c.created_at,c.updated_at,i.title,u.full_name AS claimant_name,u.avatar_path AS claimant_avatar_path,(SELECT COUNT(*) FROM claim_photos p WHERE p.claim_id=c.claim_id) AS proof_count'
            .$clause.' ORDER BY c.updated_at DESC,c.claim_id DESC LIMIT ? OFFSET ?',
            [...$bindings, $limit, $offset],
        );
        foreach ($claims as &$claim) {
            $claim['claim_id'] = (string) $claim['claim_id'];
            $claim['item_id'] = (string) $claim['item_id'];
            $claim = $this->dates($this->avatar($claim));
        }
        unset($claim);
        $counts = array_column($this->rows('SELECT claim_status,COUNT(*) AS n FROM claims GROUP BY claim_status'), 'n', 'claim_status');

        return $this->page($claims, $total, $page, $limit) + ['counts' => $counts];
    }

    public function staffDetail(int $id, array $user): array
    {
        $record = DB::selectOne(
            'SELECT c.claim_id,c.item_id,c.related_lost_item_id,c.claimant_user_id,c.ownership_answer,c.claim_status,c.reviewed_by_user_id,c.review_note,c.reviewed_at,c.created_at,c.updated_at,u.full_name AS claimant_name,u.avatar_path AS claimant_avatar_path,u.email AS claimant_email,u.student_id AS claimant_student_id FROM claims c JOIN users u ON u.user_id=c.claimant_user_id WHERE c.claim_id=?',
            [$id],
        );
        if (! $record) {
            throw new ContractError('Claim not found.', 404, 'not_found');
        }
        $claim = $this->avatar((array) $record);
        $proofIds = array_column($this->rows('SELECT claim_photo_id FROM claim_photos WHERE claim_id=? ORDER BY claim_photo_id', [$id]), 'claim_photo_id');
        // Same digest the review command recomputes under its locks (see ClaimRevision for the native divergence).
        $claim['revision'] = ClaimRevision::compute(DB::connection(), $claim);
        $claim['claim_id'] = (string) $claim['claim_id'];
        $reportUser = ['user_id' => $user['id'], 'role' => $user['role']];
        $item = $this->staffReport((int) $claim['item_id'], $reportUser);
        $lost = $claim['related_lost_item_id'] === null ? null : $this->staffReport((int) $claim['related_lost_item_id'], $reportUser);
        $claim['item'] = $item;
        $claim['lost'] = $lost;
        $claim['ownership_photos'] = $this->photoUrls($proofIds, 'claim');

        $active = in_array($claim['claim_status'], self::ACTIVE_CLAIMS, true) && $this->reportAvailable($item);
        $lostAvailable = $lost === null || $this->reportAvailable($lost);
        $claim['decisions'] = $active ? ['rejected'] : [];
        if ($active && $lostAvailable) {
            $claim['decisions'][] = 'approved';
        }
        $claim['approval_blocked'] = $active && ! $lostAvailable ? 'The linked Lost report is already matched to another item, so this claim cannot be approved.' : null;
        if ($active && $claim['claim_status'] === 'PENDING') {
            $claim['decisions'][] = 'need_more_info';
        }

        $handover = DB::selectOne('SELECT handover_id FROM handovers WHERE claim_id=?', [$id]);
        $claim['handover_id'] = $handover ? (string) $handover->handover_id : null;
        $request = $claim['claim_status'] === 'PENDING'
            ? DB::selectOne("SELECT message,created_at FROM notifications WHERE claim_id=? AND user_id=? AND notification_type='CLAIM_NEEDS_INFO' ORDER BY notification_id DESC LIMIT 1", [$id, $claim['claimant_user_id']])
            : null;
        $claim['previous_request'] = $request ? ['note' => $request->message, 'at' => $this->items->utcIso($request->created_at)] : null;
        $claim['resubmitted_at'] = $request ? $this->items->utcIso($claim['updated_at']) : null;

        return $this->dates($claim);
    }

    public function staffReport(int $id, array $user): array
    {
        $raw = $this->items->findItem($id);
        $public = (array) DB::selectOne('SELECT '.$this->items->publicColumns().',u.avatar_path AS reporter_avatar_path FROM v_items_overview v JOIN users u ON u.user_id=v.reporter_user_id WHERE v.item_id=?', [$id]);
        $context = $this->items->projectionContext([$public], $user);
        $report = $this->items->safeItem($public, $user, $context);
        $report['verification_clue'] = $raw['verification_clue'];
        $report['ownership_photos'] = $this->photoUrls(array_column($this->rows("SELECT photo_id FROM item_photos WHERE item_id=? AND photo_kind='OWNERSHIP' ORDER BY photo_id", [$id]), 'photo_id'), 'item');

        $history = $this->rows('SELECT h.history_id,h.changed_at,h.note,s.status_code FROM item_status_history h JOIN item_statuses s ON s.status_id=h.status_id WHERE h.item_id=? ORDER BY h.changed_at,h.history_id', [$id]);
        $report['revision'] = $this->revision($raw + ['history_id' => max([0, ...array_column($history, 'history_id')])], [
            'item_id', 'reporter_user_id', 'category_id', 'location_id', 'item_type', 'title', 'description', 'verification_clue', 'status_id', 'updated_at', 'resolved_at', 'history_id',
        ]);
        $report['history'] = array_map(fn ($event) => [
            'at' => $this->items->utcIso($event['changed_at']),
            'status' => $this->items->uiStatusName($event['status_code']),
            'note' => $event['note'] ?? '',
        ], $history);

        $lock = DB::selectOne("SELECT c.claim_id,h.handover_id FROM claims c LEFT JOIN handovers h ON h.claim_id=c.claim_id WHERE (c.item_id=? OR c.related_lost_item_id=?) AND c.claim_status IN ('PENDING','NEED_MORE_INFO','APPROVED') ORDER BY c.claim_status='APPROVED' DESC,c.updated_at DESC LIMIT 1", [$id, $id]);
        $report['active_claim_id'] = $lock ? (string) $lock->claim_id : null;
        $report['active_handover_id'] = isset($lock->handover_id) ? (string) $lock->handover_id : null;
        $report['allowed_statuses'] = [];
        if (! in_array($raw['status_code'], ['RESOLVED', 'UNCLAIMED_CLOSED', 'HANDOVER'], true) && ! $report['matched_item_id'] && ! $lock) {
            $choices = ['OPEN', 'UNDER_REVIEW', 'UNCLAIMED_CLOSED'];
            if ($this->items->hasSuggestion($id)) {
                $choices[] = 'POTENTIAL_MATCH';
            }
            if ($raw['item_type'] === 'LOST') {
                $choices[] = 'RESOLVED';
            }
            $report['allowed_statuses'] = array_values(array_diff($choices, [$raw['status_code']]));
        }

        return $report;
    }

    private function reportAvailable(array $report): bool
    {
        return in_array($report['status_code'], ItemReadRepository::ACTIVE_ITEM_CODES, true) && $report['matched_item_id'] === null;
    }

    private function revision(array $record, array $keys): string
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = (string) ($record[$key] ?? '');
        }

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    private function avatar(array $claim): array
    {
        $claim['claimant_avatar_url'] = $this->auth->avatar($claim['claimant_avatar_path']);
        unset($claim['claimant_avatar_path']);

        return $claim;
    }

    private function dates(array $claim): array
    {
        foreach (['created_at', 'updated_at', 'reviewed_at'] as $key) {
            if (array_key_exists($key, $claim)) {
                $claim[$key] = $this->items->utcIso($claim[$key]);
            }
        }

        return $claim;
    }

    private function photoUrls(array $ids, string $kind): array
    {
        return array_map(fn ($id) => '/api/photos.php?kind='.$kind.'&id='.$id, $ids);
    }

    private function page(array $claims, int $total, int $page, int $limit): array
    {
        return ['claims' => $claims, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $limit))];
    }

    private function rows(string $query, array $bindings = []): array
    {
        return array_map(fn ($row) => (array) $row, DB::select($query, $bindings));
    }
}
