<?php

namespace App\Support;

use RuntimeException;

final class Workspace
{
    public const ROOT = 'C:/ReFind-Laravel';

    private static ?array $profile = null;

    public static function norm(string $path): string
    {
        return strtolower(rtrim(str_replace('\\', '/', $path), '/'));
    }

    public static function profile(): array
    {
        if (self::$profile !== null) {
            return self::$profile;
        }
        $name = getenv('REFIND_PROFILE');
        if (! in_array($name, ['dev', 'test'], true)) {
            throw new RuntimeException('Explicit isolated REFIND_PROFILE required.');
        }
        $owner = json_decode((string) file_get_contents(self::ROOT.'/.refind-laravel-owner.json'), true, 32, JSON_THROW_ON_ERROR);
        if (($owner['project'] ?? '') !== 'refind-laravel-poc' || self::norm($owner['root'] ?? '') !== self::norm(self::ROOT)) {
            throw new RuntimeException('Workspace ownership mismatch.');
        }
        self::assertPath(self::ROOT);
        self::assertPath(self::ROOT.'/laravel');
        self::assertPath(self::ROOT.'/state/'.$name);
        self::assertPath(self::ROOT.'/state/'.$name.'/profile.json');
        $p = json_decode((string) file_get_contents(self::ROOT.'/state/'.$name.'/profile.json'), true, 32, JSON_THROW_ON_ERROR);
        if (($p['profile'] ?? '') !== $name || ($p['workspace_id'] ?? '') !== $owner['workspace_id'] || ($p['database'] ?? '') !== 'refind_laravel_'.$name || ($p['username'] ?? '') !== 'refind_'.$name.'_ro') {
            throw new RuntimeException('Profile identity mismatch.');
        }
        $expected = ['DB_HOST' => '127.0.0.1', 'DB_PORT' => '3307', 'DB_DATABASE' => $p['database'], 'DB_USERNAME' => $p['username'], 'DB_CONNECTION' => 'mariadb', 'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'refind_laravel_'.$name];
        foreach ($expected as $key => $value) {
            $external = getenv($key);
            if ($external !== false && $external !== $value) {
                throw new RuntimeException('Conflicting isolated configuration: '.$key);
            }
        }
        if (getenv('DB_URL') || getenv('DATABASE_URL')) {
            throw new RuntimeException('Connection URLs are forbidden in the proof of concept.');
        }
        if (file_exists(self::ROOT.'/laravel/bootstrap/cache/config.php')) {
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
