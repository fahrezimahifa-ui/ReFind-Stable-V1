# Phase 8 handoff: integrated ReFind Laravel candidate (unaccepted)

19 November 2026. Phase 8 integrates the Phase 6–7 WIP and completes the remaining native features, with framework evidence, release preparation and an executable verification workflow. **Nothing in Phases 6–8 is accepted; the academic release is not accepted.**

## State

| Item | Value |
|---|---|
| Worktree / branch | `C:\ReFind-Laravel\laravel-p8`, `migration/phase8-integration` (local; no push, merge or deploy) |
| Base | Phase 7 WIP `b889b80` (which contains Phase 6 `1ea27c5`). Both are preserved unchanged, with their own worktrees and profiles. |
| WIP commits | `516311b` phase8-dev profile · `27c2ea1` remaining native features · this commit (verification workflow, docs) |
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
| `tools/verify-candidate.py` fail-safe | Verified. Below the RAM floor it stopped at preflight, wrote `accepted:false` evidence, started nothing and left the database as found. |
| Live HTTP, races and browser on `phase8-dev` | **Not run yet.** Available RAM stayed at about 1.3–1.4 GiB, below the 2 GiB floor; most memory is held by the owner's Chrome and the Claude app. Run `verify-candidate.py --browser` once at least 2 GiB is free. It covers the Phase 6/7 live suites as regressions and the new `test-phase8-http.py`. |
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

1. Free memory and run `verify-candidate.py --browser` on `phase8-dev` (targeted, about 5 minutes).
2. Owner review of `migration/phase8-integration`.
3. The coordinated final acceptance effort in `RELEASE_PREPARATION.md` §4.
