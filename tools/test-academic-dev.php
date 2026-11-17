<?php

use App\Support\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/academic-bootstrap.php';
try {
    if (Workspace::profile()['profile'] !== 'academic-dev') {
        throw new RuntimeException('B dev only.');
    }
    DB::select('SELECT user_id FROM users LIMIT 1');
    $checks = 1;
    foreach (['INSERT INTO _write_operations(operation_id,manifest_sha256)SELECT operation_id,manifest_sha256 FROM _write_operations WHERE 1=0', 'UPDATE users SET full_name=full_name WHERE 1=0', 'DELETE FROM items WHERE 1=0'] as $sql) {
        try {
            DB::statement($sql);
            throw new RuntimeException('SAFETY GATE FAILED: development writes unexpectedly permitted.');
        } catch (QueryException$e) {
            if ((int) $e->errorInfo[1] !== 1142) {
                throw $e;
            }$checks++;
        }
    }
    echo json_encode(['academic_dev_read_only_checks_passed' => $checks]);

} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
