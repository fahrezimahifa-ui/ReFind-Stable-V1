<?php

namespace App\Session;

use Illuminate\Session\SessionManager;

final class ReFindSessionManager extends SessionManager
{
    protected function buildSession($handler)
    {
        if ($this->config->get('session.driver') !== 'file' || $this->config->get('session.encrypt')) {
            throw new \RuntimeException('Only the isolated file session profile is enabled.');
        }

        return new ReFindSessionStore($this->config->get('session.cookie'), $handler, null, 'json');
    }
}
