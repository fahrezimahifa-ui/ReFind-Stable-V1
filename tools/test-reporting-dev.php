<?php

use App\Services\ReportService;
use App\Support\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

require __DIR__.'/academic-bootstrap.php';
require __DIR__.'/AcademicSnapshot.php';
try {
    if (Workspace::profile()['profile'] !== 'academic-dev') {
        throw new RuntimeException('Synthetic B-dev only.');
    }
    $admin = AcademicSnapshot::admin();
    $before = AcademicSnapshot::capture($admin);
    $snapshot = AcademicSnapshot::save($admin);
    $checks = [];
    $check = function ($label, $ok) use (&$checks) {
        if (! $ok) {
            throw new RuntimeException('B-dev gate: '.$label);
        }$checks[] = $label;
    };
    $service = app(ReportService::class);
    $data = ['action' => 'create', 'type' => 'LOST', 'title' => 'Synthetic academic dev quartz jade cobalt', 'description' => 'Public report smoke fixture.', 'category_id' => '1', 'location_id' => '35', 'date' => '2026-01-15', 'time' => '', 'clue' => 'Private B-dev synthetic detail.'];
    $a = $service->mutate(['user_id' => '1', 'role' => 'STUDENT'], Request::create('/api/items.php', 'POST', $data));
    $b = $service->mutate(['user_id' => '2', 'role' => 'STUDENT'], Request::create('/api/items.php', 'POST', array_replace($data, ['type' => 'FOUND'])));
    $pair = DB::selectOne('SELECT match_score,match_status FROM item_matches WHERE lost_item_id=? AND found_item_id=?', [$a['id'], $b['id']]);
    $check('dev report pair uses genuine matching', $pair && $pair->match_status === 'SUGGESTED' && (int) $pair->match_score === 100);
    $check('dev submission and match history written', (int) DB::selectOne('SELECT COUNT(*) AS n FROM item_status_history WHERE item_id IN (?,?)', [$a['id'], $b['id']])->n >= 4);
    $check('dev notifications owned by their reporters', (int) DB::selectOne('SELECT COUNT(*) AS n FROM notifications WHERE (item_id=? AND user_id<>1) OR (item_id=? AND user_id<>2)', [$a['id'], $b['id']])->n === 0);
    $service->mutate(['user_id' => '1', 'role' => 'STUDENT'], Request::create('/api/items.php', 'POST', array_replace($data, ['action' => 'update', 'id' => $a['id'], 'title' => $data['title'].' updated'])));
    $service->mutate(['user_id' => '1', 'role' => 'STUDENT'], Request::create('/api/items.php', 'POST', ['action' => 'delete', 'id' => $a['id']]));
    $service->mutate(['user_id' => '2', 'role' => 'STUDENT'], Request::create('/api/items.php', 'POST', ['action' => 'delete', 'id' => $b['id']]));
    $check('dev eligible deletion removes new reports', ! DB::selectOne('SELECT item_id FROM items WHERE item_id IN (?,?)', [$a['id'], $b['id']]));
    AcademicSnapshot::restore($admin, $snapshot);
    $check('exact dev database schema counters and private media restored', AcademicSnapshot::capture($admin) === $before);
    VerificationReceipt::complete(count($checks), ['checks' => $checks, 'restore_verified' => true]);
    echo json_encode(['academic_dev_reporting_checks_passed' => count($checks)]);
} catch (Throwable $error) {
    fwrite(STDERR,get_class($error).': '.$error->getMessage());
    exit(1);
}
