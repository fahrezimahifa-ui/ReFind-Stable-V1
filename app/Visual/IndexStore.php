<?php

namespace App\Visual;

use RuntimeException;

/**
 * The photo-search index: a derived, rebuildable cache of photo vectors kept as files (no database table).
 *
 *   current.json     {"version":1,"generation":N,"file":"index-N.bin","model_key":…,"dimensions":D,
 *                     "entries":{"<photo_id>":{"path":"items/<hex>.jpg","sha256":…,"slot":K,"at":…}}}
 *   index-N.bin      K-th record = D little-endian float32 values (unit vector)
 *   index.lock       held by the one writer (the indexer)
 *
 * An entry only counts when its photo ID *and* stored file path match the database row, because a snapshot restore
 * can reuse a photo ID for a different file. Each write creates a new generation file and then swaps the small
 * pointer, so a search never reads a half-written vector file; old generations are pruned when nothing holds them.
 * Vectors are read one record at a time (PHP runs with 128 MB). Pure: the directory is passed in.
 */
final class IndexStore
{
    public function __construct(private string $dir) {}

    public function dir(): string
    {
        return $this->dir;
    }

    /** The current index for this model key, or null when there is none or it belongs to another model. */
    public function load(string $modelKey): ?array
    {
        $file = $this->dir.'/current.json';
        if (! is_file($file)) {
            return null;
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return null;
        }
        $meta = json_decode($raw, true, 8);
        if (! is_array($meta) || ($meta['version'] ?? 0) !== 1 || ($meta['model_key'] ?? '') !== $modelKey || ! is_int($meta['dimensions'] ?? null)
            || ! is_string($meta['file'] ?? null) || ! preg_match('/^index-[0-9]+\.bin$/D', $meta['file']) || ! is_array($meta['entries'] ?? null)) {
            return null;
        }

        return $meta;
    }

    /**
     * Yields [photo_id, unit vector] for each wanted photo whose stored path matches.
     *
     * @param  array<int,string>  $wanted  photo_id => file_path from the database
     */
    public function vectors(array $meta, array $wanted): \Generator
    {
        $dims = $meta['dimensions'];
        $handle = @fopen($this->dir.'/'.$meta['file'], 'rb');
        if (! $handle) {
            return;
        }
        try {
            foreach ($wanted as $photo => $path) {
                $entry = $meta['entries'][(string) $photo] ?? null;
                if (! is_array($entry) || ($entry['path'] ?? null) !== $path || ! is_int($entry['slot'] ?? null)) {
                    continue;
                }
                if (fseek($handle, $entry['slot'] * 4 * $dims) !== 0) {
                    continue;
                }
                $vector = Vector::unpack((string) fread($handle, 4 * $dims), $dims);
                if ($vector !== null) {
                    yield [(int) $photo, $vector];
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /** Holds the writer lock for $body (the indexer is the only writer). Returns null when another writer has it. */
    public function exclusive(callable $body): mixed
    {
        $this->ensureDir();
        $lock = fopen($this->dir.'/index.lock', 'c');
        if (! $lock) {
            throw new RuntimeException('Photo index lock unavailable.');
        }
        try {
            if (! flock($lock, LOCK_EX | LOCK_NB)) {
                return null;
            }
            try {
                return $body();
            } finally {
                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    /**
     * Writes a new generation. $entries maps photo_id => ['path','sha256', and either 'vector' (new unit vector)
     * or 'slot' (reuse from $previous)]. Call inside exclusive().
     */
    public function commit(string $modelKey, int $dims, array $entries, ?array $previous): array
    {
        $this->ensureDir();
        $generation = (int) ($previous['generation'] ?? 0) + 1;
        $name = 'index-'.$generation.'.bin';
        $old = $previous ? @fopen($this->dir.'/'.$previous['file'], 'rb') : null;
        $out = fopen($this->dir.'/'.$name, 'wb');
        if (! $out) {
            throw new RuntimeException('Cannot write the photo index.');
        }
        $meta = ['version' => 1, 'generation' => $generation, 'file' => $name, 'model_key' => $modelKey, 'dimensions' => $dims, 'entries' => []];
        $slot = 0;
        try {
            ksort($entries);
            foreach ($entries as $photo => $entry) {
                if (isset($entry['vector'])) {
                    $bytes = Vector::pack(Vector::normalize($entry['vector'], $dims));
                } elseif ($old && isset($entry['slot']) && fseek($old, $entry['slot'] * 4 * $dims) === 0) {
                    $bytes = (string) fread($old, 4 * $dims);
                    if (Vector::unpack($bytes, $dims) === null) {
                        continue;
                    }
                } else {
                    continue;
                }
                if (fwrite($out, $bytes) !== strlen($bytes)) {
                    throw new RuntimeException('Cannot write the photo index.');
                }
                $meta['entries'][(string) $photo] = ['path' => $entry['path'], 'sha256' => $entry['sha256'], 'slot' => $slot++, 'at' => $entry['at'] ?? gmdate('Y-m-d\TH:i:s\Z')];
            }
            if (! fflush($out)) {
                throw new RuntimeException('Cannot write the photo index.');
            }
        } finally {
            fclose($out);
            if ($old) {
                fclose($old);
            }
        }
        $this->swapPointer($meta);
        $this->prune($name, $previous['file'] ?? null);

        return $meta;
    }

    private function swapPointer(array $meta): void
    {
        $tmp = $this->dir.'/current.json.tmp';
        if (file_put_contents($tmp, json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Cannot write the photo index pointer.');
        }
        // On Windows a rename over a file that a search has open briefly fails; retry for up to ~2 s.
        for ($try = 0; $try < 20; $try++) {
            if (@rename($tmp, $this->dir.'/current.json')) {
                return;
            }
            usleep(100000);
        }
        throw new RuntimeException('Cannot swap the photo index pointer.');
    }

    /** Keeps the current and the previous generation (a search may still read it); older files go when free. */
    private function prune(string $current, ?string $previous): void
    {
        foreach (glob($this->dir.'/index-*.bin') ?: [] as $file) {
            $base = basename($file);
            if ($base !== $current && $base !== $previous) {
                @unlink($file);
            }
        }
    }

    private function ensureDir(): void
    {
        if (! is_dir($this->dir) && ! mkdir($this->dir, 0700, true) && ! is_dir($this->dir)) {
            throw new RuntimeException('Cannot create the photo index folder.');
        }
    }
}
