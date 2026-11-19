<?php

namespace App\Http\Controllers;

use App\Exceptions\ContractError;
use App\Repositories\ClaimReadRepository;
use App\Repositories\StaffReportRepository;
use App\Services\AuthService;
use App\Support\ClaimStages;
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

    public function staff(Request $request, AuthService $auth, ClaimReadRepository $claims, StaffReportRepository $reports)
    {
        $user = $auth->require('BUILDING_MANAGEMENT');
        $query = $request->query();
        Contract::allowed($query, ['resource', 'id', 'q', 'status', 'type', 'page', 'limit']);
        $resource = $query['resource'] ?? 'reports';
        if (! in_array($resource, ['claims', 'reports'], true)) {
            throw new ContractError('Invalid staff resource.', 400);
        }
        if ($resource === 'reports') {
            // BM report queue/detail arrive with the Phase 8 status stage; accepted profiles keep the 501 contract.
            if (! ClaimStages::has('status')) {
                throw new ContractError('This operation is not available in the proof of concept.', 501, 'feature_pending');
            }

            return Contract::json(isset($query['id']) ? $reports->detail(Contract::id($query['id']), $auth->identity($user)) : $reports->queue($query, $auth->identity($user)));
        }
        if (isset($query['id'])) {
            return Contract::json(['claim' => $claims->staffDetail(Contract::id($query['id']), $auth->identity($user))]);
        }

        return Contract::json($claims->queue($query));
    }
}
