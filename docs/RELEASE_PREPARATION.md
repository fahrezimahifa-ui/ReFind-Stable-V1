# Release preparation: integrated ReFind Laravel candidate

Status: **integrated development candidate, not accepted, not released.** No push, merge or deployment is authorized. Native ReFind (`/refind/`, `/refind-test/`), XAMPP and the accepted Laravel checkpoints are unaffected.

## 1. Candidate lineage

| Ref | Commit | Role |
|---|---|---|
| `migration/academic-b` | `7f864aa` | Accepted Phase 5 B (reporting, matching) |
| `migration/phase6-6a` | `b70b711` | Accepted 6A (score-grant cleanup) |
| canonical `laravel` working tree | uncommitted | 6B candidate, archived as `c1f4bde6…` (unaccepted) |
| `migration/phase6-impl` | `1ea27c5` | Phase 6 WIP: 6B integration, 6C–6E |
| `migration/phase7-impl` | `b889b80` | Phase 7 WIP: pickup and return |
| `migration/phase8-integration` | HEAD of `laravel-p8` | Phase 8 integrated candidate: all native features except AI |

Each branch has its own worktree and disposable profile: `phase6-dev` → `laravel-p6`, `phase7-dev` → `laravel-p7`, `phase8-dev` → `laravel-p8`. Earlier branches stay verifiable exactly as tested.

## 2. Feature stages and activation model

Every unaccepted write path sits behind `App\Support\ClaimStages`. Stages are hard-wired per disposable profile, and the accepted academic profiles get **none**. They keep the pre-Phase-6 contract (501 / 403) until a reviewed activation.

| Stage | Feature | Grant delta (`tools/grant-claims.php`) |
|---|---|---|
| `student` | claim submit, resubmit and withdraw, private proof | `claims` INSERT/UPDATE (listed columns), `claim_photos` INSERT/DELETE |
| `review` | BM request more info, reject | (uses `student` claim UPDATE) |
| `approval` | approve, confirmed pair, one pending handover | `item_matches` confirmation INSERT/UPDATE columns, `handovers` INSERT |
| `pickup` | schedule, reschedule, cancel, return, release | `handovers` UPDATE (pickup columns), DELETE (native release) |
| `status` | BM Reports tab, manual report status | none (accepted reporting grants) |
| `notifications` | read-marking | `notifications` UPDATE(is_read) |
| `account` | display name, password, avatar | `users` UPDATE(full_name, password_hash, avatar_path) |
| `registration` | loopback self-registration | `users` INSERT (listed columns) |
| `history` | campus history page | none |

Role, email, activation, user deletion, claim and notification deletion, and pair identity changes all stay denied. `tools/report-privileges.php` enforces the exact expected set for each profile.

**Academic activation (requires separate approval for each profile and stage):**
1. Stop that profile's workers and confirm quiescence (the launcher and grant script check live listeners and sessions).
2. Take a verified snapshot (`AcademicSnapshot`) and record the exact grant map.
3. In a reviewed commit, add the approved stages to `ClaimStages::PROFILES['academic-test']`, add the profile to the approved `ACTIVATION` entries in `grant-claims.php`, and add the matching expected sets to `report-privileges.php`.
4. Run `grant-claims.php <stage>` in order (student → review → approval → pickup → status → notifications → account → registration), then run the exact privilege audit.
5. Run the full verification for that profile (section 4). Activate `academic-dev` only after `academic-test` passes.
6. **Rollback:** run `grant-claims.php <stage> --revoke` in reverse order, revert the stage commit, restore the snapshot, and re-audit.

## 3. Integration into the canonical checkout (later, after review)

The canonical `C:\ReFind-Laravel\laravel` still holds the unaccepted 6B working-tree candidate, which is archived and hash-verified. Before integration:
1. Confirm the archive (`c1f4bde6…`) and its 278-entry manifest.
2. Decide the integration branch name.
3. Update `Workspace::PROFILES` so the academic profiles run the integrated code from `laravel`.
4. Re-pin `test-academic-guards.py` (branch and adapted assets).
5. Extend `VerificationReceipt.php` and `verify-phase5.py` beyond phase 5 for the Phase 6–8 suites.
6. Remove the stray `.pyc` files tracked in Phase 6 commits `13dd9d1` and `9a44603` (ignored from Phase 7 on).

## 4. Executable verification workflow

**Development candidate (targeted, available now):**

```powershell
python -B C:\ReFind-Laravel\laravel-p8\tools\verify-candidate.py --profile phase8-dev
```

- Add `--browser` for the Playwright journeys. Use `--skip-live` to omit the live HTTP and race suites.
- The steps are:
  1. resource preflight (2 GiB RAM / 5 GiB disk floor)
  2. static checks
  3. exact grant audit
  4. full PHPUnit
  5. snapshot plus limiter backup
  6. two live workers running `test-phase6-http.py`, `test-phase7-http.py` and `test-phase8-http.py` (plus the UI suites)
  7. exact restore with a state comparison
  8. protection checks (academic grants against the 6B evidence, the 6B archive and canonical files, accepted refs)
  9. owned shutdown
- It always restores and stops what it started. The summary goes to `C:\ReFind-Laravel\evidence\phase8\candidate-<UTC>\summary.json` with `accepted: false` and the deferred gates listed.

**Coordinated final acceptance (deferred, run once on the stable candidate):**

| Order | Gate | Notes |
|---|---|---|
| 1 | Native HTTP parity | External comparator kit (`PHASE_6_DOCKER_FREE_HANDOFF.md`). Covers claims, review, approval, pickups, BM reports and status, notifications, account and registration. Never same-host. |
| 2 | Full matrix with receipts | The inherited 33 suites plus the Phase 6–8 PHPUnit, HTTP, race and browser suites. Fresh source-bound receipts, exact restore, protection and resource gates, owned shutdown. |
| 3 | Academic activation | Section 2, profile by profile, each with exact grant audits. |
| 4 | Fresh-Windows qualification | External machine, install plus restart, at the accepted SHA. |
| 5 | Academic evidence | Authenticated rubric mapping, screenshots and recordings, `FRAMEWORK_EVIDENCE.md`. |
| 6 | Owner sign-offs | See section 6. |

## 5. Runtime and deployment notes

- **Runtime:** portable PHP 8.5.12 NTS, MariaDB 10.11.19 on 127.0.0.1:3307, Composer 2.11.2. The `composer.lock` pins Laravel 13.42.0 and PHPUnit 12.5.44. There are no Node build steps; the assets are static (jQuery/Bootstrap).
- **Dev profiles:** `tools/workspace/phase6-control.py init|grants|start|stop|status --profile <dev>`. Synthetic data only. Credentials are in `state/<profile>/credentials.json`, which is private and outside Git.
- **Deployment of the academic candidate:** local only. The cutover of native ReFind is out of scope and would need its own plan (see the native `docs/CUTOVER_RUNBOOK.md`).

## 6. Known differences and owner decisions

- **Stronger claim revision digest** (adds the latest `CLAIM_SUBMITTED` notification ID). Closes the native same-second token reuse. Pending sign-off.
- **Native REJECTED → CONFIRMED pair conversion** on explicit approval. Preserved; pending sign-off.
- **Student claim writes take the shared advisory lock** (native uses row locks only). This is a strengthening.
- **Native release deletes the handover.** A concurrent loser gets 404 instead of 409, which is native behaviour.
- **Phase 8 grants** (`notifications.is_read`, `users` profile/password/avatar UPDATE, `users` INSERT): approved for the disposable profile only. Academic activation needs explicit approval.
- **Registration** is local-only (loopback peer, native `registration_local_only`). Native defaults to disabled; the stage keeps it off everywhere except `phase8-dev`.
- **AI visual search** is excluded.
