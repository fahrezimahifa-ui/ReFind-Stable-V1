# Phase 5 Checkpoint A targeted recovery — stopped at verification gate

Date: 17 November 2026. Branch: `migration/academic-b`.

**Not accepted. No commit was created.** HEAD remains `af7b349bd7bdcaa2845bc7ae4ac9a04c542dc31b` (accepted Phase 4). All existing Phase 5 work and the recovery changes remain uncommitted. Checkpoint B was not started.

## Preservation before changes

The 37 pre-existing changed/untracked files were archived before the recovery edits at:
`C:/ReFind-Laravel/state/recovery-checkpoint-a/20261117T083913Z-35f0377c/uncommitted-before.zip`.
The adjacent `manifest.json` records their original hashes and base commit. ZIP integrity was checked after stopping. No reset, discard, dependency installation, grant, push, merge, or deployment was performed.

## Corrections prepared

| Requested correction | Changes | Verification status |
| --- | --- | --- |
| Guarded driver initialization | Register the guarded MariaDB factory in `AppServiceProvider::register()` through the database manager before boot; fail closed if a connection is already cached. Preserve target-server, datadir, schema, and workspace-owner guards. | PHP syntax passes; application boot verification not reached. |
| Non-resolving metrics | Subscribe to `QueryExecuted` through the event dispatcher. Add a metrics switch and middleware bypass; remove boot-time `DB::listen()`. | PHP syntax passes; live behavior unverified. |
| Metrics transaction regression | Fresh-process bootstrap checks for both settings and a PHPUnit test for guarded connection class, lazy PDO, PDO/server identity, pinned transaction ownership, forbidden reconnection/replacement/transaction control, and rollback. | Added; not executed because the earlier verification gate failed. |
| Standalone CLI failures | Wrap all initialization in fail-closed handling, restore a nonzero uncaught-exception handler after Laravel bootstrap, and add a fatal-error shutdown guard. | PHP syntax passes; early-failure integration fixtures not reached. |
| Fresh receipts | Shared process-plus-receipt acceptance protocol; unique run/invocation/suite/profile/checkpoint identities, positive counts, completion flags, timestamps, fresh destinations, atomic emitters. Existing suites now emit completion receipts. Composer/lint/PHPUnit adapters validate their results before emitting. | Three positive adapters passed. Eight negative cases were rejected correctly. Entire protocol is not accepted due to the fixture defect below. |
| Early initialization negative test | Add subprocess fixtures for exceptions/fatal errors before a local try/catch and invalid-profile failure during actual bootstrap. Require nonzero exit, no PASS text, and no completion receipt. | Added; not reached. |

No report CRUD/media business behavior, matching/history/notification implementation, grants, runtime configuration, or XAMPP configuration was changed by this recovery. Existing uncommitted CRUD/media work was retained.

## Full Checkpoint A attempt

Run ID: `0d3ea557a4304f1f917d6edf20665598`.

Evidence directory:
`C:/ReFind-Laravel/evidence/phase5/verification-crud-20261117T084855Z-0d3ea557`.

| Gate | Result |
| --- | --- |
| Initial resource preflight | PASS: 2,418 MiB available RAM; 36.07 GiB free disk. Required gates remain 2 GiB / 5 GiB. |
| PHP syntax | PASS: 82 files; fresh identified receipt. |
| Composer strict validation | PASS: fresh identified receipt. |
| Platform requirements | PASS: 18 requirements; fresh identified receipt. |
| Verification protocol negative tests | **FAIL; stopped.** Child exit 1; no suite completion receipt; no runner PASS for this suite. |
| Metrics bootstrap on/off | Not reached. |
| Isolation guards / privilege matrix / write foundation | Not reached. |
| Report CRUD, ownership, private uploads, compensation and reconciliation | Not reached. |
| PHPUnit, authentication, CSRF, HTTP, concurrency, browser UI/themes/responsive | Not reached. |
| Full database/media restoration and PoC database fingerprint regression | Not reached. |

The complete Checkpoint A suite was invoked, but cannot be reported as completed or accepted. Earlier Phase 5 results are not substituted for the unexecuted gates.

## Exact stop reason and remaining correction

The new negative child assigns `suite = 'foreign-suite'`. The parent also invokes that case using the expected suite label `'foreign-suite'`. Consequently the receipt still has the correct expected identity and is accepted; the negative test raises `AssertionError: Negative verification was accepted: foreign-suite`.

This is a defect in the new test fixture: it did not create a mismatched identity. It is not evidence that the comparator accepted different suite values. The fixture must use a value demonstrably different from its expected suite (and assert that distinction) before a future authorized full run. No fixture correction or test continuation was performed after the failed acceptance gate.

The eight preceding negative cases rejected missing receipts, nonzero exit despite a receipt, malformed JSON, incomplete status, failed status, zero checks, boolean check counts, and stale timestamps. Foreign run/invocation/profile/checkpoint cases, the positive control, pre-existing receipt rejection, and actual early PHP initialization failures were not reached. Their success is not claimed.

## Post-stop safety observations

- The verification run stopped before `control.start_db()`; MariaDB and application web/browser workloads were never started in this run. No SQL verification or grant command ran.
- No native database connection was made. No native installation HTTP request was made. Native files were accessed only for read-only preservation checks.
- All 237 protected original PoC/private files match their baseline; original PoC branch remains `ac03a9dae33c5d03318ac357fdb0c31abcaffa75`.
- All six native-installation/shared-configuration groups match the Phase 5 baseline. All ten pre-existing native uncommitted paths are preserved.
- Owned ports 3307, 8013, 8014, and 8015 are closed; academic process records are empty. Runtime data and credentials were preserved.
- No privilege changes occurred. Academic-dev remains SELECT-only; existing approved B-test CRUD/photo privileges were neither expanded nor re-granted.
- Lowest sampled available RAM was 2,342 MiB. Disk and initial RAM gates passed. No application/DB/browser resource or query-count measurements are claimed for the unexecuted workloads.

## Review artifacts

- `summary.json`: failed/incomplete overall run and passed-gate receipt paths.
- `suites/php-syntax.json`, `suites/composer-validation.json`, `suites/platform-requirements.json`: fresh positive receipts.
- `suites/verification-protocol.txt`: exact failing test traceback; there is no positive protocol suite receipt.
- `suites/negative-fixtures/`: deliberately invalid receipts and subprocess logs retained as negative artifacts, not acceptance receipts.
- `post-stop-preservation.json`: read-only preservation audit after shutdown.

Stop for review. Checkpoint A has no accepted Git SHA. Matching/history/notifications and Checkpoint B remain outside this recovery.
