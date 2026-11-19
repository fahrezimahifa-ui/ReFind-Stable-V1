<?php

namespace App\Http\Controllers;

use App\Exceptions\ContractError;
use App\Repositories\HandoverReadRepository;
use App\Services\AuthService;
use App\Services\PickupService;
use App\Support\ClaimStages;
use App\Support\Contract;
use Illuminate\Http\Request;

/** /api/handovers.php (native api/handovers.php). Fails closed (501) unless the Phase 7 pickup stage is enabled. */
final class HandoverController
{
    private static function gate(): void
    {
        if (! ClaimStages::has('pickup')) {
            throw new ContractError('This operation is not available in the proof of concept.', 501, 'feature_pending');
        }
    }

    public function index(Request $request, AuthService $auth, HandoverReadRepository $handovers)
    {
        $u = $auth->require();
        self::gate();
        Contract::allowed($request->query(), ['id', 'status', 'q', 'page', 'limit']);

        return Contract::json($handovers->listing($request->query(), $auth->identity($u)));
    }

    public function mutate(Request $request, AuthService $auth, PickupService $pickups)
    {
        $u = $auth->require('BUILDING_MANAGEMENT');
        self::gate();
        Contract::allowed($request->query(), ['id', 'status', 'q', 'page', 'limit']);

        return Contract::json($pickups->mutate(['user_id' => (string) $u->user_id, 'role' => $u->role], $request));
    }
}
