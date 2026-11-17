<?php

use App\Support\Workspace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Cache;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (Workspace::profile()['profile'] !== 'academic-test') {
    throw new RuntimeException('Synthetic test profile required.');
}
$input = json_decode(file_get_contents(Workspace::storage().'/concurrency-input.json'), true, 16, JSON_THROW_ON_ERROR);
$id = CookieValuePrefix::remove(app('encrypter')->decrypt(urldecode($input['cookie']), false));
if (! is_string($id) || ! preg_match('/^[a-zA-Z0-9]{40}$/D', $id)) {
    throw new RuntimeException('Invalid synthetic session ID.');
}
$lock = Cache::store('file')->lock('session:'.$id, 65);
if (! $lock->get()) {
    throw new RuntimeException('Cannot acquire synthetic session lock.');
}
try {
    file_put_contents(Workspace::storage().'/lock-ready.json', json_encode(['acquired' => true]));
    usleep(1000000);
} finally {
    $lock->release();
}
echo "Synthetic session lock released.\n";
