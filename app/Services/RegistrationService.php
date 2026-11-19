<?php

namespace App\Services;

use App\Exceptions\ContractError;
use App\Foundation\MediaJournal;
use App\Foundation\WriteContext;
use App\Foundation\WriteCoordinator;
use App\Foundation\WriteFailure;
use App\Models\User;
use App\Support\ClaimStages;
use App\Support\Contract;
use App\Support\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Phase 8 Student self-registration (native api/register.php and app/registration*.php at 47eea86). Available only
 * when the registration stage is enabled and the socket peer is loopback (native registration_local_only); new
 * accounts are always active Students without a student ID or avatar.
 */
final class RegistrationService
{
    public function __construct(private MediaJournal $journal, private WriteCoordinator $writes, private AuthService $auth) {}

    public static function loopback(mixed $address): bool
    {
        if (! is_string($address) || str_contains($address, "\0")) {
            return false;
        }
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 4) {
            return ord($packed[0]) === 127;
        }
        if ($packed === inet_pton('::1')) {
            return true;
        }

        return substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff" && ord($packed[12]) === 127;
    }

    public static function available(): bool
    {
        return ClaimStages::has('registration') && self::loopback(request()->server('REMOTE_ADDR'));
    }

    private function field(string $key, string $message): never
    {
        throw new ContractError($message, 422, 'validation_error', [$key => $message]);
    }

    /** Native registration_attempt: 10 attempts per address per 15 minutes, counted before validation. */
    private function attempt(): void
    {
        $key = hash_hmac('sha256', 'registration-ip|'.request()->server('REMOTE_ADDR', 'local'), Workspace::profile()['key']);
        $file = fopen(Workspace::storage().'/auth/registration-attempts.json', 'c+');
        if (! $file || ! flock($file, LOCK_EX)) {
            throw new RuntimeException('Registration limiter unavailable.');
        }
        try {
            $raw = stream_get_contents($file);
            $buckets = $raw === '' ? [] : json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            if (! is_array($buckets)) {
                throw new RuntimeException('Invalid registration limiter state.');
            }
            $now = now()->timestamp;
            foreach ($buckets as $id => $bucket) {
                if (! is_array($bucket) || ! is_int($bucket['start'] ?? null) || ! is_int($bucket['count'] ?? null) || $bucket['start'] > $now || $bucket['count'] < 1) {
                    throw new RuntimeException('Invalid registration limiter bucket.');
                }
                if ($bucket['start'] + 900 <= $now) {
                    unset($buckets[$id]);
                }
            }
            $bucket = $buckets[$key] ?? ['start' => $now, 'count' => 0];
            if ($bucket['count'] >= 10) {
                throw new ContractError('Too many account creation attempts. Please wait and try again.', 429, 'registration_rate_limited', [], ['Retry-After' => (string) max(1, $bucket['start'] + 900 - $now)]);
            }
            $bucket['count']++;
            $buckets[$key] = $bucket;
            $encoded = json_encode($buckets, JSON_THROW_ON_ERROR);
            if (! rewind($file) || ! ftruncate($file, 0) || fwrite($file, $encoded) !== strlen($encoded) || ! fflush($file)) {
                throw new RuntimeException('Cannot persist registration limiter.');
            }
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    public function register(Request $request): User
    {
        $this->attempt();
        Contract::allowed($request->query(), []);
        Contract::allowed($request->files->all(), []);
        $data = $request->request->all();
        Contract::allowed($data, ['name', 'email', 'password', 'confirm_password', '_csrf']);
        $type = strtolower(trim(explode(';', (string) $request->header('Content-Type', ''))[0]));
        if (! in_array($type, ['multipart/form-data', 'application/x-www-form-urlencoded'], true)) {
            throw new ContractError('Use the account creation form.', 400, 'invalid_request');
        }
        $field = fn (string $k, string $m) => $this->field($k, $m);
        $name = AccountService::profileName($data, $field);
        $email = $data['email'] ?? '';
        if (! is_string($email) || ! mb_check_encoding($email, 'UTF-8')) {
            $this->field('email', 'Enter a valid email address.');
        }
        $email = mb_strtolower(trim($email), 'UTF-8');
        if (strlen($email) > 150 || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->field('email', 'Enter a valid email address of at most 150 characters.');
        }
        $password = AccountService::password($data, 'password', $field, true);
        $confirm = AccountService::password($data, 'confirm_password', $field);
        if (! hash_equals($password, $confirm)) {
            $this->field('confirm_password', 'The password confirmation does not match.');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $conflict = fn () => new ContractError('An account could not be created with these details. Try another email or log in.', 409, 'registration_conflict');
        try {
            $id = $this->writes->run($this->journal->create(), function (WriteContext $context) use ($name, $email, $hash, $conflict) {
                $db = $context->connection;
                if ($db->selectOne('SELECT user_id FROM users WHERE email=? FOR UPDATE', [$email])) {
                    throw $conflict();
                }
                $db->insert("INSERT INTO users(full_name,email,password_hash,role,is_active,student_id,avatar_path) VALUES (?,?,?,'STUDENT',1,NULL,NULL)", [$name, $email, $hash]);

                return (int) $db->getPdo()->lastInsertId();
            });
        } catch (WriteFailure $error) {
            $duplicate = $error->getPrevious() instanceof QueryException && (int) ($error->getPrevious()->errorInfo[1] ?? 0) === 1062;
            if (! $error->uncertain && $duplicate) {
                throw $conflict();
            }
            throw $error;
        }

        return $this->auth->establish(User::findOrFail($id));
    }
}
