<?php

namespace App\Http\Middleware;

use App\Exceptions\ContractError;
use App\Services\AuthService;
use App\Support\Contract;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

final class ReFindCsrfMiddleware extends PreventRequestForgery
{
    public function handle($request, \Closure $next)
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
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
        $h = $request->header('X-CSRF-Token');
        $b = $request->request->all()['_csrf'] ?? null;
        $token = $h ?? $b;
        $current = $request->session()->token();
        $valid = is_string($current) && is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token) && hash_equals($current, $token) && ! ($h !== null && $b !== null && $h !== $b);
        if ($request->headers->has('Origin')) {
            $o = parse_url($request->header('Origin'));
            $scheme = $request->getScheme();
            $valid = $valid && $o && ($o['scheme'] ?? '') === $scheme && strtolower($o['host'] ?? '') === strtolower($request->getHost()) && ($o['port'] ?? ($scheme === 'https' ? 443 : 80)) === $request->getPort();
        }
        if (! $valid) {
            throw new ContractError('Your session changed. Reload the page and try again.', 403, 'csrf_invalid');
        }

        return $next($request);
    }
}
