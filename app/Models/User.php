<?php

namespace App\Models;

class User extends \Illuminate\Foundation\Auth\User
{
    protected $primaryKey = 'user_id';

    public $timestamps = false;

    protected $hidden = ['password_hash'];

    protected $guarded = ['*'];

    public function getAuthPasswordName()
    {
        return 'password_hash';
    }

    public function getRememberTokenName()
    {
        return '';
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
