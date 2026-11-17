<?php

use App\Support\Workspace;

if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--auth-state-only') {
    exit(1);
}
require __DIR__.'/../vendor/autoload.php';

if (Workspace::profile()['profile'] !== 'academic-test') {
    throw new RuntimeException('Test profile required.');
}
$file = Workspace::storage().'/auth/attempts.json';
Workspace::assertPath($file);
$handle = fopen($file, 'c+');
if (! $handle || ! flock($handle, LOCK_EX)) {
    throw new RuntimeException('Cannot lock synthetic limiter fixture.');
}try {
    ftruncate($handle, 0);
    rewind($handle);
    fwrite($handle, '{}');
    fflush($handle);
} finally {
    flock($handle, LOCK_UN);
    fclose($handle);
}echo "Isolated synthetic test limiter reset.\n";
