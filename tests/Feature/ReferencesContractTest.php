<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Guest reference read: the pickup desk text matches native 47eea86 (UTF-8, no double encoding). */
final class ReferencesContractTest extends TestCase
{
    public function test_pickup_desk_text_matches_native_and_payload_has_no_double_encoding(): void
    {
        $response = $this->get('/api/references.php', ['HTTP_ACCEPT' => 'application/json']);
        $response->assertOk();
        $desk = $response->json('pickup_desk');
        $this->assertSame('Building Management service desk', $desk['name']);
        $this->assertSame('Gedung A lobby, ground floor', $desk['location']);
        $this->assertSame("Monday\u{2013}Friday, 08:00\u{2013}16:00 WIB", $desk['hours']);
        // UTF-8 read as Windows-1252 leaves these sequences behind; none belong in the payload.
        $this->assertDoesNotMatchRegularExpression('/Ã|Â|â€/u', json_encode($response->json(), JSON_UNESCAPED_UNICODE));
    }
}
