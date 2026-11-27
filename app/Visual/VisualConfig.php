<?php

namespace App\Visual;

use App\Exceptions\ContractError;
use App\Support\ClaimStages;
use App\Support\Workspace;

/**
 * Photo-search gate and private configuration.
 *
 * On only when the profile has the `visual` stage (phase8-dev; never the academic profiles) AND its private
 * state/<profile>/visual.json is valid AND, for the hosted engine, the owner's key file exists. The key lives outside
 * every profile folder (state/secrets/), so demo backups never copy it; it is read here and nowhere else, and is never
 * logged or returned. REFIND_VISUAL_PROVIDER=fake can only downgrade phase8-dev to the offline fake engine.
 */
final class VisualConfig
{
    public const SECRETS = Workspace::ROOT.'/state/secrets';

    public const STRATEGIES = ['image_embedding', 'describe_match'];

    private const DEFAULTS = ['rerank' => false, 'timeout_seconds' => 8, 'user_per_minute' => 9, 'install_per_minute' => 30, 'daily_cap' => 500, 'auto_index' => false, 'dimensions' => null, 'embedding_model' => null, 'vision_model' => null, 'request_format' => 'openai', 'key_file' => self::SECRETS.'/inferhub.key'];

    /** Validates a decoded visual.json (pure). Returns the normalized config or throws \InvalidArgumentException. */
    public static function parse(mixed $raw): array
    {
        if (! is_array($raw) || array_is_list($raw)) {
            throw new \InvalidArgumentException('visual.json must be an object.');
        }
        $known = ['provider', 'base_url', 'strategy', 'embedding_model', 'vision_model', 'request_format', 'dimensions', 'rerank', 'timeout_seconds', 'user_per_minute', 'install_per_minute', 'daily_cap', 'auto_index', 'key_file', '_about'];
        if ($unknown = array_diff(array_keys($raw), $known)) {
            throw new \InvalidArgumentException('Unknown visual.json field: '.implode(', ', $unknown));
        }
        if (($raw['provider'] ?? null) === 'gemini') {
            // Google's host is fixed in GeminiProvider; base_url is not used for it.
            $raw += ['embedding_model' => 'gemini-embedding-2', 'dimensions' => 768, 'key_file' => self::SECRETS.'/gemini.key', 'strategy' => 'image_embedding'];
            if (isset($raw['base_url']) || $raw['strategy'] !== 'image_embedding') {
                throw new \InvalidArgumentException('gemini uses Google\'s fixed host and image_embedding only.');
            }
        }
        $c = $raw + self::DEFAULTS;
        if (! in_array($c['provider'] ?? null, ['inferhub', 'gemini', 'fake'], true)) {
            throw new \InvalidArgumentException('provider must be "inferhub", "gemini" or "fake".');
        }
        if ($c['provider'] === 'gemini') {
            if (! is_string($c['embedding_model']) || ! preg_match('~^gemini-[a-z0-9.-]{1,60}$~D', $c['embedding_model'])) {
                throw new \InvalidArgumentException('embedding_model must be a Gemini model name.');
            }
            if ($c['dimensions'] !== null && (! is_int($c['dimensions']) || $c['dimensions'] < 64 || $c['dimensions'] > 4096)) {
                throw new \InvalidArgumentException('dimensions must be null or 64–4096.');
            }
        }
        foreach (['rerank', 'auto_index'] as $flag) {
            if (! is_bool($c[$flag])) {
                throw new \InvalidArgumentException($flag.' must be true or false.');
            }
        }
        foreach (['timeout_seconds' => [2, 20], 'user_per_minute' => [1, 60], 'install_per_minute' => [1, 120], 'daily_cap' => [1, 100000]] as $key => [$min, $max]) {
            if (! is_int($c[$key]) || $c[$key] < $min || $c[$key] > $max) {
                throw new \InvalidArgumentException($key.' must be an integer from '.$min.' to '.$max.'.');
            }
        }
        if ($c['provider'] === 'inferhub') {
            if (! is_string($c['base_url'] ?? null) || ! preg_match('#^https://[A-Za-z0-9.-]+(:[0-9]{2,5})?(/[A-Za-z0-9._~/-]*)?$#D', $c['base_url'])) {
                throw new \InvalidArgumentException('base_url must be an https URL without query or credentials.');
            }
            $c['base_url'] = rtrim($c['base_url'], '/');
            if (! in_array($c['strategy'] ?? null, self::STRATEGIES, true)) {
                throw new \InvalidArgumentException('strategy must be one of: '.implode(', ', self::STRATEGIES).'.');
            }
            foreach (['embedding_model', 'vision_model', 'request_format'] as $key) {
                if ($c[$key] !== null && (! is_string($c[$key]) || ! preg_match('~^[A-Za-z0-9._:/@+-]{1,120}$~D', $c[$key]))) {
                    throw new \InvalidArgumentException($key.' is not a valid model or format name.');
                }
            }
            if ($c['strategy'] === 'image_embedding' && ! is_string($c['embedding_model'])) {
                throw new \InvalidArgumentException('embedding_model is required for image_embedding.');
            }
            if (($c['strategy'] === 'describe_match' || $c['rerank']) && ! is_string($c['vision_model'])) {
                throw new \InvalidArgumentException('vision_model is required for describe_match or rerank.');
            }
            if ($c['dimensions'] !== null && (! is_int($c['dimensions']) || $c['dimensions'] < 64 || $c['dimensions'] > 4096)) {
                throw new \InvalidArgumentException('dimensions must be null or 64–4096.');
            }
        }
        if (! is_string($c['key_file']) || ! str_starts_with(Workspace::norm($c['key_file']), Workspace::norm(self::SECRETS).'/')) {
            throw new \InvalidArgumentException('key_file must be inside state/secrets.');
        }

        return $c;
    }

    public static function enabled(): bool
    {
        return ClaimStages::has('visual');
    }

    /** The validated private config, or null when it is missing or invalid (photo search then stays unavailable). */
    public static function current(): ?array
    {
        if (app()->bound('refind.visual.config')) {
            return self::parse(app('refind.visual.config')); // feature tests only (bound in code, never from input)
        }
        $file = Workspace::storage().'/visual.json';
        if (! is_file($file)) {
            return null;
        }
        try {
            Workspace::assertPath($file);
            $c = self::parse(json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            return null;
        }
        if (getenv('REFIND_VISUAL_PROVIDER') === 'fake' && Workspace::profile()['profile'] === 'phase8-dev') {
            $c['provider'] = 'fake';
        }

        return $c;
    }

    /** Whether the owner's key file is present and well formed (never reveals the key). */
    public static function keyPresent(?array $config = null): bool
    {
        try {
            return self::key($config ?? self::current() ?? self::DEFAULTS) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    /** Reads the key: no links or redirects, at most 512 printable characters. Never log or return the value. */
    public static function key(array $config): string
    {
        $file = $config['key_file'] ?? self::DEFAULTS['key_file'];
        Workspace::assertPath($file);
        if (! is_file($file) || is_link($file)) {
            throw new \RuntimeException('Photo search key missing.');
        }
        $handle = fopen($file, 'rb');
        $raw = $handle ? (string) fread($handle, 513) : '';
        if ($handle) {
            fclose($handle);
        }
        $key = trim($raw);
        if (strlen($raw) > 512 || ! preg_match('/^[\x21-\x7E]{8,512}$/D', $key)) {
            throw new \RuntimeException('Photo search key unreadable.');
        }

        return $key;
    }

    /** Stage on, config valid, and the engine usable (fake, or hosted with a key). */
    public static function ready(): bool
    {
        if (! self::enabled()) {
            return false;
        }
        $c = self::current();

        return $c !== null && ($c['provider'] === 'fake' || self::keyPresent($c));
    }

    /** Fails closed with the native error codes. */
    public static function require(): array
    {
        if (! self::enabled()) {
            throw new ContractError('Photo search is unavailable.', 403, 'visual_disabled');
        }
        $c = self::current();
        if ($c === null || ($c['provider'] !== 'fake' && ! self::keyPresent($c))) {
            throw new ContractError('Photo search is unavailable. You can still search by keyword.', 503, 'visual_unavailable');
        }

        return $c;
    }

    public static function indexDir(): string
    {
        if (app()->bound('refind.visual.index_dir')) {
            return app('refind.visual.index_dir'); // feature tests use a scratch index
        }

        return Workspace::storage().'/visual';
    }

    public static function makeProvider(?array $config = null): Provider
    {
        $c = $config ?? self::require();

        return match ($c['provider']) {
            'fake' => new FakeProvider,
            'gemini' => new GeminiProvider($c),
            default => new InferHubProvider($c),
        };
    }
}
