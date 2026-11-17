# Phase 4 implementation report — Protected Write Foundation

Completed verification: **17 November 2026 14:13 Asia/Bangkok**. Phase 4 only; stop for review before Phase 5.

## Outcome and exact checkpoint

The dedicated academic branch contains transaction, locking, private-upload staging, operation-journal and recovery infrastructure. No Student report, claim, BM decision, pickup, account or notification mutations were enabled. Existing guarded unavailable responses remain in place.

- Branch: `migration/academic-b`.
- Base and preserved PoC branch: `migration/laravel13` at `ac03a9dae33c5d03318ac357fdb0c31abcaffa75`.
- Exact completed commit: recorded after the local commit in `C:/ReFind-Laravel/evidence/phase4/checkpoint.json` and supplied with this report. The receipt is outside Git to avoid a self-referencing commit hash.
- Native reference and verified GitHub main: `47eea8648d2d47faa895d855fe6da53e6b1a3087`.
- Independent Laravel repository, no remote; no push, deployment or live routing change.

The existing application directory is now the B checkout. The original PoC remains recoverable through its unchanged branch, original launcher, profiles, credentials and databases. Stop all owned runtimes before switching releases; do not run the historical PoC controller against the academic checkout.

## Profiles, databases and filesystem boundaries

| Profile | Schema on owned 127.0.0.1:3307 | Application identity | Cookie | Private state |
|---|---|---|---|---|
| academic-dev | refind_laravel_b_dev | refind_b_dev_app | refind_laravel_b_dev | state/academic-dev |
| academic-test | refind_laravel_b_test | refind_b_test_app | refind_laravel_b_test | state/academic-test |

Each profile has independent credentials, a fresh application key, credential fingerprint key material, session/cache/auth files, generated synthetic media and storage roots. No native or PoC records, hashes, credentials, media or sessions were copied. Initial fixture content is generated independently from the versioned synthetic-fixture specification.

Both schemas contain the 14 core tables, two invoker-security views and ownership/operation metadata. The test schema additionally contains `_foundation_probe`. Experimental embedding schema, AI providers and indexing jobs remain absent.

The approved PHP 8.5.12, Laravel 13.42.0 and MariaDB 10.11.19 binaries/dependencies are unchanged. The existing isolated MariaDB instance/data directory is reused on port 3307; B schemas are separate. This does not claim byte-identical shared InnoDB system files. Original PoC logical table contents/schema and private profile state were verified unchanged.

The original controller and PHP/MariaDB configuration files are preserved. The academic controller has its own process record and log directory. PHP web processes receive B-specific temporary/upload/error-log locations through process arguments. No Windows service, Docker, Redis, queue daemon, global PATH edit or XAMPP change was introduced.

## Effective privilege matrix

An additional direct privilege-metadata audit verified the exact grants below for both new identities, including no active roles and no unexpected global or column grants. Sanitized evidence: `docs/verification/phase4/privilege-matrix.json`.

| Resource | academic-dev application | academic-test application |
|---|---|---|
| Own B schema: core tables, views and metadata | SELECT | SELECT |
| `_foundation_probe` | Absent | INSERT, UPDATE, DELETE, plus inherited SELECT |
| `_write_operations` | SELECT | INSERT, plus inherited SELECT; UPDATE/DELETE denied |
| Business tables, including users/items/claims/photos/handovers/history/notifications | No DML | No DML |
| Other B profile, original PoC schemas, native-schema names and mysql system tables | No grants | No grants; cross-schema denial tests passed |
| Global administration, DDL, FILE, PROCESS, grant authority | None | None |

Business write privileges from the longer-term plan were deliberately not granted prematurely. Phase 5 will require its own reviewed, table-scoped privilege additions. The write coordinator also refuses non-test profiles in Phase 4.

Native port 3306 was not contacted with B or administrative credentials. Wrong-port/host/schema/profile overrides are rejected before a connection is attempted. Denials for native-schema names were checked on the isolated server, not by connecting to native database services. Protected filesystem paths are rejected by scoped library guards; this is not an OS sandbox against arbitrary code running as the same Windows user.

Administrative provisioning/restoration is CLI-only, outside the public document root, and verifies the actual server port, data directory, target schema and ownership marker. New schema/account ownership conflicts cause refusal; provisioning does not reset nonempty unowned targets.

## Transaction and locking infrastructure

`PinnedMariaDbConnection`, `WriteCoordinator` and `WriteContext` implement the unit-of-work boundary:

1. Resolve and validate a connection before pinning its PDO handle and server connection ID.
2. Acquire the database-specific matching advisory lock with a maximum five-second wait.
3. Begin exactly one coordinator-owned transaction; callbacks cannot use ordinary begin/commit/rollback, replace the PDO, reconnect or nest a write unit.
4. Lock requested parent rows in unique ascending ID order. A later request that moves backwards in the lock order is refused.
5. Execute work once, promote prepared files, verify transactional media references, and insert the immutable operation-ledger marker in that same transaction.
6. Persist commit intent before committing. A transport that returns with a transaction still open cannot report success.
7. Explicitly release the advisory lock in `finally`; clear the pin and close/reset the connection. Server-side connection termination also releases its locks.

No automatic transaction retry is used. Lock timeouts, deadlocks and uniqueness conflicts map to the existing 409 state-conflict contract. Uncertain outcomes use the existing server-error envelope with refresh-before-retry wording and preserve recovery state. Underlying exceptions are retained for private diagnostics, without exposing SQL causes in JSON.

Future business services must use this coordinator boundary and must not issue raw PDO transaction control, implicit-commit statements or independent writes outside it. Business-specific revision checks, competing-claim rules and state transitions remain Phase 5+ work.

## Private uploads, manifests and compensation

`UploadStager` checks an owned B upload path, upload validity, MIME/signature agreement, dimensions up to 1200px, prepared size up to 250000 bytes and new-file collection limits. It generates random private filenames and ignores the supplied extension/MIME claim. Record ownership and retained-plus-new photo counts belong to future business services.

A file-backed operation manifest records the profile/owner, generated paths, checksums, planned removals and progress. Writes flush before replacement; an operation lock prevents simultaneous reconciliation of an active manifest. Staging occurs on the same volume as the final private collections.

- New files are promoted before database commit, while their database references remain uncommitted.
- Confirmed rollback compensates only that operation's generated files, with reference checks.
- Replaced files are removed only after known commit and a fresh reference check.
- Failed cleanup retains files and a pending manifest; committed business data is not falsely rolled back.
- Uncertain commit retains media until a fresh locking read of the immutable ledger determines the outcome. Ledger checksum mismatch refuses recovery.

Fault injection exists only as PHP test collaborators. No HTTP parameter, route or header enables it. No cleanup daemon or automatic reset was added.

`reconcile-academic.php` accepts an explicit operation ID. `restore-academic.php` is a separate B-test-only CLI operation and refuses recorded web processes. Recovery/maintenance requires a quiescent test workspace; simultaneous CLI maintenance is unsupported.

## Middleware and error handling

The original forgery-middleware position now contains a small request-guard composition: mutation preconditions first, then the dedicated CSRF validator. Session startup still occurs before both. Endpoint action/role/registration checks were extracted out of CSRF while preserving their previous precedence. Mutation routes require explicit access metadata.

One canonical Laravel `_token` remains exactly 64 lowercase hexadecimal characters. API `csrf` and `csrf_token()` resolve that token. X-CSRF-Token / optional `_csrf`, conflict rejection, Origin checks, rotation and mandatory enforcement under APP_ENV=testing remain intact. No test or same-origin bypass was introduced.

Laravel validation, authentication and authorization exceptions now map explicitly to the existing JSON/field-error contracts. Unknown errors remain sanitized. No UI asset, public business endpoint contract or frontend dependency was redesigned.

## Acceptance evidence

Latest full receipt: `verification-20261117T071125Z`. All checks passed and owned runtimes stopped in the verifier's finalizer.

| Check | Result |
|---|---|
| PHP syntax | 67 source/test/tool files pass |
| Composer strict validation/platform requirements | Pass; dependency manifests and lockfile unchanged |
| Academic guard checks | 69 pass |
| Synthetic write/rollback/recovery checks | 104 pass |
| Academic development privilege checks | Four pass; SELECT works and writes remain denied |
| PHPUnit | 21 tests, 70 assertions pass, including Laravel exception mapping and uncertain-outcome redaction |
| Search behavior | 50 regression checks pass |
| Actual HTTP contracts | 136 checks pass; business mutations remain unavailable |
| Session concurrency | Four checks across two independent web processes; old authenticated-cookie replay rejected |
| Browser compatibility | 132 checks; five widths, light/dark, no JavaScript errors or external/native requests |
| Original PoC state/configuration | 237 protected files unchanged; original branch preserved |
| Original PoC databases | Data/schema fingerprints equal for 15 named tables per schema, using original SELECT-only identities |
| Native installations/shared configuration | Six groups checked, ten existing RC changes preserved, no mismatches |
| Exact effective-privilege audit | Both B identities pass; zero unexpected privileges/roles |

Infrastructure cases include successful commit, actual SQL failure, uniqueness conflict, advisory contention, an approximately 5.063s row timeout, reconnect/PDO/transaction-boundary rejection, real B-only connection termination during work and before commit, simulated lost acknowledgement after successful commit, an incomplete commit transport, staging/promotion/deletion failures, pending-cleanup recovery, spoofed uploads and protected-path refusal.

The real termination test kills only the connection ID captured from the pinned B connection on the validated isolated server. Lost commit acknowledgement is simulated after a real commit; it is not a packet-level network-partition test.

Full B-test restoration used a private snapshot of all 17 named test tables and authoritative media. Tests deliberately changed synthetic probe/ledger data and removed a synthetic media file, restored the snapshot, then compared the complete captured result exactly. Database restoration is transactional; database plus filesystem restoration is an offline, journaled sequence, not one cross-resource atomic transaction.

The earlier historical native-session drift identified in Phase 3 was retained. Phase 4 captured the current session fingerprints without restoring old sessions, and the final native comparison matched that current baseline. Native databases were not queried or modified during this phase.

## Resource observations

The 2 GiB available-RAM and 5 GiB free-disk requirements were never lowered. The retry initially found 2605 MiB available RAM and 35.65 GiB disk; the final full verification started with 3580 MiB and 36.13 GiB.

Sampled over the final verification:

| Observation | Value |
|---|---|
| Minimum available system RAM | 2924 MiB |
| Maximum observed individual PHP process RSS | 66.3 MiB |
| Maximum observed owned database RSS | 117.9 MiB |
| Maximum observed combined child Chrome RSS | 571.8 MiB |

These are periodic samples, not guaranteed instantaneous peaks. Existing PHP 128 MiB limits, MariaDB 128 MiB buffer pool/12 connections, bounded logs, two-web-process cap and one browser worker remain. All reserved owned runtime ports were free after shutdown.

## Limitations and next review

- This verifies infrastructure on synthetic B fixtures, not report/claim/pickup business transaction parity or WDD assessment readiness.
- Power loss, disk-device failure and real network partitions were not induced. The design uses flushed manifests, immutable transaction markers and verified snapshots; keep backups and review pending recovery state after interruption.
- Recovery and restore commands require offline/quiescent B test maintenance. Do not run simultaneous CLI writers or maintenance tools.
- The shared Windows identity can read administrative material. Database grants/path guards reduce application mistakes; they do not isolate arbitrary malicious server-side code.
- PHP 8.4 remains an unprovisioned fallback. Production runtime/server/HTTPS/cookie, deployment and cutover work remains separate.
- Advisory locking is intentionally coarse. Future phases must measure real matching workloads before changing lock granularity.

The two initial functional test failures were corrected before acceptance: standalone CLI failure exit signaling, and connection reinitialization before a new pin. Tests now assert the actual rejection causes. Failed-development evidence remains local; only passing/sanitized receipts are versioned.

Rollback of this release: stop the academic controller, preserve B evidence/state, and select the preserved PoC source branch with its original controller. No native restoration or credential/session/media replacement is required. B schemas/state can remain inactive; no deletion or privilege reversal is automatic.

**Phase 4 stops here. No Phase 5 implementation, business privilege expansion, push or deployment is authorized by this checkpoint.**
