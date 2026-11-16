<?php

namespace App\Repositories;

use App\Exceptions\ContractError;
use App\Services\AuthService;
use App\Services\Search;
use App\Support\Contract;
use Illuminate\Support\Facades\DB;

final class ItemReadRepository
{
    private const ACTIVE_ITEM_CODES = ['OPEN', 'UNDER_REVIEW', 'POTENTIAL_MATCH', 'VERIFICATION'];

    private const RESUBMITTABLE_CLAIM_CODES = ['NEED_MORE_INFO', 'WITHDRAWN'];

    private function rows(string $query, array $values = []): array
    {
        return array_map(fn ($r) => (array) $r, DB::select($query, $values));
    }

    private function row(string $query, array $values = []): ?array
    {
        return $this->rows($query, $values)[0] ?? null;
    }

    private function fail(string $message, int $status = 422, string $code = 'validation_error'): never
    {
        throw new ContractError($message, $status, $code);
    }

    private function avatarUrl(?string $path): ?string
    {
        return app(AuthService::class)->avatar($path);
    }

    private function requireRole(array $user, string $role): void
    {
        if ($user['role'] !== $role) {
            throw new ContractError('This action is not available to your role.', 403, 'forbidden');
        }
    }

    public function uiStatusName(string $code): string
    {
        return ['OPEN' => 'Reported', 'UNDER_REVIEW' => 'Under review', 'POTENTIAL_MATCH' => 'Possible match', 'VERIFICATION' => 'Claim review',
            'HANDOVER' => 'Ready for pickup', 'RESOLVED' => 'Returned', 'UNCLAIMED_CLOSED' => 'Never retrieved'][$code] ?? $code;
    }

    public function campusToday(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
    }

    public function utcIso(?string $value): ?string
    {
        return $value === null ? null : (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    }

    public function confirmedMatch(int $id): ?array
    {
        return $this->row("SELECT * FROM item_matches WHERE match_status='CONFIRMED' AND (lost_item_id=? OR found_item_id=?) LIMIT 1", [$id, $id]);
    }

    public function hasSuggestion(int $id): bool
    {
        return (bool) $this->row("SELECT match_id FROM item_matches WHERE match_status='SUGGESTED' AND (lost_item_id=? OR found_item_id=?) LIMIT 1", [$id, $id]);
    }

    public function projectionContext(array $records, array $user): array
    {
        $context = [];
        foreach ($records as $record) {
            $context[(int) $record['item_id']] = ['photos' => [], 'match' => null, 'active_claim' => false, 'own_claim' => null, 'ownership_count' => 0];
        }
        if (! $context) {
            return [];
        }
        $ids = array_keys($context);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        foreach ($this->rows("SELECT item_id,photo_id,photo_kind FROM item_photos WHERE item_id IN ($marks) ORDER BY is_primary DESC,photo_id", $ids) as $photo) {
            if ($photo['photo_kind'] === 'ITEM') {
                $context[(int) $photo['item_id']]['photos'][] = ['photo_id' => $photo['photo_id']];
            } else {
                $context[(int) $photo['item_id']]['ownership_count']++;
            }
        }
        foreach ($this->rows("SELECT lost_item_id,found_item_id FROM item_matches WHERE match_status='CONFIRMED' AND (lost_item_id IN ($marks) OR found_item_id IN ($marks)) ORDER BY match_id", array_merge($ids, $ids)) as $match) {
            foreach (['lost_item_id', 'found_item_id'] as $key) {
                $id = (int) $match[$key];
                if (isset($context[$id]) && $context[$id]['match'] === null) {
                    $context[$id]['match'] = $match;
                }
            }
        }
        foreach ($this->rows("SELECT item_id,related_lost_item_id FROM claims WHERE claim_status IN ('PENDING','NEED_MORE_INFO','APPROVED') AND (item_id IN ($marks) OR related_lost_item_id IN ($marks))", array_merge($ids, $ids)) as $claim) {
            foreach (['item_id', 'related_lost_item_id'] as $key) {
                $id = (int) $claim[$key];
                if (isset($context[$id])) {
                    $context[$id]['active_claim'] = true;
                }
            }
        }
        if ($user['role'] === 'STUDENT') {
            foreach ($this->rows("SELECT claim_id,item_id,claim_status,review_note FROM claims WHERE claimant_user_id=? AND item_id IN ($marks)", array_merge([(int) $user['user_id']], $ids)) as $claim) {
                $id = (int) $claim['item_id'];
                unset($claim['item_id']);
                $context[$id]['own_claim'] = $claim;
            }
        }

        return $context;
    }

    public function safeItems(array $records, array $user): array
    {
        $context = $this->projectionContext($records, $user);

        return array_map(fn ($record) => $this->safeItem($record, $user, $context), $records);
    }

    public function safeItem(array $record, array $user, ?array $context = null): array
    {
        $id = (int) $record['item_id'];
        $record['reporter_avatar_url'] = $this->avatarUrl($record['reporter_avatar_path'] ?? null);
        unset($record['reporter_avatar_path']);
        $record['item_id'] = (string) $id;
        $record['reporter_user_id'] = (string) $record['reporter_user_id'];
        $photos = $context !== null ? $context[$id]['photos'] : $this->rows("SELECT photo_id FROM item_photos WHERE item_id=? AND photo_kind='ITEM' ORDER BY is_primary DESC,photo_id", [$id]);
        $record['photos'] = array_map(fn ($p) => '/'.'api/photos.php?kind=item&id='.$p['photo_id'], $photos);
        $record['photo_ids'] = array_column($photos, 'photo_id');
        $match = $context !== null ? $context[$id]['match'] : $this->confirmedMatch($id);
        $record['matched_item_id'] = $match ? (string) ((int) $match['lost_item_id'] === $id ? $match['found_item_id'] : $match['lost_item_id']) : null;
        $own = $record['reporter_user_id'] === $user['user_id'];
        $related = $context !== null ? $context[$id]['active_claim'] : $this->activeClaimForReport($id);
        $active = in_array($record['status_code'], self::ACTIVE_ITEM_CODES, true);
        $record['can_edit'] = $user['role'] === 'STUDENT' && $own && ! $related && ! $match && in_array($record['status_code'], ['OPEN', 'POTENTIAL_MATCH'], true);
        if ($user['role'] === 'STUDENT' && $own && ! $record['can_edit'] && in_array($record['status_code'], ['OPEN', 'POTENTIAL_MATCH', 'VERIFICATION', 'UNDER_REVIEW'], true)) {
            $record['edit_locked_reason'] = $related ? 'Editing is paused while a claim that uses this report is being reviewed.' : 'Building Management is reviewing this report, so it cannot be edited right now.';
        }
        $claim = $context !== null ? $context[$id]['own_claim'] : ($user['role'] === 'STUDENT' ? $this->row('SELECT claim_id,claim_status,review_note FROM claims WHERE item_id=? AND claimant_user_id=?', [$id, (int) $user['user_id']]) : null);
        $record['own_claim'] = $claim;
        $record['can_claim'] = $user['role'] === 'STUDENT' && ! $own && ! $match && $record['item_type'] === 'FOUND' && $active && (! $claim || in_array($claim['claim_status'], self::RESUBMITTABLE_CLAIM_CODES, true));
        $record['can_report_found'] = $user['role'] === 'STUDENT' && ! $own && ! $match && $record['item_type'] === 'LOST' && $active;
        if ($own) {
            $record['ownership_photo_count'] = $context !== null ? $context[$id]['ownership_count'] : (int) $this->row("SELECT COUNT(*) AS n FROM item_photos WHERE item_id=? AND photo_kind='OWNERSHIP'", [$id])['n'];
        }
        if (isset($record['created_at'])) {
            $record['created_at'] = $this->utcIso($record['created_at']);
        }
        if (isset($record['resolved_at'])) {
            $record['resolved_at'] = $this->utcIso($record['resolved_at']);
        }

        return $record;
    }

    public function publicColumns(): string
    {
        return 'v.item_id,v.reporter_user_id,v.reporter_name,v.item_type,v.title,v.description,v.category_id,v.category_name,v.location_id,v.location_name,v.location_detail,v.floor_name,v.event_date,v.event_time,v.status_code,v.status_name,v.created_at,v.resolved_at';
    }

    public function possibleMatches(int $id, array $user): array
    {
        // Pairs stored before v0.2.5 may still name the same reporter or another category; never show them.
        $records = $this->rows('SELECT '.$this->publicColumns().",m.match_score FROM item_matches m JOIN items self ON self.item_id=? JOIN v_items_overview v ON v.item_id=IF(m.lost_item_id=?,m.found_item_id,m.lost_item_id) WHERE (m.lost_item_id=? OR m.found_item_id=?) AND m.match_status='SUGGESTED' AND v.reporter_user_id<>self.reporter_user_id AND v.category_id=self.category_id AND v.status_code IN ('OPEN','UNDER_REVIEW','POTENTIAL_MATCH','VERIFICATION') AND NOT EXISTS(SELECT 1 FROM item_matches c WHERE c.match_status='CONFIRMED' AND (c.lost_item_id=v.item_id OR c.found_item_id=v.item_id)) ORDER BY m.match_score DESC,v.item_id LIMIT 6", [$id, $id, $id, $id]);

        return $this->safeItems($records, $user);
    }

    public function pagination(array $query, int $default = 12): array
    {
        $page = isset($query['page']) ? Contract::id($query['page']) : 1;
        $limit = isset($query['limit']) ? Contract::id($query['limit']) : $default;
        if ($page > 100000 || $limit > 48) {
            $this->fail('Invalid pagination.', 400);
        }

        return [$page, $limit, ($page - 1) * $limit];
    }

    public function activeClaimForReport(int $id): ?array
    {
        return $this->row("SELECT claim_id FROM claims WHERE (item_id = ? OR related_lost_item_id = ?) AND claim_status IN ('PENDING','NEED_MORE_INFO','APPROVED') LIMIT 1", [$id, $id]);
    }

    public function findItem(int $id): array
    {
        $item = $this->row('SELECT i.*, s.status_code FROM items i JOIN item_statuses s ON s.status_id = i.status_id WHERE i.item_id = ?', [$id]);
        if (! $item) {
            $this->fail('Report not found.', 404, 'not_found');
        }

        return $item;
    }

    public function requireEditableItem(array $user, array $item): void
    {
        $this->requireRole($user, 'STUDENT');
        if ((string) $item['reporter_user_id'] !== $user['user_id']) {
            $this->fail('Only the reporter can change this report.', 403, 'forbidden');
        }
        if (! in_array($item['status_code'], ['OPEN', 'POTENTIAL_MATCH'], true) ||
            $this->activeClaimForReport((int) $item['item_id']) ||
            $this->row("SELECT match_id FROM item_matches WHERE match_status = 'CONFIRMED' AND (lost_item_id = ? OR found_item_id = ?) LIMIT 1", [(int) $item['item_id'], (int) $item['item_id']])) {
            $this->fail('This report is locked by its workflow.', 409, 'state_conflict');
        }
    }

    public function structuredFilters(array $filters): array
    {
        $where = [];
        $values = [];
        if (isset($filters['type']) && $filters['type'] !== '') {
            $type = strtoupper(Contract::text($filters, 'type', 10));
            if (! in_array($type, ['LOST', 'FOUND'], true)) {
                $this->fail('Invalid report type.', 400);
            }
            $where[] = 'v.item_type=?';
            $values[] = $type;
        }
        foreach (['category_id', 'location_id'] as $key) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $where[] = 'v.'.$key.'=?';
                $values[] = Contract::id($filters[$key]);
            }
        }
        if (isset($filters['status']) && $filters['status'] !== '') {
            $status = strtoupper(Contract::text($filters, 'status', 40));
            if (! $this->row('SELECT status_id FROM item_statuses WHERE status_code=?', [$status])) {
                $this->fail('Invalid status filter.', 400);
            }
            $where[] = 'v.status_code=?';
            $values[] = $status;
        }
        if (isset($filters['date']) && $filters['date'] !== '') {
            $where[] = 'v.event_date=?';
            $values[] = $this->validEventDate(Contract::text($filters, 'date', 10));
        }

        return [$where, $values];
    }

    private function validEventDate(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (! $date || $date->format('Y-m-d') !== $value || substr($value, 0, 4) < '1000') {
            throw new ContractError('Enter a valid event date.');
        }

return $value;
    }

    public function listing(array $filters, array $user): array
    {
        $where = [];
        $values = [];
        if (isset($filters['id'])) {
            $where[] = 'v.item_id = ?';
            $values[] = Contract::id($filters['id']);
        }
        if (isset($filters['mine'])) {
            if ($filters['mine'] !== '1') {
                $this->fail('Invalid mine filter.', 400);
            }
            $where[] = 'v.reporter_user_id = ?';
            $values[] = (int) $user['user_id'];
        }
        [$structured, $structuredValues] = $this->structuredFilters($filters);
        $where = array_merge($where, $structured);
        $values = array_merge($values, $structuredValues);
        if (isset($filters['closed'])) {
            if ($filters['closed'] !== '1') {
                $this->fail('Invalid archive filter.', 400);
            }$where[] = "v.status_code IN ('RESOLVED','UNCLAIMED_CLOSED')";
        }
        if (isset($filters['active'])) {
            if ($filters['active'] !== '1' || isset($filters['closed'])) {
                $this->fail('Invalid active filter.', 400);
            }$where[] = "v.status_code NOT IN ('RESOLVED','UNCLAIMED_CLOSED')";
        }
        // Every word must appear somewhere in the report, in any order (see app/search.php).
        if (isset($filters['q'])) {
            [$search,$terms] = app(Search::class)->condition(app(Search::class)->terms(Contract::text($filters, 'q', 200, false)), "CONCAT_WS(' ',v.title,v.description,v.category_name,v.location_name,v.location_detail,v.floor_name)");
            if ($search !== '') {
                $where[] = $search;
                array_push($values, ...$terms);
            }
        }
        $clause = $where ? ' WHERE '.implode(' AND ', $where) : '';
        [$page,$limit,$offset] = $this->pagination($filters);
        $total = (int) $this->row('SELECT COUNT(*) AS n FROM v_items_overview v'.$clause, $values)['n'];
        $items = $this->rows('SELECT '.$this->publicColumns().',u.avatar_path AS reporter_avatar_path FROM v_items_overview v JOIN users u ON u.user_id=v.reporter_user_id'.$clause.' ORDER BY v.created_at DESC,v.item_id DESC LIMIT ? OFFSET ?', array_merge($values, [$limit, $offset]));
        if (isset($filters['id']) && ! $items) {
            $this->fail('Report not found.', 404, 'not_found');
        }
        $items = $this->safeItems($items, $user);
        if (isset($filters['editable'])) {
            if ($filters['editable'] !== '1' || ! isset($filters['id'])) {
                $this->fail('Invalid edit request.', 400);
            }
            $raw = $this->findItem(Contract::id($filters['id']));
            $this->requireEditableItem($user, $raw);
            $items[0]['private_clue'] = $raw['verification_clue'];
            $items[0]['ownership_photo_ids'] = array_column($this->rows("SELECT photo_id FROM item_photos WHERE item_id = ? AND photo_kind = 'OWNERSHIP'", [(int) $raw['item_id']]), 'photo_id');
        }
        if (isset($filters['id'])) {
            $id = Contract::id($filters['id']);
            // Actor is a role-level label only (never a name or ID): you, building_management, reporter, student or system.
            $items[0]['history'] = array_map(fn ($h) => ['status_code' => $h['status_code'], 'status_name' => $this->uiStatusName($h['status_code']), 'at' => $this->utcIso($h['changed_at']), 'note' => $h['note'], 'actor' => $h['actor']], $this->rows("SELECT s.status_code,s.status_name,h.changed_at,h.note,
        CASE WHEN h.changed_by_user_id IS NULL THEN 'system' WHEN h.changed_by_user_id=? THEN 'you' WHEN u.role='BUILDING_MANAGEMENT' THEN 'building_management'
             WHEN h.changed_by_user_id=i.reporter_user_id THEN 'reporter' ELSE 'student' END AS actor
        FROM item_status_history h JOIN item_statuses s ON s.status_id=h.status_id JOIN items i ON i.item_id=h.item_id LEFT JOIN users u ON u.user_id=h.changed_by_user_id
        WHERE h.item_id=? ORDER BY h.changed_at,h.history_id", [(int) $user['user_id'], $id]));
            $items[0]['matches'] = $items[0]['matched_item_id'] || in_array($items[0]['status_code'], ['RESOLVED', 'UNCLAIMED_CLOSED'], true) ? [] : $this->possibleMatches($id, $user);

            return ['item' => $items[0]];
        }

        return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit, 'pages' => max(1,(int) ceil($total / $limit))];

    }
}
