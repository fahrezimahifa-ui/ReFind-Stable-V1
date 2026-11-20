<?php

namespace App\Support;

/**
 * Phase 6/7 claim and pickup write gates. Fail closed: only the disposable phase6-dev/phase7-dev profiles can
 * enable a stage. REFIND_PHASE6_STAGES may only narrow that profile's set (for gate testing); it can never enable a
 * stage in academic-dev, academic-test or any other profile, and it is deliberately not read from config.
 */
final class ClaimStages
{
    /** Hard-wired per disposable profile; every other profile (including both academic ones) gets none. */
    public const PROFILES = [
        'phase6-dev' => ['student', 'review', 'approval'],
        'phase7-dev' => ['student', 'review', 'approval', 'pickup'],
        // Phase 8 integration candidate: remaining native features (BM report status, notification read-marking,
        // account self-service and self-registration) on the disposable phase8-dev profile only.
        'phase8-dev' => ['student', 'review', 'approval', 'pickup', 'status', 'notifications', 'account', 'registration', 'history'],
        // The persistent localhost demo shows every journey on its own synthetic schema (owner authorization, 20 November 2026).
        'demo' => ['student', 'review', 'approval', 'pickup', 'status', 'notifications', 'account', 'registration', 'history'],
    ];

    public const ALL = ['student', 'review', 'approval', 'pickup', 'status', 'notifications', 'account', 'registration', 'history'];

    public static function forProfile(string $profile, string|false|null $override): array
    {
        $base = self::PROFILES[$profile] ?? [];
        if ($base === [] || $override === false || $override === null) {
            return $base;
        }
        $requested = array_map('trim', explode(',', $override));

        return array_values(array_intersect($base, $requested));
    }

    public static function enabled(): array
    {
        return self::forProfile(Workspace::profile()['profile'], getenv('REFIND_PHASE6_STAGES'));
    }

    public static function has(string $stage): bool
    {
        return in_array($stage, self::enabled(), true);
    }

    public static function any(): bool
    {
        return self::enabled() !== [];
    }

    /** Native review decisions available under the enabled stages, in native drawer order. */
    public static function decisions(): array
    {
        $out = [];
        if (self::has('approval')) {
            $out[] = 'approved';
        }
        if (self::has('review')) {
            $out[] = 'rejected';
            $out[] = 'need_more_info';
        }

        return $out;
    }
}
