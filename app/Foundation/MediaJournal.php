<?php

namespace App\Foundation;

use App\Support\Workspace;
use RuntimeException;

final class MediaJournal
{
    public function __construct(private ?FileOperations $files = null)
    {
        $this->files ??= new FileOperations;
    }

    public static function scoped(string $relative): string
    {
        if (! preg_match('~^(?:operations/(?:staging|manifests)/[a-f0-9]{32}(?:\.(?:json|lock)|-[a-f0-9]{32}\.(?:jpg|png|webp))|app/private/refind/(?:items|evidence|claims)/[a-f0-9]{32}\.(?:jpg|png|webp))$~D', $relative)) {
            throw new RuntimeException('Invalid academic operation path.');
        }
        $path = Workspace::storage().'/'.$relative;
        Workspace::assertPath($path);

        return $path;
    }

    public function create(): MediaOperation
    {
        return new MediaOperation(bin2hex(random_bytes(16)), $this->files, true);
    }

    public function load(string $id): MediaOperation
    {
        return new MediaOperation($id, $this->files, false);
    }
}
