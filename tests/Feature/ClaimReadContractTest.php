<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AuthService;
use App\Support\ClaimStages;
use App\Support\ReportQueryCounter;
use App\Support\Workspace;
use Illuminate\Testing\TestResponse;
use PDO;
use Tests\TestCase;

final class ClaimReadContractTest extends TestCase
{
    private const TOKEN = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function test_claim_reads_bounded_role_scoped_and_read_only(): void
    {
        $this->getJson('/api/claims.php?unsupported=1')->assertUnauthorized();
        $this->getJson('/api/staff.php?resource=claims&id=bad')->assertUnauthorized();
        $this->postJson('/api/claims.php', ['action' => 'withdraw'])->assertUnauthorized();
        $this->get('/my-reports.html?tab=claims&claim=1')->assertRedirect('/login.html?return=%2Fmy-reports.html%3Ftab%3Dclaims%26claim%3D1');

        // Runs unchanged on accepted academic-test (writes gated) and on the disposable phase6-dev profile.
        $this->assertContains(Workspace::profile()['profile'], ['academic-test', 'phase6-dev', 'phase7-dev']);
        require_once base_path('tools/AcademicSnapshot.php');
        $admin = \AcademicSnapshot::admin();
        $before = hash('sha256', json_encode(\AcademicSnapshot::capture($admin), JSON_THROW_ON_ERROR));
        $snapshot = \AcademicSnapshot::save($admin);
        try {
            $fixture = $this->seedClaimReadFixtures($admin);
            config(['refind.query_metrics' => true]);
            $this->studentReads($fixture);
            $this->staffReads($admin, $fixture);
            $this->studentReadsWithNoClaims($fixture);
        } finally {
            \AcademicSnapshot::restore($admin, $snapshot);
            $this->assertSame($before, hash('sha256', json_encode(\AcademicSnapshot::capture($admin), JSON_THROW_ON_ERROR)), 'Synthetic rows, media, and auto-increment restored');
        }
    }

    private function asUser(int $id): void
    {
        $user = User::findOrFail($id);
        $this->actingAs($user)->withSession(['_token' => self::TOKEN, 'refind' => [
            'issued_at' => now()->timestamp, 'last_seen' => now()->timestamp,
            'fingerprint' => app(AuthService::class)->fingerprint($user->password_hash),
        ]]);
    }

    private function measured(string $url, int $selects): TestResponse
    {
        // Use the application's existing QueryExecuted listener. actingAs supplies the authenticated user.
        $counter = app(ReportQueryCounter::class);
        $before = $counter->count;
        $response = $this->getJson($url);
        $response->assertOk();
        $this->assertSame($selects, $counter->count - $before, $url);

        return $response;
    }

    private function studentReads(array $fixture): void
    {
        $this->asUser(1);
        $claims = $fixture['claims'];
        foreach ([1, 24, 48] as $size) {
            $page = $this->measured('/api/claims.php?limit='.$size, 4);
            $page->assertJsonCount($size, 'claims')->assertJsonPath('page', 1);
            $this->assertGreaterThanOrEqual(50, $page->json('total'));
            $this->assertSame(array_map('strval', array_slice(array_reverse($claims), 0, $size)), array_column($page->json('claims'), 'claim_id'));
        }
        $this->measured('/api/claims.php?page=2&limit=24', 4)->assertJsonPath('page', 2)->assertJsonPath('claims.0.claim_id', (string) $claims[25]);
        $this->measured('/api/claims.php?page=100000', 2)->assertJsonPath('page', 100000)->assertJsonCount(0, 'claims');
        $this->measured('/api/claims.php?itemId='.$fixture['item'], 4)->assertJsonCount(1, 'claims');
        $this->measured('/api/claims.php?itemId=4294967295', 2)->assertJsonPath('pages', 1)->assertJsonPath('total', 0)->assertJsonCount(0, 'claims');
        $result = $this->getJson('/api/claims.php?id='.$claims[0])->assertOk()
            ->assertJsonPath('claims.0.claim_id', (string) $claims[0])
            ->assertJsonPath('claims.0.item_id', (string) $fixture['item'])
            ->assertJsonPath('claims.0.related_lost_item_id', (string) $fixture['lost'])
            ->assertJsonPath('claims.0.claimant_user_id', '1')
            ->assertJsonPath('claims.0.claim_status', 'WITHDRAWN')
            ->assertJsonPath('claims.0.ownership_photo_count', 1)
            ->assertJsonPath('claims.0.ownership_photo_ids.0', $fixture['proof'])
            ->assertJsonPath('claims.0.updated_at', '2030-01-02T03:04:05Z')
            ->assertJsonPath('claims.0.can_resubmit', true)
            ->assertJsonPath('claims.0.can_withdraw', false);
        $this->assertEmpty(array_intersect(array_keys($result->json('claims.0')), [
            'ownership_photos', 'file_path', 'claimant_email', 'claimant_student_id', 'verification_clue', 'revision',
        ]));
        $this->getJson('/api/claims.php?id='.$fixture['other'])->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->getJson('/api/claims.php?id=4294967295')->assertNotFound();
        $this->getJson('/api/claims.php?id='.$claims[0].'&itemId=4294967295')->assertNotFound();
        $this->getJson('/api/claims.php?id[]=1')->assertBadRequest();
        $this->getJson('/api/claims.php?limit=49')->assertBadRequest();
        $this->getJson('/api/claims.php?extra=1')->assertBadRequest();
        $this->getJson('/api/staff.php?resource=claims&id=bad')->assertForbidden();
        $this->get('/api/photos.php?kind=claim&id='.$fixture['proof'])->assertForbidden();
        $this->get('/my-reports.html?tab=claims&claim='.$claims[0])->assertOk()->assertSee('data-activity="claims"', false);
        $this->get('/admin.html?tab=claims&claim='.$claims[0])->assertForbidden();
        $this->get('/claim.html?id='.$claims[0])->assertStatus(ClaimStages::has('student') ? 200 : 501);
        $this->postJson('/api/claims.php', ['action' => 'withdraw'])->assertForbidden()->assertJsonPath('code', 'csrf_invalid');
        $this->postJson('/api/claims.php', ['action' => 'withdraw'], ['X-CSRF-Token' => self::TOKEN])->assertStatus(ClaimStages::has('student') ? 400 : 501)->assertJsonPath('code', ClaimStages::has('student') ? 'invalid_id' : 'feature_pending');
    }

    private function staffReads(PDO $admin, array $fixture): void
    {
        $this->asUser(3);
        $id = $fixture['claims'][49];
        foreach ([1, 24, 48] as $size) {
            $result = $this->measured('/api/staff.php?resource=claims&q=6B+synthetic+Found&limit='.$size, 3);
            $result->assertJsonPath('total', 49)->assertJsonCount($size, 'claims')->assertJsonPath('claims.0.claim_id', (string) $id);
            foreach ($result->json('claims') as $summary) {
                $this->assertContains($summary['claim_status'], ['PENDING', 'NEED_MORE_INFO']);
                $this->assertEmpty(array_intersect(array_keys($summary), ['ownership_answer', 'claimant_email', 'file_path', 'claimant_avatar_path']));
            }
        }
        $empty = $this->measured('/api/staff.php?resource=claims&q=6B_no_such_fixture', 3);
        $empty->assertJsonPath('total', 0)->assertJsonCount(0, 'claims')->assertJsonPath('pages', 1);
        $this->assertGreaterThanOrEqual(49, $empty->json('counts.PENDING'));
        $this->getJson('/api/staff.php?resource=claims&q=6B+synthetic+Found&status=NEED_MORE_INFO')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('claims.0.claim_status', 'NEED_MORE_INFO');
        $this->getJson('/api/staff.php?resource=claims&status=WITHDRAWN')
            ->assertOk()->assertJsonPath('claims.0.claim_id', (string) $fixture['claims'][0]);
        $this->getJson('/api/staff.php?resource=claims&status=pending')->assertBadRequest();
        $this->getJson('/api/staff.php?resource[]=claims')->assertBadRequest();
        $this->getJson('/api/staff.php?resource=reports')->assertStatus(501);
        $this->getJson('/api/staff.php?resource=claims&id=4294967295')->assertNotFound();

        $path = '/api/staff.php?resource=claims&id=';
        $terminal = $this->getJson($path.$fixture['claims'][0].'&status=invalid&page=0')->assertOk()
            ->assertJsonPath('claim.claim_status', 'WITHDRAWN')->assertJsonPath('claim.decisions', [])
            ->assertJsonPath('claim.item.verification_clue', 'Synthetic private detail')
            ->assertJsonPath('claim.lost.item_id', (string) $fixture['lost'])
            ->assertJsonPath('claim.ownership_photos', ['/api/photos.php?kind=claim&id='.$fixture['proof']]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $terminal->json('claim.revision'));
        $this->assertStringNotContainsString($fixture['photoPath'], $terminal->getContent());
        $this->assertStringEndsWith('@refind-poc.invalid', $terminal->json('claim.claimant_email'));
        $this->get('/api/photos.php?kind=claim&id='.$fixture['proof'])->assertOk()->assertHeader('Content-Type', 'image/png');

        $detail = $this->getJson($path.$id)->assertOk()
            ->assertJsonPath('claim.revision', $this->getJson($path.$id)->json('claim.revision'))
            ->assertJsonPath('claim.previous_request.note', 'Synthetic latest claimant note')
            ->assertJsonPath('claim.previous_request.at', '2030-01-02T03:04:05Z')
            ->assertJsonPath('claim.resubmitted_at', '2030-01-02T03:04:05Z')
            ->assertJsonPath('claim.decisions', ['rejected', 'approved', 'need_more_info']);
        $revision = $detail->json('claim.revision');
        $s = $admin->prepare("UPDATE claims SET ownership_answer=?, updated_at='2030-01-02 03:04:05' WHERE claim_id=?");
        $s->execute(['Synthetic revised claim answer', $id]);
        $state = $this->getJson($path.$id)->assertOk()->json('claim.revision');
        $this->assertNotSame($revision, $state);
        $admin->prepare('INSERT INTO claim_photos (claim_id,file_path) VALUES (?,?)')->execute([$id, $fixture['photoPath']]);
        $evidence = $this->getJson($path.$id)->assertOk()->assertJsonPath('claim.resubmitted_at', '2030-01-02T03:04:05Z')->json('claim.revision');
        $this->assertNotSame($state, $evidence);
        $this->getJson($path.$id)->assertOk()->assertJsonPath('claim.revision', $evidence);

        $page = $this->get('/admin.html?tab=claims&claim='.$fixture['claims'][0])->assertOk();
        ClaimStages::decisions() ? $page->assertSee('id="operation-form"', false) : $page->assertDontSee('id="operation-form"', false);
        $this->get('/admin.html?tab=reports')->assertStatus(501);
        $this->getJson('/api/handovers.php')->assertStatus(ClaimStages::has('pickup') ? 200 : 501);
        $this->postJson('/api/staff.php', ['action' => 'review'])->assertForbidden()->assertJsonPath('code', 'csrf_invalid');
        $this->postJson('/api/staff.php', ['action' => 'review'], ['X-CSRF-Token' => self::TOKEN])->assertStatus(ClaimStages::decisions() ? 400 : 501);
        $this->postJson('/api/handovers.php', ['action' => 'schedule'], ['X-CSRF-Token' => self::TOKEN])->assertStatus(ClaimStages::has('pickup') ? 400 : 501);
    }

    private function studentReadsWithNoClaims(array $fixture): void
    {
        $this->asUser(2);
        $this->measured('/api/claims.php?itemId='.$fixture['item'], 2)->assertJsonPath('total', 0)->assertJsonCount(0, 'claims');
        $this->getJson('/api/claims.php?id='.$fixture['claims'][0])->assertNotFound();
    }

    private function seedClaimReadFixtures(PDO $admin): array
    {
        $report = $admin->prepare("INSERT INTO items (reporter_user_id,category_id,item_type,title,description,verification_clue,event_date) VALUES (?,1,?,?, 'Synthetic report detail','Synthetic private detail','2030-01-02')");
        $report->execute([1, 'LOST', '6B synthetic Lost']);
        $lost = (int) $admin->lastInsertId();
        $insert = $admin->prepare("INSERT INTO claims (item_id,related_lost_item_id,claimant_user_id,ownership_answer,claim_status,review_note,created_at,updated_at) VALUES (?,?,?,'Synthetic claim answer',?,?,'2030-01-02 03:04:05','2030-01-02 03:04:05')");
        $claims = [];
        for ($i = 0; $i < 50; $i++) {
            $report->execute([2, 'FOUND', '6B synthetic Found '.$i]);
            $found = (int) $admin->lastInsertId();
            if ($i === 0) {
                $item = $found;
            }
            $status = match ($i) {
                0 => 'WITHDRAWN',
                1 => 'NEED_MORE_INFO',
                default => 'PENDING',
            };
            $insert->execute([$found, $i === 0 ? $lost : null, 1, $status, $i === 0 ? 'Synthetic terminal note' : null]);
            $claims[] = (int) $admin->lastInsertId();
        }
        $report->execute([1, 'FOUND', '6B foreign ownership sample']);
        $insert->execute([(int) $admin->lastInsertId(), null, 2, 'PENDING', null]);
        $other = (int) $admin->lastInsertId();

        $photoPath = 'claims/'.bin2hex(random_bytes(16)).'.png';
        $file = Workspace::media().'/'.$photoPath;
        Workspace::assertPath($file);
        $image = imagecreatetruecolor(12, 12);
        $this->assertTrue(imagepng($image, $file));
        $admin->prepare('INSERT INTO claim_photos (claim_id,file_path) VALUES (?,?)')->execute([$claims[0], $photoPath]);
        $proof = (int) $admin->lastInsertId();

        $notification = $admin->prepare("INSERT INTO notifications (user_id,claim_id,notification_type,title,message,created_at) VALUES (?,?,'CLAIM_NEEDS_INFO','Synthetic request',?,'2030-01-02 03:04:05')");
        $notification->execute([1, $claims[49], 'Synthetic older claimant note']);
        $notification->execute([1, $claims[49], 'Synthetic latest claimant note']);
        $notification->execute([2, $claims[49], 'Synthetic other recipient note']);

        return compact('claims', 'item', 'lost', 'other', 'photoPath', 'proof');
    }
}
