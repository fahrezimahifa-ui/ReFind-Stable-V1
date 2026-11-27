<?php

namespace App\Visual;

use InvalidArgumentException;

/**
 * Unit vectors for photo search (pure; no profile or database). Vectors are stored as little-endian float32
 * ("g*"), the same packing as native visual-core.php, and are normalized on the way in and on the way out.
 */
final class Vector
{
    /** @param list<int|float> $values */
    public static function normalize(array $values, ?int $dimensions = null): array
    {
        if (! array_is_list($values) || $values === [] || ($dimensions !== null && count($values) !== $dimensions)) {
            throw new InvalidArgumentException('Invalid vector size.');
        }
        $squares = 0.0;
        foreach ($values as $value) {
            if ((! is_float($value) && ! is_int($value)) || ! is_finite((float) $value)) {
                throw new InvalidArgumentException('Invalid vector value.');
            }
            $squares += $value * $value;
        }
        if ($squares <= 0.0 || ! is_finite($squares)) {
            throw new InvalidArgumentException('Invalid vector norm.');
        }
        $norm = sqrt($squares);

        return array_map(fn ($value) => $value / $norm, $values);
    }

    public static function pack(array $unit): string
    {
        return pack('g*', ...$unit);
    }

    public static function unpack(string $packed, int $dimensions): ?array
    {
        if ($dimensions < 1 || strlen($packed) !== 4 * $dimensions) {
            return null;
        }
        try {
            return self::normalize(array_values(unpack('g*', $packed)), $dimensions);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function cosine(array $a, array $b): float
    {
        $dot = 0.0;
        foreach ($a as $index => $value) {
            $dot += $value * $b[$index];
        }

        return min(1.0, max(-1.0, $dot));
    }
}
