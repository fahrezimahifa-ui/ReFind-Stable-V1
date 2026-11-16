<?php

namespace App\Services;

use App\Exceptions\ContractError;
use App\Support\Workspace;

final class LoginLimiter
{
    public function attempt(string $email, string $ip, callable $check): mixed
    {
        $secret = Workspace::profile()['key'];
        $keys = [hash_hmac('sha256', 'pair|'.$email.'|'.$ip, $secret) => 5, hash_hmac('sha256', 'ip|'.$ip, $secret) => 30];
        $file = fopen(Workspace::storage().'/auth/attempts.json', 'c+');
        if (! $file || ! flock($file, LOCK_EX)) {
            throw new \RuntimeException('Authentication limiter unavailable.');
        }
        try {
            $raw = stream_get_contents($file);
            $buckets = $raw === '' ? [] : json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($buckets)) {
                throw new \RuntimeException('Invalid limiter.');
            }$now = now()->timestamp;
            foreach ($buckets as $k => $bucket) {
                if (($bucket['start'] ?? 0) + 900 <= $now) {
                    unset($buckets[$k]);
                }
            }
            $wait = 0;
            foreach ($keys as $k => $limit) {
                if (($buckets[$k]['count'] ?? 0) >= $limit) {
                    $wait = max($wait, $buckets[$k]['start'] + 900 - $now);
                }
            }
            if ($wait > 0) {
                throw new ContractError('Too many login attempts. Please wait and try again.', 429, 'login_rate_limited', [], ['Retry-After' => (string) $wait]);
            }
            $user = $check();
            if ($user) {
                unset($buckets[array_key_first($keys)]);
            } else {
                foreach ($keys as $k => $limit) {
                    $buckets[$k] ??= ['start' => $now, 'count' => 0];
                    $buckets[$k]['count']++;
                }
            }
            rewind($file);
            ftruncate($file, 0);
            if (fwrite($file, json_encode($buckets, JSON_THROW_ON_ERROR)) === false || ! fflush($file)) {
                throw new \RuntimeException('Cannot persist limiter.');
            }
            if (! $user) {
                throw new ContractError('Email or password could not be verified.', 401, 'invalid_credentials');
            }

return $user;
        } finally {
            flock($file,LOCK_UN);
            fclose($file);
        }
    }
}
