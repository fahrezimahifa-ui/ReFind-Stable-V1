<?php

namespace Tests\Feature;

use App\Exceptions\ContractError;
use App\Foundation\MediaJournal;
use App\Foundation\WriteCoordinator;
use App\Foundation\WriteFailure;
use App\Services\ClaimService;
use App\Support\ClaimStages;
use App\Support\Workspace;
use Illuminate\Database\Events\QueryExecuted;
use RuntimeException;
use Tests\TestCase;

/** Phase 6C Student claim commands against real synthetic phase6-dev data (native 47eea86 contract). */
final class ClaimWriteContractTest extends TestCase
{
    use Phase6Harness;

    public function test_write_gates_fail_closed_outside_phase6_dev(): void
    {
        foreach (['academic-dev', 'academic-test', 'academic-b', ''] as $profile) {
            foreach ([false, null, 'student,review,approval', 'student', 'approval'] as $override) {
                $this->assertSame([], ClaimStages::forProfile($profile, $override), "$profile must never enable claim writes");
            }
        }
        $this->assertSame(['student', 'review', 'approval'], ClaimStages::forProfile('phase6-dev', false));
        $this->assertSame(ClaimStages::ALL, ClaimStages::forProfile('phase7-dev', false));
        $this->assertSame(['review'], ClaimStages::forProfile('phase6-dev', 'review'));
        $this->assertSame([], ClaimStages::forProfile('phase6-dev', 'pickup'), 'pickup can never be enabled on phase6-dev');
        $this->assertSame([], ClaimStages::forProfile('phase7-dev', ''));
        $this->assertSame(['approval', 'pickup'], ClaimStages::forProfile('phase7-dev', 'bogus, approval,pickup'));
        // The unaccepted checkout cannot even boot an accepted profile, and vice versa.
        $this->assertFalse(Workspace::permitted('academic-test'));
        $this->assertFalse(Workspace::permitted('academic-dev'));
        $this->assertTrue(Workspace::permitted(Workspace::profile()['profile']));
        $this->assertTrue(Workspace::permitted('academic-test', 'C:/ReFind-Laravel/laravel'));
        $this->assertFalse(Workspace::permitted('phase6-dev', 'C:/ReFind-Laravel/laravel'));
        $this->assertTrue(Workspace::permitted('phase6-dev', 'C:/ReFind-Laravel/laravel-p6'));
        $this->assertFalse(Workspace::permitted('phase7-dev', 'C:/ReFind-Laravel/laravel-p6'));
        $this->assertFalse(Workspace::permitted('phase7-dev', 'C:/ReFind-Laravel/laravel'));
        $this->assertFalse(Workspace::permitted('unknown-profile'));

        $this->withSnapshot(function () {
            $found = $this->item($this->studentB, 'FOUND');
            $previous = getenv('REFIND_PHASE6_STAGES');
            putenv('REFIND_PHASE6_STAGES=none'); // matches no stage on every platform
            try {
                $this->asUser($this->studentA);
                $this->assertError($this->submit(['action' => 'submit', 'itemId' => (string) $found, 'answer' => 'Blue sticker on the lid.']), 501, 'feature_pending', 'This operation is not available in the proof of concept.');
                $this->assertError($this->submit(['action' => 'withdraw', 'itemId' => (string) $found]), 501, 'feature_pending');
                $this->get('/claim.html?id='.$found)->assertStatus(501);
                $this->asUser($this->bm);
                $this->assertError($this->staffPost(['action' => 'review', 'id' => '1', 'decision' => 'rejected', 'note' => 'Synthetic gate note', 'revision' => str_repeat('a', 64), 'confirm' => '1']), 501, 'feature_pending');
                putenv('REFIND_PHASE6_STAGES=student');
                $this->assertError($this->staffPost(['action' => 'review', 'id' => '1', 'decision' => 'rejected', 'note' => 'Synthetic gate note', 'revision' => str_repeat('a', 64), 'confirm' => '1']), 501, 'feature_pending');
                putenv('REFIND_PHASE6_STAGES=student,review');
                $this->assertError($this->staffPost(['action' => 'review', 'id' => '1', 'decision' => 'approved', 'note' => 'Synthetic gate note', 'revision' => str_repeat('a', 64), 'confirm' => '1']), 501, 'feature_pending');
            } finally {
                putenv($previous === false ? 'REFIND_PHASE6_STAGES' : 'REFIND_PHASE6_STAGES='.$previous);
            }
            $this->assertSame(0, (int) $this->admin->query('SELECT COUNT(*) FROM claims WHERE item_id='.$found)->fetchColumn(), 'Gated requests write nothing');
        });
    }

    public function test_student_submit_resubmit_withdraw_contract(): void
    {
        $this->withSnapshot(function () {
            $found = $this->item($this->studentB, 'FOUND', 'OPEN', 1, 38, 'Grey wireless keyboard');
            $other = $this->item($this->studentB, 'FOUND', 'OPEN', 1, 38, 'Second keyboard sample');
            $lost = $this->item($this->studentA, 'LOST', 'OPEN', 1, 38, 'Grey keyboard lost');
            $foreignLost = $this->item($this->studentB, 'LOST');
            $ownFound = $this->item($this->studentA, 'FOUND');
            $closed = $this->item($this->studentB, 'FOUND', 'UNCLAIMED_CLOSED');
            $answer = 'Scratched space bar and a blue sticker under it.';

            // Boundary: guest, wrong role, CSRF precede everything.
            $this->postJson('/api/claims.php', ['itemId' => (string) $found])->assertUnauthorized();
            $this->asUser($this->bm);
            $this->assertError($this->submit(['itemId' => (string) $found, 'answer' => $answer]), 403, 'forbidden');
            $this->asUser($this->studentA);
            $this->assertError($this->call('POST', '/api/claims.php', ['itemId' => (string) $found, 'answer' => $answer], [], [], ['HTTP_ACCEPT' => 'application/json']), 403, 'csrf_invalid');

            // Native validation order and codes.
            $this->assertError($this->submit(['itemId' => (string) $found, 'lostItemId' => (string) $foreignLost, 'answer' => $answer]), 403, 'forbidden', 'Choose one of your own Lost reports.');
            $this->assertError($this->submit(['itemId' => (string) $found, 'lostItemId' => '999999', 'answer' => $answer]), 404, 'not_found', 'Report not found.');
            $this->assertError($this->submit(['itemId' => 'abc', 'answer' => $answer]), 400, 'invalid_id');
            $this->assertError($this->submit(['itemId' => '999999', 'answer' => $answer]), 404, 'not_found');
            $this->assertError($this->submit(['itemId' => (string) $ownFound, 'answer' => $answer]), 409, 'state_conflict', 'You cannot claim this report.');
            $this->assertError($this->submit(['itemId' => (string) $lost, 'answer' => $answer]), 409, 'state_conflict', 'You cannot claim this report.');
            $this->assertError($this->submit(['itemId' => (string) $found, 'answer' => $answer, 'claimant_user_id' => '2']), 400, 'invalid_request');
            $this->assertError($this->submit(['action' => 'update', 'itemId' => (string) $found, 'answer' => $answer]), 400, 'validation_error', 'Invalid claim action.');
            $this->assertError($this->submit(['itemId' => (string) $found]), 422, 'validation_error', 'Check the answer field.');
            $this->assertError($this->submit(['itemId' => (string) $found, 'answer' => 'short']), 422, 'validation_error', null, 'answer');
            $this->assertError($this->submit(['itemId' => (string) $found, 'answer' => $answer], [$this->png(), $this->png(), $this->png()]), 422, 'validation_error', 'Use at most 2 photos.', 'ownershipPhotos');
            $this->assertError($this->submit(['itemId' => (string) $found, 'answer' => $answer], [$this->png(1300, 20)]), 422, 'validation_error', null, 'ownershipPhotos');
            $this->assertError($this->submit(['itemId' => (string) $closed, 'answer' => $answer]), 409, 'state_conflict', 'This item is no longer eligible for your claim.');
            $this->assertError($this->submit(['action' => 'resubmit', 'itemId' => (string) $found, 'answer' => $answer]), 404, 'not_found', 'Claim to update not found.');
            $this->assertError($this->submit(['itemId' => (string) $found, 'answer' => $answer, 'keep_photo_ids' => '[1]']), 403, 'forbidden', 'A retained photo does not belong to your claim.');
            $this->assertSame([], $this->rows('SELECT claim_id FROM claims WHERE item_id IN (?,?)', [$found, $closed]), 'No writes after denials');
            $mediaBefore = $this->claimMedia();

            // Submit with an own Lost link and one private photo.
            $n = $this->maxId('notifications', 'notification_id');
            $h = $this->maxId('item_status_history', 'history_id');
            $r = $this->submit(['action' => 'submit', 'itemId' => (string) $found, 'lostItemId' => (string) $lost, 'answer' => '  '.$answer.'  ', 'keep_photo_ids' => '[]'], [$this->png()]);
            $r->assertCreated()->assertExactJson(['ok' => true, 'id' => $r->json('id'), 'resubmitted' => false]);
            $claim = (int) $r->json('id');
            $row = $this->claimRow($claim);
            $this->assertSame(['PENDING', $answer, (string) $lost, (string) $this->studentA, null, null, null], [$row['claim_status'], $row['ownership_answer'], (string) $row['related_lost_item_id'], (string) $row['claimant_user_id'], $row['reviewed_by_user_id'], $row['review_note'], $row['reviewed_at']]);
            $this->assertSame('VERIFICATION', $this->itemStatus($found));
            $this->assertSame('OPEN', $this->itemStatus($lost), 'Linked Lost status is untouched by submit');
            $expected = [[$this->studentB, 'STATUS_CHANGED', 'Claim review', 'Your report is now: Claim review.', $found, null], [$this->studentA, 'CLAIM_SUBMITTED', 'Claim submitted', 'Your identifying detail was sent to Building Management for manual review.', $found, $claim]];
            foreach ($this->bms as $staff) {
                $expected[] = [$staff, 'CLAIM_QUEUED', 'New claim to review', 'An ownership claim is waiting for review.', $found, $claim];
            }
            $this->assertSame($expected, $this->notificationsSince($n));
            $this->assertSame([[$found, 'VERIFICATION', 'Ownership claims are awaiting manual review by Building Management.', $this->studentA]], $this->historySince($h));
            $photos = $this->rows('SELECT claim_photo_id,file_path FROM claim_photos WHERE claim_id=?', [$claim]);
            $this->assertCount(1, $photos);
            $this->assertMatchesRegularExpression('~^claims/[a-f0-9]{32}\.png$~', $photos[0]['file_path']);
            $this->assertFileExists(Workspace::media().'/'.$photos[0]['file_path']);
            $this->assertSame(count($mediaBefore) + 1, count($this->claimMedia()));

            // Private evidence: claimant sees IDs/counts only, BM receives bytes.
            $mine = $this->getJson('/api/claims.php?id='.$claim)->assertOk()->json('claims.0');
            $this->assertSame([1, [(int) $photos[0]['claim_photo_id']]], [$mine['ownership_photo_count'], $mine['ownership_photo_ids']]);
            $this->assertArrayNotHasKey('ownership_photos', $mine);
            $this->assertTrue($mine['can_withdraw']);
            $this->getJson('/api/photos.php?kind=claim&id='.$photos[0]['claim_photo_id'])->assertForbidden();
            $this->asUser($this->bm);
            $this->get('/api/photos.php?kind=claim&id='.$photos[0]['claim_photo_id'])->assertOk()->assertHeader('Content-Type', 'image/png');
            $this->asUser($this->studentB);
            $this->getJson('/api/claims.php?id='.$claim)->assertNotFound();

            // Duplicates and the one-Lost-per-active-claim rule.
            $this->asUser($this->studentA);
            $this->assertError($this->submit(['itemId' => (string) $found, 'answer' => $answer]), 409, 'duplicate_claim', 'You already have an active or approved claim for this item.');
            $this->assertError($this->submit(['itemId' => (string) $other, 'lostItemId' => (string) $lost, 'answer' => $answer]), 422, 'validation_error', null, 'lostItemId');

            // Withdraw settles the idle state without notifications and keeps evidence.
            $n = $this->maxId('notifications', 'notification_id');
            $h = $this->maxId('item_status_history', 'history_id');
            $this->submit(['action' => 'withdraw', 'itemId' => (string) $found])->assertOk()->assertExactJson(['ok' => true, 'id' => (string) $claim, 'status' => 'WITHDRAWN', 'resubmitted' => false]);
            $this->assertSame('WITHDRAWN', $this->claimRow($claim)['claim_status']);
            $this->assertSame('OPEN', $this->itemStatus($found));
            $this->assertSame([], $this->notificationsSince($n));
            $this->assertSame([[$found, 'OPEN', 'Ownership claim withdrawn by the claimant.', $this->studentA]], $this->historySince($h));
            $this->assertCount(1, $this->rows('SELECT claim_photo_id FROM claim_photos WHERE claim_id=?', [$claim]));
            $this->assertError($this->submit(['action' => 'withdraw', 'itemId' => (string) $found]), 409, 'state_conflict', 'Only a claim that is still under review can be withdrawn.');
            $this->assertError($this->submit(['action' => 'withdraw', 'itemId' => (string) $other]), 404, 'not_found', 'Claim not found.');
            $this->assertError($this->submit(['action' => 'withdraw', 'itemId' => '999999']), 409, 'state_conflict');
            $this->assertError($this->submit(['action' => 'withdraw', 'itemId' => (string) $found, 'answer' => 'x']), 400, 'invalid_request');

            // Submit on a WITHDRAWN row resubmits the same record and retains omitted evidence.
            $n = $this->maxId('notifications', 'notification_id');
            $this->submit(['action' => 'submit', 'itemId' => (string) $found, 'answer' => $answer.' Again.'])->assertOk()->assertExactJson(['ok' => true, 'id' => (string) $claim, 'resubmitted' => true]);
            $this->assertSame([null, 'PENDING'], [$this->claimRow($claim)['related_lost_item_id'], $this->claimRow($claim)['claim_status']]);
            $this->assertCount(1, $this->rows('SELECT claim_photo_id FROM claim_photos WHERE claim_id=?', [$claim]));
            $titles = array_map(fn ($x) => [$x[1], $x[2]], $this->notificationsSince($n));
            $this->assertSame(['STATUS_CHANGED', 'Claim review'], $titles[0]);
            $this->assertSame(['CLAIM_SUBMITTED', 'Claim resubmitted'], $titles[1]);
            $this->assertSame(array_fill(0, count($this->bms), ['CLAIM_QUEUED', 'Claim resubmitted for review']), array_slice($titles, 2));

            // NEED_MORE_INFO resubmission: replace evidence; old file removed only after commit; reviewer fields reset.
            $this->admin->exec("UPDATE claims SET claim_status='NEED_MORE_INFO',reviewed_by_user_id={$this->bm},review_note='Synthetic request for detail',reviewed_at=UTC_TIMESTAMP() WHERE claim_id=$claim");
            $oldPhoto = $this->rows('SELECT claim_photo_id,file_path FROM claim_photos WHERE claim_id=?', [$claim])[0];
            $this->assertError($this->submit(['itemId' => (string) $found, 'answer' => $answer, 'keep_photo_ids' => 'not-json']), 422, 'validation_error', 'Invalid retained-photo selection.', 'keep_photo_ids');
            $this->assertError($this->submit(['itemId' => (string) $found, 'answer' => $answer, 'keep_photo_ids' => json_encode([$oldPhoto['claim_photo_id'], $oldPhoto['claim_photo_id']])]), 422, 'validation_error', 'Do not select the same photo twice.', 'keep_photo_ids');
            $this->assertError($this->submit(['itemId' => (string) $found, 'answer' => $answer], [$this->png(), $this->png()]), 422, 'validation_error', 'Use at most 2 ownership photos including retained evidence.', 'ownershipPhotos');
            $this->assertFileExists(Workspace::media().'/'.$oldPhoto['file_path'], 'Rejected resubmission keeps prior evidence');
            $h = $this->maxId('item_status_history', 'history_id');
            $this->submit(['action' => 'resubmit', 'itemId' => (string) $found, 'lostItemId' => (string) $lost, 'answer' => $answer, 'keep_photo_ids' => '[]'], [$this->png(), $this->png()])->assertOk()->assertJsonPath('resubmitted', true);
            $row = $this->claimRow($claim);
            $this->assertSame(['PENDING', null, null, null, (string) $lost], [$row['claim_status'], $row['reviewed_by_user_id'], $row['review_note'], $row['reviewed_at'], (string) $row['related_lost_item_id']]);
            $new = $this->rows('SELECT file_path FROM claim_photos WHERE claim_id=? ORDER BY claim_photo_id', [$claim]);
            $this->assertCount(2, $new);
            $this->assertFileDoesNotExist(Workspace::media().'/'.$oldPhoto['file_path'], 'Replaced evidence removed after commit');
            $this->assertSame([[$found, 'VERIFICATION', 'Ownership claim updated for review.', $this->studentA]], array_slice($this->historySince($h), -1), 'Already in review: history note only');

            // REJECTED is final for this claimant/item.
            $this->admin->exec("UPDATE claims SET claim_status='REJECTED' WHERE claim_id=$claim");
            $this->assertError($this->submit(['itemId' => (string) $found, 'answer' => $answer]), 409, 'claim_final', 'Your claim for this item was rejected. A rejection is final for this item.');
            $this->assertError($this->submit(['action' => 'withdraw', 'itemId' => (string) $found]), 409, 'state_conflict');

            // Withdraw with another active claim keeps the review state and records a generic note.
            $second = $this->item($this->studentB, 'FOUND');
            $this->admin->exec("INSERT INTO users(full_name,email,password_hash,role,is_active) VALUES ('Synthetic Student C','student-c@refind-poc.invalid','x','STUDENT',1)");
            $studentC = (int) $this->admin->lastInsertId();
            $this->submit(['itemId' => (string) $second, 'answer' => $answer])->assertCreated();
            $this->admin->exec("INSERT INTO claims(item_id,claimant_user_id,ownership_answer) VALUES ($second,$studentC,'Synthetic competing answer')");
            $h = $this->maxId('item_status_history', 'history_id');
            $this->submit(['action' => 'withdraw', 'itemId' => (string) $second])->assertOk();
            $this->assertSame('VERIFICATION', $this->itemStatus($second));
            $this->assertSame([[$second, 'VERIFICATION', 'Ownership claim withdrawn; other claims remain under review.', $this->studentA]], $this->historySince($h));
        });
    }

    public function test_student_write_rollback_and_uncertain_recovery(): void
    {
        $this->withSnapshot(function () {
            $found = $this->item($this->studentB, 'FOUND');
            $service = app(ClaimService::class);
            $actor = ['user_id' => (string) $this->studentA, 'role' => 'STUDENT'];
            $data = ['action' => 'submit', 'itemId' => (string) $found, 'answer' => 'Synthetic rollback detail text.'];

            foreach (['after_begin', 'after_work', 'after_promote', 'before_commit'] as $point) {
                $before = \AcademicSnapshot::capture($this->admin);
                try {
                    $service->mutate($actor, $this->serviceRequest('/api/claims.php', $data, [$this->png()]), function (string $current) use ($point) {
                        if ($current === $point) {
                            throw new RuntimeException('Injected claim failure at '.$point);
                        }
                    });
                    $this->fail('Injected failure unexpectedly succeeded at '.$point);
                } catch (WriteFailure $e) {
                    $this->assertFalse($e->uncertain, $point.' rolled back');
                    $this->assertSame(500, $e->status);
                }
                $after = \AcademicSnapshot::capture($this->admin);
                $this->assertSameRows($before, $after, $point.' rows unchanged');
                $this->assertSame($before['media'], $after['media'], $point.' staged media compensated');
            }

            // SQL failure on the claimant notification after claim/photo/status writes: nothing persists.
            $armed = true;
            app('events')->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$armed) {
                if ($armed && str_starts_with(strtolower($event->sql), 'insert into notifications')) {
                    $armed = false;
                    throw new RuntimeException('Injected notification insert failure.');
                }
            });
            $before = \AcademicSnapshot::capture($this->admin);
            try {
                $service->mutate($actor, $this->serviceRequest('/api/claims.php', $data, [$this->png()]));
                $this->fail('Notification failure unexpectedly succeeded');
            } catch (WriteFailure $e) {
                $this->assertFalse($e->uncertain);
            }
            $after = \AcademicSnapshot::capture($this->admin);
            $this->assertSameRows($before, $after, 'claim, photo, status, history all rolled back');
            $this->assertSame($before['media'], $after['media']);

            // Locked validation failure (ContractError) after staging: rollback and compensated media, status kept.
            $this->admin->exec("INSERT INTO claims(item_id,claimant_user_id,ownership_answer,claim_status) VALUES ($found,{$this->studentA},'Synthetic prior rejected','REJECTED')");
            $before = \AcademicSnapshot::capture($this->admin);
            try {
                $service->mutate($actor, $this->serviceRequest('/api/claims.php', $data, [$this->png()]));
                $this->fail('Final claim unexpectedly accepted');
            } catch (ContractError $e) {
                $this->assertSame([409, 'claim_final'], [$e->status, $e->errorCode]);
            }
            $this->assertSame($before['media'], \AcademicSnapshot::capture($this->admin)['media'], 'Staged proof compensated after locked denial');
            $this->admin->exec("DELETE FROM claims WHERE item_id=$found");

            // Lost commit acknowledgement: outcome unknown, reconcile proves commit, no duplicate effects.
            $n = $this->maxId('notifications', 'notification_id');
            $operationId = $this->uncertain(fn () => $service->mutate($actor, $this->serviceRequest('/api/claims.php', $data, [$this->png()]), null, function ($connection) {
                $connection->commit();
                throw new RuntimeException('Lost commit acknowledgement.');
            }));
            $operation = app(MediaJournal::class)->load($operationId);
            $this->assertSame('outcome_unknown', $operation->snapshot()['state']);
            $this->assertSame('committed', app(WriteCoordinator::class)->reconcile($operation));
            unset($operation);
            $claims = $this->rows('SELECT claim_id FROM claims WHERE item_id=?', [$found]);
            $this->assertCount(1, $claims, 'exactly one committed claim');
            $this->assertCount(1, $this->rows("SELECT notification_id FROM notifications WHERE notification_id>? AND notification_type='CLAIM_SUBMITTED'", [$n]));
            $photo = $this->rows('SELECT file_path FROM claim_photos WHERE claim_id=?', [$claims[0]['claim_id']]);
            $this->assertCount(1, $photo);
            $this->assertFileExists(Workspace::media().'/'.$photo[0]['file_path']);

            // Real connection loss mid-work: uncertain, never retried, reconciles to rollback with compensated media.
            $this->admin->exec("UPDATE claims SET claim_status='WITHDRAWN' WHERE item_id=$found");
            $before = \AcademicSnapshot::capture($this->admin);
            $db = \Illuminate\Support\Facades\DB::connection();
            $operationId = $this->uncertain(fn () => $service->mutate($actor, $this->serviceRequest('/api/claims.php', $data + ['keep_photo_ids' => '[]'], [$this->png()]), function (string $point) use ($db) {
                if ($point === 'after_work') {
                    $this->admin->exec('KILL CONNECTION '.$db->pinnedId());
                    $db->select('SELECT 1');
                }
            }));
            $operation = app(MediaJournal::class)->load($operationId);
            $this->assertSame('rolled_back', app(WriteCoordinator::class)->reconcile($operation));
            unset($operation);
            $after = \AcademicSnapshot::capture($this->admin);
            $this->assertSameRows($before, $after, 'connection loss rolled back every row');
            $this->assertSame($before['media'], $after['media'], 'connection loss kept prior evidence and compensated new media');
            \Illuminate\Support\Facades\DB::purge();
        });
    }
}
