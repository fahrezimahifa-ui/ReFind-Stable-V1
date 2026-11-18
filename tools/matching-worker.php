<?php

use App\Services\ReportService;
use App\Support\Workspace;
use Illuminate\Http\Request;

require __DIR__.'/academic-bootstrap.php';
if (Workspace::profile()['profile'] !== 'academic-test') {
    throw new RuntimeException('Synthetic B-test worker only.');
}
$path = $argv[1] ?? '';
Workspace::assertPath($path);
$parent = dirname($path);
if (! str_starts_with(Workspace::norm($parent).'/', Workspace::norm(Workspace::storage().'/tmp').'/') || ! preg_match('/^matching-concurrency-[a-f0-9]{32}$/D', basename($parent)) || ! preg_match('/^worker-[12]\.json$/D', basename($path))) {
    throw new RuntimeException('Worker fixture path refused.');
}
$data = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
if (! in_array($data['actor'] ?? null, [1, 2], true)) {
    throw new RuntimeException('Synthetic worker actor required.');
}
$ready = $path.'.ready';
$handle = fopen($ready, 'xb');
if (! $handle || fwrite($handle, 'ready') !== 5) {
    throw new RuntimeException('Worker readiness failed.');
}
fclose($handle);
$deadline = microtime(true) + 12;
while (! is_file($parent.'/go')) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Worker barrier timed out.');
    }
    usleep(20000);
}
$result = app(ReportService::class)->mutate(['user_id' => (string) $data['actor'], 'role' => 'STUDENT'], Request::create('/api/items.php', 'POST', $data['data']));
echo json_encode(['ok' => true, 'result' => $result], JSON_THROW_ON_ERROR);
