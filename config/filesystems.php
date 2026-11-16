<?php

use App\Support\Workspace;

return ['default' => 'refind_private', 'disks' => ['refind_private' => ['driver' => 'local', 'root' => Workspace::media(), 'visibility' => 'private', 'serve' => false, 'throw' => true]]];
