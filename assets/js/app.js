/* HostelEase — Material You micro-interactions (vanilla JS, no dependencies) */
(function () {
  'use strict';

  /* ---- Mobile navigation rail ---- */
  var railToggle = document.querySelector('[data-rail-toggle]');
  var scrim = document.querySelector('[data-scrim]');

  function openRail() { document.body.classList.add('rail-open'); if (scrim) scrim.classList.add('show'); }
  function closeRail() { document.body.classList.remove('rail-open'); if (scrim) scrim.classList.remove('show'); }

  if (railToggle) railToggle.addEventListener('click', function () {
    document.body.classList.contains('rail-open') ? closeRail() : openRail();
  });
  if (scrim) scrim.addEventListener('click', function () {
    closeRail();
    closeNotifs();
    closeModal();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeRail(); closeNotifs(); closeModal(); }
  });

  /* ---- Notification side sheet ---- */
  var panel = document.querySelector('[data-notif-panel]');
  var notifBtn = document.querySelector('[data-notif-toggle]');
  function openNotifs() { if (panel) { panel.classList.add('open'); if (scrim) scrim.classList.add('show'); } }
  function closeNotifs() { if (panel) { panel.classList.remove('open'); if (scrim && !document.body.classList.contains('rail-open') && !document.querySelector('.modal.open')) scrim.classList.remove('show'); } }
  if (notifBtn) notifBtn.addEventListener('click', function (e) {
    e.preventDefault();
    panel && panel.classList.contains('open') ? closeNotifs() : openNotifs();
  });

  /* ---- Modal ---- */
  var modal = document.querySelector('[data-modal]');
  function openModal() { if (modal) { modal.classList.add('open'); if (scrim) scrim.classList.add('show'); document.body.style.overflow = 'hidden'; } }
  function closeModal() {
    if (modal) { modal.classList.remove('open'); document.body.style.overflow = ''; }
    if (scrim && !document.body.classList.contains('rail-open') && !(panel && panel.classList.contains('open'))) scrim.classList.remove('show');
  }
  document.querySelectorAll('[data-open-modal]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      e.preventDefault();
      var target = b.getAttribute('data-target');
      if (target) {
        var t = document.getElementById(target);
        if (t) { var title = t.querySelector('[data-modal-title]'); if (title) title.textContent = b.getAttribute('data-title') || title.textContent; }
      }
      openModal();
    });
  });
  document.querySelectorAll('[data-close-modal]').forEach(function (b) { b.addEventListener('click', closeModal); });
  if (modal) modal.addEventListener('click', function (e) { if (e.target.classList.contains('modal-backdrop')) closeModal(); });

  /* ---- Confirm dialogs for destructive actions ---- */
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (!window.confirm(el.getAttribute('data-confirm'))) { e.preventDefault(); e.stopImmediatePropagation(); }
    });
  });

  /* ---- Password visibility toggle ---- */
  document.querySelectorAll('[data-toggle-password]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-toggle-password'));
      if (!input) return;
      var showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
      btn.style.opacity = showing ? '.55' : '1';
    });
  });

  /* ---- Auto-dismiss flash alerts ---- */
  setTimeout(function () {
    document.querySelectorAll('.flash-stack .alert').forEach(function (a) {
      a.style.transition = 'opacity .4s cubic-bezier(0.2,0,0,1), transform .4s cubic-bezier(0.2,0,0,1)';
      a.style.opacity = '0';
      a.style.transform = 'translateY(-8px)';
      setTimeout(function () { a.remove(); }, 420);
    });
  }, 6000);

  /* ---- Auto-submit filter forms when a select changes ---- */
  document.querySelectorAll('[data-auto-submit]').forEach(function (el) {
    el.addEventListener('change', function () { el.closest('form').submit(); });
  });

  /* ---- Selectable chips (visual only) ---- */
  document.querySelectorAll('[data-chip-group]').forEach(function (group) {
    group.addEventListener('click', function (e) {
      var chip = e.target.closest('.chip-selectable');
      if (!chip) return;
      group.querySelectorAll('.chip-selectable').forEach(function (c) { c.classList.remove('selected'); });
      chip.classList.add('selected');
    });
  });

  /* ---- Ripple / press feedback for cards ---- */
  document.querySelectorAll('.card-hover, .room-card, .notif-item').forEach(function (el) {
    el.addEventListener('pointerdown', function () { el.style.transform = 'scale(.985)'; });
    ['pointerup', 'pointerleave', 'pointercancel'].forEach(function (ev) {
      el.addEventListener(ev, function () { el.style.transform = ''; });
    });
  });

  /* ---- Live search box: submit on Enter, debounce on typing ---- */
  var liveSearch = document.querySelector('[data-live-search]');
  if (liveSearch) {
    var timer = null;
    liveSearch.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () { liveSearch.closest('form').submit(); }, 700);
    });
  }

  /* ---- Animate stat counters on first paint ---- */
  document.querySelectorAll('[data-count]').forEach(function (el) {
    var target = parseFloat(el.getAttribute('data-count'));
    if (isNaN(target)) return;
    var suffix = el.getAttribute('data-suffix') || '';
    var dur = 700, start = null;
    function step(ts) {
      if (!start) start = ts;
      var p = Math.min((ts - start) / dur, 1);
      var eased = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(target * eased) + suffix;
      if (p < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  });

  /* ---- Room assignment: keep bed preview in sync ---- */
  var roomSelect = document.querySelector('[data-room-select]');
  if (roomSelect) {
    var info = document.querySelector('[data-room-info]');
    roomSelect.addEventListener('change', function () {
      var opt = roomSelect.options[roomSelect.selectedIndex];
      if (info) info.textContent = opt.value ? (opt.getAttribute('data-info') || '') : 'A vacant room will be chosen automatically.';
    });
  }
})();
