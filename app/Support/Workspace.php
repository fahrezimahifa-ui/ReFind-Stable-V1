<?php

namespace App\Support;

use RuntimeException;

final class Workspace
{
    public const ROOT = 'C:/ReFind-Laravel';

    /**
     * Each isolated profile is bound to one application checkout. The unaccepted Phase 6 implementation
     * (laravel-p6) can therefore only reach its own disposable schema, never the accepted academic ones.
     */
    public const PROFILES = [
        'academic-dev' => ['database' => 'refind_laravel_b_dev', 'username' => 'refind_b_dev_app', 'cookie' => 'refind_laravel_b_dev', 'port' => 8013, 'ports' => [8013], 'app' => 'laravel'],
        'academic-test' => ['database' => 'refind_laravel_b_test', 'username' => 'refind_b_test_app', 'cookie' => 'refind_laravel_b_test', 'port' => 8014, 'ports' => [8014, 8015], 'app' => 'laravel'],
        'phase6-dev' => ['database' => 'refind_laravel_p6_dev', 'username' => 'refind_p6_dev_app', 'cookie' => 'refind_laravel_p6_dev', 'port' => 8016, 'ports' => [8016, 8017], 'app' => 'laravel-p6', 'records' => 'phase6-processes.json'],
        'phase7-dev' => ['database' => 'refind_laravel_p7_dev', 'username' => 'refind_p7_dev_app', 'cookie' => 'refind_laravel_p7_dev', 'port' => 8018, 'ports' => [8018, 8019], 'app' => 'laravel-p7', 'records' => 'phase7-processes.json'],
        'phase8-dev' => ['database' => 'refind_laravel_p8_dev', 'username' => 'refind_p8_dev_app', 'cookie' => 'refind_laravel_p8_dev', 'port' => 8020, 'ports' => [8020, 8021], 'app' => 'laravel-p8', 'records' => 'phase8-processes.json'],
        // Persistent localhost demo: synthetic data only, all stages on (never an academic profile, never a verification target).
        'demo' => ['database' => 'refind_laravel_demo', 'username' => 'refind_demo_app', 'cookie' => 'refind_laravel_demo', 'port' => 8030, 'ports' => [8030, 8031], 'app' => 'laravel-demo', 'records' => 'demo-processes.json'],
    ];

    /** Synthetic, unaccepted development profiles, including the persistent demo (never the accepted academic ones). */
    public const DEV_PROFILES = ['phase6-dev', 'phase7-dev', 'phase8-dev', 'demo'];

    public static function isDev(): bool
    {
        return in_array(self::profile()['profile'], self::DEV_PROFILES, true);
    }

    public static function definition(): array
    {
        return self::PROFILES[self::profile()['profile']];
    }

    private static ?array $profile = null;

    public static function norm(string $path): string
    {
        return strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    }

    /** Whether a checkout may run a profile; any other combination fails closed. */
    public static function permitted(string $name, ?string $appRoot = null): bool
    {
        $def = self::PROFILES[$name] ?? null;

        return $def !== null && self::norm($appRoot ?? dirname(__DIR__, 2)) === self::norm(self::ROOT.'/'.$def['app']);
    }

    public static function profile(): array
    {
        if (self::$profile !== null) {
            return self::$profile;
        }
        $name = getenv('REFIND_PROFILE');
        if (! is_string($name) || ! isset(self::PROFILES[$name])) {
            throw new RuntimeException('Explicit isolated REFIND_PROFILE required.');
        }
        if (! self::permitted($name)) {
            throw new RuntimeException('This isolated profile is not permitted from this application checkout.');
        }
        $def = self::PROFILES[$name];
        self::assertPath(self::ROOT.'/.refind-laravel-owner.json');
        self::assertPath(self::ROOT.'/state/academic-owner.json');
        $parent = json_decode((string) file_get_contents(self::ROOT.'/.refind-laravel-owner.json'), true, 32, JSON_THROW_ON_ERROR);
        $owner = json_decode((string) file_get_contents(self::ROOT.'/state/academic-owner.json'), true, 32, JSON_THROW_ON_ERROR);
        if (($owner['project'] ?? '') !== 'refind-laravel-academic-b' || self::norm($owner['root'] ?? '') !== self::norm(self::ROOT) || ($owner['parent_workspace_id'] ?? '') !== ($parent['workspace_id'] ?? null)) {
            throw new RuntimeException('Workspace ownership mismatch.');
        }
        self::assertPath(self::ROOT);
        self::assertPath(self::ROOT.'/'.$def['app']);
        self::assertPath(self::ROOT.'/state/'.$name);
        self::assertPath(self::ROOT.'/state/'.$name.'/profile.json');
        $p = json_decode((string) file_get_contents(self::ROOT.'/state/'.$name.'/profile.json'), true, 32, JSON_THROW_ON_ERROR);
        if (($p['profile'] ?? '') !== $name || ($p['workspace_id'] ?? '') !== $owner['workspace_id'] || ($p['database'] ?? '') !== $def['database'] || ($p['username'] ?? '') !== $def['username'] || ($p['cookie'] ?? '') !== $def['cookie'] || ($p['url'] ?? '') !== 'http://127.0.0.1:'.$def['port']) {
            throw new RuntimeException('Profile identity mismatch.');
        }
        $expected = ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '3307', 'DB_DATABASE' => $p['database'], 'DB_USERNAME' => $p['username'], 'DB_CONNECTION' => 'mariadb', 'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => $def['cookie']];
        foreach ($expected as $key => $value) {
            $external = getenv($key);
            if ($external !== false && $external !== $value) {
                throw new RuntimeException('Conflicting isolated configuration: '.$key);
            }
        }
        if (getenv('DB_URL') || getenv('DATABASE_URL')) {
            throw new RuntimeException('Connection URLs are forbidden in the proof of concept.');
        }
        if (file_exists(self::ROOT.'/'.$def['app'].'/bootstrap/cache/config.php')) {
            throw new RuntimeException('Cached configuration is not enabled for this proof of concept.');
        }
        $binary = self::norm((string) realpath(PHP_BINARY));
        if ($binary !== self::norm(self::ROOT.'/runtime/php85/php.exe')) {
            throw new RuntimeException('Use the selected isolated PHP executable.');
        }

        return self::$profile = $p;
    }

    public static function assertPath(string $path): void
    {
        $normalized = self::norm($path);
        $base = self::norm(self::ROOT);
        if ($normalized !== $base && ! str_starts_with($normalized, $base.'/')) {
            throw new RuntimeException('Path escapes workspace.');
        }
        $cursor = $path;
        while (self::norm($cursor) !== self::norm(dirname($cursor))) {
            if (file_exists($cursor) && (is_link($cursor) || self::norm((string) realpath($cursor)) !== self::norm($cursor))) {
                throw new RuntimeException('Redirected workspace path refused.');
            }
            if (self::norm($cursor) === $base) {
                break;
            }$cursor = dirname($cursor);
        }
    }

    public static function storage(): string
    {
        return self::ROOT.'/state/'.self::profile()['profile'];
    }

    public static function media(): string
    {
        return self::storage().'/app/private/refind';
    }

    public static function assertDatabase(array $config): void
    {
        $p = self::profile();
        if (($config['host'] ?? '') !== '127.0.0.1' || (int) ($config['port'] ?? 0) !== 3307 || ($config['database'] ?? '') !== $p['database'] || ($config['username'] ?? '') !== $p['username'] || ! empty($config['url']) || ! empty($config['read']) || ! empty($config['write'])) {
            throw new RuntimeException('Database isolation check failed before connection.');
        }
    }
}
