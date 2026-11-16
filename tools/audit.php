<?php

use App\Support\Workspace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$keys = ['users' => 'user_id', 'buildings' => 'building_id', 'categories' => 'category_id', 'item_statuses' => 'status_id', 'campus_locations' => 'location_id', 'location_floors' => 'location_floor_id', 'items' => 'item_id', 'item_photos' => 'photo_id', 'item_matches' => 'match_id', 'claims' => 'claim_id', 'claim_photos' => 'claim_photo_id', 'handovers' => 'handover_id', 'notifications' => 'notification_id', 'item_status_history' => 'history_id', '_workspace_owner' => 'workspace_id'];
$out = ['profile' => Workspace::profile()['profile'], 'tables' => [], 'media' => []];
foreach ($keys as $table => $key) {
    $rows = DB::select('SELECT * FROM `'.$table.'` ORDER BY `'.$key.'`');
    $ddl = (array) DB::selectOne('SHOW CREATE TABLE `'.$table.'`');
    $out['tables'][$table] = ['rows' => count($rows), 'data_sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)), 'schema_sha256' => hash('sha256', json_encode($ddl, JSON_THROW_ON_ERROR))];
}
foreach (['items', 'evidence', 'claims', 'avatars'] as $collection) {
    foreach (glob(Workspace::media().'/'.$collection.'/*') as $file) {
        $out['media'][$collection.'/'.basename($file)] = hash_file('sha256', $file);
    }
}
$out['instance'] = (array) DB::selectOne('SELECT @@port AS port,@@datadir AS datadir,@@time_zone AS time_zone,@@tx_isolation AS isolation_level,@@sql_mode AS sql_mode,VERSION() AS version');
echo json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
