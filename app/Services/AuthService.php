<?php

namespace App\Services;

use App\Exceptions\ContractError;
use App\Models\User;
use App\Support\Workspace;
use Illuminate\Support\Facades\Auth;

final class AuthService
{
    public function fingerprint(string $hash): string
    {
        return hash_hmac('sha256', $hash, Workspace::profile()['key']);
    }

    public function current(): ?User
    {
        $user = Auth::guard('web')->user();
        if (! $user) {
            return null;
        }$s = request()->session();
        $meta = $s->get('refind');
        $now = now()->timestamp;
        if (! is_array($meta) || ! is_int($meta['issued_at'] ?? null) || ! is_int($meta['last_seen'] ?? null) || ! is_string($meta['fingerprint'] ?? null) || $now - $meta['issued_at'] >= 28800 || $now - $meta['last_seen'] >= 1800 || ! $user->is_active || ! hash_equals($this->fingerprint($user->password_hash), $meta['fingerprint'])) {
            $this->logout();

            return null;
        }
        $s->put('refind.last_seen', $now);

        return $user;
    }

    public function require(?string $role = null): User
    {
        $user = $this->current();
        if (! $user) {
            throw new ContractError('Please log in to continue.', 401, 'authentication_required');
        }if ($role && $user->role !== $role) {
            throw new ContractError('This action is not available to your role.', 403, 'forbidden');
        }

return $user;
    }

    public function identity(User $u): array
    {
        return ['id' => (string) $u->user_id, 'name' => $u->full_name, 'role' => $u->role, 'avatar_url' => $this->avatar($u->avatar_path)];
    }

    public function avatar(?string $path): ?string
    {
        return $path && preg_match('~^avatars/([a-f0-9]{32})\.jpg$~D', $path, $m) ? '/api/avatar.php?token='.$m[1] : null;
    }

    public function login(string $email, string $password): User
    {
        $u = app(LoginLimiter::class)->attempt($email, (string) request()->server('REMOTE_ADDR', 'local'), function () use ($email, $password) {
            $u = filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 150 ? User::where('email', $email)->first() : null;
            $ok = password_verify($password, $u?->password_hash ?? Workspace::profile()['dummy_hash']);

            return $u && $u->is_active && $password !== '' && $ok ? $u : null;
        });
        Auth::guard('web')->login($u, false);
        request()->session()->regenerate(true);
        $now = now()->timestamp;
        request()->session()->put('refind', ['issued_at' => $now, 'last_seen' => $now, 'fingerprint' => $this->fingerprint($u->password_hash)]);

        return $u;
    }

    public function logout(): void
    {
        Auth::guard('web')->logout();
        request()->session()->invalidate();
        request()->session()->regenerateToken();
    }
}
