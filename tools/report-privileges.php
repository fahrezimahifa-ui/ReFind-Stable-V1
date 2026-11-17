<?php

use App\Support\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/academic-bootstrap.php';
try {
    $p = Workspace::profile();
    $db = DB::connection();
    $grantee = "'".$p['username']."'@'127.0.0.1'";
    $test = $p['profile'] === 'academic-test';
    $active = $test || config('refind.report_effects');
    $schema = array_map(fn ($r) => (array) $r, $db->select('SELECT TABLE_SCHEMA,PRIVILEGE_TYPE,IS_GRANTABLE FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE=?', [$grantee]));
    if ($schema !== [['TABLE_SCHEMA' => $p['database'], 'PRIVILEGE_TYPE' => 'SELECT', 'IS_GRANTABLE' => 'NO']]) {
        throw new RuntimeException('SAFETY GATE FAILED: schema privilege');
    }
    $global = $db->select('SELECT PRIVILEGE_TYPE,IS_GRANTABLE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE=?', [$grantee]);
    foreach ($global as $g) {
        if ($g->PRIVILEGE_TYPE !== 'USAGE' || $g->IS_GRANTABLE !== 'NO') {
            throw new RuntimeException('SAFETY GATE FAILED: global privilege');
        }
    }
    if ($db->selectOne('SELECT CURRENT_ROLE() AS r')->r !== null) {
        throw new RuntimeException('SAFETY GATE FAILED: role grants');
    }
    $allowed = [];
    $add = function ($table, $priv, $cols) use (&$allowed) {
        foreach (explode(',', $cols) as $column) {
            $allowed[$table.'|'.$column.'|'.$priv] = true;
        }
    };
    if ($active) {
        $add('items', 'INSERT', 'reporter_user_id,category_id,location_id,status_id,item_type,title,description,location_detail,event_date,event_time,verification_clue');
        $add('items', 'UPDATE', 'title,description,category_id,location_id,location_floor_id,location_detail,event_date,event_time,verification_clue');
        $add('item_photos', 'INSERT', 'item_id,photo_kind,file_path');
        $add('item_photos', 'UPDATE', 'is_primary');
    }
    if (config('refind.report_effects')) {
        $add('items', 'UPDATE', 'status_id,resolved_at');
        $add('item_matches', 'INSERT', 'lost_item_id,found_item_id,match_score');
        $add('item_matches', 'UPDATE', 'match_score');
        $add('item_status_history', 'INSERT', 'item_id,status_id,changed_by_user_id,note');
        $add('notifications', 'INSERT', 'user_id,notification_type,title,message,item_id,claim_id');
    }
    $columns = array_map(fn ($r) => (array) $r, $db->select('SELECT TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,PRIVILEGE_TYPE,IS_GRANTABLE FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE=?', [$grantee]));
    $actual = [];
    foreach ($columns as $c) {
        if ($c['TABLE_SCHEMA'] !== $p['database'] || $c['IS_GRANTABLE'] !== 'NO') {
            throw new RuntimeException('SAFETY GATE FAILED: foreign column privilege');
        }$actual[$c['TABLE_NAME'].'|'.$c['COLUMN_NAME'].'|'.$c['PRIVILEGE_TYPE']] = true;
    }
    ksort($allowed);
    ksort($actual);
    if ($allowed !== $actual) {
        throw new RuntimeException('SAFETY GATE FAILED: column privileges differ');
    }
    $expected = [];
    if ($test) {
        foreach (['INSERT', 'UPDATE', 'DELETE'] as $priv) {
            $expected[] = '_foundation_probe|'.$priv;
        }
    }if ($active) {
        $expected[] = '_write_operations|INSERT';
        $expected[] = 'items|DELETE';
        $expected[] = 'item_photos|DELETE';
    }if (config('refind.report_effects')) {
        $expected[] = 'item_matches|DELETE';
    }sort($expected);
    $tables = array_map(fn ($r) => (array) $r, $db->select('SELECT TABLE_SCHEMA,TABLE_NAME,PRIVILEGE_TYPE,IS_GRANTABLE FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=?', [$grantee]));
    $actual = [];
    foreach ($tables as $t) {
        if ($t['TABLE_SCHEMA'] !== $p['database'] || $t['IS_GRANTABLE'] !== 'NO') {
            throw new RuntimeException('SAFETY GATE FAILED: foreign table privilege');
        }$actual[] = $t['TABLE_NAME'].'|'.$t['PRIVILEGE_TYPE'];
    }sort($actual);
    if ($actual !== $expected) {
        throw new RuntimeException('SAFETY GATE FAILED: table privileges differ');
    }
    foreach (['refind_laravel_dev', 'refind_laravel_test', $test ? 'refind_laravel_b_dev' : 'refind_laravel_b_test', 'refind_db_0_2_0', 'refind_db_0_2_0_test'] as $foreign) {
        foreach (['SELECT * FROM `'.$foreign.'`.users LIMIT 0', 'UPDATE `'.$foreign.'`.users SET full_name=full_name WHERE 1=0'] as $sql) {
            try {
                $db->statement($sql);
                throw new RuntimeException('SAFETY GATE FAILED: foreign access');
            } catch (QueryException$e) {
                if (! in_array((int) $e->errorInfo[1], [1044, 1142, 1143], true)) {
                    throw $e;
                }
            }
        }
    }
    foreach (['UPDATE users SET full_name=full_name WHERE 1=0', 'UPDATE items SET reporter_user_id=reporter_user_id WHERE 1=0', 'UPDATE items SET item_type=item_type WHERE 1=0', 'UPDATE item_matches SET match_status=match_status WHERE 1=0', 'UPDATE notifications SET is_read=is_read WHERE 1=0', 'DELETE FROM _write_operations WHERE 1=0'] as $sql) {
        try {
            $db->statement($sql);
            throw new RuntimeException('SAFETY GATE FAILED: protected mutation');
        } catch (QueryException$e) {
            if (! in_array((int) $e->errorInfo[1], [1142, 1143], true)) {
                throw $e;
            }
        }
    }
    echo json_encode(['profile' => $p['profile'], 'passed' => true, 'schema_grants' => $schema, 'table_grants' => $tables, 'column_grants' => $columns, 'cross_schema_select_update_denials' => 10, 'protected_mutation_denials' => 6, 'native_server_contacted' => false], JSON_PRETTY_PRINT);
} catch (Throwable$e) {
    fwrite(STDERR,$e->getMessage());
    exit(1);
}
VerificationReceipt::complete(21, ['profile' => $p['profile'], 'schema_grants' => $schema, 'table_grants' => $tables, 'column_grants' => $columns, 'cross_schema_denials' => 10, 'protected_mutation_denials' => 6, 'native_server_contacted' => false]);
