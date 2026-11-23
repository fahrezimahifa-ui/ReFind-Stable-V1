<!doctype html>
<html lang="en">
<head>
  <script src="js/theme.js"></script>
  <link rel="preload" href="assets/fonts/Manrope-variable.woff2" as="font" type="font/woff2" crossorigin="anonymous">
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="app-base" content="{{ $BASE_PATH }}">
  <title>My profile · ReFind</title><link rel="icon" type="image/svg+xml" href="assets/refind-logo-mark.svg"><link rel="icon" type="image/png" sizes="32x32" href="assets/refind-favicon-32.png"><link rel="apple-touch-icon" sizes="180x180" href="assets/refind-shortcut-180.png"><link rel="stylesheet" href="vendor/bootstrap.min.css"><link rel="stylesheet" href="css/theme.css"><link rel="stylesheet" href="css/auth.css"><link rel="stylesheet" href="css/styles.css">
<link rel="stylesheet" href="{{ $BASE_PATH }}css/polish.css"></head>
<body data-page="profile" data-requires-auth="true">
  @include('partials.navbar')
  <main id="main-content" tabindex="-1" class="account-main profile-main">
    <div class="account-heading"><h1>Your profile</h1><p>Update your display name and profile picture. Your account identifiers stay private.</p></div>
    <p class="loading-note" id="account-loading" role="status">Loading your profile…</p>
    <div class="feedback" id="account-load-error" role="alert" tabindex="-1" hidden></div><button class="btn btn-outline retry-load" id="retry-account" type="button" hidden>Retry loading profile</button>
    {{-- Round 4 (owner): one WhatsApp-style column. The pinned account-ui.js and avatar-editor.js keep working through the same element IDs; profile.js adds the photo menu, the inline name editor and the crop-screen controls. --}}
    <div class="account-layout profile-column" id="account-content" hidden>
      <section class="account-card profile-card" aria-labelledby="profile-name-title">
        <div class="profile-photo">
          <button class="profile-photo-button" id="avatar-trigger" type="button" aria-haspopup="menu" aria-expanded="false" aria-controls="avatar-menu"><span class="visually-hidden">Change profile photo</span><span class="profile-avatar" data-profile-initials aria-hidden="true"></span><span class="profile-photo-overlay" aria-hidden="true"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M14.5 4h-5L7.5 7H4a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-6"/><circle cx="12" cy="13" r="3.5"/><path d="M19 2.5v6M16 5.5h6"/></svg><span>Edit</span></span><span class="profile-photo-badge" aria-hidden="true"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 4h5l2 3H20a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1h3.5Z"/><circle cx="12" cy="13" r="3.5"/></svg></span></button>
          <div class="avatar-menu" id="avatar-menu" role="menu" aria-label="Profile photo" hidden>
            <button class="avatar-menu-item" id="avatar-view" type="button" role="menuitem" hidden><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="3"/></svg>View photo</button>
            <button class="avatar-menu-item" id="avatar-take" type="button" role="menuitem" hidden><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9.5 4h5l2 3H20a1 1 0 0 1 1 1v11a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1h3.5Z"/><circle cx="12" cy="13" r="3.5"/></svg>Take photo</button>
            <button class="avatar-menu-item" id="avatar-change" type="button" role="menuitem"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/></svg>Upload photo</button>
            <button class="avatar-menu-item avatar-menu-danger" id="avatar-remove" type="button" role="menuitem" hidden><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6"/></svg>Remove photo</button>
          </div>
          <input id="avatar-file" type="file" accept="image/jpeg,image/png,image/webp" class="visually-hidden" tabindex="-1" aria-label="Choose profile picture">
          <input id="avatar-camera" type="file" accept="image/*" capture="user" class="visually-hidden" tabindex="-1" aria-label="Take a profile picture">
        </div>
        <div id="avatar-feedback" class="feedback" role="status" tabindex="-1" hidden></div>
        <div class="profile-name-line" id="profile-name-view"><h2 id="profile-name-title" data-profile-name></h2><button class="icon-button profile-name-edit" id="name-edit" type="button" aria-label="Edit display name" title="Edit display name"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20h4L19 9l-4-4L4 16Z"/><path d="m13.5 6.5 4 4"/></svg></button></div>
        <form id="profile-form" class="profile-name-form" novalidate hidden>
          <fieldset id="profile-fields" disabled>
            <div class="field"><label for="display-name">Display name</label><div class="profile-name-input"><input class="form-control" id="display-name" type="text" maxlength="120" autocomplete="name" required aria-describedby="name-hint name-error"><button class="profile-name-save" id="profile-save" type="submit">Save display name</button><button class="icon-button profile-name-cancel" id="profile-reset" type="button" aria-label="Cancel editing" title="Cancel"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button></div><span class="hint" id="name-hint">1–120 characters. Enter saves, Escape cancels. This name appears on your reports.</span><span class="field-error" id="name-error" hidden></span></div>
          </fieldset>
        </form>
        <div class="feedback" id="profile-feedback" role="status" tabindex="-1" hidden></div>
        <p class="profile-role" data-profile-role></p>
        <div class="profile-rows">
          <div class="profile-row"><label for="profile-email">Email</label><input class="form-control readonly-control" id="profile-email" type="email" readonly autocomplete="email"><svg class="profile-lock" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-label="Cannot be changed" role="img"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg></div>
          <div class="profile-row" id="student-id-field" hidden><label for="profile-student-id">Student ID · private</label><input class="form-control readonly-control" id="profile-student-id" type="text" readonly><svg class="profile-lock" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-label="Never shown on public item cards" role="img"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg></div>
          <div class="profile-row"><label for="profile-role">Role</label><input class="form-control readonly-control" id="profile-role" type="text" readonly><svg class="profile-lock" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-label="Cannot be changed" role="img"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg></div>
        </div>
        <p class="profile-privacy">Your name appears on your reports. Email and student ID stay private.</p>
        <a class="profile-link-row" href="settings.html"><span>Account settings</span><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg></a>
      </section>
    </div>
  </main>

  <dialog id="avatar-dialog" class="avatar-dialog avatar-stage" aria-labelledby="avatar-title" aria-describedby="avatar-help">
    <div class="avatar-stage-bar"><button type="button" class="avatar-stage-icon" id="avatar-cancel" aria-label="Cancel" title="Cancel"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button><h2 id="avatar-title">Drag the image to adjust</h2><button type="button" class="avatar-stage-upload" id="avatar-reupload"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/></svg>Upload</button></div>
    <p id="avatar-help" class="visually-hidden">Drag the photo or use the zoom buttons. Only the part inside the circle is kept.</p>
    <div class="avatar-stage-area"><div class="avatar-canvas-wrap"><canvas id="avatar-canvas" width="512" height="512" aria-label="Profile picture preview"></canvas></div><div class="avatar-zoom-buttons"><button type="button" id="avatar-zoom-in" aria-label="Zoom in" title="Zoom in"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg></button><button type="button" id="avatar-zoom-out" aria-label="Zoom out" title="Zoom out"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M5 12h14"/></svg></button></div></div>
    <div class="avatar-stage-foot">
      <details class="avatar-position"><summary>Position controls</summary><div class="avatar-controls"><label for="avatar-zoom">Zoom</label><input id="avatar-zoom" type="range" min="1" max="3" step="0.01" value="1"><label for="avatar-x">Horizontal position</label><input id="avatar-x" type="range" min="0" max="100" step="1" value="50"><label for="avatar-y">Vertical position</label><input id="avatar-y" type="range" min="0" max="100" step="1" value="50"></div><button type="button" class="avatar-stage-text" id="avatar-reset">Reset crop</button></details>
      <p id="avatar-editor-status" role="status" tabindex="-1"></p>
      <button type="button" class="avatar-stage-save" id="avatar-save">Save picture</button>
    </div>
  </dialog>
  <dialog id="avatar-view-dialog" class="avatar-view-dialog" aria-label="Profile photo"><img id="avatar-view-image" alt="Your profile photo"><button type="button" class="avatar-stage-icon avatar-view-close" id="avatar-view-close" aria-label="Close photo" title="Close"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg></button></dialog>
  <script src="vendor/jquery.min.js"></script><script src="js/api.js"></script><script src="js/shell.js"></script><script src="js/avatar-editor.js"></script><script src="js/account-ui.js"></script><script src="js/profile.js"></script>
</body>
</html>
