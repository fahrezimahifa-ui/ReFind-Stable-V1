<?php

use App\Exceptions\ContractError;
use App\Foundation\WriteFailure;
use App\Services\ReportService;
use App\Support\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

require __DIR__.'/academic-bootstrap.php';
require __DIR__.'/AcademicSnapshot.php';
try {
    $admin = AcademicSnapshot::admin();
    $before = AcademicSnapshot::capture($admin);
    $snapshot = AcademicSnapshot::save($admin);
    $checks = [];
    $db = DB::connection();
    $check = function ($name, $ok) use (&$checks) {
        if (! $ok) {
            throw new RuntimeException('CONSISTENCY GATE FAILED: '.$name);
        }$checks[] = $name;
    };
    $request = function ($data, $files = []) {
        return Request::create('/api/items.php', 'POST', $data, [], $files);
    };
    $service = app(ReportService::class);
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
    $data = ['action' => 'create', 'type' => 'LOST', 'title' => 'Synthetic checkpoint report', 'description' => 'Small blue reusable case', 'category_id' => '1', 'location_id' => '35', 'date' => $today, 'time' => '', 'clue' => 'A hidden synthetic ownership detail'];
    $source = Workspace::storage().'/uploads/report-fixture.png';
    $im = imagecreatetruecolor(80, 50);
    imagepng($im, $source);
    $file = fn () => new UploadedFile($source, 'photo.png', 'image/png', null, true);
    $one = ['user_id' => '1', 'role' => 'STUDENT'];
    $two = ['user_id' => '2', 'role' => 'STUDENT'];
    $queries = 0;
    $db->listen(function () use (&$queries) {
        $queries++;
    });
    $created = $service->mutate($one, $request($data, ['photos' => [$file()], 'ownershipPhotos' => [$file()]]));
    $id = (int) $created['id'];
    $check('create ID/action contract', $id > 8 && $created['action'] === 'create');
    $row = (array) $db->selectOne('SELECT * FROM items WHERE item_id=?', [$id]);
    $check('unknown event time remains NULL', $row['event_time'] === null);
    $photos = $db->select('SELECT * FROM item_photos WHERE item_id=? ORDER BY photo_id', [$id]);
    $check('public and ownership media committed', count($photos) === 2 && is_file(Workspace::media().'/'.$photos[0]->file_path) && is_file(Workspace::media().'/'.$photos[1]->file_path));
    $old = $photos[0]->file_path;
    $oldPrivate = $photos[1]->file_path;
    $updated = $service->mutate($one, $request(array_replace($data, ['action' => 'update', 'id' => (string) $id, 'title' => 'Updated synthetic checkpoint report', 'keep_photo_ids' => '[]', 'keep_ownership_ids' => json_encode([(int) $photos[1]->photo_id])]), ['photos' => [$file()]]));
    $check('update keeps ID and replaces public photo', $updated['id'] === (string) $id && ! is_file(Workspace::media().'/'.$old) && is_file(Workspace::media().'/'.$oldPrivate));
    try {
        $service->mutate($two, $request(['action' => 'delete', 'id' => (string) $id]));
        throw new RuntimeException('SAFETY GATE FAILED: foreign delete');
    } catch (ContractError$e) {
        $check('foreign ownership rejected', $e->status === 403);
    }
    $newPath = $db->selectOne("SELECT file_path FROM item_photos WHERE item_id=? AND photo_kind='ITEM'", [$id])->file_path;
    try {
        $service->mutate($one, $request(array_replace($data, ['action' => 'update', 'id' => (string) $id, 'keep_photo_ids' => '[1]'])));
        throw new RuntimeException('SAFETY GATE FAILED: foreign retained photo');
    } catch (ContractError$e) {
        $check('foreign retained photo rejected with original status', $e->status === 403 && is_file(Workspace::media().'/'.$newPath));
    }
    $deleted = $service->mutate($one, $request(['action' => 'delete', 'id' => (string) $id]));
    $check('eligible delete cascades photo metadata', ! $db->selectOne('SELECT item_id FROM items WHERE item_id=?', [$id]) && ! $db->selectOne('SELECT photo_id FROM item_photos WHERE item_id=?', [$id]));
    $check('deleted media removed after commit', ! is_file(Workspace::media().'/'.$newPath) && ! is_file(Workspace::media().'/'.$oldPrivate));
    // Failure after report/photo SQL must restore the complete business data and generated files.
    $state = AcademicSnapshot::capture($admin);
    $failedOp = null;
    try {
        $service->mutate($one, $request($data, ['photos' => [$file()]]), function ($point) {
            if ($point === 'after_promote') {
                throw new RuntimeException('Injected interrupted report promotion.');
            }
        });
        throw new RuntimeException('Expected injected failure');
    } catch (WriteFailure$e) {
        $failedOp = $e->operationId;
        $check('interrupted promotion confirmed rollback', ! $e->uncertain);
    }
    $after = AcademicSnapshot::capture($admin);
    foreach ($state['tables'] as $table => $x) {
        $check('rollback rows '.$table, $x['rows'] === $after['tables'][$table]['rows']);
    }$check('rollback authoritative media unchanged', $state['media'] === $after['media']);
    AcademicSnapshot::restore($admin, $snapshot);
    $check('exact fixture and counter restoration', AcademicSnapshot::capture($admin) === $before);
    $out = ['passed' => count($checks), 'checks' => $checks, 'query_events' => $queries, 'checkpoint' => config('refind.report_effects') ? 'matching' : 'crud', 'restored' => true];
    file_put_contents(Workspace::ROOT.'/evidence/phase5/service-results.json', json_encode($out, JSON_PRETTY_PRINT));
    echo json_encode(['report_service_checks_passed' => count($checks), 'restored' => true, 'query_events' => $queries]);
} catch (Throwable$e) {
    fwrite(STDERR,get_class($e).': '.$e->getMessage());
    exit(1);
}
VerificationReceipt::complete(count($checks), $out);
