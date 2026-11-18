# Phase 5 Checkpoint B — stopped at native matching parity gate

17 November 2026. **Not accepted; no Checkpoint B commit.** HEAD remains `f004beaadc691bc2aa39f6d3d89b28184ad0b972` on `migration/academic-b`. Checkpoint A is preserved. Do not start Phase 6.

## Implementation prepared

Added a separate native-equivalent RuleMatcher (threshold 55; category 25, location 25, words up to 35, dates up to 15), transactional ReportEffects, exact history/notification messages, suggestion replacement, silent loss-of-suggestion resets, and preserved confirmed/rejected/active workflow behavior. All effects use the existing pinned coordinator connection and ordered parent locks. ReportEffects does not implement claims, BM decisions, pickups, accounts or AI.

Existing receipts were extended to accept the newly authorized matching checkpoint with the same exact-identity/freshness rules. All Checkpoint A suites remain in the runner. Tests were added for native state replay, pure scoring boundaries, SQL-effect failure injection, lost connections/commit acknowledgement, concurrent create/edit/delete, dev-profile restoration and matching HTTP/notification ownership. Existing forbidden operations remain tested; the foundation suite removes only the now-permitted match DELETE denial and retains denial of match-status edits and history/notification UPDATE/DELETE.

AcademicSnapshot now permits only the two fixed, ownership-guarded B schemas, using their exact profile-bound table sets (17 test / 16 dev), and refuses restoration while web processes run. It does not accept arbitrary schemas. Original PoC/native targets remain refused.

## Verification result

Preparation receipts: `C:/ReFind-Laravel/evidence/phase5/checkpoint-b-preparation-20261117T114735Z-22ccdc2d`.
Full failed run: `C:/ReFind-Laravel/evidence/phase5/verification-matching-20261117T115132Z-da4cdb8d`.

Passed: resource preflight; PHP syntax (90 files); Composer strict/platform (18); receipt protocol (21); lazy guarded metrics bootstrap (4 disabled + 4 enabled); isolation guards (69); exact privilege audits (21 per profile); transaction/media foundation (97); report service CRUD/media with effects enabled (28).

**FAIL:** native-matching-parity exited 1. The runner recorded completed=false/passed=false, did not emit a PASS or success receipt for that suite, and stopped all owned runtimes.

Concurrent matching, dev reporting/restoration, pure scoring PHPUnit tests, remaining security/session regressions, report/matching HTTP and browser suites, full final fixture restoration and original PoC DB fingerprint recheck were not reached. No completion or functional parity is claimed for B.

## Failure evidence and diagnostic limit

The native replay completed and its controlled snapshot restoration was verified. The first Laravel replay is an edit of synthetic LOST report 100 against the controlled eligibility fixtures. It produced operation `43527dd1dc16809fa273dec4fb185de7` and failed before a state-parity comparison.

The captured stderr is exactly `App\Foundation\WriteFailure: The request could not be completed.` The existing WriteFailure mapping identifies this as a known rollback, non-conflict server error; the manifest is complete and records `advisory_released`, with no new/removed media. It does not indicate an uncertain commit.

**The underlying SQL/runtime cause is unconfirmed.** The new parity test's outer catch printed only the wrapper class/message and omitted getPrevious()/SQLSTATE and progress context. No current nested exception was written to Laravel's private log. Consequently the current artifacts cannot establish which inner statement failed. This diagnostic gap must be corrected narrowly before another authorized replay; no claim is made that it is a matching-score, grant, or connection defect. No additional harness architecture, repair, test rerun, database inspection/startup or automatic restoration was attempted after the failed gate.

## Database privileges and retained state

Both academic profiles received only the approved B matching/report grants. Academic-test adds items.status_id/resolved_at UPDATE, match INSERT (lost/found/score), score UPDATE, match DELETE, history INSERT and notification INSERT. Academic-dev adds the equivalent report item/photo/append-only ledger grants plus those effects. Neither profile gained global/schema-wide write, grant option, DDL, cross-schema access, match-status/confirmation changes, notification read marking, or history/notification UPDATE/DELETE.

Exact added column/table privileges and the successful audit paths are in `privilege-changes.json`. Grants remain in place; they were not silently revoked. Keys, cookies, credentials and runtime configuration were not changed. Both profile/credential byte fingerprints match their pre-grant baselines.

Original pre-grant B snapshots: academic-test `874dee9b57e498266bc7edd76d86d0d0`; academic-dev `10ec25cc7391378807fefc4253e8bdc2`. The parity suite separately saved the original eight-item B-test fixture as snapshot `f02f4a320cb72eee7c2b8f926d7eed59`, then a controlled sixteen-item fixture as `16fdf279e0bfc16869319fda02a4b933`.

The B-test matching failure state is retained for diagnosis; it was not restored to the original eight-item data after failure. Rolled-back inserts may advance AUTO_INCREMENT counters. Baseline snapshots and source work remain available for explicit safe recovery. B-dev data was only snapshotted before grants; its CRUD smoke suite did not run. Do not start the unverified academic application before recovery/review.

## Protected boundaries and resources

Read-only post-stop protection audit: 237 original PoC/private files checked; mismatches: 0. Six native/shared-configuration groups checked; mismatches: 0. Ten native pre-existing uncommitted paths are preserved. Original PoC branch remains `ac03a9dae33c5d03318ac357fdb0c31abcaffa75`. No native database connection occurred, no original PoC/native schema was opened with a write-capable identity, and no native media/installations/XAMPP settings were changed.

All owned DB/web ports 3307/8013/8014/8015 are closed and academic process records are empty. Final full database fingerprint verification was not reached; this limitation is recorded rather than substituting old receipts.

Initial full-run preflight: 3,103 MiB available RAM / 35.90 GiB disk. Minimum sampled available RAM: 2,913 MiB; peak PHP RSS: 43.2 MiB; owned MariaDB: 117.5 MiB. Browser workload was not reached. No resource gate was lowered. Only the optional Widgets UI was closed during user-authorized cleanup; Task Manager denied closure and was left alone. Active applications and services were preserved.

Uncommitted source work archive: `C:/ReFind-Laravel/state/recovery-checkpoint-b/stopped-20261117T115817Z-7445a048/uncommitted-work.zip`, with hash manifest. No reset, discard, push, merge, deployment, new dependency, Laravel project or XAMPP/service configuration change occurred.

The repository's existing AGENTS.md requires: “Stop if either consistency/safety gate fails.” This failed checkpoint is left for review. The next targeted recovery should retain snapshots/work, capture the existing exception chain privately, identify the actual failed statement, apply only the necessary fix, then rerun the entire B matrix with all A regressions before any commit.
