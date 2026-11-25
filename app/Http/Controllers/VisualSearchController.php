<?php

namespace App\Http\Controllers;

use App\Exceptions\ContractError;
use App\Repositories\ItemReadRepository;
use App\Repositories\StaffReportRepository;
use App\Services\AuthService;
use App\Support\ClaimStages;
use App\Support\Contract;
use App\Visual\VisualSearch;
use Illuminate\Http\Request;

/**
 * Photo search endpoints (Laravel divergence from native, owner decision 25 Nov 2026):
 *   POST /api/visual-search.php         Students, Explore: 1–3 photo[] + Explore's structured filters
 *   POST /api/staff-visual-search.php   Building Management, Reports tab: 1–3 photo[] + type/status
 * Both answer with similarity labels only; Building Management still decides ownership.
 */
final class VisualSearchController
{
    public function explore(Request $r, AuthService $auth, VisualSearch $search, ItemReadRepository $items)
    {
        $u = $auth->require('STUDENT');
        Contract::allowed($r->query(), []);
        $user = ['user_id' => (string) $u->user_id, 'role' => $u->role];
        $result = $search->run($r, $user, 'explore');
        $cards = $result['state'] === 'matches' ? $items->rankedCards($result['matches'], $user) : [];

        return Contract::json(['state' => $result['state'] === 'matches' && ! $cards ? 'no_match' : $result['state'], 'items' => $cards]);
    }

    public function staffReports(Request $r, AuthService $auth, VisualSearch $search, StaffReportRepository $reports)
    {
        $u = $auth->require('BUILDING_MANAGEMENT');
        Contract::allowed($r->query(), []);
        if (! ClaimStages::has('status')) {
            throw new ContractError('This operation is not available in the proof of concept.', 501, 'feature_pending');
        }
        $identity = $auth->identity($u);
        $result = $search->run($r, ['user_id' => (string) $u->user_id, 'role' => $u->role], 'staff');
        $list = $result['state'] === 'matches' ? $reports->projectRanked($result['matches'], $identity) : [];

        return Contract::json(['state' => $result['state'] === 'matches' && ! $list ? 'no_match' : $result['state'], 'reports' => $list, 'total' => count($list), 'page' => 1, 'pages' => 1]);
    }
}
