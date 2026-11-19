<?php

namespace App\Http\Controllers;

use App\Exceptions\ContractError;
use App\Services\AuthService;
use App\Services\ClaimReviewService;
use App\Services\StaffStatusService;
use App\Support\ClaimStages;
use App\Support\Contract;
use Illuminate\Http\Request;

/** POST /api/staff.php (native api/staff.php). Role and CSRF already ran in the request guard. */
final class StaffClaimController
{
    public function mutate(Request $request, AuthService $auth, ClaimReviewService $reviews, StaffStatusService $statuses)
    {
        $u = $auth->require('BUILDING_MANAGEMENT');
        if (! ClaimStages::decisions() && ! ClaimStages::has('status')) {
            // Accepted profiles keep the pre-Phase-6 contract exactly.
            throw new ContractError('This operation is not available in the proof of concept.', 501, 'feature_pending');
        }
        Contract::allowed($request->query(), ['resource', 'id', 'q', 'status', 'type', 'page', 'limit']);
        $action = Contract::text($request->request->all(), 'action', 20);
        if ($action === 'review') {
            return Contract::json($reviews->review(['user_id' => (string) $u->user_id, 'role' => $u->role], $request));
        }
        if ($action === 'status') {
            // Phase 8 manual BM report status; StaffStatusService fails closed without the status stage.
            return Contract::json($statuses->mutate(['user_id' => (string) $u->user_id, 'role' => $u->role], $request));
        }
        throw new ContractError('Invalid staff operation.', 400, 'invalid_request');
    }
}
