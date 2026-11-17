<?php

namespace App\Http\Middleware;

use App\Exceptions\ContractError;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

final class ReFindCsrfMiddleware extends PreventRequestForgery
{
    public function handle($request, \Closure $next)
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
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
