# Phase 5 Checkpoint A — Student report CRUD and private media

Checkpoint A acceptance passed on 17 November 2026. Branch: `migration/academic-b`; parent: accepted Phase 4 `af7b349bd7bdcaa2845bc7ae4ac9a04c542dc31b`. The final local commit SHA is recorded in the adjacent private run's `checkpoint.json` after commit and in the delivery message. This report and all source/test changes are included in that checkpoint.

This is the first internal Phase 5 checkpoint, not completion of Phase 5, Milestone B, or the academic demonstration release. Stop for review before Checkpoint B. Earlier failure reports remain historical; this successful complete run supersedes their acceptance status without deleting their evidence.

## Implemented behavior

- Genuine Laravel `POST /api/items.php` create/update/delete through `ReportController`, `ReportService`, and `ReportPolicy`, retaining existing URLs, selectors, multipart fields, JSON status/action/id contracts, assets, and frontend stack.
- Student-only ownership checks; only eligible own OPEN/POTENTIAL_MATCH reports can change. Active claims, confirmed pairs, terminal state, immutable type/reporter fields, and foreign retained-photo IDs are rejected. The policy is rechecked under the coordinated write lock.
- Private public-report-photo and Lost ownership-evidence staging through the existing UploadStager, MediaJournal/manifests, WriteCoordinator and recovery infrastructure. Retained IDs are validated for item/kind; photo count, MIME/signature, dimensions, size, and upload completeness are enforced. Found reports reject Lost ownership uploads.
- Existing evidence authorization is preserved: ownership details/photos do not appear in public projections; Students do not read private evidence images, while authorized Building Management can. Private originals are never exposed as public filesystem paths.
- Database mutations, media promotion and the operation ledger use the pinned connection/coordinator. Failed SQL is not retried. Known rollback compensates staged/promoted files; uncertain outcomes retain media until ledger-based reconciliation. Deleted/replaced/cascaded closed-claim media is cleaned only after commit and reference checks.
- Reporting and My Reports Blade views preserve the native UI. Claims controls remain disabled and unsupported operations return explicit errors. No native PHP handler is executed for report requests.

## Recovery corrections

The guarded MariaDB driver is registered during provider registration, before any database resolution; finding a pre-existing cached connection fails closed. Metrics subscribe to QueryExecuted through the event dispatcher, not DB::listen during boot. Metrics can be disabled without touching database configuration. Fresh-process on/off checks prove boot leaves the manager connection cache empty and the guarded PDO lazy; the live regression proves the class, PDO and server connection identity, pin and owned transaction remain unchanged across toggles, with reconnect/replacement/manual transaction control refused.

Standalone CLI initialization is wrapped before Laravel boot, restores a nonzero uncaught-exception handler after kernel bootstrap, and has a fatal shutdown guard. The negative suite verifies real exceptions/fatal errors before local try/catch and invalid-profile bootstrap failures cannot report PASS or emit success receipts.

The runner requires process exit zero AND a fresh complete receipt matching run, invocation, suite, profile and checkpoint, with valid timestamps and positive counts. Vendor checks use lint/Composer validation or complete zero-failure JUnit evidence. The corrected foreign-suite fixture constructs an identity different from the expected one, asserts that fact before validation, and requires rejection specifically for suite mismatch. No negative case was removed or weakened and no further verification architecture was introduced during the final correction/resume.

## Verified acceptance matrix

Full run ID: `e38ccd8629fe4ac98f1594e7adb8c1e3`.
Private full run: `C:/ReFind-Laravel/evidence/phase5/verification-crud-20261117T090938Z-e38ccd86`.
Versioned positive receipts: `docs/verification/phase5-checkpoint-a/`.
The receipt-protocol prerequisite separately passed first at `C:/ReFind-Laravel/evidence/phase5/protocol-resume-20261117T090921Z-906ae829`, then passed again within the full run.

| Gate | Verified result |
| --- | --- |
| PHP syntax / Composer / platform | 82 files; strict validation passes; 18 platform requirements pass. No dependencies changed. |
| Receipt protocol | 21 checks, including invalid identities, malformed/missing/stale/incomplete receipts, zero/noninteger counts, nonzero exit, positive control, fresh destinations, and actual early CLI failures. |
| Guarded metrics bootstrap | 4 checks with metrics disabled; 4 enabled. No connection resolution at boot. |
| Isolation / preservation guards | 69 checks; approved runtime/profile boundaries and unchanged frontend assets. |
| Effective privileges | 21 checks each for academic-test and academic-dev, including column/table/global/role restrictions and cross-schema/protected DML denials. |
| Transaction/media foundation | 98 checks: SQL failure/rollback, no retry, ordered locks, advisory contention/release, row timeout, pin/PDO/transaction guards, actual connection loss, promotion/staging/cleanup failures, lost commit acknowledgement, uncertain committed/aborted outcomes, reconciliation and exact DB/media restoration. |
| Report service | 28 checks; genuine CRUD/media, ownership/retained-ID rejection, injected interrupted promotion rollback and exact fixture/counter/media restoration. |
| Academic-dev boundary / pure search | 4 boundary checks; 50 search regressions. |
| PHPUnit | 22 tests, 156 assertions, zero failure/error/skipped/risky/warning/incomplete; includes live metrics toggle safety. |
| Report HTTP | 65 checks: multipart CRUD, field/date/time validation, role/CSRF/ownership, private evidence, retained IDs, malformed/oversized/excess uploads, immutable/locked reports, cleanup, unsupported operations. |
| Existing HTTP contract | 136 checks; authentication, 64-character CSRF, sessions, JSON/HTTP methods, private media, disabled AI and unavailable operations. |
| Cross-process sessions | 4 checks; file locking, logout race and old-cookie replay. |
| Existing browser UI | 132 checks; light/dark and 360/390/768/1280/1440 widths. |
| Report/My Reports browser UI | 152 checks; same themes/widths, create/edit/replace/delete, private evidence representation, previews, validation and disabled claims; no JavaScript errors or external/native traffic. |
| HTTP/browser fixture restoration | Fresh receipts after each exact B-test database/authoritative-media restoration. |
| Original PoC databases | Both 15-table fingerprints unchanged, using original SELECT-only identities on the guarded isolated 3307 server. |
| Original PoC/private files | 237 protected files plus original branch check (238 checks) unchanged. |
| Native/shared configuration | Six groups unchanged; all ten pre-existing native uncommitted files preserved. No native database connection. |
| Shutdown | All owned DB/web processes stopped; ports 3307/8013/8014/8015 free. |

Each executed child has an identified positive completion receipt; the runner itself reports completed=true, passed=true and failure=null. Intentionally invalid receipts remain only under the private negative-fixture evidence and are not included as positive checkpoint receipts. Representative mobile light and desktop dark screenshots were visually reviewed after browser acceptance; screenshot artifacts remain private evidence.

## Effective privilege matrix and changes

No privileges were added, revoked or re-granted during this recovery/resume. The previously approved Checkpoint A grants were verified exactly, without activating the grant helper. No write-capable identity was used against native databases or original PoC schemas.

| Resource | academic-dev application | academic-test application |
| --- | --- | --- |
| Own B schema | SELECT | SELECT |
| items INSERT | denied | reporter_user_id, category_id, location_id, status_id, item_type, title, description, location_detail, event_date, event_time, verification_clue only |
| items UPDATE | denied | title, description, category_id, location_id, location_floor_id, location_detail, event_date, event_time, verification_clue only |
| items DELETE | denied | allowed |
| item_photos INSERT / UPDATE / DELETE | denied | INSERT item_id/photo_kind/file_path; UPDATE is_primary; DELETE allowed |
| _write_operations | SELECT only | INSERT only beyond SELECT; UPDATE/DELETE denied |
| _foundation_probe | SELECT only | synthetic INSERT/UPDATE/DELETE, unchanged from Phase 4 |
| users, claims, claim_photos, handovers, matches, history, notifications | SELECT only | application DML denied |
| Other B/original PoC/native-named schemas and mysql | denied | denied |
| Global privileges, grant option, DDL, roles | denied | denied |

B-test restoration/eligibility fixtures are separate guarded CLI-only administrative collaborators, restricted by server, schema and workspace ownership checks; their synthetic setup is not application permission or enabled business workflow. Read-only foreign-schema denial probes use the isolated 3307 connection; they never connect to XAMPP/native 3306.

## Resources and query observations

Unchanged requirements: at least 2 GiB available RAM and 5 GiB free disk. Initial preflight: 2,892 MiB and 36.05 GiB. Final preflight: 2,729 MiB and 36.02 GiB. Lowest sampled available memory: 2,290 MiB. Peak sampled PHP RSS: 66.0 MiB per process; owned MariaDB RSS: 118.3 MiB; child Chrome aggregate RSS: 613.7 MiB. No gate was lowered and no XAMPP/service/runtime configuration changed. The optional Windows Widgets panel had been closed under separate user authorization before this run.

The report-service regression observed 105 Laravel query events across its 28 checks. This is an aggregate regression measurement, not a per-request benchmark or count of direct PDO ownership probes. HTTP query metrics log count, SQL time, elapsed time and PHP peak memory privately. No premature SQL/ORM optimization was introduced. Advisory waits remain bounded; the deliberately contended row-lock test observed 5.064 seconds, with no retry.

## Boundaries, remaining limitations and rollback

- Writes are enabled only for synthetic academic-test in this checkpoint. Academic-dev remains SELECT-only and report writes explicitly unavailable. Profiles retain independent schemas, credentials, keys, cookies and private storage. No shared native sessions/database/media writes.
- Matching computation, status-history insertion and notification creation are not implemented here; ReportEffects remains no-op and report_effects=false. Existing read-only seeded suggestions/history/notifications remain reference fixtures. Current report statuses do not gain the automatic effects planned for Checkpoint B.
- Claims, BM review decisions, pickup, account changes and AI remain unavailable. Full functional parity and the WDD academic-release gate are not claimed by this checkpoint.
- Fault injection, reconciliation and database/media restoration remain CLI-only test/maintenance capabilities. No HTTP fault or privileged maintenance switch was added. Snapshots, credentials, session files, manifests and private media are excluded from Git.
- All 52 pre-existing dirty/untracked files were privately archived before the fixture correction; only the two authorized fixture test files changed during that correction. Other uncommitted Phase 5/recovery work was retained and is included in this local Checkpoint A commit, alongside this report, README and sanitized receipts. Historical failure reports remain intact.
- The B-test data, media and auto-increment counters were restored by the accepted suites. Failed/uncertain-operation recovery is ledger-driven; never delete uncertain media speculatively. Native/QA/original PoC are never restoration targets.
- Phase 4 remains the parent checkpoint; original Milestone A remains on migration/laravel13 at ac03a9dae33c5d03318ac357fdb0c31abcaffa75. Any future code rollback must stop owned runtimes, preserve the Checkpoint A branch and private state, and use a separate checkout; earlier privilege-denial tests require their corresponding scoped B grants. No reset/revoke/restore is performed automatically by delivery.

No acceptance failures remain in this run. Do not start Checkpoint B or Phase 6 without review. Nothing was pushed, merged, or deployed.
