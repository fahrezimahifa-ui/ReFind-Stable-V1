<?php

use App\Support\Workspace;

require __DIR__.'/academic-bootstrap.php';
require __DIR__.'/AcademicSnapshot.php';
try {
    $admin = AcademicSnapshot::admin();
    $action = $argv[1] ?? '';
    if ($action === 'save') {
        echo json_encode(['snapshot' => AcademicSnapshot::save($admin)]);
    } elseif ($action === 'restore') {
        $records = json_decode(file_get_contents(Workspace::ROOT.'/state/academic-processes.json'), true);
        foreach ($records as $key => $record) {
            if (str_starts_with($key, 'web-')) {
                throw new RuntimeException('Stop web processes before restore.');
            }
        }
        AcademicSnapshot::restore($admin, $argv[2] ?? '');
        echo json_encode(['restored' => true]);
    } elseif ($action === 'eligibility') {
        $admin->exec("INSERT INTO item_matches(lost_item_id,found_item_id,match_score,match_status,confirmed_by_user_id,confirmed_at)VALUES(6,7,100,'CONFIRMED',3,UTC_TIMESTAMP())");
        $admin->exec("INSERT INTO claims(item_id,claimant_user_id,ownership_answer,claim_status,reviewed_by_user_id,review_note,reviewed_at)VALUES(4,2,'Synthetic closed claim ownership proof.','REJECTED',3,'Synthetic rejection.',UTC_TIMESTAMP())");
        $claim = (int) $admin->lastInsertId();
        $relative = 'claims/'.bin2hex(random_bytes(16)).'.png';
        $im = imagecreatetruecolor(40, 30);
        imagepng($im, Workspace::media().'/'.$relative);
        $s = $admin->prepare('INSERT INTO claim_photos(claim_id,file_path)VALUES(?,?)');
        $s->execute([$claim, $relative]);
        file_put_contents(Workspace::storage().'/phase5-eligibility.json', json_encode(['claim' => $claim, 'claim_photo' => $relative]));
        echo json_encode(['synthetic_eligibility_fixtures' => true]);
    } else {
        throw new RuntimeException('Invalid fixture action.');
    }
} catch (Throwable$e) {
    fwrite(STDERR, $e->getMessage());
    exit(1);
}
VerificationReceipt::complete(1, ['action' => $action, 'profile' => 'academic-test']);
