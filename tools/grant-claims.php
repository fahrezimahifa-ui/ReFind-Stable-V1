<?php

use App\Support\Workspace;

/*
 * Reviewed, reversible Phase 6 claim grant deltas.
 *   php tools/grant-claims.php <student|review|approval|pickup> [--revoke]
 * Applied only to the disposable phase6-dev/phase7-dev principals (pickup: phase7-dev only). Activating these grants for academic-dev or
 * academic-test requires a separately reviewed change to ACTIVATION below (fail closed until then).
 * Revoke in reverse order (pickup, approval, review, student); a stage cannot be revoked while a later stage is live.
 */
require __DIR__.'/academic-bootstrap.php';

const ACTIVATION = ['student' => ['phase6-dev', 'phase7-dev'], 'review' => ['phase6-dev', 'phase7-dev'], 'approval' => ['phase6-dev', 'phase7-dev'], 'pickup' => ['phase7-dev']];
const ORDER = ['student', 'review', 'approval', 'pickup'];
const STAGES = [
    'student' => [
        ['claims', 'INSERT', 'item_id,related_lost_item_id,claimant_user_id,ownership_answer'],
        ['claims', 'UPDATE', 'ownership_answer,related_lost_item_id,claim_status,reviewed_by_user_id,review_note,reviewed_at'],
        ['claim_photos', 'INSERT', 'claim_id,file_path'],
        ['claim_photos', 'DELETE', null],
    ],
    'review' => [
        ['claims', 'UPDATE', 'claim_status,reviewed_by_user_id,review_note,reviewed_at'],
    ],
    'approval' => [
        ['claims', 'UPDATE', 'claim_status,reviewed_by_user_id,review_note,reviewed_at'],
        ['item_matches', 'INSERT', 'match_status,confirmed_by_user_id,confirmed_at'],
        ['item_matches', 'UPDATE', 'match_score,match_status,confirmed_by_user_id,confirmed_at'],
        ['handovers', 'INSERT', 'claim_id,pickup_location_id,handled_by_user_id,notes'],
    ],
    // Phase 7 pickup operations: schedule/reschedule/cancel/complete update the handover; native release deletes it.
    'pickup' => [
        ['handovers', 'UPDATE', 'handover_status,pickup_location_id,scheduled_at,completed_at,handled_by_user_id,notes'],
        ['handovers', 'DELETE', null],
    ],
];

function grant_map(PDO $pdo, string $grantee, string $db): array
{
    $map = [];
    $s = $pdo->prepare('SELECT TABLE_NAME,PRIVILEGE_TYPE,COLUMN_NAME FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE=? AND TABLE_SCHEMA=? ORDER BY 1,2,3');
    $s->execute([$grantee, $db]);
    foreach ($s->fetchAll(PDO::FETCH_NUM) as [$t,$p,$c]) {
        $map[] = "$t|$p|$c";
    }
    $s = $pdo->prepare('SELECT TABLE_NAME,PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=? AND TABLE_SCHEMA=? ORDER BY 1,2');
    $s->execute([$grantee, $db]);
    foreach ($s->fetchAll(PDO::FETCH_NUM) as [$t,$p]) {
        $map[] = "$t|$p|*";
    }
    sort($map);

    return $map;
}

function stage_entries(string $stage): array
{
    $out = [];
    foreach (STAGES[$stage] as [$table,$priv,$columns]) {
        foreach ($columns ? explode(',', $columns) : ['*'] as $column) {
            $out[] = "$table|$priv|$column";
        }
    }

    return $out;
}

try {
    $stage = $argv[1] ?? '';
    $revoke = in_array('--revoke', array_slice($argv, 2), true);
    $p = Workspace::profile();
    if (! isset(STAGES[$stage]) || array_diff(array_slice($argv, 2), ['--revoke'])) {
        throw new RuntimeException('Usage: grant-claims.php <student|review|approval|pickup> [--revoke]');
    }
    if (! in_array($p['profile'], ACTIVATION[$stage], true)) {
        throw new RuntimeException('Claim grants are not activated for this profile.');
    }
    // Live quiescence: no listener on this profile's ports and no connected app-principal session.
    foreach (Workspace::definition()['ports'] as $port) {
        $socket = @fsockopen('127.0.0.1', $port, $code, $message, 0.3);
        if ($socket) {
            fclose($socket);
            throw new RuntimeException('Stop the web workers of this profile before changing grants.');
        }
    }
    $secret = json_decode(file_get_contents(Workspace::ROOT.'/state/owner-credentials.json'), true, 16, JSON_THROW_ON_ERROR);
    $pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname='.$p['database'].';charset=utf8mb4', 'root', $secret['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $server = $pdo->query('SELECT @@port AS p,@@datadir AS d,DATABASE() AS db')->fetch(PDO::FETCH_ASSOC);
    if ((int) $server['p'] !== 3307 || Workspace::norm($server['d']) !== Workspace::norm(Workspace::ROOT.'/state/mariadb-data') || $server['db'] !== $p['database'] || $pdo->query('SELECT workspace_id FROM _workspace_owner')->fetchColumn() !== $p['workspace_id']) {
        throw new RuntimeException('Grant target ownership mismatch.');
    }
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE USER=?');
    $s->execute([$p['username']]);
    if ((int) $s->fetchColumn() !== 0) {
        throw new RuntimeException('Application sessions are connected; grant change refused.');
    }
    $grantee = "'".$p['username']."'@'127.0.0.1'";
    $user = $pdo->quote($p['username'])."@'127.0.0.1'";
    $target = '`'.$p['database'].'`.';
    $before = grant_map($pdo, $grantee, $p['database']);
    $statements = [];
    if (! $revoke) {
        foreach (STAGES[$stage] as [$table,$priv,$columns]) {
            $statements[] = 'GRANT '.$priv.($columns ? ' ('.$columns.')' : '').' ON '.$target.'`'.$table.'` TO '.$user;
        }
    } else {
        foreach (array_slice(ORDER, array_search($stage, ORDER, true) + 1) as $next) {
            $earlier = array_merge(...array_map('stage_entries', array_slice(ORDER, 0, array_search($next, ORDER, true))));
            if (array_intersect(array_diff(stage_entries($next), $earlier), $before)) {
                throw new RuntimeException('Revoke later stages first ('.$next.' is still granted).');
            }
        }
        // Keep entries an earlier stage (or the accepted reporting set) still needs.
        $keep = array_merge(['item_matches|INSERT|lost_item_id', 'item_matches|INSERT|found_item_id', 'item_matches|INSERT|match_score'], ...array_map('stage_entries', array_slice(ORDER, 0, array_search($stage, ORDER, true))));
        $remove = [];
        foreach (array_diff(stage_entries($stage), $keep) as $entry) {
            [$table,$priv,$column] = explode('|', $entry);
            if (in_array($entry, $before, true)) {
                $remove[$table.'|'.$priv][] = $column;
            }
        }
        foreach ($remove as $key => $columns) {
            [$table,$priv] = explode('|', $key);
            $statements[] = 'REVOKE '.$priv.($columns !== ['*'] ? ' ('.implode(',', $columns).')' : '').' ON '.$target.'`'.$table.'` FROM '.$user;
        }
    }
    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
    $after = grant_map($pdo, $grantee, $p['database']);
    $result = ['profile' => $p['profile'], 'stage' => $stage, 'revoke' => $revoke, 'statements' => count($statements), 'added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after)), 'original_targets_modified' => false];
    file_put_contents(Workspace::storage().'/grants-'.gmdate('Ymd\THis\Z').'-'.$stage.($revoke ? '-revoke' : '').'.json', json_encode(['before' => $before, 'after' => $after] + $result, JSON_PRETTY_PRINT));
    echo json_encode($result);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
VerificationReceipt::complete(max(1, count($statements)), $result);
