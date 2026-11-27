<?php

namespace App\Visual;

use InvalidArgumentException;
use RuntimeException;

/**
 * Photo preparation shared by queries and the index (pure; GD + fileinfo). Same contract as native
 * visual_prepare_image ("jpeg-white-v1"): a valid JPEG/PNG/WebP of at most 250 KB and 1–1200 px per side is decoded
 * and re-encoded as a JPEG on white, which also drops EXIF/location metadata before anything leaves the machine.
 */
final class Preprocess
{
    public const VERSION = 'jpeg-white-v1';

    public const MAX_BYTES = 250000;

    public static function prepare(string $source): string
    {
        if ($source === '' || strlen($source) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Use a prepared photo of at most 250 KB.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($source);
        $size = @getimagesizefromstring($source);
        if (! $size || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || $size['mime'] !== $mime
            || min($size[0], $size[1]) < 1 || max($size[0], $size[1]) > 1200) {
            throw new InvalidArgumentException('Use a valid JPEG, PNG or WebP photo no larger than 1200 pixels per side.');
        }
        $image = @imagecreatefromstring($source);
        if (! $image) {
            throw new InvalidArgumentException('The photo could not be read.');
        }
        $canvas = imagecreatetruecolor($size[0], $size[1]);
        if (! $canvas) {
            throw new RuntimeException('Photo preparation unavailable.');
        }
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $size[0], $size[1]);
        ob_start();
        try {
            if (! imagejpeg($canvas, null, 85)) {
                throw new RuntimeException('Photo preparation unavailable.');
            }

            return (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
