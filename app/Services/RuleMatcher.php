<?php

namespace App\Services;

use DateTimeImmutable;

/** Native v1.0 public-text rules; private clues and media never enter scoring. */
final class RuleMatcher
{
    public const THRESHOLD = 55;

    public const ACTIVE_CODES = ['OPEN', 'UNDER_REVIEW', 'POTENTIAL_MATCH', 'VERIFICATION'];

    public function words(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $matches);

        return array_values(array_unique(array_filter($matches[0], fn ($word) => mb_strlen($word) > 2 && ! in_array($word, ['the', 'and', 'with', 'this', 'that', 'from', 'item', 'found', 'lost', 'floor', 'barang', 'yang', 'dan', 'dari', 'hilang', 'ditemukan'], true))));
    }

    public function score(array $first, array $second): int
    {
        $score = (int) $first['category_id'] === (int) $second['category_id'] ? 25 : 0;
        if ($first['location_id'] && (int) $first['location_id'] === (int) $second['location_id']) {
            $score += 25;
        }
        $score += min(35, count(array_intersect($this->words($first['title'].' '.$first['description']), $this->words($second['title'].' '.$second['description']))) * 7);
        $days = (new DateTimeImmutable($first['event_date']))->diff(new DateTimeImmutable($second['event_date']))->days;
        $score += $days <= 2 ? 15 : ($days <= 7 ? 10 : ($days <= 14 ? 5 : 0));

        return min(100, $score);
    }

    public function suggestionScore(array $lost, array $found): ?int
    {
        if ((int) $lost['reporter_user_id'] === (int) $found['reporter_user_id'] || (int) $lost['category_id'] !== (int) $found['category_id']) {
            return null;
        }
        if ((new DateTimeImmutable($found['event_date'])) < (new DateTimeImmutable($lost['event_date']))->modify('-1 day')) {
            return null;
        }
        $score = $this->score($lost, $found);

        return $score >= self::THRESHOLD ? $score : null;
    }
}
