<?php

namespace App\Session;

use Illuminate\Session\Store;

final class ReFindSessionStore extends Store
{
    public function regenerateToken()
    {
        $this->put('_token', bin2hex(random_bytes(32)));
    }

    public function start()
    {
        $result = parent::start();
        if (! is_string($this->token()) || ! preg_match('/^[a-f0-9]{64}$/D', $this->token())) {
            $this->regenerateToken();
        }

return $result;
    }
}
