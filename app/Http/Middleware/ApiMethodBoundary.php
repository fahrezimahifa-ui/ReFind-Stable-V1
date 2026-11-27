<?php

namespace App\Http\Middleware;

use App\Exceptions\ContractError;

final class ApiMethodBoundary
{
    public function handle($request, \Closure $next)
    {
        $path = $request->path();
        $methods = null;
        if (preg_match('~^api/(session|references|photos|avatar)\.php$~D', $path)) {
            $methods = ['GET'];
        } elseif (preg_match('~^api/(auth|register|visual-search|staff-visual-search)\.php$~D', $path)) {
            $methods = ['POST'];
        } elseif (preg_match('~^api/(account|items|claims|staff|handovers|notifications)\.php$~D', $path)) {
            $methods = ['GET', 'POST'];
        } elseif ($path === '/' || preg_match('~^(?:index|login|register|browse|item|report|claim|my-reports|admin|history|notifications|profile|settings)\.html$~iD', $path)) {
            $methods = ['GET', 'HEAD'];
        }
        if ($methods && ! in_array($request->method(), $methods, true)) {
            throw new ContractError('Method not allowed.', 405, 'method_not_allowed', [], ['Allow' => implode(', ', $methods)]);
        }

        return $next($request);
    }
}
