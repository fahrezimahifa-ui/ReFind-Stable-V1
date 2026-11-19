<?php

namespace App\Services;

use App\Exceptions\ContractError;
use App\Foundation\MediaJournal;
use App\Foundation\WriteContext;
use App\Foundation\WriteCoordinator;
use App\Models\User;
use App\Support\Contract;
use App\Support\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

/**
 * Phase 8 account self-service (native api/account.php, app/accounts.php and app/avatars.php at 47eea86):
 * display name, password change with credential rotation, and a prepared 512px JPEG profile picture. Writes run
 * under the coordinator with the account row locked and the session fingerprint rechecked.
 */
final class AccountService
{
    private const MESSAGES = ['update_profile' => 'Your display name has been saved.', 'change_password' => 'Password updated. Other sessions will need to log in again.', 'update_avatar' => 'Your profile picture has been saved.', 'remove_avatar' => 'Your profile picture has been removed.'];

    public function __construct(private MediaJournal $journal, private WriteCoordinator $writes, private AuthService $auth) {}

    private function field(string $key, string $message, string $code = 'validation_error'): never
    {
        throw new ContractError($message, 422, $code, [$key => $message]);
    }

    public function profile(array $user): array
    {
        return ['id' => (string) $user['user_id'], 'name' => $user['full_name'], 'email' => $user['email'],
            'student_id' => $user['role'] === 'STUDENT' ? $user['student_id'] : null, 'role' => $user['role'], 'avatar_url' => $this->auth->avatar($user['avatar_path'] ?? null)];
    }

    public static function profileName(array $data, callable $field): string
    {
        $name = $data['name'] ?? '';
        if (! is_string($name) || ! mb_check_encoding($name, 'UTF-8')) {
            $field('name', 'Enter a valid display name.');
        }
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            $field('name', 'Use a display name between 1 and 120 characters, without control characters.');
        }

        return $name;
    }

    /** Passwords are never trimmed; at most 72 bytes (bcrypt), new passwords at least 10 characters. */
    public static function password(array $data, string $key, callable $field, bool $new = false): string
    {
        $password = $data[$key] ?? '';
        if ($password === '') {
            $field($key, str_contains($key, 'current') ? 'Enter your current password.' : (str_contains($key, 'confirm') ? 'Repeat the new password.' : 'Enter a new password.'));
        }
        if (! is_string($password) || ! mb_check_encoding($password, 'UTF-8') || str_contains($password, "\0") || strlen($password) > 72) {
            $field($key, 'This password is too long. Use at most 72 bytes; emoji and accented letters count as more than one.');
        }
        if ($new && mb_strlen($password) < 10) {
            $field($key, 'Use at least 10 characters for the new password.');
        }

        return $password;
    }

    public function mutate(User $user, Request $request, ?callable $fault = null, ?callable $commitTransport = null): array
    {
        $data = $request->request->all();
        $action = Contract::text($data, 'action', 30);
        $field = fn (string $k, string $m) => $this->field($k, $m);
        if ($action !== 'update_avatar') {
            Contract::allowed($request->files->all(), []);
        }
        $name = $current = $new = $avatar = null;
        if ($action === 'update_profile') {
            Contract::allowed($data, ['action', 'name', '_csrf']);
            $name = self::profileName($data, $field);
        } elseif ($action === 'change_password') {
            Contract::allowed($data, ['action', 'current_password', 'new_password', 'confirm_password', '_csrf']);
            $current = self::password($data, 'current_password', $field);
            $new = self::password($data, 'new_password', $field, true);
            $confirm = self::password($data, 'confirm_password', $field);
            if (! hash_equals($new, $confirm)) {
                $this->field('confirm_password', 'The password confirmation does not match.');
            }
        } elseif (in_array($action, ['update_avatar', 'remove_avatar'], true)) {
            Contract::allowed($data, ['action', '_csrf']);
            $avatar = $action === 'update_avatar' ? $this->preparedAvatar($request) : null;
        } else {
            throw new ContractError('Invalid account action.', 400, 'invalid_request');
        }
        $operation = $this->journal->create();
        $started = false;
        try {
            $staged = $avatar !== null ? $operation->stage($avatar, 'avatars', 'jpg') : null;
            $started = true;
            $record = $this->writes->run($operation, function (WriteContext $context) use ($user, $action, $name, $current, $new, $staged, $operation) {
                $db = $context->connection;
                $row = $db->selectOne('SELECT user_id,full_name,email,student_id,role,is_active,password_hash,avatar_path FROM users WHERE user_id=? FOR UPDATE', [(int) $user->user_id]);
                $record = $row ? (array) $row : null;
                $meta = request()->session()->get('refind');
                if (! $record || ! (int) $record['is_active'] || ! is_string($meta['fingerprint'] ?? null) || ! hash_equals($meta['fingerprint'], $this->auth->fingerprint($record['password_hash']))) {
                    throw new ContractError('Your account session changed. Please log in again.', 401, 'authentication_required');
                }
                if ($action === 'update_profile') {
                    $db->update('UPDATE users SET full_name=? WHERE user_id=?', [$name, (int) $record['user_id']]);
                    $record['full_name'] = $name;
                } elseif ($action === 'change_password') {
                    $this->verifyCurrent($record, $current);
                    if (hash_equals($current, $new)) {
                        $this->field('new_password', 'Choose a password different from the current password.');
                    }
                    $record['password_hash'] = password_hash($new, PASSWORD_DEFAULT);
                    $db->update('UPDATE users SET password_hash=? WHERE user_id=?', [$record['password_hash'], (int) $record['user_id']]);
                } else {
                    if ($record['avatar_path']) {
                        $operation->removeAfterCommit($record['avatar_path']);
                    }
                    $db->update('UPDATE users SET avatar_path=? WHERE user_id=?', [$staged, (int) $record['user_id']]);
                    $record['avatar_path'] = $staged;
                }

                return $record;
            }, $fault, $commitTransport);
        } catch (Throwable $error) {
            if (! $started) {
                try {
                    $this->writes->reconcile($operation);
                } catch (Throwable) {/* Preserve the pending manifest when recovery cannot be proved. */
                }
            }
            if ($error instanceof ContractError && $error->status === 401 && $error->errorCode === 'authentication_required') {
                $this->auth->logout();
            }
            throw $error;
        }
        if ($action === 'change_password') {
            // Rotate the credential binding: this session continues, every other session fails its fingerprint check.
            $session = request()->session();
            $session->put('refind.fingerprint', $this->auth->fingerprint($record['password_hash']));
            $session->put('refind.last_seen', now()->timestamp);
            $session->regenerate(true);
        }
        $record['user_id'] = (string) $record['user_id'];

        return ['profile' => $this->profile($record), 'user' => ['id' => $record['user_id'], 'name' => $record['full_name'], 'role' => $record['role'], 'avatar_url' => $this->auth->avatar($record['avatar_path'])],
            'csrf' => request()->session()->token(), 'message' => self::MESSAGES[$action]];
    }

    /** Native prepared_avatar: a 512x512 JPEG of at most 250 KB, re-encoded server side. */
    private function preparedAvatar(Request $request): string
    {
        Contract::allowed($request->files->all(), ['avatar']);
        $file = $request->file('avatar');
        $prefix = Workspace::norm(Workspace::storage().'/uploads').'/';
        if (! $file instanceof UploadedFile || ! $file->isValid() || ! str_starts_with(Workspace::norm((string) realpath($file->getRealPath())), $prefix)) {
            $this->field('avatar', 'Choose a complete photo upload.');
        }
        $path = $file->getRealPath();
        if (filesize($path) > 250000 || (new \finfo(FILEINFO_MIME_TYPE))->file($path) !== 'image/jpeg') {
            $this->field('avatar', 'Prepare a JPEG photo no larger than 250 KB.');
        }
        $size = @getimagesize($path);
        if (! $size || $size[0] !== 512 || $size[1] !== 512 || $size['mime'] !== 'image/jpeg') {
            $this->field('avatar', 'Prepare a square 512px profile picture.');
        }
        $source = @imagecreatefromstring((string) file_get_contents($path));
        if (! $source) {
            $this->field('avatar', 'This photo could not be decoded. Choose another image.');
        }
        ob_start();
        $ok = imagejpeg($source, null, 85);
        $bytes = ob_get_clean();
        imagedestroy($source);
        if (! $ok || ! is_string($bytes) || strlen($bytes) > 250000) {
            $this->field('avatar', 'This photo could not be prepared. Choose another image.');
        }

        return $bytes;
    }

    /** Native verify_current_password: per account+address limiter (5 per 15 minutes) persisted across rollback. */
    private function verifyCurrent(array $record, string $password): void
    {
        $key = hash_hmac('sha256', 'password-change|'.$record['user_id'].'|'.request()->server('REMOTE_ADDR', 'local'), Workspace::profile()['key']);
        $file = fopen(Workspace::storage().'/auth/password-attempts.json', 'c+');
        if (! $file || ! flock($file, LOCK_EX)) {
            throw new RuntimeException('Password verification limiter unavailable.');
        }
        try {
            $raw = stream_get_contents($file);
            $buckets = $raw === '' ? [] : json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($buckets)) {
                throw new RuntimeException('Invalid password verification limiter.');
            }
            $now = now()->timestamp;
            foreach ($buckets as $id => $bucket) {
                if (($bucket['start'] ?? 0) + 900 <= $now) {
                    unset($buckets[$id]);
                }
            }
            if (($buckets[$key]['count'] ?? 0) >= 5) {
                throw new ContractError('Too many password verification attempts. Please wait before trying again.', 429, 'password_rate_limited', [], ['Retry-After' => (string) max(1, $buckets[$key]['start'] + 900 - $now)]);
            }
            $valid = password_verify($password, $record['password_hash']);
            if ($valid) {
                unset($buckets[$key]);
            } else {
                $buckets[$key] ??= ['start' => $now, 'count' => 0];
                $buckets[$key]['count']++;
            }
            rewind($file);
            ftruncate($file, 0);
            if (fwrite($file, json_encode($buckets, JSON_THROW_ON_ERROR)) === false || ! fflush($file)) {
                throw new RuntimeException('Cannot persist password limiter.');
            }
            if (! $valid) {
                $this->field('current_password', 'The current password could not be verified.', 'current_password_invalid');
            }
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
