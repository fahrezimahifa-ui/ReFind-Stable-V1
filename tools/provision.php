<?php

use App\Support\Workspace;

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__.'/../vendor/autoload.php';

$p = Workspace::profile();
$root = Workspace::ROOT;
$admin = json_decode(file_get_contents($root.'/state/owner-credentials.json'), true, 16, JSON_THROW_ON_ERROR);
$pdo = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', $admin['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
$server = $pdo->query('SELECT @@port AS port,@@datadir AS datadir')->fetch(PDO::FETCH_ASSOC);
if ((int) $server['port'] !== 3307 || Workspace::norm($server['datadir']) !== Workspace::norm($root.'/state/mariadb-data')) {
    throw new RuntimeException('Wrong provisioning server.');
}
$db = $p['database'];
$user = $p['username'];
$pdo->exec('CREATE DATABASE IF NOT EXISTS `'.$db.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$pdo->exec('USE `'.$db.'`');
$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if ($tables) {
    $receipt = Workspace::storage().'/provisioned.json';
    if (! is_file($receipt) || json_decode(file_get_contents($receipt), true)['workspace_id'] !== $p['workspace_id']) {
        throw new RuntimeException('Nonempty schema without valid completed receipt; no reset performed.');
    }
    echo "Already provisioned; preserving isolated data.\n";
    exit;
}
$pdo->exec('CREATE USER IF NOT EXISTS '.$pdo->quote($user)."@'127.0.0.1' IDENTIFIED BY ".$pdo->quote($p['password']));
$pdo->exec('GRANT SELECT ON `'.$db.'`.* TO '.$pdo->quote($user)."@'127.0.0.1'");
$pdo->exec('CREATE TABLE _workspace_owner(workspace_id CHAR(32) PRIMARY KEY) ENGINE=InnoDB');
$s = $pdo->prepare('INSERT INTO _workspace_owner VALUES(?)');
$s->execute([$p['workspace_id']]);
foreach (['core-schema.sql', 'views.sql', 'seed-reference.sql'] as $file) {
    $sql = preg_replace('/^--.*$/m', '', file_get_contents(__DIR__.'/../database/'.$file));
    foreach (explode(';', $sql) as $stmt) {
        if (trim($stmt) !== '') {
            $pdo->exec($stmt);
        }
    }
}
$pdo->exec("SET time_zone='+00:00'");
$credentials = [];
foreach ([['student_a', 'Synthetic Student A', 'student-a@refind-poc.invalid', 'STUDENT', 1], ['student_b', 'Synthetic Student B', 'student-b@refind-poc.invalid', 'STUDENT', 1], ['bm', 'Synthetic Building Management', 'bm@refind-poc.invalid', 'BUILDING_MANAGEMENT', 1], ['inactive', 'Synthetic Inactive Student', 'inactive@refind-poc.invalid', 'STUDENT', 0]] as [$key,$name,$email,$role,$active]) {
    $password = bin2hex(random_bytes(16));
    $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    $s = $pdo->prepare('INSERT INTO users(full_name,email,password_hash,role,is_active)VALUES(?,?,?,?,?)');
    $s->execute([$name, $email, $hash, $role, $active]);
    $credentials[$key] = ['id' => (string) $pdo->lastInsertId(), 'email' => $email, 'password' => $password];
}
$event = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->modify('-1 day')->format('Y-m-d');
$records = [
    [1, 3, 35, 3, 'LOST', 'Brown leather wallet', 'Brown leather wallet with diagonal stitching.', 'Synthetic private ownership clue A.'],
    [2, 3, 35, 3, 'FOUND', 'Brown leather wallet', 'Brown leather wallet with diagonal stitching.', 'Synthetic private finder clue B.'],
    [2, 6, 37, 6, 'LOST', 'Scientific calculator', 'Grey solar calculator.', 'Synthetic private recovered clue.'],
    [1, 1, 38, 1, 'FOUND', 'Blue headphones', 'Blue over-ear headphones.', 'Synthetic private headphones clue.'],
    [2, 8, 39, 4, 'FOUND', 'Folding umbrella', 'Compact folding umbrella.', 'Synthetic private umbrella clue.'],
    [1, 3, 36, 1, 'LOST', 'Card holder', 'Black card holder.', 'Synthetic private card-holder clue.'],
    [2, 1, 38, 1, 'FOUND', 'Wireless mouse', 'Grey wireless mouse.', 'Synthetic private mouse clue.'],
    [2, 8, 40, 7, 'FOUND', 'Water bottle', 'Clear water bottle.', 'Synthetic private archived clue.']];
foreach ($records as [$reporter,$cat,$loc,$status,$type,$title,$description,$clue]) {
    $s = $pdo->prepare('INSERT INTO items(reporter_user_id,category_id,location_id,status_id,item_type,title,description,event_date,event_time,verification_clue,resolved_at)VALUES(?,?,?,?,?,?,?,?,NULL,?,?)');
    $s->execute([$reporter, $cat, $loc, $status, $type, $title, $description, $event, $clue, in_array($status, [6, 7]) ? gmdate('Y-m-d H:i:s') : null]);
    $id = (int) $pdo->lastInsertId();
    $s = $pdo->prepare('INSERT INTO item_status_history(item_id,status_id,changed_by_user_id,note)VALUES(?,?,?,?)');
    $s->execute([$id, $status, $reporter, 'Synthetic report fixture.']);
}
$pdo->exec('INSERT INTO item_matches(lost_item_id,found_item_id,match_score)VALUES(1,2,100)');
$pdo->exec("INSERT INTO claims(item_id,claimant_user_id,ownership_answer)VALUES(5,1,'Synthetic private claim answer.')");
$media = [];
function picture(string $collection, string $label, bool $avatar = false): string
{
    $relative = $collection.'/'.bin2hex(random_bytes(16)).($avatar ? '.jpg' : '.png');
    $im = imagecreatetruecolor($avatar ? 512 : 900, $avatar ? 512 : 540);
    $bg = imagecolorallocate($im, 229, 237, 244);
    $fg = imagecolorallocate($im, 45, 73, 96);
    imagefill($im, 0, 0, $bg);
    imagefilledrectangle($im, 80, 80, $avatar ? 432 : 820, $avatar ? 432 : 460, $fg);
    imagestring($im, 5, 100, 110, $label, imagecolorallocate($im, 255, 255, 255));
    $path = Workspace::media().'/'.$relative;
    if ($avatar) {
        imagejpeg($im, $path, 85);
    } else {
        imagepng($im, $path);
    }

    return $relative;
}
for ($id = 1; $id <= 8; $id++) {
    $path = picture('items', 'SYNTHETIC REPORT '.$id);
    $s = $pdo->prepare("INSERT INTO item_photos(item_id,photo_kind,file_path,is_primary)VALUES(?,'ITEM',?,1)");
    $s->execute([$id, $path]);
    $media['item_'.$id] = (int) $pdo->lastInsertId();
}
$path = picture('evidence', 'SYNTHETIC PRIVATE LOST EVIDENCE');
$s = $pdo->prepare("INSERT INTO item_photos(item_id,photo_kind,file_path)VALUES(6,'OWNERSHIP',?)");
$s->execute([$path]);
$media['ownership'] = (int) $pdo->lastInsertId();
$path = picture('claims', 'SYNTHETIC PRIVATE CLAIM EVIDENCE');
$s = $pdo->prepare('INSERT INTO claim_photos(claim_id,file_path)VALUES(1,?)');
$s->execute([$path]);
$media['claim'] = (int) $pdo->lastInsertId();
$path = picture('avatars', 'SYNTHETIC AVATAR', true);
$s = $pdo->prepare('UPDATE users SET avatar_path=? WHERE user_id=1');
$s->execute([$path]);
$media['avatar_token'] = basename($path, '.jpg');
$pdo->exec("INSERT INTO notifications(user_id,notification_type,title,message,item_id)VALUES(1,'REPORT_SUBMITTED','Report submitted','Synthetic report saved.',1),(1,'CLAIM_SUBMITTED','Claim submitted','Synthetic claim awaiting review.',5)");
file_put_contents(Workspace::storage().'/credentials.json', json_encode($credentials, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
file_put_contents(Workspace::storage().'/fixtures.json', json_encode($media, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
file_put_contents(Workspace::storage().'/provisioned.json', json_encode(['workspace_id' => $p['workspace_id'], 'profile' => $p['profile'], 'core_tables' => 14, 'views' => 2, 'synthetic' => true], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
echo 'Provisioned isolated '.$p['profile']." with synthetic fixtures and SELECT-only application access.\n";
