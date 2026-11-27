<?php

namespace App\Visual;

/**
 * Frozen similarity cutoffs, committed under resources/visual/ (no secrets). A receipt only applies to the exact
 * model key it was fitted for; without one, photo search answers 503 visual_uncalibrated, as native does.
 * cutoff: lowest score shown at all ("Similar"); strong_cutoff: "Very similar". Fitted on the validation split only.
 */
final class Calibration
{
    public static function file(string $dir, string $modelKey): string
    {
        return $dir.'/calibration-'.substr(hash('sha256', $modelKey), 0, 16).'.json';
    }

    /** @return array{cutoff:float,strong_cutoff:float}|null */
    public static function load(string $dir, string $modelKey): ?array
    {
        $file = self::file($dir, $modelKey);
        if (! is_file($file)) {
            return null;
        }
        $r = json_decode((string) file_get_contents($file), true, 8);
        if (! is_array($r) || ($r['model_key'] ?? null) !== $modelKey) {
            return null;
        }
        $cutoff = $r['cutoff'] ?? null;
        $strong = $r['strong_cutoff'] ?? null;
        if (! is_numeric($cutoff) || ! is_numeric($strong) || $cutoff <= -1 || $cutoff >= 1 || $strong < $cutoff || $strong > 1) {
            return null;
        }

        return ['cutoff' => (float) $cutoff, 'strong_cutoff' => (float) $strong];
    }

    public static function dir(): string
    {
        return dirname(__DIR__, 2).'/resources/visual';
    }
}
