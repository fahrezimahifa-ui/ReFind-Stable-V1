<?php

namespace App\Http\Middleware;

final class ReFindRequestGuard
{
    public function handle($request, \Closure $next)
    {
        return app(MutationPreconditions::class)->handle($request, fn ($request) => app(ReFindCsrfMiddleware::class)->handle($request, $next));
    }
}
