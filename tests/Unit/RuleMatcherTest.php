<?php

namespace Tests\Unit;

use App\Services\RuleMatcher;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../../tools/NativeMatchingOracle.php';

final class RuleMatcherTest extends TestCase
{
    public function test_exact_native_tokens_scores_eligibility_and_boundary_weights(): void
    {
        \NativeMatchingOracle::boot();
        $matcher = new RuleMatcher;
        foreach (['The and with ITEM floor barang yang dan dari hilang ditemukan', 'Blue BLUE blue gold 123 12', 'École кошелек dompet-biru 百科知识', "phone_case Wi-Fi O'Reilly"] as $text) {
            $this->assertSame(\ReFindNativeOracle\matching_words($text), $matcher->words($text));
        }
        $base = ['reporter_user_id' => 1, 'category_id' => 1, 'location_id' => 35, 'title' => 'Blue gold green silver black orange', 'description' => '', 'event_date' => '2026-01-15'];
        foreach ([-2, -1, 0, 2, 3, 7, 8, 14, 15] as $days) {
            foreach ([35, 36, null] as $location) {
                foreach ([1, 2] as $category) {
                    foreach ([1, 2] as $owner) {
                        foreach (['Blue', 'Blue gold', 'Blue gold green silver black orange', 'lost found item'] as $title) {
                            $candidate = array_replace($base, ['reporter_user_id' => $owner, 'category_id' => $category, 'location_id' => $location, 'title' => $title, 'event_date' => (new \DateTimeImmutable($base['event_date']))->modify($days.' days')->format('Y-m-d')]);
                            $this->assertSame(\ReFindNativeOracle\match_score($base, $candidate), $matcher->score($base, $candidate));
                            $this->assertSame(\ReFindNativeOracle\suggestion_score($base, $candidate), $matcher->suggestionScore($base, $candidate));
                        }
                    }
                }
            }
        }
        $blank = array_replace($base, ['title' => '', 'description' => '']);
        $this->assertSame(55, $matcher->suggestionScore($blank, array_replace($blank, ['reporter_user_id' => 2, 'event_date' => '2026-01-29'])));
        $this->assertNull($matcher->suggestionScore($blank, array_replace($blank, ['reporter_user_id' => 2, 'event_date' => '2026-01-30'])));
        $this->assertSame(65, $matcher->suggestionScore($blank, array_replace($blank, ['reporter_user_id' => 2, 'event_date' => '2026-01-14'])));
        $this->assertNull($matcher->suggestionScore($base, array_replace($base, ['reporter_user_id' => 2, 'event_date' => '2026-01-13'])));
        $this->assertSame(100, $matcher->score($base, $base));
        $this->assertSame($matcher->score($base, $base), $matcher->score($base + ['verification_clue' => 'private A'], $base + ['verification_clue' => 'private B']));
        $this->assertSame(\ReFindNativeOracle\ACTIVE_ITEM_CODES, RuleMatcher::ACTIVE_CODES);
        $this->assertSame(\ReFindNativeOracle\MATCH_THRESHOLD, RuleMatcher::THRESHOLD);
    }
}
