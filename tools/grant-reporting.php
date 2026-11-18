<?php

use App\Support\Workspace;

require __DIR__.'/academic-bootstrap.php';
try {
    $stage = $argv[1] ?? '';
    $p = Workspace::profile();
    if (! in_array($stage, ['crud', 'matching'], true) || ($stage === 'crud' && $p['profile'] !== 'academic-test')) {
        throw new RuntimeException('Invalid grant stage/profile.');
    }
    $records = json_decode(file_get_contents(Workspace::ROOT.'/state/academic-processes.json'), true);
    foreach ($records as $key => $value) {
        if (str_starts_with($key, 'web-')) {
            throw new RuntimeException('Stop web instances before changing grants.');
        }
    }
    $secret = json_decode(file_get_contents(Workspace::ROOT.'/state/owner-credentials.json'), true);
    $pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname='.$p['database'].';charset=utf8mb4', 'root', $secret['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $server = $pdo->query('SELECT @@port AS p,@@datadir AS d')->fetch(PDO::FETCH_ASSOC);
    if ((int) $server['p'] !== 3307 || Workspace::norm($server['d']) !== Workspace::norm(Workspace::ROOT.'/state/mariadb-data') || $pdo->query('SELECT workspace_id FROM _workspace_owner')->fetchColumn() !== $p['workspace_id']) {
        throw new RuntimeException('Grant target ownership mismatch.');
    }
    $target = '`'.$p['database'].'`.';
    $user = $pdo->quote($p['username'])."@'127.0.0.1'";
    $grants = [
        ['items', 'INSERT', 'reporter_user_id,category_id,location_id,status_id,item_type,title,description,location_detail,event_date,event_time,verification_clue'],
        ['items', 'UPDATE', 'title,description,category_id,location_id,location_floor_id,location_detail,event_date,event_time,verification_clue'], ['items', 'DELETE', null],
        ['item_photos', 'INSERT', 'item_id,photo_kind,file_path'], ['item_photos', 'UPDATE', 'is_primary'], ['item_photos', 'DELETE', null], ['_write_operations', 'INSERT', null],
    ];
    if ($stage === 'matching') {
        $grants = array_merge($grants, [['items', 'UPDATE', 'status_id,resolved_at'], ['item_matches', 'INSERT', 'lost_item_id,found_item_id,match_score'], ['item_matches', 'DELETE', null], ['item_status_history', 'INSERT', 'item_id,status_id,changed_by_user_id,note'], ['notifications', 'INSERT', 'user_id,notification_type,title,message,item_id,claim_id']]);
    }
    foreach ($grants as [$table,$priv,$columns]) {
        $pdo->exec('GRANT '.$priv.($columns ? ' ('.$columns.')' : '').' ON '.$target.'`'.$table.'` TO '.$user);
    }
    echo json_encode(['profile' => $p['profile'], 'stage' => $stage, 'grants' => $grants, 'original_targets_modified' => false]);
} catch (Throwable$e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
VerificationReceipt::complete(count($grants), ['profile' => $p['profile'], 'stage' => $stage, 'grants' => $grants, 'native_connections' => false]);
