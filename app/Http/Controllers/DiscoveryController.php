<?php

namespace App\Http\Controllers;

use App\Exceptions\ContractError;
use App\Repositories\ItemReadRepository;
use App\Services\AuthService;
use App\Support\Contract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DiscoveryController
{
    public function items(Request $r, AuthService $auth, ItemReadRepository $repo)
    {
        $u = $auth->require();
        Contract::allowed($r->query(), ['id', 'mine', 'editable', 'q', 'type', 'category_id', 'location_id', 'status', 'date', 'closed', 'active', 'page', 'limit']);

        return Contract::json($repo->listing($r->query(), ['user_id' => (string) $u->user_id, 'role' => $u->role]));
    }

    public function references(Request $r, ItemReadRepository $repo)
    {
        Contract::allowed($r->query(), []);
        $rows = fn ($sql) => array_map(fn ($r) => (array) $r, DB::select($sql));
        $categories = $rows('SELECT category_id AS id,category_name AS name FROM categories WHERE is_active=1 ORDER BY display_order');
        $areas = $rows("SELECT location_id AS id,location_code AS code,location_name AS name FROM campus_locations WHERE location_kind='AREA' AND is_active=1 ORDER BY display_order");

        return Contract::json(['categories' => $categories, 'locations' => $areas, 'statuses' => array_map(fn ($s) => ['code' => $s['code'], 'name' => $repo->uiStatusName($s['code'])], $rows('SELECT status_code AS code FROM item_statuses ORDER BY display_order')), 'filter_categories' => $categories, 'filter_locations' => array_map(fn ($l) => ['id' => $l['id'], 'name' => $l['name']], $areas), 'pickup_desk' => ['name' => 'Building Management service desk', 'location' => 'Gedung A lobby, ground floor', 'hours' => 'Mondayâ€“Friday, 08:00â€“16:00 WIB', 'contact' => 'bm@refind-poc.invalid'], 'campus_today' => $repo->campusToday(), 'timezone' => 'Asia/Jakarta']);
    }

    public function notifications(Request $r, AuthService $auth)
    {
        $u = $auth->require();
        Contract::allowed($r->query(), ['page', 'limit', 'count', 'unread']);
        if ($r->has('unread') && $r->query('unread') !== '1') {
            throw new ContractError('Invalid notification filter.', 400);
        }if ($r->query('count') !== '1' || $r->hasAny(['page', 'limit'])) {
            throw new ContractError('This operation is not available in the proof of concept.', 501, 'feature_pending');
        }

return Contract::json(['unread_count' => (int) DB::selectOne('SELECT COUNT(*) AS n FROM notifications WHERE user_id=? AND is_read=0', [$u->user_id])->n]);
    }

    public function unavailable(Request $r, AuthService $auth)
    {
        $access = $r->route()->defaults['access'] ?? 'authenticated';
        $auth->require(in_array($access, ['STUDENT', 'BUILDING_MANAGEMENT'], true) ? $access : null);
        throw new ContractError('This operation is not available in the proof of concept.', 501, 'feature_pending');
    }

    public function visual()
    {
        throw new ContractError('Photo search is unavailable.',403,'visual_disabled');
    }
}
