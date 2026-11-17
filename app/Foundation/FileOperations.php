<?php

namespace App\Foundation;

use RuntimeException;

class FileOperations
{
    public function move(string $source, string $target): void
    {
        if (file_exists($target) || ! rename($source, $target)) {
            throw new RuntimeException('Private file promotion failed.');
        }
    }

    public function remove(string $path): void
    {
        if (is_file($path) && ! unlink($path)) {
            throw new RuntimeException('Private file cleanup failed.');
        }
        if (is_dir($path)) {
            throw new RuntimeException('Refusing directory cleanup.');
        }
    }

    public function writeExclusive(string $path, string $bytes): void
    {
        $f = fopen($path, 'xb');
        if (! $f) {
            throw new RuntimeException('Private file creation failed.');
        }
        try {
            if (fwrite($f, $bytes) !== strlen($bytes) || ! fflush($f) || (function_exists('fsync') && ! fsync($f))) {
                throw new RuntimeException('Private file flush failed.');
            }
        } finally {
            fclose($f);
        }
    }
}
