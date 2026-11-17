<?php

use App\Exceptions\ContractError;
use App\Http\Middleware\ApiMethodBoundary;
use App\Http\Middleware\LegacyMethod;
use App\Http\Middleware\ReFindRequestGuard;
use App\Http\Middleware\SecurityHeaders;
use App\Support\Contract;
use App\Support\Workspace;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;

Workspace::profile();
$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php')
    ->withMiddleware(function (Middleware $m): void {
        $m->remove([TrimStrings::class, ConvertEmptyStringsToNull::class, HandleCors::class]);
        $m->prepend(ApiMethodBoundary::class);
        $m->append(SecurityHeaders::class);
        $m->web(prepend: [LegacyMethod::class], replace: [PreventRequestForgery::class => ReFindRequestGuard::class]);
    })
    ->withExceptions(function (Exceptions $e): void {
        $e->render(fn (Throwable $error, Request $request) => Contract::error($error, $request));
        $e->dontReport([ContractError::class]);
    })->create();
$app->useStoragePath(Workspace::storage());

return $app;
