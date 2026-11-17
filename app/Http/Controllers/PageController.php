<?php

namespace App\Http\Controllers;

use App\Exceptions\ContractError;
use App\Services\AuthService;
use App\Services\SafeReturnUrl;
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
        if ($route === 'register.html') {
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
        if ($route === 'my-reports.html' && $r->query('tab') === 'claims') {
            return response()->view('message', ['TITLE' => 'Claims are not available yet', 'MESSAGE' => 'Reporting is available. Claim and pickup workflows will be added in a later release.', 'BACK_URL' => '/my-reports.html', 'BASE_PATH' => '/'], 501, Contract::headers(false));
        }
        if (! in_array($def['page'], ['home.html', 'login.html', 'browse.html', 'item.html', 'report.html', 'my-reports.html'], true)) {
            return response()->view('message', ['TITLE' => 'Not available in this proof of concept', 'MESSAGE' => 'This workflow is reserved for a later reviewed migration phase.', 'BACK_URL' => $u->role === 'BUILDING_MANAGEMENT' ? '/browse.html' : '/index.html', 'BASE_PATH' => '/'], 501, Contract::headers(false));
        }

        return response()->view(basename($def['page'], '.html'), ['BASE_PATH' => '/'], 200, Contract::headers(false));
    }
}
