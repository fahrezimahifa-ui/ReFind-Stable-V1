<?php

namespace Tests\Feature;

use App\Http\Middleware\ReFindRequestGuard;
use App\Models\User;
use App\Services\AuthService;
use App\Session\ReFindSessionStore;
use App\Support\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AuthContractTest extends TestCase
{
    private function token(): string
    {
        return str_repeat('a', 64);
    }

    private function authenticated(int $id = 1, array $override = []): self
    {
        $u = User::findOrFail($id);
        $meta = ['issued_at' => now()->timestamp, 'last_seen' => now()->timestamp, 'fingerprint' => app(AuthService::class)->fingerprint($u->password_hash)];

        return $this->actingAs($u)->withSession(['_token' => $this->token(), 'refind' => array_replace($meta, $override)]);
    }

    public function test_testing_environment_uses_real_compatibility_store_and_middleware(): void
    {
        $this->assertTrue($this->app->runningUnitTests());
        $this->assertInstanceOf(ReFindSessionStore::class, app('session')->driver());
        $this->get('/api/session.php')->assertOk();
        $this->assertSame(['mariadb'], array_keys(config('database.connections')));
        $this->assertSame(['refind_private'], array_keys(config('filesystems.disks')));
        $group = app('router')->getMiddlewareGroups()['web'];
        $this->assertContains(ReFindRequestGuard::class, $group);
        $this->assertNotContains(PreventRequestForgery::class, $group);
        $this->get('/api/session.php')->assertOk()->assertJsonPath('authenticated', false)->assertJsonPath('visual_search_enabled', false);
    }

    public function test_tokenless_login_is_rejected_even_while_running_unit_tests(): void
    {
        $this->withSession(['_token' => $this->token()])->post('/api/auth.php', ['action' => 'login', 'email' => 'nobody@refind-poc.invalid', 'password' => 'invalid-password'])->assertStatus(403)->assertJsonPath('code', 'csrf_invalid');
    }

    public function test_same_origin_shortcut_does_not_bypass_token(): void
    {
        $this->withSession(['_token' => $this->token()])->withHeaders(['Sec-Fetch-Site' => 'same-origin'])->post('/api/auth.php', ['action' => 'login'])->assertStatus(403)->assertJsonPath('code', 'csrf_invalid');
    }

    public function test_conflicting_and_array_body_tokens_are_rejected(): void
    {
        $this->withSession(['_token' => $this->token()])->withHeaders(['X-CSRF-Token' => $this->token()])->post('/api/auth.php', ['action' => 'login', '_csrf' => str_repeat('b', 64)])->assertStatus(403)->assertJsonPath('code', 'csrf_invalid');
        $this->withSession(['_token' => $this->token()])->post('/api/auth.php', ['action' => 'login', '_csrf' => ['bad']])->assertStatus(403);
    }

    public function test_body_token_alone_reaches_authentication(): void
    {
        $this->withSession(['_token' => $this->token()])->post('/api/auth.php', ['action' => 'login', 'email' => 'body-token@refind-poc.invalid', 'password' => 'invalid-password', '_csrf' => $this->token()])->assertStatus(401)->assertJsonPath('code', 'invalid_credentials');
    }

    public function test_foreign_origin_is_rejected_with_a_valid_token(): void
    {
        $this->withSession(['_token' => $this->token()])->withHeaders(['X-CSRF-Token' => $this->token(), 'Origin' => 'https://example.invalid'])->post('/api/auth.php', ['action' => 'login'])->assertStatus(403)->assertJsonPath('code', 'csrf_invalid');
    }

    public function test_idle_expiry_clears_identity(): void
    {
        $this->authenticated(1, ['last_seen' => now()->timestamp - 1800])->get('/api/items.php')->assertStatus(401)->assertJsonPath('code', 'authentication_required');
    }

    public function test_absolute_expiry_cannot_be_extended_by_activity(): void
    {
        $this->authenticated(1, ['issued_at' => now()->timestamp - 28800])->get('/api/items.php')->assertStatus(401);
    }

    public function test_fingerprint_and_inactive_account_are_checked(): void
    {
        $this->authenticated(1, ['fingerprint' => str_repeat('0', 64)])->get('/api/items.php')->assertStatus(401);
        $this->authenticated(4)->get('/api/items.php')->assertStatus(401);
    }

    public function test_public_projection_omits_private_verification_fields(): void
    {
        $r = $this->authenticated()->get('/api/items.php?id=1')->assertOk();
        $this->assertArrayNotHasKey('verification_clue', $r->json('item'));
        $this->assertArrayNotHasKey('private_clue', $r->json('item'));
        $this->assertSame('1', $r->json('item.item_id'));
    }

    public function test_other_role_and_private_evidence_are_forbidden(): void
    {
        $this->authenticated()->get('/admin.html')->assertStatus(403);
        $this->get('/api/photos.php?kind=claim&id=1')->assertStatus(403);
    }

    public function test_business_mutation_remains_unavailable_after_valid_guards(): void
    {
        $this->authenticated()->withHeaders(['X-CSRF-Token' => $this->token()])->post('/api/claims.php', ['action' => 'submit'])->assertStatus(501)->assertJsonPath('code', 'feature_pending');
    }

    public function test_no_head_expansion_for_api(): void
    {
        $this->call('HEAD', '/api/session.php')->assertStatus(405)->assertHeader('Allow', 'GET');
    }

    public function test_application_database_identity_is_checked_before_connecting(): void
    {
        $cfg = config('database.connections.mariadb');
        $cfg['port'] = 3306;
        $this->expectException(\RuntimeException::class);
        Workspace::assertDatabase($cfg);
    }

    public function test_application_credentials_cannot_update_even_a_zero_row_selection(): void
    {
        try {
            DB::statement('UPDATE users SET full_name=full_name WHERE 1=0');
            $this->fail('Application user unexpectedly has UPDATE access.');
        } catch (QueryException $e) {
            $this->assertSame(1142, (int) $e->errorInfo[1]);
        }
    }
}
