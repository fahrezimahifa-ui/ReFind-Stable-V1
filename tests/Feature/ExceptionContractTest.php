<?php

namespace Tests\Feature;

use App\Foundation\WriteFailure;
use App\Support\Contract;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ExceptionContractTest extends TestCase
{
    public function test_laravel_validation_maps_to_existing_field_contract(): void
    {
        $v = Validator::make(['name' => ''], ['name' => 'required']);
        $response = Contract::error(new ValidationException($v), Request::create('/api/items.php', 'POST'));
        $this->assertSame(422, $response->getStatusCode());
        $data = $response->getData(true);
        $this->assertSame('validation_error', $data['code']);
        $this->assertIsString($data['fields']['name']);
    }

    public function test_laravel_authentication_and_authorization_map_to_json(): void
    {
        foreach ([[new AuthenticationException, 401, 'authentication_required'], [new AuthorizationException, 403, 'forbidden']] as [$e,$status,$code]) {
            $r = Contract::error($e, Request::create('/api/items.php', 'POST'));
            $this->assertSame($status, $r->getStatusCode());
            $this->assertSame($code, $r->getData(true)['code']);
        }
    }

    public function test_uncertain_commit_requests_refresh_without_leaking_cause(): void
    {
        $e = new WriteFailure(str_repeat('a', 32), true, new \RuntimeException('private database diagnostic'));
        $r = Contract::error($e, Request::create('/api/items.php', 'POST'));
        $this->assertSame(500, $r->getStatusCode());
        $d = $r->getData(true);
        $this->assertSame('server_error', $d['code']);
        $this->assertStringContainsString('Reload', $d['message']);
        $this->assertStringNotContainsString('private database',$d['message']);
    }
}
