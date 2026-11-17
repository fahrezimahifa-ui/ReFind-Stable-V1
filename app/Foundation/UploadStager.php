<?php

namespace App\Foundation;

use App\Exceptions\ContractError;
use App\Support\Workspace;
use Illuminate\Http\UploadedFile;

final class UploadStager
{
    public function stage(UploadedFile $file, string $collection, MediaOperation $operation): string
    {
        if (! $file->isValid()) {
            throw new ContractError('Upload incomplete.', 422, 'validation_error');
        }
        $path = $file->getRealPath();
        $prefix = Workspace::norm(Workspace::storage().'/uploads').'/';
        if (! $path || ! str_starts_with(Workspace::norm($path), $prefix)) {
            throw new ContractError('Unowned upload refused.', 422, 'validation_error');
        }
        Workspace::assertPath($path);
        if (filesize($path) > 250000) {
            throw new ContractError('Prepared photos must be at most 250 KB.', 422, 'validation_error');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $size = @getimagesize($path);
        $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (! isset($extensions[$mime]) || ! $size || min($size[0], $size[1]) < 1 || max($size[0], $size[1]) > 1200 || $size['mime'] !== $mime) {
            throw new ContractError('Use a valid prepared JPEG, PNG or WebP image.', 422, 'validation_error');
        }

        return $operation->stage(file_get_contents($path), $collection, $extensions[$mime]);
    }
}
