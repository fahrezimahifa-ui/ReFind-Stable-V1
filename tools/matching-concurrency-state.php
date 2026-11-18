<?php

use App\Support\Workspace;
use Illuminate\Support\Facades\DB;

require __DIR__.'/academic-bootstrap.php';
if (Workspace::profile()['profile'] !== 'academic-test' || count($argv) !== 3 || ! ctype_digit($argv[1]) || ! ctype_digit($argv[2])) {
    throw new RuntimeException('Synthetic concurrency state request refused.');
}
$ids = [(int) $argv[1], (int) $argv[2]];
$db = DB::connection();
$pair = $db->selectOne('SELECT COUNT(*) AS n,MAX(match_score) AS score FROM item_matches WHERE lost_item_id=? AND found_item_id=?', $ids);
$out = ['pair_count' => (int) $pair->n, 'pair_score' => $pair->score, 'statuses' => [], 'submissions' => [], 'status_alerts' => [], 'history_counts' => []];
foreach ($ids as $id) {
    $out['statuses'][] = $db->selectOne('SELECT s.status_code FROM items i JOIN item_statuses s ON s.status_id=i.status_id WHERE i.item_id=?', [$id])?->status_code;
    $out['submissions'][] = (int) $db->selectOne("SELECT COUNT(*) AS n FROM notifications WHERE item_id=? AND notification_type='REPORT_SUBMITTED'", [$id])->n;
    $out['status_alerts'][] = (int) $db->selectOne("SELECT COUNT(*) AS n FROM notifications WHERE item_id=? AND notification_type='STATUS_CHANGED'", [$id])->n;
    $out['history_counts'][] = (int) $db->selectOne('SELECT COUNT(*) AS n FROM item_status_history WHERE item_id=?', [$id])->n;
}
$out['private_text_absent'] = ! $db->selectOne("SELECT 1 FROM notifications WHERE message LIKE '%Private concurrent report clue%' LIMIT 1");
$out['deleted_alerts_retained'] = (int) $db->selectOne("SELECT COUNT(*) AS n FROM notifications WHERE user_id=2 AND item_id IS NULL AND notification_type IN ('REPORT_SUBMITTED','STATUS_CHANGED','REPORT_DELETED')")->n >= 3;
$out['no_orphans'] = ! $db->selectOne('SELECT 1 FROM item_matches m LEFT JOIN items l ON l.item_id=m.lost_item_id LEFT JOIN items f ON f.item_id=m.found_item_id WHERE l.item_id IS NULL OR f.item_id IS NULL LIMIT 1') && ! $db->selectOne('SELECT 1 FROM item_status_history h LEFT JOIN items i ON i.item_id=h.item_id WHERE i.item_id IS NULL LIMIT 1') && ! $db->selectOne('SELECT 1 FROM notifications n LEFT JOIN items i ON i.item_id=n.item_id WHERE n.item_id IS NOT NULL AND i.item_id IS NULL LIMIT 1');
echo json_encode($out, JSON_THROW_ON_ERROR);
