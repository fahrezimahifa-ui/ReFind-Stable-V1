<?php

namespace App\Http\Controllers;

use App\Exceptions\ContractError;
use App\Services\AuthService;
use App\Services\ClaimService;
use App\Support\ClaimStages;
use App\Support\Contract;
use Illuminate\Http\Request;

/** POST /api/claims.php (native api/claims.php). Role and CSRF already ran in the request guard. */
final class ClaimController
{
    public function mutate(Request $request, AuthService $auth, ClaimService $claims)
    {
        $u = $auth->require('STUDENT');
        if (! ClaimStages::has('student')) {
            // Accepted profiles keep the pre-Phase-6 contract exactly.
            throw new ContractError('This operation is not available in the proof of concept.', 501, 'feature_pending');
        }
        Contract::allowed($request->query(), ['id', 'itemId', 'page', 'limit']);
        $result = $claims->mutate(['user_id' => (string) $u->user_id, 'role' => $u->role], $request);

        return Contract::json($result, $result['resubmitted'] || isset($result['status']) ? 200 : 201);
    }
}
