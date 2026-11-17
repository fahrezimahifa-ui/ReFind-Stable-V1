<?php

namespace App\Http\Middleware;

use App\Support\Contract;

final class SecurityHeaders
{
    public function handle($request, \Closure $next)
    {
        $response = $next($request);
        foreach (Contract::headers($request->is('api/*')) as $k => $v) {
            if (! ($k === 'Pragma' && $request->is('api/photos.php') && in_array($response->getStatusCode(), [200, 304], true)) && ! $response->headers->has($k)) {
                $response->headers->set($k, $v);
            }
        }

        return $response;
    }
}
