# Laravel framework evidence (WDD academic requirement)

Integrated candidate `migration/phase8-integration` (Laravel **13.42**, PHP **8.5.12**, MariaDB **10.11.19**, PHPUnit **12.5.44**). The recorded WDD requirement is adoption of a PHP framework, with Laravel recommended by the lecturer. **The authenticated final rubric is not yet available.** This document is engineering evidence to map once it is; it is not an assessment claim.

ReFind's native v1.0 was procedural PHP: one script per endpoint (`api/*.php`) plus shared helpers. The migration moves every business endpoint behind Laravel's HTTP kernel, router, middleware pipeline, service container, Blade views and test harness. The native JSON contracts, URLs and browser JavaScript are kept so behaviour stays comparable.

## Framework features in use

| Laravel feature | How ReFind uses it | Where |
|---|---|---|
| Application bootstrap and configuration | `Application::configure()` with routing, middleware and exception hooks. Per-profile configuration comes from guarded profile files, with no `.env` secrets in Git. | `bootstrap/app.php`, `config/*.php`, `app/Support/Workspace.php` |
| Routing | A single route file maps the native URLs (`/api/*.php`, `*.html`) to controllers. Route defaults carry the native allowed methods and the access policy. | `routes/web.php` |
| HTTP middleware pipeline | Method boundary, legacy method and `Allow` handling, role-before-CSRF preconditions, a custom 64-hex CSRF guard, security headers and query metrics. The framework's TrimStrings, ConvertEmptyStrings and CORS middleware are removed deliberately to keep native input semantics. | `app/Http/Middleware/*`, `bootstrap/app.php` |
| Controllers | Thin controllers delegate to services and repositories. | `app/Http/Controllers/*` (11 controllers) |
| Service container and dependency injection | Constructor injection throughout. Scoped bindings for the pinned write coordinator and the effect services on the guarded connection. | `app/Providers/AppServiceProvider.php`, `app/Services/*` |
| Database layer | The query builder with prepared statements and bound parameters, plus a custom MariaDB connection driver (`PinnedMariaDbConnection`). The driver pins the connection identity, denies reconnects during protected work, and verifies the server, port, datadir and owner. | `app/Database/PinnedMariaDbConnection.php`, repositories and services |
| Transactions and locking | `WriteCoordinator` runs each write as one owned transaction under a per-schema advisory lock, with ordered row locks (`WriteContext`), an operation ledger, and reconciliation of uncertain outcomes. | `app/Foundation/*` |
| Eloquent ORM and authentication | An Eloquent `User` model with Laravel's `web` guard. `AuthService` adds a credential fingerprint, idle and absolute expiry, and login and registration session establishment. | `app/Models/User.php`, `app/Services/AuthService.php` |
| Sessions | A custom session manager and store on Laravel's file driver (64-hex token, locking), with rotation on login and password change. | `app/Session/*` |
| Views (Blade) | Thin Blade bindings of the native pages (`{{ $BASE_PATH }}`, `@include('partials.navbar')`, `@if` stage gates). Data attributes drive the native jQuery UI. | `resources/views/*.blade.php` (15 views) |
| Validation and error rendering | `Contract` helpers reproduce native field validation. One exception renderer emits the native JSON error shape and sanitizes 500s. | `app/Support/Contract.php`, `app/Exceptions/ContractError.php`, `bootstrap/app.php` |
| File storage | Private media outside the public root, served through authorized controllers with private caching and ETag. Uploads are staged through a journaled media operation (promote before commit, compensate on rollback). | `app/Foundation/MediaJournal.php`, `MediaOperation.php`, `UploadStager.php`, `app/Http/Controllers/MediaController.php` |
| Events | `QueryExecuted` listeners feed query metrics, and tests use them for SQL fault injection. | `app/Providers/AppServiceProvider.php`, `tests/Feature/*` |
| Testing | PHPUnit feature tests on Laravel's HTTP testing layer (`actingAs`, `withSession`, `call`) against a real disposable MariaDB schema, with exact snapshot restore. They cover the native contracts, privacy, gates, fault injection, lost commit acknowledgement and real connection loss. | `tests/Feature/*`, `tests/Unit/*`, `phpunit.phase8.xml` |

## Functional coverage of the native application

| Journey | Native | Laravel candidate |
|---|---|---|
| Sign in/out, sessions, CSRF, roles | yes | accepted at Phase 5 A/B |
| Discovery, item detail, private media | yes | accepted at Phase 5 A/B |
| Lost/Found reports, private evidence, rule matching, history, notifications | yes | accepted at Phase 5 B |
| Claims: submit, resubmit, withdraw, private proof | yes | Phase 6 WIP (unaccepted) |
| BM review: request more info, reject, approve, one pending handover | yes | Phase 6 WIP (unaccepted) |
| Pickup: schedule, reschedule, cancel, return, release | yes | Phase 7 WIP (unaccepted) |
| BM Reports tab and manual status; notification read-marking; account (name, password, avatar); self-registration; history | yes | Phase 8 WIP (unaccepted) |
| Photo search (native: experimental AI visual search) | experimental | `phase8-dev` now, Stable from the 1.4.0 promotion (owner decision 27 Nov 2026, `docs/VISUAL_SEARCH.md`); academic profiles keep it off |

## Test evidence (development profiles, synthetic data)

| Checkpoint | Evidence |
|---|---|
| Phase 6 WIP `1ea27c5` | PHPUnit 34 tests, 2114 assertions; HTTP and races 78 checks; browser 15 checks; owner manual test with no issues |
| Phase 7 WIP `b889b80` | PHPUnit 38 tests, 2661 assertions; pickup HTTP and races 81 checks ×5; browser 10 + 15 checks; owner manual test with no issues |
| Phase 8 integration (this branch) | `tools/verify-candidate.py` on `58bc42c` passed: PHPUnit 43 tests, 2955 assertions; live HTTP and races 77 + 81 + 28 checks; browser 15 + 10 checks; exact restore; protection checks. Evidence: `evidence/phase8/candidate-20261119T095326Z/summary.json`. |

The accepted academic evidence (Phases 4, 5 A and 5 B) is unchanged and lives under `C:\ReFind-Laravel\evidence`. **No Phase 6–8 checkpoint is accepted.** See `RELEASE_PREPARATION.md` for the deferred gates.

## Still to do for the academic submission

- Map each authenticated rubric item to the rows above and to a receipt.
- Produce desktop/mobile and light/dark screenshots or recordings of each journey on synthetic data.
- Add an architecture diagram showing the request path: router → middleware → controller → service → coordinator → MariaDB/media.
