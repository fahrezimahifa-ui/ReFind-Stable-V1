<?php

namespace App\Visual;

/**
 * Describe-and-match (InferHub has no embedding models, owner decision 27 Nov 2026): a vision chat model writes a
 * structured description of the main item in a photo, and this class turns that description into a fixed-length
 * hashed feature vector, so the existing index, cosine ranking and calibrated cutoffs apply unchanged.
 * Pure: prompt, request body, response parsing and feature hashing need no network or profile.
 */
final class Describer
{
    public const VERSION = 'describe-v2-640';

    public const MAX_SIDE = 640;

    public const DIMENSIONS = 1024;

    public const CATEGORIES = ['bag', 'wallet', 'phone', 'keys', 'card', 'headphones', 'charger_cable_powerbank', 'computer_tablet', 'calculator', 'game_controller',
        'camera', 'watch', 'glasses', 'jewelry', 'bottle_cup', 'umbrella', 'clothing', 'shoes', 'stationery', 'book_notebook', 'electronics_other', 'toy', 'other'];

    public const COLORS = ['black', 'white', 'grey', 'silver', 'gold', 'brown', 'beige', 'red', 'orange', 'yellow', 'green', 'blue', 'navy', 'purple', 'pink', 'transparent', 'multicolour'];

    /** Field weights: identity clues (brand, visible text, category) count most; background never counts. */
    private const WEIGHTS = ['category' => 3.0, 'object' => 2.0, 'color' => 2.0, 'brand' => 3.0, 'text' => 2.0, 'material' => 1.0, 'feature' => 1.5];

    public static function prompt(): string
    {
        return 'You describe the single main lost-and-found item in a photo so it can be matched with other photos of the SAME physical item. '
            .'Ignore the background, hands, table and lighting. Reply with JSON only, no prose, exactly these keys: '
            .'{"category": one of ['.implode(', ', self::CATEGORIES).'], '
            .'"object": short noun phrase, max 5 words (e.g. "flip calculator case"), '
            .'"colors": 1-3 main item colours from ['.implode(', ', self::COLORS).'], '
            .'"brand": brand or model name if clearly visible, else null, '
            .'"visible_text": up to 4 short words or codes printed on the item, else [], '
            .'"material": one word (leather, plastic, metal, fabric, glass, paper, rubber, other), '
            .'"features": up to 4 phrases of at most 4 words about distinctive physical details (shape, stickers, damage, attachments)}. '
            .'Keep it compact: no explanations.';
    }

    /** OpenAI-style chat request with the photo as a data URI (works for the gateway's Claude, Qwen and Gemini routes). */
    public static function request(string $model, string $jpeg): array
    {
        $jpeg = self::shrink($jpeg);

        return ['model' => $model, 'temperature' => 0, 'max_tokens' => 220, 'messages' => [
            ['role' => 'system', 'content' => self::prompt()],
            ['role' => 'user', 'content' => [
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,'.base64_encode($jpeg)]],
                ['type' => 'text', 'text' => 'Describe the main item as JSON.'],
            ]],
        ]];
    }

    /** Longest side at most MAX_SIDE px (fewer image tokens, faster replies); smaller photos pass unchanged. */
    public static function shrink(string $jpeg): string
    {
        $image = @imagecreatefromstring($jpeg);
        if (! $image) {
            throw new ProviderError('media');
        }
        [$w, $h] = [imagesx($image), imagesy($image)];
        if (max($w, $h) <= self::MAX_SIDE) {
            return $jpeg;
        }
        $scale = self::MAX_SIDE / max($w, $h);
        $small = imagescale($image, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)), IMG_BICUBIC);
        ob_start();
        imagejpeg($small, null, 85);

        return (string) ob_get_clean();
    }

    /** Extracts and normalizes the description from a chat completion. */
    public static function parse(mixed $json): array
    {
        $content = is_array($json) ? ($json['choices'][0]['message']['content'] ?? null) : null;
        if (is_array($content)) { // some routes return content parts
            $content = implode('', array_map(fn ($p) => is_array($p) ? (string) ($p['text'] ?? '') : '', $content));
        }
        if (! is_string($content) || ! preg_match('/\{.*\}/s', $content, $m)) {
            throw new ProviderError('bad_response');
        }
        $d = json_decode($m[0], true, 8);
        if (! is_array($d)) {
            throw new ProviderError('bad_response');
        }
        $words = fn ($v, int $max) => array_slice(array_values(array_filter(array_map(fn ($s) => is_string($s) ? mb_strtolower(trim($s), 'UTF-8') : '', is_array($v) ? $v : [$v]), fn ($s) => $s !== '' && mb_strlen($s) <= 60)), 0, $max);
        $category = is_string($d['category'] ?? null) && in_array(strtolower($d['category']), self::CATEGORIES, true) ? strtolower($d['category']) : 'other';
        $colors = array_values(array_intersect($words($d['colors'] ?? [], 3), self::COLORS));
        $brand = is_string($d['brand'] ?? null) && trim($d['brand']) !== '' && strtolower(trim($d['brand'])) !== 'null' ? mb_strtolower(trim($d['brand']), 'UTF-8') : null;

        return ['category' => $category, 'object' => $words($d['object'] ?? '', 1)[0] ?? '', 'colors' => $colors, 'brand' => $brand,
            'visible_text' => $words($d['visible_text'] ?? [], 4), 'material' => $words($d['material'] ?? '', 1)[0] ?? '', 'features' => $words($d['features'] ?? [], 5)];
    }

    /** Signed feature hashing of the description into a unit vector. */
    public static function vector(array $d): array
    {
        $v = array_fill(0, self::DIMENSIONS, 0.0);
        $add = function (string $feature, float $weight) use (&$v) {
            $h = crc32($feature);
            $v[$h % self::DIMENSIONS] += (($h >> 31) & 1 ? -1 : 1) * $weight;
        };
        $tokens = fn (string $s) => array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', $s) ?: [], fn ($t) => mb_strlen($t) > 1 && ! in_array($t, ['the', 'and', 'with', 'of', 'a', 'an'], true)));
        $add('cat:'.$d['category'], self::WEIGHTS['category']);
        foreach ($tokens($d['object']) as $t) {
            $add('obj:'.$t, self::WEIGHTS['object']);
        }
        foreach ($d['colors'] as $c) {
            $add('color:'.$c, self::WEIGHTS['color']);
        }
        if ($d['brand'] !== null) {
            $add('brand:'.$d['brand'], self::WEIGHTS['brand']);
            foreach ($tokens($d['brand']) as $t) {
                $add('text:'.$t, self::WEIGHTS['text']);
            }
        }
        foreach ($d['visible_text'] as $s) {
            foreach ($tokens($s) as $t) {
                $add('text:'.$t, self::WEIGHTS['text']);
            }
        }
        if ($d['material'] !== '') {
            $add('mat:'.$d['material'], self::WEIGHTS['material']);
        }
        foreach ($d['features'] as $f) {
            foreach ($tokens($f) as $t) {
                $add('feat:'.$t, self::WEIGHTS['feature']);
            }
        }

        return Vector::normalize($v);
    }
}
