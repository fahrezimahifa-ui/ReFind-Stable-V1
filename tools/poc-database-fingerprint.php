<?php

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$root = 'C:/ReFind-Laravel';
$out = [];
$tables = ['users', 'buildings', 'categories', 'item_statuses', 'campus_locations', 'location_floors', 'items', 'item_photos', 'item_matches', 'claims', 'claim_photos', 'handovers', 'notifications', 'item_status_history', '_workspace_owner'];
foreach (['dev', 'test'] as $name) {
    $p = json_decode(file_get_contents($root.'/state/'.$name.'/profile.json'), true, 16, JSON_THROW_ON_ERROR);
    if ($p['profile'] !== $name || $p['database'] !== 'refind_laravel_'.$name || $p['username'] !== 'refind_'.$name.'_ro') {
        throw new RuntimeException('Unexpected original PoC read-only identity.');
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3307;dbname='.$p['database'].';charset=utf8mb4', $p['username'], $p['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]);
    $v = $pdo->query('SELECT @@port,@@datadir')->fetch(PDO::FETCH_NUM);
    if ((int) $v[0] !== 3307 || strtolower(str_replace('\\', '/', rtrim($v[1], '/\\'))) !== strtolower($root.'/state/mariadb-data')) {
        throw new RuntimeException('Unexpected PoC read-only server.');
    }
    $pdo->exec("SET time_zone='+00:00'");
    foreach ($tables as $table) {
        $rows = $pdo->query('SELECT * FROM `'.$table.'` ORDER BY 1')->fetchAll(PDO::FETCH_ASSOC);
        $ddl = $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
        $out[$name][$table] = ['rows' => count($rows), 'data_sha256' => hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR)), 'schema_sha256' => hash('sha256', $ddl)];
    }
}
echo json_encode($out,JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
