<?php

use App\Support\Workspace;

$p = Workspace::profile();

return ['default' => 'mariadb', 'connections' => ['mariadb' => ['driver' => 'mariadb', 'host' => '127.0.0.1', 'port' => 3307, 'database' => $p['database'], 'username' => $p['username'], 'password' => $p['password'], 'unix_socket' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'prefix_indexes' => true, 'strict' => false, 'modes' => ['NO_ZERO_IN_DATE', 'NO_ZERO_DATE', 'NO_ENGINE_SUBSTITUTION'], 'timezone' => '+00:00', 'isolation_level' => 'REPEATABLE READ', 'engine' => 'InnoDB', 'options' => [PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false, PDO::ATTR_PERSISTENT => false]]], 'migrations' => ['table' => 'migrations', 'update_date_on_publish' => false]];
