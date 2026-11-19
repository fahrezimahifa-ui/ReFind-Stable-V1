<?php

namespace Tests\Feature;

use App\Foundation\MediaJournal;
use App\Foundation\WriteCoordinator;
use App\Foundation\WriteFailure;
use App\Services\PickupService;
use App\Support\ClaimStages;
use App\Support\PickupEvent;
use App\Support\Workspace;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Events\QueryExecuted;
use PDO;
use RuntimeException;
use Tests\TestCase;

/** Phase 7 pickup reads and BM pickup operations against real synthetic phase7-dev data (native 47eea86). */
final class PickupContractTest extends TestCase
{
    use Phase6Harness;

    private const REASON = 'Owner asked for a different time slot.';

    private function bm2(): int
    {
        $this->admin->exec("INSERT INTO users(full_name,email,password_hash,role,is_active) VALUES ('Synthetic BM Two','bm-two@refind-poc.invalid','x','BUILDING_MANAGEMENT',1)");

        return (int) $this->admin->lastInsertId();
    }

    /** Creates an approved claim through the real Phase 6 endpoints and returns its IDs. */
    private function approvedPickup(bool $linked = true, ?int $finder = null): array
    {
        $finder ??= $this->studentB;
        $found = $this->item($finder, 'FOUND', 'OPEN', 1, 38, 'Grey keyboard found', 'Grey wireless keyboard with stripe.');
        $lost = $linked ? $this->item($this->studentA, 'LOST', 'OPEN', 1, 38, 'Grey keyboard lost', 'Grey wireless keyboard with stripe.') : null;
        $this->asUser($this->studentA);
        $claim = (int) $this->submit(['itemId' => (string) $found, 'lostItemId' => $lost ? (string) $lost : '', 'answer' => 'Scratched space bar and a blue sticker.'])->assertCreated()->json('id');
        $revision = $this->revision($claim);
        $handover = $this->staffPost(['action' => 'review', 'id' => (string) $claim, 'decision' => 'approved', 'note' => 'Ownership details verified by Building Management.', 'revision' => $revision, 'confirm' => '1'])->assertOk()->json('handover_id');

        return [$found, $lost, $claim, (int) $handover];
    }

    private function handoverRevision(int $handover): string
    {
        $this->asUser($this->bm);

        return (string) $this->getJson('/api/handovers.php?id='.$handover)->assertOk()->json('handovers.0.revision');
    }

    private function pickup(array $data)
    {
        return $this->call('POST', '/api/handovers.php', $data, [], [], ['HTTP_X_CSRF_TOKEN' => self::TOKEN, 'HTTP_ACCEPT' => 'application/json']);
    }

    private function op(int $handover, string $action, array $extra = [])
    {
        $revision = $this->handoverRevision($handover);

        return $this->pickup(['action' => $action, 'id' => (string) $handover, 'revision' => $revision, 'confirm' => '1'] + $extra);
    }

    private static function jakarta(string $modify, string $time): array
    {
        $local = (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->modify($modify)->format('Y-m-d').'T'.$time;
        $utc = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local, new DateTimeZone('Asia/Jakarta'))->setTimezone(new DateTimeZone('UTC'));

        return [$local, $utc->format('Y-m-d H:i:s'), $utc->format('Y-m-d\TH:i:s\Z')];
    }

    private function handoverRow(int $handover): array
    {
        return $this->rows('SELECT * FROM handovers WHERE handover_id=?', [$handover])[0] ?? [];
    }

    public function test_pickup_reads_privacy_and_gates(): void
    {
        $this->assertSame([], ClaimStages::forProfile('academic-test', 'pickup'));
        $this->assertSame([], ClaimStages::forProfile('academic-dev', 'student,review,approval,pickup'));
        $this->assertSame([], ClaimStages::forProfile('phase6-dev', 'pickup'));
        $this->withSnapshot(function () {
            $this->getJson('/api/handovers.php')->assertUnauthorized();
            [$found, $lost, $claim, $handover] = $this->approvedPickup();

            $this->asUser($this->studentA);
            $mine = $this->getJson('/api/handovers.php')->assertOk()->json();
            $this->assertSame(1, $mine['total']);
            $record = $mine['handovers'][0];
            $this->assertSame([(string) $handover, (string) $claim, (string) $found, (string) $lost, 'PENDING', '38'], [$record['handover_id'], $record['claim_id'], $record['item_id'], $record['related_lost_item_id'], $record['handover_status'], $record['pickup_location_id']]);
            foreach (['claimant_email', 'claimant_student_id', 'revision', 'operations', 'audit', 'claim_status', 'claimant_user_id'] as $private) {
                $this->assertArrayNotHasKey($private, $record, 'claimant view omits '.$private);
            }
            $this->assertError($this->pickup(['action' => 'schedule', 'id' => (string) $handover]), 403, 'forbidden');
            $this->asUser($this->studentB);
            $this->assertSame(0, $this->getJson('/api/handovers.php')->assertOk()->json('total'), 'finder does not see the owner pickup');
            $this->getJson('/api/handovers.php?id='.$handover)->assertNotFound()->assertJsonPath('message', 'Pickup record not found.');

            $this->asUser($this->bm);
            $bm = $this->getJson('/api/handovers.php?id='.$handover)->assertOk()->json('handovers.0');
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $bm['revision']);
            $this->assertSame(['schedule', 'complete', 'release'], $bm['operations']);
            $this->assertSame([], $bm['audit']);
            $this->assertSame('student-a@refind-poc.invalid', $bm['claimant_email']);
            $this->assertSame(1, $this->getJson('/api/handovers.php?status=active&q=keyboard')->assertOk()->json('total'));
            $this->assertSame(0, $this->getJson('/api/handovers.php?status=COMPLETED')->assertOk()->json('total'));
            $this->assertError($this->getJson('/api/handovers.php?status=LOST'), 400, 'validation_error', 'Invalid pickup state.');
            $this->assertError($this->getJson('/api/handovers.php?extra=1'), 400, 'invalid_request');
            $this->get('/admin.html?tab=handover&handover='.$handover)->assertOk()->assertSee('data-bm-tab="handover"', false)->assertDontSee('data-bm-tab="reports"', false);
            $this->get('/admin.html?tab=reports')->assertStatus(501);
            $this->asUser($this->studentA);
            $this->get('/notifications.html')->assertOk()->assertSee('data-notifications-read-only="true"', false);
            $this->assertError($this->call('POST', '/api/notifications.php', ['action' => 'read_all'], [], [], ['HTTP_X_CSRF_TOKEN' => self::TOKEN, 'HTTP_ACCEPT' => 'application/json']), 501, 'feature_pending');

            // Narrowing the stages switches every Phase 7 surface back to the pre-Phase-7 contract.
            $previous = getenv('REFIND_PHASE6_STAGES');
            putenv('REFIND_PHASE6_STAGES=student,review,approval');
            try {
                $this->getJson('/api/handovers.php')->assertStatus(501);
                $this->getJson('/api/notifications.php')->assertStatus(501);
                $this->getJson('/api/notifications.php?count=1')->assertOk();
                $this->get('/notifications.html')->assertStatus(501);
                $this->asUser($this->bm);
                $this->assertError($this->pickup(['action' => 'complete', 'id' => (string) $handover, 'confirm' => '1', 'revision' => str_repeat('a', 64)]), 501, 'feature_pending');
                $this->get('/admin.html?tab=handover')->assertStatus(501);
            } finally {
                putenv($previous === false ? 'REFIND_PHASE6_STAGES' : 'REFIND_PHASE6_STAGES='.$previous);
            }
            $this->assertSame('PENDING', $this->handoverRow($handover)['handover_status']);
        });
    }

    public function test_schedule_reschedule_cancel_and_confirm_return(): void
    {
        $this->withSnapshot(function () {
            $bm2 = $this->bm2();
            [$found, $lost, $claim, $handover] = $this->approvedPickup();
            [$local, $utc, $iso] = self::jakarta('+2 days', '10:30');
            $base = ['action' => 'schedule', 'id' => (string) $handover, 'pickup_location_id' => '36', 'scheduled_at' => $local, 'revision' => $this->handoverRevision($handover), 'confirm' => '1'];

            // Native validation order and messages.
            $this->assertError($this->pickup(['extra' => '1'] + $base), 400, 'invalid_request');
            $this->assertError($this->pickup(['id' => 'x'] + $base), 400, 'invalid_id');
            $this->assertError($this->pickup(['action' => 'teleport'] + $base), 400, 'validation_error', 'Invalid pickup operation.');
            $this->assertError($this->pickup(['confirm' => '0'] + $base), 422, 'validation_error', 'Confirm this operation explicitly.', 'confirm');
            $this->assertError($this->pickup(['pickup_location_id' => ''] + $base), 422, 'validation_error', 'Choose a pickup area.', 'pickup_location_id');
            $this->assertError($this->pickup(['scheduled_at' => ''] + $base), 422, 'validation_error', 'Choose a pickup date and time.', 'scheduled_at');
            $this->assertError($this->pickup(['pickup_location_id' => '999999'] + $base), 422, 'validation_error', 'Choose an active campus pickup area.', 'pickup_location_id');
            [$past] = self::jakarta('-1 day', '10:30');
            foreach ([$past, '2030-02-30T10:00', '2030-01-01 10:00', 'tomorrow'] as $bad) {
                $this->assertError($this->pickup(['scheduled_at' => $bad] + $base), 422, 'validation_error', null, 'scheduled_at');
            }
            $this->assertError($this->pickup(['action' => 'reschedule', 'note' => self::REASON] + $base), 409, 'state_conflict', 'That operation does not match the current pickup state.');
            $this->assertError($this->pickup(['action' => 'cancel_schedule', 'note' => self::REASON] + $base), 409, 'state_conflict', 'That operation does not match the current pickup state.');
            $this->assertError($this->pickup(['action' => 'release'] + $base), 422, 'validation_error', 'Check the note field.');
            $this->assertError($this->pickup(['action' => 'release', 'note' => 'short'] + $base), 422, 'validation_error', 'Provide a reason of at least 8 characters.', 'note');
            $this->assertError($this->pickup(['id' => '999999'] + $base), 404, 'not_found', 'Pickup record not found.');
            foreach (['', str_repeat('0', 64), 'nothex'] as $bad) {
                $this->assertError($this->pickup(['revision' => $bad] + $base), 409, 'state_conflict', 'This record changed. Refresh the detail before deciding again.');
            }
            $this->assertSame('PENDING', $this->handoverRow($handover)['handover_status'], 'no change after denials');

            // Schedule: UTC storage, history on both reports, private audit to the claimant and the other BM.
            $n = $this->maxId('notifications', 'notification_id');
            $h = $this->maxId('item_status_history', 'history_id');
            $this->pickup($base)->assertOk()->assertExactJson(['ok' => true, 'id' => (string) $handover, 'status' => 'SCHEDULED']);
            $row = $this->handoverRow($handover);
            $this->assertSame(['SCHEDULED', '36', $utc, (string) $this->bm, 'Pickup scheduled by BM.', null], [$row['handover_status'], (string) $row['pickup_location_id'], $row['scheduled_at'], (string) $row['handled_by_user_id'], $row['notes'], $row['completed_at']]);
            $note = 'Pickup appointment scheduled or updated.';
            $this->assertSame([[$found, 'HANDOVER', $note, $this->bm], [$lost, 'HANDOVER', $note, $this->bm]], $this->historySince($h), 'both locked reports receive the history row');
            $message = 'BM #'.$this->bm.' schedule. Before: PENDING / location 38 / unscheduled. After: SCHEDULED / location 36 / '.$iso.'.';
            $this->assertSame([[$bm2, 'HANDOVER_SCHEDULE', 'Pickup scheduled', $message, $found, $claim], [$this->studentA, 'HANDOVER_SCHEDULE', 'Pickup scheduled', $message, $found, $claim]], $this->notificationsSince($n));
            $event = PickupEvent::parse($message);
            $this->assertSame(['schedule', 'PENDING', 'SCHEDULED', 'Gedung B', $iso, ''], [$event['operation'], $event['before']['status'], $event['after']['status'], $event['after']['location'], $event['after']['scheduled_at'], $event['reason']]);
            $this->asUser($this->bm);
            $detail = $this->getJson('/api/handovers.php?id='.$handover)->assertOk()->json('handovers.0');
            $this->assertSame(['reschedule', 'cancel_schedule', 'complete', 'release'], $detail['operations']);
            $this->assertSame([$iso, 'Gedung B'], [$detail['scheduled_at'], $detail['pickup_location']]);
            $this->assertSame('schedule', $detail['audit'][0]['pickup_event']['operation']);

            // Claimant and other-BM notification lists (read-only) expose the structured pickup event.
            $this->asUser($this->studentA);
            $list = $this->getJson('/api/notifications.php?unread=1')->assertOk()->json();
            $this->assertSame('HANDOVER_SCHEDULE', $list['notifications'][0]['notification_type']);
            $this->assertSame((string) $handover, $list['notifications'][0]['handover_id']);
            $this->assertSame('schedule', $list['notifications'][0]['pickup_event']['operation']);
            $this->assertGreaterThanOrEqual(1, $list['unread_count']);
            $this->assertError($this->getJson('/api/notifications.php?count=1&page=1'), 400, 'validation_error', 'Invalid count request.');
            $this->assertError($this->getJson('/api/notifications.php?unread=0'), 400, 'validation_error', 'Invalid notification filter.');
            $this->asUser($this->studentB);
            $this->assertNotContains('HANDOVER_SCHEDULE', array_column($this->getJson('/api/notifications.php')->assertOk()->json('notifications'), 'notification_type'), 'finder receives no pickup audit');
            $this->getJson('/api/notifications.php')->assertOk()->assertJsonMissing(['handover_id' => (string) $handover]);

            // Reschedule needs a change and a reason; the reason travels in the audit.
            $this->assertError($this->op($handover, 'reschedule', ['pickup_location_id' => '36', 'scheduled_at' => $local, 'note' => self::REASON]), 409, 'state_conflict', 'Choose a different pickup time or location before rescheduling.');
            $this->assertError($this->op($handover, 'reschedule', ['pickup_location_id' => '35', 'scheduled_at' => $local]), 422, 'validation_error', 'Check the note field.');
            [$local2, $utc2, $iso2] = self::jakarta('+3 days', '14:15');
            $n = $this->maxId('notifications', 'notification_id');
            $this->op($handover, 'reschedule', ['pickup_location_id' => '35', 'scheduled_at' => $local2, 'note' => self::REASON])->assertOk()->assertJsonPath('status', 'SCHEDULED');
            $this->assertSame(['35', $utc2, self::REASON], [(string) $this->handoverRow($handover)['pickup_location_id'], $this->handoverRow($handover)['scheduled_at'], $this->handoverRow($handover)['notes']]);
            $this->assertStringEndsWith('After: SCHEDULED / location 35 / '.$iso2.'. Reason: '.self::REASON, $this->notificationsSince($n)[0][3]);

            // Cancel the appointment: ownership, pair and report states are retained.
            $h = $this->maxId('item_status_history', 'history_id');
            $this->op($handover, 'cancel_schedule', ['note' => self::REASON])->assertOk()->assertJsonPath('status', 'PENDING');
            $row = $this->handoverRow($handover);
            $this->assertSame(['PENDING', null, '35', self::REASON], [$row['handover_status'], $row['scheduled_at'], (string) $row['pickup_location_id'], $row['notes']]);
            $this->assertSame('APPROVED', $this->claimRow($claim)['claim_status']);
            $this->assertSame(['CONFIRMED'], array_column($this->rows('SELECT match_status FROM item_matches WHERE lost_item_id=? AND found_item_id=?', [$lost, $found]), 'match_status'));
            $this->assertSame(['HANDOVER', 'HANDOVER'], [$this->itemStatus($found), $this->itemStatus($lost)]);
            $this->assertSame('Pickup appointment cancelled; verified ownership retained.', $this->historySince($h)[0][2]);

            // Confirm the physical return from PENDING: reports RESOLVED, finder told, owner's own Lost silent.
            $n = $this->maxId('notifications', 'notification_id');
            $h = $this->maxId('item_status_history', 'history_id');
            $this->op($handover, 'complete')->assertOk()->assertExactJson(['ok' => true, 'id' => (string) $handover, 'status' => 'COMPLETED']);
            $row = $this->handoverRow($handover);
            $this->assertSame(['COMPLETED', 'Physical return confirmed by BM.'], [$row['handover_status'], $row['notes']]);
            $this->assertNotNull($row['completed_at']);
            $this->assertSame(['RESOLVED', 'RESOLVED'], [$this->itemStatus($found), $this->itemStatus($lost)]);
            $this->assertCount(2, $this->rows('SELECT item_id FROM items WHERE item_id IN (?,?) AND resolved_at IS NOT NULL', [$found, $lost]));
            $this->assertSame([[$found, 'RESOLVED', 'Verified item returned; handover completed.', $this->bm], [$lost, 'RESOLVED', 'Verified item returned; handover completed.', $this->bm]], $this->historySince($h));
            $notes = $this->notificationsSince($n);
            $this->assertSame([$this->studentB, 'STATUS_CHANGED', 'Returned', 'Your report is now: Returned.', $found, null], $notes[0]);
            $this->assertSame([[$bm2, 'HANDOVER_COMPLETE', 'Item returned'], [$this->studentA, 'HANDOVER_COMPLETE', 'Item returned']], array_map(fn ($x) => array_slice($x, 0, 3), array_slice($notes, 1)));
            $this->assertCount(3, $notes);
            $this->asUser($this->bm);
            $this->assertSame([], $this->getJson('/api/handovers.php?id='.$handover)->json('handovers.0.operations'));
            $this->assertError($this->op($handover, 'release', ['note' => self::REASON]), 409, 'state_conflict', 'Only an active approved pickup can be changed.');
            $this->assertCount(1, $this->rows('SELECT handover_id FROM handovers WHERE claim_id=?', [$claim]), 'exactly one handover throughout');
            $this->asUser($this->studentA);
            $this->assertSame('COMPLETED', $this->getJson('/api/handovers.php')->json('handovers.0.handover_status'));
            $this->asUser($this->studentB);
            $this->assertSame('RESOLVED', $this->getJson('/api/items.php?id='.$found)->assertOk()->json('item.status_code'));
        });
    }

    public function test_release_revokes_ownership_and_makes_reports_available(): void
    {
        $this->withSnapshot(function () {
            [$found, $lost, $claim, $handover] = $this->approvedPickup();
            [$local, , $iso] = self::jakarta('+1 day', '09:00');
            $this->op($handover, 'schedule', ['pickup_location_id' => '38', 'scheduled_at' => $local])->assertOk();
            $n = $this->maxId('notifications', 'notification_id');
            $h = $this->maxId('item_status_history', 'history_id');
            $reason = 'Owner did not collect within the agreed window.';
            $this->op($handover, 'release', ['note' => $reason])->assertOk()->assertExactJson(['ok' => true, 'id' => (string) $handover, 'status' => 'RELEASED']);
            $this->assertSame([], $this->handoverRow($handover), 'native release deletes the handover row');
            $row = $this->claimRow($claim);
            $this->assertSame(['REJECTED', 'Pickup released: '.$reason, (string) $this->bm], [$row['claim_status'], $row['review_note'], (string) $row['reviewed_by_user_id']]);
            $pair = $this->rows('SELECT match_status,confirmed_by_user_id,confirmed_at FROM item_matches WHERE lost_item_id=? AND found_item_id=?', [$lost, $found])[0];
            $this->assertSame(['REJECTED', (string) $this->bm], [$pair['match_status'], (string) $pair['confirmed_by_user_id']]);
            $this->assertSame(['OPEN', 'OPEN'], [$this->itemStatus($found), $this->itemStatus($lost)]);
            $note = 'Pickup released by Building Management; the report is available again.';
            $this->assertSame([[$found, 'OPEN', $note, $this->bm], [$lost, 'OPEN', $note, $this->bm]], $this->historySince($h));
            $this->assertSame([
                [$this->studentB, 'STATUS_CHANGED', 'Reported', 'Your report is now: Reported.', $found, null],
                [$this->studentA, 'HANDOVER_RELEASE', 'Pickup released', 'BM #'.$this->bm.' release. Before: SCHEDULED / location 38 / '.$iso.'. After: RELEASED / location none / unscheduled. Reason: '.$reason, $found, $claim],
            ], $this->notificationsSince($n));
            // Release is final for that claimant; the Found report is claimable again by others.
            $this->asUser($this->studentA);
            $this->assertError($this->submit(['itemId' => (string) $found, 'answer' => 'Trying again after release.']), 409, 'claim_final');
            $this->getJson('/api/claims.php?id='.$claim)->assertOk()->assertJsonPath('claims.0.claim_status', 'REJECTED');
            $this->admin->exec("INSERT INTO users(full_name,email,password_hash,role,is_active) VALUES ('Synthetic Student C','student-c@refind-poc.invalid','x','STUDENT',1)");
            $this->asUser((int) $this->admin->lastInsertId());
            $this->submit(['itemId' => (string) $found, 'answer' => 'A different owner with real detail.'])->assertCreated();
            $this->assertSame([], $this->rows("SELECT h.handover_id FROM handovers h JOIN claims c ON c.claim_id=h.claim_id WHERE c.claim_status<>'APPROVED'"), 'no orphan handover');

            // Unlinked release from PENDING leaves pairs alone.
            [$found2, , $claim2, $handover2] = $this->approvedPickup(false);
            $this->op($handover2, 'release', ['note' => $reason])->assertOk()->assertJsonPath('status', 'RELEASED');
            $this->assertSame(['OPEN', 'REJECTED'], [$this->itemStatus($found2), $this->claimRow($claim2)['claim_status']]);
            $this->assertError($this->pickup(['action' => 'complete', 'id' => (string) $handover2, 'revision' => str_repeat('a', 64), 'confirm' => '1']), 404, 'not_found', 'Pickup record not found.');
        });
    }

    public function test_pickup_atomicity_lock_contention_and_uncertain_outcome(): void
    {
        $this->withSnapshot(function () {
            [$found, $lost, $claim, $handover] = $this->approvedPickup();
            $service = app(PickupService::class);
            $bm = ['user_id' => (string) $this->bm, 'role' => 'BUILDING_MANAGEMENT'];
            [$local] = self::jakarta('+2 days', '11:00');
            $request = fn (string $action, array $extra = []) => $this->serviceRequest('/api/handovers.php', ['action' => $action, 'id' => (string) $handover, 'revision' => $this->handoverRevision($handover), 'confirm' => '1'] + $extra);

            foreach ([['schedule', ['pickup_location_id' => '36', 'scheduled_at' => $local]], ['complete', []], ['release', ['note' => self::REASON]]] as [$action, $extra]) {
                foreach (['after_begin', 'after_work', 'after_promote', 'before_commit'] as $point) {
                    $before = \AcademicSnapshot::capture($this->admin);
                    try {
                        $service->mutate($bm, $request($action, $extra), function (string $current) use ($point) {
                            if ($current === $point) {
                                throw new RuntimeException('Injected pickup failure.');
                            }
                        });
                        $this->fail("Injected $action failure unexpectedly succeeded at $point");
                    } catch (WriteFailure $e) {
                        $this->assertFalse($e->uncertain);
                    }
                    $this->assertSameRows($before, \AcademicSnapshot::capture($this->admin), "$action rollback at $point");
                }
            }
            // SQL failures part-way through release and completion: no partial ownership/pair/status changes.
            foreach ([['release', ['note' => self::REASON], 'delete from handovers'], ['release', ['note' => self::REASON], 'insert into notifications'], ['complete', [], 'insert into item_status_history']] as [$action, $extra, $prefix]) {
                $armed = true;
                app('events')->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$armed, $prefix) {
                    if ($armed && str_starts_with(strtolower($event->sql), $prefix)) {
                        $armed = false;
                        throw new RuntimeException('Injected pickup SQL failure.');
                    }
                });
                $before = \AcademicSnapshot::capture($this->admin);
                try {
                    $service->mutate($bm, $request($action, $extra));
                    $this->fail('SQL failure unexpectedly succeeded: '.$prefix);
                } catch (WriteFailure $e) {
                    $this->assertFalse($e->uncertain);
                }
                $this->assertSameRows($before, \AcademicSnapshot::capture($this->admin), "$action atomic after $prefix");
            }
            $this->assertSame(['APPROVED', 'PENDING'], [$this->claimRow($claim)['claim_status'], $this->handoverRow($handover)['handover_status']]);

            // Shared workflow lock held elsewhere: bounded wait, native busy 409, nothing written.
            $secret = json_decode(file_get_contents(Workspace::ROOT.'/state/owner-credentials.json'), true);
            $holder = new PDO('mysql:host=127.0.0.1;port=3307;dbname='.Workspace::profile()['database'], 'root', $secret['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $lock = "'refind-matching-".Workspace::profile()['database']."'";
            $this->assertSame(1, (int) $holder->query("SELECT GET_LOCK($lock,0)")->fetchColumn());
            try {
                $this->assertError($this->op($handover, 'complete'), 409, 'state_conflict', 'Another workflow is saving. Please retry.');
            } finally {
                $holder->query("SELECT RELEASE_LOCK($lock)");
                $holder = null;
            }
            $this->assertSame('PENDING', $this->handoverRow($handover)['handover_status']);

            // Lost commit acknowledgement on the physical return: unknown outcome, reconciled committed, never replayed.
            $revision = $this->handoverRevision($handover);
            $n = $this->maxId('notifications', 'notification_id');
            $operationId = $this->uncertain(fn () => $service->mutate($bm, $this->serviceRequest('/api/handovers.php', ['action' => 'complete', 'id' => (string) $handover, 'revision' => $revision, 'confirm' => '1']), null, function ($connection) {
                $connection->commit();
                throw new RuntimeException('Lost return acknowledgement.');
            }));
            $operation = app(MediaJournal::class)->load($operationId);
            $this->assertSame('committed', app(WriteCoordinator::class)->reconcile($operation));
            unset($operation);
            $this->assertSame('COMPLETED', $this->handoverRow($handover)['handover_status']);
            $this->asUser($this->bm);
            $this->assertError($this->pickup(['action' => 'complete', 'id' => (string) $handover, 'revision' => $revision, 'confirm' => '1']), 409, 'state_conflict');
            $this->assertCount(1, $this->rows("SELECT notification_id FROM notifications WHERE notification_id>? AND notification_type='HANDOVER_COMPLETE'", [$n]), 'audit not duplicated');
            $this->assertSame(['RESOLVED', 'RESOLVED'], [$this->itemStatus($found), $this->itemStatus($lost)]);
        });
    }
}
