<?php

namespace App\Http\Controllers;

use App\Exceptions\ContractError;
use App\Services\AccountService;
use App\Services\AuthService;
use App\Services\NotificationService;
use App\Services\RegistrationService;
use App\Services\SafeReturnUrl;
use App\Support\ClaimStages;
use App\Support\Contract;
use Illuminate\Http\Request;

/** Phase 8 account, notification read-marking and registration endpoints; each fails closed without its stage. */
final class AccountController
{
    private static function gate(string $stage): void
    {
        if (! ClaimStages::has($stage)) {
            throw new ContractError('This operation is not available in the proof of concept.', 501, 'feature_pending');
        }
    }

    public function show(Request $request, AuthService $auth, AccountService $accounts)
    {
        $u = $auth->require();
        self::gate('account');
        Contract::allowed($request->query(), []);

        return Contract::json(['profile' => $accounts->profile($u->getAttributes())]);
    }

    public function mutate(Request $request, AuthService $auth, AccountService $accounts)
    {
        $u = $auth->require();
        self::gate('account');
        Contract::allowed($request->query(), []);

        return Contract::json($accounts->mutate($u, $request));
    }

    public function notifications(Request $request, AuthService $auth, NotificationService $notifications)
    {
        $u = $auth->require();

        return Contract::json($notifications->mark(['user_id' => (string) $u->user_id, 'role' => $u->role], $request));
    }

    public function register(Request $request, AuthService $auth, RegistrationService $registrations, SafeReturnUrl $urls)
    {
        // Availability, already-authenticated and CSRF checks ran in the request guard (native order).
        $u = $registrations->register($request);
        $identity = $auth->identity($u);

        return Contract::json(['authenticated' => true, 'user' => $identity, 'csrf' => $request->session()->token(), 'redirect' => $urls->landingUrl(['role' => $u->role])], 201);
    }
}
