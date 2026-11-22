$(async function () {
  const app = window.ReFind, U = window.StudentUI, shell = window.ReFindShell;
  const params = new URLSearchParams(location.search);
  const handoversAvailable = document.body.dataset.handoversUnavailable !== 'true', reportsAvailable = document.body.dataset.staffReportsUnavailable !== 'true';
  const tabsAvailable = ['claims', ...(reportsAvailable ? ['reports'] : []), ...(handoversAvailable ? ['handover'] : [])], claimsOnly = tabsAvailable.length === 1;
  const decisionsAllowed = (document.body.dataset.bmDecisions || '').split(' ').filter(Boolean), claimsReadOnly = decisionsAllowed.length === 0;
  let tab = tabsAvailable.includes(params.get('tab')) ? params.get('tab') : 'claims';
  let selected = params.get(tab === 'claims' ? 'claim' : tab === 'reports' ? 'report' : 'handover') || null;
  let pinned = Boolean(selected), initialFocus = Boolean(selected), current = null, page = 1;
  let request = 0, detailRequest = 0, saving = false, operation = null, refs, trigger = null, timer;
  const drafts = new Map();
  const labels = {approved:'Approve claim', rejected:'Reject claim', need_more_info:'Request more info', status:'Update report status', schedule:'Schedule pickup', reschedule:'Reschedule pickup', cancel_schedule:'Cancel appointment', complete:'Confirm item returned', release:'Release pickup'};
  const done = {approved:'Claim approved.', rejected:'Claim rejected.', need_more_info:'More information requested from the claimant.', status:'Report status updated.', schedule:'Pickup scheduled.', reschedule:'Pickup rescheduled.', cancel_schedule:'Appointment cancelled. Ownership stays approved.', complete:'Item return confirmed.', release:'Pickup released. The report and any linked Lost report are available again.'};
  const titles = {claims:'Review Queue', reports:'Report review', handover:'Pickups'};
  const terminalStatuses = ['RESOLVED','UNCLAIMED_CLOSED'];
  const get = (endpoint, query = {}) => app.request(endpoint + '?' + new URLSearchParams(Object.entries(query).filter(([, v]) => v !== '' && v !== null && v !== undefined)));
  const mobile = () => innerWidth < 1100;
  // Desktop: the page is the only scroller. The detail column is sticky and moves with the page until one of its edges
  // reaches the window, so a tall record reads top to bottom while the queue keeps its place.
  const detailBox = document.querySelector('#bm-detail');
  let detailTop = null, lastScroll = scrollY, scrollFrame = 0;
  const headerOffset = () => (document.querySelector('.app-header')?.getBoundingClientRect().height || 70) + 16;
  function placeDetail(reset = false) {
    if (!detailBox) return;
    if (mobile()) { detailBox.style.removeProperty('top'); detailTop = null; lastScroll = scrollY; return; }
    const high = headerOffset(), low = Math.min(high, (window.visualViewport?.height || innerHeight) - detailBox.offsetHeight - 16);
    const delta = scrollY - lastScroll; lastScroll = scrollY;
    detailTop = reset || detailTop === null ? high : Math.min(high, Math.max(low, detailTop - delta));
    detailBox.style.top = Math.round(detailTop) + 'px';
  }
  // A new selection shows the record from its top without moving the page; the page moves only when the record's
  // start cannot be shown in place (at the very end of a long queue).
  function revealDetail() {
    if (mobile()) { U.focusRecord(detailBox); return; }
    placeDetail(true);
    if (!detailBox.hasAttribute('tabindex')) detailBox.setAttribute('tabindex', '-1');
    detailBox.focus({preventScroll: true});
    if (detailBox.getBoundingClientRect().top < headerOffset() - 2) U.focusRecord(detailBox);
  }
  addEventListener('scroll', () => { if (!scrollFrame) scrollFrame = requestAnimationFrame(() => { scrollFrame = 0; placeDetail(); }); }, {passive: true});
  addEventListener('resize', () => placeDetail());
  if (detailBox && window.ResizeObserver) new ResizeObserver(() => placeDetail()).observe(detailBox);
  $('#bm-queue-list').on('keydown', '.bm-queue-card', function (event) {
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;
    const cards = $('#bm-queue-list .bm-queue-card:not(:disabled)'), index = cards.index(this);
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? cards.length - 1 : index + (event.key === 'ArrowDown' ? 1 : -1);
    if (next < 0 || next >= cards.length) return;
    event.preventDefault(); cards.eq(next).trigger('focus');
  });
  const idKey = () => tab === 'claims' ? 'claim_id' : tab === 'reports' ? 'item_id' : 'handover_id';
  const paramKey = () => tab === 'claims' ? 'claim' : tab === 'reports' ? 'report' : 'handover';
  const draftKey = snapshot => snapshot.kind + ':' + snapshot.id + ':' + snapshot.action;
  // Only irreversible decisions ask for the extra confirmation tick.
  const needsConfirm = action => ['approved','rejected','complete','release'].includes(action) || action === 'status' && terminalStatuses.includes($('#operation-status').val());

  function feedback(message, success = false, extra = null) {
    const box = $('#bm-feedback').toggleClass('success', success).attr('role', success ? 'status' : 'alert').text(message);
    if (extra) box.append(' ', extra);
    box.prop('hidden', false);
    if (success) U.focusRecord(box[0]);
  }
  function error(e) {
    if (e.status === 401) { location.replace(app.loginUrl()); return; }
    feedback(e.message || String(e)); $('#bm-retry').prop('hidden', false); $('#bm-loading').prop('hidden', true);
  }
  const emptyDetail = (title = 'Choose a record', message = claimsReadOnly ? 'Select a claim to see its details.' : 'Select a card to see its details and next steps.') => U.empty(title, message);
  function setView(view) { $('.bm-layout').attr('data-mobile-view', view); }
  function syncUrl() {
    const query = {tab};
    // Search, filter and page live in the URL too, so a reload or a return from a report's public page keeps the queue.
    const q = $('#bm-search').val().trim(), status = $('#bm-state').val(), defaultStatus = $('#bm-state option').first().val();
    if (q) query.q = q;
    if (status && status !== defaultStatus) query.status = status;
    if (page > 1) query.page = page;
    // On phones an auto-selected record stays out of the URL until it is actually opened.
    if (selected && (!mobile() || $('.bm-layout').attr('data-mobile-view') === 'detail')) query[paramKey()] = selected;
    history.replaceState(history.state, '', app.base + 'admin.html?' + new URLSearchParams(query));
    document.title = titles[tab] + ' · ReFind';
    $('#nav-home').attr('aria-current', 'page');
  }
  function switchState() {
    const state = $('#bm-state').empty();
    const choices = tab === 'claims' ? [['PENDING',U.claimNames.PENDING],['NEED_MORE_INFO',U.claimNames.NEED_MORE_INFO],['APPROVED','Approved'],['REJECTED','Rejected'],['WITHDRAWN','Withdrawn'],['all','All claims']]
      : tab === 'handover' ? [['active','Active pickups'],['PENDING','Awaiting scheduling'],['SCHEDULED','Scheduled'],['COMPLETED','Returned'],['all','All pickups']]
      : [['all','All reports'], ...refs.statuses.map(s => [s.code, U.statusNames[s.code] || s.name])];
    if (tab === 'claims') choices.unshift(['active','Active claims']);
    choices.forEach(([id, name]) => state.append($('<option>').val(id).text(name)));
    $('#bm-title').text('Review Queue'); // matches the nav item; the tabs and intro name the section
    $('#bm-intro').text(tab === 'handover' ? 'Arrange pickup, update appointments, and record the return to a verified owner.' : tab === 'reports' ? 'Check reports and choose the next available step.' : claimsReadOnly ? 'Review claim details and ownership evidence.' : 'Compare the identifying details, then record your decision.');
    $('#queue-title').text(tab === 'claims' ? 'Claims' : tab === 'reports' ? 'Reports' : 'Pickups');
    $('#bm-search').attr('placeholder', tab === 'claims' ? 'Search item, claimant or area' : tab === 'reports' ? 'Search item, reporter or area' : 'Search item, claimant or pickup area');
    $('[data-bm-tab]').each(function () { const active = $(this).attr('data-bm-tab') === tab; $(this).toggleClass('active', active).attr('aria-pressed', String(active)); });
  }
  function evidence(urls, title) {
    const section = $('<section>').addClass('bm-evidence-section').append($('<h3>').text(title)), photos = $('<div>').addClass('bm-evidence');
    if (!urls?.length) section.append($('<p>').addClass('small muted').text('No supporting images provided.'));
    else urls.forEach((src, index) => photos.append($('<button>').attr({type:'button','aria-label':'Enlarge ' + title + ' ' + (index + 1)}).append($('<img>').attr({src, alt:title + ' ' + (index + 1), loading:'lazy'})).on('click', function () {
      trigger = this; $('#bm-evidence-title').text(title + ' ' + (index + 1)); $('#bm-evidence-image').attr({src, alt:title + ' ' + (index + 1)}); document.querySelector('#bm-evidence-dialog').showModal();
    })));
    return section.append(photos);
  }
  // A labelled text field for long values (descriptions, notes), styled like the record's field labels.
  // A titled group of record details. Later groups get a floating separator line (polish.css).
  const group = (title, ...content) => $('<section>').addClass('bm-group').append(title ? $('<h3>').addClass('bm-group-title').text(title) : null, ...content);
  // A missing value reads as missing (muted), so it never outweighs its label.
  const placeholder = text => $('<span>').addClass('value-empty').text(text);
  function field(title, text, extra = '') { return $('<section>').addClass('bm-field ' + extra).append($('<h3>').addClass('bm-field-label').text(title), $('<p>').toggleClass('value-empty', !text).text(text || 'Not specified')); }
  function block(title, text, priv = false) { return $('<div>').addClass('bm-block' + (priv ? ' private' : '')).append($('<h3>').text(title), $('<p>').toggleClass('value-empty', !text).text(text || 'No identifying detail recorded.')); }
  function meta(values) { const list = $('<dl>').addClass('detail-meta'); values.forEach(([title, value]) => list.append($('<div>').append($('<dt>').text(title), $('<dd>').append(value instanceof jQuery ? value : value ? document.createTextNode(String(value)) : placeholder('Not specified'))))); return list; }
  // Claims and reports: at most one primary and one outline button. Request more info is a text button with the same
  // dialog. Pickups lay out their own rows and sections in handoverDetail.
  function actionStyle(action) {
    if (action === 'need_more_info') return 'text-button';
    if (action === 'rejected') return 'btn btn-danger-outline';
    return ['approved','status'].includes(action) ? 'btn btn-primary' : 'btn btn-outline';
  }
  // Buttons first (primary, then outline), then text actions.
  function actionRank(action) {
    const style = actionStyle(action);
    return style.includes('btn-primary') ? 0 : style.startsWith('btn ') ? 1 : 2;
  }
  function actionButton(action, snapshot, style = actionStyle(action)) {
    return $('<button>').addClass(style).attr({type:'button','data-bm-action':action}).text(labels[action]).on('click', function () {
      if (saving || !current || selected !== String(snapshot.id) || current.revision !== snapshot.revision) return;
      openOperation(action, snapshot, this);
    });
  }
  // Switch tab and record in place instead of reloading the page.
  async function openRecord(nextTab, id, afterSave = false) {
    if (!tabsAvailable.includes(nextTab) || saving && !afterSave) return;
    clearTimeout(timer); ++request; ++detailRequest;
    tab = nextTab; selected = String(id); pinned = true; initialFocus = true; page = 1; current = null;
    $('#bm-search').val(''); switchState(); setView('detail'); syncUrl(); await loadQueue(1, true);
  }
  const heading = title => $('<h2>').attr('title', title).text(U.titleParts(title).display);
  const recordHeading=(title,chips)=>$('<header>').addClass('bm-record-heading').append(chips,heading(title));
  function claimDetail(claim) {
    const box = $('<article>').attr('data-detail-claim-id', claim.claim_id);
    box.append(recordHeading(claim.item.title,$('<div>').addClass('chips').append(U.stateChip(claim.claim_status,U.claimNames[claim.claim_status]),U.demoChip(claim.item.title))));
    // Three purposeful groups: who claims it, which item, and the ownership evidence side by side (the claimant's photos
    // sit with their answer, opposite the finder's private clue).
    const photo = claim.item.photos.length ? $('<button>').addClass('bm-report-photo').attr({type:'button','aria-label':'Enlarge public item photo'}).append($('<img>').attr({src:claim.item.photos[0],alt:U.titleParts(claim.item.title).display})).on('click', function () {
      trigger = this; $('#bm-evidence-title').text('Public item photo'); $('#bm-evidence-image').attr({src:claim.item.photos[0],alt:U.titleParts(claim.item.title).display}); document.querySelector('#bm-evidence-dialog').showModal();
    }) : null;
    box.append(group('Claimant', meta([['Name',shell.identity(claim.claimant_name,claim.claimant_avatar_url)],['Email',claim.claimant_email],['Student ID',claim.claimant_student_id],['Submitted',U.date(claim.created_at,true)],...(claim.resubmitted_at ? [['Resubmitted',U.date(claim.resubmitted_at,true)]] : [])])));
    box.append(group('Item', $('<div>').addClass('bm-claim-summary').append(meta([['Found at',U.locationText(claim.item)],['Linked Lost report',claim.lost ? U.titleParts(claim.lost.title).display : placeholder('None linked')]]), photo), field('Public description', claim.item.description, 'bm-claim-description')));
    box.append(group('Ownership evidence', claim.previous_request ? field('Your previous request · ' + U.date(claim.previous_request.at,true), claim.previous_request.note, 'bm-outcome') : null,
      $('<div>').addClass('bm-compare').append(block('Claimant answer',claim.ownership_answer).append(evidence(claim.ownership_photos,"Claimant's private photos")), block("Finder's private clue",claim.item.verification_clue,true))));
    if (claim.lost) box.append($('<section>').addClass('bm-audit').append(block("Lost owner's ownership details",claim.lost.verification_clue,true), evidence(claim.lost.ownership_photos,"Lost owner's private photos"), U.navAction('Open linked report',U.itemUrl(claim.lost.item_id))));
    if (claim.review_note) box.append(field('Review note' + (claim.reviewed_at ? ' · ' + U.date(claim.reviewed_at,true) : ''), claim.review_note, 'bm-outcome'));
    if (claim.approval_blocked) box.append($('<p>').addClass('bm-locked-note').text(claim.approval_blocked));
    if (claimsReadOnly) return box;
    const actions = $('<div>').addClass('bm-detail-actions');
    [...claim.decisions].filter(action => decisionsAllowed.includes(action)).sort((a, b) => actionRank(a, claim) - actionRank(b, claim)).forEach(action => actions.append(actionButton(action,{kind:'claim',id:claim.claim_id,revision:claim.revision,record:claim})));
    if (claim.handover_id && handoversAvailable) actions.append($('<button>').addClass('btn btn-primary').attr('type','button').text('Open pickup').on('click', () => openRecord('handover', claim.handover_id)));
    if (!claim.decisions.length) box.append($('<p>').addClass('bm-locked-note').text(claim.claim_status === 'APPROVED' ? 'Ownership is approved. Continue to the pickup to arrange it or see the return record.' : claim.claim_status === 'WITHDRAWN' ? 'The claimant withdrew this claim. They may send a new one while the item is available.' : claim.claim_status === 'REJECTED' ? 'This claim was rejected. A rejection is final for this item.' : 'This decision is saved.'));
    return box.append(actions);
  }
  function reportDetail(report) {
    // Two groups: the public report, then what only Building Management sees.
    const box = $('<article>').attr('data-detail-report-id',report.item_id).append(recordHeading(report.title,U.chips(report)),
      group(null, meta([['Type',report.item_type === 'LOST' ? 'Lost' : 'Found'],[report.item_type === 'LOST' ? 'Last seen at' : 'Found at',U.locationText(report)],[report.item_type === 'LOST' ? 'Date lost' : 'Date found',U.eventDate(report)],['Reporter',shell.identity(report.reporter_name,report.reporter_avatar_url)]]), field('Public description', report.description)),
      group(null, block(report.item_type === 'LOST' ? 'Ownership details' : 'Private clue',report.verification_clue,true), evidence(report.ownership_photos,'Private photos')));
    const actions = $('<div>').addClass('bm-detail-actions');
    if (report.allowed_statuses.length) actions.append(actionButton('status',{kind:'report',id:report.item_id,revision:report.revision,record:report}));
    else if (['RESOLVED','UNCLAIMED_CLOSED'].includes(report.status_code)) box.append($('<p>').addClass('bm-locked-note').text('Archived report · read-only.'));
    else {
      const lock = $('<p>').addClass('bm-locked-note').text('This report is locked while its claim or pickup is in progress. ');
      if (report.active_handover_id) lock.append($('<button>').addClass('text-button').attr('type','button').text('Open pickup').on('click', () => openRecord('handover', report.active_handover_id)));
      else if (report.active_claim_id) lock.append($('<button>').addClass('text-button').attr('type','button').text('Open the claim').on('click', () => openRecord('claims', report.active_claim_id)));
      box.append(lock);
    }
    actions.append($('<a>').addClass('btn btn-outline').attr('href',U.itemUrl(report.item_id)).text('Public detail'));
    const list = $('<ol>'); report.history.forEach(event => list.append($('<li>').append($('<strong>').text(event.status), $('<p>').text(U.date(event.at,true) + (event.note ? ' · ' + event.note : '')))));
    return box.append(actions,$('<section>').addClass('bm-audit').append($('<h3>').text('Status history'),list));
  }
  function localInput(value) {
    if (!value) return '';
    const parts = new Intl.DateTimeFormat('en-CA',{timeZone:'Asia/Jakarta',year:'numeric',month:'2-digit',day:'2-digit',hour:'2-digit',minute:'2-digit',hourCycle:'h23'}).formatToParts(new Date(value));
    const part = name => parts.find(p => p.type === name).value;
    return part('year') + '-' + part('month') + '-' + part('day') + 'T' + part('hour') + ':' + part('minute');
  }
  // Default appointment: the next working day at 10:00 campus time.
  function nextWorkingSlot() {
    const today = localInput(new Date()).slice(0,10), d = new Date(today + 'T10:00:00+07:00');
    do d.setUTCDate(d.getUTCDate() + 1); while ([0,6].includes(new Date(d.getTime() + 7 * 3600000).getUTCDay()));
    return localInput(d);
  }
  function handoverDetail(record) {
    const returned = record.handover_status === 'COMPLETED', scheduled = record.handover_status === 'SCHEDULED';
    const notes = record.notes === 'Arrange pickup with Building Management.' ? 'Awaiting a pickup appointment.' : record.notes;
    const box = $('<article>').attr({'data-detail-handover-id':record.handover_id,'data-handover-status':record.handover_status}).append(recordHeading(record.title,$('<div>').addClass('chips').append(U.stateChip(record.handover_status,U.pickupNames[record.handover_status]),U.demoChip(record.title))),
      // Two groups: who collects it, then the pickup itself (area, time, note).
      group('Verified claimant', $('<div>').addClass('bm-pickup-meta').append(meta([['Name',shell.identity(record.claimant_name,record.claimant_avatar_url)],['Email',record.claimant_email],['Student ID',record.claimant_student_id]]))),
      group('Pickup', meta([['Pickup area',record.pickup_location || placeholder('Not assigned')],...(returned ? [['Returned',U.date(record.completed_at,true)],['Appointment',record.scheduled_at ? U.date(record.scheduled_at,true) : 'Collected without a scheduled appointment']] : [['Schedule',record.scheduled_at ? U.date(record.scheduled_at,true) + (U.pickupPastDue(record) ? ' · time passed' : '') : placeholder('Not scheduled yet. Agree a time with the claimant.')]])]), notes ? field('Pickup note', notes, 'bm-outcome') : null));
    // Display only: a passed appointment is flagged, but its state changes only through the operations below.
    if (U.pickupPastDue(record)) box.append($('<p>').addClass('past-due-note').text('The scheduled time has passed. If the owner collected the item, confirm the return; otherwise reschedule with them.'));
    // Main row: the two likeliest next steps for this state. Appointment changes and release have their own labelled
    // sections below, so every operation the API offers stays visible without crowding the decision.
    const snapshot = {kind:'handover',id:record.handover_id,revision:record.revision,record}, ops = record.operations || [];
    const main = (scheduled ? ['reschedule','complete'] : ['schedule','complete']).filter(action => ops.includes(action));
    const actions = $('<div>').addClass('bm-detail-actions');
    main.forEach((action, index) => actions.append(actionButton(action, snapshot, index === 0 ? 'btn btn-primary' : 'btn btn-outline')));
    const section = (key, title, text, control) => $('<section>').addClass('bm-action-section bm-action-section--' + key).attr('aria-labelledby', 'bm-section-' + key)
      .append($('<h3>').attr('id', 'bm-section-' + key).text(title), text ? $('<p>').text(text) : null, control);
    const more = [];
    if (ops.includes('cancel_schedule')) more.push(section('appointment', 'Appointment', 'Cancelling keeps ownership approved. You can schedule the same pickup again.', actionButton('cancel_schedule', snapshot, 'text-button')));
    ops.filter(action => !main.includes(action) && !['cancel_schedule','release'].includes(action)).forEach(action => more.push(section(action, labels[action], '', actionButton(action, snapshot, 'text-button'))));
    if (ops.includes('release')) more.push(section('release', 'If the owner never collects', 'Releasing closes this claim as rejected and makes the report, and any linked Lost report, available again. You will be asked for a reason and a confirmation.', actionButton('release', snapshot, 'btn btn-danger-outline')));
    if (main.length) box.append(actions);
    if (returned || !ops.length) box.append($('<p>').addClass('bm-locked-note').text(returned ? 'Returned record · read-only.' : 'No pickup operation is available for this record.'));
    else if (!scheduled) box.append($('<p>').addClass('bm-locked-note').text('Schedule a time with the claimant, or confirm the return if they collect the item now.'));
    box.append(more);
    const audit = $('<ol>');
    (record.audit || []).forEach(event => {
      const entry = $('<li>').append($('<strong>').text(event.title),$('<p>').text(U.date(event.at,true)));
      if (event.pickup_event) entry.append($('<p>').text(U.pickupMessage(event.pickup_event,event.at)),$('<details>').addClass('technical-audit').append($('<summary>').text('Original audit record'),$('<p>').text(event.message)));
      else entry.append($('<p>').text(event.message));
      audit.append(entry);
    });
    if (!record.audit?.length) audit.append($('<li>').text('No pickup changes recorded yet.'));
    return box.append($('<section>').addClass('bm-audit').append($('<h3>').text('Private pickup history'),audit));
  }
  async function loadDetail(focus = false) {
    const sequence = ++detailRequest, type = tab, id = selected; current = null;
    if (!id) { $('#bm-detail-content').empty().append(emptyDetail()); return; }
    $('#bm-detail-content').empty().append($('<p>').addClass('loading-note').text('Loading selected record…'));
    try {
      const data = type === 'claims' ? (await get('staff.php',{resource:'claims',id})).claim : type === 'reports' ? (await get('staff.php',{resource:'reports',id})).report : (await get('handovers.php',{id})).handovers[0];
      if (sequence !== detailRequest || tab !== type || selected !== id) return;
      current = data; $('#bm-detail-content').empty().append(type === 'claims' ? claimDetail(data) : type === 'reports' ? reportDetail(data) : handoverDetail(data));
      if(focus)document.querySelector('#bm-detail').scrollTop=0;
      $('.bm-queue-card').each(function () { const active = $(this).attr('data-record-id') === selected; $(this).toggleClass('selected',active).attr('aria-pressed',String(active)); });
      if (focus) setView('detail');
      syncUrl(); if (focus) revealDetail();
    } catch (e) {
      if (sequence !== detailRequest) return;
      if (e.status === 401) location.replace(app.loginUrl());
      else $('#bm-detail-content').empty().append(emptyDetail('Record unavailable',e.message));
    }
  }
  function resetSelection() {
    ++request; ++detailRequest; selected = null; current = null; pinned = false;
    setView('queue'); $('#bm-selection-note').prop('hidden',true); $('#bm-detail-content').empty().append(emptyDetail());
    $('.bm-queue-card').prop('disabled',true); $('#bm-loading').prop('hidden',false); syncUrl();
  }
  async function loadQueue(next = page, keepFeedback = false) {
    page = next; const sequence = ++request;
    $('#bm-loading').prop('hidden',false); $('#bm-retry').prop('hidden',true); if (!keepFeedback) $('#bm-feedback').prop('hidden',true);
    try {
      const query = {q:$('#bm-search').val().trim(),status:$('#bm-state').val(),page,limit:24};
      const data = tab === 'handover' ? await get('handovers.php',query) : await get('staff.php',{...query,resource:tab});
      if (sequence !== request) return;
      // A remembered page past the end (records were decided meanwhile) falls back to the last page.
      if (data.total && page > data.pages) return loadQueue(data.pages, keepFeedback);
      const records = tab === 'claims' ? data.claims : tab === 'reports' ? data.reports : data.handovers;
      if (!pinned && !records.some(record => String(record[idKey()]) === selected)) selected = records.length ? String(records[0][idKey()]) : null;
      const outside = Boolean(selected) && !records.some(record => String(record[idKey()]) === selected);
      $('#bm-selection-note').text(mobile() ? 'This linked or saved record is not in the current list. Back to queue returns to that list.' : 'This linked or saved record is not in the current list. Choose a card to return to it.').prop('hidden',!outside);
      const box = $('#bm-queue-list').empty(); $('#queue-count').text(U.plural(data.total, 'record'));
      records.forEach(record => {
        const id = String(record[idKey()]), code = tab === 'claims' ? record.claim_status : tab === 'reports' ? record.status_code : record.handover_status;
        const label = tab === 'claims' ? U.claimNames[code] : tab === 'reports' ? U.statusNames[code] : U.pickupNames[code];
        const detail = tab === 'claims' ? record.claimant_name + ' · ' + U.plural(record.proof_count, 'supporting image') : tab === 'reports' ? (record.item_type === 'LOST' ? 'Lost · ' : 'Found · ') + U.locationText(record) : record.claimant_name + ' · ' + (code === 'COMPLETED' ? 'Returned' : record.scheduled_at ? U.date(record.scheduled_at,true) : 'Not scheduled yet');
        const summary=$('<p>').text(detail);
        if(tab!=='reports'){const extra=tab==='claims'?U.plural(record.proof_count,'supporting image'):code==='COMPLETED'?'Returned':record.scheduled_at?U.date(record.scheduled_at,true):'Not scheduled yet';summary.empty().addClass('bm-queue-person').append(shell.identity(record.claimant_name,record.claimant_avatar_url),$('<span>').text(extra));}
        box.append($('<button>').addClass('bm-queue-card').toggleClass('selected',id === selected).attr({type:'button','data-record-id':id,'aria-pressed':String(id === selected)}).append(U.stateChip(code,label),tab==='handover'&&U.pickupPastDue(record)?$('<span>').addClass('past-due-flag').text('Time passed'):null,$('<h3>').attr('title',record.title).text(U.titleParts(record.title).display),U.demoChip(record.title),summary).on('click',async function () {
          if (saving) return; selected = id; pinned = false; $('#bm-selection-note').prop('hidden',true);
          // On phones the detail is its own step, so the system Back button returns to the list.
          if (mobile() && !history.state?.bmDetail) history.pushState({bmDetail:true}, '', app.base + 'admin.html?' + new URLSearchParams({tab, [paramKey()]: id}));
          setView('detail'); await loadDetail(true);
        }));
      });
      if (!records.length) box.append(query.q ? emptyDetail('No matching records','Try another search word or clear the search.') : emptyDetail('Queue is clear','Nothing in this view needs attention. Change the filter to revisit outcomes.'));
      const pager = $('#bm-pager').empty();
      if (data.pages > 1) pager.append($('<button>').addClass('btn btn-outline').attr('type','button').prop('disabled',page <= 1).text('Previous').on('click',() => { resetSelection(); loadQueue(page - 1); }),$('<span>').text(page + ' / ' + data.pages),$('<button>').addClass('btn btn-outline').attr('type','button').prop('disabled',page >= data.pages).text('Next').on('click',() => { resetSelection(); loadQueue(page + 1); }));
      // Remove the queue loading row before focus calculates its final scroll position.
      syncUrl(); $('#bm-loading').prop('hidden',true); await loadDetail(initialFocus); initialFocus = false;
      if (sequence !== request) return;
      $('#bm-loading').prop('hidden',true); document.body.dataset.ready = 'true';
    } catch (e) { if (sequence === request) error(e); }
  }
  function updateStatusConfirm() {
    if (!operation || operation.action !== 'status') return;
    const final = terminalStatuses.includes($('#operation-status').val());
    $('#operation-confirm-row').prop('hidden', !final); $('#operation-confirm').prop('checked', false);
    $('#operation-status-warning').prop('hidden', !final);
    $('#operation-submit').prop('disabled', !$('#operation-status').val() || final);
  }
  function openOperation(action, snapshot, button) {
    operation = {action,...snapshot};
    trigger = button;
    const draft = drafts.get(draftKey(operation)), record = snapshot.record;
    const noteRequired = snapshot.kind !== 'handover' || ['reschedule','cancel_schedule','release'].includes(action);
    $('#operation-title').text(labels[action]);
    $('#operation-record-title').text(U.titleParts(record.item?.title || record.title).display);
    const person = record.claimant_name ? 'Claimant: ' + record.claimant_name + ' · ' : record.reporter_name ? 'Reporter: ' + record.reporter_name + ' · ' : '';
    const recordType = snapshot.kind === 'claim' ? 'Claim' : snapshot.kind === 'report' ? 'Report' : 'Pickup';
    $('#operation-record-meta').text(person + recordType + ' #' + snapshot.id);
    $('#operation-feedback,[data-bm-error],#operation-refresh,#operation-status-warning').prop('hidden',true);
    $('[data-bm-error]').text('');
    $('#operation-confirm').prop('checked',false);
    $('#operation-submit').removeClass('btn-primary btn-danger btn-warning-outline').addClass(['rejected','release'].includes(action) ? 'btn-danger' : action === 'cancel_schedule' ? 'btn-warning-outline' : 'btn-primary').text(labels[action]);
    $('#operation-dismiss').text(action === 'cancel_schedule' ? 'Keep appointment' : 'Cancel');
    $('#operation-fields').prop('disabled',false);
    $('#operation-note').val(draft?.note ?? (action === 'approved' ? 'Ownership verified. Building Management will set a pickup time and notify you.' : '')).attr('maxlength',snapshot.kind === 'handover' ? 250 : 500);
    $('#operation-note-label').text(snapshot.kind === 'handover' ? (noteRequired ? 'Reason (8–250 characters) *' : 'Pickup note · optional (max 250 characters)') : 'Review note (8–500 characters) *');
    $('#operation-note-hint').text(snapshot.kind === 'claim' ? 'The claimant receives this note. It does not appear in public history.' : snapshot.kind === 'report' ? 'The reporter receives this note. It does not appear in public history.' : 'The claimant sees this note in their pickup updates.');
    $('#operation-status-field').prop('hidden',action !== 'status');
    $('#operation-schedule-fields').prop('hidden',!['schedule','reschedule'].includes(action));
    if (action === 'status') {
      const select = $('#operation-status').empty().append($('<option>').val('').text('Choose next status'));
      record.allowed_statuses.forEach(code => select.append($('<option>').val(code).text(U.statusNames[code] + (terminalStatuses.includes(code) ? ' (final)' : ''))));
      if (draft?.status) select.val(draft.status);
    }
    if (['schedule','reschedule'].includes(action)) {
      $('#operation-location').val(draft?.location ?? record.pickup_location_id ?? '');
      $('#operation-date').attr('min', localInput(new Date())).val(draft?.date ?? (record.scheduled_at ? localInput(record.scheduled_at) : nextWorkingSlot()));
    }
    const early = action === 'complete' && record.scheduled_at && new Date(record.scheduled_at) > new Date();
    let intro = {need_more_info:'The claimant receives your note and can update this same claim. It stays open until they reply and you decide.', status:'The reporter is notified of the new status. While a report is Under review, its reporter cannot edit it.', schedule:'The claimant is notified and sees the pickup time and place in My Activity.', reschedule:'The claimant is notified of the new time and place, with your reason.'}[action] || 'Check the selected record and your note before saving.';
    if (action === 'approved') {
      intro = 'Approval confirms ownership, creates one pickup to arrange and closes competing active claims.';
    } else if (action === 'complete') {
      intro = (early ? 'The appointment is on ' + U.date(record.scheduled_at,true) + '. Confirm only if the owner already collected the item early. ' : '') + 'Confirm only after the item has been handed to this verified owner. Linked reports become Returned.';
    } else if (action === 'release') {
      intro = 'Use this when the owner never collects the item or ownership must be withdrawn. The claim is closed as rejected, and the report and any linked Lost report become available again.';
    } else if (action === 'cancel_schedule') {
      intro = 'The appointment will be cancelled. Ownership stays approved and the pickup can be scheduled again.';
    } else if (action === 'rejected') {
      intro = 'A rejection is final: the claimant cannot claim this item again.';
    }
    $('#operation-intro').text(intro);
    $('#operation-confirm-label').text(action === 'complete' ? 'I confirm this item was physically returned to the verified claimant.' : action === 'status' ? 'I understand this status is final and the report becomes read-only.' : 'I reviewed this record and confirm the decision.');
    $('#operation-confirm-row').prop('hidden', !needsConfirm(action));
    $('#operation-submit').prop('disabled', needsConfirm(action) || action === 'status' && !$('#operation-status').val());
    if (action === 'status') updateStatusConfirm();
    operation.initial = operationValues();
    document.querySelector('#operation-dialog').showModal();
    $('#operation-note').trigger('focus');
  }
  // Leaving the page with an open, changed decision dialog asks first; a deliberate redirect sets `leaving`.
  const operationValues = () => JSON.stringify([$('#operation-note').val(), $('#operation-location').val(), $('#operation-date').val(), $('#operation-status').val()]);
  let leaving = false;
  addEventListener('beforeunload', event => { if (!leaving && operation && document.querySelector('#operation-dialog')?.open && operationValues() !== operation.initial) { event.preventDefault(); event.returnValue = ''; } });
  function sessionEnded() { leaving = true; if (operation) U.saveDraft('bm-operation', '#operation-form', {kind: operation.kind, id: operation.id, action: operation.action}); location.replace(app.loginUrl()); }
  function rememberDraft() { if (operation) drafts.set(draftKey(operation),{note:$('#operation-note').val(),location:$('#operation-location').val(),date:$('#operation-date').val(),status:$('#operation-status').val()}); }
  function showQueue() {
    setView('queue'); const previous = selected; selected = null; current = null; pinned = false; ++detailRequest; syncUrl();
    $('#bm-selection-note').prop('hidden',true);
    const card = $('.bm-queue-card').filter(function () { return $(this).attr('data-record-id') === previous; })[0];
    if (card) U.focusRecord(card); else U.focusRecord(document.querySelector('#bm-search'));
  }
  $('#bm-back').on('click',() => { if (history.state?.bmDetail) history.back(); else showQueue(); });
  window.addEventListener('popstate', () => {
    if (saving || document.querySelector('#operation-dialog')?.open) { history.pushState({bmDetail:true}, '', location.href); return; }
    const id = new URLSearchParams(location.search).get(paramKey());
    if (history.state?.bmDetail && id) { selected = id; setView('detail'); loadDetail(true); } else showQueue();
  });
  $('#operation-status').on('change', updateStatusConfirm);
  $('#operation-confirm').on('change',function () { $('#operation-submit').prop('disabled',saving || !this.checked); });
  $('[data-close-operation]').on('click',() => { if (!saving) { rememberDraft(); document.querySelector('#operation-dialog').close(); } });
  $('#operation-dialog').on('cancel',event => { if (saving) event.preventDefault(); else rememberDraft(); });
  $('dialog').on('close',() => { if (trigger && document.contains(trigger)) trigger.focus(); });
  $('#bm-evidence-close').on('click',() => document.querySelector('#bm-evidence-dialog').close());
  $('#bm-evidence-dialog').on('close',() => $('#bm-evidence-image').removeAttr('src'));
  $('#operation-refresh').on('click',async () => { rememberDraft(); document.querySelector('#operation-dialog').close(); pinned = true; await loadQueue(page); });
  function inlineError(key, message, selector) { $('[data-bm-error="' + key + '"]').text(message).prop('hidden',false); if (selector) $(selector).trigger('focus'); }
  function validateOperation(note) {
    const action = operation.action;
    const required = operation.kind !== 'handover' || ['reschedule','cancel_schedule','release'].includes(action);
    if (required && Array.from(note).length < 8) {
      inlineError('note','Provide at least 8 characters.','#operation-note');
      return false;
    }
    if (action === 'status' && !$('#operation-status').val()) {
      inlineError('status','Choose the next status.','#operation-status');
      return false;
    }
    if (['schedule','reschedule'].includes(action)) {
      const location = $('#operation-location').val(), date = $('#operation-date').val();
      if (!location) {
        inlineError('pickup_location_id','Choose a pickup area.','#operation-location');
        return false;
      }
      if (!date) {
        inlineError('scheduled_at','Choose a pickup date and time.','#operation-date');
        return false;
      }
      if (new Date(date + ':00+07:00') <= new Date()) {
        inlineError('scheduled_at','Choose a time in the future (campus time).','#operation-date');
        return false;
      }
      if (action === 'reschedule' && String(location) === String(operation.record.pickup_location_id) && date === localInput(operation.record.scheduled_at)) {
        inlineError('scheduled_at','Choose a different time or area to reschedule.','#operation-date');
        return false;
      }
    }
    if (needsConfirm(action) && !$('#operation-confirm').prop('checked')) {
      inlineError('confirm','Tick the confirmation to continue.');
      return false;
    }
    return true;
  }
  function buildOperationRequest(note) {
    const action = operation.action;
    // Every BM mutation carries the displayed revision; a failed request is never replayed automatically.
    const values = {id:operation.id,revision:operation.revision,note,confirm:'1'};
    let endpoint = 'staff.php';
    if (operation.kind === 'claim') {
      values.action = 'review';
      values.decision = action;
    } else if (operation.kind === 'report') {
      values.action = 'status';
      values.status = $('#operation-status').val();
    } else {
      endpoint = 'handovers.php';
      values.action = action;
      if (['schedule','reschedule'].includes(action)) {
        values.pickup_location_id = $('#operation-location').val();
        values.scheduled_at = $('#operation-date').val();
      }
    }
    return {endpoint,values};
  }
  $('#operation-form').on('submit',async function (event) {
    event.preventDefault();
    if ((claimsReadOnly && !handoversAvailable && !reportsAvailable) || saving || !operation) return;
    const action = operation.action, note = $('#operation-note').val().trim();
    $('[data-bm-error],#operation-feedback').prop('hidden',true);
    if (!validateOperation(note)) return;
    const {endpoint,values} = buildOperationRequest(note);
    let next = null;
    saving = true;
    $('#operation-fields').prop('disabled',true);
    $('#operation-submit,[data-close-operation],[data-logout]').prop('disabled',true);
    $('#operation-submit').text('Saving…');
    try {
      const result = await app.request(endpoint,values);
      drafts.delete(draftKey(operation));
      document.querySelector('#operation-dialog').close();
      next = action === 'approved' && result.handover_id && handoversAvailable ? $('<button>').addClass('text-button').attr('type','button').prop('disabled',true).text('Schedule pickup now').on('click',() => openRecord('handover', result.handover_id)) : null;
      // A released pickup no longer exists, so show the closed claim instead.
      if (action === 'release') await openRecord('claims', operation.record.claim_id, true);
      else { pinned = true; await loadQueue(page,true); }
      feedback(done[action],true,next);
      await shell.refreshUnread();
    } catch (e) {
      rememberDraft();
      if (e.status === 401) { sessionEnded(); return; }
      if (e.code === 'csrf_invalid') {
        const state = await app.session().catch(() => null);
        if (state && !state.authenticated) { sessionEnded(); return; }
      }
      $('#operation-fields').prop('disabled',false);
      $('#operation-feedback').text(e.message).prop('hidden',false);
      for (const [key,message] of Object.entries(e.fields || {})) $('[data-bm-error="' + key + '"]').text(message).prop('hidden',false);
      // A failed attempt must be confirmed again before it is resent.
      $('#operation-confirm').prop('checked',false);
      $('#operation-refresh').prop('hidden',!(e.status === 409 && e.code === 'state_conflict'));
      $('#operation-feedback').trigger('focus');
    } finally {
      saving = false;
      if (next) next.prop('disabled',false);
      $('#operation-fields').prop('disabled',false);
      $('#operation-submit').prop('disabled',needsConfirm(action) && !$('#operation-confirm').prop('checked')).text(labels[action]);
      $('[data-close-operation],[data-logout]').prop('disabled',false);
    }
  });
  try {
    const state = await shell.ready; if (!state.authenticated) return;
    if (handoversAvailable || reportsAvailable) {
      refs = await app.request('references.php'); U.fillSelect('#operation-location',refs.locations,'Select pickup campus area');
    }
    setView(selected ? 'detail' : 'queue'); switchState();
    const savedStatus = params.get('status'), savedPage = Number(params.get('page'));
    if (savedStatus && $('#bm-state option').toArray().some(option => option.value === savedStatus)) $('#bm-state').val(savedStatus);
    $('#bm-search').val((params.get('q') || '').slice(0, 100));
    page = Number.isInteger(savedPage) && savedPage >= 1 && savedPage <= 100000 ? savedPage : 1;
    $('#bm-search').on('input',() => { if (saving) return; clearTimeout(timer); resetSelection(); timer = setTimeout(() => loadQueue(1),200); });
    $('#bm-state').on('change',() => { if (saving) return; clearTimeout(timer); resetSelection(); loadQueue(1); });
    $('[data-bm-tab]').on('click',async function () { if (saving) return; clearTimeout(timer); resetSelection(); tab = $(this).attr('data-bm-tab'); page = 1; $('#bm-search').val(''); switchState(); syncUrl(); await loadQueue(1); });
    $('#bm-retry').on('click',() => loadQueue(page)); U.trackReturn('admin', 'Back to Review Queue'); await loadQueue();
    U.restoreReturn('admin');
    // A decision note typed before the session ended comes back in its dialog.
    const kept = U.takeDraft('bm-operation');
    if (kept?.extra?.kind && labels[kept.extra.action]) {
      drafts.set(draftKey(kept.extra), {note: kept.fields['operation-note'] ?? '', location: kept.fields['operation-location'] ?? '', date: kept.fields['operation-date'] ?? '', status: kept.fields['operation-status'] ?? ''});
      feedback('Your session ended, so your note was kept. Open "' + labels[kept.extra.action] + '" again to continue.', true);
    }
  } catch (e) { error(e); }
});
