# ReFind Phase 5 Checkpoint A — protected Student reporting

Current branch: `migration/academic-b`, based on Milestone A `ac03a9dae33c5d03318ac357fdb0c31abcaffa75`.

Checkpoint A enables Laravel Student report CRUD and private uploads on synthetic academic-test only. Academic-dev remains read-only. Matching/history/notification effects, claims, BM decisions, pickup, account mutations and AI remain unavailable. This is not the academic demonstration release. Stop for review before Checkpoint B.

## Review

Read `docs/PHASE5_CHECKPOINT_A_REPORT.md` and the sanitized receipts under `docs/verification/phase5-checkpoint-a`. The Phase 4 report and earlier failure reports remain historical.
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
- `academic-test`: `refind_laravel_b_test`, separate keys/cookie/storage; approved column-scoped item/photo CRUD, synthetic `_foundation_probe` writes and append-only `_write_operations` INSERT. The report records the exact privilege matrix.
- Other business mutations and all original PoC/native targets are refused.

Only the B synthetic test profile can execute report write units. For the protected CRUD demonstration, use `academic-control.py start --profile academic-test`, port 8014 and its private synthetic credentials file. No HTTP endpoint exposes fault injection, reconciliation or restoration tools.

## Verify

```powershell
python -B C:\ReFind-Laravel\laravel\tools\verify-phase5.py --checkpoint crud
```

The verifier uses B fixtures, samples resources, compares protected state and always stops owned runtimes. It performs synthetic writes, deliberate B-only connection termination and B-test database/media restoration. Do not run simultaneous verification or maintenance commands. Failed test state and manifests are retained for review; failure does not trigger an automatic reset.

Private manifests are under `state/academic-test/operations/manifests`; snapshots are under `state/academic-test/snapshots`. Never copy these, credentials, session files or private media into Git. Reconciliation uses an explicit operation ID and the transaction ledger. Restoration is a separate CLI-only, quiescent-test operation and refuses web processes; review the report before using either tool.

Nothing is pushed or deployed. Milestone A is preserved on `migration/laravel13`; starting that release requires its original source checkout and launcher instructions. Native ReFind and persistent QA remain independent.
