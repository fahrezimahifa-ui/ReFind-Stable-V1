<?php

namespace App\Policies;

use App\Models\User;

final class MediaPolicy
{
    public function privateEvidence(User $user): bool
    {
        return $user->role === 'BUILDING_MANAGEMENT';
    }
}
