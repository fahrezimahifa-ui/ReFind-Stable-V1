# ReFind Laravel: professor UI/UX refinement

Branch `migration/uiux-refinement` from `migration/qa-final` `de7d8bd` (worktree `C:\ReFind-Laravel\laravel-p8`, profile `phase8-dev`). **Unaccepted work in progress.** `migration/qa-final` stays unchanged as the verified fallback, and native main and test (`47eea86`) are untouched.

The audit, implementation plan and acceptance matrix are kept outside the repository in `C:\ReFind-Laravel\planning\professor-uiux\`. [QA_RELEASE_READINESS.md](QA_RELEASE_READINESS.md) records the QA of `de7d8bd`. This file records what changed after it.

## Parity fix found during batch 1: shared stylesheet on four pages

Native `page.php` adds `css/polish.css` to every page it serves. The Laravel views written in Phases 6–8 (`bm`, `profile`, `settings`, `register`) did not include it, so on those pages the Laravel candidate lacked the shared visual layer:

- the floating decision bar;
- the compact Review Queue header;
- the passed-pickup note style;
- the red text style for destructive text actions.

The four views now load it exactly as the other views do. This restores native behaviour and is not a refinement. The structural parity review at `de7d8bd` compared view markup, not served pages, which is why the gap went unnoticed.

## Batch 1: decision hierarchy, passed pickups and notification text (A3, A4, A5)

| Note | Change | Files |
|---|---|---|
| P07, P08 | A Building Management claim shows two buttons: Approve claim (primary) and Reject claim (outline). Request more info is a text button in the same bar. | `public/js/bm.js` (`actionStyle`, `actionRank`), `public/css/polish.css` |
| P07, P10 | A pickup shows at most two buttons. Pending: Schedule pickup, then Confirm item returned. Scheduled: Confirm item returned, then Reschedule pickup. Once the appointment time has passed, Reschedule pickup becomes the primary action. Cancel appointment and Release pickup are text buttons, and Release keeps the red danger style. | `public/js/bm.js` |
| P12 | A passed appointment is shown as flat status, not a control: a clock icon, an amber edge rule and plain text, with no filled box, border box or pill. This applies to the BM detail, the Student My Activity card and the queue card, where the former "Time passed" chip is now flat text. | `public/js/bm.js`, `public/css/polish.css` |
| P05 | The notification message is 14 px, below the 16 px title. Previously both were 16 px. | `public/css/polish.css` |

**Preserved:**
- Every operation the API offers is still rendered, with the same `data-bm-action`, click handler, dialog, required reason, confirmation tick and server-side checks.
- No API, database, grant or workflow change.
- The browser suites still select controls by `data-bm-action`.

**Intentional native differences:** `js/bm.js` and `css/polish.css` are recorded in [SOURCE_PROVENANCE.json](SOURCE_PROVENANCE.json) (`adapted_assets`, with hashes and reasons). The guard expectation in `tools/test-academic-guards.py` lists them exactly.

**Tests updated:**
- `tools/test-phase6-ui.py`: two claim buttons with Approve first; Request more info is a text button opened by keyboard.
- `tools/test-phase7-ui.py`:
  - pending pickup shows two buttons and Release stays enabled;
  - a passed appointment, shown by moving only the browser clock, keeps all four operations, with Reschedule first and primary;
  - flat passed-time status in the BM detail, queue and Student card;
  - notification message smaller than its title.

## Verification of batch 1 (19 November 2026)

These checks ran on the working tree that became the batch-1 commits, on `phase8-dev` with synthetic data. The flow reused the `verify-candidate.py` helpers: preflight, static checks, snapshot, live checks, exact restore, protection checks.

| Check | Result |
|---|---|
| Static: `php -l` (122 files), `node --check` on the page scripts, `py_compile` on the tools | passed |
| `test-phase6-ui.py` | 17 checks passed |
| `test-phase7-ui.py` | 19 checks passed |
| `test-phase8-ui.py` (profile, settings, history and register pages, which now load the shared layer) | 11 checks passed |
| Before/after evidence | 16 checks passed. Two decision buttons per bar, flat passed-time status, no attention chip in the queue, notification message 14 px against a 16 px title, no horizontal overflow, no JavaScript errors. |
| Keyboard | Tab order follows the visual order. Each control shows a focus ring. Enter opens the Request more info and Release pickup dialogs, Escape closes them, and focus returns to the control. |
| Synthetic state after restore | identical |
| Protection | Academic grants match the 6B evidence, 6B archive `c1f4bde6…`, 19/19 canonical files, accepted refs unchanged |

**Evidence:**
- `C:\ReFind-Laravel\evidence\uiux\batch1-20261119T105624Z` (suites);
- `C:\ReFind-Laravel\evidence\uiux\batch1-20261119T110219Z` (evidence re-run with a corrected stylesheet metric and a native-equivalent view);
- screenshots and metrics in `C:\ReFind-Laravel\planning\professor-uiux\after\`.

Each screenshot comes in three variants on the same data:
- `before` is `de7d8bd` as served;
- `native` is the same assets with the shared layer on every page, as native serves them;
- `after` is this batch.

**Not covered by this batch's checks:**
- PHPUnit, inherited CLI suites, HTTP and security suites. There was no backend change; they run once on the final refinement SHA.
- Widths other than 390 and 1440 px.
- Real devices, screen readers and forced-colors mode.
- Formal contrast measurement on translucent panels. The script gives an approximate 5.7:1 or better for the queue flag.

Nothing here replaces the final `verify-candidate.py --browser` run, or the deferred acceptance gates in QA_RELEASE_READINESS.md.

## Defect found, not changed

`app/Http/Controllers/DiscoveryController.php` (the `references.php` response) has carried a double-encoded service-desk hours string since the foundation commit `ac03a9d`. The page shows "Mondayâ€“Friday, 08:00â€“16:00 WIB", where native shows "Monday–Friday, 08:00–16:00 WIB". It appears in My Activity and on the claim page. The text is part of the accepted API response, so it is left for a separately approved fix.
