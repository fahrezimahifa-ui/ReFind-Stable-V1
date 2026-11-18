<?php

namespace Tests\Feature;

use App\Foundation\WriteFailure;
use App\Models\User;
use App\Services\AuthService;
use App\Support\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PDO;

/** Shared real-database helpers for the Phase 6 claim suites (phase6-dev synthetic profile only). */
trait Phase6Harness
{
    protected const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected PDO $admin;

    protected int $studentA;

    protected int $studentB;

    protected int $bm;

    protected array $bms;

    /** Runs $body between an exact snapshot and its verified restoration. */
    protected function withSnapshot(callable $body): void
    {
        $this->assertSame('phase6-dev', Workspace::profile()['profile'], 'Phase 6 suites run only on the disposable profile');
        require_once base_path('tools/AcademicSnapshot.php');
        $this->admin = \AcademicSnapshot::admin();
        $before = hash('sha256', json_encode(\AcademicSnapshot::capture($this->admin), JSON_THROW_ON_ERROR));
        $snapshot = \AcademicSnapshot::save($this->admin);
        try {
            $users = $this->admin->query("SELECT user_id,email,role FROM users WHERE is_active=1 ORDER BY user_id")->fetchAll(PDO::FETCH_ASSOC);
            $byEmail = array_column($users, 'user_id', 'email');
            $this->studentA = (int) $byEmail['student-a@refind-poc.invalid'];
            $this->studentB = (int) $byEmail['student-b@refind-poc.invalid'];
            $this->bm = (int) $byEmail['bm@refind-poc.invalid'];
            $this->bms = array_map('intval', array_column(array_filter($users, fn ($u) => $u['role'] === 'BUILDING_MANAGEMENT'), 'user_id'));
            $body();
        } finally {
            \AcademicSnapshot::restore($this->admin, $snapshot);
            $this->assertSame($before, hash('sha256', json_encode(\AcademicSnapshot::capture($this->admin), JSON_THROW_ON_ERROR)), 'Synthetic rows, media and counters restored exactly');
            unset($this->admin); // PHPUnit retains test objects; release the root session (max-connections is 12).
            \Illuminate\Support\Facades\DB::disconnect();
        }
    }

    protected function asUser(int $id): void
    {
        $user = User::findOrFail($id);
        $this->actingAs($user)->withSession(['_token' => self::TOKEN, 'refind' => [
            'issued_at' => now()->timestamp, 'last_seen' => now()->timestamp,
            'fingerprint' => app(AuthService::class)->fingerprint($user->password_hash),
        ]]);
    }

    protected function item(int $reporter, string $type, string $status = 'OPEN', int $category = 1, ?int $location = 38, string $title = 'Phase six synthetic report', string $description = 'Synthetic phase six description.'): int
    {
        $s = $this->admin->prepare("INSERT INTO items(reporter_user_id,category_id,location_id,status_id,item_type,title,description,event_date,verification_clue) VALUES (?,?,?,(SELECT status_id FROM item_statuses WHERE status_code=?),?,?,?,?, 'Synthetic private clue.')");
        $s->execute([$reporter, $category, $location, $status, $type, $title, $description, gmdate('Y-m-d', time() - 86400)]);

        return (int) $this->admin->lastInsertId();
    }

    protected function claimRow(int $id): array
    {
        $s = $this->admin->prepare('SELECT * FROM claims WHERE claim_id=?');
        $s->execute([$id]);

        return $s->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    protected function itemStatus(int $item): string
    {
        $s = $this->admin->prepare('SELECT s.status_code FROM items i JOIN item_statuses s ON s.status_id=i.status_id WHERE i.item_id=?');
        $s->execute([$item]);

        return (string) $s->fetchColumn();
    }

    protected function rows(string $sql, array $bindings = []): array
    {
        $s = $this->admin->prepare($sql);
        $s->execute($bindings);

        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    protected function maxId(string $table, string $key): int
    {
        return (int) $this->admin->query("SELECT COALESCE(MAX($key),0) FROM $table")->fetchColumn();
    }

    /** Notifications created after a watermark, as [user,type,title,message,item,claim] tuples. */
    protected function notificationsSince(int $after): array
    {
        return array_map(fn ($r) => [(int) $r['user_id'], $r['notification_type'], $r['title'], $r['message'], $r['item_id'] === null ? null : (int) $r['item_id'], $r['claim_id'] === null ? null : (int) $r['claim_id']],
            $this->rows('SELECT * FROM notifications WHERE notification_id>? ORDER BY notification_id', [$after]));
    }

    protected function historySince(int $after): array
    {
        return array_map(fn ($r) => [(int) $r['item_id'], $r['status_code'], $r['note'], (int) $r['changed_by_user_id']],
            $this->rows('SELECT h.*,s.status_code FROM item_status_history h JOIN item_statuses s ON s.status_id=h.status_id WHERE history_id>? ORDER BY history_id', [$after]));
    }

    protected function png(int $w = 40, int $h = 30): UploadedFile
    {
        $path = Workspace::storage().'/uploads/p6-'.bin2hex(random_bytes(8)).'.png';
        Workspace::assertPath($path);
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
        imagepng($im, $path);

        return new UploadedFile($path, 'proof.png', 'image/png', null, true);
    }

    protected function claimMedia(): array
    {
        $files = array_map('basename', glob(Workspace::media().'/claims/*') ?: []);
        sort($files);

        return $files;
    }

    protected function submit(array $data, array $photos = []): TestResponse
    {
        return $this->call('POST', '/api/claims.php', $data, [], $photos ? ['ownershipPhotos' => $photos] : [], ['HTTP_X_CSRF_TOKEN' => self::TOKEN, 'HTTP_ACCEPT' => 'application/json']);
    }

    protected function staffPost(array $data): TestResponse
    {
        return $this->call('POST', '/api/staff.php', $data, [], [], ['HTTP_X_CSRF_TOKEN' => self::TOKEN, 'HTTP_ACCEPT' => 'application/json']);
    }

    protected function serviceRequest(string $uri, array $data, array $photos = []): Request
    {
        return Request::create($uri, 'POST', $data, [], $photos ? ['ownershipPhotos' => $photos] : []);
    }

    /** Returns the operation id of an uncertain outcome; the caught failure is released before returning. */
    protected function uncertain(callable $write): string
    {
        try {
            $write();
        } catch (WriteFailure $error) {
            $this->assertTrue($error->uncertain, 'Outcome must be uncertain');

            return $error->operationId;
        }
        $this->fail('Expected an uncertain outcome.');
    }

    protected function revision(int $claim): string
    {
        $this->asUser($this->bm);

        return (string) $this->getJson('/api/staff.php?resource=claims&id='.$claim)->assertOk()->json('claim.revision');
    }

    /** Rows of every snapshotted table are identical (InnoDB counters may advance on rollback). */
    protected function assertSameRows(array $before, array $after, string $label): void
    {
        foreach ($before['tables'] as $table => $value) {
            $this->assertSame($value['rows'], $after['tables'][$table]['rows'], $label.': '.$table);
        }
    }

    protected function assertError(TestResponse $r, int $status, string $code, ?string $message = null, ?string $field = null): void
    {
        $r->assertStatus($status)->assertJsonPath('ok', false)->assertJsonPath('code', $code);
        if ($message !== null) {
            $r->assertJsonPath('message', $message);
        }
        if ($field !== null) {
            $this->assertArrayHasKey($field, $r->json('fields') ?? [], 'field error '.$field);
        }
    }
}
