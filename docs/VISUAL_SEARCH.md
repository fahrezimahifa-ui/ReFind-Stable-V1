# Photo search (Stable round 5)

**Status:** built and verified on Test (`phase8-dev`, port 8020) on 27 November 2026, branch `feature/photo-search`.
Not on Stable (8030) yet: it ships only with an explicit promotion (Stable 1.4.0). Academic profiles keep it off.

**What it does:** a Student on Explore, or Building Management on the Review Queue **Reports** tab, attaches one to
three photos instead of typing. ReFind ranks public report photos by visual similarity and labels the closest ones
"Very similar" or "Similar". It suggests candidates only: a label is not ownership, and Building Management still
decides every claim.

## 1. Owner decisions (25–27 November 2026)

| Topic | Decision |
|---|---|
| Engine | InferHub first (the owner's key); replaced by Google Gemini `gemini-embedding-2` after InferHub proved to have no embedding models and its vision models failed every gate (section 4) |
| Photos per search | Up to 3; each report ranks by its best match to any of them |
| Crop | A crop step before searching, with the box preset to the whole photo ("Use whole photo" / "Search") |
| Who | Students on Explore (camera icon inside the search field). BM on the Reports tab only, Lost reports first, with a "Lost reports \| All reports" switch in the chip |
| Results | Ranked in the normal grid, "Very similar" / "Similar" labels, never scores; "Your report" tag on the Student's own reports |
| No match | Honest "No close photo matches" with a tip (crop closer, another angle), the normal list below |
| Typed words | Set aside while searching by photo, restored by × Clear |
| Filters | Type, category, location and date narrow the returned matches locally (no new call); Status re-runs the search |
| Index | Every public item photo, automatically, by a background indexer (never ownership photos, claim evidence or avatars) |
| Notice | One line: "Your photo is sent to an AI service to compare it and isn't saved." |
| Spend | 500 provider calls a day per installation, plus per-minute limits |
| Gates (relaxed by the owner) | Typical search ≤ 3 s, slow (p95) ≤ 6 s, the same object in the top 5 ≥ 80 %, unrelated photos returned ≤ 10 % |
| Free Gemini tier | Acceptable: the target is a hosted demo (for example on Vercel) with synthetic data only, never real data |
| Rollout | Engine and numbers first, then the Explore and BM screens in reviewed batches, then Stable only on a go-ahead |

## 2. How it works

```
browser: pick 1–3 photos → crop → shrink (≤1200 px, JPEG ≤250 KB) → POST
server:  gate → validate → re-encode (white JPEG q85) → budget → Gemini embeds the query photos (one batch call)
         → cosine against the file index of public photos visible under the same filters
         → best score per report → cutoffs → up to 24 reports, labels only → normal card JSON
indexer: every 30 s, embeds public ITEM photos not yet indexed (10 per pass) and drops entries whose photo is gone
```

- **Same visibility as Explore.** Candidates come from `v_items_overview` with the Explore filters
  (`ItemReadRepository::structuredFilters`). Closed reports (Returned, Unclaimed closed) are left out unless a status
  filter asks for them, as keyword search does. The BM endpoint filters by type and queue state instead.
- **Scoring** (`app/Visual/Scorer.php`): each report scores the best cosine over every query photo × report photo pair;
  ties go to the lower photo id, then item id. A report is returned at or above the calibrated `cutoff`; it is
  "Very similar" at or above `strong_cutoff`, otherwise "Similar". The matched photo moves to the front of the card.
- **Nothing is stored from a search.** Query photos are read from PHP's temporary upload, prepared in memory and
  discarded. No score, clue or vector reaches the browser (the HTTP suite checks the raw response).
- **One request at a time:** the PHP built-in server serves one request at a time, so a search (typically 1.5–3 s)
  briefly holds the whole site. The provider call has a hard deadline (`timeout_seconds`, default 8).

## 3. Code map

| Part | File |
|---|---|
| Gate, private config, key reading | `app/Visual/VisualConfig.php` (stage `visual` in `app/Support/ClaimStages.php`: `phase8-dev` only until promotion) |
| Search | `app/Visual/VisualSearch.php` |
| Engines | `app/Visual/Provider.php`, `GeminiProvider.php`, `FakeProvider.php` (offline, tests only), `InferHubProvider.php` + `Describer.php` (kept for the record) |
| Errors | `app/Visual/ProviderError.php` (429 → quota, 401/403 → project, 408/504 → timeout, 400/413/415/422 → rejected, else unavailable) |
| Preprocessing | `app/Visual/Preprocess.php` (`jpeg-white-v1`: JPEG/PNG/WebP ≤ 250 KB and ≤ 1200 px, re-encoded on white) |
| Scoring, vectors | `app/Visual/Scorer.php`, `app/Visual/Vector.php` |
| Index | `app/Visual/IndexStore.php` |
| Budget | `app/Visual/Budget.php` |
| Calibration | `app/Visual/Calibration.php`, frozen files in `resources/visual/` |
| Endpoints | `app/Http/Controllers/VisualSearchController.php`, routes in `routes/web.php`, POST-only in `ApiMethodBoundary` |
| Cards | `ItemReadRepository::rankedCards()` (Explore), `StaffReportRepository::projectRanked()` (BM) |
| Session flags | `AuthController`: `visual_search_enabled` (Student), `visual_staff_enabled` (BM, needs the `status` stage) |
| Browser | `public/js/photo-search.js` (new, `added_assets`), adapted `student.js`, `student-common.js`, `bm.js`, `polish.css` |
| Indexer | `tools/visual-index.php`, started by `tools/workspace/phase6-control.py start --launcher` |
| Evaluation | `tools/visual-eval-prep.py`, `tools/visual-evaluate.php` |

## 4. Engine choice and evaluation

**Test photos** (private, not in Git): 118 licensed Wikimedia Commons photos re-downloaded from native's pinned list
(`C:\ReFind-Laravel\fixtures\visual-commons`), the round 2 photo reports on Test, and a fresh "batch B" of 46 licensed
photos taken only for held-out scoring (`fixtures\round2-batchB`, with `credits.html`).

**Native for comparison** (`refind-dev` QA, 11 Nov): the same Gemini model found the right item first 4 of 4 times,
but returned matches for 4 of 10 unrelated photos, and p95 latency was 15–17 s.

| Run (`C:\ReFind-Laravel\evidence\visual\`) | Top 5 | Unrelated returned | Typical / p95 | Verdict |
|---|---|---|---|---|
| InferHub, Claude Haiku 5.5 describes, text match | n/a | n/a | 3.98 s / 7.85 s | fails |
| InferHub, Qwen3 omni flash describes, text match | 76.9 % | 11.8 % | 4.46 s / 11.83 s | fails |
| Gemini, first calibration (`…015851Z-gemini-embedding-2`) | 100 % | 18.8 % | 1.72 s / 6.46 s | fails |
| **Gemini, refit (`…022029Z-gemini-refit-batchb`)** | **88.6 %** | **0 %** | **1.48 s / 2.72 s** | **passes** |

- InferHub listed no embedding models (alias names returned 404), so only "describe the photo, then compare the
  words" was possible, and its vision calls took 4–8 s with timeouts.
- **The refit, honestly:** the first Gemini run held out the round 2 photos and failed on them. The refit moved round 2
  into calibration and scored a brand-new held-out set (batch B plus 10 unrelated photos) that no fitting had seen. The
  connection retry (one retry on a connect stall, within the deadline) fixed the slow tail.
- **Cutoffs** (validation only: Commons validation + round 2): `strong_cutoff` sits halfway between the highest
  unrelated score and the lowest same-object score; `cutoff` follows native's rule. Frozen in
  `resources/visual/calibration-706881148a1de9a9.json` (cutoff 0.808126, strong 0.961587) with the model key
  `gemini:gemini-embedding-2:768:jpeg-white-v1`. A different model, size or preprocessing has a different key and
  needs its own calibration; until then searches answer 503 `visual_uncalibrated`.
- **Held-out detail:** 3 photos per search p50 1.78 s; no provider errors. Look-alikes (another earbud case, another
  grey charger) can still come back as "Similar", never "Very similar", which is why the label is not ownership.

## 5. Private configuration and key

`state/<profile>/visual.json` (ignored by Git; invalid or missing means photo search stays off):

| Field | Meaning (default) |
|---|---|
| `provider` | `gemini` (also `inferhub`, `fake`) |
| `embedding_model`, `dimensions` | `gemini-embedding-2`, 768 (Gemini's host is fixed; `base_url` is refused) |
| `timeout_seconds` | 8 (2–20) |
| `user_per_minute`, `install_per_minute`, `daily_cap` | 9, 30, 500 |
| `auto_index` | `false`; `true` lets launcher starts run the indexer |
| `key_file` | `state/secrets/gemini.key` (must be inside `state/secrets`) |

- **The key** is created by the owner in `C:\ReFind-Laravel\state\secrets\gemini.key`, outside every profile folder,
  so demo backups never copy it. `VisualConfig::key()` reads it (no links, printable, ≤ 512 characters) and nothing
  logs or returns it. `visual-index.php status` reports only present/absent.
- **The gate** is all of: the profile's `visual` stage, a valid `visual.json`, a key (unless `fake`) and a calibration
  file for the engine's model key. The session flags drive the UI; the endpoints check again and fail closed.
- `REFIND_VISUAL_PROVIDER=fake` downgrades `phase8-dev` to the offline fake engine and is ignored on every other profile.

## 6. API

Both are multipart POSTs with the CSRF header and `photo[]` (1–3 files). Unknown fields are refused.

| Endpoint | Role | Other fields | Response |
|---|---|---|---|
| `POST /api/visual-search.php` | STUDENT | `type`, `category_id`, `location_id`, `status`, `date` | `{state, items}`: normal Explore cards plus `match_label`, matched photo first |
| `POST /api/staff-visual-search.php` | BUILDING_MANAGEMENT | `type` (`LOST`/`FOUND`/empty), `status` (`all` or a state code) | `{state, reports, total, page: 1, pages: 1}`: normal queue cards plus `match_label` |

`state` is `matches`, `no_match` or `empty_index` (nothing indexed yet; no provider call is made). Errors: 401 guest,
403 wrong role / `csrf_invalid` / `visual_disabled`, 400 `invalid_request`, 422 `validation_error` (no photo, four
photos, not an image, too big), 429 `visual_rate_limited` with `Retry-After`, 503 `visual_unavailable`,
`visual_uncalibrated` or `visual_timeout`.

## 7. Index, indexer and budget

- **Index** (derived, rebuildable, no database table): `state/<profile>/visual/<first 12 hex of sha256(model key)>/`
  with `current.json` (generation pointer and photo_id → path map) and packed float32 `index-<generation>.bin`. Only
  the current and previous generations are kept. An entry counts only if both its photo id and file path still match,
  so a snapshot restore that reuses an id never mixes photos. One folder per engine keeps the fake engine's test index
  away from Gemini's. Deleting the folder rebuilds it on the next passes.
- **Not in the demo digest:** the index lives outside the media collections that `demo_state.py` hashes; `backup_demo.py`
  copies `state\demo` and therefore the index too.
- **Indexer** `tools/visual-index.php`: `status`, `sync [--limit N]`, `loop` (every 30 s; exits when the stage or config
  goes away or the web ports stay closed), `list-models`, `probe`. It never runs inside a web request.
  `phase6-control.py start --launcher` starts it as `indexer-<profile>` only when `auto_index` is `true`; `stop` ends it
  before the web worker. Envelopes and `verify-candidate.py` never pass `--launcher`, so suites never spend calls.
- **Budget** (`state/<profile>/auth/visual-budget.json`, file-locked, user keys are HMACs): per user per minute,
  per installation per minute and per day (resets at midnight WIB), shared by searches and the indexer. A quota or
  project error from Google pauses photo search (Retry-After, or 15 minutes).

## 8. Screens

- **Explore (Students):** camera icon at the right end of the search field → picker → crop dialog (corner handles,
  keyboard support, "Use whole photo" / "Search", the AI notice) → chip with thumbnails, "+ Add" and "× Clear". On
  phones the chip's text is hidden and its buttons sit as two halves under the thumbnails. Results replace the grid in
  place, ranked, with labels; the results line says how many matched.
- **Report form:** the native opt-in block is gone; one hint under public photos: "Public photos can also be found with
  photo search."
- **BM Reports tab:** the same camera icon and crop step; Lost reports first, the in-chip switch widens to all
  reports; queue cards show the matched photo's thumbnail and label. Desktop opens the best match automatically, phones
  do not. Switching tabs ends photo mode; the pager is hidden in photo mode.
- **Unchanged nearby (round 5 batch B notes):** "Date lost or found" replaces "Event date" without its hint; on phones
  "Clear filters" moved into the Filters dropdown (full width, it clears only the dropdown filters), desktop keeps the
  results-line link.

## 9. Tests

All suites use the offline fake engine (no provider call is possible) and run inside snapshot envelopes.

| Suite | Checks |
|---|---|
| `tests/Unit/VisualSearchTest.php` | 12: vectors, scorer, index store, preprocessing, fake engine, InferHub and Gemini parsing, config, budget, calibration |
| `tests/Feature/VisualSearchContractTest.php` | 5: endpoints with a scratch index and an empty budget |
| `tools/test-visual-http.py` | 29: gate and flags, roles, CSRF, validation, ranking and label, honest no match, BM filters, no score in responses; creates its own photo report |
| `tools/test-visual-ui.py` | 44: camera, crop, chip, results, no match, clear and restore, filters, phones, BM tab, and the background indexer picking up a new report |

`verify-candidate.py` runs all four and `node --check`s `photo-search.js`. The live Gemini check on 8020
(`evidence\visual\live-check-8020-20261127.txt`) passed 10 of 10: three Lost photos found their Found reports, three
photos at once, honest no match, both cross-role refusals, and a BM search.

## 10. Operations

| Task | How |
|---|---|
| Is it on, and how far is the index? | `php tools/visual-index.php status` (with the profile's environment) |
| Index now | `php tools/visual-index.php sync --limit 50` (spends one call per photo) |
| Rebuild | Stop the instance, delete `state/<profile>/visual/<folder>`, start with `--launcher` |
| Turn off | Set `auto_index` to `false` (stops indexing) or remove `visual.json` (stops photo search); the stage stays |
| Change model | New model key → new calibration from the evaluation tools before any search works |

## 11. Limits and open items

- **Free Gemini tier:** Google may use free-tier inputs to improve its products. Fine for synthetic demo photos; real
  campus data needs a paid project and a privacy review first.
- **Hosting elsewhere** (for example Vercel): the file index and the 30-second loop need a different home there (object
  storage or a database table, and a scheduled job).
- **Not verified:** real phones (camera capture, touch), Safari and Firefox.
- **Look-alikes:** different objects of the same model can rank high; Building Management's check stays the safeguard.
