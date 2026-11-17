<?php

use Illuminate\Contracts\Console\Kernel;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
// Laravel's console renderer can otherwise mask an uncaught standalone CLI error.
$academicCliFailure = static function (Throwable $error): never {
    fwrite(STDERR, 'CLI verification failed: '.get_class($error).' at '.basename($error->getFile()).':'.$error->getLine().PHP_EOL);
    exit(1);
};
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        fwrite(STDERR, "Fatal CLI initialization/verification failure.\n");
        exit(1);
    }
});
set_exception_handler($academicCliFailure);
try {
    require_once __DIR__.'/VerificationReceipt.php';
    VerificationReceipt::begin();
    require __DIR__.'/../vendor/autoload.php';
    $app = require __DIR__.'/../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    // The kernel bootstrap installs Laravel's handler; restore fail-closed CLI exit semantics.
    set_exception_handler($academicCliFailure);
} catch (Throwable $error) {
    $academicCliFailure($error);
}
