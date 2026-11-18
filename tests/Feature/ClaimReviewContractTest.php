<?php

namespace Tests\Feature;

use App\Foundation\WriteFailure;
use App\Services\ClaimReviewService;
use App\Support\ClaimRevision;
use App\Support\Workspace;
use PDO;
use RuntimeException;
use Tests\TestCase;

/** Phase 6D BM request-more-info and rejection against real synthetic phase6-dev data (native 47eea86). */
final class ClaimReviewContractTest extends TestCase
{
    use Phase6Harness;

    private const NOTE = 'Please describe the sticker colour and position.';

    private function submitAs(int $student, int $found, array $extra = [], array $photos = []): int
    {
        $this->asUser($student);
        $r = $this->submit(['itemId' => (string) $found, 'answer' => 'Synthetic identifying detail for review.'] + $extra, $photos);
        $this->assertContains($r->status(), [200, 201], (string) $r->getContent());

        return (int) $r->json('id');
    }

    private function decide(int $claim, string $decision, ?string $revision = null, string $note = self::NOTE)
    {
        $revision ??= $this->revision($claim);
        $this->asUser($this->bm);

        return $this->staffPost(['action' => 'review', 'id' => (string) $claim, 'decision' => $decision, 'note' => $note, 'revision' => $revision, 'confirm' => '1']);
    }

    public function test_request_more_info_reject_contract_and_resubmission_loop(): void
    {
        $this->withSnapshot(function () {
            $this->postJson('/api/staff.php', ['action' => 'review'])->assertUnauthorized();
            $found = $this->item($this->studentB, 'FOUND');
            $claim = $this->submitAs($this->studentA, $found, [], [$this->png()]);
            $fresh = $this->revision($claim);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $fresh);
            $valid = ['action' => 'review', 'id' => (string) $claim, 'decision' => 'need_more_info', 'note' => self::NOTE, 'revision' => $fresh, 'confirm' => '1'];

            // Boundary and native validation order.
            $this->asUser($this->studentA);
            $this->assertError($this->staffPost($valid), 403, 'forbidden');
            $this->asUser($this->bm);
            $this->assertError($this->call('POST', '/api/staff.php', $valid, [], [], ['HTTP_ACCEPT' => 'application/json']), 403, 'csrf_invalid');
            $this->assertError($this->staffPost(['action' => 'publish'] + $valid), 400, 'invalid_request', 'Invalid staff operation.');
            $this->assertError($this->staffPost(['action' => 'status', 'id' => (string) $found, 'status' => 'OPEN', 'note' => self::NOTE, 'confirm' => '1', 'revision' => $fresh]), 501, 'feature_pending');
            $this->assertError($this->staffPost(['extra' => '1'] + $valid), 400, 'invalid_request');
            $this->assertError($this->staffPost(['id' => 'x'] + $valid), 400, 'invalid_id');
            $this->assertError($this->staffPost(['decision' => 'maybe'] + $valid), 422, 'validation_error', null, 'decision');
            $this->assertError($this->staffPost(['note' => 'short'] + $valid), 422, 'validation_error', 'Provide a reason of at least 8 characters.', 'note');
            $this->assertError($this->staffPost(['note' => ''] + $valid), 422, 'validation_error', 'Check the note field.');
            $this->assertError($this->staffPost(['confirm' => 'yes'] + $valid), 422, 'validation_error', 'Confirm this operation explicitly.', 'confirm');
            $this->assertError($this->staffPost(['id' => '999999'] + $valid), 404, 'not_found', 'Claim not found.');
            $stale = 'This record changed. Refresh the detail before deciding again.';
            foreach (['', 'ABC', str_repeat('A', 64), str_repeat('0', 64)] as $bad) {
                $this->assertError($this->staffPost(['revision' => $bad] + $valid), 409, 'state_conflict', $stale);
            }
            $without = $valid;
            unset($without['revision']);
            $this->assertError($this->staffPost($without), 409, 'state_conflict', $stale);
            $this->assertSame('PENDING', $this->claimRow($claim)['claim_status'], 'No change after denials');

            // Request more information: status/note/time recorded, Found stays in review, private notification only.
            $n = $this->maxId('notifications', 'notification_id');
            $h = $this->maxId('item_status_history', 'history_id');
            $this->staffPost($valid)->assertOk()->assertExactJson(['ok' => true, 'id' => (string) $claim, 'status' => 'NEED_MORE_INFO', 'handover_id' => null]);
            $row = $this->claimRow($claim);
            $this->assertSame(['NEED_MORE_INFO', (string) $this->bm, self::NOTE], [$row['claim_status'], (string) $row['reviewed_by_user_id'], $row['review_note']]);
            $this->assertNotNull($row['reviewed_at']);
            $this->assertSame('VERIFICATION', $this->itemStatus($found));
            $this->assertSame([[$found, 'VERIFICATION', 'Ownership review requested additional detail.', $this->bm]], $this->historySince($h));
            $this->assertSame([[$this->studentA, 'CLAIM_NEEDS_INFO', 'More info requested', self::NOTE, $found, $claim]], $this->notificationsSince($n));
            $this->assertStringNotContainsString(self::NOTE, json_encode($this->rows('SELECT note FROM item_status_history')), 'Private review note stays out of public history');
            // Repeated and ineligible decisions.
            $this->assertError($this->staffPost($valid), 409, 'state_conflict', $stale);
            $this->assertError($this->decide($claim, 'need_more_info'), 409, 'state_conflict', 'This claim is no longer eligible for that decision.');
            $this->asUser($this->studentA);
            $this->getJson('/api/claims.php?id='.$claim)->assertOk()->assertJsonPath('claims.0.review_note', self::NOTE)->assertJsonPath('claims.0.can_resubmit', true);

            // Same-second probe: native digest repeats after NEED_MORE_INFO -> PENDING; Laravel's does not.
            $this->admin->exec("UPDATE claims SET updated_at='2030-01-01 00:00:00' WHERE claim_id=$claim");
            $this->admin->exec("UPDATE claims SET claim_status='PENDING',review_note=NULL,reviewed_at=NULL,reviewed_by_user_id=NULL,updated_at='2030-01-01 00:00:00' WHERE claim_id=$claim");
            $pendingNative = ClaimRevision::native($this->claimRow($claim), array_column($this->rows('SELECT claim_photo_id FROM claim_photos WHERE claim_id=? ORDER BY 1', [$claim]), 'claim_photo_id'));
            $pendingToken = $this->revision($claim);
            $this->admin->exec("UPDATE claims SET claim_status='NEED_MORE_INFO',review_note='x',reviewed_at='2030-01-01 00:00:00',updated_at='2030-01-01 00:00:00' WHERE claim_id=$claim");
            $this->asUser($this->studentA);
            $this->submit(['itemId' => (string) $found, 'answer' => 'Synthetic identifying detail for review.'])->assertOk()->assertJsonPath('resubmitted', true);
            $this->admin->exec("UPDATE claims SET updated_at='2030-01-01 00:00:00' WHERE claim_id=$claim");
            $replayNative = ClaimRevision::native($this->claimRow($claim), array_column($this->rows('SELECT claim_photo_id FROM claim_photos WHERE claim_id=? ORDER BY 1', [$claim]), 'claim_photo_id'));
            $this->assertSame($pendingNative, $replayNative, 'Probe: the native digest recreates the earlier PENDING token within one second');
            $this->assertNotSame($pendingToken, $this->revision($claim), 'Laravel digest differs after resubmission');
            $this->assertError($this->decide($claim, 'rejected', $pendingToken), 409, 'state_conflict', $stale);
            $this->assertSame('PENDING', $this->claimRow($claim)['claim_status']);

            // BM detail shows the previous request and resubmission time.
            $this->asUser($this->bm);
            $detail = $this->getJson('/api/staff.php?resource=claims&id='.$claim)->assertOk()->json('claim');
            $this->assertSame(['approved', 'rejected', 'need_more_info'], [...array_intersect(['approved', 'rejected', 'need_more_info'], $detail['decisions'])]);

            // Reject the last active claim: settle to idle, private notification, final for the claimant.
            $n = $this->maxId('notifications', 'notification_id');
            $h = $this->maxId('item_status_history', 'history_id');
            $this->decide($claim, 'rejected')->assertOk()->assertExactJson(['ok' => true, 'id' => (string) $claim, 'status' => 'REJECTED', 'handover_id' => null]);
            $this->assertSame('OPEN', $this->itemStatus($found));
            $this->assertSame([[$found, 'OPEN', 'Ownership claim rejected after manual review.', $this->bm]], $this->historySince($h));
            $this->assertSame([[$this->studentA, 'CLAIM_REJECTED', 'Claim rejected', self::NOTE, $found, $claim]], $this->notificationsSince($n));
            $this->assertError($this->decide($claim, 'rejected'), 409, 'state_conflict', 'This claim is no longer eligible for that decision.');
            $this->asUser($this->studentA);
            $this->assertError($this->submit(['itemId' => (string) $found, 'answer' => 'Synthetic identifying detail for review.']), 409, 'claim_final');
            $this->asUser($this->bm);
            $this->assertSame([], $this->getJson('/api/staff.php?resource=claims&id='.$claim)->json('claim.decisions'));
        });
    }

    public function test_reject_with_competitors_suggestions_and_need_more_info_source(): void
    {
        $this->withSnapshot(function () {
            $this->admin->exec("INSERT INTO users(full_name,email,password_hash,role,is_active) VALUES ('Synthetic Student C','student-c@refind-poc.invalid','x','STUDENT',1)");
            $studentC = (int) $this->admin->lastInsertId();
            // Competing claims: the first rejection keeps the review state.
            $found = $this->item($this->studentB, 'FOUND');
            $a = $this->submitAs($this->studentA, $found);
            $this->admin->exec("INSERT INTO claims(item_id,claimant_user_id,ownership_answer) VALUES ($found,$studentC,'Synthetic competing answer')");
            $h = $this->maxId('item_status_history', 'history_id');
            $this->decide($a, 'rejected')->assertOk();
            $this->assertSame('VERIFICATION', $this->itemStatus($found));
            $this->assertSame([[$found, 'VERIFICATION', 'Ownership claim rejected; other claims remain under review.', $this->bm]], $this->historySince($h));

            // A live suggestion settles the last rejection to Possible match; rejection is allowed from NEED_MORE_INFO.
            $found2 = $this->item($this->studentB, 'FOUND');
            $lost = $this->item($studentC, 'LOST');
            $this->admin->exec("INSERT INTO item_matches(lost_item_id,found_item_id,match_score) VALUES ($lost,$found2,70)");
            $claim = $this->submitAs($this->studentA, $found2);
            $this->decide($claim, 'need_more_info')->assertOk();
            $this->decide($claim, 'rejected')->assertOk()->assertJsonPath('status', 'REJECTED');
            $this->assertSame('POTENTIAL_MATCH', $this->itemStatus($found2));
            $this->assertSame('SUGGESTED', $this->rows('SELECT match_status FROM item_matches WHERE lost_item_id=? AND found_item_id=?', [$lost, $found2])[0]['match_status'], 'Rejection leaves suggestions untouched');
        });
    }

    public function test_review_lock_contention_and_atomic_rollback(): void
    {
        $this->withSnapshot(function () {
            $found = $this->item($this->studentB, 'FOUND');
            $claim = $this->submitAs($this->studentA, $found);
            $revision = $this->revision($claim);

            // Another writer holds the shared workflow lock: bounded wait, native busy 409, nothing written.
            $secret = json_decode(file_get_contents(Workspace::ROOT.'/state/owner-credentials.json'), true);
            $holder = new PDO('mysql:host=127.0.0.1;port=3307;dbname='.Workspace::profile()['database'], 'root', $secret['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->assertSame(1, (int) $holder->query("SELECT GET_LOCK('refind-matching-".Workspace::profile()['database']."',0)")->fetchColumn());
            $started = microtime(true);
            try {
                $this->assertError($this->decide($claim, 'rejected', $revision), 409, 'state_conflict', 'Another workflow is saving. Please retry.');
                $this->assertGreaterThanOrEqual(4.5, microtime(true) - $started, 'bounded five-second wait');
            } finally {
                $holder->query("SELECT RELEASE_LOCK('refind-matching-".Workspace::profile()['database']."')");
                $holder = null;
            }
            $this->assertSame('PENDING', $this->claimRow($claim)['claim_status']);

            // Injected failures after the decision writes roll back status, history and notifications together.
            $service = app(ClaimReviewService::class);
            $bm = ['user_id' => (string) $this->bm, 'role' => 'BUILDING_MANAGEMENT'];
            foreach (['after_work', 'before_commit'] as $point) {
                foreach (['rejected', 'need_more_info'] as $decision) {
                    $before = \AcademicSnapshot::capture($this->admin);
                    try {
                        $service->review($bm, $this->serviceRequest('/api/staff.php', ['action' => 'review', 'id' => (string) $claim, 'decision' => $decision, 'note' => self::NOTE, 'revision' => $revision, 'confirm' => '1']), function (string $current) use ($point) {
                            if ($current === $point) {
                                throw new RuntimeException('Injected review failure.');
                            }
                        });
                        $this->fail('Injected review failure unexpectedly succeeded');
                    } catch (WriteFailure $e) {
                        $this->assertFalse($e->uncertain);
                    }
                    $this->assertSameRows($before, \AcademicSnapshot::capture($this->admin), "$decision rollback at $point");
                }
            }
            $this->decide($claim, 'rejected', $revision)->assertOk();
        });
    }
}
