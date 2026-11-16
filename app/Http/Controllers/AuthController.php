<?php

namespace App\Http\Controllers;

use App\Services\AuthService;
use App\Services\SafeReturnUrl;
use App\Support\Contract;
use Illuminate\Http\Request;

final class AuthController
{
    public function session(Request $r, AuthService $auth)
    {
        Contract::allowed($r->query(), []);
        $u = $auth->current();

        return Contract::json(['authenticated' => $u !== null, 'user' => $u ? $auth->identity($u) : null, 'csrf' => $r->session()->token(), 'visual_search_enabled' => false, 'registration_enabled' => false, 'expires' => ['idle_seconds' => 1800, 'absolute_seconds' => 28800]]);
    }

    public function mutate(Request $r, AuthService $auth, SafeReturnUrl $urls)
    {
        $data = $r->request->all();
        $action = $data['action'];
        if ($action === 'logout') {
            $auth->logout();

            return Contract::json(['authenticated' => false, 'redirect' => '/login.html']);
        }
        $email = mb_strtolower(Contract::text($data, 'email', 200, false), 'UTF-8');
        $pw = $data['password'] ?? '';
        if (! is_string($pw) || ! mb_check_encoding($pw, 'UTF-8') || strlen($pw) > 72 || str_contains($pw, "\0")) {
            $pw = '';
        }
        $u = $auth->login($email, $pw);

        return Contract::json(['authenticated' => true, 'user' => $auth->identity($u), 'csrf' => $r->session()->token(), 'redirect' => $urls->safeReturn($data['return'] ?? '', ['role' => $u->role])]);
    }
}
