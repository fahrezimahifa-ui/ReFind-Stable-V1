# Checkpoint B targeted diagnostic recovery — stopped at a new fixture gate

17 November 2026. **No accepted Checkpoint B and no new commit.** HEAD remains `f004beaadc691bc2aa39f6d3d89b28184ad0b972` on `migration/academic-b`. Accepted Checkpoint A and original PoC remain preserved; no Phase 6 work.

## Verified original root cause

Existing evidence was inspected with services stopped. It established only a known rollback/advisory release, not the inner cause. Before reproduction, all 22 pre-existing uncommitted files, the quiescent portable database directory and both B-profile directories (930 private files), plus hashes of 60 existing evidence files, were preserved under `C:/ReFind-Laravel/state/recovery-checkpoint-b/diagnostic-20261117T120409Z-85556170`. Both ZIP archives passed integrity checks.

Only CLI tooling gained private diagnostics: complete nested exception classes/codes/available SQLSTATE and driver codes; argument-free stack frames; failed replay/coordinator context; sanitized parameterized query templates/hashes and binding types, never binding values. QueryException's interpolated message is omitted. Quoted text/DSNs/secret assignments are redacted. Files are stored in the existing private B-test snapshot directory. No diagnostics are registered in HTTP and Contract error rendering is unchanged.

A controlled `--diagnose-first` invocation saved the retained sixteen-item state as snapshot `3b0354e643d11d016fb7c10a9cb3189f`, restored the approved controlled fixture, and reproduced report 100's first update using only guarded `refind_laravel_b_test` on owned port 3307. It returned nonzero and emitted no passing receipt.

Private diagnostic: `C:/ReFind-Laravel/state/academic-test/snapshots/matching-diagnostic-3da4d0c906c43d372419bc912e8f1b65.json`.
Diagnostic run: `C:/ReFind-Laravel/evidence/phase5/checkpoint-b-diagnostic-20261117T120646Z-177336da`.

The chain is WriteFailure -> Illuminate Database QueryException -> PDOException. SQLSTATE **42000**, MariaDB error **1143**, denied **UPDATE of item_matches.lost_item_id** on the duplicate-key upsert path. The existing account deliberately has INSERT on the two identity columns and score, but UPDATE only on match_score. This is direct evidence from the approved runtime, not a guess about the algorithm or a lost connection. The operation remained known-rollback; all 17 table row sets and authoritative media were unchanged. Post-diagnostic snapshot: `23fa1271f43babd63e693f955203cee2`.

## Minimal correction applied

In ReportEffects only, replace the duplicate-key upsert with a locked pair lookup under the existing coordinator/advisory/ordered parent locks. Insert a missing pair; update only match_score for an existing SUGGESTED pair; preserve CONFIRMED/REJECTED rows exactly. The native eligibility, threshold, weights, status, notification and history code is unchanged. No privilege was added or relaxed. The existing privilege audit additionally contains explicit negative UPDATE tests for both identity columns; those new audit cases still await the complete matrix.

## Recovery and focused verification

Using existing maintenance tools, operations `43527dd1dc16809fa273dec4fb185de7` and `f449cda312d8da4d00ce70281d2db50d` were reconciled as rolled_back/complete. Approved original B-test snapshot `f02f4a320cb72eee7c2b8f926d7eed59` was then restored with exact database/media/schema/counter verification and a fresh complete restoration receipt.

The focused parity suite ran next, with fresh identity-bound receipt requirements. It completed **15 checks across the first two replay steps**, including item/match/history/notification equality against native output, exact threshold, confirmed/rejected preservation, no repeated alert, and private-data exclusion. This demonstrates progress past the original upsert failure; it is **not** a complete suite pass.

**New gate failure:** replay step 3 uses category_id **2**, but the approved categories fixture has **Bags, is_active=0**. ReportService correctly throws ContractError 422, validation_error, “Choose an active category.” before entering a third write transaction. The oracle replay wrapper bypasses report input validation, so its category-switch scenario incorrectly admitted this inactive input.

Private diagnostic: `C:/ReFind-Laravel/state/academic-test/snapshots/matching-diagnostic-10f25b629a58812eead0bad081c13990.json`.
Immutable original-snapshot evidence: `727d5e43304154ce467ac37a2e988e6f.json`, categories table: ID1 Electronics active=1, ID2 Bags active=0, ID3 ID/Wallet active=1. The stack identifies ReportService field/values validation; there is no SQLSTATE or driver error for this validation failure. The diagnostic coordinator_stage value `after_commit` belongs to the last successful step; its separate replay stage and stack establish that step 3 failed before the coordinator.

Focused run evidence: `C:/ReFind-Laravel/evidence/phase5/checkpoint-b-focused-recovery-20261117T121014Z-9ba58f39`. There is **no passing native-matching-parity receipt**. Full Checkpoint B acceptance (including all A regressions, concurrency, PHPUnit, HTTP/browser, privilege re-audit and final restoration/fingerprints) was not started after this failure. No further source correction or test rerun was attempted after the new gate.

## Minimal proposed next correction — not applied

Use verified active category **3** as the second category, distinct from active category 1, consistently in the new B-only fixtures: parity candidate 400 and replay steps 3/8; concurrent edit/delete; matching HTTP category edit. Add a small fixture precondition asserting the selected IDs are distinct and active before fixture writes. Keep category 2 inactive and retain every existing inactive-category rejection test; do not change ReportService validation or database categories. Reset coordinator diagnostic context at each replay so it cannot carry the preceding step's success stage.

This is a fixture correction within the existing tests, not a new test framework. After authorization it must be followed by explicit guarded state recovery, focused parity first, and then the complete B matrix only on focused success.

## Retained state, safety and resources

The focused suite created a new controlled snapshot `58ed7739eda1a42d8f6de0f3009fe563` and committed its first two synthetic replay operations. That failure state is retained; it was not silently reset after step 3. Original baseline, controlled, retained-state and diagnostic snapshots/evidence remain available. B-dev business data was not mutated during this recovery; both B grant sets were unchanged. Do not start the unverified academic app before review/recovery.

The stopped database/B-profile state and uncommitted source were archived again at `C:/ReFind-Laravel/state/recovery-checkpoint-b/after-diagnostic-stop-20261117T121506Z-e3c32398`. All old evidence hashes and unrelated pre-existing source hashes remain unchanged. Private detailed diagnostics are not copied into Git or public responses.

Post-stop read-only protection audit: 237 original PoC/private files and six native/shared-configuration groups checked, zero mismatches; ten native pre-existing dirty paths preserved. Original PoC branch `ac03a9dae33c5d03318ac357fdb0c31abcaffa75` is unchanged. No native database was contacted. No original PoC/native schema was opened with write-capable credentials. Source HEAD is still Checkpoint A; no reset, discard, grant/revoke, push, merge, deployment, XAMPP change or new framework/dependency occurred.

Owned ports 3307/8013/8014/8015 are closed and process records empty. Fresh resource preflights passed at 2,983 MiB for reproduction and 3,043 MiB for focused recovery; disk 35.89 GiB. The 2 GiB/5 GiB gates were unchanged. No browser workload ran, and no complete-matrix resource measurements are claimed.

Stopped immediately at the new consistency gate, as requested. Checkpoint B remains for review.
