# Phase 7 implementation handoff (pickup and physical return): unaccepted WIP

19 November 2026. Phase 7 continues from the tested Phase 6 WIP as an authorized development-sequencing amendment. **Nothing in Phase 6 or Phase 7 is formally accepted**; Phase 6's outstanding gates are unchanged (see `PHASE6_IMPLEMENTATION_HANDOFF.md`).

## Where the work is

| Item | Value |
|---|---|
| Worktree / branch | `C:\ReFind-Laravel\laravel-p7`, `migration/phase7-impl` (local only; no push, merge or deploy) |
| Base | Phase 6 WIP `1ea27c5` (`migration/phase6-impl`). It is preserved unchanged and was clean when checked. |
| WIP commits | `72f00c2` phase7-dev profile · `f48f6f4` pickup workflows · this handoff commit |
| Profile | `phase7-dev`: schema `refind_laravel_p7_dev`, principal `refind_p7_dev_app`, workers 8018/8019, `state/phase7-dev`, `state/phase7-processes.json`. It is bound to the `laravel-p7` checkout. |

## Approved Phase 7 boundary (as implemented)

**In scope:**
- Native BM pickup operations: schedule, reschedule, cancel the appointment, confirm the physical return, and release.
- Their status, history and notification effects.
- Pickup reads for the Student and BM.
- Student and BM UI integration.
- A read-only notifications list. It is the claimant's only record of reschedule and cancel reasons, and the channel through which other BMs see the pickup audit.

**Out of scope, kept gated:**
- Notification read-marking (`POST notifications.php`). It needs a new `notifications.is_read` write grant, which is unapproved, so the UI controls stay inert.
- BM manual report status changes and the Reports tab.
- The campus history page, account, registration and AI.

## Implemented workflows (native 47eea86 semantics)

| Area | Implementation |
|---|---|
| Pickup reads | `GET /api/handovers.php` (`HandoverController`, `HandoverReadRepository`). Students see only their own pickups, without contact, revision, operation or audit fields. BM also gets `revision`, `operations` and, on an exact record, the private audit with parsed pickup events. Supports the `status` (active/PENDING/SCHEDULED/COMPLETED/all), `q` and pagination filters. |
| Schedule / reschedule | `PickupService`. The time must be a future Asia/Jakarta `Y-m-d\TH:i`; it is stored in UTC and rechecked under the lock. The pickup area must be active. A reschedule needs a change plus an 8–250 character reason. History goes on both locked reports. |
| Cancel appointment | Returns the pickup to PENDING, clears the time and keeps the location. Ownership, the confirmed pair and HANDOVER statuses are retained. |
| Confirm physical return | Handover COMPLETED with `completed_at`. Found and Lost become RESOLVED with `resolved_at`. The finder gets "Returned"; the owner's own Lost is silent. |
| Release | Claim becomes REJECTED with `Pickup released: <reason>`, the confirmed pair becomes REJECTED, and the handover row is deleted (native). The reports settle back to idle and the Found report is claimable by others again. Release is final for that claimant. |
| Audit and notifications | `HANDOVER_*` with the native message format, sent to the claimant and every other active BM. The read-only `GET notifications.php` returns structured `pickup_event` data (`PickupEvent`). |
| UI | BM **Claims** and **Handover** tabs, with native pickup operations and the "Schedule pickup now" shortcut. Student My Activity pickup cards, and the item page notice. `notifications.html` is a Blade binding of the native page, with read-marking controls hidden or inert. |
| Gates | `ClaimStages`: the `pickup` stage exists only for `phase7-dev`. Every Phase 7 surface returns the pre-Phase-7 501 contract when the stage is off, and it can never be enabled on academic profiles. |
| Grants | `grant-claims.php pickup` (`phase7-dev` only, reversible) grants `handovers` UPDATE(handover_status, pickup_location_id, scheduled_at, completed_at, handled_by_user_id, notes) and DELETE (needed by native release). `report-privileges.php` enforces the exact `phase7-dev` set: 68 column grants, 6 table grants, and 14 cross-schema denials covering both academic schemas and the Phase 6 schema. |

All writes go through `WriteCoordinator`, which provides the shared advisory lock, ordered parent locks, the operation ledger and reconciliation, with no automatic retry.

## Changed files since `1ea27c5`

- **Application:**
  - `app/Http/Controllers/{HandoverController (new), DiscoveryController, PageController}.php`
  - `app/Repositories/{HandoverReadRepository, NotificationReadRepository}.php` (new)
  - `app/Services/{PickupService (new), WorkflowEffects}.php`
  - `app/Support/{PickupEvent (new), ClaimStages, Workspace}.php`
  - `config/{app, refind}.php`
  - `routes/web.php`
- **Frontend:**
  - `public/js/{bm, student}.js`
  - `resources/views/{bm, claim, item, my-reports}.blade.php`
  - `resources/views/notifications.blade.php` (new)
  - `docs/SOURCE_PROVENANCE.json`
- **Tests and tooling:**
  - `tests/Feature/PickupContractTest.php` (new); inherited tests made profile- and stage-aware.
  - `phpunit.phase7.xml` (new).
  - `tools/{grant-claims, report-privileges, AcademicSnapshot, phase6-dev, server}.php`
  - `tools/workspace/phase6-control.py`, now `--profile phase7-dev|phase6-dev`.
  - `tools/test-phase6-{http,ui}.py` (profile-aware).
  - `tools/test-phase7-{http,ui}.py` (new).
- **Hygiene:**
  - `.gitignore` now ignores `__pycache__/`.
  - Stray `.pyc` files that Phase 6 commits `13dd9d1` and `9a44603` had picked up are untracked here. They are still present in the preserved Phase 6 branch; remove them at integration.

## Tests run (synthetic `phase7-dev` only)

| Suite | Result |
|---|---|
| PHP lint (28 changed PHP files), `node --check`, Python compile | All passed |
| PHPUnit `phpunit.phase7.xml` (real DB, exact snapshot restore per test) | **38 tests, 2661 assertions, OK.** This includes the inherited Phase 6 suites and the new `PickupContractTest` (4 tests, 544 assertions). |
| ↳ `PickupContractTest` | Reads, privacy and gates (including env narrowing and academic/phase6 refusals); native validation order; exact rows, history and notifications for every operation; release availability; every coordinator fault seam for schedule, complete and release; mid-operation SQL failures with no partial writes; advisory lock contention; lost commit acknowledgement (committed, not replayed, audit not duplicated) |
| `test-phase7-http.py`, live workers 8018 + 8019 | **81 checks passed in each of 5 runs.** Every ordering was seen for: double schedule, confirm return vs release, reschedule vs cancel, and release vs a new claim. Pickup invariants held. |
| `test-phase6-http.py` on `phase7-dev` (regression) | 77 checks passed |
| `test-phase7-ui.py` (Playwright, 1440 light / 390 dark, fresh browser per phase) | 10 checks passed: BM schedule, reschedule, cancel, return and release from the Handover tab; the Student's appointment, Returned and Released outcomes; a read-only notification opening the pickup. No JS errors, no 5xx, no read-marking POSTs. |
| `test-phase6-ui.py` on `phase7-dev` (regression) | 15 checks passed |
| Restore and protection | The `phase7-dev` state was identical after restore and the grant audit passed. Academic grants are identical to the 6B evidence. The `phase6-dev` data and its 62 grants are unchanged. The 6B archive (`c1f4bde6…`) and canonical tree (19 files) are unchanged. The Phase 6 branch is clean at `1ea27c5`. |

A defect found and fixed during testing: a race assertion was too narrow. When release wins, native returns `404 Pickup record not found.` to a concurrent confirm-return, because release deletes the row. The test now accepts that native loser code. The application was correct.

## Manual demonstration

1. Start the profile and its worker (the second command runs `init` first if it isn't provisioned):

   ```powershell
   python -B C:\ReFind-Laravel\laravel-p7\tools\workspace\phase6-control.py start --profile phase7-dev
   ```

2. Sign in at `http://127.0.0.1:8018/login.html`. Credentials are in `state/phase7-dev/credentials.json`, which is private and outside Git.
3. Student journey: claim a Found report, then BM approves it in **Review Queue → Claims**.
4. BM journey in the **Handover** tab, or via "Schedule pickup now": schedule (pickup area, plus a Jakarta date and time), reschedule (with a reason), cancel the appointment (with a reason), confirm the item returned (tick required), or release (reason and tick).
5. The Student follows along in **My Activity → Claims** (appointment, Returned or Released) and **Notifications** (read-only).
6. To restore afterwards, stop the worker, then run `tools/phase6-dev.php restore <snapshot>` with `REFIND_PROFILE=phase7-dev`. The post-provision snapshot ID is in `state/phase7-dev/tmp/post-provision-snapshot.txt`.

## Known differences and pending decisions

- **Phase 6 decisions are unchanged and still pending sign-off:** the stronger claim revision digest (it adds the latest `CLAIM_SUBMITTED` notification ID), and native REJECTED → CONFIRMED pair conversion on approval. Neither is active on academic profiles.
- **Handover revision** uses the native digest. All its fields are BM-authored, so an identical digest means identical pickup facts. No divergence.
- **Advisory lock:** pickup operations take the shared workflow lock, which matches native `workflow_lock`.
- **Notification read-marking:** unavailable pending an owner decision and a reviewed grant. The unread count therefore does not decrease when notifications are viewed.
- **Native release** deletes the handover row. A concurrent loser therefore receives 404 rather than 409; this is native behaviour.

## Outstanding acceptance gates

- **Phase 6 gates, still separate:**
  - native HTTP comparison for 6B–6E
  - the full 33-suite matrix
  - fresh-Windows qualification
  - academic grant activation
  - divergence sign-offs
- **Phase 7 gates:**
  - native HTTP comparison for pickup reads and operations
  - inclusion in the full matrix with fresh receipts
  - fresh-Windows qualification
  - reviewed activation of the `pickup` grants and stage for academic profiles

## Phase 8 handoff: integrated verification and academic evidence

1. **Integrate for review.** Diff `b70b711..migration/phase7-impl` (Phase 6 plus Phase 7). Decide the integration branch name. Re-pin `test-academic-guards.py` and extend `VerificationReceipt` / `verify-phase5.py` beyond phase 5 for the Phase 6/7 suites. Decide the `Workspace::PROFILES` checkout binding for the integrated tree. Remove the stray Phase 6 `.pyc` files.
2. **Native comparison.** Use the external comparator kit, isolated and never same-host, for claims, BM review, approval, pickups and notifications. Compare codes, shapes, recipients, history and media hashes; normalize IDs, times and filenames.
3. **Full matrix.** Run the inherited 33 suites plus the Phase 6/7 PHPUnit, HTTP, race and browser suites with fresh source-bound receipts, exact restore, protection and resource gates, and owned shutdown.
4. **Grant activation.** This is a separately authorized, reversible activation of `grant-claims.php` (student, review, approval and pickup) on `academic-dev` and `academic-test`. Verify with an exact `report-privileges.php` audit before and after.
5. **Fresh Windows.** External install and restart qualification at the accepted SHA.
6. **Academic evidence.** Obtain the authenticated WDD rubric. Map each mandatory item to a visible Student or BM journey (report → claim → review → approval → pickup → return/release) and a receipt. Produce desktop/mobile and light/dark screenshots or recordings from synthetic data. Document the Laravel architecture mapping, privacy (private evidence, notes, audit) and recovery evidence.
7. **Open owner decisions.** Notification read-marking (and its grant); the BM Reports tab and manual status; the history page; the two Phase 6 divergences.
