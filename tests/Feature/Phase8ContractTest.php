<?php

namespace Tests\Feature;

use App\Foundation\WriteFailure;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AuthService;
use App\Services\StaffStatusService;
use App\Support\ClaimStages;
use App\Support\Workspace;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Tests\TestCase;

/** Phase 8 remaining native features against real synthetic phase8-dev data (native 47eea86). */
final class Phase8ContractTest extends TestCase
{
    use Phase6Harness;

    private array $authFiles = [];

    /** Limiter state lives outside the DB snapshot: keep it exactly as found. */
    private function withAuthState(callable $body): void
    {
        $dir = Workspace::storage().'/auth';
        foreach (glob($dir.'/*.json') ?: [] as $file) {
            $this->authFiles[$file] = file_get_contents($file);
        }
        try {
            $body();
        } finally {
            foreach (glob($dir.'/*.json') ?: [] as $file) {
                if (! array_key_exists($file, $this->authFiles)) {
                    unlink($file);
                }
            }
            foreach ($this->authFiles as $file => $content) {
                file_put_contents($file, $content);
            }
        }
    }

    private function stages(string $value, callable $body): void
    {
        $previous = getenv('REFIND_PHASE6_STAGES');
        putenv('REFIND_PHASE6_STAGES='.$value);
        try {
            $body();
        } finally {
            putenv($previous === false ? 'REFIND_PHASE6_STAGES' : 'REFIND_PHASE6_STAGES='.$previous);
        }
    }

    private function send(string $uri, array $data, array $files = [])
    {
        return $this->call('POST', $uri, $data, [], $files, ['HTTP_X_CSRF_TOKEN' => self::TOKEN, 'HTTP_ACCEPT' => 'application/json']);
    }

    private function reportRevision(int $item): string
    {
        $this->asUser($this->bm);

        return (string) $this->getJson('/api/staff.php?resource=reports&id='.$item)->assertOk()->json('report.revision');
    }

    private function setStatus(int $item, string $status, ?string $revision = null, string $note = 'Synthetic operational note.')
    {
        $revision ??= $this->reportRevision($item);
        $this->asUser($this->bm);

        return $this->send('/api/staff.php', ['action' => 'status', 'id' => (string) $item, 'status' => $status, 'note' => $note, 'revision' => $revision, 'confirm' => '1']);
    }

    private function jpeg(int $w = 512, int $h = 512, string $type = 'jpeg'): UploadedFile
    {
        $path = Workspace::storage().'/uploads/p8-'.bin2hex(random_bytes(8)).'.'.($type === 'jpeg' ? 'jpg' : 'png');
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 30, 120, 200));
        $type === 'jpeg' ? imagejpeg($im, $path, 80) : imagepng($im, $path);

        return new UploadedFile($path, 'avatar.'.($type === 'jpeg' ? 'jpg' : 'png'), $type === 'jpeg' ? 'image/jpeg' : 'image/png', null, true);
    }

    private function password(string $key): string
    {
        return json_decode(file_get_contents(Workspace::storage().'/credentials.json'), true)[$key]['password'];
    }

    public function test_bm_report_queue_detail_and_manual_status(): void
    {
        $this->withSnapshot(function () {
            $open = $this->item($this->studentB, 'FOUND', 'OPEN', 1, 38, 'Phase eight lamp');
            $lost = $this->item($this->studentA, 'LOST', 'POTENTIAL_MATCH', 1, 38, 'Phase eight lamp lost');
            $neighbour = $this->item($this->studentB, 'FOUND', 'POTENTIAL_MATCH', 1, 38, 'Phase eight lamp found');
            $this->admin->exec("INSERT INTO item_matches(lost_item_id,found_item_id,match_score) VALUES ($lost,$neighbour,70)");

            $this->asUser($this->studentA);
            $this->getJson('/api/staff.php?resource=reports')->assertForbidden();
            $this->asUser($this->bm);
            $queue = $this->getJson('/api/staff.php?q=Phase+eight+lamp&type=FOUND')->assertOk()->json();
            $this->assertSame(2, $queue['total'], 'default resource is reports, filtered by search and type');
            $row = collect($queue['reports'])->firstWhere('item_id', (string) $open) ?? collect($queue['reports'])->firstWhere('item_id', $open);
            $this->assertSame(['UNDER_REVIEW', 'UNCLAIMED_CLOSED'], $row['allowed_statuses']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $row['revision']);
            $this->assertError($this->getJson('/api/staff.php?resource=reports&status=LOST'), 400, 'validation_error', 'Invalid report state.');
            $this->assertError($this->getJson('/api/staff.php?resource=reports&type=x'), 400, 'validation_error', 'Invalid report type.');
            $detail = $this->getJson('/api/staff.php?resource=reports&id='.$lost)->assertOk()->json();
            $this->assertSame($detail['report'], $detail['reports'][0]);
            $this->assertSame(['OPEN', 'UNDER_REVIEW', 'UNCLAIMED_CLOSED', 'RESOLVED'], $detail['report']['allowed_statuses']);
            $this->assertSame($row['revision'], $this->getJson('/api/staff.php?resource=reports&id='.$open)->json('report.revision'), 'list and detail share the revision');
            $this->get('/admin.html?tab=reports&report='.$open)->assertOk()->assertSee('data-bm-tab="reports"', false);

            // Native validation order.
            $rev = $this->reportRevision($open);
            $this->assertError($this->send('/api/staff.php', ['action' => 'status', 'id' => (string) $open, 'status' => 'UNDER_REVIEW', 'note' => 'short', 'revision' => $rev, 'confirm' => '1']), 422, 'validation_error', 'Provide a reason of at least 8 characters.', 'note');
            $this->assertError($this->send('/api/staff.php', ['action' => 'status', 'id' => (string) $open, 'status' => 'UNDER_REVIEW', 'note' => 'Synthetic operational note.', 'revision' => $rev]), 422, 'validation_error', 'Confirm this operation explicitly.', 'confirm');
            $this->assertError($this->setStatus($open, 'RESOLVED', $rev), 409, 'state_conflict', 'Finish the active claim/handover or choose an eligible transition.');
            $this->assertError($this->setStatus($open, 'UNDER_REVIEW', str_repeat('0', 64)), 409, 'state_conflict', 'This record changed. Refresh the detail before deciding again.');

            // UNDER_REVIEW: history plus one private operational note, no generic status notification.
            $n = $this->maxId('notifications', 'notification_id');
            $h = $this->maxId('item_status_history', 'history_id');
            $this->setStatus($open, 'under_review', $rev, 'Checking the desk log for this lamp.')->assertOk()->assertExactJson(['ok' => true, 'id' => (string) $open, 'status' => 'UNDER_REVIEW']);
            $this->assertSame([[$open, 'UNDER_REVIEW', 'Operational status updated by Building Management.', $this->bm]], $this->historySince($h));
            $this->assertSame([[$this->studentB, 'OPERATIONAL_NOTE', 'Report updated: Under review', 'Checking the desk log for this lamp.', $open, null]], $this->notificationsSince($n));
            $this->assertError($this->setStatus($open, 'UNDER_REVIEW', $rev), 409, 'state_conflict', 'This record changed. Refresh the detail before deciding again.');

            // RESOLVED on a Lost report: suggestions removed and the neighbour settles to Reported.
            $h = $this->maxId('item_status_history', 'history_id');
            $this->setStatus($lost, 'RESOLVED')->assertOk()->assertJsonPath('status', 'RESOLVED');
            $this->assertSame(['RESOLVED', 'OPEN'], [$this->itemStatus($lost), $this->itemStatus($neighbour)]);
            $this->assertSame([], $this->rows('SELECT match_id FROM item_matches WHERE lost_item_id=?', [$lost]));
            $this->assertCount(1, $this->rows('SELECT item_id FROM items WHERE item_id=? AND resolved_at IS NOT NULL', [$lost]));
            $this->assertSame([[$lost, 'RESOLVED', 'Building Management recorded recovery of this Lost report.', $this->bm], [$neighbour, 'OPEN', 'No current matching suggestions.', $this->bm]], $this->historySince($h));
            $this->assertSame([], $this->getJson('/api/staff.php?resource=reports&id='.$lost)->json('report.allowed_statuses'), 'final state offers nothing');

            // A report with an active claim is blocked.
            $claimed = $this->item($this->studentB, 'FOUND');
            $this->admin->exec("INSERT INTO claims(item_id,claimant_user_id,ownership_answer) VALUES ($claimed,{$this->studentA},'Synthetic claim answer')");
            $this->assertSame([], $this->getJson('/api/staff.php?resource=reports&id='.$claimed)->json('report.allowed_statuses'));
            $this->assertError($this->setStatus($claimed, 'UNCLAIMED_CLOSED'), 409, 'state_conflict');

            // Atomic rollback for manual status.
            $service = app(StaffStatusService::class);
            $before = \AcademicSnapshot::capture($this->admin);
            try {
                $service->mutate(['user_id' => (string) $this->bm, 'role' => 'BUILDING_MANAGEMENT'], $this->serviceRequest('/api/staff.php', ['action' => 'status', 'id' => (string) $open, 'status' => 'UNCLAIMED_CLOSED', 'note' => 'Synthetic operational note.', 'revision' => $this->reportRevision($open), 'confirm' => '1']), function (string $point) {
                    if ($point === 'before_commit') {
                        throw new RuntimeException('Injected status failure.');
                    }
                });
                $this->fail('Injected status failure unexpectedly succeeded');
            } catch (WriteFailure $e) {
                $this->assertFalse($e->uncertain);
            }
            $this->assertSameRows($before, \AcademicSnapshot::capture($this->admin), 'manual status rollback');

            $this->stages('student,review,approval,pickup', function () use ($open) {
                $this->asUser($this->bm);
                $this->getJson('/api/staff.php?resource=reports')->assertStatus(501);
                $this->assertError($this->send('/api/staff.php', ['action' => 'status', 'id' => (string) $open, 'status' => 'OPEN', 'note' => 'Synthetic operational note.', 'revision' => str_repeat('a', 64), 'confirm' => '1']), 501, 'feature_pending');
                $this->get('/admin.html?tab=reports')->assertStatus(501);
            });
        });
    }

    public function test_notification_read_marking(): void
    {
        $this->withSnapshot(function () {
            $this->postJson('/api/notifications.php', ['action' => 'read_all'])->assertUnauthorized();
            foreach (range(1, 3) as $i) {
                $this->admin->exec("INSERT INTO notifications(user_id,notification_type,title,message) VALUES ({$this->studentA},'STATUS_CHANGED','Synthetic $i','Synthetic message $i')");
            }
            $mine = (int) $this->admin->lastInsertId();
            $this->admin->exec("INSERT INTO notifications(user_id,notification_type,title,message) VALUES ({$this->studentB},'STATUS_CHANGED','Foreign','Foreign message')");
            $foreign = (int) $this->admin->lastInsertId();
            $this->asUser($this->studentA);
            $unread = $this->getJson('/api/notifications.php?count=1')->json('unread_count');
            $this->assertError($this->call('POST', '/api/notifications.php', ['id' => (string) $mine], [], [], ['HTTP_ACCEPT' => 'application/json']), 403, 'csrf_invalid');
            $this->send('/api/notifications.php', ['id' => (string) $mine])->assertOk()->assertExactJson(['ok' => true, 'unread_count' => $unread - 1]);
            $this->assertSame('1', (string) $this->rows('SELECT is_read FROM notifications WHERE notification_id=?', [$mine])[0]['is_read']);
            $this->assertError($this->send('/api/notifications.php', ['id' => (string) $foreign]), 404, 'not_found', 'Notification not found.');
            $this->assertSame('0', (string) $this->rows('SELECT is_read FROM notifications WHERE notification_id=?', [$foreign])[0]['is_read'], 'another user\'s notification untouched');
            $this->assertError($this->send('/api/notifications.php', ['action' => 'delete']), 400, 'invalid_request', 'Invalid notification action.');
            $this->assertError($this->send('/api/notifications.php', ['id' => '1', 'extra' => 'x']), 400, 'invalid_request');
            $this->send('/api/notifications.php', ['action' => 'read_all'])->assertOk()->assertExactJson(['ok' => true, 'unread_count' => 0]);
            $this->assertSame(0, $this->getJson('/api/notifications.php?count=1')->json('unread_count'));
            $this->get('/notifications.html')->assertOk()->assertSee('data-notifications-read-only="false"', false);
            $this->stages('student,review,approval,pickup', function () {
                $this->asUser($this->studentA);
                $this->assertError($this->send('/api/notifications.php', ['action' => 'read_all']), 501, 'feature_pending');
                $this->get('/notifications.html')->assertOk()->assertSee('data-notifications-read-only="true"', false);
            });
        });
    }

    public function test_account_profile_password_and_avatar(): void
    {
        $this->withAuthState(fn () => $this->withSnapshot(function () {
            $this->getJson('/api/account.php')->assertUnauthorized();
            $this->asUser($this->studentA);
            $profile = $this->getJson('/api/account.php')->assertOk()->json('profile');
            $this->assertSame([(string) $this->studentA, 'student-a@refind-poc.invalid', 'STUDENT'], [$profile['id'], $profile['email'], $profile['role']]);
            $this->assertArrayHasKey('student_id', $profile);
            $this->assertError($this->getJson('/api/account.php?x=1'), 400, 'invalid_request');
            $this->get('/profile.html')->assertOk();
            $this->get('/settings.html')->assertOk();

            // Display name.
            foreach (['', str_repeat('x', 121), "Bad\x07name"] as $bad) {
                $this->assertError($this->send('/api/account.php', ['action' => 'update_profile', 'name' => $bad]), 422, 'validation_error', null, 'name');
            }
            $this->assertError($this->send('/api/account.php', ['action' => 'update_profile', 'name' => 'Ok', 'role' => 'BUILDING_MANAGEMENT']), 400, 'invalid_request');
            $this->assertError($this->send('/api/account.php', ['action' => 'promote']), 400, 'invalid_request', 'Invalid account action.');
            $r = $this->send('/api/account.php', ['action' => 'update_profile', 'name' => '  Synthetic Renamed Student  '])->assertOk();
            $this->assertSame(['Synthetic Renamed Student', 'Synthetic Renamed Student', 'Your display name has been saved.'], [$r->json('profile.name'), $r->json('user.name'), $r->json('message')]);
            $this->assertSame('Synthetic Renamed Student', $this->rows('SELECT full_name FROM users WHERE user_id=?', [$this->studentA])[0]['full_name']);
            $this->assertSame('STUDENT', $this->rows('SELECT role FROM users WHERE user_id=?', [$this->studentA])[0]['role']);

            // Avatar: prepared 512px JPEG only; replacement and removal clean up after commit.
            $this->assertError($this->send('/api/account.php', ['action' => 'update_avatar'], ['avatar' => $this->jpeg(400, 400)]), 422, 'validation_error', 'Prepare a square 512px profile picture.', 'avatar');
            $this->assertError($this->send('/api/account.php', ['action' => 'update_avatar'], ['avatar' => $this->jpeg(512, 512, 'png')]), 422, 'validation_error', 'Prepare a JPEG photo no larger than 250 KB.', 'avatar');
            $this->assertError($this->send('/api/account.php', ['action' => 'update_avatar']), 422, 'validation_error', 'Choose a complete photo upload.', 'avatar');
            $old = $this->rows('SELECT avatar_path FROM users WHERE user_id=?', [$this->studentA])[0]['avatar_path'];
            $r = $this->send('/api/account.php', ['action' => 'update_avatar'], ['avatar' => $this->jpeg()])->assertOk()->assertJsonPath('message', 'Your profile picture has been saved.');
            $path = $this->rows('SELECT avatar_path FROM users WHERE user_id=?', [$this->studentA])[0]['avatar_path'];
            $this->assertMatchesRegularExpression('~^avatars/[a-f0-9]{32}\.jpg$~', $path);
            $this->assertFileExists(Workspace::media().'/'.$path);
            if ($old) {
                $this->assertFileDoesNotExist(Workspace::media().'/'.$old, 'replaced avatar removed after commit');
            }
            $this->get($r->json('profile.avatar_url'))->assertOk()->assertHeader('Content-Type', 'image/jpeg');
            $service = app(AccountService::class);
            $before = \AcademicSnapshot::capture($this->admin);
            try {
                $service->mutate(User::findOrFail($this->studentA), \Illuminate\Http\Request::create('/api/account.php', 'POST', ['action' => 'update_avatar'], [], ['avatar' => $this->jpeg()]), function (string $point) {
                    if ($point === 'after_promote') {
                        throw new RuntimeException('Injected avatar failure.');
                    }
                });
                $this->fail('Injected avatar failure unexpectedly succeeded');
            } catch (WriteFailure $e) {
                $this->assertFalse($e->uncertain);
            }
            $after = \AcademicSnapshot::capture($this->admin);
            $this->assertSameRows($before, $after, 'avatar rollback rows');
            $this->assertSame($before['media'], $after['media'], 'avatar rollback media compensated');
            $this->send('/api/account.php', ['action' => 'remove_avatar'])->assertOk()->assertJsonPath('profile.avatar_url', null);
            $this->assertFileDoesNotExist(Workspace::media().'/'.$path);

            // Password: native validation, limiter-backed verification, rotation of every other session.
            $current = $this->password('student_a');
            $new = 'Synthetic new password 2026';
            $this->assertError($this->send('/api/account.php', ['action' => 'change_password', 'current_password' => '', 'new_password' => $new, 'confirm_password' => $new]), 422, 'validation_error', 'Enter your current password.', 'current_password');
            $this->assertError($this->send('/api/account.php', ['action' => 'change_password', 'current_password' => $current, 'new_password' => 'short', 'confirm_password' => 'short']), 422, 'validation_error', 'Use at least 10 characters for the new password.', 'new_password');
            $this->assertError($this->send('/api/account.php', ['action' => 'change_password', 'current_password' => $current, 'new_password' => $new, 'confirm_password' => $new.'x']), 422, 'validation_error', 'The password confirmation does not match.', 'confirm_password');
            $this->assertError($this->send('/api/account.php', ['action' => 'change_password', 'current_password' => 'not the password', 'new_password' => $new, 'confirm_password' => $new]), 422, 'current_password_invalid', 'The current password could not be verified.', 'current_password');
            $this->assertError($this->send('/api/account.php', ['action' => 'change_password', 'current_password' => $current, 'new_password' => $current, 'confirm_password' => $current]), 422, 'validation_error', 'Choose a password different from the current password.', 'new_password');
            $oldHash = $this->rows('SELECT password_hash FROM users WHERE user_id=?', [$this->studentA])[0]['password_hash'];
            $r = $this->send('/api/account.php', ['action' => 'change_password', 'current_password' => $current, 'new_password' => $new, 'confirm_password' => $new])->assertOk();
            $this->assertSame('Password updated. Other sessions will need to log in again.', $r->json('message'));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $r->json('csrf'));
            $this->assertNotSame(self::TOKEN, $r->json('csrf'), 'CSRF token rotated');
            $hash = $this->rows('SELECT password_hash FROM users WHERE user_id=?', [$this->studentA])[0]['password_hash'];
            $this->assertTrue(password_verify($new, $hash));
            // Another session still bound to the old credential is signed out.
            $this->actingAs(User::findOrFail($this->studentA))->withSession(['_token' => self::TOKEN, 'refind' => ['issued_at' => now()->timestamp, 'last_seen' => now()->timestamp, 'fingerprint' => app(AuthService::class)->fingerprint($oldHash)]]);
            $this->getJson('/api/account.php')->assertUnauthorized();
            // A credential changed elsewhere invalidates this session at the locked recheck.
            $this->asUser($this->studentA);
            $this->admin->exec("UPDATE users SET password_hash='".password_hash('changed elsewhere 123', PASSWORD_DEFAULT)."' WHERE user_id={$this->studentA}");
            $this->assertError($this->send('/api/account.php', ['action' => 'update_profile', 'name' => 'Should not apply']), 401, 'authentication_required');
            $this->assertSame('Synthetic Renamed Student', $this->rows('SELECT full_name FROM users WHERE user_id=?', [$this->studentA])[0]['full_name']);

            $this->stages('student,review,approval,pickup', function () {
                $this->asUser($this->studentB);
                $this->getJson('/api/account.php')->assertStatus(501);
                $this->get('/profile.html')->assertStatus(501);
            });
        }));
    }

    public function test_student_self_registration(): void
    {
        $this->withAuthState(fn () => $this->withSnapshot(function () {
            $guest = fn () => $this->withSession(['_token' => self::TOKEN]);
            $guest();
            $this->getJson('/api/session.php')->assertOk()->assertJsonPath('registration_enabled', true);
            $this->get('/register.html')->assertOk()->assertSee('data-page="register"', false);
            $valid = ['name' => 'Synthetic New Student', 'email' => '  New.Student@REFIND-poc.invalid ', 'password' => 'Synthetic pass 12345', 'confirm_password' => 'Synthetic pass 12345'];

            $this->assertError($this->call('POST', '/api/register.php', $valid, [], [], ['HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '10.20.30.40']), 403, 'registration_disabled');
            $guest();
            $this->assertError($this->call('POST', '/api/register.php', $valid, [], [], ['HTTP_ACCEPT' => 'application/json']), 403, 'csrf_invalid');
            $this->assertError($this->send('/api/register.php', ['role' => 'BUILDING_MANAGEMENT'] + $valid), 400, 'invalid_request');
            $this->assertError($this->send('/api/register.php', ['email' => 'not-an-email'] + $valid), 422, 'validation_error', null, 'email');
            $this->assertError($this->send('/api/register.php', ['password' => 'short', 'confirm_password' => 'short'] + $valid), 422, 'validation_error', 'Use at least 10 characters for the new password.', 'password');
            $this->assertError($this->send('/api/register.php', ['confirm_password' => 'different value 1'] + $valid), 422, 'validation_error', 'The password confirmation does not match.', 'confirm_password');
            $this->assertError($this->call('POST', '/api/register.php', [], [], [], ['HTTP_X_CSRF_TOKEN' => self::TOKEN, 'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], json_encode($valid)), 400, 'invalid_request', 'Use the account creation form.');

            $r = $this->send('/api/register.php', $valid)->assertCreated();
            $this->assertSame([true, 'STUDENT', '/index.html', 'Synthetic New Student'], [$r->json('authenticated'), $r->json('user.role'), $r->json('redirect'), $r->json('user.name')]);
            $row = $this->rows("SELECT * FROM users WHERE email='new.student@refind-poc.invalid'")[0];
            $this->assertSame(['STUDENT', '1', null, null], [$row['role'], (string) $row['is_active'], $row['student_id'], $row['avatar_path']]);
            $this->assertTrue(password_verify('Synthetic pass 12345', $row['password_hash']));
            $this->getJson('/api/session.php')->assertJsonPath('authenticated', true)->assertJsonPath('user.id', (string) $row['user_id']);
            $this->assertError($this->send('/api/register.php', $valid), 409, 'already_authenticated');
            $this->get('/register.html')->assertRedirect('/index.html');

            // Duplicate (case-insensitive) email after logging out.
            $this->app['auth']->guard('web')->logout();
            $guest();
            $this->assertError($this->send('/api/register.php', ['email' => 'NEW.student@refind-poc.invalid'] + $valid), 409, 'registration_conflict');
            $this->assertCount(1, $this->rows("SELECT user_id FROM users WHERE email='new.student@refind-poc.invalid'"));

            // Address limiter: 10 attempts per 15 minutes.
            $file = Workspace::storage().'/auth/registration-attempts.json';
            $key = hash_hmac('sha256', 'registration-ip|127.0.0.1', Workspace::profile()['key']);
            file_put_contents($file, json_encode([$key => ['start' => time(), 'count' => 10]]));
            $guest();
            $this->assertError($this->send('/api/register.php', ['email' => 'another@refind-poc.invalid'] + $valid), 429, 'registration_rate_limited');

            $this->stages('student,review,approval,pickup,account', function () use ($valid, $guest) {
                $guest();
                $this->getJson('/api/session.php')->assertJsonPath('registration_enabled', false);
                $this->assertError($this->send('/api/register.php', $valid), 403, 'registration_disabled');
                $this->get('/register.html')->assertRedirect('/login.html');
            });
            $this->assertSame([], ClaimStages::forProfile('academic-test', 'registration,account,notifications,status,history'));
        }));
    }

    public function test_history_page_is_stage_gated(): void
    {
        $this->withSnapshot(function () {
            $this->get('/history.html')->assertRedirect();
            $this->asUser($this->studentA);
            $this->get('/history.html')->assertOk()->assertSee('data-page="history"', false);
            $this->getJson('/api/items.php?closed=1&status=RESOLVED')->assertOk();
            $this->stages('student,review,approval,pickup', function () {
                $this->asUser($this->studentA);
                $this->get('/history.html')->assertStatus(501);
            });
        });
    }
}
