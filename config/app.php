<?php

use App\Support\Workspace;

$p = Workspace::profile();

return ['name' => 'ReFind', 'env' => $p['profile'] === 'academic-test' || (in_array($p['profile'], ['phase6-dev', 'phase7-dev'], true) && getenv('APP_ENV') === 'testing') ? 'testing' : 'local', 'debug' => false, 'url' => $p['url'], 'timezone' => 'UTC', 'locale' => 'en', 'fallback_locale' => 'en', 'faker_locale' => 'en_US', 'cipher' => 'AES-256-CBC', 'key' => $p['key'], 'previous_keys' => [], 'maintenance' => ['driver' => 'file']];
