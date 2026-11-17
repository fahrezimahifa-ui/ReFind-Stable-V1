<?php

use App\Foundation\MediaJournal;
use App\Foundation\WriteCoordinator;
use Illuminate\Support\Facades\DB;

require __DIR__.'/academic-bootstrap.php';
try {
    $id = $argv[1] ?? '';
    $operation = (new MediaJournal)->load($id);
    $result = (new WriteCoordinator(DB::connection()))->reconcile($operation);
    echo json_encode(['outcome' => $result, 'state' => $operation->snapshot()['state']]);

} catch (Throwable $error) {
    fwrite(STDERR, 'Reconciliation failed; inspect the private operation state.');
    exit(1);
}
