<?php

namespace App\Visual;

use App\Support\Workspace;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Spending guard shared by searches and the indexer: per-user and per-installation calls per rolling minute, a
 * daily cap that resets at midnight campus time (WIB), and a pause after the provider reports a quota or key
 * problem. One file-locked JSON file (like LoginLimiter); user keys are HMACs, never IDs.
 */
final class Budget
{
    public function __construct(private string $file, private string $secret, private array $config) {}

    public static function forProfile(array $config): self
    {
        return new self(Workspace::storage().'/auth/visual-budget.json', Workspace::profile()['key'], $config);
    }

    /**
     * Reserves $calls provider calls. Returns 0 when reserved, otherwise the seconds to wait (nothing is reserved).
     * $user = null is the indexer, which only draws on the installation and daily budgets.
     */
    public function reserve(?int $user, int $calls, ?int $now = null): int
    {
        $now ??= time();

        return $this->locked(function (array $s) use ($user, $calls, $now) {
            $wait = max(0, (int) ($s['pause_until'] ?? 0) - $now);
            $s = $this->roll($s, $now);
            if ($s['day_count'] + $calls > $this->config['daily_cap']) {
                $wait = max($wait, $this->secondsToCampusMidnight($now));
            }
            $buckets = ['install' => $this->config['install_per_minute']];
            if ($user !== null) {
                $buckets[hash_hmac('sha256', 'visual-user|'.$user, $this->secret)] = $this->config['user_per_minute'];
            }
            foreach ($buckets as $key => $limit) {
                $bucket = $s['minute'][$key] ?? ['start' => $now, 'count' => 0];
                if ($bucket['count'] + $calls > $limit) {
                    $wait = max($wait, $bucket['start'] + 60 - $now, 1);
                }
            }
            if ($wait > 0) {
                return [$s, $wait];
            }
            foreach ($buckets as $key => $limit) {
                $s['minute'][$key] ??= ['start' => $now, 'count' => 0];
                $s['minute'][$key]['count'] += $calls;
            }
            $s['day_count'] += $calls;

            return [$s, 0];
        });
    }

    /** After a provider quota/key error: no calls for $seconds (capped at a day). */
    public function pause(int $seconds, ?int $now = null): void
    {
        $now ??= time();
        $this->locked(fn (array $s) => [array_merge($s, ['pause_until' => max((int) ($s['pause_until'] ?? 0), $now + min(86400, max(1, $seconds)))]), null]);
    }

    /** Counts for status output (no user keys). */
    public function summary(?int $now = null): array
    {
        $now ??= time();

        return $this->locked(function (array $s) use ($now) {
            $s = $this->roll($s, $now);

            return [$s, ['day' => $s['day'], 'calls_today' => $s['day_count'], 'daily_cap' => $this->config['daily_cap'], 'paused_seconds' => max(0, (int) ($s['pause_until'] ?? 0) - $now)]];
        });
    }

    private function roll(array $s, int $now): array
    {
        $today = $this->campusDay($now);
        if (($s['day'] ?? '') !== $today) {
            $s['day'] = $today;
            $s['day_count'] = 0;
        }
        $s['day_count'] = (int) ($s['day_count'] ?? 0);
        $s['minute'] = array_filter($s['minute'] ?? [], fn ($b) => is_array($b) && ($b['start'] ?? 0) + 60 > $now);

        return $s;
    }

    private function campusDay(int $now): string
    {
        return (new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Asia/Jakarta'))->format('Y-m-d');
    }

    private function secondsToCampusMidnight(int $now): int
    {
        $local = (new DateTimeImmutable('@'.$now))->setTimezone(new DateTimeZone('Asia/Jakarta'));

        return max(1, $local->modify('tomorrow')->getTimestamp() - $now);
    }

    private function locked(callable $body): mixed
    {
        $dir = dirname($this->file);
        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException('Photo search budget unavailable.');
        }
        $handle = fopen($this->file, 'c+');
        if (! $handle || ! flock($handle, LOCK_EX)) {
            throw new RuntimeException('Photo search budget unavailable.');
        }
        try {
            $raw = stream_get_contents($handle);
            $state = $raw === '' || $raw === false ? [] : json_decode($raw, true, 8);
            if (! is_array($state)) {
                $state = [];
            }
            [$state, $result] = $body($state);
            rewind($handle);
            ftruncate($handle, 0);
            if (fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR)) === false || ! fflush($handle)) {
                throw new RuntimeException('Cannot persist the photo search budget.');
            }

            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
