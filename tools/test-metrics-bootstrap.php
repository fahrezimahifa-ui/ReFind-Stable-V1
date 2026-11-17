<?php

use App\Database\PinnedMariaDbConnection;

require __DIR__.'/academic-bootstrap.php';
$manager = $app->make('db');
if ($manager->getConnections() !== []) {
    throw new RuntimeException('Metrics boot cached a default connection.');
}
$db = $manager->connection();
if (! $db instanceof PinnedMariaDbConnection || ! $db->getRawPdo() instanceof Closure) {
    throw new RuntimeException('Metrics changed guarded lazy connection creation.');
}
if (config('refind.query_metrics') !== (getenv('REFIND_QUERY_METRICS') !== '0')) {
    throw new RuntimeException('Metrics configuration mismatch.');
}
VerificationReceipt::complete(4, ['metrics_enabled' => config('refind.query_metrics'), 'connection_class' => get_class($db), 'pdo_resolved' => false]);
echo "Guarded lazy metrics bootstrap verified.\n";
