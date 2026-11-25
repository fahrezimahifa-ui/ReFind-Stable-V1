<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClaimController;
use App\Http\Controllers\ClaimReadController;
use App\Http\Controllers\DiscoveryController;
use App\Http\Controllers\HandoverController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StaffClaimController;
use App\Http\Controllers\VisualSearchController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PageController::class, 'show'])->defaults('legacy_methods', ['GET', 'HEAD']);
Route::get('/{page}', [PageController::class, 'show'])->where('page', '(?i:(?:index|login|register|browse|item|report|claim|my-reports|admin|history|notifications|profile|settings)\.html)')->defaults('legacy_methods', ['GET', 'HEAD']);
$api = function (string $path, array $methods, array $action, string $access = 'none') {
    $r = Route::match($methods, '/api/'.$path, $action)->defaults('legacy_methods', $methods)->defaults('access', $access);

    return $r;
};
$api('session.php', ['GET'], [AuthController::class, 'session']);
$api('auth.php', ['POST'], [AuthController::class, 'mutate'], 'auth_action');
$api('references.php', ['GET'], [DiscoveryController::class, 'references']);
$api('items.php', ['GET'], [DiscoveryController::class, 'items']);
$api('items.php', ['POST'], [ReportController::class, 'mutate'], 'STUDENT');
$api('notifications.php', ['GET'], [DiscoveryController::class, 'notifications']);
$api('photos.php', ['GET'], [MediaController::class, 'photo']);
$api('avatar.php', ['GET'], [MediaController::class, 'avatar']);
// Phase 6 claim commands: each controller fails closed (501) unless ClaimStages enables its stage.
$api('claims.php', ['POST'], [ClaimController::class, 'mutate'], 'STUDENT');
$api('staff.php', ['POST'], [StaffClaimController::class, 'mutate'], 'BUILDING_MANAGEMENT');
$api('handovers.php', ['POST'], [HandoverController::class, 'mutate'], 'BUILDING_MANAGEMENT');
// Phase 8: account self-service, notification read-marking and registration (each fails closed without its stage).
$api('account.php', ['POST'], [AccountController::class, 'mutate'], 'authenticated');
$api('notifications.php', ['POST'], [AccountController::class, 'notifications'], 'authenticated');
$api('register.php', ['POST'], [AccountController::class, 'register'], 'registration');
$api('claims.php', ['GET'], [ClaimReadController::class, 'claims'], 'authenticated');
$api('staff.php', ['GET'], [ClaimReadController::class, 'staff'], 'BUILDING_MANAGEMENT');
$api('handovers.php', ['GET'], [HandoverController::class, 'index'], 'authenticated');
$api('account.php', ['GET'], [AccountController::class, 'show'], 'authenticated');
// Photo search (Laravel divergence, 25 Nov 2026): fails closed (403 visual_disabled) without the visual stage.
$api('visual-search.php', ['POST'], [VisualSearchController::class, 'explore'], 'STUDENT');
$api('staff-visual-search.php', ['POST'], [VisualSearchController::class, 'staffReports'], 'BUILDING_MANAGEMENT');
