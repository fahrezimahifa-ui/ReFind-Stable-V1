<?php

namespace App\Http\Controllers;

use App\Exceptions\ContractError;
use App\Repositories\ClaimReadRepository;
use App\Services\AuthService;
use App\Support\Contract;
use Illuminate\Http\Request;

final class ClaimReadController
{
    public function claims(Request $request, AuthService $auth, ClaimReadRepository $claims)
    {
        $user = $auth->require();
        Contract::allowed($request->query(), ['id', 'itemId', 'page', 'limit']);

        return Contract::json($claims->listing($request->query(), $auth->identity($user)));
    }

    public function staff(Request $request, AuthService $auth, ClaimReadRepository $claims)
    {
        $user = $auth->require('BUILDING_MANAGEMENT');
        $query = $request->query();
        Contract::allowed($query, ['resource', 'id', 'q', 'status', 'type', 'page', 'limit']);
        $resource = $query['resource'] ?? 'reports';
        if (! in_array($resource, ['claims', 'reports'], true)) {
            throw new ContractError('Invalid staff resource.', 400);
        }
        if ($resource === 'reports') {
            throw new ContractError('This operation is not available in the proof of concept.', 501, 'feature_pending');
        }
        if (isset($query['id'])) {
            return Contract::json(['claim' => $claims->staffDetail(Contract::id($query['id']), $auth->identity($user))]);
        }

        return Contract::json($claims->queue($query));
    }
}
