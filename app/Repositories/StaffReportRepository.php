<?php

namespace App\Repositories;

use App\Exceptions\ContractError;
use App\Support\Contract;
use Illuminate\Support\Facades\DB;

/**
 * BM report queue and detail (native api/staff.php resource=reports and staff_report_list at 47eea86).
 * Raw rows only feed revisions and manual-status choices; private columns never merge into the public records.
 */
final class StaffReportRepository
{
    public function __construct(private ItemReadRepository $items, private ClaimReadRepository $claims) {}

    /** Native manual_status_choices: final/handover states and blocked reports offer nothing. */
    public static function choices(array $item, bool $blocked, bool $hasSuggestion): array
    {
        if (in_array($item['status_code'], ['RESOLVED', 'UNCLAIMED_CLOSED', 'HANDOVER'], true) || $blocked) {
            return [];
        }
        $choices = ['OPEN', 'UNDER_REVIEW', 'UNCLAIMED_CLOSED'];
        if ($hasSuggestion) {
            $choices[] = 'POTENTIAL_MATCH';
        }
        if ($item['item_type'] === 'LOST') {
            $choices[] = 'RESOLVED';
        }

        return array_values(array_diff($choices, [$item['status_code']]));
    }

    /** Native item_revision over the raw report row and its latest history ID. */
    public static function revision(array $raw, int $historyId): string
    {
        $raw['history_id'] = $historyId;
        $values = [];
        foreach (['item_id', 'reporter_user_id', 'category_id', 'location_id', 'item_type', 'title', 'description', 'verification_clue', 'status_id', 'updated_at', 'resolved_at', 'history_id'] as $key) {
            $values[$key] = (string) ($raw[$key] ?? '');
        }

        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }

    public function detail(int $id, array $user): array
    {
        $report = $this->claims->staffReport($id, ['user_id' => $user['id'], 'role' => $user['role']]);

        return ['report' => $report, 'reports' => [$report]];
    }

    public function queue(array $query, array $user): array
    {
        [$page, $limit, $offset] = $this->items->pagination($query, 24);
        $where = [];
        $values = [];
        $search = Contract::text($query, 'q', 200, false);
        $status = Contract::text($query, 'status', 40, false);
        if ($status !== '' && $status !== 'all') {
            if (! DB::selectOne('SELECT status_id FROM item_statuses WHERE status_code=?', [$status])) {
                throw new ContractError('Invalid report state.', 400);
            }
            $where[] = 'v.status_code=?';
            $values[] = $status;
        }
        if (isset($query['type']) && $query['type'] !== '') {
            $type = strtoupper(Contract::text($query, 'type', 10));
            if (! in_array($type, ['LOST', 'FOUND'], true)) {
                throw new ContractError('Invalid report type.', 400);
            }
            $where[] = 'v.item_type=?';
            $values[] = $type;
        }
        if ($search !== '') {
            $where[] = "LOCATE(?,CONCAT_WS(' ',v.title,v.category_name,v.location_name,v.location_detail,v.floor_name,v.reporter_name))>0";
            $values[] = $search;
        }
        $clause = $where ? ' WHERE '.implode(' AND ', $where) : '';
        $total = (int) DB::selectOne('SELECT COUNT(*) AS n FROM v_items_overview v'.$clause, $values)->n;
        $records = array_map(fn ($r) => (array) $r, DB::select('SELECT '.$this->items->publicColumns().',u.avatar_path AS reporter_avatar_path FROM v_items_overview v JOIN users u ON u.user_id=v.reporter_user_id'.$clause.' ORDER BY v.created_at DESC,v.item_id DESC LIMIT ? OFFSET ?', [...$values, $limit, $offset]));

        return ['reports' => $this->project($records, ['user_id' => $user['id'], 'role' => $user['role']]), 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $limit))];
    }

    /** Photo search on the BM Reports tab: queue records in rank order, matched photo first, similarity label. */
    public function projectRanked(array $matches, array $user): array
    {
        $projected = array_column($this->project($this->items->rankedRecords($matches), ['user_id' => $user['id'], 'role' => $user['role']]), null, 'item_id');
        $reports = [];
        foreach ($matches as $match) {
            $report = $projected[(string) $match['item_id']] ?? null;
            if ($report && ($report = ItemReadRepository::withMatch($report, $match))) {
                $reports[] = $report;
            }
        }

        return $reports;
    }

    private function project(array $records, array $user): array
    {
        if (! $records) {
            return [];
        }
        $context = $this->items->projectionContext($records, $user);
        $ids = array_map(fn ($r) => (int) $r['item_id'], $records);
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $raw = [];
        foreach (DB::select("SELECT i.*,s.status_code FROM items i JOIN item_statuses s ON s.status_id=i.status_id WHERE i.item_id IN ($marks)", $ids) as $row) {
            $raw[(int) $row->item_id] = (array) $row;
        }
        $history = [];
        foreach (DB::select("SELECT item_id,MAX(history_id) AS history_id FROM item_status_history WHERE item_id IN ($marks) GROUP BY item_id", $ids) as $row) {
            $history[(int) $row->item_id] = (int) $row->history_id;
        }
        $suggested = [];
        foreach (DB::select("SELECT lost_item_id,found_item_id FROM item_matches WHERE match_status='SUGGESTED' AND (lost_item_id IN ($marks) OR found_item_id IN ($marks))", [...$ids, ...$ids]) as $match) {
            $suggested[(int) $match->lost_item_id] = true;
            $suggested[(int) $match->found_item_id] = true;
        }
        $reports = [];
        foreach ($records as $record) {
            $id = (int) $record['item_id'];
            if (! isset($raw[$id])) {
                throw new ContractError('Report not found.', 404, 'not_found');
            }
            $report = $this->items->safeItem($record, $user, $context);
            $blocked = $context[$id]['match'] !== null || $context[$id]['active_claim'];
            $report['allowed_statuses'] = self::choices($raw[$id], $blocked, isset($suggested[$id]));
            $report['revision'] = self::revision($raw[$id], $history[$id] ?? 0);
            $reports[] = $report;
        }

        return $reports;
    }
}
