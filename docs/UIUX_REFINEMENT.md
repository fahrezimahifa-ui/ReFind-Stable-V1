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

## Automatic pickup expiry: resolved as Option A (owner decision, 19 November 2026)

**Decision.** The owner approved Option A for the current ReFind academic release. This is an explicit business-policy decision:
1. A scheduled pickup becoming overdue does not automatically release the item, revoke claim approval or change ownership.
2. The overdue ("time passed") indicator continues to appear automatically, based on the scheduled date and time.
3. The authorized manual Release pickup operation stays, with its reason, confirmation, history, notifications and transactional safeguards.
4. Release pickup stays visually separate from the two primary actions, Reschedule pickup and Confirm item returned.
5. Cancel appointment stays a lower-priority action that is still discoverable and usable.
6. No automatic release, automatic cancellation, background job or grace-period rule is implemented.
7. The automatic-expiry question is resolved as Option A.

The decision covers business policy only. It does not authorize deployment or academic-profile activation. The current code already matches it: batch 3 has no scheduler or expiry rule, shows the indicator from the stored time, and places Release and Cancel appointment in their own sections.

The findings and options that informed the decision are kept below.

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

**Options considered:**
- **A (chosen):** keep release manual, as today. The proposal also mentioned an optional a BM "Time passed" filter in the Handover queue, and an in-app reminder to the claimant when an appointment passes. Neither optional addition was requested, so neither is implemented.
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

## Batch 4: app-wide visual hierarchy and guidance (P05 expansion, 19 November 2026)

The owner's review of the dark-mode phone BM claim detail found that field labels such as "Claimant" and "Claimant email" read much weaker than their values. The professor asked for an interface that explains itself. This batch audited the whole app for hierarchy, labelling, grouping and guidance.

**Owner choices for this batch:**
- "Pickup" everywhere in the UI;
- two private-detail terms app-wide: **private clue** (the finder's secret detail) and **ownership details** (a Lost owner's private detail), with evidence called **private photos**;
- a compact no-photo placeholder on phones.

**Commits:**
- `2e447c8` (4A, hierarchy CSS);
- `8630caa` (4B, BM clarity);
- `3140c61` (4C, Student and shared clarity).

**Pages examined**, at 390 px dark and 1440 px light:
- BM claim, report and pickup detail, and every pickup and claim dialog;
- the item page (own Lost report, and a Found report from a claimant's view);
- the report form (new and edit) and the claim form;
- My Activity (Active reports, Claims, Closed), Notifications, History and Explore;
- Profile, Settings, Register and Login.

**Confirmed issues and changes:**

| Severity | Issue | Change | Files |
|---|---|---|---|
| High | Field labels (`.detail-meta dt`, muted 14 px) read as decoration, and sat 8 px from their value but only 12 px from the next value | A new `--label` token (light `#33405a`, dark `#d3dcef`). Labels now sit 2 px above their value with 16 px between pairs; values are 15 px/600 ink. Label contrast rose from 9.5 to 12 in dark mode and from 8 to 10.4 in light mode, and the record title stays dominant (24/32 px). | `polish.css` |
| High | The claimant answer and the private clue (the decision content) were muted 14 px under 20 px headings | The box heading is a 14 px label and the content is 15 px ink, start-aligned in the narrow compare columns. Batch 2's justification of these boxes was withdrawn, because it spread the words unevenly. | `polish.css` |
| High | BM section headings (20 px) competed with the 24 px record title on phones | 17 px | `polish.css` |
| High | Unlabelled BM blocks, and BM labels vaguer than the Student ones | Labelled "Public description", "Review note", "Pickup note" and "Your previous request". The labels "Found at", "Last seen at", "Date found / lost" and "Linked Lost report" now match the Student pages. | `bm.js`, `polish.css` |
| High | "Handover" and "Pickup" mixed in BM | The tab and links say Pickups / Open pickup, the approval dialog says it "creates one pickup", and the notification link says "View pickup". `?tab=handover` and `data-bm-tab="handover"` are unchanged. | `bm.blade.php`, `bm.js`, `student.js` |
| High | The claim filter said "Waiting for BM / student" while the cards said "Pending review / More info requested" | The filter uses the card names (`U.claimNames`) | `bm.js` |
| High | The private detail had six names | Aligned to private clue, ownership details and private photos on the claim form, report form, My Activity and BM | `claim.blade.php`, `report.blade.php`, `student.js`, `bm.js` |
| Medium | Generic dialog intros | Request more info, status, schedule and reschedule now say what happens. Every statement was checked in the services: the claimant can update the same claim; reporters can't edit while Under review (`can_edit` covers Reported and Possible match only); the claimant is notified and sees the time and place in My Activity. | `bm.js` |
| Medium | Submit disabled until the box is ticked, with no reason | "Saving is enabled once you tick this box." linked by `aria-describedby` | `bm.blade.php` |
| Medium | "Both reports become available again" even without a linked Lost report | "the report and any linked Lost report" | `bm.js` |
| Medium | Limits enforced but not shown | Item name at least 3 characters (`ReportService`). Private clue at least 8 (`ReportService`). Private photos up to 2 per report or claim (`files(..., 2)`); JPEG, PNG or WebP files up to 10 MB each, which the browser prepares as copies of at most 250 KB (`uploads.js`, `UploadStager`). Display name up to 120, with `maxlength` (`AccountService::profileName`). The Explore date filter matches one day (`ItemReadRepository`). | `report.blade.php`, `claim.blade.php`, `register.blade.php`, `browse.blade.php` |
| Medium | Silent states on the report form | Lost reports get "After you submit" guidance, reusing the existing journey statement that ReFind notifies the owner of similar Found reports. Edit mode states that the type is fixed. Switching to Found with private photos chosen states they were removed (the server rule: "Found reports use a private clue, not Lost ownership photos"). | `report.blade.php`, `student.js` |
| Medium | Journey notes pointed to claim buttons that read-only claims mode hides | The notes are conditional on that mode | `student.js` |
| Medium | Pickup notifications read "ownership stays approved by {name}", and an optional schedule note was labelled "Reason:" | "Appointment cancelled by {name}. Ownership stays approved." An optional note is labelled "Note:" (client-rendered; the server text is unchanged). | `student-common.js` |
| Low | "Queue is clear" shown for a search with no results; vague Closed-tab and "Verified report pair." wording; the "Asia/Jakarta" field label | "No matching records" for searches; "Reports that were returned or never retrieved appear here."; "Linked to a verified matching report."; "WIB (campus time)" | `bm.js`, `student.js`, `bm.blade.php` |
| Low | The journey "Now" marker and journey note were rounded like controls | Square status shape | `polish.css` |
| Low | Full-height empty no-photo frame on phones | A compact labelled strip below 680 px; desktop grids are unchanged | `polish.css` |

**Already compliant (unchanged):**
- Page titles outrank intros, and batch 1 had already fixed the notification hierarchy.
- Form labels and hints share the `.field label` and `.hint` styles.
- Muted text already passed AA contrast: about 7.9:1 in light and 9:1 in dark mode.
- Every action shows a success message.
- Navigation `aria-current`, focus rings and `aria-pressed` tabs are in place.
- The single page scroll, pickup sections and full-width phone segments from batch 3 are preserved.

**Documented, not changed** (product or server decisions):
- Login has no forgot-password flow.
- Self-registered students have no student ID, so BM sees "Not specified".
- Status labels are kept by rule: "Under review", "Claim review", "Pending review".
- Server-generated text is unchanged: notification titles, the generic "Check the {key} field." and the approval notification.
- The status chips have no legend.
- An empty password on login is reported in the banner, not under the field.

**Tests updated:**
- `tools/test-phase7-ui.py`: the release feedback text.
- `tools/test-phase6-ui.py`: "1 private photo".
- `tools/test-academic-guards.py`: `js/student-common.js` added to the adapted-asset set.

**Provenance:** `docs/SOURCE_PROVENANCE.json` has new hashes and reasons for `bm.js`, `student.js` and `polish.css`, and records `student-common.js` as a new adapted asset.

**Focused checks.** Each ran on `phase8-dev` and restored exactly. Evidence is in `C:\ReFind-Laravel\planning\professor-uiux\after\batch4\{before,tune,after}`; the runs are `batch4-before-20261119T152554Z` and `batch4-after-20261119T153924Z`.
- **Before and after capture:** the same fixtures, on 18 pages at 390 px dark and 1440 px light, plus every BM dialog, opened and closed only. The fixtures were a Found report with a photo, a Lost report with ownership photos and a linked pending claim, an overdue pickup, a returned item, and an editable Lost report without a photo. The overdue time was moved into the past in the disposable schema, because the API accepts future times only.
- **Accessibility:** no form control without an accessible name on any captured page, and the new hints are linked by `aria-describedby`. No inner scroll areas, sideways overflow or JavaScript errors.
- **Regression guards:** 21 checks at 360, 768 and 1024 px on BM claim and pickup, Explore, My Activity, Notifications, History and the report form. No inner scroll areas, no sideways overflow, and equal full-row segments on phones.
- **Browser suites after 4B and after 4C:** Phase 6 / 7 / 8 at 17 / 22 / 14 both times.

**Not tested:**
- real devices, screen readers and forced-colors mode;
- formal contrast measurement on translucent glass panels (the script approximates the effective background).

## Batch 4 integrated regression (19 November 2026)

`python -B tools/verify-candidate.py --browser` ran on the clean committed candidate **`b0bba20`** (`dirty_worktree: false`), which contains batch 4 code `3140c61` plus its record. It **passed** with `accepted: false`. Evidence: `C:\ReFind-Laravel\evidence\phase8\candidate-20261119T154356Z\summary.json`. It supersedes the `63c93b0` run above.

| Step | Result |
|---|---|
| Preflight, static checks (123 PHP files, page scripts, tools), exact grant audit | passed |
| PHPUnit (`phpunit.phase8.xml`) | 46 tests, 2,983 assertions, 0 failures |
| Inherited Phase 5 suites | search passed; report service 27; native matching parity 205, restore verified |
| Live HTTP and concurrency | Phase 6: 77; Phase 7: 81; Phase 8: 28 |
| Security probes | 75 |
| Browser suites | Phase 6: 17; Phase 7: 22; Phase 8: 14 |
| Synthetic state | identical to the verified baseline before and after |
| Protection | Academic grants match the 6B evidence, 6B archive `c1f4bde6…`, 19/19 canonical files, accepted refs unchanged |

**Still preserved:** `migration/uiux-rc1-verified` (`73c10fe`) and `qa-final` (`de7d8bd`). The pickup-expiry policy remains Option A. The deferred acceptance gates in [QA_RELEASE_READINESS.md](QA_RELEASE_READINESS.md) stay open. The candidate is not frozen, tagged, pushed or merged.

## Owner review fix: note boxes span their card (20 November 2026)

**What was reported:** in the final manual review, the BM note "This linked or saved record is not in the current list…" left a gap on its right, narrower than its card.

**Cause:** the native rule `p{max-width:64ch}` (`auth.css`) keeps plain paragraphs to a readable line length. It also capped bordered note boxes that are built as `<p>` elements.

**Fix:** `p.context-note`, `p.journey-note` and `p.report-visibility` now span their container (`polish.css`). Plain paragraphs keep the 64ch measure. The same cause and fix also apply to the item page's journey note and the report form's "Who can see this report?" box.

**Verified** on the review demo at 1440 px light and 390 px dark: each box spans its container, measured and seen in screenshots in `planning\professor-uiux\after\batch4\note-fix\`.

## Final integrated regression after the owner review fix (20 November 2026)

`python -B tools/verify-candidate.py --browser` ran on the clean committed candidate **`0e0f9dc`** (`dirty_worktree: false`), which contains the note-box fix. It **passed** with `accepted: false`. Evidence: `C:\ReFind-Laravel\evidence\phase8\candidate-20261119T221829Z\summary.json`. It supersedes the `b0bba20` run above.

Two earlier attempts on the same commit hit the 2 GiB memory gates; neither gate was lowered:
- `candidate-20261119T221329Z` passed every stage through the security probes, then the first browser suite stopped at its memory floor.
- The run before it couldn't start because of the preflight gate.

The interrupted attempt restored its snapshot. The completed run's starting state matched the verified baseline, which confirms that restore was exact.

| Step | Result |
|---|---|
| Preflight, static checks (123 PHP files, page scripts, tools), exact grant audit | passed |
| PHPUnit (`phpunit.phase8.xml`) | 46 tests, 2,983 assertions, 0 failures |
| Inherited Phase 5 suites | search passed; report service 27; native matching parity 205, restore verified |
| Live HTTP and concurrency | Phase 6: 77; Phase 7: 81; Phase 8: 28 |
| Security probes | 75 |
| Browser suites | Phase 6: 17; Phase 7: 22; Phase 8: 14 |
| Synthetic state | identical to the verified baseline before and after |
| Protection | Academic grants match the 6B evidence, 6B archive `c1f4bde6…`, 19/19 canonical files, accepted refs unchanged |

**Still preserved:** `migration/uiux-rc1-verified` (`73c10fe`) and `qa-final` (`de7d8bd`). The pickup-expiry policy remains Option A. The deferred acceptance gates in [QA_RELEASE_READINESS.md](QA_RELEASE_READINESS.md) stay open. The candidate is not frozen, tagged, pushed or merged.

## Stable round 1: return navigation and form safety (21 November 2026)

**Context:** after the local Stable 1.0.0 (`refind-laravel-stable-1.0.0`), the owner reported that opening a card from Explore page 2 and pressing **Back to Explore** returned to page 1 at the top. A code scan of the same class of problem found seven confirmed inconveniences. Work is on `develop-round1` off `develop`. It is frontend only: no PHP, schema, grant or business-rule change.

| # | Inconvenience | Fix |
|---|---|---|
| N1 | Back from an item lost the list's page, filters and scroll | List pages remember, in this tab only, their URL, scroll position and opened record. The item page's Back link, the browser Back button and a reload return to the same place and focus the opened card. |
| N2 | The item page always said "Back to Explore" | It names and targets the list you came from: Explore, History, My Activity, Home or the BM Review Queue. A fresh tab still falls back to Explore; the Notifications origin is unchanged. |
| N3 | My Activity forgot its page | `page` is kept in its URL. |
| N4 | The BM queue forgot search, filter and page after a reload or a public-report visit | `q`, `status` (when not the default) and `page` are kept in its URL. A remembered page past the end falls back to the last page. |
| N5 | Back after submitting returned to the spent form | Successful report and claim submits, withdraw and delete replace the history entry. |
| N6 | No warning when leaving a filled form | A changed report or claim form, or an open, changed BM decision dialog, asks before leaving. An untouched form doesn't, and neither does a successful submit. |
| N7 | An ended session (30-minute idle) discarded the typed form | The typed text (never files or passwords) is kept in this tab, restored once after signing in, and the student is told to add any photos again. This covers the report form, the claim form and the BM decision note. |

**Files:** `public/js/student-common.js` (shared helpers), `public/js/student.js`, `public/js/bm.js`. Provenance hashes and reasons are updated in `docs/SOURCE_PROVENANCE.json`.

**Tests:**
- New suite: `tools/test-stable-navigation-ui.py` on `phase8-dev`, inside a snapshot envelope, with 30 synthetic reports created through the API for multi-page lists. **56/56 checks passed**, and the restore was identical.
- It covers:
  - Explore page 2 with the Found filter, scrolled to the bottom, at 1440 px light and 390 px dark: the Back link and browser Back return to the exact URL, with scroll within 40 px and the opened card focused;
  - the Back labels and targets from Home, History and My Activity (page 2);
  - BM search, filter and page across reloads, and the return from "Public detail" to the same queue and record;
  - the fresh-tab fallback;
  - Back after submit;
  - the unsaved-changes prompt (stay keeps the text; an untouched form leaves silently);
  - drafts restored after a forced session end, for the report form and the BM note.

## Stable page entrance candidate (22 November 2026)

Port the 200 ms fade and 4 px lift from ReFind-Test commit 699aa2b to Stable 1.1.0. The early theme script hands eligible navigation intent to the destination. The motion follows the device preference and applies to existing signed-in surfaces, including Home's panel when auth reveals it and the Item Detail loading line. Report and claim confirmations keep their existing presentation and replace the completed form in history.

## Stable round 3: polish (22 November 2026)

**Context:** the owner asked for a polish-only round on the Stable demo: clearer and calmer, with no new features, sections or extra
motion, and nothing that weighs the pages down (round 2 was rejected for bloating Home and slowing the app). Scope: Explore, item
page, Report and Claim forms, My Activity and the BM Review Queue; Home's layout, Notifications, History, Login and account pages
were out of scope, although shared styles (labels, chips, headings) also reach them. Codex's 1.1.1 page entrance is kept exactly.
Branch `polish/stable-1.2` off `develop` (`2f5a7b4`, 1.1.1 merged with the fixture tools). Built in three batches, each reviewed
by the owner from before/after screenshots on `phase8-dev`; the owner's review notes are folded in.

### What changed

| Area | Before | Now |
|---|---|---|
| Labels and values (owner note) | Labels 14 px/600 in a lighter colour; values 15 px/600 in full ink, so "Not specified" read before "Student ID" | Labels lead (ink, 700, 15 px); values follow (regular, softer ink); missing values are muted (`--value`, `--empty`). Applies to detail lists, BM fields and evidence boxes, form labels, the desk card, "Your pickup" and "Your claim", and profile read-only values. **Reverses batch 4's "values keep the emphasis" at the owner's request.** |
| Status chips | Two equal chips (Lost/Found + status) | One coloured status chip, still spanning the card (batch 2); the card's date line starts with a bold "Found"/"Lost"; the item page shows the chip plus "Found item"/"Lost item". Labels and codes unchanged. |
| Card lines | "Category · area · spot", then "Found on date · time WIB" | Where, then "Found 20 Nov 2026, 10:15 WIB"; the date line sits at the bottom of every card, so dates align across a row. |
| No public photo | A full-size grey box "No public photo provided" | A calm icon and "No photo" in the same frame on card grids and the two-column item page (rows stay aligned, owner review); a short strip on phones and the narrow item page. |
| Layout jumps | Later loads showed a loading line above the cards; My Activity emptied its grid first; photos popped in | Current cards stay, dimmed and `aria-busy`, until new ones arrive; the loading text is for screen readers only; skeletons match card height; count lines keep their height; photos fade in (150 ms, only with motion allowed). First-load CLS was already near zero and stays so. |
| Page headings | Slogan titles ("Find your way back to it."), large type, BM eyebrow | Plain title and one sentence, 26-32 px; title and button share the row on desktop; on phones the button sits under the sentence at full row width (owner review). Explore is "Explore"; the BM eyebrow is gone. The report heading renders on the server from the URL, so it no longer swaps after load. |
| Report form | Every field full width (~1,060 px) | Full width with short fields paired: item name and category; Where & when in four columns on wide screens (owner review; a narrow column was tried and rejected for the empty space it left). |
| Action pill | Translucent glass over content | Same floating pill on a near-solid surface; it clears all content at the end of every form and BM record (checked). It still floats over content while scrolling long pages. |
| BM records (owner note) | One loose list of fields, a single public photo top-right, private photos outside their box | Purposeful groups: claim = Claimant, Item (details, every public photo, public description), Ownership evidence (claimant's photos inside "Claimant answer", opposite the finder's clue); pickup = Verified claimant, Pickup; report = public report, then its private section with photos inside the clue box. Groups are split by a thin line that fades before the panel edges; two detail columns get a short floating vertical line; the item name is a centred 28 px header with a line under it. |
| Item page details | Description and details run together | The same floating line under the description and the column line between the two detail columns. |
| My Activity claims | Uneven cards, "0 private photos…", long desk block, "Update / resubmit claim" | Title links to the item; cards in a row share a height with actions along the bottom; a zero photo count is hidden; the desk details sit behind "Questions about your pickup? Contact Building Management"; "Update claim". |
| Next step after approval | "Building Management will arrange a pickup time and place with you…" | "Approved. Building Management will set a pickup time and notify you." The BM approval note is pre-filled with the same next step (server text unchanged). |
| Report journey | Five labels | One short muted line under each step (for example "Building Management checks a claim"). |

Files: `public/css/polish.css`, `public/js/student-common.js`, `public/js/student.js`, `public/js/bm.js` (adapted; hashes and
reasons in `docs/SOURCE_PROVENANCE.json`), and the views `browse`, `report`, `item` and `bm.blade.php`. The pinned native styles and
scripts are untouched. Tests changed on purpose: `test-stable-navigation-ui.py` (page 2 scroll threshold 300 → 150 px, because
photo-less seeded cards are shorter) and `test-phase6-ui.py` ("Update claim" and the approval sentence).

### Round 3 verification (phase8-dev, 22 November 2026)

| Check | Result |
|---|---|
| `test-stable-navigation-ui.py` | 56/56 after every batch and review fix |
| `test-phase6-ui.py` / `test-phase7-ui.py` / `test-phase8-ui.py` | 17/17, 22/22, 14/14 (each in its own envelope) |
| `test-phase8-http.py` | 28/28 |
| PHPUnit `phpunit.phase8.xml` | 46 tests, 1 failure: `PickupContractTest::test_pickup_reads_privacy_and_gates` expects no completed pickups, but the round 2 fixtures on phase8-dev include one. The same failure occurs on the untouched `develop` base, so it is data-dependent and not caused by this round. |
| Pill overlap at the end of forms and BM records | Clear at 1440 px and 390 px, light and dark |
| Layout shift (CLS, first load) | Explore 0.0003, My Activity about 0.005, item 0.0003, BM queue 0.023: unchanged from before the round |
| Envelope restore | Identical after every run |

**Environmental, not app:** one phase 7 run after four back-to-back suites hit a JavaScript error from a failed script load
(Windows loopback socket exhaustion, see the handoff); run alone it passed 22/22. The private log total passed the 20 MiB start gate
during the round; the phase8-dev web log was reviewed (no 5xx, no PHP errors) and archived as a verified `.gz` beside itself.

**Not run or not verifiable here:** `test-claim-read-ui.py` (targets academic-test on 8014, off limits); real phones, Safari and
Firefox. Evidence: `C:\ReFind-Laravel\evidence\stable\round3-20261122\` (before/after screenshots, comparisons, suite logs).

## Stable round 4: menu, 3D cards, WhatsApp profile, claim pages (23 November 2026)

**Context:** the owner sent four notes with screenshots. This round was run with the `design-review-loop` skill: question
rounds, a before set, then four batches, each reviewed from before/after images (and, for the 3D cards, tried live on
`phase8-dev`).

The notes:
1. the phone menu pushes the page down;
2. cards should float like 3D cards on hover;
3. the profile picture card is crowded, and should be like WhatsApp's photo editing;
4. claims should be regular cards with a button to a full claim page.

Unlike round 3 it adds features at the owner's request. Round 2's lesson still holds: no bloat, nothing slower. Branch
`polish/stable-1.3` off `develop` (`576fe28`).

### What changed

| Area | Before | Now |
|---|---|---|
| Phone menu (A) | Below 1000 px the Menu panel was part of the sticky header: opening it pushed every signed-in page down by 263–363 px (measured on 18 page types at 390 and 768 px) | A solid floating panel under the header (full width on phones, 360 px on the right on tablets). No page moves (0 of 36 page-views). `shell.js` (pinned) still toggles and closes it. CSS only. |
| 3D cards (B) | Flat cards with a border change on hover | **Which cards:** report cards (Explore, Home, History), My Activity cards, BM queue cards and notification rows.<br>**On hover:** they lift ~6 px (queue cards 3 px), tilt up to 6° toward the mouse and catch a soft light. Wide notification rows tilt less and take the card surface.<br>**Scope:** mouse only. Reduced motion keeps the shadow; keyboard focus lifts without the tilt.<br>**How:** a frame-throttled handler sets CSS variables, and `transform` draws it, so layout never moves. Tried live by the owner. |
| Profile (C) | Two cards; the left one crowded (small photo, two buttons, hint, name, chip, text, settings button); the right one a display-name form | **Layout:** one WhatsApp-style column.<br>**Photo:** a 160 px round photo with a camera "Edit" overlay (a badge on touch), opening View photo / Take photo (phones) / Upload photo / Remove photo.<br>**Name and rows:** the name with a pencil for inline editing (Enter saves, Escape cancels); the role as the subtitle; read-only rows with lock icons (an empty student ID reads "Not specified"); the privacy line; an Account settings row.<br>**Crop dialog:** a dark "Drag the image to adjust" stage with Upload, the square crop dimmed outside the circle, +/− zoom (and the wheel), a round ✓, and the position sliders under "Position controls". |
| Claim cards (D) | Text cards: status, submitted date, boxed pickup and past-due notes, a desk disclosure, the review note, a photo line, then View item / Update / Withdraw | **The card:** a regular card with the item's photo, the claim status chip, the title, where it was found, then one status line and a full-width View details pinned together at the bottom.<br>**Example status lines:** "Pickup 22 Nov 2026, 16:00 WIB · Lapangan", "Pickup time passed · contact Building Management", "Answer Building Management's question". |
| Claim page (D) | None: everything lived on the card | `my-reports.html?claim=<id>` (no tab), with its shell rendered on the server.<br>**Panels:** progress steps (Submitted → Under review → Approved → Ready for pickup → Returned, with More info / Rejected / Withdrawn variants); the item card (photo, title, where, when, card-wide View item); your answer and private photo count; Building Management's note; the pickup with the desk card; Update claim / Claim again and Withdraw.<br>**Layout:** the item sits in a sticky side column on desktop and comes first on phones.<br>**Navigation:** submitted claims, claim notifications and the item page's Follow my claim open it. Back returns to the exact list place; an item opened from a claim says "Back to claim". |

**Owner review notes, applied:**
- the claim card's status line and button pinned to the bottom;
- the item card's button card-wide with centred text;
- on phones, the item card above the steps.

**Found in my own review, before showing the owner:**
- the menu at the pill's 97 % let headings show through in dark mode, so the panel is now solid;
- wide notification rows touched the list border, so their tilt is gentler;
- an empty student ID showed a blank row, and the Position controls disclosure had no visible arrow;
- the card-wide title link covered View details;
- the desktop claim page put the status last;
- a class clash removed the claim page's phone gutters;
- the status line repeated the current step's text.

**Files:**
- **CSS and adapted JS:** `public/css/polish.css`, `public/js/student-common.js` (tilt, `claimDetailUrl`, `trackReturn`
  options) and `public/js/student.js` (claim cards and page, links), with hashes and reasons in
  `docs/SOURCE_PROVENANCE.json`.
- **New:** `public/js/profile.js`, Laravel-only and recorded under `added_assets`, which `tools/ci-static-checks.py` now
  verifies.
- **Views:** `profile.blade.php` and `my-reports.blade.php`.
- **Unchanged:** the pinned native `shell.js`, `account-ui.js` and `avatar-editor.js` (CI checks this). No server, route,
  schema or API change.

**Tests changed on purpose:**
- `test-phase8-ui.py`: the pencil before editing the name; photo upload → zoom → save → remove.
- `test-phase6-ui.py` and `test-phase7-ui.py`: claim details are asserted on the claim page; card → claim page.
- Outside git: `stable-tools/stable_nav_readonly.py` (claims: card → claim page → item → "Back to claim"); the original is
  kept as `.bak-20261123`.

### Round 4 verification (phase8-dev, 23 November 2026)

| Check | Result |
|---|---|
| `test-stable-navigation-ui.py` | 56/56 after every batch |
| `test-phase6-ui.py` / `test-phase7-ui.py` | 17/17; 23/23 (one new check) |
| `test-phase8-ui.py` / `test-phase8-http.py` | 18/18 (four new photo/name checks); 28/28 |
| Menu shift (A) | 0 of 36 page-views move when the menu opens (before: 36 of 36, 263–363 px) |
| Claim card alignment (D) | View details and card bottoms share one edge in every row (measured) |
| Layout shift (CLS, first load) | Unchanged on every captured page (Explore 0.0003, claims tab ≈ 0.005, claim pages 0.0003, BM queue 0.023) |
| Captures | Read-only guard: no write attempted in any capture; restore identical after every run |

**Environmental, not app:**
- The machine restarted at 12:48. That stopped the owned database (restarted with the dev control) and the 8030 demo
  (left off).
- The round 3 full regression had deleted the extra test-claimant credentials. Every suite run now seeds them first,
  like `verify-candidate.py`, and removes the file afterwards.
- Several runs hit the 2 GiB free-memory gates. Those were rerun after the owner freed memory; no gate was lowered.

**Not verified here:** real phones (Take photo, touch hover), Safari and Firefox. `test-claim-read-ui.py` targets
academic-test and was not run. Evidence: `C:\ReFind-Laravel\evidence\stable\round4-20261123\`.

## Stable round 5: photo search (25–27 November 2026)

**Context:** the owner remembered native ReFind's experimental AI visual search and asked for a Google Lens /
Pinterest-style photo search for Students and Building Management, built on Test (8020) first, using the
`design-review-loop` skill. Unlike rounds 3 and 4 this round adds a server feature: an engine, two endpoints, a
background indexer and a private configuration. Branch `feature/photo-search` off `develop` (`8ab5f6a`). The engine,
its evaluation and its operation are documented in [VISUAL_SEARCH.md](VISUAL_SEARCH.md).

**Batches:**
- **A, engine and numbers (no UI):** InferHub had no embedding models and its describe-then-compare strategies failed
  every gate, so the owner chose Gemini `gemini-embedding-2`. The first calibration failed (unrelated 18.8 %, p95
  6.5 s); an honest refit on a fresh held-out set plus a connection retry passed (top 5 88.6 %, unrelated 0 %, typical
  1.48 s, p95 2.72 s). Calibration frozen in `resources/visual/`.
- **B, Explore (B1–B5):** camera icon in the search field, crop dialog, chip, ranked results with labels, honest no
  match, the report-form hint. Five review passes on the owner's notes.
- **C, BM Reports tab:** the same component on the Reports tab, Lost reports first with an in-chip switch, matched-photo
  thumbnails and labels on queue cards.

### What changed

| Area | Before | Now |
|---|---|---|
| Explore search (B) | Keyword field only; native's hidden visual-search markup, refused by the server | **Camera icon** at the field's right end (Students). **Crop dialog** with the box preset to the whole photo, corner handles and keyboard support, "Use whole photo" / "Search", and the AI notice line. **Chip** with up to 3 thumbnails, "+ Add" and "× Clear"; typed words are set aside and come back on Clear. **Results** in the normal grid, ranked, "Very similar" / "Similar" and "Your report" tags, never scores. **No match:** "No close photo matches" with a tip, the normal list below. |
| Explore filters (B3–B5) | "Event date" with a hint line; "Clear filters" in the results line on every screen | "Date lost or found", no hint. On phones "Clear filters" sits at the bottom of the Filters dropdown, full width, spaced from the date field, and clears only the dropdown filters; desktop keeps the results-line link. With photo results, type/category/location/date narrow them locally and Status re-runs the search. |
| Phone chip (B2) | — | Thumbnails on top, "+ Add" and "× Clear" as two halves underneath, no chip text; a thinner focus ring. The results line drops "Building Management verifies ownership" on phones so "Clear filters" never wraps. |
| Report form (B) | A hidden Gemini opt-in checkbox (refused by the server) | One hint under public photos: "Public photos can also be found with photo search." Every public item photo is indexed automatically. |
| BM Reports tab (C) | Keyword field only | Camera icon on the Reports tab only; Lost reports first, "Lost reports \| All reports" inside the chip; queue cards show the matched photo's thumbnail and label; desktop opens the best match, phones don't; the pager is hidden and switching tabs ends photo mode. |
| Server (A) | `visual-search.php` refused; no BM route | `POST visual-search.php` (Student) and `POST staff-visual-search.php` (BM), gated by the `visual` stage (`phase8-dev` only), a private `visual.json`, the owner's key and a frozen calibration. |

**Files:**
- **New:** `app/Visual/*`, `app/Http/Controllers/VisualSearchController.php`, `resources/visual/calibration-*.json`,
  `public/js/photo-search.js` (Laravel-only, under `added_assets`), `tools/visual-index.php`,
  `tools/visual-eval-prep.py`, `tools/visual-evaluate.php`, `tools/test-visual-http.py`, `tools/test-visual-ui.py`,
  `tests/Unit/VisualSearchTest.php`, `tests/Feature/VisualSearchContractTest.php`.
- **Adapted, with hashes and reasons in `docs/SOURCE_PROVENANCE.json`:** `public/js/student.js`, `student-common.js`,
  `bm.js`, `public/css/polish.css`. The provenance file's `excluded_feature` is now `null`, and a `laravel_divergences`
  entry records how this differs from native (engine path, 3 photos, BM access, automatic index, crop).
- **Changed:** `ItemReadRepository`, `StaffReportRepository`, `AuthController` (session flags), `ClaimStages`,
  `ApiMethodBoundary`, `routes/web.php`, `browse.blade.php`, `report.blade.php`, `bm.blade.php`,
  `tools/workspace/phase6-control.py` (indexer on `start --launcher`), `tools/verify-candidate.py`. The old
  `DiscoveryController::visual()` stub is removed.
- **No schema, grant or academic-profile change.** The pinned native assets are unchanged (CI checks this).
- **Outside Git:** the `design-review-loop` skill's `capture.py` gained a `files` step (attach files to an input) for
  these captures; documented in its `SKILL.md`.

**Owner review notes, applied:** B2 (chip buttons under the thumbnails, no chip text on phones, thinner focus ring,
shorter results line), B3 ("Clear filters" into the phone dropdown), B4 ("Date lost or found", desktop unchanged), B5
(no hint text; more space above "Clear filters").

**Found in my own review, before showing the owner:**
- the crop box's outline and handles were clipped by the stage, so they now sit inside it;
- the fake and Gemini engines shared one index folder, so each engine now has its own;
- the suites relied on photos from the fixture data; they now create their own photo report, so they pass on the clean
  pre-fixture snapshot too;
- the UI suite clicked the BM switch while a search was still running; it now waits.

### Round 5 verification (phase8-dev, 27 November 2026)

| Check | Result |
|---|---|
| `verify-candidate.py --browser` on the clean pre-fixture snapshot | passed (`evidence\phase8\candidate-20261127T055232Z`): static (143 PHP files), grant audit, PHPUnit, inherited search/report/matching parity, HTTP 77 + 81 + 28 + security 75 + photo 29, browser 17 + 23 + 18 + photo 44, restore identical, protection |
| Live Gemini check on 8020 | 10/10 (`evidence\visual\live-check-8020-20261127.txt`) |
| Layout shift (CLS) | Explore with photo results 0.0003; BM Reports 0.0242 (unchanged); BM after a photo search 0.045 (match) and 0.068 (no match), under 0.1 |
| Captures | Read-only guard: only the photo-search POST (which writes nothing) allowed; no other write attempted; restore identical |

**Bookkeeping:** the regression recorded `58f7b8d` with the suites' then-uncommitted edits; those exact edits were
committed as `b5d2866` during the run.

**Not verified here:** real phones (camera capture, touch), Safari and Firefox. Evidence:
`C:\ReFind-Laravel\evidence\visual\round5-photo-search-20261127\` and the `batch-a-*` evaluation folders beside it.
