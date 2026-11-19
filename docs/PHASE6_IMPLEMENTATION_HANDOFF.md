# Phase 6 implementation handoff (6B integration, 6C–6E): unaccepted WIP

19 November 2026. Prepared for independent Codex verification and formal checkpoint decisions.

**No checkpoint is accepted.** 6B, 6C, 6D and 6E are implemented and tested on a disposable synthetic profile only. Native HTTP parity, the full inherited matrix, fresh-Windows qualification and academic-profile activation are still pending (see the last section).

## Where the work is

| Item | Value |
|---|---|
| Worktree / branch | `C:\ReFind-Laravel\laravel-p6`, `migration/phase6-impl`. Local only: no push, merge or deploy. |
| Base | Accepted 6A `b70b711` |
| WIP commits | `f852eb6` 6B restored · `13dd9d1` dev profile · `fcfa442` 6C · `3025c78` 6D · `439cba2` 6E · this handoff commit |
| Canonical 6B candidate | Untouched: still uncommitted on `migration/phase6-6b` in `C:\ReFind-Laravel\laravel` (19 files matching the archive). The archive is still `c1f4bde6…2844890`. |
| 6B restore proof | All 19 archive entries were verified against the archive manifest. All 278 entries of `focused-20261118T095345Z-051fc8c7/source-manifest.json` match the tree: 180 exact bytes, plus 98 that are equal after line-ending normalization (the canonical checkout has CRLF working copies; this worktree checks out LF per `.gitattributes`). |

## Isolated development profile `phase6-dev`

- **Identity.** Schema `refind_laravel_p6_dev`, principal `refind_p6_dev_app@127.0.0.1` and cookie `refind_laravel_p6_dev`. Workers run on `8016`/`8017`. State lives in `state/phase6-dev`, with web process records in `state/phase6-processes.json`.
- **Shared server.** The profile runs on the migration-owned portable MariaDB at 3307. It never touches the academic schemas, principals, grants, workers or state.
- **Checkout binding (`Workspace::PROFILES`).** `laravel-p6` can only boot `phase6-dev`, and `phase6-dev` can only run from `laravel-p6`. The accepted profiles only boot from `laravel`.
- **Fail-closed gates (`App\Support\ClaimStages`).**
  - Stages are `student`, `review` and `approval`, and they exist only for `phase6-dev`.
  - `REFIND_PHASE6_STAGES` can only narrow the set on `phase6-dev`. It can never enable a stage elsewhere.
  - Claim stages are deliberately not read from config.
  - Disabled stages return the pre-Phase-6 `501 feature_pending` contract, after the role and CSRF checks.
- **Launcher `tools/workspace/phase6-control.py`** (`init | grants | start [--second] | stop [--db] | status`).
  - Every control action re-verifies live ownership: psutil pid, create time, exe and cmdline, plus port probes.
  - `stop --db` refuses while another profile serves or any non-system session is connected (checked with `PROCESSLIST`).
  - The shared `db` record stays in `academic-processes.json`, so the canonical launcher can still stop the database.
- **Grants `tools/grant-claims.php <student|review|approval> [--revoke]`.**
  - Activation is limited to `phase6-dev`. Academic activation needs a reviewed change to `ACTIVATION`.
  - It checks live quiescence first: no listener on 8016/8017 and no app sessions.
  - Before and after grant maps are written to `state/phase6-dev/grants-*.json`.
  - Revoke must run in reverse order, and it refuses while a later stage is still granted.
  - Stage deltas:
    - **student:** `claims` INSERT(item_id, related_lost_item_id, claimant_user_id, ownership_answer); `claims` UPDATE(ownership_answer, related_lost_item_id, claim_status, reviewed_by_user_id, review_note, reviewed_at); `claim_photos` INSERT(claim_id, file_path) and DELETE.
    - **review:** no additional grants.
    - **approval:** `item_matches` INSERT adds (match_status, confirmed_by_user_id, confirmed_at); `item_matches` UPDATE(match_score, match_status, confirmed_by_user_id, confirmed_at); `handovers` INSERT(claim_id, pickup_location_id, handled_by_user_id, notes).
  - Pair identity columns, claim/handover DELETE, notification mutation and account writes all stay denied. `report-privileges.php` enforces the exact `phase6-dev` set; the academic expectations are unchanged.
- **Helpers.** `tools/phase6-dev.php` provides `save`, `restore <id>`, `state`, `seed-students` and `hold-lock <s>`. `phpunit.phase6.xml` runs the suite on `phase6-dev` only.

```powershell
python -B C:\ReFind-Laravel\laravel-p6\tools\workspace\phase6-control.py init
```
```powershell
python -B C:\ReFind-Laravel\laravel-p6\tools\workspace\phase6-control.py grants
```
```powershell
python -B C:\ReFind-Laravel\laravel-p6\tools\workspace\phase6-control.py start
```

## Implemented workflows (native 47eea86 semantics)

| Slice | Implementation |
|---|---|
| 6B | The 6B reads are preserved as restored. The staff detail revision now uses the shared `App\Support\ClaimRevision` digest. Inherited 501 assertions are stage-aware: still 501 on accepted profiles, native validation results on `phase6-dev`. |
| 6C | `ClaimService` and `ClaimController` (`POST /api/claims.php`). Covers submit; resubmit; submit on a `NEED_MORE_INFO`/`WITHDRAWN` row reusing the same record; withdraw; the optional own-Lost link; one active claim per Lost; answer 8–1000 characters; private evidence (keep, replace or remove, at most 2, and foreign or duplicate IDs rejected); `REJECTED` as final; the `VERIFICATION` transition; history; the `CLAIM_SUBMITTED`, `CLAIM_QUEUED` and `STATUS_CHANGED` notifications; and settling to idle on withdraw. `claim.html` is a Blade binding of the native page, served only under the Student stage. |
| 6D | `ClaimReviewService` and `StaffClaimController` (`POST /api/staff.php action=review`). Covers note and confirm validation; a constant-time fresh revision (missing, malformed, stale or repeated revisions return 409); request more information (from `PENDING` only); reject (from `PENDING` or `NEED_MORE_INFO`) with native settle and history rules; and private notifications. `action=status` stays 501. The BM drawer shows only the enabled decisions. |
| 6E | Linked and unlinked approval; the confirmed pair at the native recomputed score; Found and Lost moved to `HANDOVER`; one `PENDING` handover (at the Found's location, `'Awaiting a pickup appointment.'`); competitor closure on the same Found; legacy shared-Lost closure with a recheck under lock; `SUGGESTED` cleanup and neighbour settling; and native notification order. The response carries a string `handover_id`. Pickup endpoints and handover reads stay 501 (Phase 7). |

All writes go through `WriteCoordinator`, which provides the shared advisory lock, ordered parent locks, staged media with the operation ledger, reconciliation, and no automatic retry.

## Tests run in this session (synthetic `phase6-dev` only)

| Suite | Result |
|---|---|
| PHP lint (32 changed PHP files), `node --check` (student.js, bm.js), Python compile | All passed |
| PHPUnit `phpunit.phase6.xml` (Unit + Feature, real DB, exact snapshot restore per test) | **34 tests, 2114 assertions, OK** |
| ↳ `ClaimWriteContractTest` (6C) | Gates, including env overrides for `academic-dev`/`academic-test`; the native contract; privacy; every coordinator fault seam; SQL failure; lost commit acknowledgement; real `KILL CONNECTION` |
| ↳ `ClaimReviewContractTest` (6D) | Validation order; stale and repeated revisions; the same-second probe; the resubmit loop; competitor and suggestion settling; lock contention (bounded wait, busy 409); rollback |
| ↳ `ClaimApprovalContractTest` (6E) | Invariants; competing and stale approvals; blocked Lost; `REJECTED`-pair conversion; mid-approval SQL failures with no partial writes; acknowledgement loss leaving exactly one handover |
| `tools/test-phase6-http.py` on live workers 8016 + 8017 | **78 checks passed, in 3 runs.** Real two-worker races: competing approvals (exactly one wins, ×2 per run), withdraw vs approve (both orderings seen), one claim per Lost, duplicate submit, submit vs report delete, resubmit/relink vs review (both orderings seen). Global invariants held. |
| `tools/test-phase6-ui.py` (Playwright, installed Chrome, 390 dark / 1440 light, reduced motion) | **15 checks passed:** claim with photo, request more info, resubmit keeping evidence, stale drawer denied with draft kept and then refreshed, approval with no pickup shortcut, approved outcome, withdraw. No JS errors, no 5xx, no handover calls. |
| Grants | `phase6-dev` exact audit passed (62 column grants, 5 table grants, 12 cross-schema denials, 13 protected-mutation denials). The revoke/regrant round-trip restored the identical map. Academic grants read back unchanged against the 6B evidence (global, schema, table, 39 column grants per profile). |
| Restore | Each PHPUnit test restored exactly (rows, media, counters). The HTTP/browser snapshot restore gave an identical state dump. |

Tooling fixes made along the way:
- `AcademicSnapshot` restore rejected `_write_operations.manifest_sha256`, because its column regex had no digits. This was a latent bug for any snapshot taken after a committed write.
- `tools/server.php` now allows ports 8016/8017 for `phase6-dev` only.
- `report_test_client.py` reads its base URL and profile from env; the defaults are unchanged.

## Review readiness (19 November 2026)

- **Owner manual demo.** The owner manually tested the integrated Phase 6 journey in a browser on `phase6-dev` (worker 8016) and **found no issues**. No code changed as a result.
  - The demo covered: submit with a linked Lost report and a private photo, request more information, resubmit, approval with one pending handover, withdrawal, rejection, competing-claim closure, stale-decision refresh, and role/privacy checks.
  - Demo reports were created through the application's own API and two extra synthetic Students through `phase6-dev.php seed-students`. Credentials were kept in a private local file under `state/phase6-dev`, which was deleted afterwards.
- **Baseline hygiene.** Before the demo, one leftover synthetic report from an earlier interrupted test run was found. That run's restore had hit the 12-connection limit, which has since been fixed in the harness. The data was reset to the clean post-provision snapshot. After the demo, the pre-demo restore point was restored.
- **Confirmation regression on `9a44603`** (unchanged code, after the demo):
  - PHPUnit: 34 tests, 2114 assertions, OK.
  - `test-phase6-http.py` on two live workers: 78 checks passed, every race had exactly one winner, and the global invariants held.
  - `test-phase6-ui.py`: 15 checks passed.
  - The `phase6-dev` snapshot restore produced a state identical to before the run, and the exact grant audit passed.
  - The owned workers and database were stopped (records empty; ports 3307, 8016 and 8017 free).
- **Protection check.** The 6B archive is still `c1f4bde6…`. The canonical uncommitted 6B tree (19 files) and the accepted branches are unchanged.
- **Review target.** `migration/phase6-impl` at the commit that adds this section (on top of `9a44603`). Diff against `b70b711` for the full Phase 6 change set.

## Native divergences to compare and sign off

1. **Revision digest.** The probe confirmed native token reuse when a claim goes `PENDING → NEED_MORE_INFO → PENDING` within one second, because `updated_at` has second precision. Laravel additionally hashes the latest claimant `CLAIM_SUBMITTED` notification ID (0 for legacy rows). The wire shape is unchanged, so compare token behaviour, never raw token equality.
2. **Advisory lock on Student writes.** Native submit and withdraw use row locks only; Laravel serialises them on the shared workflow lock. This is a strengthening. A contention 409 carries the native claim conflict text.
3. **Same-pair `REJECTED` → `CONFIRMED`** on explicit approval is preserved from native. It is implemented as a locked read plus an explicit UPDATE instead of an upsert. **Sign-off needed** before approval is activated on academic profiles.
4. **Upload messages.** Claim uploads map the stager's messages to the native wording. The report-upload messages are unchanged.
5. **UI gates.** `student.js` and `bm.js` (recorded in `SOURCE_PROVENANCE.json`) keep the queue claims-only and suppress handover and pickup navigation. Approved claims show the native pending-pickup note without calling `handovers.php`.

## Gates still pending (not passed)

- Independent native HTTP comparison for 6B–6E. Use the existing comparator kit; same-host runs are not a substitute.
- The full inherited 33-suite matrix with fresh receipts. `VerificationReceipt` and `verify-phase5.py` are still phase-5 scoped, and `test-academic-guards.py` still pins `migration/phase6-6b`. Re-pin both to the approved branch when it is accepted.
- Fresh-Windows install and restart qualification (external).
- Reviewed activation of `grant-claims.php` for `academic-dev`/`academic-test`, plus integration into the canonical checkout. The checkout binding in `Workspace::PROFILES` then needs a decision.
- WDD rubric mapping.

## Suggested Codex verification order

1. Check out `439cba2`, or this handoff commit, in `laravel-p6`.
2. `git diff b70b711..HEAD` for review.
3. Run `phase6-control.py init` and `grants`, then PHPUnit with `phpunit.phase6.xml`.
4. `phase6-dev.php save`, `seed-students`, then `start` and `start --second`, then `test-phase6-http.py`.
5. Stop the second worker and run `test-phase6-ui.py`.
6. `stop`, then `phase6-dev.php restore <id>`.
7. Then the native comparison and the formal gates above.
