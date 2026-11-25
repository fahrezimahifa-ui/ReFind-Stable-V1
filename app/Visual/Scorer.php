<?php

namespace App\Visual;

/**
 * Ranking for photo search (pure). Each report keeps its single best score over every query photo and every one of
 * its indexed photos ("best match per item", owner decision 25 Nov 2026). Ties prefer the lower photo ID, then the
 * lower report ID, as native visual_rank does. Scores never leave the server: callers only see the match label.
 */
final class Scorer
{
    public const LIMIT = 12;

    /**
     * @param  iterable<array{item_id:int,photo_id:int,vector:array}>  $candidates  unit vectors of indexed photos
     * @param  list<array>  $queries  unit vectors of the 1–3 query photos
     * @return list<array{item_id:int,photo_id:int,score:float}>
     */
    public static function rank(iterable $candidates, array $queries): array
    {
        $best = [];
        foreach ($candidates as $row) {
            $item = (int) $row['item_id'];
            $photo = (int) $row['photo_id'];
            $score = -1.0;
            foreach ($queries as $query) {
                $score = max($score, Vector::cosine($query, $row['vector']));
            }
            $old = $best[$item] ?? null;
            if (! $old || $score > $old['score'] || ($score === $old['score'] && $photo < $old['photo_id'])) {
                $best[$item] = ['item_id' => $item, 'photo_id' => $photo, 'score' => $score];
            }
        }
        $ranked = array_values($best);
        usort($ranked, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: ($a['item_id'] <=> $b['item_id']));

        return $ranked;
    }

    /**
     * Keeps matches at or above the cutoff and labels them; raw scores are dropped here.
     *
     * @return list<array{item_id:int,photo_id:int,label:string}>
     */
    public static function select(array $ranked, float $cutoff, float $strong, int $limit = self::LIMIT): array
    {
        $out = [];
        foreach ($ranked as $match) {
            if ($match['score'] < $cutoff || count($out) >= $limit) {
                break;
            }
            $out[] = ['item_id' => $match['item_id'], 'photo_id' => $match['photo_id'], 'label' => self::label($match['score'], $cutoff, $strong)];
        }

        return $out;
    }

    /** "Very similar" only above a strong cutoff that sits above the main one; otherwise every match is "Similar". */
    public static function label(float $score, float $cutoff, float $strong): string
    {
        return $strong > $cutoff && $score >= $strong ? 'very_similar' : 'similar';
    }
}
