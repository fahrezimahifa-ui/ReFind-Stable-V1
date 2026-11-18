<?php

use App\Support\Workspace;

require __DIR__.'/academic-bootstrap.php';
require __DIR__.'/AcademicSnapshot.php';
foreach (json_decode(file_get_contents(Workspace::ROOT.'/state/academic-processes.json'), true, 32, JSON_THROW_ON_ERROR) as $name => $record) {
    if (str_starts_with($name, 'web-')) {
        throw new RuntimeException('Baseline requires stopped web instances.');
    }
}
$pdo = AcademicSnapshot::admin();
$id = AcademicSnapshot::save($pdo);
$p = Workspace::profile();
$grantee = "'".$p['username']."'@'127.0.0.1'";
$privileges = [];
foreach (['SCHEMA_PRIVILEGES', 'TABLE_PRIVILEGES', 'COLUMN_PRIVILEGES'] as $table) {
    $statement = $pdo->prepare('SELECT * FROM information_schema.'.$table.' WHERE GRANTEE=?');
    $statement->execute([$grantee]);
    $privileges[$table] = $statement->fetchAll(PDO::FETCH_ASSOC);
}
$receipt = ['baseline_commit' => 'f004beaadc691bc2aa39f6d3d89b28184ad0b972', 'profile' => $p['profile'], 'database' => $p['database'], 'snapshot' => $id, 'privileges' => $privileges, 'profile_sha256' => hash_file('sha256', Workspace::storage().'/profile.json'), 'credentials_sha256' => hash_file('sha256', Workspace::storage().'/credentials.json')];
file_put_contents(Workspace::storage().'/snapshots/checkpoint-b-'.$id.'.metadata.json', json_encode($receipt, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo json_encode(['profile' => $p['profile'], 'database' => $p['database'], 'snapshot' => $id, 'baseline_saved' => true], JSON_THROW_ON_ERROR);
VerificationReceipt::complete(1, ['profile' => $p['profile'], 'snapshot' => $id, 'baseline_saved' => true]);
