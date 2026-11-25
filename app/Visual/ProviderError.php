<?php

namespace App\Visual;

/**
 * A provider failure reduced to a reason code before anything can log it (HTTP client messages may quote part of a
 * response body). Reasons follow native visual-provider.php: quota, project, timeout, unavailable, rejected,
 * bad_response, rate_limited, media.
 */
final class ProviderError extends \RuntimeException
{
    public function __construct(public readonly string $reason, public readonly int $retryAfter = 0)
    {
        parent::__construct('Photo search provider: '.$reason);
    }

    /** Native-style mapping of an HTTP status from the provider to a reason code. */
    public static function fromStatus(int $status, int $retryAfter = 0): self
    {
        return match (true) {
            $status === 429 => new self('quota', $retryAfter > 0 ? $retryAfter : 900),
            $status === 401, $status === 403 => new self('project', 900),
            $status === 408, $status === 504 => new self('timeout'),
            $status === 400, $status === 413, $status === 415, $status === 422 => new self('rejected'),
            default => new self('unavailable', 60),
        };
    }
}
