<!doctype html>
<html lang="en">
<head>
  <script src="js/theme.js"></script>
  <link rel="preload" href="assets/fonts/Manrope-variable.woff2" as="font" type="font/woff2" crossorigin="anonymous">
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="app-base" content="{{ $BASE_PATH }}">
  <title>Review Queue · ReFind</title><link rel="icon" type="image/svg+xml" href="assets/refind-logo-mark.svg"><link rel="icon" type="image/png" sizes="32x32" href="assets/refind-favicon-32.png"><link rel="apple-touch-icon" sizes="180x180" href="assets/refind-shortcut-180.png"><link rel="stylesheet" href="vendor/bootstrap.min.css"><link rel="stylesheet" href="css/theme.css"><link rel="stylesheet" href="css/auth.css"><link rel="stylesheet" href="css/styles.css"><link rel="stylesheet" href="css/student.css"><link rel="stylesheet" href="css/bm.css">
</head>
<body data-page="bm" data-claims-read-only="true" data-requires-auth="true">
  @include('partials.navbar')
  <main id="main-content" tabindex="-1" class="student-main bm-main"><header class="page-heading"><div><span class="eyebrow">Building Management</span><h1 id="bm-title">Review Queue</h1><p id="bm-intro">Review claim details and ownership evidence.</p></div></header>
    <div class="filter-panel bm-filters"><label class="search-field"><span class="visually-hidden">Search operational queue</span><input class="form-control" id="bm-search" type="search" maxlength="200" placeholder="Search item, claimant or area"></label><label class="bm-state-filter"><span class="visually-hidden">Filter queue state</span><select class="form-select" id="bm-state"></select></label></div>
    <div class="feedback" id="bm-feedback" role="status" tabindex="-1" hidden></div><p class="loading-note" id="bm-loading" role="status">Loading queue…</p><button class="btn btn-outline" id="bm-retry" type="button" hidden>Refresh queue</button>
    <div class="bm-layout" data-mobile-view="queue"><section class="bm-queue" aria-label="Operational queue"><div class="bm-queue-heading"><h2 id="queue-title">Claims</h2><span id="queue-count" aria-live="polite"></span></div><div id="bm-queue-list"></div><div class="pager" id="bm-pager"></div></section><section class="bm-detail" id="bm-detail" aria-label="Selected record" tabindex="-1"><button class="btn btn-outline bm-back" id="bm-back" type="button"><svg class="ui-icon" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M19 12H5m6-6-6 6 6 6" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"/></svg><span>Back to queue</span></button><p class="context-note" id="bm-selection-note" hidden></p><div id="bm-detail-content"><div class="empty-state"><h2>Choose a record</h2><p>Select a claim to see its details.</p></div></div></section></div>
  </main>
  <dialog class="photo-dialog" id="bm-evidence-dialog" aria-labelledby="bm-evidence-title"><h2 id="bm-evidence-title">Private ownership evidence</h2><img id="bm-evidence-image" alt="Private ownership evidence"><button class="btn btn-outline" id="bm-evidence-close" type="button">Close</button></dialog><script src="vendor/jquery.min.js"></script><script src="js/api.js"></script><script src="js/shell.js"></script><script src="js/student-common.js"></script><script src="js/bm.js"></script>
</body>
</html>
