<?php

namespace App\Http\Middleware;

use App\Exceptions\ContractError;
use App\Services\AuthService;
use App\Support\Contract;

final class MutationPreconditions
{
    public function handle($request, \Closure $next)
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }
        if (! isset($request->route()->defaults['access']) || $request->route()->defaults['access'] === 'none') {
            throw new ContractError('Mutation access policy is missing.', 500, 'server_error');
        }
        $auth = app(AuthService::class);
        $access = $request->route()->defaults['access'] ?? 'none';
        if ($access === 'registration') {
            throw new ContractError('Account creation is currently unavailable. Please log in with an existing account.', 403, 'registration_disabled');
        }
        if ($access === 'auth_action') {
            Contract::allowed($request->query(), []);
            Contract::allowed($request->request->all(), ['action', 'email', 'password', 'return', '_csrf']);
            $action = Contract::text($request->request->all(), 'action', 20);
            if ($action === 'login') {
                if ($auth->current()) {
                    throw new ContractError('Log out before signing in with another account.', 409, 'already_authenticated');
                }
            } elseif ($action === 'logout') {
                $auth->require();
            } else {
                throw new ContractError('Invalid authentication action.', 400, 'invalid_request');
            }
        } elseif ($access !== 'none') {
            $auth->require(in_array($access, ['STUDENT', 'BUILDING_MANAGEMENT'], true) ? $access : null);
        }

        return $next($request);
    }
}
