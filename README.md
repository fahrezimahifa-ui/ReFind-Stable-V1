# ReFind Phase 4 protected write foundation

Current branch: `migration/academic-b`, based on Milestone A `ac03a9dae33c5d03318ac357fdb0c31abcaffa75`.

Phase 4 introduces protected transaction/media infrastructure and synthetic tests. Report, claim, BM decision, pickup and account mutations remain unavailable. This is not the academic demonstration release. Stop for review before Phase 5.

## Review

Read `docs/PHASE4_REPORT.md` and the sanitized receipts under `docs/verification/phase4`.
The original PoC report remains historical at `docs/POC_REPORT.md`; its branch, profiles, credentials and databases remain preserved.

## Start only when needed

```powershell
python -B C:\ReFind-Laravel\launchers\academic-control.py start --profile academic-dev
python -B C:\ReFind-Laravel\launchers\academic-control.py status
python -B C:\ReFind-Laravel\launchers\academic-control.py stop
```

Open <http://127.0.0.1:8013/login.html>. Synthetic development login values are stored privately in `C:\ReFind-Laravel\state\academic-dev\credentials.json`.

Use the academic controller on this branch. Do not use the historical PoC controller or runners while serving the academic checkout. Only one release/profile set may use the shared portable MariaDB instance at a time; competing controllers refuse the occupied database port. Stop all owned processes before changing branches.

Both 2 GiB available RAM and 5 GiB free disk are required. No gate was lowered. The controller uses loopback, at most two PHP servers and one owned database process. No XAMPP configuration, service installation, Docker or global PATH change is required.

## Profiles and write privileges

- `academic-dev`: `refind_laravel_b_dev`, independent keys/cookie/storage and SELECT-only application access.
- `academic-test`: `refind_laravel_b_test`, separate keys/cookie/storage; application writes only to `_foundation_probe` (INSERT/UPDATE/DELETE) and `_write_operations` (INSERT).
- All business tables remain read-only. Original PoC/native targets are refused.

Only the B synthetic test profile can execute a write unit in this phase. No HTTP endpoint exposes the write coordinator, fault injection, reconciliation or restoration tools.

## Verify

```powershell
python -B C:\ReFind-Laravel\laravel\tools\verify-phase4.py
```

The verifier uses B fixtures, samples resources, compares protected state and always stops owned runtimes. It performs synthetic writes, deliberate B-only connection termination and B-test database/media restoration. Do not run simultaneous verification or maintenance commands. Failed test state and manifests are retained for review; failure does not trigger an automatic reset.

Private manifests are under `state/academic-test/operations/manifests`; snapshots are under `state/academic-test/snapshots`. Never copy these, credentials, session files or private media into Git. Reconciliation uses an explicit operation ID and the transaction ledger. Restoration is a separate CLI-only, quiescent-test operation and refuses web processes; review the report before using either tool.

Nothing is pushed or deployed. Milestone A is preserved on `migration/laravel13`; starting that release requires its original source checkout and launcher instructions. Native ReFind and persistent QA remain independent.
