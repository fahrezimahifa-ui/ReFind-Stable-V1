<?php

use App\Foundation\FileOperations;
use App\Foundation\MediaJournal;
use App\Foundation\UploadStager;
use App\Foundation\WriteCoordinator;
use App\Foundation\WriteFailure;
use App\Support\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

require __DIR__.'/academic-bootstrap.php';
require __DIR__.'/AcademicSnapshot.php';
if (Workspace::profile()['profile'] !== 'academic-test') {
    throw new RuntimeException('B test required.');
}
$checks = [];
$samples = [];
function check(string $label, bool $ok): void
{
    global $checks;
    if (! $ok) {
        throw new RuntimeException('CHECK FAILED: '.$label);
    }$checks[] = $label;
}
function denied(string $label, callable $f): void
{
    try {
        $f();
    } catch (Throwable $e) {
        check($label, true);

        return;
    }throw new RuntimeException('SAFETY GATE FAILED: '.$label);
}
function expectedFailure(string $label, callable $f, bool $uncertain = false, ?int $status = null): WriteFailure
{
    try {
        $f();
    } catch (WriteFailure $e) {
        check($label.' [cause '.get_class($e->getPrevious()).', actual status '.$e->status.', uncertain '.($e->uncertain ? 'true' : 'false').']', $e->uncertain === $uncertain && ($status === null || $e->status === $status));

        return $e;
    }throw new RuntimeException('Expected write failure: '.$label);
}
$db = DB::connection();
$coordinator = new WriteCoordinator($db);
$journal = new MediaJournal;
$admin = AcademicSnapshot::admin();
$initial = AcademicSnapshot::capture($admin);
$snapshot = AcademicSnapshot::save($admin);
function secondConnection(): PDO
{
    $p = Workspace::profile();
    $pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname='.$p['database'].';charset=utf8mb4', $p['username'], $p['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);

    return $pdo;
}
function probeInsert($context, int $id, ?string $path = null): void
{
    $context->connection->insert('INSERT INTO _foundation_probe(id,operation_id,value,media_path)VALUES(?,?,7,?)', [$id, $context->operationId, $path]);
}
try {
    $grants = $db->select('SHOW GRANTS');
    $all = implode('\n', array_map(fn ($x) => implode(' ', (array) $x), $grants));
    check('no global privilege escalation', ! preg_match('/GRANT (?:ALL|FILE|PROCESS|SUPER|CREATE|DROP|ALTER|SHUTDOWN)/i', $all) && ! str_contains($all, 'WITH GRANT OPTION'));
    foreach (['refind_laravel_dev', 'refind_laravel_test', 'refind_laravel_b_dev', 'refind_db_0_2_0', 'refind_db_0_2_0_test', 'mysql'] as $schema) {
        try {
            $db->select('SELECT * FROM `'.$schema.'`.users LIMIT 0');
            throw new RuntimeException('SAFETY GATE FAILED: cross-schema read');
        } catch (QueryException $e) {
            check('denied schema '.$schema, in_array((int) $e->errorInfo[1], [1044, 1142], true));
        }
    }
    foreach (['users', 'items', 'item_photos', 'claims', 'claim_photos', 'handovers', 'item_matches', 'item_status_history', 'notifications', 'categories', '_workspace_owner'] as $table) {
        if(in_array($table,['items','item_photos'],true)) continue; // Newly granted report CRUD is checked independently.
        if(config('refind.report_effects') && in_array($table,['item_matches','item_status_history','notifications'],true)) continue;

        foreach (['UPDATE `'.$table.'` SET '.($table === 'users' ? 'full_name=full_name' : ($table === 'items' ? 'title=title' : ($table === 'categories' ? 'category_name=category_name' : ($table === '_workspace_owner' ? 'workspace_id=workspace_id' : ($table === 'notifications' ? 'is_read=is_read' : ($table === 'item_photos' ? 'is_primary=is_primary' : ($table === 'claims' ? 'claim_status=claim_status' : ($table === 'claim_photos' ? 'file_path=file_path' : ($table === 'handovers' ? 'handover_status=handover_status' : ($table === 'item_matches' ? 'match_score=match_score' : 'note=note')))))))))).' WHERE 1=0', 'DELETE FROM `'.$table.'` WHERE 1=0', 'INSERT INTO `'.$table.'` SELECT * FROM `'.$table.'` WHERE 1=0'] as $sql) {
            try {
                $db->statement($sql);
                throw new RuntimeException('SAFETY GATE FAILED: business write permission');
            } catch (QueryException $e) {
                check('business DML denied '.$table.' '.strtok($sql, ' '), in_array((int) $e->errorInfo[1],[1142,1143],true));
            }
        }
    }
    foreach (['UPDATE _write_operations SET manifest_sha256=manifest_sha256 WHERE 1=0', 'DELETE FROM _write_operations WHERE 1=0'] as $sql) {
        try {
            $db->statement($sql);
            throw new RuntimeException('SAFETY GATE FAILED: ledger mutable');
        } catch (QueryException $e) {
            check('append-only ledger '.strtok($sql, ' '), in_array((int) $e->errorInfo[1],[1142,1143],true));
        }
    }
    $db->insert('INSERT INTO _foundation_probe(id,operation_id,value)VALUES(10,?,0),(20,?,0)', [bin2hex(random_bytes(16)), bin2hex(random_bytes(16))]);
    $db->update('UPDATE _foundation_probe SET value=1 WHERE id=10');
    check('probe INSERT UPDATE allowed', (int) $db->selectOne('SELECT value FROM _foundation_probe WHERE id=10')->value === 1);
    $db->insert('INSERT INTO _foundation_probe(id,operation_id)VALUES(30,?)', [bin2hex(random_bytes(16))]);
    $db->delete('DELETE FROM _foundation_probe WHERE id=30');
    check('probe DELETE allowed', ! $db->selectOne('SELECT id FROM _foundation_probe WHERE id=30'));
    $source = Workspace::storage().'/uploads/synthetic.png';
    $im = imagecreatetruecolor(30, 20);
    imagepng($im, $source);
    $upload = new UploadedFile($source, 'renamed.php', 'text/plain', null, true);
    $stager = new UploadStager;
    $op = $journal->create();
    $path = $stager->stage($upload, 'items', $op);
    $runs = (object) ['count' => 0];
    $result = $coordinator->run($op, function ($c) use (&$runs, $path) {
        $runs->count++;
        $c->lockProbeRows([20, 10, 20]);
        check('ascending unique parent locks', $c->lockedIds === [10, 20]);
        probeInsert($c, 100, $path);

        return 'saved';
    });
    check('one work invocation and committed result', $result === 'saved' && $runs->count === 1);
    check('commit ledger checksum', $db->selectOne('SELECT manifest_sha256 FROM _write_operations WHERE operation_id=?', [$op->id])->manifest_sha256 === $op->planHash());
    check('committed media exists', is_file(Workspace::media().'/'.$path));
    check('successful manifest complete', $op->snapshot()['state'] === 'complete');
    check('explicit advisory release recorded', in_array('advisory_released', $op->snapshot()['events'], true));
    check('connection pin cleared', $db->pinnedId() === null && $db->transactionLevel() === 0);
    $op = $journal->create();
    $bad = $stager->stage($upload, 'items', $op);
    $runs = (object) ['count' => 0];
    expectedFailure('SQL failure rolls back once', fn () => $coordinator->run($op, function ($c) use ($bad, &$runs) {
        $runs->count++;
        probeInsert($c, 101, $bad);
        $c->connection->statement('INSERT INTO _foundation_missing VALUES(1)');
    }));
    check('failed SQL not retried', $runs->count === 1);
    check('failed SQL row absent', ! $db->selectOne('SELECT id FROM _foundation_probe WHERE id=101'));
    check('rollback staged and promoted files absent', ! is_file(Workspace::media().'/'.$bad) && ! is_file(MediaJournal::scoped($op->snapshot()['new'][0]['staged'])));
    $op = $journal->create();
    expectedFailure('unique conflict maps 409', fn () => $coordinator->run($op, fn ($c) => probeInsert($c, 100)), false, 409);
    $op = $journal->create();
    $failure = expectedFailure('reconnect refused', fn () => $coordinator->run($op, fn ($c) => $c->connection->reconnect()));
    check('reconnect failed for pinned-connection reason', str_contains($failure->getPrevious()->getMessage(), 'Reconnection is forbidden'));
    $op = $journal->create();
    $failure = expectedFailure('PDO replacement refused', fn () => $coordinator->run($op, fn ($c) => $c->connection->setPdo(null)));
    check('replacement failed for pinned-connection reason', str_contains($failure->getPrevious()->getMessage(), 'Replacing a pinned'));
    $op = $journal->create();
    $failure = expectedFailure('nested transaction unit refused', fn () => $coordinator->run($op, fn ($c) => $c->connection->pin()));
    check('nested unit rejected explicitly', str_contains($failure->getPrevious()->getMessage(), 'Nested write'));
    $op = $journal->create();
    expectedFailure('backward lock order refused', fn () => $coordinator->run($op, function ($c) {
        $c->lockProbeRows([20]);
        $c->lockProbeRows([10]);
    }));
    foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
        $op = $journal->create();
        $failure = expectedFailure('callback cannot '.$method, fn () => $coordinator->run($op, fn ($c) => $c->connection->$method()));
        check('coordinator owns '.$method, str_contains($failure->getPrevious()->getMessage(), 'Transaction control belongs'));
    }
    $op = $journal->create();
    $path = $stager->stage($upload, 'items', $op);
    expectedFailure('real connection loss during work remains non-retryable', fn () => $coordinator->run($op, function ($c) use ($admin, $path) {
        probeInsert($c, 109, $path);
        $admin->exec('KILL CONNECTION '.(int) $c->connection->pinnedId());
        $c->connection->selectOne('SELECT 1');
    }), true, 500);
    $id = $op->id;
    unset($op);
    $op = $journal->load($id);
    check('lost work connection reconciles to rollback', $coordinator->reconcile($op) === 'rolled_back' && ! $db->selectOne('SELECT id FROM _foundation_probe WHERE id=109'));
    $name = 'refind-matching-'.Workspace::profile()['database'];
    $other = secondConnection();
    $s = $other->prepare('SELECT GET_LOCK(?,0)');
    $s->execute([$name]);
    check('other connection acquired advisory lock', (int) $s->fetchColumn() === 1);
    $op = $journal->create();
    $runs = (object) ['count' => 0];
    expectedFailure('advisory conflict maps 409', fn () => $coordinator->run($op, function ($c) use (&$runs) {
        $runs->count++;
    }, null, null, 0), false, 409);
    check('busy unit does not run work', $runs->count === 0);
    $s = $other->prepare('SELECT RELEASE_LOCK(?)');
    $s->execute([$name]);
    check('foreign lock not released by coordinator', (int) $s->fetchColumn() === 1);
    $other->beginTransaction();
    $other->query('SELECT * FROM _foundation_probe WHERE id=10 FOR UPDATE');
    $op = $journal->create();
    $runs = (object) ['count' => 0];
    $start = microtime(true);
    expectedFailure('row timeout maps 409', fn () => $coordinator->run($op, function ($c) use (&$runs) {
        $runs->count++;
        $c->lockProbeRows([10]);
    }), false, 409);
    $wait = microtime(true) - $start;
    $other->rollBack();
    check('bounded row wait and no retry', $wait >= 4.5 && $wait < 9 && $runs->count === 1);
    $samples['row_lock_wait_seconds'] = round($wait, 3);
    $brokenFiles = new class extends FileOperations
    {
        public function move(string $a, string $b): void
        {
            throw new RuntimeException('Injected promotion failure.');
        }
    };
    $op = (new MediaJournal($brokenFiles))->create();
    $bad = $stager->stage($upload, 'items', $op);
    expectedFailure('promotion failure rolls back database', fn () => $coordinator->run($op, fn ($c) => probeInsert($c, 102, $bad)));
    check('promotion-failed row and file absent', ! $db->selectOne('SELECT id FROM _foundation_probe WHERE id=102') && ! is_file(Workspace::media().'/'.$bad));
    $brokenStage = new class extends FileOperations
    {
        public function writeExclusive(string $p, string $bytes): void
        {
            if (str_contains($p, '/operations/staging/')) {
                throw new RuntimeException('Injected staging failure.');
            }parent::writeExclusive($p, $bytes);
        }
    };
    $op = (new MediaJournal($brokenStage))->create();
    denied('staging filesystem failure rejected', fn () => $stager->stage($upload, 'items', $op));
    $id = $op->id;
    unset($op);
    $op = $journal->load($id);
    check('failed stage can reconcile', $coordinator->reconcile($op) === 'rolled_back' && $op->snapshot()['state'] === 'complete');
    $old = 'items/'.bin2hex(random_bytes(16)).'.png';
    copy($source, Workspace::media().'/'.$old);
    $db->insert('INSERT INTO _foundation_probe(id,operation_id,media_path)VALUES(105,?,?)', [bin2hex(random_bytes(16)), $old]);
    $brokenDelete = new class($old) extends FileOperations
    {
        public function __construct(private string $old) {}

        public function remove(string $path): void
        {
            if (str_ends_with($path, $this->old)) {
                throw new RuntimeException('Injected deletion failure.');
            }parent::remove($path);
        }
    };
    $op = (new MediaJournal($brokenDelete))->create();
    $path = $stager->stage($upload, 'items', $op);
    $op->removeAfterCommit($old);
    $coordinator->run($op, fn ($c) => $c->connection->update('UPDATE _foundation_probe SET media_path=? WHERE id=105', [$path]));
    check('post-commit cleanup retained safe files', $op->snapshot()['state'] === 'cleanup_pending' && is_file(Workspace::media().'/'.$old) && is_file(Workspace::media().'/'.$path));
    $id = $op->id;
    unset($op);
    $op = $journal->load($id);
    check('cleanup recovery uses committed ledger', $coordinator->reconcile($op) === 'committed' && ! is_file(Workspace::media().'/'.$old) && is_file(Workspace::media().'/'.$path));
    $op = $journal->create();
    $path = $stager->stage($upload, 'items', $op);
    expectedFailure('lost commit acknowledgement classified uncertain', fn () => $coordinator->run($op, fn ($c) => probeInsert($c, 106, $path), null, function ($c) {
        $c->commit();
        throw new RuntimeException('Simulated lost commit acknowledgement.');
    }), true, 500);
    check('unknown committed media retained', is_file(Workspace::media().'/'.$path) && $op->snapshot()['state'] === 'outcome_unknown');
    $id = $op->id;
    unset($op);
    $op = $journal->load($id);
    check('uncertain committed outcome reconciled', $coordinator->reconcile($op) === 'committed' && $db->selectOne('SELECT id FROM _foundation_probe WHERE id=106') !== null);
    $op = $journal->create();
    $path = $stager->stage($upload, 'items', $op);
    $killed = (object) ['id' => null];
    expectedFailure('real connection loss during commit classified uncertain', fn () => $coordinator->run($op, fn ($c) => probeInsert($c, 107, $path), function ($point) use ($admin, $db, &$killed) {
        if ($point === 'before_commit') {
            $killed->id = $db->pinnedId();
            if (! $killed->id) {
                throw new RuntimeException('No owned connection to kill.');
            }$admin->exec('KILL CONNECTION '.(int) $killed->id);
        }
    }), true, 500);
    check('real killed connection did not reconnect', $killed->id !== null && $db->pinnedId() === null);
    check('unknown aborted media retained', is_file(Workspace::media().'/'.$path));
    $id = $op->id;
    unset($op);
    $op = $journal->load($id);
    check('uncertain aborted outcome reconciled', $coordinator->reconcile($op) === 'rolled_back' && ! $db->selectOne('SELECT id FROM _foundation_probe WHERE id=107') && ! is_file(Workspace::media().'/'.$path));
    $op = $journal->create();
    $path = $stager->stage($upload, 'items', $op);
    expectedFailure('unreferenced promoted media rejected', fn () => $coordinator->run($op, fn ($c) => null));
    check('unreferenced promoted media compensated', ! is_file(Workspace::media().'/'.$path));
    foreach (['../dev/profile.json', 'app/private/refind/../../../../dev/credentials.json', 'C:/xampp/htdocs/refind/storage/items/test.jpg', 'app/private/refind/items/not-random.jpg'] as $bad) {
        denied('foreign or malformed media path refused', fn () => MediaJournal::scoped($bad));
    }
    $op = $journal->create();
    $id = $op->id;
    denied('active manifest cannot be reconciled', fn () => $journal->load($id));
    unset($op);
    $op = $journal->load($id);
    $coordinator->reconcile($op);
    $invalid = Workspace::storage().'/uploads/invalid.png';
    file_put_contents($invalid, '<?php echo 1;');
    $op = $journal->create();
    denied('spoofed MIME upload refused', fn () => $stager->stage(new UploadedFile($invalid, 'photo.png', 'image/png', null, true), 'items', $op));
    $coordinator->reconcile($op);
    $op = $journal->create();
    for ($i = 0; $i < 4; $i++) {
        $stager->stage($upload, 'items', $op);
    }denied('photo count enforced before transaction', fn () => $stager->stage($upload, 'items', $op));
    $coordinator->reconcile($op);
    $op = $journal->create();
    $path = $stager->stage($upload, 'items', $op);
    expectedFailure('incomplete commit transport cannot report success', fn () => $coordinator->run($op, fn ($c) => probeInsert($c, 110, $path), null, fn ($connection) => null), true, 500);
    check('incomplete commit keeps media until reconciliation', is_file(Workspace::media().'/'.$path) && ! $db->selectOne('SELECT id FROM _foundation_probe WHERE id=110'));
    $id = $op->id;
    unset($op);
    $op = $journal->load($id);
    check('incomplete commit reconciles to rollback', $coordinator->reconcile($op) === 'rolled_back' && ! is_file(Workspace::media().'/'.$path));
    // Demonstrate actual isolated DB + authoritative-media restoration, not just mock compensation.
    $victim = array_key_first($initial['media']);
    unlink(Workspace::media().'/'.$victim);
    check('synthetic restore fixture deliberately differs', AcademicSnapshot::capture($admin) !== $initial);
    unset($op);
    AcademicSnapshot::restore($admin, $snapshot);
    check('entire B test database and media restored', AcademicSnapshot::capture($admin) === $initial);
    $receipt = ['passed' => count($checks), 'checks' => $checks, 'observations' => $samples, 'restore_verified' => true, 'reporting_http_mutations_enabled' => true, 'unsupported_business_mutations_enabled' => false, 'native_connections' => false, 'real_connection_loss_tested' => true, 'lost_commit_acknowledgement_simulated' => true];
    file_put_contents(Workspace::ROOT.'/evidence/phase5/write-results.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode(['write_foundation_checks_passed' => count($checks), 'restore_verified' => true, 'observations' => $samples]);
} catch (Throwable $e) {
    // Keep synthetic state and manifests for review on failure. No reset is automatic.
    file_put_contents(Workspace::ROOT.'/evidence/phase5/write-failure.json', json_encode(['class' => get_class($e), 'message' => $e->getMessage()], JSON_PRETTY_PRINT));
    fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL);
    exit(1);
}
VerificationReceipt::complete(count($checks), $receipt);
