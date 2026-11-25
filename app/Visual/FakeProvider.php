<?php

namespace App\Visual;

/**
 * Deterministic, offline stand-in for the hosted engine: a 128-value vector made of an 8×8 grey layout (mean-removed)
 * and a 4×4×4 colour histogram, weighted equally. The same photo always scores 1.0, crops and re-encodes of it score
 * high, unrelated photos score lower. Used by tests and stable screenshots; only phase8-dev may select it.
 */
final class FakeProvider implements Provider
{
    public const KEY = 'fake:grey8+rgb4:v1:'.Preprocess::VERSION;

    public function modelKey(): string
    {
        return self::KEY;
    }

    public function embed(array $jpegs): array
    {
        return array_map(fn ($jpeg) => self::vector($jpeg), $jpegs);
    }

    public static function vector(string $jpeg): array
    {
        $image = @imagecreatefromstring($jpeg);
        if (! $image) {
            throw new ProviderError('media');
        }
        $small = imagecreatetruecolor(8, 8);
        imagecopyresampled($small, $image, 0, 0, 0, 0, 8, 8, imagesx($image), imagesy($image));
        $grey = [];
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $c = imagecolorat($small, $x, $y);
                $grey[] = 0.299 * (($c >> 16) & 255) + 0.587 * (($c >> 8) & 255) + 0.114 * ($c & 255);
            }
        }
        $mean = array_sum($grey) / 64;
        $grey = array_map(fn ($v) => $v - $mean, $grey);
        $sample = imagecreatetruecolor(32, 32);
        imagecopyresampled($sample, $image, 0, 0, 0, 0, 32, 32, imagesx($image), imagesy($image));
        $hist = array_fill(0, 64, 0.0);
        for ($y = 0; $y < 32; $y++) {
            for ($x = 0; $x < 32; $x++) {
                $c = imagecolorat($sample, $x, $y);
                $hist[intdiv(($c >> 16) & 255, 64) * 16 + intdiv(($c >> 8) & 255, 64) * 4 + intdiv($c & 255, 64)] += 1.0;
            }
        }

        return Vector::normalize(array_merge(self::part($grey), self::part($hist)));
    }

    /** Unit-length part scaled by sqrt(1/2), so the cosine of two vectors is the mean of the parts' cosines. */
    private static function part(array $values): array
    {
        $norm = sqrt(array_sum(array_map(fn ($v) => $v * $v, $values)));

        return $norm > 0 ? array_map(fn ($v) => $v / $norm * M_SQRT1_2, $values) : array_fill(0, count($values), 0.0);
    }
}
