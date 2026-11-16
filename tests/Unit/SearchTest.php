<?php

namespace Tests\Unit;

use App\Services\Search;
use PHPUnit\Framework\TestCase;

final class SearchTest extends TestCase
{
    public function test_word_forms_and_bound_sql_preserve_native_search(): void
    {
        $s = new Search;
        foreach (['wallets' => [['wallet']], 'batteries' => [['batteries', 'battery']], 'glasses' => [['glass']], 'watches' => [['watch']], '???' => [['???']], 'wallet wallets Wallet' => [['wallet']]] as $q => $expected) {
            $this->assertSame($expected, $s->terms($q));
        }
        [$sql,$values] = $s->condition($s->terms("brown wallets x' OR 1=1 --"), "CONCAT_WS(' ',v.title,v.description)");
        $this->assertSame(count($values), substr_count($sql, '?'));
        $this->assertStringNotContainsString('brown', $sql);
        $this->assertStringContainsString(' AND ', $sql);
        $this->assertLessThanOrEqual(8, count($s->terms('a1 b2 c3 d4 e5 f6 g7 h8 i9')));
    }
}
