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
    foreach (['UPDATE _write_operations SET manifest_sha256=manifest_sha256 WHERE 1=0', 'UPDATE users SET full_name=full_name WHERE 1=0', 'UPDATE items SET reporter_user_id=reporter_user_id WHERE 1=0'] as $sql) {
        try {
            DB::statement($sql);
            throw new RuntimeException('SAFETY GATE FAILED: development writes unexpectedly permitted.');
        } catch (QueryException$e) {
            if (!in_array((int) $e->errorInfo[1],[1142,1143],true)) {
                throw $e;
            }$checks++;
        }
    }
    echo json_encode(['academic_dev_boundary_checks_passed' => $checks]);

} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
VerificationReceipt::complete($checks, ['academic_dev_boundary_checks_passed' => $checks]);
