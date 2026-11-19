# ReFind Laravel: frozen release candidate (20 November 2026)

**Frozen at the owner's request after the final manual review.** The candidate is the commit that adds this file, marked with the local annotated tag **`refind-laravel-rc-2026-11-20`**.

**What the freeze is:**
- **Local only:** the repository has no remotes, and nothing is pushed, merged or deployed.
- **Not accepted:** the academic profiles are not activated, and nothing is labelled accepted. The open gates are below.
- **Change rule:** any later change needs a new candidate and a new integrated regression.

## 1. Source identity

| Item | Value |
|---|---|
| Tag | `refind-laravel-rc-2026-11-20` (annotated, local) |
| Branch | `migration/uiux-refinement` (worktree `C:\ReFind-Laravel\laravel-p8`) |
| Verified code commit | `0e0f9dc48bdf80697c50b744553dd7eab51c7684` |
| Code tree | `6fdb5262b409ef755db5da0809bc0941488783f7`, 330 tracked files |
| Commits after the code commit | Documentation only: `a894876` (regression record) and the commit adding this file. No application, test or configuration file differs from `0e0f9dc`. |
| Per-file manifest | `C:\ReFind-Laravel\evidence\uiux\freeze-20261119T222753Z\source-manifest.json`, sha256 `e7773aa66fcb327bcbfd28ff67d02929f384cca292df5cfa825b3220f1c206e7`. It records the SHA-256 of every tracked file at the code commit. |
| Native reference | `fahrezimahifa-ui/ReFind-v0.2.5` at `47eea8648d2d47faa895d855fe6da53e6b1a3087` (native main and test run the same source) |
| Framework and runtime | Laravel 13.42.0 (`composer.lock` sha256 `3ee73d0ce2643b60426f592807d85b5722d77447051f906feadb25d3048763e5`), PHPUnit 12.5.44, portable PHP 8.5.12 NTS, MariaDB 10.11.19 on 127.0.0.1:3307 |
| Intentional native asset differences ([SOURCE_PROVENANCE.json](SOURCE_PROVENANCE.json)) | `js/bm.js` `a06e4f77…7c`, `js/student.js` `cedd1a00…58`, `css/polish.css` `85c06dcd…c6`, `js/student-common.js` `55391278…98`. All other native assets are byte-identical. |

## 2. Verification of the frozen code

`python -B tools/verify-candidate.py --browser` on the clean code commit `0e0f9dc` **passed** with `accepted: false`. Evidence: `C:\ReFind-Laravel\evidence\phase8\candidate-20261119T221829Z\summary.json`.

| Step | Result |
|---|---|
| Static checks and exact grant audit | passed (123 PHP files) |
| PHPUnit | 46 tests, 2,983 assertions, 0 failures |
| Inherited Phase 5 suites | search; report service 27; native matching parity 205 |
| Live HTTP and concurrency | Phase 6: 77; Phase 7: 81; Phase 8: 28 |
| Security probes | 75 |
| Browser suites | Phase 6: 17; Phase 7: 22; Phase 8: 14 |
| Synthetic state | identical to the verified baseline before and after |
| Protection | Academic grants match the 6B evidence, 6B archive `c1f4bde6…`, 19/19 canonical files, accepted refs unchanged |

Focused UI evidence for each refinement batch is listed in [UIUX_REFINEMENT.md](UIUX_REFINEMENT.md). The acceptance matrix, audit and screenshots are kept outside the repository in `C:\ReFind-Laravel\planning\professor-uiux\`.

**Not tested:**
- real phones and tablets;
- screen readers and forced-colors mode;
- formal contrast measurement on the translucent glass panels.

## 3. Lineage, preserved references and rollback

**Lineage:** `migration/qa-final` `de7d8bd`, the QA-verified baseline, followed by these code commits:

| Commit | Change |
|---|---|
| `ea06e06` | Shared stylesheet on the BM, profile, settings and register pages |
| `4d65033` | Batch 1 |
| `c7bf08b` | Service-desk hours text |
| `b94bb60` | Batch 2 |
| `63c93b0` | Batch 3, owner-review corrections |
| `2e447c8`, `8630caa`, `3140c61` | Batch 4, hierarchy and guidance |
| `0e0f9dc` | Note boxes |

Interleaved documentation commits record decisions and results.

**Preserved, unchanged:**

| Branch or checkout | Commit |
|---|---|
| `migration/uiux-rc1-verified` (the earlier reviewed candidate) | `73c10fe` |
| `migration/qa-final` | `de7d8bd` |
| `migration/phase8-integration` | `edcd335` |
| `migration/academic-b` | `7f864aa` |
| `migration/phase6-6a` | `b70b711` |
| `migration/laravel13` | `ac03a9d` |
| Phase 6 WIP | `1ea27c5` |
| Phase 7 WIP | `b889b80` |
| Canonical 6B working tree and archive | `c1f4bde6…` |
| Native main and test | `47eea86` |

**Rollback:** check out the tag, or any preserved branch. `phase8-dev` restores from its snapshots. Grant deltas are reversible, revoked in reverse order (`tools/grant-claims.php <stage> --revoke`).

## 4. Decisions on record

**Pickup expiry: Option A** (owner, 19 November 2026):
- an overdue pickup never releases the item, revokes approval or changes ownership automatically;
- the overdue indicator stays automatic;
- Release pickup stays a manual, authorized operation with its reason, confirmation, history, notifications and safeguards;
- there is no background job or grace-period rule.

**Owner choices in the UI refinement:**
- "Pickup" is the term everywhere in the UI. Links and internal codes are unchanged.
- The private-detail terms are **private clue**, **ownership details** and **private photos**.
- The no-photo placeholder is compact on phones.
- P11 means the page is the only ordinary scroller.
- P07 means two main actions per pickup state, with Cancel appointment and Release pickup in their own labelled sections.

**Documented, not changed** (owner decisions for later):
- Login has no forgot-password flow.
- Self-registered students have no student ID.
- Status label names stay as they are.
- Server-generated text stays as it is: notification titles, the generic field error and the approval notification.
- The status chips have no legend.
- An empty password on login is reported in the banner.
- There is no Student-initiated pickup reschedule request (plan Wave C).

## 5. Remaining formal acceptance work (prepared, not started)

Nothing here has run. Each gate starts from the frozen tag, and those marked as needing approval wait for explicit owner authorization.

| # | Gate | Needs approval | Inputs and steps | Pass evidence |
|---|---|---|---|---|
| 1 | **Owner sign-offs** | Owner | (a) The three deliberate divergences, with recommendations in [QA_RELEASE_READINESS.md](QA_RELEASE_READINESS.md) §6: the stronger claim revision digest, native REJECTED → CONFIRMED pair conversion on approval, and the shared advisory lock on Student writes. (b) The Phase 8 grants: `notifications.is_read`, `users` profile, password and avatar, and `users` INSERT. (c) Whether registration is enabled for the academic release. (d) Professor UI/UX acceptance of P01–P13 against the acceptance matrix. (e) The "documented, not changed" items above. | A signed decision record added to the release docs |
| 2 | **Canonical integration** | Owner (branch name, timing) | [RELEASE_PREPARATION.md](RELEASE_PREPARATION.md) §3, starting from the tag: confirm the 6B archive and manifest, choose the integration branch, point `Workspace::PROFILES` academic profiles at the integrated checkout, re-pin `tools/test-academic-guards.py` (branch, and the four adapted assets now listed), extend `VerificationReceipt.php` and `verify-phase5.py` to the Phase 6–8 suites, and remove the two stray tracked `.pyc` files. | Integration commit and passing guard checks |
| 3 | **Independent native HTTP parity** | Owner (an approved independent native target) | Use the comparator kit (`PHASE_6_DOCKER_FREE_HANDOFF.md`, `Stage-ReFind-6BComparator.ps1`), extended to the Phase 6–8 scenarios. Never use main or test as the target. The API contract is unchanged by the UI refinement. Expected differences (UI wording, adapted assets, the corrected service-desk text) are listed in `UIUX_REFINEMENT.md`. | Comparator report with zero unexplained differences |
| 4 | **Full inherited 33-suite matrix with receipts** | Follows gate 2 | Run `verify-phase5.py`, extended, against `academic-dev` and `academic-test` on the integrated code, with fresh source-bound receipts. | Receipts for every suite at the integrated SHA |
| 5 | **Academic activation of stages and grants** | Owner, per profile | Apply `tools/grant-claims.php` deltas and stage activation separately for each profile, with exact before/after grant audits (`tools/report-privileges.php`). Keep Option A. | Grant audits matching the approved sets; protection checks |
| 6 | **Fresh-Windows install and restart qualification** | Owner (external machine) | Install the tagged SHA with the prepared staging kit; restart and re-verify. | Install and restart log; verification summary |
| 7 | **Academic evidence and rubric mapping** | Professor / owner | Map [FRAMEWORK_EVIDENCE.md](FRAMEWORK_EVIDENCE.md), the UI/UX audit, the plan, the acceptance matrix and the before/after screenshots to the rubric. | Submission evidence package |

**Suggested order:** 1 → 2 → 3 → 4 → 5 → 6 → 7. Gate 3 can run before 2 once an independent target is approved.
