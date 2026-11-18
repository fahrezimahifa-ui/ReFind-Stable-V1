<?php

namespace App\Support;

/**
 * Phase 6 claim write gates. Fail closed: only the disposable phase6-dev profile can ever enable a stage.
 * REFIND_PHASE6_STAGES may only narrow the phase6-dev set (for gate testing); it can never enable a
 * stage in academic-dev, academic-test or any other profile, and it is deliberately not read from config.
 */
final class ClaimStages
{
    public const PROFILE = 'phase6-dev';

    public const ALL = ['student', 'review', 'approval'];

    public static function forProfile(string $profile, string|false|null $override): array
    {
        if ($profile !== self::PROFILE) {
            return [];
        }
        if ($override === false || $override === null) {
            return self::ALL;
        }
        $requested = array_map('trim', explode(',', $override));

        return array_values(array_intersect(self::ALL, $requested));
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
