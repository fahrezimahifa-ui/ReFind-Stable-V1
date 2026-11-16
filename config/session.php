<?php

use App\Support\Workspace;

$p = Workspace::profile();

return ['driver' => 'file', 'lifetime' => 480, 'expire_on_close' => true, 'encrypt' => false, 'files' => storage_path('framework/sessions'), 'lottery' => [2, 100], 'cookie' => 'refind_laravel_'.$p['profile'], 'path' => '/', 'domain' => null, 'secure' => false, 'http_only' => true, 'same_site' => 'lax', 'partitioned' => false, 'serialization' => 'json', 'block' => true, 'block_store' => 'file', 'block_lock_seconds' => 65, 'block_wait_seconds' => 10];
