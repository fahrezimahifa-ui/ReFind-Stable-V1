<?php

namespace Tests\Feature;

use App\Foundation\MediaJournal;
use App\Foundation\WriteCoordinator;
use App\Foundation\WriteFailure;
use App\Services\ClaimReviewService;
use App\Services\RuleMatcher;
use Illuminate\Database\Events\QueryExecuted;
use RuntimeException;
use Tests\TestCase;

/** Phase 6E approval, competing-claim closure and exactly one pending handover (native 47eea86). */
final class ClaimApprovalContractTest extends TestCase
{
    use Phase6Harness;

    private const NOTE = 'Ownership details verified by Building Management.';

    private int $studentC;

    private function studentC(): int
    {
        $this->admin->exec("INSERT INTO users(full_name,email,password_hash,role,is_active) VALUES ('Synthetic Student C','student-c@refind-poc.invalid','x','STUDENT',1)");

        return $this->studentC = (int) $this->admin->lastInsertId();
    }

    private function claim(int $found, int $claimant, ?int $lost = null, string $status = 'PENDING'): int
    {
        $s = $this->admin->prepare('INSERT INTO claims(item_id,related_lost_item_id,claimant_user_id,ownership_answer,claim_status) VALUES (?,?,?,?,?)');
        $s->execute([$found, $lost, $claimant, 'Synthetic ownership answer for approval.', $status]);
        $id = (int) $this->admin->lastInsertId();
        $this->admin->exec("UPDATE items SET status_id=(SELECT status_id FROM item_statuses WHERE status_code='VERIFICATION') WHERE item_id=$found AND status_id IN (SELECT status_id FROM item_statuses WHERE status_code IN ('OPEN','POTENTIAL_MATCH'))");

        return $id;
    }

    private function approve(int $claim, ?string $revision = null)
    {
        $revision ??= $this->revision($claim);
        $this->asUser($this->bm);

        return $this->staffPost(['action' => 'review', 'id' => (string) $claim, 'decision' => 'approved', 'note' => self::NOTE, 'revision' => $revision, 'confirm' => '1']);
    }

    private function invariants(): void
    {
        $this->assertSame([], $this->rows("SELECT item_id FROM claims WHERE claim_status='APPROVED' GROUP BY item_id HAVING COUNT(*)>1"), 'one approved owner per Found');
        $this->assertSame([], $this->rows("SELECT id FROM (SELECT lost_item_id AS id FROM item_matches WHERE match_status='CONFIRMED' UNION ALL SELECT found_item_id FROM item_matches WHERE match_status='CONFIRMED') x GROUP BY id HAVING COUNT(*)>1"), 'one confirmed pair per report');
        $this->assertSame([], $this->rows('SELECT claim_id FROM handovers GROUP BY claim_id HAVING COUNT(*)>1'), 'one handover per claim');
        $this->assertSame([], $this->rows("SELECT h.handover_id FROM handovers h JOIN claims c ON c.claim_id=h.claim_id WHERE c.claim_status<>'APPROVED'"), 'handovers only for approved claims');
        $this->assertSame([], $this->rows("SELECT c.claim_id FROM claims c LEFT JOIN handovers h ON h.claim_id=c.claim_id WHERE c.claim_status='APPROVED' AND h.handover_id IS NULL AND c.claim_id>1"), 'every new approval has its handover');
    }

    public function test_unlinked_approval_closes_competitors_and_creates_one_pending_handover(): void
    {
        $this->withSnapshot(function () {
            $c = $this->studentC();
            $found = $this->item($this->studentB, 'FOUND', 'POTENTIAL_MATCH', 1, 38);
            $neighbour = $this->item($c, 'LOST', 'POTENTIAL_MATCH', 1, 38);
            $this->admin->exec("INSERT INTO item_matches(lost_item_id,found_item_id,match_score) VALUES ($neighbour,$found,65)");
            $winner = $this->claim($found, $this->studentA);
            $loser = $this->claim($found, $c);
            $loserRevision = $this->revision($loser);
            $n = $this->maxId('notifications', 'notification_id');
            $h = $this->maxId('item_status_history', 'history_id');

            $r = $this->approve($winner)->assertOk();
            $handover = $r->json('handover_id');
            $this->assertIsString($handover);
            $r->assertExactJson(['ok' => true, 'id' => (string) $winner, 'status' => 'APPROVED', 'handover_id' => $handover]);
            $row = $this->claimRow($winner);
            $this->assertSame(['APPROVED', (string) $this->bm, self::NOTE], [$row['claim_status'], (string) $row['reviewed_by_user_id'], $row['review_note']]);
            $this->assertSame(['REJECTED', 'Another ownership claim was verified by Building Management.'], [$this->claimRow($loser)['claim_status'], $this->claimRow($loser)['review_note']]);
            $this->assertSame('HANDOVER', $this->itemStatus($found));
            $this->assertSame('OPEN', $this->itemStatus($neighbour), 'unmatched neighbour settles to Reported');
            $this->assertSame([], $this->rows('SELECT match_id FROM item_matches WHERE found_item_id=?', [$found]), 'suggestions of the assigned Found removed');
            $handovers = $this->rows('SELECT * FROM handovers WHERE claim_id=?', [$winner]);
            $this->assertCount(1, $handovers);
            $this->assertSame([$handover, 'PENDING', '38', (string) $this->bm, 'Awaiting a pickup appointment.', null, null], [(string) $handovers[0]['handover_id'], $handovers[0]['handover_status'], (string) $handovers[0]['pickup_location_id'], (string) $handovers[0]['handled_by_user_id'], $handovers[0]['notes'], $handovers[0]['scheduled_at'], $handovers[0]['completed_at']]);
            $this->assertSame([
                [$this->studentB, 'STATUS_CHANGED', 'Ready for pickup', 'Your report is now: Ready for pickup.', $found, null],
                [$c, 'CLAIM_REJECTED', 'Claim closed', 'Another ownership claim was verified by Building Management.', $found, $loser],
                [$this->studentA, 'CLAIM_APPROVED', 'Claim approved', self::NOTE, $found, $winner],
            ], $this->notificationsSince($n));
            $this->assertSame([
                [$found, 'HANDOVER', 'Ownership verified by Building Management. Item is ready for pickup.', $this->bm],
                [$neighbour, 'OPEN', 'No current matching suggestions.', $this->bm],
            ], $this->historySince($h));
            $this->invariants();

            // Competing approval with a revision read before the first decision, then with a fresh one.
            $this->assertError($this->approve($loser, $loserRevision), 409, 'state_conflict', 'This record changed. Refresh the detail before deciding again.');
            $this->assertError($this->approve($loser), 409, 'state_conflict', 'This claim is no longer eligible for that decision.');
            $this->assertError($this->approve($winner), 409, 'state_conflict', 'This claim is no longer eligible for that decision.');
            $this->admin->exec("INSERT INTO users(full_name,email,password_hash,role,is_active) VALUES ('Synthetic Student D','student-d@refind-poc.invalid','x','STUDENT',1)");
            $late = $this->claim($found, (int) $this->admin->lastInsertId(), null, 'NEED_MORE_INFO');
            $this->assertError($this->approve($late), 409, 'state_conflict', 'This item has already been assigned or closed.');
            $this->assertCount(1, $this->rows('SELECT handover_id FROM handovers WHERE claim_id IN (?,?,?)', [$winner, $loser, $late]));
            $this->asUser($this->bm);
            $this->getJson('/api/staff.php?resource=claims&id='.$winner)->assertOk()->assertJsonPath('claim.handover_id', $handover)->assertJsonPath('claim.decisions', []);
            $this->asUser($this->studentA);
            $this->getJson('/api/claims.php?id='.$winner)->assertOk()->assertJsonPath('claims.0.claim_status', 'APPROVED')->assertJsonPath('claims.0.can_withdraw', false);
            $this->assertError($this->submit(['action' => 'withdraw', 'itemId' => (string) $found]), 409, 'state_conflict');
            // Pickup endpoints stay unavailable unless the Phase 7 pickup stage is enabled.
            if (! \App\Support\ClaimStages::has('pickup')) {
                $this->asUser($this->bm);
                $this->call('POST', '/api/handovers.php', ['action' => 'schedule', 'id' => $handover], [], [], ['HTTP_X_CSRF_TOKEN' => self::TOKEN, 'HTTP_ACCEPT' => 'application/json'])->assertStatus(501);
                $this->getJson('/api/handovers.php')->assertStatus(501);
            }
        });
    }

    public function test_linked_approval_confirms_pair_and_closes_legacy_shared_lost_claims(): void
    {
        $this->withSnapshot(function () {
            $c = $this->studentC();
            $found = $this->item($this->studentB, 'FOUND', 'POTENTIAL_MATCH', 1, 38, 'Grey keyboard found', 'Grey wireless keyboard with stripe.');
            $lost = $this->item($this->studentA, 'LOST', 'POTENTIAL_MATCH', 1, 38, 'Grey keyboard lost', 'Grey wireless keyboard with stripe.');
            $unrelatedLost = $this->item($c, 'LOST', 'POTENTIAL_MATCH', 1, 40);
            $unrelatedFound = $this->item($this->studentB, 'FOUND', 'POTENTIAL_MATCH', 1, 40);
            $this->admin->exec("INSERT INTO item_matches(lost_item_id,found_item_id,match_score) VALUES ($lost,$found,55),($unrelatedLost,$unrelatedFound,60)");
            $legacyFound = $this->item($c, 'FOUND');
            $winner = $this->claim($found, $this->studentA, $lost);
            // Legacy data: another active claim reuses the same Lost on a different Found.
            $legacy = $this->claim($legacyFound, $this->studentA, $lost);
            $n = $this->maxId('notifications', 'notification_id');

            $this->approve($winner)->assertOk()->assertJsonPath('status', 'APPROVED');
            $pair = $this->rows('SELECT * FROM item_matches WHERE lost_item_id=? AND found_item_id=?', [$lost, $found]);
            $this->assertCount(1, $pair);
            $expectedScore = app(RuleMatcher::class)->score($this->rows('SELECT * FROM items WHERE item_id=?', [$lost])[0], $this->rows('SELECT * FROM items WHERE item_id=?', [$found])[0]);
            $this->assertSame(['CONFIRMED', (string) $this->bm, number_format($expectedScore, 2, '.', '')], [$pair[0]['match_status'], (string) $pair[0]['confirmed_by_user_id'], (string) $pair[0]['match_score']]);
            $this->assertNotNull($pair[0]['confirmed_at']);
            $this->assertSame(['HANDOVER', 'HANDOVER'], [$this->itemStatus($found), $this->itemStatus($lost)]);
            $this->assertSame(['REJECTED', 'Closed because your linked Lost report was matched to another approved claim.'], [$this->claimRow($legacy)['claim_status'], $this->claimRow($legacy)['review_note']]);
            $this->assertSame('OPEN', $this->itemStatus($legacyFound), 'legacy Found settled after its claim closed');
            $this->assertSame([['SUGGESTED']], array_map(fn ($r) => [$r['match_status']], $this->rows('SELECT match_status FROM item_matches WHERE lost_item_id=? AND found_item_id=?', [$unrelatedLost, $unrelatedFound])), 'unrelated suggestions untouched');
            $this->assertSame(['POTENTIAL_MATCH', 'POTENTIAL_MATCH'], [$this->itemStatus($unrelatedLost), $this->itemStatus($unrelatedFound)]);
            $notes = $this->notificationsSince($n);
            $this->assertSame([$this->studentB, 'STATUS_CHANGED', 'Ready for pickup'], array_slice($notes[0], 0, 3));
            $this->assertSame([$this->studentA, 'CLAIM_REJECTED', 'Other claims closed', '1 other claim(s) that used the same Lost report were closed because this claim was approved.', $found, $legacy], $notes[1]);
            $this->assertSame([$this->studentA, 'CLAIM_APPROVED', 'Claim approved'], array_slice($notes[2], 0, 3));
            $this->assertCount(3, $notes, 'Lost owner gets no separate status notification; legacy loser gets none of its own');
            $this->invariants();

            // A later claim cannot use the now-assigned Lost.
            $another = $this->item($this->studentB, 'FOUND');
            $this->asUser($this->studentA);
            $this->assertError($this->submit(['itemId' => (string) $another, 'lostItemId' => (string) $lost, 'answer' => 'Synthetic later answer text.']), 409, 'state_conflict', 'That Lost report is already assigned or closed.');
        });
    }

    public function test_same_pair_rejected_conversion_and_blocked_lost(): void
    {
        $this->withSnapshot(function () {
            // Native upsert converts a same-pair REJECTED row to CONFIRMED during explicit approval (flagged for sign-off).
            $found = $this->item($this->studentB, 'FOUND');
            $lost = $this->item($this->studentA, 'LOST');
            $this->admin->exec("INSERT INTO item_matches(lost_item_id,found_item_id,match_score,match_status) VALUES ($lost,$found,40,'REJECTED')");
            $claim = $this->claim($found, $this->studentA, $lost);
            $this->approve($claim)->assertOk();
            $this->assertSame(['CONFIRMED'], array_column($this->rows('SELECT match_status FROM item_matches WHERE lost_item_id=? AND found_item_id=?', [$lost, $found]), 'match_status'));
            $this->assertCount(1, $this->rows('SELECT match_id FROM item_matches WHERE lost_item_id=? AND found_item_id=?', [$lost, $found]), 'pair row preserved, never duplicated');

            // A linked Lost that already has a terminal outcome blocks approval with no partial writes.
            $found2 = $this->item($this->studentB, 'FOUND');
            $lost2 = $this->item($this->studentA, 'LOST');
            $claim2 = $this->claim($found2, $this->studentA, $lost2);
            $revision = $this->revision($claim2);
            $this->admin->exec("UPDATE items SET status_id=(SELECT status_id FROM item_statuses WHERE status_code='UNCLAIMED_CLOSED') WHERE item_id=$lost2");
            $before = \AcademicSnapshot::capture($this->admin);
            $this->assertError($this->approve($claim2, $revision), 409, 'state_conflict', 'The linked Lost report already has an owner or terminal outcome.');
            $this->assertSameRows($before, \AcademicSnapshot::capture($this->admin), 'blocked approval wrote nothing');
            $this->asUser($this->bm);
            $detail = $this->getJson('/api/staff.php?resource=claims&id='.$claim2)->assertOk()->json('claim');
            $this->assertNotContains('approved', $detail['decisions']);
            $this->assertNotNull($detail['approval_blocked']);
            $this->invariants();
        });
    }

    public function test_approval_rollback_uncertain_outcome_and_exactly_one_handover(): void
    {
        $this->withSnapshot(function () {
            $c = $this->studentC();
            $found = $this->item($this->studentB, 'FOUND');
            $lost = $this->item($this->studentA, 'LOST');
            $winner = $this->claim($found, $this->studentA, $lost);
            $this->claim($found, $c);
            $revision = $this->revision($winner);
            $service = app(ClaimReviewService::class);
            $bm = ['user_id' => (string) $this->bm, 'role' => 'BUILDING_MANAGEMENT'];
            $request = fn () => $this->serviceRequest('/api/staff.php', ['action' => 'review', 'id' => (string) $winner, 'decision' => 'approved', 'note' => self::NOTE, 'revision' => $revision, 'confirm' => '1']);

            foreach (['after_begin', 'after_work', 'after_promote', 'before_commit'] as $point) {
                $before = \AcademicSnapshot::capture($this->admin);
                try {
                    $service->review($bm, $request(), function (string $current) use ($point) {
                        if ($current === $point) {
                            throw new RuntimeException('Injected approval failure.');
                        }
                    });
                    $this->fail('Injected approval failure unexpectedly succeeded at '.$point);
                } catch (WriteFailure $e) {
                    $this->assertFalse($e->uncertain);
                }
                $this->assertSameRows($before, \AcademicSnapshot::capture($this->admin), 'approval rollback at '.$point);
            }
            // SQL failures part-way through approval (after pair, after handover, on the winner notification).
            foreach (['insert into handovers', 'update claims set claim_status=\'rejected\'', 'delete from item_matches'] as $prefix) {
                $armed = true;
                app('events')->listen(QueryExecuted::class, function (QueryExecuted $event) use (&$armed, $prefix) {
                    if ($armed && str_starts_with(strtolower($event->sql), $prefix)) {
                        $armed = false;
                        throw new RuntimeException('Injected approval SQL failure.');
                    }
                });
                $before = \AcademicSnapshot::capture($this->admin);
                try {
                    $service->review($bm, $request());
                    $this->fail('SQL failure unexpectedly succeeded: '.$prefix);
                } catch (WriteFailure $e) {
                    $this->assertFalse($e->uncertain);
                }
                $this->assertSameRows($before, \AcademicSnapshot::capture($this->admin), 'no partial confirmation/handover after '.$prefix);
            }
            $this->assertSame([], $this->rows('SELECT handover_id FROM handovers WHERE claim_id=?', [$winner]));

            // Lost commit acknowledgement: unknown outcome, reconciled as committed, never replayed.
            $operationId = $this->uncertain(fn () => $service->review($bm, $request(), null, function ($connection) {
                $connection->commit();
                throw new RuntimeException('Lost approval acknowledgement.');
            }));
            $operation = app(MediaJournal::class)->load($operationId);
            $this->assertSame('committed', app(WriteCoordinator::class)->reconcile($operation));
            unset($operation);
            $this->assertCount(1, $this->rows('SELECT handover_id FROM handovers WHERE claim_id=?', [$winner]), 'exactly one pending handover');
            $this->assertSame('APPROVED', $this->claimRow($winner)['claim_status']);
            // A client retry with the old revision is denied rather than replayed.
            $this->assertError($this->approve($winner, $revision), 409, 'state_conflict');
            $this->assertCount(1, $this->rows('SELECT handover_id FROM handovers WHERE claim_id=?', [$winner]));
            $this->assertCount(1, $this->rows("SELECT notification_id FROM notifications WHERE claim_id=? AND notification_type='CLAIM_APPROVED'", [$winner]));
            $this->invariants();
        });
    }
}
