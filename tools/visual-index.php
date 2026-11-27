<?php

use App\Support\Workspace;
use App\Visual\Budget;
use App\Visual\Calibration;
use App\Visual\IndexStore;
use App\Visual\Preprocess;
use App\Visual\ProviderError;
use App\Visual\VisualConfig;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/*
 * Photo-search indexer (profiles with the `visual` stage only; never inside a web request).
 *   status               stage, config, key present/absent (never the key), model key, calibration, index counts, budget
 *   sync [--limit N]     one pass: embed public ITEM photos not yet indexed (default 10), drop entries whose photo is gone
 *   loop                 sync every 30 s; exits when the profile's web ports stay closed or the stage/config goes away
 *   list-models          one GET to the provider's model list (ids and any modality hints)
 *   probe MODEL [FORMAT] one tiny image-embedding call with a synthetic photo: dimensions, latency, usage
 * Prints JSON. Writes only state/<profile>/visual/ and the shared budget file.
 */
require __DIR__.'/academic-bootstrap.php';

const VISUAL_FAILURES = 'failures.json';

function visual_out(array $data): void
{
    fwrite(STDOUT, json_encode($data, JSON_UNESCAPED_SLASHES).PHP_EOL);
}

function visual_raw_config(): array
{
    $file = Workspace::storage().'/visual.json';
    Workspace::assertPath($file);
    if (! is_file($file)) {
        throw new RuntimeException('No visual.json for this profile.');
    }

    return json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
}

/** Eligible photos: every public ITEM photo with a well-formed private path (search applies visibility later). */
function visual_eligible(): array
{
    $out = [];
    foreach (DB::select("SELECT photo_id,file_path FROM item_photos WHERE photo_kind='ITEM' ORDER BY photo_id") as $row) {
        if (preg_match('~^items/[a-f0-9]{32}\.(jpg|png|webp)$~D', $row->file_path)) {
            $out[(int) $row->photo_id] = $row->file_path;
        }
    }

    return $out;
}

function visual_status(): array
{
    $out = ['profile' => Workspace::profile()['profile'], 'stage' => VisualConfig::enabled()];
    $config = VisualConfig::current();
    $out['config'] = $config === null ? 'missing or invalid' : 'valid';
    if ($config === null) {
        return $out;
    }
    $out['provider'] = $config['provider'];
    $out['key'] = $config['provider'] === 'fake' ? 'not needed' : (VisualConfig::keyPresent($config) ? 'present' : 'absent');
    $out['auto_index'] = $config['auto_index'];
    try {
        $provider = VisualConfig::makeProvider($config);
        $out['model_key'] = $provider->modelKey();
        $out['calibration'] = Calibration::load(Calibration::dir(), $provider->modelKey()) ? 'present' : 'missing';
        $meta = (new IndexStore(VisualConfig::indexDir($provider->modelKey())))->load($provider->modelKey());
        $eligible = visual_eligible();
        $ready = $meta ? count(array_filter($eligible, fn ($path, $id) => ($meta['entries'][(string) $id]['path'] ?? null) === $path, ARRAY_FILTER_USE_BOTH)) : 0;
        $failures = visual_failures($provider->modelKey());
        $out['photos'] = ['eligible' => count($eligible), 'ready' => $ready, 'pending' => count($eligible) - $ready, 'failing' => count(array_intersect_key($failures, $eligible))];
        $out['budget'] = Budget::forProfile($config)->summary();
    } catch (Throwable $e) {
        $out['error'] = get_class($e);
    }

    return $out;
}

function visual_failures(string $key): array
{
    $file = VisualConfig::indexDir($key).'/'.VISUAL_FAILURES;
    $data = is_file($file) ? json_decode((string) file_get_contents($file), true, 8) : [];

    return is_array($data) ? $data : [];
}

function visual_save_failures(string $key, array $failures): void
{
    $dir = VisualConfig::indexDir($key);
    if (! is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    file_put_contents($dir.'/'.VISUAL_FAILURES, json_encode($failures, JSON_UNESCAPED_SLASHES));
}

/** One indexing pass. Returns counts; never prints photo contents, paths beyond IDs, or the key. */
function visual_sync(int $limit): array
{
    $config = VisualConfig::require();
    $provider = VisualConfig::makeProvider($config);
    $store = new IndexStore(VisualConfig::indexDir($provider->modelKey()));
    $result = $store->exclusive(function () use ($config, $provider, $store, $limit) {
        $key = $provider->modelKey();
        $meta = $store->load($key);
        $eligible = visual_eligible();
        $failures = array_intersect_key(visual_failures($key), $eligible);
        $now = time();
        $keep = [];
        foreach ($eligible as $id => $path) {
            $entry = $meta['entries'][(string) $id] ?? null;
            if ($entry && $entry['path'] === $path) {
                $keep[$id] = ['path' => $path, 'sha256' => $entry['sha256'], 'slot' => $entry['slot'], 'at' => $entry['at']];
            }
        }
        $dropped = $meta ? count($meta['entries']) - count($keep) : 0;
        $todo = array_slice(array_filter(array_diff_key($eligible, $keep), fn ($id) => ($failures[$id]['next_at'] ?? 0) <= $now, ARRAY_FILTER_USE_KEY), 0, $limit, true);
        $done = 0;
        $stop = null;
        $budget = Budget::forProfile($config);
        foreach (array_chunk($todo, 4, true) as $chunk) {
            $jpegs = [];
            $hashes = [];
            foreach ($chunk as $id => $path) {
                $file = Workspace::media().'/'.$path;
                try {
                    Workspace::assertPath($file);
                    $bytes = (string) file_get_contents($file);
                    $hashes[$id] = hash('sha256', $bytes);
                    $jpegs[$id] = Preprocess::prepare($bytes);
                } catch (Throwable) {
                    $failures[$id] = ['attempts' => ($failures[$id]['attempts'] ?? 0) + 1, 'reason' => 'media', 'next_at' => $now + 86400];
                }
            }
            if (! $jpegs) {
                continue;
            }
            // The offline fake engine costs nothing, so only the hosted engine draws on the spending budget.
            if ($config['provider'] !== 'fake' && ($wait = $budget->reserve(null, count($jpegs))) > 0) {
                $stop = 'budget_wait_'.$wait.'s';
                break;
            }
            DB::disconnect(); // never hold a database session across a provider call
            try {
                $vectors = $provider->embed(array_values($jpegs));
            } catch (ProviderError $e) {
                if (in_array($e->reason, ['quota', 'project'], true)) {
                    $budget->pause($e->retryAfter > 0 ? $e->retryAfter : 900);
                    $stop = 'provider_'.$e->reason;
                    break;
                }
                foreach (array_keys($jpegs) as $id) {
                    $attempts = ($failures[$id]['attempts'] ?? 0) + 1;
                    $failures[$id] = ['attempts' => $attempts, 'reason' => $e->reason, 'next_at' => $now + [60, 300, 1800, 21600][min(3, $attempts - 1)]];
                }

                continue;
            }
            foreach (array_keys($jpegs) as $position => $id) {
                $keep[$id] = ['path' => $chunk[$id], 'sha256' => $hashes[$id], 'vector' => $vectors[$position]];
                unset($failures[$id]);
                $done++;
            }
        }
        $dims = $meta['dimensions'] ?? null;
        foreach ($keep as $entry) {
            if (isset($entry['vector'])) {
                $dims = count($entry['vector']);
                break;
            }
        }
        if ($dims !== null && ($done > 0 || $dropped > 0 || $meta === null)) {
            $store->commit($key, $dims, $keep, $meta);
        }
        visual_save_failures($key, $failures);

        return ['indexed' => $done, 'dropped' => $dropped, 'ready' => count($keep), 'eligible' => count($eligible), 'failing' => count($failures), 'stopped' => $stop];
    });

    return $result ?? ['skipped' => 'another indexer holds the lock'];
}

function visual_ports_open(): bool
{
    foreach (Workspace::definition()['ports'] as $port) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
        if ($socket) {
            fclose($socket);

            return true;
        }
    }

    return false;
}

$action = $argv[1] ?? 'status';
if (! VisualConfig::enabled() && $action !== 'status') {
    throw new RuntimeException('Photo search is not enabled for this profile.');
}
if ($action === 'status') {
    visual_out(visual_status());
} elseif ($action === 'sync') {
    $limit = 10;
    if (($argv[2] ?? '') === '--limit' && preg_match('/^[1-9][0-9]{0,3}$/D', $argv[3] ?? '')) {
        $limit = (int) $argv[3];
    }
    visual_out(visual_sync($limit));
} elseif ($action === 'loop') {
    $closed = 0;
    while (true) {
        $config = VisualConfig::current();
        if (! VisualConfig::enabled() || $config === null || ! $config['auto_index']) {
            visual_out(['loop' => 'stopped', 'reason' => 'disabled']);
            break;
        }
        $closed = visual_ports_open() ? 0 : $closed + 1;
        if ($closed >= 2) {
            visual_out(['loop' => 'stopped', 'reason' => 'web worker gone']);
            break;
        }
        try {
            $r = visual_sync(10);
            if (($r['indexed'] ?? 0) || ($r['dropped'] ?? 0) || ($r['stopped'] ?? null)) {
                visual_out(['at' => gmdate('c')] + $r);
            }
        } catch (Throwable $e) {
            visual_out(['at' => gmdate('c'), 'error' => get_class($e)]);
        }
        DB::disconnect();
        sleep(30);
    }
} elseif ($action === 'list-models') {
    $raw = visual_raw_config();
    $config = VisualConfig::parse(['provider' => 'inferhub', 'strategy' => 'image_embedding', 'embedding_model' => 'placeholder'] + array_intersect_key($raw, array_flip(['base_url', 'key_file'])));
    $response = Http::withToken(VisualConfig::key($config))->acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting()->get($config['base_url'].'/models');
    if (! $response->successful()) {
        visual_out(['models' => null, 'http_status' => $response->status()]);
        exit(1);
    }
    $models = [];
    foreach ((array) ($response->json('data') ?? []) as $m) {
        if (! is_array($m) || ! is_string($m['id'] ?? null)) {
            continue;
        }
        $hints = array_intersect_key($m, array_flip(['type', 'object', 'owned_by', 'modalities', 'input_modalities', 'output_modalities', 'architecture', 'pricing', 'context_length', 'task', 'capabilities']));
        $models[] = ['id' => $m['id']] + $hints;
    }
    visual_out(['count' => count($models), 'models' => $models]);
} elseif ($action === 'probe') {
    $raw = visual_raw_config();
    $model = $argv[2] ?? '';
    $format = $argv[3] ?? 'openai';
    $config = VisualConfig::parse(['provider' => 'inferhub', 'strategy' => 'image_embedding', 'embedding_model' => $model, 'request_format' => $format] + array_intersect_key($raw, array_flip(['base_url', 'key_file', 'timeout_seconds', 'daily_cap', 'install_per_minute', 'user_per_minute'])));
    if (Budget::forProfile($config)->reserve(null, 1) > 0) {
        throw new RuntimeException('Budget exhausted for now.');
    }
    $img = imagecreatetruecolor(96, 96);
    imagefill($img, 0, 0, imagecolorallocate($img, 240, 240, 240));
    imagefilledellipse($img, 48, 48, 60, 40, imagecolorallocate($img, 30, 60, 160));
    ob_start();
    imagejpeg($img, null, 85);
    $jpeg = (string) ob_get_clean();
    $started = microtime(true);
    $response = Http::withToken(VisualConfig::key($config))->acceptJson()->asJson()->connectTimeout(5)->timeout($config['timeout_seconds'])->withoutRedirecting()
        ->post($config['base_url'].'/embeddings', \App\Visual\InferHubProvider::imageEmbeddingRequest($config, [$jpeg]));
    $out = ['model' => $model, 'format' => $format, 'http_status' => $response->status(), 'seconds' => round(microtime(true) - $started, 3)];
    if ($response->successful()) {
        try {
            $vectors = \App\Visual\InferHubProvider::parseEmbeddings($response->json(), 1, null);
            $out['dimensions'] = count($vectors[0]);
        } catch (ProviderError $e) {
            $out['parse'] = $e->reason;
        }
        $out['usage'] = $response->json('usage');
    } else {
        $err = $response->json('error');
        $out['error_type'] = is_array($err) ? ($err['type'] ?? $err['code'] ?? null) : null;
    }
    visual_out($out);
} elseif ($action === 'probe-gemini') {
    // Speed probe: K single-photo embedding calls with licensed test photos (never prints the key).
    $config = VisualConfig::parse(['provider' => 'gemini', 'timeout_seconds' => 20]);
    $provider = new \App\Visual\GeminiProvider($config);
    $times = [];
    foreach (['demo-reference', 'demo-positive', 'pair-1-reference'] as $name) {
        if (Budget::forProfile($config)->reserve(null, 1) > 0) {
            throw new RuntimeException('Budget exhausted for now.');
        }
        $started = microtime(true);
        try {
            $v = $provider->embed([Preprocess::prepare((string) file_get_contents(Workspace::ROOT.'/fixtures/visual-commons/'.$name.'.jpg'))]);
            $times[] = ['photo' => $name, 'seconds' => round(microtime(true) - $started, 3), 'dimensions' => count($v[0])];
        } catch (ProviderError $e) {
            $times[] = ['photo' => $name, 'seconds' => round(microtime(true) - $started, 3), 'error' => $e->reason, 'http_status' => $e->status];
        }
    }
    visual_out(['model' => $config['embedding_model'], 'calls' => $times]);
} elseif ($action === 'probe-describe') {
    // One describe call with a licensed test photo: shows the description, latency and token usage (never the key).
    $raw = visual_raw_config();
    $image = $argv[3] ?? Workspace::ROOT.'/fixtures/visual-commons/demo-reference.jpg';
    Workspace::assertPath($image);
    $config = VisualConfig::parse(['provider' => 'inferhub', 'strategy' => 'describe_match', 'vision_model' => $argv[2] ?? ''] + array_intersect_key($raw, array_flip(['base_url', 'key_file', 'timeout_seconds', 'daily_cap', 'install_per_minute', 'user_per_minute'])));
    if (Budget::forProfile($config)->reserve(null, 1) > 0) {
        throw new RuntimeException('Budget exhausted for now.');
    }
    $provider = new \App\Visual\InferHubProvider($config);
    $started = microtime(true);
    try {
        $provider->embed([Preprocess::prepare((string) file_get_contents($image))]);
        visual_out(['model' => $argv[2], 'seconds' => round(microtime(true) - $started, 3), 'description' => $provider->descriptions[0], 'usage' => $provider->usage]);
    } catch (ProviderError $e) {
        visual_out(['model' => $argv[2], 'seconds' => round(microtime(true) - $started, 3), 'error' => $e->reason, 'http_status' => $e->status]);
    }
} else {
    throw new RuntimeException('Unknown action.');
}
