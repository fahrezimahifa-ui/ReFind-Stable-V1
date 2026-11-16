<?php

namespace App\Http\Middleware;

use App\Exceptions\ContractError;

final class LegacyMethod
{
    public function handle($request, \Closure $next)
    {
        $allowed = $request->route()->defaults['legacy_methods'] ?? ['GET', 'HEAD'];
        if (! in_array($request->method(), $allowed, true)) {
            throw new ContractError('Method not allowed.', 405, 'method_not_allowed', [], ['Allow' => implode(', ', $allowed)]);
        }

        return $next($request);
    }
}
