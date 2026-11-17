<?php

// A subprocess-only negative fixture: both faults precede its local try/catch.
require __DIR__.'/academic-bootstrap.php';
if (($argv[1] ?? '') === 'fatal') {
    trigger_error('Deliberate early CLI fatal initialization fixture.', E_USER_ERROR);
}
throw new RuntimeException('Deliberate early CLI initialization fixture.');
try {
    echo "PASS must never be reached\n";
    VerificationReceipt::complete(1);
} catch (Throwable) {
    exit(1);
}
