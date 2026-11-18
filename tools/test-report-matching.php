<?php

use App\Foundation\MediaJournal;
use App\Foundation\WriteCoordinator;
use App\Foundation\WriteFailure;
use App\Services\ReportService;
use App\Support\Workspace;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

if ($argc !== 1) {
    fwrite(STDERR, "Usage: php tools/test-report-matching.php\n");
    exit(1);
}

require __DIR__.'/academic-bootstrap.php';
require __DIR__.'/AcademicSnapshot.php';
require __DIR__.'/NativeMatchingOracle.php';
require __DIR__.'/matching-diagnostics.php';
$diagnosticStage = 'initialization';
$diagnosticQueries = [];
$diagnosticContext = [];
$startOperation = function (string $stage, array $context = []) use (&$diagnosticStage, &$diagnosticContext, &$diagnosticQueries): void {
    $diagnosticStage = $stage;
    $diagnosticContext = $context;
    $diagnosticQueries = [];
};
$recordCoordinatorStage = function (string $point) use (&$diagnosticContext): void {
    $diagnosticContext['coordinator_stage'] = $point;
};
try {
    if (Workspace::profile()['profile'] !== 'academic-test' || ! config('refind.report_effects')) {
        throw new RuntimeException('Matching acceptance requires synthetic B-test effects.');
    }
    NativeMatchingOracle::boot();
    $admin = AcademicSnapshot::admin();
    NativeMatchingOracle::database($admin);
    $initial = AcademicSnapshot::capture($admin);
    $original = AcademicSnapshot::save($admin);
    $checks = [];
    $check = function (string $name, bool $ok) use (&$checks): void {
        if (! $ok) {
            throw new RuntimeException('MATCHING CONSISTENCY GATE FAILED: '.$name);
        }
        $checks[] = $name;
    };
    // Return only the operation ID. A retained WriteFailure keeps the original MediaOperation, and its
    // manifest lock, alive through trace arguments, its previous Throwable and the captured work closure.
    // The exception is released when this helper returns, as in test-write-foundation.php expectedFailure().
    $uncertainOperation = function (string $label, callable $write) use ($check): string {
        try {
            $write();
        } catch (WriteFailure $error) {
            $check($label, $error->uncertain);

            return $error->operationId;
        }
        throw new RuntimeException('Expected uncertain report outcome: '.$label);
    };
    $stagedLeftovers = fn (string $operationId): array => glob(Workspace::storage().'/operations/staging/'.$operationId.'-*') ?: [];
    $db = DB::connection();
    $service = app(ReportService::class);
    $db->beforeExecuting(function (string $sql, array $bindings) use (&$diagnosticQueries): void {
        $diagnosticQueries[] = matching_query_context($sql, $bindings);
        if (count($diagnosticQueries) > 20) {
            array_shift($diagnosticQueries);
        }
    });
    $request = fn ($data, $files = []) => Request::create('/api/items.php', 'POST', $data, [], $files);
    $actor = fn ($id) => ['user_id' => (string) $id, 'role' => 'STUDENT'];
    $primaryCategory = 1;
    $comparisonCategory = 3;
    $activeCategories = array_map('intval', $admin->query('SELECT category_id FROM categories WHERE is_active=1')->fetchAll(PDO::FETCH_COLUMN));
    $check('fixture categories are distinct and active', $primaryCategory !== $comparisonCategory && ! array_diff([$primaryCategory, $comparisonCategory], $activeCategories));
    $base = ['action' => 'update', 'id' => '100', 'title' => 'Amber cobalt copper glass crystal', 'description' => 'Distinctive public tokens.', 'category_id' => (string) $primaryCategory, 'location_id' => '35', 'date' => '2026-01-15', 'time' => '', 'clue' => 'PRIVATE_SENTINEL_never_in_match_or_notifications'];
    // Quiescent, synthetic B-test fixture replacement; original exact data/media snapshot retained.
    foreach (['claim_photos', 'handovers', 'claims', 'item_matches', 'item_status_history', 'notifications', 'item_photos', 'items'] as $table) {
        $admin->exec('DELETE FROM '.$table);
    }
    $statuses = $admin->query('SELECT status_code,status_id FROM item_statuses')->fetchAll(PDO::FETCH_KEY_PAIR);
    $insert = $admin->prepare('INSERT INTO items(item_id,reporter_user_id,category_id,location_id,status_id,item_type,title,description,event_date,verification_clue) VALUES(?,?,?,?,?,?,?,?,?,?)');
    $fixtures = [
        [100, 1, 1, 35, 'OPEN', 'LOST', '2026-01-15', true],
        [200, 2, 1, 35, 'OPEN', 'FOUND', '2026-01-15', true],
        [300, 1, 1, 35, 'OPEN', 'FOUND', '2026-01-15', true],
        [400, 2, $comparisonCategory, 35, 'OPEN', 'FOUND', '2026-01-15', true],
        [500, 2, 1, 35, 'OPEN', 'FOUND', '2026-01-13', true],
        [600, 2, 1, 35, 'RESOLVED', 'FOUND', '2026-01-15', true],
        [700, 2, 1, 35, 'UNDER_REVIEW', 'FOUND', '2026-01-15', true],
        [800, 2, 1, 35, 'VERIFICATION', 'FOUND', '2026-01-15', true],
        [900, 2, 1, 35, 'OPEN', 'FOUND', '2026-01-15', true],
        [901, 1, 1, 35, 'OPEN', 'LOST', '2026-01-15', true],
        [1000, 2, 1, 36, 'OPEN', 'FOUND', '2026-01-30', false],
        [1100, 2, 1, 35, 'OPEN', 'FOUND', '2026-01-29', false],
        [1200, 2, 1, 35, 'OPEN', 'FOUND', '2026-01-30', false],
        [1300, 2, 1, 35, 'UNCLAIMED_CLOSED', 'FOUND', '2026-01-15', true],
        [1400, 2, 1, 35, 'HANDOVER', 'FOUND', '2026-01-15', true],
        [1500, 2, 1, 35, 'OPEN', 'FOUND', '2026-01-15', true],
    ];
    foreach ($fixtures as [$id,$owner,$category,$location,$status,$type,$date,$text]) {
        $insert->execute([$id, $owner, $category, $location, $statuses[$status], $type, $text ? $base['title'] : 'Unrelated vocabulary', $text ? $base['description'] : '', $date, $base['clue']]);
    }
    $admin->exec("INSERT INTO item_matches(lost_item_id,found_item_id,match_score,match_status,confirmed_by_user_id,confirmed_at) VALUES(901,900,100,'CONFIRMED',3,UTC_TIMESTAMP())");
    $admin->exec("INSERT INTO item_matches(lost_item_id,found_item_id,match_score,match_status) VALUES(100,1500,12,'REJECTED')");
    $admin->exec('ALTER TABLE items AUTO_INCREMENT=1501');
    $controlled = AcademicSnapshot::save($admin);
    $protectedPairs = fn (): array => $admin->query("SELECT * FROM item_matches WHERE match_status IN ('CONFIRMED','REJECTED') ORDER BY match_id")->fetchAll(PDO::FETCH_ASSOC);
    $initialProtectedPairs = $protectedPairs();
    $canonical = function () use ($admin): array {
        return [
            'items' => $admin->query('SELECT item_id,reporter_user_id,category_id,location_id,status_id,item_type,title,description,event_date,resolved_at FROM items ORDER BY item_id')->fetchAll(PDO::FETCH_ASSOC),
            'matches' => $admin->query('SELECT lost_item_id,found_item_id,match_score,match_status,confirmed_by_user_id FROM item_matches ORDER BY lost_item_id,found_item_id')->fetchAll(PDO::FETCH_ASSOC),
            'history' => $admin->query('SELECT item_id,status_id,changed_by_user_id,note FROM item_status_history ORDER BY history_id')->fetchAll(PDO::FETCH_ASSOC),
            'notifications' => $admin->query('SELECT user_id,notification_type,title,message,item_id,claim_id,is_read FROM notifications ORDER BY notification_id')->fetchAll(PDO::FETCH_ASSOC),
        ];
    };
    $steps = [
        [1, $base], [1, $base], [1, array_replace($base, ['category_id' => (string) $comparisonCategory])], [1, $base],
        [1, ['action' => 'delete', 'id' => '100']],
        [1, array_replace($base, ['action' => 'create', 'type' => 'LOST'])],
        [2, array_replace($base, ['action' => 'create', 'type' => 'FOUND'])],
        [2, array_replace($base, ['action' => 'update', 'id' => '1502', 'type' => 'FOUND', 'category_id' => (string) $comparisonCategory])],
        [2, ['action' => 'delete', 'id' => '1502']],
        [2, array_replace($base, ['id' => '200', 'type' => 'FOUND', 'title' => 'Amber denim wool pendant clasp', 'description' => 'Striped fabric sleeve.'])],
    ];
    $rescoreStep = array_key_last($steps);
    $rescoredPair = fn (array $rows): array => array_values(array_filter($rows, fn ($match) => (int) $match['lost_item_id'] === 1501 && (int) $match['found_item_id'] === 200));
    // Reference replay calls only extracted native functions, through the guarded B-test PDO adapter.
    $reference = function (int $owner, array $data) use ($admin, $statuses): void {
        $action = $data['action'];
        $id = $action === 'create' ? null : (int) $data['id'];
        $old = $id ? $admin->query('SELECT * FROM items WHERE item_id='.$id)->fetch(PDO::FETCH_ASSOC) : null;
        $type = $old['item_type'] ?? $data['type'];
        $lock = 'refind-matching-'.Workspace::profile()['database'];
        $s = $admin->prepare('SELECT GET_LOCK(?,5)');
        $s->execute([$lock]);
        if ((int) $s->fetchColumn() !== 1) {
            throw new RuntimeException('Oracle advisory lock unavailable.');
        }
        try {
            $admin->beginTransaction();
            $s = $admin->prepare('SELECT item_id FROM items WHERE item_type<>?');
            $s->execute([$type]);
            $ids = array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
            if ($id) {
                $ids[] = $id;
            }
            $locked = \ReFindNativeOracle\lock_items($ids);
            if ($action === 'delete') {
                $item = $locked[$id];
                $admin->exec('DELETE FROM items WHERE item_id='.$id);
                unset($locked[$id]);
                NativeMatchingOracle::deleted($item, $locked, $owner);
            } else {
                $values = [$data['title'], $data['description'], (int) $data['category_id'], (int) $data['location_id'], $data['date'], $data['clue']];
                if ($action === 'create') {
                    $s = $admin->prepare('INSERT INTO items(title,description,category_id,location_id,event_date,verification_clue,reporter_user_id,status_id,item_type) VALUES(?,?,?,?,?,?,?,?,?)');
                    $s->execute([...$values, $owner, $statuses['OPEN'], $type]);
                    $id = (int) $admin->lastInsertId();
                } else {
                    $s = $admin->prepare('UPDATE items SET title=?,description=?,category_id=?,location_id=?,event_date=?,verification_clue=?,location_floor_id=NULL WHERE item_id=?');
                    $s->execute([...$values, $id]);
                }
                $item = $admin->query('SELECT i.*,s.status_code FROM items i JOIN item_statuses s ON s.status_id=i.status_id WHERE item_id='.$id)->fetch(PDO::FETCH_ASSOC);
                $locked[$id] = $item;
                NativeMatchingOracle::saved($item, $locked, $owner, $action === 'create');
            }
            $admin->commit();
        } catch (Throwable $error) {
            if ($admin->inTransaction()) {
                $admin->rollBack();
            }throw $error;
        } finally {
            $s = $admin->prepare('SELECT RELEASE_LOCK(?)');
            $s->execute([$lock]);
        }
    };
    $expected = [];
    foreach ($steps as $index => [$owner,$data]) {
        $startOperation('native_reference_replay_'.($index + 1).'_'.$data['action']);
        $reference($owner, $data);
        $expected[] = $canonical();
    }
    $startOperation('restore_controlled_before_laravel_replay');
    AcademicSnapshot::restore($admin, $controlled);
    foreach ($steps as $index => [$owner,$data]) {
        $startOperation('laravel_replay_'.($index + 1).'_'.$data['action']);
        if ($index === $rescoreStep) {
            $beforeRescore = $canonical();
            $beforeRescoreProtectedPairs = $protectedPairs();
        }
        $service->mutate($actor($owner), $request($data), $recordCoordinatorStage);
        $actual = $canonical();
        foreach (['items', 'matches', 'history', 'notifications'] as $kind) {
            $check('native replay parity step '.($index + 1).' '.$kind, $actual[$kind] === $expected[$index][$kind]);
        }
        if ($index === 0) {
            $suggested = array_map('intval', $admin->query("SELECT found_item_id FROM item_matches WHERE lost_item_id=100 AND match_status='SUGGESTED' ORDER BY found_item_id")->fetchAll(PDO::FETCH_COLUMN));
            $check('eligible OPEN UNDER_REVIEW VERIFICATION and exact threshold', $suggested === [200, 700, 800, 1100]);
            $check('threshold equality score 55', (int) $admin->query('SELECT match_score FROM item_matches WHERE lost_item_id=100 AND found_item_id=1100')->fetchColumn() === 55);
            $check('confirmed pair preserved', (int) $admin->query("SELECT COUNT(*) FROM item_matches WHERE lost_item_id=901 AND found_item_id=900 AND match_status='CONFIRMED' AND match_score=100")->fetchColumn() === 1);
            $check('rejected pair remains rejected', (int) $admin->query("SELECT COUNT(*) FROM item_matches WHERE lost_item_id=100 AND found_item_id=1500 AND match_status='REJECTED' AND match_score=12")->fetchColumn() === 1);
            $check('protected pair identities and confirmation metadata survive refresh', $protectedPairs() === $initialProtectedPairs);
        }
        if ($index === 1) {
            $check('unchanged pairing does not repeat notification', count($actual['notifications']) === count($expected[0]['notifications']));
        }
        if ($index === 4) {
            $check('deletion retains prior alerts with nullable item and sends owner-only deletion', count(array_filter($actual['notifications'], fn ($n) => $n['notification_type'] === 'REPORT_DELETED' && (int) $n['user_id'] === 1 && $n['item_id'] === null)) === 1);
        }
        if ($index === $rescoreStep) {
            $prior = $rescoredPair($beforeRescore['matches']);
            $updated = $rescoredPair($actual['matches']);
            $check('same-pair rescore starts with one suggested score 100', count($prior) === 1 && $prior[0]['match_status'] === 'SUGGESTED' && (float) $prior[0]['match_score'] === 100.0);
            $check('same-pair rescore leaves one suggested score 72', count($updated) === 1 && $updated[0]['match_status'] === 'SUGGESTED' && (float) $updated[0]['match_score'] === 72.0);
            $pairStatuses = array_map('intval', $admin->query('SELECT status_id FROM items WHERE item_id IN (200,1501) ORDER BY item_id')->fetchAll(PDO::FETCH_COLUMN));
            $potentialMatch = (int) $statuses['POTENTIAL_MATCH'];
            $check('rescore keeps both reports in potential match', $pairStatuses === [$potentialMatch, $potentialMatch]);
            $check('rescore sends no repeat notification', $actual['notifications'] === $beforeRescore['notifications']);
            $history = array_slice($actual['history'], count($beforeRescore['history']));
            $check('rescore keeps the report update history', count($history) === 1 && (int) $history[0]['item_id'] === 200 && $history[0]['note'] === 'Report details updated by the reporter.');
            $check('rescore leaves protected pairs byte-for-byte unchanged', $protectedPairs() === $beforeRescoreProtectedPairs);
        }
        $check('no private clue in notifications step '.($index + 1), ! str_contains(json_encode($actual['notifications']), 'PRIVATE_SENTINEL'));
    }
    // Failure injection includes the actual INSERT event, not a mocked effects service.
    $faultPrefix = null;
    $faultHits = 0;
    app('events')->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$faultPrefix, &$faultHits): void {
        if ($faultPrefix !== null && str_starts_with(strtolower($event->sql), $faultPrefix)) {
            $faultHits++;
            throw new RuntimeException('Injected matching SQL observer failure.');
        }
    });
    $source = Workspace::storage().'/uploads/matching-fixture.png';
    $im = imagecreatetruecolor(50, 40);
    imagepng($im, $source);
    imagedestroy($im);
    $file = fn () => new UploadedFile($source, 'photo.png', 'image/png', null, true);
    $faultCases = [['create-after-effects', 'create', 'after_work', null], ['edit-after-promotion', 'update', 'after_promote', null], ['delete-after-effects', 'delete', 'after_work', null], ['notification-insert', 'create', null, 'insert into notifications'], ['suggestion-insert', 'update', null, 'insert into item_matches'], ['history-insert', 'update', null, 'insert into item_status_history']];
    foreach ($faultCases as [$label,$action,$point,$prefix]) {
        $startOperation('restore_before_fault_case_'.$label);
        AcademicSnapshot::restore($admin, $controlled);
        if ($action === 'delete') {
            $startOperation('prepare_fault_case_'.$label);
            $service->mutate($actor(1), $request($base), $recordCoordinatorStage);
        }
        $startOperation('fault_case_'.$label);
        $before = AcademicSnapshot::capture($admin);
        $faultPrefix = $prefix;
        $faultHits = 0;
        $data = $action === 'delete' ? ['action' => 'delete', 'id' => '100'] : array_replace($base, ['action' => $action, 'type' => 'LOST']);
        $files = $action === 'delete' ? [] : ['photos' => [$file()]];
        try {
            $service->mutate($actor(1), $request($data, $files), function ($current) use ($point, $recordCoordinatorStage): void {
                $recordCoordinatorStage($current);
                if ($point !== null && $current === $point) {
                    throw new RuntimeException('Injected effects rollback.');
                }
            });
            throw new RuntimeException('Failure injection unexpectedly succeeded.');
        } catch (WriteFailure $error) {
            $check($label.' confirmed rollback', ! $error->uncertain);
        } finally {
            $faultPrefix = null;
        }
        $after = AcademicSnapshot::capture($admin);
        foreach ($before['tables'] as $table => $value) {
            $check($label.' atomic rows '.$table, $value['rows'] === $after['tables'][$table]['rows']);
        }
        $check($label.' authoritative media compensated', $before['media'] === $after['media']);
        if ($prefix !== null) {
            $check($label.' SQL not retried', $faultHits === 1);
        }
    }
    $startOperation('restore_before_commit_acknowledgement_loss');
    AcademicSnapshot::restore($admin, $controlled);
    $startOperation('commit_acknowledgement_loss');
    $priorItems = array_map('intval', $admin->query('SELECT item_id FROM items')->fetchAll(PDO::FETCH_COLUMN));
    $operationId = $uncertainOperation('report commit acknowledgement loss remains uncertain', fn () => $service->mutate($actor(1), $request(array_replace($base, ['action' => 'create', 'type' => 'LOST']), ['photos' => [$file()]]), $recordCoordinatorStage, function ($connection): void {
        $connection->commit();
        throw new RuntimeException('Lost report commit acknowledgement.');
    }));
    $op = app(MediaJournal::class)->load($operationId);
    $check('acknowledgement loss keeps an unknown outcome after releasing the advisory lock', $op->snapshot()['state'] === 'outcome_unknown' && in_array('advisory_released', $op->snapshot()['events'], true));
    $check('committed report effects reconcile together', app(WriteCoordinator::class)->reconcile($op) === 'committed');
    $check('committed report operation completes without staged leftovers', $op->snapshot()['state'] === 'complete' && $stagedLeftovers($operationId) === []);
    unset($op);
    $newItems = array_values(array_diff(array_map('intval', $admin->query('SELECT item_id FROM items')->fetchAll(PDO::FETCH_COLUMN)), $priorItems));
    $check('acknowledgement-loss commit created exactly one report', count($newItems) === 1);
    $check('committed report notification not duplicated', (int) $admin->query("SELECT COUNT(*) FROM notifications WHERE notification_type='REPORT_SUBMITTED'")->fetchColumn() === 1);
    $s = $admin->prepare("SELECT COUNT(*) FROM item_status_history WHERE item_id=? AND note='Report submitted.'");
    $s->execute([$newItems[0]]);
    $check('committed report history not duplicated', (int) $s->fetchColumn() === 1);
    $s = $admin->prepare("SELECT file_path FROM item_photos WHERE item_id=? AND photo_kind='ITEM'");
    $s->execute([$newItems[0]]);
    $photos = $s->fetchAll(PDO::FETCH_COLUMN);
    $check('committed report photo retained in authoritative media', count($photos) === 1 && is_file(Workspace::media().'/'.$photos[0]));
    $startOperation('restore_before_connection_loss');
    AcademicSnapshot::restore($admin, $controlled);
    $startOperation('connection_loss');
    $before = AcademicSnapshot::capture($admin);
    $operationId = $uncertainOperation('actual report connection loss is uncertain and not retried', fn () => $service->mutate($actor(1), $request(array_replace($base, ['action' => 'create', 'type' => 'LOST']), ['photos' => [$file()]]), function ($point) use ($admin, $db, $recordCoordinatorStage): void {
        $recordCoordinatorStage($point);
        if ($point === 'after_work') {
            $admin->exec('KILL CONNECTION '.$db->pinnedId());
            $db->select('SELECT 1');
        }
    }));
    $op = app(MediaJournal::class)->load($operationId);
    $check('lost connection was not silently reconnected to release the advisory lock', in_array('release_connection_lost', $op->snapshot()['events'], true));
    $check('lost report connection reconciles all effects to rollback', app(WriteCoordinator::class)->reconcile($op) === 'rolled_back');
    $check('rolled-back report operation completes without staged leftovers', $op->snapshot()['state'] === 'complete' && $stagedLeftovers($operationId) === []);
    unset($op);
    $after = AcademicSnapshot::capture($admin);
    foreach ($before['tables'] as $table => $value) {
        $check('connection loss atomic rows '.$table, $value['rows'] === $after['tables'][$table]['rows']);
    }
    $check('connection loss media compensation', $before['media'] === $after['media']);
    $startOperation('restore_original_fixture');
    AcademicSnapshot::restore($admin, $original);
    $check('exact B-test data schema counters and media restored', AcademicSnapshot::capture($admin) === $initial);
    $out = ['passed' => count($checks), 'checks' => $checks, 'native_reference' => '47eea8648d2d47faa895d855fe6da53e6b1a3087', 'repository_sha256' => NativeMatchingOracle::$repositoryHash, 'reports_sha256' => NativeMatchingOracle::$reportsHash, 'native_bootstrap_executed' => false, 'native_database_contacted' => false, 'oracle_database' => 'refind_laravel_b_test', 'restore_verified' => true];
    VerificationReceipt::complete(count($checks), $out);
    echo json_encode(['matching_checks_passed' => count($checks), 'restore_verified' => true]);
} catch (Throwable $error) {
    $diagnostic = matching_capture_failure($error, $diagnosticContext + ['stage' => $diagnosticStage, 'recent_queries' => $diagnosticQueries, 'original_snapshot' => $original ?? null, 'controlled_snapshot' => $controlled ?? null, 'completed_checks' => count($checks ?? [])]);
    fwrite(STDERR, get_class($error).': private diagnostic '.basename($diagnostic).PHP_EOL);
    exit(1);
}
