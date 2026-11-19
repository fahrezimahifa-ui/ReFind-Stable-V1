<?php

use App\Support\Workspace;

/*
 * phase6-dev / phase7-dev helper (disposable synthetic profiles only).
 *   save                  snapshot DB rows + private media, prints the id
 *   restore <id>          exact restore (refuses while phase6-dev workers or app sessions are live)
 *   state                 SELECT-only JSON summary used by concurrency/HTTP suites
 *   seed-students         add synthetic student_c/student_d (credentials in private tmp state)
 *   hold-lock <seconds>   hold the shared workflow advisory lock to prove contention handling
 */
require __DIR__.'/academic-bootstrap.php';
require_once __DIR__.'/AcademicSnapshot.php';

if (! Workspace::isDev()) {
    throw new RuntimeException('Disposable development profiles only.');
}
$action = $argv[1] ?? '';
$admin = AcademicSnapshot::admin();
$out = ['action' => $action];
if ($action === 'save') {
    $out['id'] = AcademicSnapshot::save($admin);
} elseif ($action === 'restore') {
    AcademicSnapshot::restore($admin, (string) ($argv[2] ?? ''));
    $out['restored'] = true;
} elseif ($action === 'state') {
    $q = fn (string $sql) => $admin->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $out['claims'] = $q('SELECT claim_id,item_id,related_lost_item_id,claimant_user_id,claim_status,reviewed_by_user_id FROM claims ORDER BY claim_id');
    $out['handovers'] = $q('SELECT handover_id,claim_id,pickup_location_id,handover_status FROM handovers ORDER BY handover_id');
    $out['matches'] = $q('SELECT lost_item_id,found_item_id,match_status FROM item_matches ORDER BY lost_item_id,found_item_id');
    $out['items'] = $q('SELECT i.item_id,s.status_code FROM items i JOIN item_statuses s ON s.status_id=i.status_id ORDER BY i.item_id');
    $out['claim_photos'] = $q('SELECT claim_photo_id,claim_id FROM claim_photos ORDER BY claim_photo_id');
    $out['notifications'] = $q('SELECT user_id,notification_type,claim_id,item_id FROM notifications ORDER BY notification_id');
    $out['claim_media_files'] = count(glob(Workspace::media().'/claims/*'));
    $out['approved_per_found'] = $q("SELECT item_id,COUNT(*) AS n FROM claims WHERE claim_status='APPROVED' GROUP BY item_id HAVING n>1");
    $out['confirmed_per_report'] = $q("SELECT id,COUNT(*) AS n FROM (SELECT lost_item_id AS id FROM item_matches WHERE match_status='CONFIRMED' UNION ALL SELECT found_item_id FROM item_matches WHERE match_status='CONFIRMED') x GROUP BY id HAVING n>1");
} elseif ($action === 'seed-students') {
    // Extra synthetic claimants for multi-claimant races; credentials stay in private tmp state.
    $out['students'] = [];
    $credentials = [];
    foreach (['student_c', 'student_d'] as $key) {
        $email = str_replace('_', '-', $key).'@refind-poc.invalid';
        $password = bin2hex(random_bytes(16));
        $s = $admin->prepare('INSERT INTO users(full_name,email,password_hash,role,is_active) VALUES (?,?,?,?,1)');
        $s->execute(['Synthetic '.ucwords(str_replace('_', ' ', $key)), $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]), 'STUDENT']);
        $credentials[$key] = ['id' => (string) $admin->lastInsertId(), 'email' => $email, 'password' => $password];
        $out['students'][] = $key;
    }
    file_put_contents(Workspace::storage().'/tmp/extra-credentials.json', json_encode($credentials, JSON_PRETTY_PRINT));
} elseif ($action === 'hold-lock') {
    $seconds = max(1, min(30, (int) ($argv[2] ?? 8)));
    $s = $admin->prepare('SELECT GET_LOCK(?,0)');
    $s->execute(['refind-matching-'.Workspace::profile()['database']]);
    if ((int) $s->fetchColumn() !== 1) {
        throw new RuntimeException('Advisory lock busy.');
    }
    file_put_contents(Workspace::storage().'/tmp/lock-held.flag', (string) time());
    sleep($seconds);
    @unlink(Workspace::storage().'/tmp/lock-held.flag');
    $out['held_seconds'] = $seconds;
} else {
    throw new RuntimeException('Usage: phase6-dev.php save|restore <id>|state|seed-students|hold-lock <seconds>');
}
echo json_encode($out, JSON_PRETTY_PRINT);
VerificationReceipt::complete(1, ['action' => $action]);
