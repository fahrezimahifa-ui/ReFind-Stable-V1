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

## Parity fix: service-desk hours text (approved 19 November 2026)

`app/Http/Controllers/DiscoveryController.php` (the guest `references.php` response) had carried a double-encoded service-desk hours string since the foundation commit `ac03a9d`. Pages showed "Mondayâ€“Friday, 08:00â€“16:00 WIB", where native shows "Monday–Friday, 08:00–16:00 WIB". The text appears in My Activity and on the claim page.

**The fix:** the string now uses UTF-8 en dashes (`e2 80 93`), identical to native `app/config.php`. The other desk fields are unchanged, including the synthetic contact address `bm@refind-poc.invalid`. The accepted checkpoints also carry the old text, so the academic profiles get this correction only when this branch is integrated.

**Regression test:** `tests/Feature/ReferencesContractTest.php` asserts the exact desk text and that the decoded payload contains no double-encoding markers (`Ã`, `Â`, `â€`).

**Verification**, on `phase8-dev` with an exact restore (evidence `C:\ReFind-Laravel\evidence\uiux\references-text-20261119T110817Z`):

| Check | Result |
|---|---|
| Static checks (123 PHP files) | passed |
| Full PHPUnit, `phpunit.phase8.xml` | 45 tests, 2,970 assertions, 0 failures |
| Live guest GET of `references.php` | hours text identical to native, no double-encoding markers |
| Synthetic state after restore | identical |
| Protection | passed |

The new test was not run against the old code. With the old string, both assertions would fail.

The only other occurrence in the repository is `docs/verification/phase4/search-regression.txt`, a recorded Phase 4 test log whose "…" ellipses were garbled when the log was written. It is a historical record, so it is left as recorded.

## Batch 2: remaining Wave A and B notes (P01–P04, P06, P09, P11, P13)

Where the plan left a visual choice open, the conservative option recorded in the plan was used. Each choice can be reversed on its own.

| Note | Change | Decision recorded |
|---|---|---|
| P01 | Status flags (`.item-chip`) and information notes (`.context-note`, `.bm-outcome`, `.feedback`, `.desk-card`, `.report-visibility`) are square (3 px). Buttons, tabs and fields keep the 11 px control radius. | Controls were not made pill-shaped, because the existing rounded radius already reads as pressable and keeps the brand. Panels and cards keep their 18 px radius as containers. |
| P03 | Card previews use `object-fit: cover` at every width; previously phones letterboxed them. The item page still shows the whole photo. | A preview can crop the edges of a portrait photo. The full photo is one tap away. |
| P04 | Flags in Explore and History cards, the item page header, the BM record header and My Activity cards span the full width as a strip of square flags. | Option A, the segmented strip, was used. It needs no markup change; option B, a band under the image, was not used. |
| P02 | Long body copy (item description, BM claim and report description, claimant answer and finder clue, notification message) is justified with hyphenation from 768 px up. | Discovery cards contain only a title and short meta lines, so no card text is justified. Phones stay start-aligned to avoid uneven word spacing. |
| P06 | The numbered lists ("What happens after you submit", BM status and pickup history) are indented 2.5 em, so items start 35–40 px inside the heading. | |
| P09 | On phones (479 px and narrower), a group of four or more view tabs becomes one labelled native select that drives the same buttons, handlers and URL. Only History has such a group. | Explore already used a Filters disclosure. BM, My Activity and Notifications have two or three tabs, which fit on one row. Batch 3 extended the select to 767 px. |
| P11 | On desktop (1100 px and wider), `bm.js` sizes the Review Queue panes to the space under the heading, so the page itself no longer scrolls. The queue and the detail each scroll independently, and nothing scrolls inside a scrolling page. Smaller screens keep one page scroll. | The two-pane review layout keeps the queue position and the decision bar in view. Removing the panes' own scrolling would lose both. Panes are at least 400 px tall, so very short windows still scroll the page. **Superseded by batch 3 after owner review:** the page is now the only scroller. |
| P13 | A static background layer of soft pools runs the full document height, above the fixed wash. Two tile heights that don't divide each other (1700 and 1100 px) make the placement look irregular. It is hidden for reduced transparency and forced colours. | "Random" is read as varied placement, not as a pattern that changes on each load. Full-page screenshots still show the fixed wash only in the first screen height; that is how they draw fixed layers. In a real scrolling window the wash always covers the screen. |

**Tests updated:**
- `tools/test-phase8-ui.py`: the phone History select is labelled, changes the view and syncs the tab state; the BM desktop page does not scroll.
- `tests/Feature/PickupContractTest.php`: a passed appointment keeps all four operations and can be rescheduled to a new future time (P10).

**Focused checks, 19 November 2026.** Each ran on `phase8-dev` and restored exactly to the verified baseline:

| Check | Result |
|---|---|
| Before/after measurement | 17 page and size combinations each run. Evidence in `C:\ReFind-Laravel\planning\professor-uiux\after\batch2\{before,after}`, run `measure-after-20261119T112226Z`. |
| `test-phase8-ui.py` | 14 checks passed |
| `PickupContractTest` | 5 tests, 555 assertions |

**Measured before → after:**
- flag row filled: 15–55% → 100%;
- empty bands in phone previews: up to 58% → 0%;
- list indent: 20 px → 35–40 px;
- History tabs at 390 px: two rows → one select;
- BM desktop page scroll: 1,108 px document in a 900 px window → 900 px, at 1280×720, 1366×768, 1440×900 and 1920×1080;
- no horizontal overflow and no JavaScript errors at any measured size.

## P10: pickup rescheduling (verified)

Phase 7 already provides BM rescheduling, the same as native `47eea86`:
- It applies to a SCHEDULED pickup and needs a new future Asia/Jakarta time and an active area.
- It needs a reason of 8–250 characters and refuses a request that changes nothing.
- It checks the displayed revision, and is race-tested against cancellation.
- It notifies the claimant and the other BMs, and records the change in the private pickup history.

A pending pickup is scheduled with Schedule pickup. Batch 1 made Reschedule the primary action once an appointment time has passed. The new contract test confirms that a passed appointment can be rescheduled.

**Remaining gap:** there is no student-initiated reschedule request. Students see "contact Building Management to arrange a new time". Adding one is a new workflow with its own business rules, data and tests, and needs separate approval (plan Wave C). No placeholder control was added.

## P12: passed pickup information and the Release action

Passive information is now non-interactive: the passed-appointment note in the BM detail and in the Student's My Activity card, and the "Time passed" flag in the BM queue. Each is flat text with a clock icon, with no button role, pointer cursor, fill or pill shape.

The genuine workflow action, Release pickup, is unchanged in authority. It appears whenever the API offers it, for pending and scheduled pickups, and opens the reason and confirmation dialog. Since batch 3 it is a red outline button in its own labelled section, "If the owner never collects". Nothing in the UI releases a pickup automatically when its time passes, and the server has no expiry rule.

**Unresolved:** the professor's note "Release pickup expire" does not say whether it also asks for an automatic expiry or release policy. No such rule exists natively, and "Never retrieved" does not set a disposal policy. Adding one would be a new business rule, so it is documented here rather than invented.

## Final candidate verification (19 November 2026)

`python -B tools/verify-candidate.py --browser` ran once on the clean committed candidate **`b94bb60`** (`dirty_worktree: false`). Evidence: `C:\ReFind-Laravel\evidence\phase8\candidate-20261119T112924Z\summary.json`. The result is **passed** with `accepted: false`.

| Step | Result |
|---|---|
| Preflight, static checks (123 PHP files, page scripts, tools), exact grant audit | passed |
| PHPUnit (`phpunit.phase8.xml`) | 46 tests, 2,983 assertions, 0 failures |
| Inherited Phase 5 suites | search passed; report service 27; native matching parity 205, restore verified |
| Live HTTP and concurrency | Phase 6: 77; Phase 7: 81; Phase 8: 28. Every race outcome legal. |
| Security probes | 75 |
| Browser suites | Phase 6: 17; Phase 7: 19; Phase 8: 14 |
| Synthetic state after restore | identical |
| Protection | Academic grants match the 6B evidence, 6B archive `c1f4bde6…`, 19/19 canonical files, accepted refs unchanged |

This run verified `b94bb60`. Batch 3, below, changed code after it, so the batch 3 regression supersedes this run. The deferred acceptance gates in [QA_RELEASE_READINESS.md](QA_RELEASE_READINESS.md) are unchanged:
- independent native HTTP parity;
- the full inherited matrix with fresh receipts;
- fresh-Windows qualification;
- academic activation;
- rubric evidence;
- owner sign-offs.

## Batch 3: owner review corrections (P07, P11, empty space)

The owner's manual review on 19 November 2026 superseded the earlier readings of P07 and P11.

**Corrected interpretations:**
- **P11:** the browser's page scrollbar is the only ordinary scroller on the BM dashboard. Two independently scrolling panes still count as double scrolling. Dialogs may scroll internally.
- **P07:** the two prominent pickup actions depend on the state. Cancel appointment and Release pickup belong in their own clearly labelled, lower-priority places. They are never hidden to meet a button count.

| Item | Change | Files |
|---|---|---|
| P11 | From 1100 px wide, the queue and detail columns keep their natural height. The fixed height and inner `overflow-y` are overridden, so only the page scrolls. The detail column is sticky and moves with the page until one of its edges reaches the window, so a tall record reads top to bottom while the queue keeps its place. Selecting a card shows the record from its top without moving the page; the page moves only when the record's start can't be shown in place, at the very end of a long queue. The decision bar sticks to the window bottom. Below 1100 px nothing changed: one page scroll with a queue or detail view. The existing 24-per-page queue paging with Previous/Next stays as the pagination, so search and filter semantics are unchanged. | `public/js/bm.js` (`placeDetail`, `revealDetail`; batch 2's `fitPanes` removed), `public/css/polish.css` |
| P07 | Pickup main row: **pending** shows Schedule pickup (primary) and Confirm item returned; **scheduled and overdue** show Reschedule pickup (primary) and Confirm item returned. Below the row, an **Appointment** section holds Cancel appointment, with the note that ownership stays approved, and an **"If the owner never collects"** section holds Release pickup, with what it does and the existing reason and confirmation dialog. Every operation the API offers is still rendered with the same `data-bm-action`, handler, dialog, permissions and server checks. Claim decisions are unchanged: Approve, Reject, and "Request more info" as a text button. | `public/js/bm.js` (`handoverDetail`, `actionButton` style argument), `public/css/polish.css` |
| Segmented controls | Below 768 px, view-tab groups of two or three are full-width rows of equal segments: Explore All/Lost/Found inside the filter card, My Activity, Notifications and the BM workflow tabs. Borders, active state, `aria-pressed` and focus are unchanged. Wider screens keep their compact left-aligned tabs. | `public/css/polish.css` |
| Empty-space audit | History's five-tab group now becomes its labelled select up to 767 px, not 479 px, so it no longer wraps onto a half-empty second row on small tablets. | `public/css/polish.css` |

**Empty-space audit, phones and tablets:**
- **Checked and already fine:** the report form's Lost/Found switch (already full width), the Explore search and Filters row, the result-count and Clear filters row, the claim and activity card footers, the BM claim action bar and the pager.
- **Noted, not changed:** the large "No public photo provided" box on cards and the item page when a report has no photo. It is a deliberate native placeholder that keeps the card rhythm. Making it compact on phones is a design decision for the owner.

**Tests updated:**
- `tools/test-phase7-ui.py`:
  - each pickup state shows its two main actions in order;
  - Cancel appointment sits in the Appointment section;
  - Release sits in its own section and stays enabled;
  - all four scheduled operations are still present.
- `tools/test-phase8-ui.py`: the BM desktop layout has no inner scroll areas.

**Focused checks** (`C:\ReFind-Laravel\evidence\uiux\batch3-check-20261119T141327Z`; screenshots and metrics in `planning\professor-uiux\after\batch3\`). The run passed 118 checks and restored exactly to the verified baseline. The fixtures were created through the normal API, with one exception: the overdue appointment's stored time was moved into the past in the disposable schema. They were 26 approved pickups (two queue pages), one with five reschedules and long reasons, one overdue appointment, and a claim with a long answer and two evidence photos.

The checks covered:
- At 360, 390, 768, 1024 and 1440 px, on BM Reports, BM Handover queue and detail, a long BM claim, Explore, My Activity, Notifications and History: no inner scroll areas, no horizontal overflow, and on phones every segmented group fills its row with equal segments.
- At 1440 px:
  - a deep link leaves the page at the top;
  - the tall pickup record can be read to its end with the page scroll;
  - the decision bar never strands below the window;
  - selecting the 21st queue card keeps the page exactly where it was (scroll position 3447 before and after), with the record's top just under the header;
  - arrow-key queue navigation keeps the focused card in view.
- Pending, scheduled and overdue pickups at 390 and 1440 px show the expected main row and sections, and the overdue note is flat status.
- No JavaScript errors.

## Automatic pickup expiry: decision needed (not implemented)

**Findings:**
1. **Native `47eea86`:** nothing happens when a scheduled time passes. The past-due indicator is "Display only; offers existing confirm-return/reschedule. No no-show policy" (`docs/V1_RELEASE_CANDIDATE.md`). Release is the manual BM operation "for no-shows or revoked ownership" (`docs/V025_REPORT.md`). A disposal or no-show policy is listed as excluded, pending stakeholder decisions.
2. **Phase 7 Laravel:** the same. There is no scheduler, console job or expiry rule; a passed time changes only the display.
3. **Roadmap:** it proposes approved retention, display and reminder rules but does not specify them. It warns that "approximate one week is insufficient for an automatic timer" (`docs/REQUIREMENTS_AND_ROADMAP.md`). The project context adds that the staff account of practice "does not authorize automatic day-seven transfers" and that Never retrieved "does not authorize disposal" (`docs/PROJECT_CONTEXT.md`).

**What an automatic transition would affect:**
- **Automatic release** would reject the approved claim and revoke ownership, turn the confirmed pair to REJECTED, delete the handover and reopen both reports. It would also notify the claimant and the finder, and add history and audit rows.
- **Automatic appointment cancellation** would keep ownership but clear the time, return the pickup to awaiting scheduling, and notify the claimant and the BMs.
- **Either one needs:**
  - a scheduled job, which the app doesn't have;
  - the same advisory lock and revision checks as manual operations, to avoid racing a BM confirming a late collection;
  - an `Asia/Jakarta` working-day rule for the grace period;
  - idempotent replays and a test matrix.

A passed appointment doesn't mean the item was returned, and it doesn't justify revoking ownership on its own.

**Proposal for approval** (nothing enabled):
- **A (recommended now):** keep release manual, as today. Optionally add a BM "Time passed" filter in the Handover queue, and an in-app reminder to the claimant when an appointment passes. Both inform without changing ownership.
- **B:** after an approved grace period, for example two campus working days after the appointment, cancel the appointment automatically. Ownership is kept and the pickup returns to awaiting scheduling, with notifications. This needs a scheduler, an audit actor ("System") and tests.
- **C:** automatically release after a longer approved period. This revokes ownership, so it needs an explicit campus policy, exceptions for valuables, a final BM confirmation step, and owner and stakeholder sign-off.

## Batch 3 integrated regression (19 November 2026)

`python -B tools/verify-candidate.py --browser` ran on the clean committed candidate **`63c93b0`** (`dirty_worktree: false`) and **passed** with `accepted: false`. Evidence: `C:\ReFind-Laravel\evidence\phase8\candidate-20261119T142627Z\summary.json`. It supersedes the `b94bb60` run above.

An earlier attempt on the same commit (`candidate-20261119T141936Z`) passed every stage up to the HTTP and security suites. The 2 GiB memory gate then refused the browser-stage worker restart; the gate was not lowered. That attempt restored its snapshot. The completed run's starting state matched the verified baseline, which confirms that restore was exact.

| Step | Result |
|---|---|
| Preflight, static checks (123 PHP files, page scripts, tools), exact grant audit | passed |
| PHPUnit (`phpunit.phase8.xml`) | 46 tests, 2,983 assertions, 0 failures |
| Inherited Phase 5 suites | search passed; report service 27; native matching parity 205, restore verified |
| Live HTTP and concurrency | Phase 6: 77; Phase 7: 81; Phase 8: 28. Every race outcome legal. |
| Security probes | 75 |
| Browser suites | Phase 6: 17; Phase 7: 22 (adds the pickup row and section checks); Phase 8: 14 |
| Synthetic state | identical to the verified baseline before and after |
| Protection | Academic grants match the 6B evidence, 6B archive `c1f4bde6…`, 19/19 canonical files, accepted refs unchanged |

The reviewed candidate before batch 3 is kept unchanged as `migration/uiux-rc1-verified` (`73c10fe`). The deferred acceptance gates in [QA_RELEASE_READINESS.md](QA_RELEASE_READINESS.md) and the automatic-expiry decision above remain open.
