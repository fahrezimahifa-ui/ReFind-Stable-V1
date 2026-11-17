# Phase 5 checkpoint A — stopped for review

The first acceptance gate did not pass. No checkpoint A commit was created, and matching/history/notification implementation did not start.

## Verified source state

- Branch: migration/academic-b.
- Last committed and accepted checkpoint: af7b349bd7bdcaa2845bc7ae4ac9a04c542dc31b (Phase 4).
- Phase 5 work remains uncommitted and preserved for inspection.
- Original PoC branch remains ac03a9dae33c5d03318ac357fdb0c31abcaffa75.
- All owned runtimes were stopped by the failed verifier's finalizer.

## Failure

The new query-count hook calls DB::listen before DB::extend registers the guarded MariaDB connection. Laravel resolves and caches a standard Illuminate MariaDbConnection at that point. ReportService dependency construction subsequently fails because WriteCoordinator requires PinnedMariaDbConnection.

The type guard rejected the mismatch before the report consistency test could perform its operations. This is an integration-ordering defect, not an accepted checkpoint.

The standalone write-foundation script also encountered this initialization error before its local try/catch. Laravel's console exception handler returned exit code 0, so the runner printed a misleading PASS line for that script. That line is invalid evidence: the suite did not complete. The later report-service command returned nonzero and stopped the overall gate. No passing Phase 5 checkpoint is claimed.

## Work prepared, not accepted

Laravel report CRUD/media service, ownership policy, report/My Reports Blade views, request metrics, column-scoped grant tooling and HTTP/browser tests were prepared. Matching effects remain empty. Academic development writes remain disabled; all unsupported workflows remain unavailable.

Only academic-test received additional report permissions: selected INSERT/UPDATE columns and DELETE on items/item_photos. Existing operation-ledger/probe permissions remain. No matching, history or notification write grants were added. Academic-dev remains read-only. The effective-grant audits for both profiles passed before the initialization failure.

A prior development smoke check passed 28 CRUD/media checks before the metrics change. It is not acceptance evidence for the current working tree.

## Valid results from this attempted gate

PHP syntax (78 files), Composer strict validation, platform checks, academic guard checks and both effective-privilege audits completed successfully. Report consistency, HTTP and browser acceptance did not complete. The failed verification summary is authoritative.

The 2 GiB RAM / 5 GiB disk gates were retained and passed before startup. No threshold was lowered. Final read-only preservation checks confirmed the original PoC's 237 protected files, six native/configuration groups and ten existing RC modifications match their baselines. No native database was contacted. No push, merge or deployment occurred.

## Required correction before another attempt

1. Register the guarded driver before any connection resolution; attach query counting through the event dispatcher so adding metrics cannot initialize a default connection.
2. Ensure standalone CLI initialization errors exit nonzero, including failures before a suite's local try/catch.
3. Require fresh completed suite receipts, not exit code alone, before reporting PASS.
4. Rerun checkpoint A in full and create its local checkpoint only after it passes. Matching remains blocked until then.

Failed receipts: C:/ReFind-Laravel/evidence/phase5/verification-crud-20261117T081509Z.

This stop follows the user's explicit instruction to stop when an internal checkpoint fails. No further implementation or privilege changes were made after the failure.
