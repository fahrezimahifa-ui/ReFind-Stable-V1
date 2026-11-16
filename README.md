# ReFind Laravel technical proof of concept

**Milestone A only. Phases 0–2. This is not the academic candidate or the full migration.**

WDD requires a PHP framework. Laravel is the lecturer's recommendation and our chosen framework. Academic sufficiency must be assessed against the current rubric in a later reviewed phase.

## Review and run

Workspace: `C:\ReFind-Laravel`. Application: `C:\ReFind-Laravel\laravel`.
The native reference is an independent, pinned, read-only clone at `C:\ReFind-Laravel\native-reference`. The application has its own `.git`, local `migration/laravel13` branch and no remote.

Run from PowerShell:

```powershell
python -B C:\ReFind-Laravel\launchers\control.py start --profile dev
python -B C:\ReFind-Laravel\launchers\control.py status
python -B C:\ReFind-Laravel\launchers\control.py stop
```

Then open <http://127.0.0.1:8013/login.html>. Synthetic login details are stored only in the private `C:\ReFind-Laravel\state\dev\credentials.json`. Never use real ReFind accounts or copy native runtime/configuration into this workspace.

The launcher performs resource, port, configuration, process identity and ownership checks. It never installs a Windows service, edits PATH/XAMPP, controls the existing mysql service, or uses Docker. Startup requires 2 GiB available RAM and 5 GiB free disk. Web servers bind to loopback and serve only `public`.

Every start requires an explicit profile. Test ports are 8014/8015 and use `refind_laravel_test`; development uses port 8013 and `refind_laravel_dev`. MariaDB uses port 3307 and a new data directory. Application credentials have SELECT-only grants. All sessions, cache, generated media and secrets are profile-specific.

## Implemented

- Native UI assets preserved; home/login/browse/item templates adapted to Blade.
- Genuine Laravel routes, controllers, middleware, User model, media policy and read repository.
- Guest/auth session API, login/logout, credential fingerprints, idle/absolute expiry and persistent login limiting.
- Mandatory canonical 64-character CSRF token, including under `APP_ENV=testing`.
- Discovery search/filters/paging, details, generic history, stored suggestions and eligible own edit metadata.
- References, own unread count, protected item/evidence/avatar delivery and authorized thumbnails/revalidation.
- Explicit role guards and unavailable responses for later-phase screens/actions.

## Deliberately unavailable

Registration, profile/password/avatar mutations, report/claim mutations, BM review/status/pickup operations and full notifications/activity screens. Matching recalculation and business transaction/locking migration are deferred. AI visual search and its schema/provider/jobs are absent.

No core request includes a legacy PHP handler. No request mutates business tables. Guarded setup created synthetic data only in the new schemas.

## Verification

Use `tools/run-verification.py` through the local Python interpreter. It verifies only migration-owned resources, captures sanitized receipts and always stops owned processes at completion. It uses the already available Python psutil, Pillow and Playwright packages and installed Chrome; no shared Python/npm package was installed.

The canonical process/startup sources are versioned under `tools/workspace`; deployed launcher copies are under the workspace's `launchers` directory. Runtime provisioning is a fresh-workspace operation, not an update/reset command. Do not rerun source-generation scratch scripts.

See `docs/POC_REPORT.md`, `docs/CSRF_CONTRACT.md`, `docs/MIGRATION_STAGES.md`, and `docs/SOURCE_PROVENANCE.json`.

## Stop and recovery

Stop owned processes before reviewing a failed gate. Keep the isolated state and evidence; no cleanup/reset is automatic. A profile-specific stop leaves the owned database running, while plain `stop` shuts down all owned web instances and gracefully shuts down the owned database. Restart preserves data and credentials.

The original `/refind/`, `/refind-test/`, dirty RC worktree, shared Git metadata, XAMPP configuration and port-3306 services are protected. No push, deployment, live cutover or later migration phase is authorized by this checkpoint.
