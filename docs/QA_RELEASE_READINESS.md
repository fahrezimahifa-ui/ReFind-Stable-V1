# ReFind Laravel: final QA, parity and release readiness

19 November 2026 · QA branch `migration/qa-final` (worktree `C:\ReFind-Laravel\laravel-p8`) · **Classification: functionally verified, with external qualifications pending.** This is not a formal acceptance.

> Later work: the professor UI/UX refinement and two parity fixes are on `migration/uiux-refinement`. After the owner-review corrections and the batch 4 hierarchy and guidance pass, the integrated regression passed on `b0bba20`; see [UIUX_REFINEMENT.md](UIUX_REFINEMENT.md). This report describes the QA of `de7d8bd` and is otherwise unchanged.

## 1. Source and commit identities

| Implementation | Identity and state |
|---|---|
| Main ReFind `C:\xampp\htdocs\refind` | Detached at `47eea86` (v1.0.0 + PR #10–#13); tracked tree clean. Config: `/refind/`, session `refind_v1`, **`registration_enabled = true` (loopback only)**. |
| Test ReFind `C:\xampp\htdocs\refind-test` | Detached at `47eea86`; tracked tree clean. Same 272-file tracked source as main. Config: `/refind-test/`, session `refind_test`, registration at its default (off), **`visual_search_enabled = true`** (AI pilot). |
| Native oracle `C:\ReFind-Laravel\native-reference` | `47eea86`, clean. Identical source to main and test, so the pin and both installations agree. Only configuration differs (above). |
| Laravel candidate | Started at `migration/phase8-integration` `edcd335` (tree `a0c1db86…`, clean; changes after `58bc42c` were documentation only). QA branch `migration/qa-final`: `cf47f72` QA suites → `e12ed85` inherited parity on the candidate → `4eaa864` UI-text fix → this report. |
| Preserved, unchanged | `migration/academic-b` `7f864aa`, `migration/phase6-6a` `b70b711`, `migration/laravel13` `ac03a9d`; Phase 6 WIP `1ea27c5`; Phase 7 WIP `b889b80`; `migration/phase8-integration` `edcd335`; the uncommitted canonical 6B tree (archive `c1f4bde6…`, 19/19 files). |

Main, test and the disposable `refind-dev` were inspected read-only (Git state and non-secret configuration flags). No interactive workflow was started on main or test. The `refind-v1-rc` worktree carries 10 pre-existing modified verification screenshots/results, which were preserved.

## 2. Feature parity matrix

Native column: main and test share source `47eea86`. Classification is against the pinned native contract. Laravel features of Phases 6–8 are enabled only on the disposable `phase8-dev` profile, and the academic profiles keep the accepted Phase 5 contract until a separate activation.

| Feature | Native main / test | Laravel | Evidence | Classification |
|---|---|---|---|---|
| Login, logout, session lifecycle, idle 30 min / absolute 8 h, fingerprint | `api/auth.php`, `session.php`, `app/auth.php` | `AuthController`, `AuthService`, `ReFindSession*` | Accepted Phase 5 A/B; `AuthContractTest`; live suites | Equivalent |
| Student/BM role separation | `request_user` | `MutationPreconditions`, `AuthService::require` | PHPUnit, security probes (cross-role, 75 checks) | Equivalent |
| CSRF and origin | `verify_csrf` | `ReFindCsrfMiddleware` | Probes, all suites | Equivalent |
| HTTP method override | Unknown `_method` field → 400 | Framework honours `_method`/override header → **405** (both deny; accepted since Phase 5) | Security probes | Intentional (framework) |
| Self-registration | main **on** (loopback), test off | `registration` stage plus loopback (`RegistrationService`), off on academic profiles | `Phase8ContractTest`, `test-phase8-http`, `test-phase8-ui` | Equivalent to main config (dev profile) |
| Account: name, password, avatar | `api/account.php` | `AccountService` (journaled avatar, credential rotation) | PHPUnit (incl. rollback), HTTP, UI | Equivalent |
| Home, Explore, search, filters, pagination | `api/items.php`, `app/search.php` | `DiscoveryController`, `Search`, `ItemReadRepository` | Accepted Phase 4/5; inherited search suite passed on candidate | Equivalent |
| Item detail, images, private media | `api/photos.php`, `avatar.php` | `MediaController`, `MediaPolicy` | Accepted; probes (traversal, private paths, ETag) | Equivalent |
| Lost/Found report create/edit/delete, private evidence | `app/reports.php` | `ReportService`, `ReportPolicy` | Accepted 5 A; inherited report-service suite (27) on candidate | Equivalent |
| Status transitions, matching, suggestions | `app/repository.php` | `RuleMatcher`, `ReportEffects` | **Native matching oracle replay: 205 checks** on candidate | Equivalent (oracle-verified) |
| Claim submit, resubmit, withdraw, private proof | `app/claims.php` | `ClaimService` | `ClaimWriteContractTest`; HTTP and races; UI | Equivalent (native HTTP pending) |
| BM claim queue and detail | `app/staff-read.php` | `ClaimReadRepository` (6B) | `ClaimReadContractTest`; HTTP; UI | Equivalent (native HTTP pending) |
| Request more info, reject | `review_claim` | `ClaimReviewService` | `ClaimReviewContractTest`; races; UI | Equivalent |
| Approve, linked or unlinked; competing and legacy closures | `review_claim`, `create_approved_pickup` | `ClaimReviewService` | `ClaimApprovalContractTest`; competing-approval races | Equivalent, plus divergences 1–2 (§6) |
| Stale revisions | `claim_revision` | `ClaimRevision` | Same-second probe; stale/repeated → 409 | Intentional divergence 1 |
| Pending handover, schedule, reschedule, cancel, return, release | `mutate_handover` | `PickupService`, `HandoverReadRepository` | `PickupContractTest`; pickup races (all orderings); UI | Equivalent |
| BM Handover tab, Student My Activity | `bm.js` / `student.js` | Same native JS (gated) | UI suites 15/10 | Equivalent |
| BM Reports tab and manual status | `staff_status`, `staff_report_list` | `StaffReportRepository`, `StaffStatusService` | `Phase8ContractTest`; HTTP; UI | Equivalent |
| Notification list and read-marking | `api/notifications.php` | `NotificationReadRepository`, `NotificationService` | PHPUnit; HTTP; UI | Equivalent |
| Campus history | `history.html` | Blade binding | PHPUnit; UI | Equivalent |
| History and notification effects of every workflow | repository helpers | `WorkflowEffects` | Exact row assertions in each contract test | Equivalent |
| AI visual search | test **on** (pilot), main off | Disabled route (Phase 4 exclusion) | Accepted gate | Out of scope |
| UI pages and assets | 13 native pages, js/css/assets/vendor | Blade bindings plus native assets | Structural diff (§3) | Equivalent, except documented gates |

## 3. Functional and UI findings

- **Structural UI parity**, from a Blade-vs-native diff after placeholder normalization:
  - home, login, browse, report, history, profile, settings and register are identical to native markup.
  - item, claim, My Activity and notifications differ only by gate data attributes.
  - The BM page adds a gated tab group and dialog.
  - All native js/css/assets/vendor files are byte-identical, except the documented gated `student.js` and `bm.js` (`SOURCE_PROVENANCE.json`). There are no Laravel-only assets.
- **Browser journeys** run at 390 px dark and 1440 px light: Guest/registration, Student and BM, covering every workflow from Phases 6–8. No JS errors, no 5xx responses, no foreign requests, no horizontal overflow.
- **Expected (non-defect) differences:**
  - Laravel serves at `/` while native serves under a base path, so private media URLs are root-relative.
  - Generated IDs and timestamps differ.
  - Method override returns 405 instead of 400 (§2).
- **Visual side-by-side screenshots** against a running native instance were **not produced**. That needs a disposable native target, which falls under the pending independent comparison (§7).

## 4. Confirmed defects and fixes

| # | Defect | Fix | Verification |
|---|---|---|---|
| 1 | My Activity subtitle and BM intro kept the 6B read-only wording after pickups and decisions were enabled (UI-text drift from native) | `4eaa864`: native text when the stage is enabled | New regression test; PHPUnit 44/2965 |
| 2 | Inherited live and browser suites still expected Phase 8 features to be absent (manual status, read-marking, Reports tab) | `58bc42c` (test-only): stage-aware assertions, with negative coverage kept for profiles without the stage | Candidate driver |
| 3 | Inherited native matching oracle, its diagnostics, and the replay were pinned to `academic-test` | `e12ed85` (test tooling): the dev profiles are also allowed, and port, datadir, own schema and owner are still verified | Oracle replay 205 checks |

No application security, data-integrity or transaction defect was found.

## 5. Tests executed (this QA)

| Run | Result |
|---|---|
| Static: Composer `validate --strict` and platform requirements; PHP lint (122 files); `node --check` on all JS; Python compile; route coverage (every native endpoint/method that native accepts is routed) | Pass |
| `verify-candidate.py --browser` on `e12ed85` (`evidence/phase8/candidate-20261119T101158Z`) | **Pass.** Grant audit; PHPUnit 43/2955; inherited search, report service (27) and native matching parity (205); live HTTP and races 77/81/28; security probes 75; browser 15/10/11; exact restore; protection |
| PHPUnit after fix `4eaa864` | 44 tests, 2965 assertions |
| **Final `verify-candidate.py --browser` on clean committed `303e9cb`** (`evidence/phase8/candidate-20261119T102106Z`) | **Pass (`accepted:false`).** Static (122 PHP files); grant audit; PHPUnit **44/2965**; inherited search, report service **27** and native matching parity **205**; live HTTP and races **77/81/28**; security probes **75**; browser **15/10/11**; exact restore (identical state); protection (academic grants identical to the 6B evidence, canonical 6B files 19/19, accepted refs unchanged); owned shutdown |

## 6. Security, privacy, transactions, concurrency, and the divergences

**Verified:**
- Guest, Student and BM authorization.
- Object-level isolation: foreign claims, pickups and notifications look the same as missing records.
- CSRF and origin checks.
- Overrides only deny.
- Private evidence: answers are hidden from finders and proof bytes are BM-only.
- Traversal and private storage paths are refused.
- Injection inputs are treated literally, with no 5xx responses or leakage.
- Sanitized errors.
- No synthetic secrets in private logs.
- Least-privilege grants (exact audits; role, email, activation and deletions stay denied).
- One approved owner per Found, one confirmed pair per report, one handover per approved claim, under real two-worker races (all orderings seen).
- Rollback at every coordinator seam, mid-operation SQL failures, lost commit acknowledgement (committed, not replayed) and real connection loss.

**Independent recommendations on the three deliberate divergences** (owner sign-off required; none is active on academic profiles):
1. **Strengthened claim revision token.** Recommend **accepting**. The probe confirmed that the native digest repeats on a same-second NEED_MORE_INFO → PENDING cycle, which would admit a stale or repeated BM decision. The fix changes no wire format and costs one indexed lookup. Residual: if notifications ever become deletable, a token could repeat. In that case keep `CLAIM_SUBMITTED` rows immutable, or add a claim version column through a reviewed schema migration.
2. **Approval converting a same-pair REJECTED match to CONFIRMED.** Recommend **accepting as native parity**. Under current workflows a pair becomes REJECTED only when a pickup is released. That also makes the claimant's claim REJECTED, which is final per item and claimant, and only the Lost owner can link that Lost report. So conversion is reachable only through legacy data. The implementation keeps the pair row (locked read plus UPDATE) and needs no identity-column grant.
3. **Shared advisory lock on Student claim writes.** Recommend **accepting**. It serializes Student writes with BM review, approval, pickup and report edits on one lock. That removes cross-writer races the native row-lock scheme leaves possible, and keeps the bounded 5-second wait with the native-style 409. No contention problems appeared in the race suites.

**Resolved owner decision (19 November 2026):** automatic pickup expiry is **Option A**. Release stays manual, with no automatic release, cancellation, background job or grace period, and the overdue indicator stays automatic. This is a business-policy decision only; see `docs/UIUX_REFINEMENT.md` and `docs/RELEASE_PREPARATION.md` §6.

## 7. Outstanding mandatory qualifications (not passed)

| Gate | Status |
|---|---|
| Independent native HTTP parity | **Pending.** No independent target is available. The native config validator only accepts approved targets, so a same-host clone would need a native config change, a hosts-file alias, or the protected XAMPP RC target, and none of those was used. Procedure: comparator kit (`PHASE_6_DOCKER_FREE_HANDOFF.md`, `Stage-ReFind-6BComparator.ps1`), extended to the Phase 6–8 scenarios in §2. |
| Full inherited 33-suite matrix with receipts | **Pending.** `verify-phase5.py` runs against the accepted `academic-test` and `academic-dev` profiles from the canonical checkout, which still holds the uncommitted 6B tree. Running the candidate there needs the approved integration step (`RELEASE_PREPARATION.md` §3). The dev-profile equivalents ran (§5). The academic-only suites not yet run on the candidate are: write foundation (`_foundation_probe`), matching concurrency workers, the Phase 5 HTTP, concurrency and UI suites at 8014, academic privilege audits on the candidate, PoC fingerprints, and native host protection. |
| Fresh-Windows install and restart qualification | **Pending** (external machine; staging kit prepared). |
| Academic activation of stages and grants | **Not authorized.** Separate approval per profile (`RELEASE_PREPARATION.md` §2). |
| Owner sign-offs | §6 items 1–3. Also: the Phase 8 grants (`notifications.is_read`; `users` profile, password and avatar; `users` INSERT); and whether registration is enabled for the academic release (main enables it on loopback; test does not). |

## 8. Academic framework evidence

The professor confirmed that a PHP framework is required and that Laravel or CodeIgniter is acceptable; Laravel was chosen. `FRAMEWORK_EVIDENCE.md` maps routing, middleware, controllers, the service container, the query builder with a pinned driver, transactions, Eloquent auth, sessions, Blade, validation and error rendering, storage, events and PHPUnit HTTP testing to code and tests. **Missing for submission:** the authenticated rubric mapping, screenshots or recordings of each journey (desktop/mobile, light/dark), and an architecture diagram. No rubric requirement was invented.

## 9. Final candidate, restoration and handoff

- **Final candidate:** `migration/qa-final` at **`303e9cb`**, verified by `candidate-20261119T102106Z` (§5). The later commit that records this result changes documentation only.
- **Restoration:** every run restored `phase8-dev` exactly (identical state dump) and its limiter files. Demo and test credentials were removed, and owned processes stopped.
- **Protected state:**
  - Academic grants are identical to the 6B evidence.
  - The 6B archive and canonical files are unchanged.
  - Accepted refs and the Phase 6/7 WIP branches are unchanged.
  - Main and test ReFind are untouched: read-only Git and config inspection only.
- **Rollback:** each branch is preserved. To roll back the QA changes, use `migration/phase8-integration` `edcd335`. Dev profiles restore from their snapshots (`tools/phase6-dev.php restore`). The grant deltas are reversible (`grant-claims.php <stage> --revoke`, reverse order).
- **Next steps:**
  1. Owner sign-offs (§6–7).
  2. The approved canonical integration (`RELEASE_PREPARATION.md` §3).
  3. The coordinated acceptance: independent native parity → full matrix → academic activation → fresh Windows → academic evidence.
