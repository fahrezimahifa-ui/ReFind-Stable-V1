<?php

require __DIR__.'/academic-bootstrap.php';
require __DIR__.'/AcademicSnapshot.php';
try {
    $records = json_decode(file_get_contents('C:/ReFind-Laravel/state/academic-processes.json'), true);
    foreach ($records as $key => $record) {
        if (str_starts_with($key, 'web-')) {
            throw new RuntimeException('Stop owned web instances before restoration.');
        }
    }
    AcademicSnapshot::restore(AcademicSnapshot::admin(), $argv[1] ?? '');
    echo "B test snapshot restored and verified.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
