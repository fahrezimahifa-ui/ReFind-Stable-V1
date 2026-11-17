<?php

namespace App\Http\Middleware;

use App\Support\ReportQueryCounter;
use App\Support\Workspace;

final class ReportQueryMetrics
{
    public function handle($request, \Closure $next)
    {
        if (! config('refind.query_metrics') || ! $request->is('api/items.php')) {
            return $next($request);
        }
        $counter = app(ReportQueryCounter::class);
        $counter->count = 0;
        $counter->sqlMs = 0;
        $start = hrtime(true);
        $response = null;
        try {
            return $response = $next($request);
        } finally {
            $action = $request->request->all()['action'] ?? null;
            $record = ['method' => $request->method(), 'action' => is_string($action) && in_array($action, ['create', 'update', 'delete'], true) ? $action : null, 'status' => $response?->getStatusCode(), 'queries' => $counter->count, 'sql_ms' => round($counter->sqlMs, 3), 'elapsed_ms' => round((hrtime(true) - $start) / 1e6, 3), 'php_peak_mib' => round(memory_get_peak_usage(true) / 1048576, 2)];
            // Observability must not turn a known commit into an apparent application failure.
            try {
                @file_put_contents(Workspace::storage().'/logs/report-queries.log', json_encode($record, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
            } catch (\Throwable) {
            }
        }
    }
}
