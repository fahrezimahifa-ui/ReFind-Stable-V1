<?php

namespace Tests\Unit;

use App\Session\ReFindSessionStore;
use Illuminate\Session\ArraySessionHandler;
use PHPUnit\Framework\TestCase;

final class SessionStoreTest extends TestCase
{
    public function test_start_and_all_framework_regeneration_paths_keep_one_hex_token(): void
    {
        $s = new ReFindSessionStore('unit', new ArraySessionHandler(480), null, 'json');
        $s->start();
        $first = $s->token();
        $id = $s->getId();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $first);
        $this->assertSame(40, strlen($id));
        $s->regenerate(true);
        $this->assertNotSame($first, $s->token());
        $this->assertNotSame($id, $s->getId());
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $s->token());
        $s->invalidate();
        $s->regenerateToken();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $s->token());
        $this->assertNull($s->get('refind'));
    }

    public function test_malformed_default_length_token_is_replaced_at_start(): void
    {
        $s = new ReFindSessionStore('unit', new ArraySessionHandler(480), null, 'json');
        $s->put('_token', str_repeat('x', 40));
        $s->start();
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $s->token());
        $this->assertNull($s->get('csrf'));
    }
}
