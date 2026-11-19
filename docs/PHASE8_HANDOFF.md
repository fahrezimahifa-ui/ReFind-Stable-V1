# Phase 8 handoff: integrated ReFind Laravel candidate (unaccepted)

19 November 2026. Phase 8 integrates the Phase 6–7 WIP and completes the remaining native features, with framework evidence, release preparation and an executable verification workflow. **Nothing in Phases 6–8 is accepted; the academic release is not accepted.**

## State

| Item | Value |
|---|---|
| Worktree / branch | `C:\ReFind-Laravel\laravel-p8`, `migration/phase8-integration` (local; no push, merge or deploy) |
| Base | Phase 7 WIP `b889b80` (which contains Phase 6 `1ea27c5`). Both are preserved unchanged, with their own worktrees and profiles. |
| WIP commits | `516311b` phase8-dev profile · `27c2ea1` remaining native features · `ebc5948` verification workflow and docs · `58bc42c` live-suite stage awareness · this commit (evidence record) |
| Profile | `phase8-dev`: `refind_laravel_p8_dev` / `refind_p8_dev_app`, ports 8020/8021, `state/phase8-dev`, bound to `laravel-p8` |
| Phase 7 demo | The owner tested scheduling, release, return, My Activity and notifications and found no issues. The pre-demo data was restored and the demo credentials deleted. |

## Implemented in Phase 8 (native 47eea86 semantics; stage-gated on `phase8-dev`)

- **BM Reports tab:**
  - Report queue and detail with search, status and type filters, native allowed statuses and revision.
  - Manual status (`staff.php action=status`):
    - Reports with an active claim, a confirmed pair or a pickup are blocked.
    - RESOLVED and UNCLAIMED_CLOSED remove suggestions and settle neighbours.
    - The reporter gets one `OPERATIONAL_NOTE`.
- **Notification read-marking:** single notification and read-all, recipient only. The notifications page is no longer read-only on this profile.
- **Account:**
  - Profile read.
  - Display name.
  - Password change: limiter, locked fingerprint recheck, session and CSRF rotation, other sessions invalidated.
  - Prepared 512px JPEG avatar replace and remove, through the media journal (new `avatars` collection, one per operation).
- **Self-registration:** loopback only, with address limiter, native validation, duplicate-email conflict and an immediate session. `session.php` advertises availability.
- **History:** read-only campus history page.
- Blade bindings for the history, profile, settings and register pages. `AuthService::establish` is shared by login and registration.
- Fail-closed stages: `status`, `notifications`, `account`, `registration` and `history`, alongside Phase 6–7's `student`, `review`, `approval` and `pickup`. Reviewed, reversible grant deltas, and an exact audit: 79 column grants, 6 table grants, 16 cross-schema and 16 protected-mutation denials.

## Verification

| Check | Result |
|---|---|
| PHPUnit `phpunit.phase8.xml` at `27c2ea1` (real DB, exact restore) | **43 tests, 2955 assertions, OK.** Includes `Phase8ContractTest` (5 tests, 295 assertions: validation, privacy, rollback and gate narrowing for every Phase 8 feature). |
| PHP lint / JS / Python compile | All changed files pass |
| `phase8-dev` exact grant audit | Passed |
| `tools/verify-candidate.py` fail-safe | Verified. Below the RAM floor it stopped at preflight, wrote `accepted:false` evidence, started nothing and left the database as found. A later run that failed mid-way stopped its workers, ran its emergency restore and returned the database to its prior state. |
| **Candidate run `candidate-20261119T095326Z` on clean committed `58bc42c`** | **Passed (`accepted:false`).** The steps were: preflight; static checks (122 PHP files plus JS and Python); exact grant audit; PHPUnit 43 tests, 2955 assertions; then, on two live workers, `test-phase6-http.py` (77 checks), `test-phase7-http.py` (81) and `test-phase8-http.py` (28); browser suites `test-phase6-ui.py` (15) and `test-phase7-ui.py` (10); exact restore with an identical state dump; protection (academic grants identical to the 6B evidence, 6B archive `c1f4bde6…`, canonical 6B files 19/19, accepted refs unchanged); owned shutdown. Race orderings seen: competing approvals one-winner ×2, withdraw vs approve → APPROVED, submit vs delete → deleted, resubmit vs review → REJECTED, double schedule, complete vs release → release, reschedule vs cancel → PENDING, release vs claim → claim accepted after release. Evidence: `C:\ReFind-Laravel\evidence\phase8\candidate-20261119T095326Z\summary.json`. |
| Test-only fixes before the passing run (`58bc42c`) | Inherited live assertions that Phase 8 features were absent are now stage-aware (manual status, read-marking, Reports tab). The Phase 8 lamp fixture was isolated from earlier suites' matching reports. No application code changed. |
| Protection | No accepted profile, grant, native install or earlier branch was touched. The driver's protection step re-checks this. |

## Deferred gates (explicitly not passed)

These are recorded in `RELEASE_PREPARATION.md` §4 and in each driver summary:
- native HTTP parity
- full matrix with receipts
- separately approved academic activation of stages and grants
- fresh-Windows qualification
- authenticated rubric mapping and academic evidence
- owner sign-offs

## Owner decisions still open

- Stronger claim revision digest.
- REJECTED → CONFIRMED pair conversion.
- Academic activation of the Phase 8 grants (`notifications.is_read`; `users` profile/password/avatar; `users` INSERT).
- Whether registration should be enabled at all outside development (native defaults to disabled).

## Next steps

1. Owner review of `migration/phase8-integration`. The integrated candidate is functionally complete apart from the excluded AI feature, and the targeted verification passed on `58bc42c`.
2. The coordinated final acceptance effort in `RELEASE_PREPARATION.md` §4.
