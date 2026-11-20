<?php

use App\Support\Workspace;

/*
 * Persistent localhost demo maintenance (profile `demo` only; synthetic schema refind_laravel_demo).
 *   status                     SELECT-only JSON summary
 *   clear-fixtures             remove the provisioning fixtures once, before the first seed (refuses afterwards)
 *   timeline <file>            map seeding wall-clock times onto a realistic campus timeline (once, during seeding)
 *   save-baseline [--replace]  snapshot rows and private media as the demo baseline
 *   restore-baseline           restore the baseline, then move its dates to today (the explicit reset path)
 * Nothing here runs at startup. The demo's data persists until the owner explicitly resets it.
 */
require __DIR__.'/academic-bootstrap.php';
require_once __DIR__.'/AcademicSnapshot.php';

if (Workspace::profile()['profile'] !== 'demo') {
    throw new RuntimeException('The persistent demo profile only.');
}

const FIXTURE_TITLES = ['Brown leather wallet', 'Brown leather wallet', 'Scientific calculator', 'Blue headphones', 'Folding umbrella', 'Card holder', 'Wireless mouse', 'Water bottle'];
// Every stored time column except handovers.scheduled_at (an appointment the BM chose, handled separately).
const EVENT_COLUMNS = ['users' => ['user_id', ['created_at', 'updated_at']], 'items' => ['item_id', ['created_at', 'updated_at', 'resolved_at']],
    'item_photos' => ['photo_id', ['uploaded_at']], 'item_matches' => ['match_id', ['created_at', 'confirmed_at']],
    'claims' => ['claim_id', ['reviewed_at', 'created_at', 'updated_at']], 'claim_photos' => ['claim_photo_id', ['uploaded_at']],
    'handovers' => ['handover_id', ['completed_at', 'created_at', 'updated_at']], 'notifications' => ['notification_id', ['created_at']],
    'item_status_history' => ['history_id', ['changed_at']], '_write_operations' => ['operation_id', ['committed_at']]];
const ISO = '~\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z~';

$action = $argv[1] ?? '';
$pdo = AcademicSnapshot::admin();
$storage = Workspace::storage();
$seedFile = $storage.'/demo-seed.json';
$baselineFile = $storage.'/demo-baseline.json';
$utc = new DateTimeZone('UTC');
$campus = new DateTimeZone('Asia/Jakarta');
$read = fn (string $file) => is_file($file) ? json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR) : null;
$write = function (string $file, array $data): void {
    Workspace::assertPath($file);
    file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
};
$count = fn (string $table) => (int) $pdo->query('SELECT COUNT(*) FROM `'.$table.'`')->fetchColumn();
/** Rewrite every stored event time with $map (string UTC 'Y-m-d H:i:s[.u]' => string), keeping ON UPDATE columns explicit. */
$remap = function (callable $map, bool $appointments) use ($pdo): int {
    $changed = 0;
    foreach (EVENT_COLUMNS as $table => [$expected, $columns]) {
        $keys = array_column($pdo->query("SHOW KEYS FROM `".$table."` WHERE Key_name='PRIMARY'")->fetchAll(PDO::FETCH_ASSOC), 'Column_name');
        if ($keys !== [$expected]) {
            throw new RuntimeException('Unexpected primary key on '.$table.'.');
        }
        $all = $appointments && $table === 'handovers' ? [...$columns, 'scheduled_at'] : $columns;
        $rows = $pdo->query('SELECT `'.$expected.'`,`'.implode('`,`', $all).'` FROM `'.$table.'`')->fetchAll(PDO::FETCH_ASSOC);
        $set = implode(',', array_map(fn ($c) => '`'.$c.'`=?', $all));
        $s = $pdo->prepare('UPDATE `'.$table.'` SET '.$set.' WHERE `'.$expected.'`=?');
        foreach ($rows as $row) {
            $values = array_map(fn ($c) => $row[$c] === null ? null : $map($row[$c]), $all);
            $s->execute([...$values, $row[$expected]]);
            $changed += $s->rowCount();
        }
    }

    return $changed;
};

$out = ['action' => $action];
if ($action === 'status') {
    foreach (['users', 'items', 'item_photos', 'item_matches', 'claims', 'handovers', 'notifications'] as $table) {
        $out['counts'][$table] = $count($table);
    }
    $out['seed'] = $read($seedFile);
    $out['baseline'] = $read($baselineFile);
} elseif ($action === 'clear-fixtures') {
    if (is_file($seedFile) || is_file($baselineFile)) {
        throw new RuntimeException('The demo has already been seeded; fixture cleanup is a one-time first-seed step.');
    }
    $titles = $pdo->query('SELECT title FROM items ORDER BY item_id')->fetchAll(PDO::FETCH_COLUMN);
    if ($titles !== FIXTURE_TITLES || $count('users') !== 4 || $count('handovers') !== 0) {
        throw new RuntimeException('The schema no longer holds exactly the provisioning fixtures; nothing was removed.');
    }
    $files = [...$pdo->query('SELECT file_path FROM item_photos')->fetchAll(PDO::FETCH_COLUMN), ...$pdo->query('SELECT file_path FROM claim_photos')->fetchAll(PDO::FETCH_COLUMN),
        ...$pdo->query('SELECT avatar_path FROM users WHERE avatar_path IS NOT NULL')->fetchAll(PDO::FETCH_COLUMN)];
    $pdo->beginTransaction();
    foreach (['notifications', 'claim_photos', 'handovers', 'claims', 'item_matches', 'item_photos', 'item_status_history', 'items'] as $table) {
        $pdo->exec('DELETE FROM `'.$table.'`');
    }
    $pdo->exec('UPDATE users SET avatar_path=NULL,updated_at=updated_at');
    $pdo->commit();
    foreach (['items', 'item_photos', 'item_matches', 'claims', 'claim_photos', 'handovers', 'notifications', 'item_status_history'] as $table) {
        $pdo->exec('ALTER TABLE `'.$table.'` AUTO_INCREMENT=1');
    }
    foreach ($files as $relative) {
        if (! preg_match('~^(items|evidence|claims|avatars)/[a-f0-9]{32}\.(jpg|png|webp)$~D', $relative)) {
            throw new RuntimeException('Unexpected fixture media path.');
        }
        $path = Workspace::media().'/'.$relative;
        Workspace::assertPath($path);
        if (is_file($path) && ! unlink($path)) {
            throw new RuntimeException('Fixture media cleanup failed.');
        }
    }
    foreach (glob(Workspace::media().'/thumbs/*') ?: [] as $thumb) { // derived thumbnails of the removed fixtures
        Workspace::assertPath($thumb);
        if (is_file($thumb)) {
            unlink($thumb);
        }
    }
    $out['removed_media'] = count($files);
    $write($seedFile, ['state' => 'seeding', 'started_at' => gmdate('c')]);
} elseif ($action === 'timeline') {
    $seed = $read($seedFile);
    if (($seed['state'] ?? '') !== 'seeding' || is_file($baselineFile)) {
        throw new RuntimeException('The timeline is applied once, during the first seed.');
    }
    $plan = $read((string) ($argv[2] ?? ''));
    $markers = array_map(fn ($m) => [new DateTimeImmutable($m[0], $utc), new DateTimeImmutable($m[1], $utc)], $plan['markers'] ?? []);
    if (count($markers) < 2) {
        throw new RuntimeException('Timeline markers missing.');
    }
    foreach (array_slice($markers, 1, null, true) as $i => [$wall, $sim]) {
        if ($wall <= $markers[$i - 1][0] || $sim <= $markers[$i - 1][1]) {
            throw new RuntimeException('Timeline markers must increase.');
        }
    }
    $scale = (int) $plan['scale'];
    $now = new DateTimeImmutable('now', $utc);
    if (end($markers)[1] >= $now) {
        throw new RuntimeException('The simulated timeline must end in the past.');
    }
    $pdo->beginTransaction();
    // Appointment corrections first (pickups the story completed, released or let pass): the row keeps its own updated_at.
    foreach ($plan['appointments'] ?? [] as $a) {
        $old = new DateTimeImmutable($a['old'], $utc);
        $new = new DateTimeImmutable($a['new'], $utc);
        $s = $pdo->prepare('UPDATE handovers SET scheduled_at=?,updated_at=updated_at WHERE claim_id=? AND scheduled_at=?');
        $s->execute([$new->format('Y-m-d H:i:s'), (int) $a['claim_id'], $old->format('Y-m-d H:i:s')]);
        $s = $pdo->prepare("UPDATE notifications SET message=REPLACE(message,?,?) WHERE claim_id=? AND notification_type LIKE 'HANDOVER\\_%'");
        $s->execute([$old->format('Y-m-d\TH:i:s\Z'), $new->format('Y-m-d\TH:i:s\Z'), (int) $a['claim_id']]);
        $out['appointments'][] = (int) $a['claim_id'];
    }
    $first = $markers[0];
    $map = function (string $value) use ($markers, $first, $scale, $utc): string {
        $v = new DateTimeImmutable($value, $utc);
        if ($v < $first[0]) { // provisioning-time rows: the accounts existed before the story started
            return $first[1]->modify('-2 days')->format('Y-m-d H:i:s');
        }
        $i = count($markers) - 1;
        while ($markers[$i][0] > $v) {
            $i--;
        }
        $offset = ($v->getTimestamp() - $markers[$i][0]->getTimestamp()) * $scale;
        $t = $markers[$i][1]->modify('+'.$offset.' seconds');
        if (isset($markers[$i + 1]) && $t >= $markers[$i + 1][1]) {
            $t = $markers[$i + 1][1]->modify('-60 seconds');
        }

        return $t->format('Y-m-d H:i:s');
    };
    $out['rows_updated'] = $remap($map, false);
    $pdo->commit();
    $write($seedFile, $seed + ['timeline_applied_at' => gmdate('c'), 'anchor_day' => $plan['anchor_day']]);
} elseif ($action === 'save-baseline') {
    $seed = $read($seedFile);
    if (($seed['state'] ?? '') !== 'seeded') {
        throw new RuntimeException('Seed the demo completely before saving its baseline.');
    }
    if (is_file($baselineFile) && ($argv[2] ?? '') !== '--replace') {
        throw new RuntimeException('A demo baseline already exists; pass --replace to supersede it deliberately.');
    }
    $id = AcademicSnapshot::save($pdo);
    $file = $storage.'/snapshots/'.$id.'.json';
    $envelope = json_decode((string) file_get_contents($file), true, 4, JSON_THROW_ON_ERROR);
    $day = (new DateTimeImmutable('now', $campus))->format('Y-m-d'); // dates are re-anchored to this campus day on reset
    $previous = $read($baselineFile);
    $write($baselineFile, ['snapshot' => $id, 'payload_sha256' => $envelope['sha256'], 'campus_day' => $day, 'saved_at' => gmdate('c'), 'previous' => $previous['snapshot'] ?? null]);
    $out += ['snapshot' => $id, 'payload_sha256' => $envelope['sha256'], 'campus_day' => $day];
} elseif ($action === 'restore-baseline') {
    $baseline = $read($baselineFile);
    if (! $baseline) {
        throw new RuntimeException('No demo baseline has been saved.');
    }
    AcademicSnapshot::restore($pdo, $baseline['snapshot']);
    // Move the restored story to today so upcoming pickups stay upcoming: one whole-day shift for every date.
    $days = (int) (new DateTimeImmutable($baseline['campus_day'], $campus))->diff(new DateTimeImmutable((new DateTimeImmutable('now', $campus))->format('Y-m-d'), $campus))->format('%r%a');
    if ($days !== 0) {
        $shift = fn (string $value) => (new DateTimeImmutable($value, $utc))->modify(($days > 0 ? '+' : '').$days.' days')->format(strlen($value) > 19 ? 'Y-m-d H:i:s.u' : 'Y-m-d H:i:s');
        $pdo->beginTransaction();
        $out['rows_updated'] = $remap($shift, true);
        $s = $pdo->prepare('UPDATE items SET event_date=DATE_ADD(event_date, INTERVAL ? DAY),updated_at=updated_at');
        $s->execute([$days]);
        foreach ($pdo->query("SELECT notification_id,message FROM notifications WHERE notification_type LIKE 'HANDOVER\\_%'")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $message = preg_replace_callback(ISO, fn ($m) => (new DateTimeImmutable($m[0], $utc))->modify(($days > 0 ? '+' : '').$days.' days')->format('Y-m-d\TH:i:s\Z'), $row['message']);
            $pdo->prepare('UPDATE notifications SET message=? WHERE notification_id=?')->execute([$message, $row['notification_id']]);
        }
        $pdo->commit();
    }
    $out += ['restored' => $baseline['snapshot'], 'shifted_days' => $days];
} else {
    throw new RuntimeException('Usage: demo-data.php status|clear-fixtures|timeline <file>|save-baseline [--replace]|restore-baseline');
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
VerificationReceipt::complete(1, ['action' => $action]);
