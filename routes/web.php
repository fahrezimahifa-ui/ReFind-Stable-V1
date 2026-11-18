<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClaimController;
use App\Http\Controllers\ClaimReadController;
use App\Http\Controllers\DiscoveryController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\StaffClaimController;
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
foreach (['handovers.php' => 'BUILDING_MANAGEMENT', 'account.php' => 'authenticated', 'notifications.php' => 'authenticated', 'register.php' => 'registration'] as $path => $access) {
    $api($path, ['POST'], [DiscoveryController::class, 'unavailable'], $access);
}
$api('claims.php', ['GET'], [ClaimReadController::class, 'claims'], 'authenticated');
$api('staff.php', ['GET'], [ClaimReadController::class, 'staff'], 'BUILDING_MANAGEMENT');
foreach (['handovers.php' => 'authenticated', 'account.php' => 'authenticated'] as $path => $access) {
    $api($path, ['GET'], [DiscoveryController::class, 'unavailable'], $access);
}
$api('visual-search.php', ['POST'], [DiscoveryController::class, 'visual'], 'STUDENT');
