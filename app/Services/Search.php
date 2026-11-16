<?php

namespace App\Services;

final class Search
{
    public function terms(string $query, int $maxWords = 8): array
    {
        $query = trim($query);
        $terms = [];
        foreach (preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            // Surrounding punctuation ("wallet," or quoted phrases) is dropped; inner marks such as ID/Wallet stay.
            $word = mb_strtolower((string) preg_replace('/^[\p{P}\p{S}]+|[\p{P}\p{S}]+$/u', '', $word));
            if ($word === '') {
                continue;
            }
            $forms = [$word];
            $length = mb_strlen($word);
            if ($length > 4 && str_ends_with($word, 'ies')) {
                $forms[] = mb_substr($word, 0, -3).'y';
            } elseif ($length > 4 && preg_match('/(?:x|ch|sh|ss|zz)es$/u', $word)) {
                $forms[] = mb_substr($word, 0, -2);
            } elseif ($length > 3 && str_ends_with($word, 's') && ! preg_match('/(?:ss|us|is)$/u', $word)) {
                $forms[] = mb_substr($word, 0, -1);
            }
            // Matching is by substring, so a form that contains another accepted form adds nothing.
            $forms = array_values(array_filter($forms, fn ($form) => ! array_filter($forms, fn ($other) => $other !== $form && str_contains($form, $other))));
            $terms[implode("\0", $forms)] = $forms; // "wallet wallets" needs only one condition
            if (count($terms) === $maxWords) {
                break;
            }
        }
        // Input made only of symbols keeps the previous whole-text behavior instead of matching everything.
        if (! $terms && $query !== '') {
            return [[$query]];
        }

        return array_values($terms);
    }

    public function condition(array $terms, string $haystack): array
    {
        $parts = [];
        $values = [];
        foreach ($terms as $forms) {
            $parts[] = '('.implode(' OR ', array_fill(0, count($forms), 'LOCATE(?,'.$haystack.')>0')).')';
            array_push($values, ...$forms);
        }

        return [implode(' AND ', $parts), $values];
    }
}
