<?php

namespace App\Http\Controllers;

use App\Exceptions\ContractError;
use App\Services\AuthService;
use App\Services\RegistrationService;
use App\Services\SafeReturnUrl;
use App\Support\ClaimStages;
use App\Support\Contract;
use Illuminate\Http\Request;

final class PageController
{
    public function show(Request $r, AuthService $auth, SafeReturnUrl $urls)
    {
        $route = strtolower(trim($r->path(), '/'));
        if ($route === '') {
            $route = 'index.html';
        }$def = $urls->pageRoutes()[$route] ?? null;
        if (! $def) {
            throw new ContractError('Page not found.', 404, 'not_found');
        }$u = $auth->current();
        $user = $u ? ['role' => $u->role] : null;
        if ($route === 'register.html' && ($user || ! RegistrationService::available())) {
            return redirect($user ? $urls->landingUrl($user) : '/login.html');
        }
        if (! $user && $route === 'index.html') {
            return redirect('/login.html');
        }
        if ($def['roles'] && ! $user) {
            return redirect('/login.html?return='.rawurlencode($urls->safeReturn($r->getRequestUri())));
        }
        if ($def['roles'] && ! in_array($u->role, $def['roles'], true)) {
            return response()->view('message', ['TITLE' => 'Access not available', 'MESSAGE' => 'This page belongs to another role.', 'BACK_URL' => $urls->landingUrl($user), 'BASE_PATH' => '/'], 403, Contract::headers(false));
        }
        if ($user && ($route === 'login.html' || ($route === 'index.html' && $u->role === 'BUILDING_MANAGEMENT'))) {
            return redirect($route === 'login.html' ? $urls->safeReturn($r->query('return', ''), $user) : $urls->landingUrl($user));
        }
        // BM Reports tab needs the Phase 8 status stage; Pickups needs the Phase 7 pickup stage.
        $unsupportedBmTab = $route === 'admin.html' && (($r->query('tab') === 'reports' && ! ClaimStages::has('status')) || ($r->query('tab') === 'handover' && ! ClaimStages::has('pickup')));
        // The claim form exists only where the Phase 6 Student stage is enabled (fail closed elsewhere).
        $served = array_merge(['home.html', 'login.html', 'browse.html', 'item.html', 'report.html', 'my-reports.html', 'bm.html'], ClaimStages::has('student') ? ['claim.html'] : [], ClaimStages::has('pickup') ? ['notifications.html'] : [], ClaimStages::has('history') ? ['history.html'] : [], ClaimStages::has('account') ? ['profile.html', 'settings.html'] : [], RegistrationService::available() ? ['register.html'] : []);
        if ($unsupportedBmTab || ! in_array($def['page'], $served, true)) {
            return response()->view('message', ['TITLE' => 'Not available in this proof of concept', 'MESSAGE' => 'This workflow is reserved for a later reviewed migration phase.', 'BACK_URL' => $u->role === 'BUILDING_MANAGEMENT' ? '/browse.html' : '/index.html', 'BASE_PATH' => '/'], 501, Contract::headers(false));
        }

        return response()->view(basename($def['page'], '.html'), ['BASE_PATH' => '/', 'CLAIMS_READ_ONLY' => ClaimStages::has('student') ? 'false' : 'true', 'BM_DECISIONS' => implode(' ', ClaimStages::decisions()), 'HANDOVERS_UNAVAILABLE' => ClaimStages::has('pickup') ? 'false' : 'true', 'STAFF_REPORTS_UNAVAILABLE' => ClaimStages::has('status') ? 'false' : 'true', 'NOTIFICATIONS_READ_ONLY' => ClaimStages::has('notifications') ? 'false' : 'true'], 200, Contract::headers(false));
    }
}
