# ReFind Laravel foundation and technical proof of concept

Review checkpoint: 17 November 2026 (Asia/Bangkok). Scope completed: **Phases 0–2 only**.

This is Milestone A, a working technical proof of concept. It is not the first academically demonstrable release, a complete parallel release, a deployment, or a live cutover. A PHP framework is mandatory for WDD. Laravel is the lecturer's recommendation and the project's chosen framework. The current rubric must be obtained and mapped before Milestone B can be called assessment-ready; a partial prototype does not establish compliance.

## Outcome and baseline

The PoC runs genuine Laravel routes, middleware, controllers, authentication, a model, a media policy, and database read abstractions. Core requests do not include or forward to procedural handlers. Existing frontend assets and user interactions are retained for the implemented pages.

The independent source reference is pinned to GitHub `fahrezimahifa-ui/ReFind-v0.2.5` main at **47eea8648d2d47faa895d855fe6da53e6b1a3087**. A read-only GitHub branch check at completion returned the same SHA. The reference checkout is clean and detached. Provenance hashes are recorded in `SOURCE_PROVENANCE.json`.

The original native installations remain the functional reference. The protection check confirmed the original Git HEAD/status, configuration fingerprints, private storage/session/auth fingerprints, and all ten pre-existing modified RC files remain unchanged. It checked four installation groups and two shared configuration files. No implementation test connected to a native database or sent HTTP requests to a native installation. Therefore these checks establish filesystem/Git/configuration preservation; they are not a new database-content snapshot of native services.

## Exact directory and Git ownership

| Location | Purpose and ownership |
|---|---|
| `C:\xampp\htdocs\refind` | Protected stable installation; no writes or branch changes |
| `C:\xampp\htdocs\refind-test` | Protected persistent QA; no writes or branch changes |
| `C:\xampp\htdocs\refind-dev` and `refind-v1-rc` | Protected existing worktrees; pre-existing RC changes preserved |
| `C:\xampp\htdocs\ReFind_0.2.3` | Existing shared Git metadata; not used to own Laravel work |
| `C:\ReFind-Laravel\native-reference` | Independent clone with its own `.git`; detached at the baseline; remote fetch source retained, push URL disabled; never executed |
| `C:\ReFind-Laravel\laravel` | Independent new repository; local `migration/laravel13` branch; no remote, no push, no relationship to native branch ownership |
| `C:\ReFind-Laravel\runtime` | Private portable PHP, MariaDB, Composer and verified downloads |
| `C:\ReFind-Laravel\state` | Private profile configuration, new credentials/keys, DB data, sessions, limiter/cache/log state and generated synthetic media; outside either source repository |
| `C:\ReFind-Laravel\launchers` | Deployed owned process controllers; versioned canonical copies in `laravel/tools/workspace` |
| `C:\ReFind-Laravel\evidence` | Local verification receipts and screenshots; selected sanitized receipts also versioned under `docs/verification` |

Neither checkout is a Git worktree of the other. Laravel changes belong only to its migration branch. Native fixes or later upstream changes require a separately reviewed action; source drift is not automatically incorporated. Runtime roots are not symlinked or junctioned into native directories.

## Verified runtime and isolation

| Component | Installed and exercised |
|---|---|
| Framework | Laravel v13.42.0, locked in Composer |
| PHP | Portable NTS Windows x64 PHP 8.5.12, own explicit php.ini |
| Database | Portable MariaDB 10.11.19, loopback port 3307, own data directory |
| Composer | 2.11.2, own home/cache, no application install scripts |
| Verification | PHPUnit 12.5.44; existing Python 3.13 and psutil/Pillow/Playwright; installed Chrome |
| Formatting tool | Laravel Pint 1.32.3, local development dependency |

PHP 8.5 resolved the selected dependencies successfully. PHP 8.4 remains the approved alternative if a later dependency blocks 8.5, but was not installed or validated here. It requires its own explicit runtime/launcher validation and rerunning the gates. The application declares PHP `^8.4`. The Laravel 12/PHP 8.2 fallback has not been provisioned or mixed into this workspace. XAMPP continues to use its existing PHP 8.2.12, Apache 2.4.58 and MariaDB 10.4.32 configuration.

The launcher binds development web to 127.0.0.1:8013 and test web to 8014, with 8015 used only for a second test process. MariaDB binds to 127.0.0.1:3307. No Windows service, Docker, proxy/vhost, PATH edit, XAMPP edit, or automatic startup was introduced. PHP serves only the new application's public directory through an allowlisted router.

Startup requires at least 2 GiB available RAM and 5 GiB free disk. Private logs exceeding 20 MiB block further startup pending review. PHP has a 128 MiB memory limit. MariaDB uses a 128 MiB buffer pool, 12 connections, bounded temporary tables, and disabled performance schema/binary logging. At most two owned web processes are allowed; browser checks use one worker and recheck headroom. Process ownership includes executable path, exact arguments, PID creation time and workspace markers; the controller refuses unowned processes.

An initial insufficient-memory gate stopped work before provisioning; it was not weakened. After headroom recovered, work continued. The final verification's point-in-time RSS sample was approximately 111.4 MiB for the owned DB and 49.9 MiB for one web process. These are samples, not peak-memory benchmarks.

## Implemented functionality and file map

| Native responsibility | Laravel implementation | Scope at this checkpoint |
|---|---|---|
| `app/routes.php`, frontend HTML entry points | `routes/web.php`, `PageController`, `SafeReturnUrl`, Blade views | Home, login, browse, item; page allowlist, role guards and safe return paths |
| `app/config.php`, `app/db.php`, API bootstrap | `Workspace`, `AppServiceProvider`, `config/*`, `bootstrap/app.php` | Explicit profile/runtime/connection guards; no native bootstrap executed |
| `api/session.php`, `api/auth.php`, `app/auth.php` | `AuthController`, `AuthService`, `LoginLimiter`, `User`, session classes | Guest/current identity, login/logout, active-account/fingerprint/expiry checks and persistent limiting |
| `app/http.php`, method and CSRF boundaries | `Contract`, `ContractError`, `ApiMethodBoundary`, `LegacyMethod`, `ReFindCsrfMiddleware`, `SecurityHeaders` | JSON/error compatibility, exact methods/Allow, mandatory CSRF and CSP |
| Read portions of `api/items.php`, `app/repository.php`, `item-filters.php`, `search.php` | `DiscoveryController`, `ItemReadRepository`, `Search` | Search/filter/paging/detail, role-sensitive projections, own editable metadata, generic history and stored suggestions |
| `api/references.php` | `DiscoveryController::references` | Reference filters, campus date/timezone, synthetic desk contact |
| Read count from `api/notifications.php` | `DiscoveryController::notifications` | Own unread count only |
| `api/photos.php`, `api/avatar.php`, read policies | `MediaController`, `MediaPolicy` | Authenticated private delivery, evidence authorization, thumbnails and authorized conditional requests |
| Native CSS, JavaScript, branding, fonts, browser vendor assets | `public/css`, `public/js`, `public/assets`, `public/vendor` | Byte-preserved allowlist with provenance hashes; no frontend framework or build step |
| Native core SQL and view/reference definitions | `database/core-schema.sql`, view/reference SQL, guarded `tools/provision.php` | Fresh synthetic dev/test schemas only; experimental embedding schema excluded |
| Report/claim/account/staff/handovers mutations | Guarded route boundaries | Explicit unavailable responses; no procedural forwarding |
| Visual-search endpoints/provider/index/jobs | Disabled route response only | No AI feature, provider, jobs, indexing or embedding table activated |

Only the four implemented screens render their native UI in Blade. Registration redirects according to the existing disabled-registration policy. Other authorized screens return an explicit 501 message; wrong-role requests return 403 and guests receive safe login redirects. BM authentication works, but its admin landing screen remains unavailable; BM can use the implemented browse flow. Assets for future screens are present without claiming those screens are complete.

Discovery exposes existing SQL and views through Laravel's database API rather than mechanically converting complex queries to Eloquent. The User model supports Laravel authentication. Stored match suggestions are synthetic records; matching recalculation has not been migrated.

## Database, authentication and media boundaries

Two new schemas, `refind_laravel_dev` and `refind_laravel_test`, exist only on the owned MariaDB instance. Each has 14 native core tables, two views and a separate workspace-ownership metadata table. They use copied core definitions and synthetic data: four users, eight items and small claim, match, notification/history and media fixtures. No native accounts, password hashes, credentials, uploads, sessions, backups or business records were copied.

Each application identity has SELECT-only access to its own schema. DDL and fixture insertion occurred only through an explicit, guarded new-workspace provisioning tool. No Artisan migration, default Laravel migration, native setup/seeding script or cleanup command was executed. A zero-row UPDATE test confirms the application identity cannot write business tables. Connections validate loopback/port/database/user, actual server data directory and workspace nonce before application queries. Default framework database aliases and storage-serving routes are removed.

Legacy-format synthetic bcrypt cost-10 hashes authenticate using `password_verify`; automatic rehashing is disabled for this read-only PoC. Actual native-account compatibility remains to be established on a later authorized sanitized copy, including algorithm/length inventory and password-change behavior. Native sessions are not imported or shared. Profiles use different application keys, host-only HttpOnly SameSite=Lax cookies, private session files, limiter keys and cache roots. Local HTTP cookies are suitable only for this loopback PoC; a deployed HTTPS candidate requires Secure-cookie configuration and its own acceptance gate.

Media is generated synthetic content under profile-specific private storage. There is no public storage link. Authorization runs before thumbnail creation, ETag evaluation or 304 response. Photo and avatar file responses explicitly force private caching, overriding Symfony's default public file-response behavior. Derivative thumbnails may be created in private storage; authoritative media remains unchanged. An authorization check is required for every revalidation, including requests with a previously obtained ETag.

## The resolved CSRF contract

Laravel retains one canonical `_token` in its session, but its token generation is specialized to `bin2hex(random_bytes(32))`: exactly 64 lowercase hexadecimal characters. Existing `csrf_token()` and API `csrf` read that same token. There is no second compatibility token to synchronize.

The browser sends `X-CSRF-Token` or optional `_csrf`. Conflicting aliases, missing/invalid tokens or an unapproved supplied Origin fail with 403 `csrf_invalid`. Laravel's `_token` body alias, X-XSRF-TOKEN and automatic XSRF cookie are not additional public channels. Native identity/role/precondition ordering is preserved. Login rotates the ID and token; logout, timeout and credential-fingerprint failure invalidate stale authentication.

The replacement forgery middleware has no testing/console or same-origin bypass. PHPUnit executes it with `APP_ENV=testing`; actual-cookie HTTP tests and independent-process session tests also exercise it. Middleware is not disabled in these security tests. See `CSRF_CONTRACT.md` for the full boundary.

## Verification and independent acceptance gates

Latest full verification: `verification-20261116T182749Z`; completed 2026-11-17 local time. All gates passed and all owned processes stopped afterward.

| Gate | Result |
|---|---|
| Phase 0: protected native baseline and separate Git ownership | Pass; reference clean/pinned, no Laravel remote, original fingerprints and ten RC changes preserved |
| Phase 1: resource/process/configuration/database boundaries | Pass; 60 guard checks, explicit profiles and refusal of wrong ports/datadir/schema/unowned processes |
| PHP syntax and dependency compatibility | 47 PHP files; Composer strict validation and platform requirements pass |
| Dependency security audit | No reported advisories or abandoned packages in locked dependencies at verification time |
| Ported pure search behavior | 50 regression checks pass |
| PHPUnit authentication/session/security behavior | 18 tests, 59 assertions pass |
| Actual HTTP contracts | 136 checks pass, including methods/roles/CSRF/limiter/media/privacy/cache behavior |
| Independent-process session locking | Four checks pass on ports 8014/8015; observed blocking about 1.13 s; old cookie rejected after concurrent logout |
| Browser and frontend compatibility | 132 checks; light/dark at 360, 390, 768, 1280 and 1440 px; no JavaScript errors or external/native requests |
| Business data and authoritative-media preservation | Synthetic test schema/data/media fingerprints equal before/after and match initial fixture snapshot across restart |
| Stop/restart and safe shutdown | Pass; graceful owned shutdown, reserved ports free, state retained |

Selected receipts are in `docs/verification`; the complete local evidence and screenshots are under `C:\ReFind-Laravel\evidence`. Test counts describe this PoC, not full functional parity. Browser screenshots were visually reviewed for desktop and mobile representative screens. No mutation transaction/concurrency parity is claimed from the session-lock test.

Framework defaults found and explicitly addressed include merged default database/storage configuration, automatic OPTIONS handling, testing/same-origin forgery bypasses, and public caching of file responses. These findings justify retaining the contract tests as future migration gates.

## Remaining risks and dependencies

| Risk/dependency | Current control | Required later evidence |
|---|---|---|
| Unknown WDD rubric coverage | PoC clearly labeled technical only | Approved feature/evidence traceability matrix; every mandatory criterion met |
| Transactional parity not migrated | No business writes; SELECT-only accounts | State-machine, audit/notification atomicity, rollback and failure-injection tests |
| Advisory/row locking and stale revisions | Native design retained as required reference | Same-connection advisory acquire/release, fresh revisions, sorted parent locks, deadlock/retry and contention tests on MariaDB |
| Filesystem/database atomicity | Synthetic authoritative media and read-only business boundary | Upload validation/staging, commit-time promotion, compensating deletion and recovery tests |
| Framework/version drift | Lockfile, runtime hashes, contract tests | Reviewed dependency updates and repeated gates; validate PHP 8.4 separately if selected |
| Cross-profile or native access | Fixed roots, separate credentials/keys/cookies, runtime/ownership guards | Keep guards during every later phase; never introduce native DB fallbacks |
| Development convenience mistaken for production hardening | Loopback-only servers and explicit scope | Separate deployment/security/backup/recovery plan before any cutover |
| Concurrent native changes | Pinned reference and original-state receipts | Explicit rebaseline review; no automatic import or branch reset |

## Operate, stop and review

From PowerShell, start only when needed:

```powershell
python -B C:\ReFind-Laravel\launchers\control.py start --profile dev
python -B C:\ReFind-Laravel\launchers\control.py status
python -B C:\ReFind-Laravel\launchers\control.py stop
```

Then open `http://127.0.0.1:8013/login.html`. Synthetic credentials are available locally in `C:\ReFind-Laravel\state\dev\credentials.json`; their values are deliberately excluded from source and this report. Tests use their own profile. Run the complete gate with `python -B C:\ReFind-Laravel\laravel\tools\run-verification.py`; it closes owned runtimes in its finalizer.

Plain `stop` gracefully stops the owned database and all owned web processes. A profile-specific stop stops that profile's web processes only. Neither operation controls port-3306/XAMPP services. Data and evidence are retained. Restart does not reset fixtures or credentials. If a gate fails, stop and review the isolated receipts; do not repair or reset the native application. Do not rerun generation scratch scripts or treat fresh provisioning as a reset command.

The local source checkpoint is on `migration/laravel13`; no remote is configured and nothing was pushed. Runtime secrets and state are outside Git. Rollback for this checkpoint is to stop the owned processes and leave the isolated workspace inactive. The native application remains available through its existing setup without a restoration operation.

**Stop here for review.** Milestone B needs its rubric/feature scope and execution approval. Full parallel parity remains Milestone C. No live-data sharing, native session sharing, production routing switch, AI activation or later migration phase is authorized by this checkpoint.
