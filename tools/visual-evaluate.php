<?php

use App\Support\Workspace;
use App\Visual\FakeProvider;
use App\Visual\GeminiProvider;
use App\Visual\InferHubProvider;
use App\Visual\Preprocess;
use App\Visual\Provider;
use App\Visual\ProviderError;
use App\Visual\Vector;
use App\Visual\VisualConfig;

/*
 * Offline photo-search evaluation (Batch A). No database, no app budget: it has its own hard call allowance.
 *   php tools/visual-evaluate.php SPEC [--fake] [--vision VISION_MODEL] [--timeout S] [--model M] [--format F] [--dimensions D] [--max-calls N]
 *                                      [--latency-sample K] [--dry-run] [--label NAME]
 * SPEC comes from tools/visual-eval-prep.py. Embeddings are cached per model key (private fixtures folder), so a
 * rerun never pays twice. Cutoffs are fitted on the spec's validation split only, then frozen and applied to the
 * held-out splits. Writes report.json + report.md under C:/ReFind-Laravel/evidence/visual/. Never prints the key.
 * "Calls" count photos embedded (the same unit as the app's daily cap).
 */
require __DIR__.'/academic-bootstrap.php';

$args = array_slice($argv, 1);
$spec = null;
$opt = ['fake' => false, 'gemini' => false, 'single-sample' => 0, 'vision' => null, 'timeout' => null, 'model' => null, 'format' => null, 'dimensions' => null, 'max-calls' => 0, 'latency-sample' => 0, 'dry-run' => false, 'label' => null];
for ($i = 0; $i < count($args); $i++) {
    $a = $args[$i];
    if (in_array($a, ['--fake', '--gemini', '--dry-run'], true)) {
        $opt[substr($a, 2)] = true;
    } elseif (str_starts_with($a, '--') && array_key_exists(substr($a, 2), $opt)) {
        $opt[substr($a, 2)] = $args[++$i] ?? null;
    } elseif ($spec === null) {
        $spec = $a;
    } else {
        throw new RuntimeException('Unknown argument.');
    }
}
$specData = json_decode((string) file_get_contents($spec), true, 64, JSON_THROW_ON_ERROR);
foreach ($specData['files'] as $file => $hash) {
    Workspace::assertPath($file);
    if (hash_file('sha256', $file) !== $hash) {
        throw new RuntimeException('Evaluation photo changed since the spec was built: '.basename($file));
    }
}

/** @var Provider $provider */
if ($opt['fake']) {
    $provider = new FakeProvider;
} elseif ($opt['gemini']) {
    $provider = new GeminiProvider(VisualConfig::parse(array_filter(['provider' => 'gemini', 'timeout_seconds' => $opt['timeout'] !== null ? (int) $opt['timeout'] : null, 'dimensions' => $opt['dimensions'] !== null ? (int) $opt['dimensions'] : null], fn ($v) => $v !== null)));
} else {
    $raw = json_decode((string) file_get_contents(Workspace::storage().'/visual.json'), true, 16, JSON_THROW_ON_ERROR);
    foreach (['model' => 'embedding_model', 'format' => 'request_format'] as $from => $to) {
        if ($opt[$from] !== null) {
            $raw[$to] = $opt[$from];
        }
    }
    if ($opt['dimensions'] !== null) {
        $raw['dimensions'] = (int) $opt['dimensions'];
    }
    if ($opt['vision'] !== null) {
        $raw['strategy'] = 'describe_match';
        $raw['vision_model'] = $opt['vision'];
    }
    if ($opt['timeout'] !== null) {
        $raw['timeout_seconds'] = (int) $opt['timeout']; // measure the real latency spread instead of cutting it off
    }
    $raw['provider'] = 'inferhub';
    $provider = new InferHubProvider(VisualConfig::parse($raw));
}
$modelKey = $provider->modelKey();
$cacheDir = Workspace::ROOT.'/fixtures/visual-eval/cache/'.substr(hash('sha256', $modelKey), 0, 16);
if (! is_dir($cacheDir)) {
    mkdir($cacheDir, 0700, true);
    file_put_contents($cacheDir.'/model.json', json_encode(['model_key' => $modelKey], JSON_UNESCAPED_SLASHES));
}

// Which files are queries (embedded one per call, so their latency is a real 1-photo search call) vs gallery-only.
$queryFiles = [];
$galleryFiles = [];
foreach ($specData['splits'] as $split) {
    foreach ($split['queries'] as $q) {
        $queryFiles[$q['file']] = true;
    }
    foreach ($split['gallery'] as $g) {
        $galleryFiles[$g['file']] = true;
    }
}
$cached = fn (string $file) => is_file($cacheDir.'/'.$specData['files'][$file].'.f32');
$todoQueries = array_values(array_filter(array_keys($queryFiles), fn ($f) => ! $cached($f)));
$todoGallery = array_values(array_filter(array_keys(array_diff_key($galleryFiles, $queryFiles)), fn ($f) => ! $cached($f)));
$latencyPhotos = 3 * (int) $opt['latency-sample'] + (int) $opt['single-sample'];
$planned = count($todoQueries) + count($todoGallery) + $latencyPhotos;
$plan = ['model_key' => $modelKey, 'uncached_query_photos' => count($todoQueries), 'uncached_gallery_photos' => count($todoGallery), 'latency_sample_photos' => $latencyPhotos, 'planned_calls' => $planned, 'http_requests' => count($todoQueries) + (int) ceil(count($todoGallery) / 4) + (int) $opt['latency-sample']];
if ($opt['dry-run']) {
    fwrite(STDOUT, json_encode(['dry_run' => $plan], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT).PHP_EOL);
    exit(0);
}
if (! $opt['fake'] && $planned > (int) $opt['max-calls']) {
    throw new RuntimeException('Planned '.$planned.' photo calls exceed --max-calls '.(int) $opt['max-calls'].'.');
}

$timings = ['single' => [], 'batch4' => [], 'triple' => []];
$errors = [];
$usage = ['prompt_tokens' => 0, 'total_tokens' => 0, 'reported' => false];
$embedFiles = function (array $files, string $timing) use ($provider, $cacheDir, $specData, &$timings, &$errors, &$usage) {
    $jpegs = array_map(fn ($f) => Preprocess::prepare((string) file_get_contents($f)), $files);
    $t = microtime(true);
    try {
        $vectors = $provider->embed($jpegs);
    } catch (ProviderError $e) {
        $errors[] = ['reason' => $e->reason, 'photos' => count($files), 'seconds' => round(microtime(true) - $t, 3)];

        return false;
    }
    $timings[$timing][] = microtime(true) - $t;
    if (($provider instanceof InferHubProvider || $provider instanceof GeminiProvider) && $provider->usage !== null) {
        $usage['reported'] = true;
        $usage['prompt_tokens'] += (int) ($provider->usage['prompt_tokens'] ?? 0);
        $usage['completion_tokens'] = ($usage['completion_tokens'] ?? 0) + (int) ($provider->usage['completion_tokens'] ?? 0);
        $usage['total_tokens'] += (int) ($provider->usage['total_tokens'] ?? 0);
    }
    foreach ($files as $i => $f) {
        file_put_contents($cacheDir.'/'.$specData['files'][$f].'.f32', Vector::pack($vectors[$i]));
        if ($provider instanceof InferHubProvider && isset($provider->descriptions[$i])) {
            file_put_contents($cacheDir.'/'.$specData['files'][$f].'.json', json_encode(['file' => basename($f), 'description' => $provider->descriptions[$i]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
    }

    return true;
};
foreach ($todoQueries as $f) {
    $embedFiles([$f], 'single');
}
foreach (array_chunk($todoGallery, 4) as $chunk) {
    $embedFiles($chunk, 'batch4');
}
$queryList = array_keys($queryFiles);
// Fresh single-photo calls (not cached again), so speed is measured even when every vector is already cached.
for ($k = 0; $k < (int) $opt['single-sample']; $k++) {
    $f = $queryList[(7 * $k) % count($queryList)];
    $t = microtime(true);
    try {
        $provider->embed([Preprocess::prepare((string) file_get_contents($f))]);
        $timings['single'][] = microtime(true) - $t;
    } catch (ProviderError $e) {
        $errors[] = ['reason' => $e->reason, 'photos' => 1, 'seconds' => round(microtime(true) - $t, 3), 'sample' => true];
    }
}
for ($k = 0; $k < (int) $opt['latency-sample']; $k++) {
    // 3-photo searches cost real calls but are not cached again (same vectors).
    $pick = [$queryList[(3 * $k) % count($queryList)], $queryList[(3 * $k + 1) % count($queryList)], $queryList[(3 * $k + 2) % count($queryList)]];
    $jpegs = array_map(fn ($f) => Preprocess::prepare((string) file_get_contents($f)), $pick);
    $t = microtime(true);
    try {
        $provider->embed($jpegs);
        $timings['triple'][] = microtime(true) - $t;
    } catch (ProviderError $e) {
        $errors[] = ['reason' => $e->reason, 'photos' => 3, 'seconds' => round(microtime(true) - $t, 3)];
    }
}

$vector = function (string $file) use ($cacheDir, $specData): ?array {
    $path = $cacheDir.'/'.$specData['files'][$file].'.f32';
    if (! is_file($path)) {
        return null;
    }
    $bytes = (string) file_get_contents($path);

    return Vector::unpack($bytes, intdiv(strlen($bytes), 4));
};

/** Ranks gallery reports for every query: best score per report, own report excluded. */
function visual_rankings(array $split, callable $vector): array
{
    $gallery = [];
    foreach ($split['gallery'] as $g) {
        if ($v = $vector($g['file'])) {
            $gallery[] = ['report' => $g['report'], 'vector' => $v];
        }
    }
    $out = [];
    foreach ($split['queries'] as $q) {
        $qv = $vector($q['file']);
        if (! $qv) {
            $out[] = $q + ['ranking' => null];

            continue;
        }
        $best = [];
        foreach ($gallery as $g) {
            if ($g['report'] === ($q['own_report'] ?? null)) {
                continue;
            }
            $best[$g['report']] = max($best[$g['report']] ?? -1.0, Vector::cosine($qv, $g['vector']));
        }
        arsort($best);
        $out[] = $q + ['ranking' => array_map(fn ($r, $s) => ['report' => $r, 'score' => round($s, 6)], array_keys($best), $best)];
    }

    return $out;
}

function visual_metrics(array $rankings, float $cutoff, float $strong): array
{
    $m = ['same_object' => ['n' => 0, 'hit1' => 0, 'hit5' => 0, 'rr' => 0.0, 'very_similar_correct' => 0, 'very_similar_wrong' => 0],
        'irrelevant' => ['n' => 0, 'returned' => 0], 'lookalike' => ['n' => 0, 'returned' => 0, 'target_returned' => 0, 'target_very_similar' => 0],
        'hard_negative' => ['n' => 0, 'returned' => 0, 'target_returned' => 0, 'target_very_similar' => 0], 'missing_vectors' => 0];
    foreach ($rankings as $q) {
        if ($q['ranking'] === null) {
            $m['missing_vectors']++;

            continue;
        }
        $shown = array_values(array_filter($q['ranking'], fn ($r) => $r['score'] >= $cutoff));
        $shown = array_slice($shown, 0, 12);
        $kind = $q['kind'];
        $m[$kind]['n']++;
        if ($kind === 'same_object') {
            foreach ($shown as $rank => $r) {
                $very = $strong > $cutoff && $r['score'] >= $strong;
                if (in_array($r['report'], $q['expected'], true)) {
                    $m[$kind]['hit1'] += $rank === 0 ? 1 : 0;
                    $m[$kind]['hit5'] += $rank < 5 ? 1 : 0;
                    $m[$kind]['rr'] += 1 / ($rank + 1);
                    $m[$kind]['very_similar_correct'] += $very ? 1 : 0;
                    break;
                }
                $m[$kind]['very_similar_wrong'] += $very ? 1 : 0;
            }
        } else {
            $m[$kind]['returned'] += $shown ? 1 : 0;
            if (isset($q['target'])) {
                foreach ($shown as $r) {
                    if (in_array($r['report'], $q['target'], true)) {
                        $m[$kind]['target_returned']++;
                        $m[$kind]['target_very_similar'] += $strong > $cutoff && $r['score'] >= $strong ? 1 : 0;
                    }
                }
            }
        }
    }
    $n = max(1, $m['same_object']['n']);
    $m['same_object']['mrr'] = round($m['same_object']['rr'] / $n, 3);
    unset($m['same_object']['rr']);

    return $m;
}

/** Validation-only cutoff (native rule): max same-object Hit@5 with ≤10% irrelevant returns; ties → MRR, fewer irrelevant, lower cutoff. */
function visual_fit(array $rankings): array
{
    $candidates = [];
    foreach ($rankings as $q) {
        foreach (array_slice($q['ranking'] ?? [], 0, 12) as $r) {
            $candidates[] = $r['score'];
        }
    }
    $candidates = array_values(array_unique($candidates));
    sort($candidates);
    $best = null;
    foreach ($candidates as $c) {
        $m = visual_metrics($rankings, $c, 2.0);
        $irr = $m['irrelevant']['n'] ? $m['irrelevant']['returned'] / $m['irrelevant']['n'] : 0;
        if ($irr > 0.10) {
            continue;
        }
        $key = [$m['same_object']['hit5'], $m['same_object']['mrr'], -$m['irrelevant']['returned'], -$c];
        if ($best === null || $key > $best['key']) {
            $best = ['key' => $key, 'cutoff' => $c];
        }
    }
    if ($best === null) {
        return ['cutoff' => null, 'strong_cutoff' => null, 'note' => 'no cutoff keeps irrelevant returns at or under 10%'];
    }
    // Strong cutoff: halfway between the highest non-target score and the lowest target score above it.
    $nonTarget = -1.0;
    $targets = [];
    foreach ($rankings as $q) {
        foreach ($q['ranking'] ?? [] as $r) {
            if ($q['kind'] === 'same_object' && in_array($r['report'], $q['expected'], true)) {
                $targets[] = $r['score'];
            } else {
                $nonTarget = max($nonTarget, $r['score']);
            }
        }
    }
    $above = array_filter($targets, fn ($s) => $s > $nonTarget);
    $strong = $above ? ($nonTarget + min($above)) / 2 : 1.0;

    return ['cutoff' => $best['cutoff'], 'strong_cutoff' => max($best['cutoff'], round($strong, 6))];
}

function visual_pct(array $values, float $p): ?float
{
    if (! $values) {
        return null;
    }
    sort($values);

    return round($values[(int) min(count($values) - 1, ceil($p * count($values)) - 1)], 3);
}

$rankings = [];
foreach ($specData['splits'] as $name => $split) {
    $rankings[$name] = visual_rankings($split, $vector);
}
$fitOn = (array) $specData['calibrate_on'];
$fit = visual_fit(array_merge(...array_map(fn ($s) => $rankings[$s], $fitOn)));
$report = ['label' => $opt['label'], 'created' => gmdate('c'), 'model_key' => $modelKey, 'plan' => $plan, 'calibration' => $fit + ['fitted_on' => $fitOn, 'spec_sha256' => hash_file('sha256', $spec)],
    'latency_seconds' => ['single_photo' => ['n' => count($timings['single']), 'p50' => visual_pct($timings['single'], 0.5), 'p95' => visual_pct($timings['single'], 0.95)],
        'three_photos' => ['n' => count($timings['triple']), 'p50' => visual_pct($timings['triple'], 0.5), 'p95' => visual_pct($timings['triple'], 0.95)],
        'gallery_batch_of_4' => ['n' => count($timings['batch4']), 'p50' => visual_pct($timings['batch4'], 0.5)]],
    'provider_errors' => $errors, 'usage' => $usage, 'splits' => []];
foreach ($rankings as $name => $r) {
    $report['splits'][$name] = $fit['cutoff'] === null ? null : visual_metrics($r, $fit['cutoff'], $fit['strong_cutoff']);
}
$held = array_filter((array) ($specData['held_out'] ?? ['commons-held_out-full', 'round2']), fn ($s) => isset($report['splits'][$s]) && $report['splits'][$s]);
$so = ['n' => 0, 'hit5' => 0];
$irr = ['n' => 0, 'returned' => 0];
foreach ($held as $s) {
    $so['n'] += $report['splits'][$s]['same_object']['n'];
    $so['hit5'] += $report['splits'][$s]['same_object']['hit5'];
    $irr['n'] += $report['splits'][$s]['irrelevant']['n'];
    $irr['returned'] += $report['splits'][$s]['irrelevant']['returned'];
}
$p50 = $report['latency_seconds']['single_photo']['p50'];
$p95 = $report['latency_seconds']['single_photo']['p95'];
$report['gates'] = [
    'top5_same_object_at_least_80pct' => ['value' => $so['n'] ? round($so['hit5'] / $so['n'], 3) : null, 'n' => $so['n'], 'pass' => $so['n'] > 0 && $so['hit5'] / $so['n'] >= 0.8],
    'unrelated_returned_at_most_10pct' => ['value' => $irr['n'] ? round($irr['returned'] / $irr['n'], 3) : null, 'n' => $irr['n'], 'pass' => $irr['n'] > 0 && $irr['returned'] / $irr['n'] <= 0.10],
    'typical_search_at_most_3s' => ['value' => $p50, 'pass' => $p50 !== null && $p50 <= 3.0, 'note' => $p50 === null ? 'no uncached query calls in this run' : null],
    'slow_search_p95_at_most_6s' => ['value' => $p95, 'pass' => $p95 !== null && $p95 <= 6.0],
];
$out = Workspace::ROOT.'/evidence/visual/batch-a-'.gmdate('Ymd\THis\Z').($opt['label'] ? '-'.preg_replace('/[^a-z0-9-]+/', '-', strtolower($opt['label'])) : '');
mkdir($out, 0700, true);
file_put_contents($out.'/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
file_put_contents($out.'/rankings.json', json_encode($rankings, JSON_UNESCAPED_SLASHES));
$md = '# Photo search evaluation: '.($opt['label'] ?? $modelKey)."\n\nModel key: `$modelKey`  \nCutoff: ".json_encode($fit)."\n\n| Split | Same-object n | Hit@1 | Hit@5 | MRR | Unrelated returned | Look-alike/hard-negative target returned (very similar) |\n|---|---|---|---|---|---|---|\n";
foreach ($report['splits'] as $name => $m) {
    if (! $m) {
        continue;
    }
    $neg = $m['lookalike']['n'] ? $m['lookalike'] : $m['hard_negative'];
    $md .= "| $name | {$m['same_object']['n']} | {$m['same_object']['hit1']} | {$m['same_object']['hit5']} | {$m['same_object']['mrr']} | {$m['irrelevant']['returned']}/{$m['irrelevant']['n']} | {$neg['target_returned']}/{$neg['n']} ({$neg['target_very_similar']}) |\n";
}
$md .= "\nGates (held-out: ".implode(' + ', $held)."):\n\n";
foreach ($report['gates'] as $gate => $g) {
    $md .= '- '.$gate.': '.json_encode($g['value']).($g['pass'] ? ' PASS' : ' FAIL')."\n";
}
$md .= "\nLatency (s): ".json_encode($report['latency_seconds'])."\nProvider errors: ".count($errors)."\n";
file_put_contents($out.'/report.md', $md);
fwrite(STDOUT, $md.PHP_EOL.'Evidence: '.$out.PHP_EOL);
