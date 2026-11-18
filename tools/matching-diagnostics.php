<?php

use App\Foundation\WriteFailure;
use App\Support\Workspace;
use Illuminate\Database\QueryException;

/** CLI-only diagnostics; never registered in the application or exposed by HTTP. */
function matching_safe_text(string $text): string
{
    $text = preg_replace("/'(?:''|[^'])*'/s", "'[redacted]'", $text);
    $text = preg_replace('/"(?:\\\\.|[^"\\\\])*"/s', '"[redacted]"', $text);
    $text = preg_replace('/(?:mysql|mariadb):[^\s]+/i', '[redacted DSN]', $text);
    $text = preg_replace('/((?:password|token|secret|authorization)\s*[=:]\s*)\S+/i', '$1[redacted]', $text);

    return $text;
}

function matching_query_context(string $sql, array $bindings): array
{
    return ['template' => matching_safe_text($sql), 'template_sha256' => hash('sha256', $sql), 'binding_count' => count($bindings), 'binding_types' => array_map(get_debug_type(...), $bindings)];
}

function matching_capture_failure(Throwable $error, array $context): string
{
    if (PHP_SAPI !== 'cli' || Workspace::profile()['profile'] !== 'academic-test') {
        throw new RuntimeException('Private matching diagnostics require synthetic B-test CLI.');
    }
    $chain = [];
    for ($current = $error; $current !== null; $current = $current->getPrevious()) {
        $info = $current instanceof PDOException ? ($current->errorInfo ?? null) : null;
        $message = $current instanceof QueryException ? 'Database query failed; driver details are recorded in the nested chain.' : matching_safe_text($current->getMessage());
        $entry = ['class' => get_class($current), 'code' => $current->getCode(), 'message' => $message, 'file' => $current->getFile(), 'line' => $current->getLine(), 'sqlstate' => $info[0] ?? null, 'driver_code' => $info[1] ?? null];
        if (isset($info[2])) {
            $entry['driver_message'] = matching_safe_text($info[2]);
            preg_match_all('/(?:column|field|table) [\'`]([a-z_][a-z0-9_]*)[\'`]/i', $info[2], $matches);
            $entry['identifiers'] = $matches[1];
        }
        if ($current instanceof QueryException) {
            $entry['failed_query'] = matching_query_context($current->getSql(), $current->getBindings());
        }
        $entry['trace'] = array_map(static fn ($frame) => array_intersect_key($frame, array_flip(['file', 'line', 'class', 'function', 'type'])), $current->getTrace());
        $chain[] = $entry;
    }
    $record = ['kind' => 'private_cli_failure_diagnostic', 'accepted' => false, 'timestamp_utc' => gmdate('c'), 'context' => $context, 'operation_id' => $error instanceof WriteFailure ? $error->operationId : null, 'uncertain' => $error instanceof WriteFailure ? $error->uncertain : null, 'exception_chain' => $chain];
    $body = json_encode($record, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
    $profile = Workspace::profile();
    foreach (['password', 'key'] as $key) {
        if (isset($profile[$key]) && str_contains($body, $profile[$key])) {
            throw new RuntimeException('Diagnostic secret redaction failed.');
        }
    }
    $file = Workspace::storage().'/snapshots/matching-diagnostic-'.bin2hex(random_bytes(16)).'.json';
    Workspace::assertPath($file);
    $handle = fopen($file, 'xb');
    if (! $handle) {
        throw new RuntimeException('Cannot create private diagnostic.');
    }
    try {
        if (fwrite($handle, $body) !== strlen($body) || ! fflush($handle) || ! fsync($handle)) {
            throw new RuntimeException('Cannot finish private diagnostic.');
        }
    } finally {
        fclose($handle);
    }

    return $file;
}
