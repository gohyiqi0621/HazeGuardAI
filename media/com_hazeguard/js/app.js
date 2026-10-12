/* ==========================================================================
   HazeGuard AI — Stage 1A prototype behaviour
   Screen router, form validation, demo interactions.

   Real backend calls live in api.js and are reached through `window.HazeAPI`:
   authentication is live end to end, and the air-quality reading is served by
   the PHP controller. Everything else on these screens is still simulated, and
   each simulated result is labelled in the UI as demo data.
   ========================================================================== */
'use strict';

/* ---------------------------------------------------------------- router -- */
const SCREENS = {
  'landing':        { kind: 'page', title: 'HazeGuard AI — Understand the air. Plan smarter. Breathe better.' },
  'login':          { kind: 'auth', title: 'Sign in Â· HazeGuard AI' },
  'register':       { kind: 'auth', title: 'Create account Â· HazeGuard AI' },
  'otp':            { kind: 'auth', title: 'Verify your email Â· HazeGuard AI' },
  'verify-result':  { kind: 'auth', title: 'Verification result Â· HazeGuard AI' },
  'forgot':         { kind: 'auth', title: 'Reset your password Â· HazeGuard AI' },
  'reset':          { kind: 'auth', title: 'Choose a new password Â· HazeGuard AI' },
  'dashboard':      { kind: 'app', title: 'Dashboard Â· HazeGuard AI' },
  'radar':          { kind: 'app', title: 'Haze Radar Â· HazeGuard AI' },
  'planner':        { kind: 'app', title: 'AI Activity Planner Â· HazeGuard AI' },
  'chat':           { kind: 'app', title: 'BreatheBuddy Â· HazeGuard AI' },
  'history':        { kind: 'app', title: 'Air quality history Â· HazeGuard AI' },
  'profile':        { kind: 'app', title: 'Profile & settings Â· HazeGuard AI' },
};

let currentScreen = null;
let currentRoute = null; // the whole hash, not just the screen name
let sessionReady = false; // true once the server has told us who we are

/* The hash carries the screen name. A "?" suffix is stripped rather than
   matched, so a link from before the reset flow moved to codes — "#reset?token=…"
   — still lands on the screen instead of a name that matches nothing. */
function parseHash() {
  const raw = location.hash.slice(1) || 'landing';
  const cut = raw.indexOf('?');
  return cut === -1 ? raw : raw.slice(0, cut);
}

function render(name) {
  // Guard on the whole hash, not just the screen name. The two differ for an
  // old "#reset?token=…" link, and re-rendering on that difference is what turns
  // a dead link into the "request a code" explanation instead of silence.
  const route = location.hash.slice(1) || 'landing';
  if (!SCREENS[name] || route === currentRoute) return;
  const info = SCREENS[name];

  // Protected routes: app screens require a signed-in session (T1B.5 guard).
  if (info.kind === 'app' && sessionReady && !currentUser) {
    toast('Please sign in to continue.', 'warn');
    navigate('login');
    return;
  }

  document.querySelectorAll('.screen').forEach((s) => { s.hidden = s.id !== 'screen-' + name; });
  document.body.dataset.kind = info.kind;
  document.body.dataset.screen = name;
  document.getElementById('shell').hidden = info.kind !== 'app';

  // active navigation state (bottom bar + sidebar)
  document.querySelectorAll('[data-nav]').forEach((btn) => {
    const active = btn.dataset.nav === name;
    btn.classList.toggle('is-active', active);
    if (active) btn.setAttribute('aria-current', 'page');
    else btn.removeAttribute('aria-current');
  });

  document.title = info.title;
  currentScreen = name;
  currentRoute = route;
  window.scrollTo(0, 0);

  if (name === 'chat') scrollChat();
  if (name === 'otp') { startEmailOtpCountdown(); $$('.otp-box').forEach((b) => { b.value = ''; }); clearError('email-otp-code'); }
  if (name === 'forgot') $('#form-forgot').hidden = false;
  if (name === 'reset') showResetState();
  if (name === 'dashboard') drawDashboardChart();
  if (name === 'history') drawHistory();
  if (info.kind === 'app') renderUser(currentUser); // identity is per-session, not per-screen
  onEnterScreen(name);
}

/**
 * The per-screen work that needs a signed-in session.
 *
 * Called from render() AND from the session boot. That second call is not
 * optional: render() runs for the initial screen *before* the session is known,
 * so on a direct load of #profile it ran with `currentUser` still null and
 * loadPreferences() returned without doing anything. Re-calling render() would
 * not help either — it bails on `route === currentRoute`, having already marked
 * the screen rendered.
 */
function onEnterScreen(name) {
  if (name === 'profile' && currentUser) {
    loadPreferences();
  }
  // The radar builds its map on entry, not at page load: Leaflet measures its
  // container, and a screen that is still `hidden` has no size.
  if (name === 'radar' && window.HazeRadar) {
    window.HazeRadar.enter();
  }
}

/**
 * A session began in the middle of the page, so the card has to change whose air
 * it is reading.
 *
 * Signing in does not reload, so nothing else would move the card off the device
 * reading it uses while signed out. The state is cleared first because whatever
 * was painted belonged to whoever was signed in before — or to nobody — and then
 * the device's coordinates are written into this account (rule 1) and the card
 * handed over to the saved row (rule 3).
 */
function beginSession() {
  savedLocation = null;
  locationSync = null;
  syncLocationFromDevice();
}

function navigate(name) {
  if (!SCREENS[name]) return;
  if (location.hash.slice(1) === name) render(name);
  else location.hash = name; // hashchange drives render → back/forward works
}

window.addEventListener('hashchange', () => render(parseHash()));

// Surface errors instead of failing silently: any uncaught exception or
// unhandled promise rejection reaches the user as a descriptive toast.
window.addEventListener('error', (e) => {
  if (e.error) toast('Something went wrong. Please reload and try again.', 'warn');
});
window.addEventListener('unhandledrejection', (e) => {
  const r = e.reason;
  if (r && r.code === 'network_error') {
    toast(r.message || 'Could not reach the server. Check your connection.', 'warn');
  } else if (r && r.message) {
    toast(r.message, 'warn');
  } else {
    toast('Something went wrong. Please try again.', 'warn');
  }
});

/* ------------------------------------------------------------- utilities -- */
// Splash: shown by the markup on initial load only; fade out once the page
// (fonts, images) is actually ready — or immediately if it already is.
(function dismissSplash() {
  const splash = document.getElementById('splash');
  if (!splash) return;
  const hide = () => {
    splash.classList.add('is-leaving');
    splash.addEventListener('transitionend', () => splash.remove(), { once: true });
    setTimeout(() => splash.remove(), 600); // reduced-motion: transitionend may not fire
  };
  if (document.readyState === 'complete') hide();
  else window.addEventListener('load', hide, { once: true });
})();

// Data saver: the decorative loop behind the landing and auth screens is ~12 MB,
// which is wallpaper nobody on a metered plan asked for. Drop the element while
// the request is still in flight — the stage's static frame is what shows.
(function bgVideoForDataSaver() {
  const connection = navigator.connection;
  if (!connection || !connection.saveData) return;
  const video = document.querySelector('.bg-video');
  if (video) video.remove();
})();

const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

function toast(message, variant = 'ok') {
  const region = $('#toast-region');
  const el = document.createElement('div');
  el.className = 'toast liquid-glass-strong' + (variant === 'warn' ? ' toast--warn' : '');
  const iconId = variant === 'warn' ? '#i-info' : '#i-check';
  el.innerHTML = `<svg class="icon" aria-hidden="true"><use href="${iconId}"/></svg><span></span>`;
  el.lastElementChild.textContent = message;
  region.appendChild(el);
  setTimeout(() => {
    el.classList.add('is-leaving');
    el.addEventListener('animationend', () => el.remove(), { once: true });
  }, 3400);
  while (region.children.length > 3) region.firstElementChild.remove();
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const USERNAME_RE = /^[a-zA-Z0-9_]{3,20}$/;

function setError(inputId, message) {
  const input = document.getElementById(inputId);
  const box = document.querySelector(`[data-error-for="${inputId}"]`);
  if (input) input.setAttribute('aria-invalid', 'true');
  if (box) { box.textContent = message; box.hidden = false; }
}
function clearError(inputId) {
  const input = document.getElementById(inputId);
  const box = document.querySelector(`[data-error-for="${inputId}"]`);
  if (input) input.removeAttribute('aria-invalid');
  if (box) { box.hidden = true; box.textContent = ''; }
}

const VALIDATORS = {
  'login-email':      (v) => EMAIL_RE.test(v) || 'Enter a valid email address, like name@example.com.',
  'otp-email':        (v) => EMAIL_RE.test(v) || 'Enter a valid email address, like name@example.com.',
  'reg-email':        (v) => EMAIL_RE.test(v) || 'Enter a valid email address, like name@example.com.',
  'forgot-email':     (v) => EMAIL_RE.test(v) || 'Enter a valid email address, like name@example.com.',
  'reset-email':      (v) => EMAIL_RE.test(v) || 'Enter a valid email address, like name@example.com.',
  'login-username':   (v) => USERNAME_RE.test(v) || 'Username must be 3–20 letters, numbers or underscores.',
  'reg-username':     (v) => USERNAME_RE.test(v) || 'Username must be 3–20 letters, numbers or underscores.',
  'login-email-pw':   (v) => v.length > 0 || 'Enter your password.',
  'login-username-pw':(v) => v.length > 0 || 'Enter your password.',
  'reg-password':     (v) => v.length >= 8 || 'Use at least 8 characters.',
  'reset-password':   (v) => v.length >= 8 || 'Use at least 8 characters.',
  'otp-code':         (v) => /^\d{6}$/.test(v) || 'Enter the 6-digit code.',
  'reset-code':       (v) => /^\d{6}$/.test(v) || 'Enter the 6-digit code.',
'chg-current':      (v) => v.length > 0 || 'Enter your current password.',
'chg-new':          (v) => v.length >= 8 || 'Use at least 8 characters.',
'del-password':     (v) => v.length > 0 || 'Enter your password.',
};

function validateField(input) {
  const isConfirm = input.id === 'reg-confirm' || input.id === 'reset-confirm'
    || input.id === 'chg-confirm';
  const check = VALIDATORS[input.id];
  if (!check && !isConfirm) return true;
  let result = check ? check(input.value.trim()) : true;
  if (result === true && isConfirm) {
    const source = document.getElementById(
      input.id === 'reg-confirm' ? 'reg-password'
        : input.id === 'reset-confirm' ? 'reset-password'
        : 'chg-new'
    );
    if (input.value !== source.value) result = 'Passwords don’t match — retype the confirmation.';
  }
  if (result === true) { clearError(input.id); return true; }
  setError(input.id, result);
  return false;
}

// inline validation: check on blur, clear as soon as the user fixes it
$$('.screen--auth input:not([type="checkbox"]):not(.otp-box)').forEach((input) => {
  input.addEventListener('blur', () => { if (input.value.trim() !== '') validateField(input); });
  input.addEventListener('input', () => { if (input.getAttribute('aria-invalid') === 'true') validateField(input); });
});

function validateForm(form, ids) {
  let firstBad = null;
  ids.forEach((id) => {
    const input = document.getElementById(id);
    if (input && !validateField(input) && !firstBad) firstBad = input;
  });
  if (firstBad) firstBad.focus();
  return !firstBad;
}

/* ------------------------------------------------------- password toggles - */
$$('[data-toggle-password]').forEach((btn) => {
  btn.addEventListener('click', () => {
    const input = document.getElementById(btn.dataset.togglePassword);
    const show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    btn.querySelector('use').setAttribute('href', show ? '#i-eye-off' : '#i-eye');
  });
});

/* ----------------------------------------------------------- tab controls - */
function wireTabs(scopeSel, btnAttr, panelAttr) {
  $$(scopeSel).forEach((scope) => {
    $$(`[${btnAttr}]`, scope).forEach((btn) => {
      btn.addEventListener('click', () => {
        const value = btn.getAttribute(btnAttr);
        $$(`[${btnAttr}]`, scope).forEach((b) => {
          const on = b === btn;
          b.classList.toggle('is-active', on);
          b.setAttribute('aria-selected', String(on));
        });
        $$(`[${panelAttr}]`, scope).forEach((p) => { p.hidden = p.getAttribute(panelAttr) !== value; });
      });
    });
  });
}
wireTabs('#screen-login', 'data-tab', 'data-tabpanel');

/* --------------------------------------------------------------- countdown */
/* The counter and its resend button are siblings in one `.otp-actions` row, so
   the button is found by looking beside the counter rather than by matching id
   pairs. That is what lets a third countdown be added without touching this. */
function makeCountdown(root) {
  const secondsEl = $('[data-otp-seconds]', root);
  const resendBtn = $('[data-resend]', root.closest('.otp-actions'));
  let timer = null;
  return {
    start(seconds = 45) {
      clearInterval(timer);
      root.hidden = false;
      if (resendBtn) resendBtn.hidden = true;
      secondsEl.textContent = seconds;
      timer = setInterval(() => {
        seconds -= 1;
        secondsEl.textContent = seconds;
        if (seconds <= 0) {
          clearInterval(timer);
          root.hidden = true;
          if (resendBtn) resendBtn.hidden = false;
        }
      }, 1000);
    },
  };
}
const otpCountdown = makeCountdown($('#otp-countdown'));
const emailCountdown = makeCountdown($('#email-otp-countdown'));
const resetCountdown = makeCountdown($('#reset-countdown'));
$('#otp-resend').addEventListener('click', async () => {
  try {
    await HazeAPI.requestLoginOtp(pendingEmail);
    otpCountdown.start();
    toast('If that address has an account, a new code is on its way.');
  } catch (err) {
    if (err.code === 'no_account') {
      toast(err.message, 'warn');
      $('#reg-email').value = pendingEmail;
      navigate('register');
      return;
    }
    toast(err.message, 'warn');
  }
});
$('#reset-resend').addEventListener('click', async () => {
  // Reads the field, not the remembered value: after a reload the variable is
  // empty, and a resend that silently asked for a code against no address would
  // leave the visitor waiting for mail that can never arrive.
  const email = $('#reset-email') ? $('#reset-email').value.trim() : '';
  if (email === '') { setError('reset-email', 'Enter the email the code was sent to.'); return; }
  try {
    // Same endpoint as the first request, and the same answer either way: the
    // server does not say whether the address exists.
    await HazeAPI.requestPasswordReset(email);
    pendingResetEmail = email;
    resetCountdown.start();
    toast('If that address has an account, a new code is on its way.');
  } catch (err) {
    toast(err.message, 'warn');
  }
});
$('#email-otp-resend').addEventListener('click', async () => {
  try {
    await HazeAPI.resendVerification(pendingEmail);
    emailCountdown.start();
    toast('If that address needs verifying, a new code is on its way.');
  } catch (err) {
    toast(err.message, 'warn');
  }
});
function startEmailOtpCountdown() { emailCountdown.start(); }

/* ------------------------------------------------------------ auth flows -- */
// Live authentication against the PHP API. `currentUser` mirrors the server
// session; `pendingEmail` carries the address across the register → verify and
// request-code → verify steps, and `pendingResetEmail` across forgot → reset.
// Both live only in memory, so a reload sends the visitor back to the start of
// the step rather than leaving a form that cannot succeed.
let currentUser = null;
let pendingEmail = '';
let pendingResetEmail = '';

/* Where the account reads its air. Written on every load when signed in, so it
   follows the person rather than drifting behind them, and rewritten whenever a
   different city is chosen. `locationSync` makes that a single job even when the
   boot and a screen entry ask for it at once. */
let savedLocation = null;
let locationSync = null;

/* ----------------------------------------------------- signed-in identity -- */
// The one place the session's identity reaches the DOM. `currentUser` is the
// server's answer — id, username, email, verified — so nothing here invents a
// name or a verification state. Runs on session boot, on entering an app
// screen, and after logout.
function initials(username) {
  const parts = String(username || '').split(/[\s._-]+/).filter(Boolean);
  const letters = parts.length > 1 ? parts[0][0] + parts[1][0] : String(username || '').slice(0, 2);
  return letters.toUpperCase() || '—';
}

function daypart() {
  const h = new Date().getHours();
  if (h < 12) return 'Good morning';
  if (h < 18) return 'Good afternoon';
  return 'Good evening';
}

function renderUser(user) {
  const fill = (sel, text) => $$(sel).forEach((el) => { el.textContent = text; });
  fill('[data-user-initials]', user ? initials(user.username) : '—');
  fill('[data-user-name]', user ? user.username : '—');
  fill('[data-user-handle]', user ? user.username : '—');
  fill('[data-user-email]', user ? user.email : '—');
  fill('[data-user-daypart]', daypart());

  const badge = $('[data-user-badge]');
  if (badge) badge.hidden = !user;

  const pill = $('[data-user-verified]');
  if (pill) {
    // A session cookie minted before the server started reporting this field
    // carries no `verified`. Say nothing rather than claim the wrong thing.
    const known = !!user && typeof user.verified === 'boolean';
    pill.hidden = !known;
    if (known) {
      pill.textContent = user.verified ? 'Verified' : 'Not verified';
      pill.className = 'pill' + (user.verified ? ' pill--ok' : '');
    }
  }
}

/* ------------------------------------------------------------------ profile ---
   The preferences that actually persist: the activity chips and the preferred
   location. Notifications are NOT here — nothing delivers them, so a stored
   "daily summary: on" would be a promise the app cannot keep. Those switches are
   disabled in the markup rather than saved and ignored. */

function paintPreferences(prefs) {
  const chosen = prefs.activities || [];

  $$('#pref-activities input[type="checkbox"]').forEach((box) => {
    box.checked = chosen.indexOf(box.value) !== -1;
  });

  const loc = prefs.location || null;
  savedLocation = loc;

  const nameEl = $('[data-pref-location-name]');
  const coordsEl = $('[data-pref-location-coords]');
  const noteEl = $('[data-pref-location-note]');

  if (nameEl) {
    // Never print a name we were not given, and never leave the row sitting on
    // "Locating…": a state that only ever changes on success is a state that can
    // stick forever. Detection sets its own pending label and clears it.
    nameEl.textContent = loc ? (loc.name || 'Unnamed place') : 'Not set';
  }

  if (coordsEl) {
    // Small type, and only when there is something to say. Printed as sent rather
    // than re-formatted, so the screen and the stored row cannot disagree.
    coordsEl.textContent = loc ? `${Number(loc.lat).toFixed(4)}, ${Number(loc.lon).toFixed(4)}` : '';
    coordsEl.hidden = !loc;
  }

  if (noteEl) {
    noteEl.textContent = loc
      ? `Readings use ${loc.name}. Saved to your account and refreshed to your current position on each load.`
      : 'No saved location yet. Allow location access and your readings will follow where you are.';
  }
}

/* --------------------------------------------------------------- places ----
   There is one location, and it lives in the account. Three rules follow:

     1. Every load re-detects the device and writes those coordinates back, so
        the readings track the person instead of the last place they set.
     2. Picking a city from Change — or pressing relocate — writes that too, and
        the card re-reads immediately.
     3. Signed in, the card reads the saved row; signed out there is no row to
        read, so it reads whatever the browser reports.

   Signed out nothing is written anywhere, because there is nowhere to write it
   to; airquality.js simply sends its own coordinates. */

/** How long after the last keystroke the search fires. */
const PLACE_SEARCH_DELAY = 300;

/* navigator.geolocation rejects with a GeolocationPositionError, whose `code`
   is numeric: 1 = PERMISSION_DENIED, 2 = POSITION_UNAVAILABLE, 3 = TIMEOUT. Only
   a denial is worth its own sentence — an unavailable GPS is a different problem
   with the same remedy, and both leave the saved city untouched. */
const GEO_DENIED = 1;

/* Debounce timer and sequence guard, one pair per screen. Sharing them between
   the two would mean typing in one search box abandoned the other's request. */
const placeState = {};

function placeSearch(key) {
  if (!placeState[key]) placeState[key] = { timer: null, seq: 0 };
  return placeState[key];
}

/**
 * The place search, shared by the profile screen and the Haze Radar.
 *
 * Two screens, one widget. The debounce, the sequence guard, the request, the
 * rendered rows and the keyboard navigation are identical; only the consequence
 * of picking differs — the profile screen *saves* the place, the radar *moves the
 * map* to it. So all of that lives here once and each screen supplies only its
 * elements and its `onPick`. A second copy of this list would drift from the
 * first within one bug fix.
 */
const PLACE_SEARCHES = {
  profile: {
    input: '#place-query',
    list: '#place-results',
    status: '[data-place-status]',
    onPick: choosePlace,
  },
  radar: {
    input: '#radar-search',
    list: '#radar-results',
    status: '#radar-place-status',
    onPick: goToRadarPlace,
  },
};

function placeStatus(key, text) {
  const el = $(PLACE_SEARCHES[key].status);
  if (el) el.textContent = text || '';
}

/** The list is a combobox popup, so it has to say whether it is open. */
function setPlaceListOpen(key, open) {
  const cfg = PLACE_SEARCHES[key];
  const list = $(cfg.list);
  const input = $(cfg.input);
  if (list) list.hidden = !open;
  if (input) input.setAttribute('aria-expanded', open ? 'true' : 'false');
}

function renderPlaceResults(key, places, query) {
  const list = $(PLACE_SEARCHES[key].list);
  if (!list) return;
  list.innerHTML = '';

  if (!places.length) {
    setPlaceListOpen(key, false);
    placeStatus(key, query ? `No place matched “${query}â€.` : '');
    return;
  }
  setPlaceListOpen(key, true);
  placeStatus(key, '');

  places.forEach((place, index) => {
    const item = document.createElement('li');
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'place-list__item';
    button.setAttribute('role', 'option');
    button.setAttribute('aria-selected', 'false');
    button.dataset.lat = place.lat;
    button.dataset.lon = place.lon;
    button.dataset.name = place.name;

    const name = document.createElement('span');
    name.className = 'place-list__name';
    name.textContent = place.name;

    const meta = document.createElement('span');
    meta.className = 'place-list__meta';
    meta.textContent = [place.country, `${Number(place.lat).toFixed(3)}, ${Number(place.lon).toFixed(3)}`]
      .filter(Boolean).join(' Â· ');

    button.append(name, meta);
    item.appendChild(button);
    list.appendChild(item);

    // Keyboard: the first result is active as soon as the list appears, so a
    // keyboard user can press Enter without tabbing through every row first.
    if (index === 0) button.setAttribute('aria-selected', 'true');
  });
}

async function runPlaceSearch(key) {
  const input = $(PLACE_SEARCHES[key].input);
  if (!input) return;
  const query = input.value.trim();

  if (query.length < 2) {
    renderPlaceResults(key, [], query);
    return;
  }

  // Sequence guard: a slow request for "Kuala" must not overwrite the results of
  // a newer one for "Kuala Lumpur".
  const state = placeSearch(key);
  const seq = ++state.seq;
  placeStatus(key, 'Searching…');

  try {
    const data = await HazeAPI.searchPlaces(query);
    if (seq !== state.seq) return;
    renderPlaceResults(key, data.places || [], query);
  } catch (err) {
    if (seq !== state.seq) return;
    placeStatus(key, 'Could not search for places. Try again.');
  }
}

function openPlaceSearch() {
  showPanel('place-search');
  const input = $(PLACE_SEARCHES.profile.input);
  if (input) {
    input.focus();
    input.value = '';
    renderPlaceResults('profile', [], '');
  }
}

function closePlaceSearch() {
  hidePanel('place-search');
  const state = placeSearch('profile');
  state.seq++;                 // abandon anything still in flight
  clearTimeout(state.timer);
  const input = $(PLACE_SEARCHES.profile.input);
  if (input) input.value = '';
  renderPlaceResults('profile', [], '');
}

/** Save the place the visitor picked. */
async function choosePlace(name, lat, lon) {
  clearError('place-query');
  try {
    // Coordinates only: the server resolves the name with the same geocoder the
    // reading uses, so the stored city is never a label the client invented.
    const data = await HazeAPI.saveLocation({ lat: Number(lat), lon: Number(lon) });
    closePlaceSearch();

    paintPreferences({ activities: currentActivities(), location: data.location });
    HazeAir.usePreferredLocation();

    toast(`Preferred location set to ${data.location.name}. Readings now use it.`);
  } catch (err) {
    setError('place-query', (err && err.message) || 'Could not save that place. Try another.');
  }
}

/**
 * Write where the browser says we are into the account, then re-read the air.
 *
 * This is rule 1. It is held behind one promise for the life of the page so the
 * session boot and the profile screen entering at the same time share a single
 * detection and a single save, instead of racing two writes against each other.
 *
 * Signed out it does nothing: there is no row to write to, and airquality.js is
 * already reading the device coordinates directly (rule 3).
 */
async function syncLocationFromDevice() {
  if (!currentUser) return false;

  if (!locationSync) locationSync = saveDeviceLocation();
  return locationSync;
}

async function saveDeviceLocation() {
  const nameEl = $('[data-pref-location-name]');
  const coordsEl = $('[data-pref-location-coords]');
  const noteEl = $('[data-pref-location-note]');

  clearError('pref-location');

  // No Geolocation API: there is nothing to write, so the card is switched over
  // to the saved row rather than left showing the boot's device guess.
  if (!window.HazeAir) {
    if (noteEl) noteEl.textContent = 'This browser cannot detect a location. Use Change to search for a city.';
    return false;
  }

  if (nameEl) nameEl.textContent = 'Locating…';
  if (noteEl) noteEl.textContent = 'Asking your browser where you are…';

  let coords;
  try {
    coords = await window.HazeAir.locate();
  } catch (err) {
    // A refusal is not something the app can fix, and the saved row is still
    // valid — so the card keeps reading it, and only the note and the inline
    // error explain why the number did not move.
    const denied = err && err.code === GEO_DENIED;
    paintPreferences({ activities: currentActivities(), location: savedLocation });
    if (noteEl) {
      noteEl.textContent = (denied ? 'Your browser would not share a location. ' : 'Could not detect a location. ')
        + stillReading()
        + 'Use Change to pick a city.';
    }
    setError('pref-location', denied ? 'Location access was declined.' : 'Could not detect your location.');
    await window.HazeAir.usePreferredLocation(true);
    return false;
  }

  const lat = Number(coords.lat.toFixed(4));
  const lon = Number(coords.lon.toFixed(4));
  if (coordsEl) {
    coordsEl.textContent = `${lat.toFixed(4)}, ${lon.toFixed(4)}`;
    coordsEl.hidden = false;
  }

  try {
    // Coordinates only. The server names the place with the same geocoder the
    // reading uses, so this screen and the card cannot disagree about the city.
    const data = await HazeAPI.saveLocation({ lat, lon });
    savedLocation = data.location;
    paintPreferences({ activities: currentActivities(), location: data.location });
    // Re-reads against the row just written, which is what updates the card.
    await window.HazeAir.usePreferredLocation(true);
    return true;
  } catch (err) {
    paintPreferences({ activities: currentActivities(), location: savedLocation });
    if (noteEl) noteEl.textContent = `Could not save your location. ${stillReading()}`;
    setError('pref-location', 'Could not save your location.');
    // The row did not move, but the card must still read it rather than the
    // device guess it has been using since the page loaded.
    await window.HazeAir.usePreferredLocation(true);
    return false;
  }
}

/**
 * The second half of a failure note: say honestly what the card is reading now.
 *
 * A first-time visitor with nothing saved has no city to name, and claiming
 * readings "still use" one would be untrue — the server falls back to its own
 * default city instead.
 */
function stillReading() {
  return savedLocation
    ? `Readings still use ${savedLocation.name}.`
    : 'Nothing is saved yet, so readings use the default city.';
}

async function loadPreferences() {
  if (!currentUser) return;
  try {
    const prefs = await HazeAPI.getPreferences();

    // Paint first so the screen is never blank while detecting. If the sync
    // below saves newer coordinates it repaints over this, so the row cannot be
    // left showing a city the reading has already moved off.
    paintPreferences(prefs);

    // Signed in, the card reads the saved row from here on — set before the
    // detection rather than after, so a refused permission degrades to this
    // saved city instead of to the boot's device guess.
    if (window.HazeAir) await window.HazeAir.usePreferredLocation(true);
  } catch (err) {
    toast('Could not load your saved preferences.', 'warn');
    return;
  }

  // Rule 1. Shares the session boot's detection and save through locationSync.
  await syncLocationFromDevice();
}

$('#pref-activities').addEventListener('change', async (e) => {
  const box = e.target;
  if (!box || box.type !== 'checkbox') return;

  const group = $$('#pref-activities input[type="checkbox"]');
  const chosen = group.filter((b) => b.checked).map((b) => b.value);

  try {
    const data = await HazeAPI.saveActivities(chosen);
    paintPreferences(data);
    clearError('pref-activities');
  } catch (err) {
    // Put the chip back the way the server still has it. Ticking a box that then
    // silently fails to save is worse than a visible error.
    const stored = await HazeAPI.getPreferences().catch(() => null);
    if (stored) paintPreferences(stored); else box.checked = !box.checked;
    showApiError(err, { activities: 'pref-activities' });
  }
});

$('#form-change-password').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!validateForm(e.target, ['chg-current', 'chg-new', 'chg-confirm'])) return;

  const current = $('#chg-current').value;
  const next = $('#chg-new').value;
  if ($('#chg-confirm').value !== next) {
    setError('chg-confirm', 'Passwords don’t match — retype the confirmation.');
    return;
  }

  busy(e.target, true);
  try {
    await HazeAPI.changePassword(current, next);
    e.target.reset();
    hidePanel('password');
    toast('Password updated.');
  } catch (err) {
    showApiError(err, { current_password: 'chg-current', password: 'chg-new' });
  } finally {
    busy(e.target, false);
  }
});

$('#form-delete-account').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!validateForm(e.target, ['del-password'])) return;

  busy(e.target, true);
  try {
    await HazeAPI.deleteAccount($('#del-password').value);
    // The server destroyed the session with the account, so the client drops its
    // copy too rather than pretending a signed-out visitor is still signed in.
    e.target.reset();
    hidePanel('delete');
    currentUser = null;
    renderUser(null);
    toast('Your account has been deleted.');
    navigate('landing');
  } catch (err) {
    showApiError(err, { password: 'del-password' });
  } finally {
    busy(e.target, false);
  }
});

function showPanel(name) { $(`[data-panel="${name}"]`).hidden = false; }
function hidePanel(name) { $(`[data-panel="${name}"]`).hidden = true; }

/**
 * The target-icon button: read where the visitor is now and make it the
 * preference, immediately.
 *
 * The load does this too, so the button exists for the case that matters —
 * someone opened the page, then moved. It forces a fresh detection rather than
 * awaiting the one the load shared, because the whole point is that the answer
 * has changed.
 */
async function relocateToCurrentPosition() {
  const btn = $('[data-action="relocate-location"]');
  // Disabled while in flight: a second tap would start a second detection and
  // two saves racing each other.
  if (btn) btn.disabled = true;

  try {
    locationSync = null;
    const saved = await syncLocationFromDevice();
    if (saved) toast(`Location set to ${savedLocation.name}. Readings now use it.`);
    else toast('Location was not updated.', 'warn');
  } finally {
    if (btn) btn.disabled = false;
  }
}

function currentActivities() {
  return $$('#pref-activities input[type="checkbox"]').filter((b) => b.checked).map((b) => b.value);
}

/* The search box, wired once per screen: debounced input, click to pick, and
   arrow keys to move. One delegated listener each rather than one per result,
   because the results are rebuilt on every search. */
Object.keys(PLACE_SEARCHES).forEach((key) => {
  const cfg = PLACE_SEARCHES[key];
  const input = $(cfg.input);
  const list = $(cfg.list);
  if (!input || !list) return;

  input.addEventListener('input', () => {
    clearError(cfg.input.slice(1));   // '#place-query' -> 'place-query'
    const state = placeSearch(key);
    clearTimeout(state.timer);
    state.timer = setTimeout(() => runPlaceSearch(key), PLACE_SEARCH_DELAY);
  });

  list.addEventListener('click', (e) => {
    const item = e.target.closest('.place-list__item');
    if (item) cfg.onPick(item.dataset.name, item.dataset.lat, item.dataset.lon);
  });

  input.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      // Only intercept once there is a list to move through: with no results,
      // ArrowDown should still let the caret move.
      if (!list.children.length) return;
      e.preventDefault();
      moveActiveOption(key, e.key === 'ArrowDown' ? 1 : -1);
      return;
    }

    if (e.key === 'Enter') {
      const items = $$(cfg.list + ' .place-list__item');
      if (!items.length) return;
      e.preventDefault();
      // Enter picks whatever is active; if none is, the first result.
      const active = items.find((i) => i.getAttribute('aria-selected') === 'true') || items[0];
      cfg.onPick(active.dataset.name, active.dataset.lat, active.dataset.lon);
    }
  });
});

/** Move the active option, wrapping at both ends. Returns the new index. */
function moveActiveOption(key, step) {
  const items = $$(PLACE_SEARCHES[key].list + ' .place-list__item');
  if (!items.length) return -1;

  const current = items.findIndex((i) => i.getAttribute('aria-selected') === 'true');
  const next = (current + step + items.length) % items.length;

  items.forEach((i, idx) => i.setAttribute('aria-selected', idx === next ? 'true' : 'false'));
  items[next].focus();
  return next;
}

function showApiError(err, fieldMap) {
  const fields = err.fields || {};
  let shown = false;
  Object.keys(fields).forEach((key) => {
    const inputId = fieldMap[key];
    if (inputId) { setError(inputId, fields[key]); shown = true; }
  });
  if (!shown) toast(err.message, 'warn');
}

function busy(form, on) {
  const btn = form.querySelector('button[type="submit"]');
  if (btn) btn.disabled = on;
}

$('#panel-login-email').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!validateForm(e.target, ['login-email', 'login-email-pw'])) return;
  busy(e.target, true);
  try {
    const data = await HazeAPI.login($('#login-email').value.trim(), $('#login-email-pw').value);
    currentUser = data.user;
    pendingEmail = '';
    toast(`Signed in as ${data.user.username}.`);
    beginSession();
    navigate('dashboard');
  } catch (err) {
    showApiError(err, { identifier: 'login-email', password: 'login-email-pw' });
    if (err.code === 'email_not_verified') {
      pendingEmail = $('#login-email').value.trim();
      toast('Verify your email first — switch to “Email codeâ€ to get a new one.', 'warn');
    }
  } finally {
    busy(e.target, false);
  }
});

$('#panel-login-username').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!validateForm(e.target, ['login-username', 'login-username-pw'])) return;
  busy(e.target, true);
  try {
    const data = await HazeAPI.login($('#login-username').value.trim(), $('#login-username-pw').value);
    currentUser = data.user;
    pendingEmail = '';
    toast(`Signed in as ${data.user.username}.`);
    beginSession();
    navigate('dashboard');
  } catch (err) {
    showApiError(err, { identifier: 'login-username', password: 'login-username-pw' });
  } finally {
    busy(e.target, false);
  }
});

// method C, step 1: request a sign-in code
$('#form-otp-request').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!validateForm(e.target, ['otp-email'])) return;
  busy(e.target, true);
  const email = $('#otp-email').value.trim();
  try {
    pendingEmail = email;
    await HazeAPI.requestLoginOtp(pendingEmail);
    e.target.hidden = true;
    $('#form-otp-verify').hidden = false;
    otpCountdown.start();
    $('#otp-code').focus();
    toast('If that address has an account, a code is on its way.');
  } catch (err) {
    if (err.code === 'no_account') {
      // No account with that address: don't pretend a code was sent. Tell them to
      // register and drop them straight on the sign-up form, pre-filled.
      toast(err.message, 'warn');
      $('#reg-email').value = pendingEmail;
      navigate('register');
      return;
    }
    if (err.code === 'email_not_verified') {
      toast(err.message, 'warn');
      return;
    }
    showApiError(err, { email: 'otp-email' });
  } finally {
    busy(e.target, false);
  }
});

// method C, step 2: verify the code
$('#form-otp-verify').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!validateForm(e.target, ['otp-code'])) return;
  busy(e.target, true);
  try {
    const data = await HazeAPI.verifyLoginOtp(pendingEmail, $('#otp-code').value.trim());
    currentUser = data.user;
    toast(`Signed in as ${data.user.username}.`);
    beginSession();
    navigate('dashboard');
  } catch (err) {
    showApiError(err, { code: 'otp-code' });
  } finally {
    busy(e.target, false);
  }
});

$('#otp-back').addEventListener('click', () => {
  $('#form-otp-verify').hidden = true;
  $('#form-otp-request').hidden = false;
  clearError('otp-code');
});

// registration
$('#form-register').addEventListener('submit', async (e) => {
  e.preventDefault();
  const ok = validateForm(e.target, ['reg-username', 'reg-email', 'reg-password', 'reg-confirm']);
  const terms = $('#reg-terms');
  const termsBox = document.querySelector('[data-error-for="reg-terms"]');
  if (!terms.checked) {
    termsBox.textContent = 'Please accept the Terms and Privacy Policy to continue.';
    termsBox.hidden = false;
    terms.focus();
    return;
  }
  termsBox.hidden = true;
  if (!ok) return;

  busy(e.target, true);
  const email = $('#reg-email').value.trim();
  const username = $('#reg-username').value.trim();
  try {
    const data = await HazeAPI.register(username, email, $('#reg-password').value);
    pendingEmail = data.email || email;
    $('#otp-target').textContent = pendingEmail;
    // A re-attempt at a sign-up that stalled on email delivery is routed to the
    // same verify screen, so the user can enter or resend the code instead of
    // being told the address is taken.
    if (data.needs_verification) {
      toast(data.message || 'We sent a fresh code to your email.');
    } else {
      toast('Account created. Check your inbox for the 6-digit code.');
    }
    navigate('otp');
  } catch (err) {
    if (err.code === 'mail_failed') {
      pendingEmail = (err.fields && err.fields.email) || email;
      $('#otp-target').textContent = pendingEmail;
      toast(err.message || 'We could not send the code. Use “Resend code” to try again.', 'warn');
      navigate('otp');
      return;
    }
    showApiError(err, { username: 'reg-username', email: 'reg-email', password: 'reg-password' });
  } finally {
    busy(e.target, false);
  }
});

// standalone email-verification OTP boxes
const otpBoxes = $$('.otp-box');
otpBoxes.forEach((box, i) => {
  box.addEventListener('input', () => {
    box.value = box.value.replace(/\D/g, '').slice(-1);
    if (box.value && i < otpBoxes.length - 1) otpBoxes[i + 1].focus();
    clearError('email-otp-code');
  });
  box.addEventListener('keydown', (e) => {
    if (e.key === 'Backspace' && !box.value && i > 0) otpBoxes[i - 1].focus();
  });
  box.addEventListener('paste', (e) => {
    e.preventDefault();
    const digits = (e.clipboardData.getData('text') || '').replace(/\D/g, '').slice(0, 6);
    digits.split('').forEach((d, j) => { if (otpBoxes[j]) otpBoxes[j].value = d; });
    otpBoxes[Math.min(digits.length, 5)].focus();
  });
});

$('#form-email-otp').addEventListener('submit', async (e) => {
  e.preventDefault();
  const code = otpBoxes.map((b) => b.value).join('');
  if (code.length < 6) {
    setError('email-otp-code', 'Enter all 6 digits from the code.');
    otpBoxes.find((b) => !b.value).focus();
    return;
  }
  clearError('email-otp-code');
  busy(e.target, true);
  try {
    await HazeAPI.verifyEmail(pendingEmail, code);
    showVerifyResult(true);
  } catch (err) {
    if (err.code === 'invalid_code') {
      showVerifyResult(false);
    } else {
      showApiError(err, { code: 'email-otp-code' });
      return;
    }
  } finally {
    busy(e.target, false);
  }
  navigate('verify-result');
});

function showVerifyResult(ok) {
  const icon = $('#verify-icon');
  icon.classList.toggle('auth__icon--warn', !ok);
  icon.querySelector('use').setAttribute('href', ok ? '#i-check' : '#i-alert');
  $('#verify-title').textContent = ok ? 'Email verified' : 'Code expired or invalid';
  $('#verify-text').textContent = ok
    ? 'Your account is active. You can sign in and start checking the air around you.'
    : 'That code is no longer valid — codes expire after 5 minutes and can only be used once. Request a fresh one to continue.';
  $('#verify-actions').innerHTML = ok
    ? '<a class="btn btn--primary btn--block" href="#login" data-go="login">Continue to sign in</a>'
    : '<a class="btn btn--primary btn--block" href="#otp" data-go="otp">Request a new code</a>' +
      '<a class="btn btn--ghost btn--block" href="#login" data-go="login">Back to sign in</a>';
}

// forgot / reset password
// The credential is a 6-digit code the visitor types, not a token in the URL, so
// there is nothing here to authorise by link.
//
// The address is a field on the reset screen, not only a remembered value.
// `pendingResetEmail` merely pre-fills it: the code is read on whatever device the
// mail arrived on, which is often not the browser that asked for it, and a reload
// or a second tab loses the variable. Requiring it here made those cases show
// "Request a code first" for a code that had in fact arrived — which reads
// exactly like a failed verification.
function showResetState() {
  const field = $('#reset-email');

  // Pre-filled from the request, but never overwritten: someone who reopens the
  // screen to try a second account must not have their address silently swapped.
  if (field && !field.value.trim() && pendingResetEmail !== '') {
    field.value = pendingResetEmail;
  }
  $('#reset-target').textContent = field && field.value.trim()
    ? field.value.trim()
    : 'your inbox';

  ['reset-email', 'reset-code', 'reset-password', 'reset-confirm'].forEach(clearError);
  // Only meaningful when a code was actually requested in this tab; starting the
  // timer otherwise would offer a resend for a request that never happened.
  if (pendingResetEmail !== '') resetCountdown.start();
}

$('#form-forgot').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!validateForm(e.target, ['forgot-email'])) return;
  busy(e.target, true);
  try {
    pendingResetEmail = $('#forgot-email').value.trim();
    // The server answers identically for a registered and an unknown address, so
    // the next screen is the same either way. It only becomes usable when a code
    // actually arrives.
    await HazeAPI.requestPasswordReset(pendingResetEmail);
    e.target.reset();
    navigate('reset');
  } catch (err) {
    showApiError(err, { email: 'forgot-email' });
  } finally {
    busy(e.target, false);
  }
});

$('#form-reset').addEventListener('submit', async (e) => {
  e.preventDefault();
  if (!validateForm(e.target, ['reset-email', 'reset-code', 'reset-password', 'reset-confirm'])) return;
  busy(e.target, true);
  try {
    // The field is the source of truth, not the remembered value: the two can
    // legitimately differ when the code was requested on another device.
    const email = $('#reset-email').value.trim();
    await HazeAPI.resetPassword(
      email,
      $('#reset-code').value.trim(),
      $('#reset-password').value
    );
    e.target.reset();
    pendingResetEmail = '';
    toast('Password updated. Sign in with your new password.');
    navigate('login');
  } catch (err) {
    if (err.code === 'invalid_code') {
      // Wrong, spent and expired are one answer from the server, so they get one
      // message. The form stays put: a fresh code resolves all three.
      setError('reset-code', err.message);
    } else {
      showApiError(err, { email: 'reset-email', password: 'reset-password' });
    }
  } finally {
    busy(e.target, false);
  }
});

/* ------------------------------------------------------------ demo data --- */
const PLACES = {
  'kuala lumpur':  { name: 'Kuala Lumpur',  aqi: 72,  pm25: 38, pm10: 61 },
  'petaling jaya': { name: 'Petaling Jaya', aqi: 58,  pm25: 29, pm10: 49 },
  'shah alam':     { name: 'Shah Alam',     aqi: 64,  pm25: 33, pm10: 54 },
  'putrajaya':     { name: 'Putrajaya',     aqi: 69,  pm25: 35, pm10: 58 },
  'klang':         { name: 'Klang',         aqi: 118, pm25: 63, pm10: 100 },
};

const SERIES = {
  '24h': [64, 61, 58, 57, 55, 54, 56, 60, 66, 71, 74, 77, 80, 83, 81, 78, 75, 73, 72, 70, 68, 66, 65, 72],
  '7d':  [68, 74, 81, 77, 63, 58, 72],
  '30d': [70, 74, 66, 61, 58, 63, 71, 79, 84, 80, 73, 67, 62, 59, 64, 72, 77, 82, 88, 83, 76, 70, 65, 61, 66, 73, 78, 74, 69, 72],
};

const METRICS = {
  aqi:  { label: 'US AQI',        unit: 'US AQI',        good: 50,  convert: (v) => v },
  pm25: { label: 'PM2.5',         unit: 'Âµg/mÂ³',         good: 25,  convert: (v) => Math.round(v * 0.53) },
  pm10: { label: 'PM10',          unit: 'Âµg/mÂ³',         good: 50,  convert: (v) => Math.round(v * 0.85) },
};

function aqiCategory(v) {
  if (v <= 50) return { label: 'Good', ribbon: 'ribbon--good' };
  if (v <= 100) return { label: 'Moderate', ribbon: 'ribbon--2' };
  if (v <= 150) return { label: 'Unhealthy for sensitive groups', ribbon: 'ribbon--3' };
  if (v <= 200) return { label: 'Unhealthy', ribbon: 'ribbon--4' };
  return { label: 'Very unhealthy', ribbon: 'ribbon--4' };
}

const DEMO_SRC = 'Illustrative demo value Â· planned source: Open-Meteo Air Quality API (modelled estimate, not an official station reading)';

/* ----------------------------------------------------------------- charts - */
let chartSeq = 0;
function renderChart(el, values, labels, unit) {
  if (!values || !values.length) { el.hidden = true; return; }
  el.hidden = false;
  const w = Math.max(Math.floor(el.clientWidth) || 300, 240);
  const h = 170;
  const padL = 36, padR = 12, padT = 12, padB = 24;
  const min = Math.min(...values), max = Math.max(...values);
  const span = Math.max(max - min, 1);
  const lo = min - span * 0.15, hi = max + span * 0.15;
  const x = (i) => padL + (i / (values.length - 1)) * (w - padL - padR);
  const y = (v) => padT + (1 - (v - lo) / (hi - lo)) * (h - padT - padB);

  const pts = values.map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`);
  const line = 'M' + pts.join(' L');
  const area = line + ` L${x(values.length - 1).toFixed(1)},${h - padB} L${padL},${h - padB} Z`;
  const gid = 'cg' + (chartSeq++);

  const yTicks = [max, Math.round((max + min) / 2), min]
    .map((v) => `<line class="chart__grid" x1="${padL}" y1="${y(v).toFixed(1)}" x2="${w - padR}" y2="${y(v).toFixed(1)}"/>
                 <text class="chart__axis-label" x="4" y="${(y(v) + 3.5).toFixed(1)}">${v}</text>`).join('');

  const midIdx = Math.floor((values.length - 1) / 2);
  const xTicks = [0, midIdx, values.length - 1]
    .map((i, k) => `<text class="chart__axis-label" x="${x(i).toFixed(1)}" y="${h - 6}" text-anchor="${k === 0 ? 'start' : k === 2 ? 'end' : 'middle'}">${labels[i]}</text>`)
    .join('');

  el.innerHTML =
    `<svg viewBox="0 0 ${w} ${h}" width="${w}" height="${h}" role="presentation">
      <defs><linearGradient id="${gid}" x1="0" y1="0" x2="0" y2="1">
        <stop offset="0" stop-color="#ffffff" stop-opacity="0.28"/>
        <stop offset="1" stop-color="#ffffff" stop-opacity="0"/>
      </linearGradient></defs>
      ${yTicks}
      <path d="${area}" fill="url(#${gid})"/>
      <path class="chart__line" d="${line}"/>
      <circle class="chart__dot" cx="${x(values.length - 1).toFixed(1)}" cy="${y(values[values.length - 1]).toFixed(1)}" r="4"/>
      ${xTicks}
      <text class="chart__axis-label" x="${w - padR}" y="${padT + 2}" text-anchor="end">${unit}</text>
    </svg>`;
}

function periodLabels(period) {
  if (period === '24h') return SERIES['24h'].map((_, i) => `${(11 + i) % 24}:00`);
  if (period === '7d') return ['Sun 5', 'Mon 6', 'Tue 7', 'Wed 8', 'Thu 9', 'Fri 10', 'Sat 11'];
  return SERIES['30d'].map((_, i) => {
    const d = new Date(2026, 8, 12 + i); // 12 Sep → 11 Oct 2026
    return `${d.getDate()} ${d.toLocaleString('en', { month: 'short' })}`;
  });
}

function drawDashboardChart() {
  const el = $('#dash-chart');
  if (el) renderChart(el, SERIES['24h'], periodLabels('24h'), 'US AQI');
}

/* ------------------------------------------------------------------ radar - */
/* The map itself lives in js/radar.js: it is the only feature that needs Leaflet,
   and keeping it out of here stops 15 KB of mapping code loading on the ten
   screens that have no map. These two are the seams it reaches back through. */

/** The radar picked a searched place: move the map, read it, save nothing. */
function goToRadarPlace(name, lat, lon) {
  if (window.HazeRadar) window.HazeRadar.goTo(name, lat, lon);
}

/** The radar asked to locate: ask the browser, through the shared promise. */
function locateOnRadar() {
  if (window.HazeRadar) window.HazeRadar.locateOnRadar();
}

/* ---------------------------------------------------------------- planner - */
let planCounter = 0;
$('#form-planner').addEventListener('submit', (e) => {
  e.preventDefault();
  const activity = $('#plan-activity').value;
  const locationName = $('#plan-location').value;
  const time = $('#plan-time').value;
  const prefs = $$('input[name="pref"]:checked').length;

  $('#plan-result').hidden = true;
  $('#plan-loading').hidden = false;
  $('#btn-generate').disabled = true;

  setTimeout(() => {
    const place = PLACES[locationName.toLowerCase()];
    const hour = parseInt(time.slice(0, 2), 10);
    const evening = hour >= 17 || hour < 7;
    const aqi = Math.max(place.aqi - (evening ? 8 : 0), 5);
    const cat = aqiCategory(aqi);
    planCounter += 1;

    $('#plan-ribbon').className = 'ribbon ' + cat.ribbon;
    $('#plan-ribbon').textContent = `${aqi <= 100 ? 'Good window' : 'Adjust your plan'} Â· AQI ${aqi}`;
    $('#plan-summary').textContent =
      `A ${time} ${activity.toLowerCase()} session in ${locationName} is ${aqi <= 100 ? 'reasonable' : 'not ideal'} in this demo scenario.`;
    $('#plan-why').textContent =
      `The illustrative AQI for your time and place sits at ${aqi} (${cat.label}, US AQI)${aqi > 100 ? ' — above 100, where sensitive groups should cut back' : ' — below the 100 threshold where sensitive groups should cut back'}. ` +
      `${evening ? 'Particulate levels ease after sunset in the demo pattern.' : 'Afternoon values are typically higher in the demo pattern.'} ` +
      `Data used: US AQI ${aqi}, PM2.5 ${Math.round(aqi * 0.53)} Âµg/mÂ³ Â· ${prefs} preference${prefs === 1 ? '' : 's'} applied Â· illustrative timestamp 11 Oct 2026, 10:40 +08:00 Â· planned source Open-Meteo (modelled estimate).`;
    $('#plan-alt').textContent = aqi > 100
      ? 'Move it indoors: a covered court, gym session or mall walk keeps your training going without the particulate load.'
      : 'If AQI climbs above 100 by then, switch to a covered stadium session or a 45-minute indoor workout.';

    $('#plan-loading').hidden = true;
    $('#btn-generate').disabled = false;
    $('#plan-result').hidden = false;
    $('#plan-result').scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }, 900);
});

$('#btn-save-plan').addEventListener('click', () => {
  toast(`Plan #${planCounter} saved in this session only — MySQL persistence arrives in Stage 5.`, 'warn');
});
$('#btn-regenerate').addEventListener('click', () => $('#form-planner').requestSubmit());

/* ------------------------------------------------------------------- chat - */
const chatBody = $('#chat-body');
const chatInput = $('#chat-input');
const chatSend = $('#chat-send');
let lastUserText = '';

function scrollChat() { chatBody.scrollTop = chatBody.scrollHeight; }

function addMessage(text, cls) {
  const el = document.createElement('div');
  el.className = 'msg ' + cls;
  el.textContent = text;
  chatBody.appendChild(el);
  scrollChat();
  return el;
}

function botReply(text) {
  const q = text.toLowerCase();
  const reading = 'AQI 72 (Moderate), PM2.5 38 Âµg/mÂ³, PM10 61 Âµg/mÂ³';
  const src = 'Demo data Â· illustrative timestamp 11 Oct 2026, 10:40 +08:00 Â· planned source: Open-Meteo (modelled estimate)';

  if (/(run|jog|exercise|football|hike|cycling|cycle|walk|picnic|workout|outdoor)/.test(q)) {
    return { text: `For a demo scenario right now: ${reading}. That’s fine for most people; sensitive groups should keep intense effort shorter and away from busy roads. In the prototype I can only cite these illustrative values; the real BreatheBuddy will call live air-quality tools (Stage 4).`, src };
  }
  if (/(aqi|index|what does|mean|scale)/.test(q)) {
    return { text: 'AQI is a 0–300+ scale: 0–50 good, 51–100 moderate, 101–150 unhealthy for sensitive groups, 151+ unhealthy for everyone. The demo reading of 72 sits in the moderate band — active people can be out, but sensitive groups should ease up at peak hours.', src };
  }
  if (/(pm|particulate|dust|particle)/.test(q)) {
    return { text: 'PM2.5 is fine particulate matter under 2.5 Âµg/m in diameter — small enough to reach deep into the lungs. PM10 is coarser dust. The demo shows PM2.5 at 38 and PM10 at 61 Âµg/mÂ³. Modelled estimates like these are labelled as estimates, never as official station measurements.', src };
  }
  if (/(haze|smoke|burn|pollution|why|bad air|pollut)/.test(q)) {
    return { text: 'Haze forms when particles scatter light — usually from fires, traffic and industry, worsened by dry, still weather. In this prototype the numbers are illustrative; with the backend (Stage 3) I’ll show you real Open-Meteo estimates with their timestamp, and official APIMS station data if we can verify access to it.', src };
  }
  if (/(rain|forecast|tomorrow|next week|will it)/.test(q)) {
    return { text: 'I can’t predict future air quality — and the final app won’t pretend to either. Forecasts would need a dedicated model source; for now I can only show readings for the moment you ask, with their timestamp.', src };
  }
  if (/(clean|best|where|area|compare|location|neighbourhood)/.test(q)) {
    return { text: 'In the demo dataset: Petaling Jaya is cleanest (AQI 58), then Shah Alam (64), Putrajaya (69), Kuala Lumpur (72), and Klang highest (118). Open the Haze Radar to see them on the map — all values are labelled demo data in this prototype.', src };
  }
  if (/(plan|schedule|save|planner)/.test(q)) {
    return { text: 'Head to the Planner tab: pick an activity, place, date and time, and it generates a verdict with the reasoning, an indoor alternative, and a save action. In the prototype the recommendation is clearly labelled as a demo.', src };
  }
  if (/(hi|hello|hey|good morning|good evening)/.test(q)) {
    return { text: 'Hi! I’m BreatheBuddy — in this prototype my replies are canned demo responses. Ask me about AQI, particulates, haze, or whether your outdoor plans are a good idea.', src: null };
  }
  return { text: 'This prototype only has canned replies — the real BreatheBuddy will answer with live tool calls once the backend lands (Stage 4). Try asking about AQI, PM2.5, haze, or your outdoor plans.', src: null };
}

function showTyping() {
  const el = document.createElement('div');
  el.className = 'typing';
  el.setAttribute('aria-label', 'BreatheBuddy is typing');
  el.innerHTML = '<i></i><i></i><i></i>';
  chatBody.appendChild(el);
  scrollChat();
  return el;
}

function answerLastMessage() {
  const typing = showTyping();
  setTimeout(() => {
    typing.remove();
    try {
      const reply = botReply(lastUserText);
      const el = addMessage(reply.text, 'msg--bot');
      if (reply.src) {
        const src = document.createElement('span');
        src.className = 'msg__src';
        src.textContent = reply.src;
        el.appendChild(src);
      }
    } catch (err) {
      const el = addMessage('Something went wrong while composing a reply. Try again.', 'msg--error');
      const retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'msg__retry';
      retry.textContent = 'Retry';
      retry.addEventListener('click', () => { el.remove(); answerLastMessage(); });
      el.appendChild(retry);
    }
  }, 700);
}

function sendChat(text) {
  const trimmed = text.trim();
  if (!trimmed) return;
  $('#chat-empty').hidden = true;
  lastUserText = trimmed;
  addMessage(trimmed, 'msg--user');
  chatInput.value = '';
  chatSend.disabled = true;
  answerLastMessage();
}

$('#chat-form').addEventListener('submit', (e) => { e.preventDefault(); sendChat(chatInput.value); });
chatInput.addEventListener('input', () => { chatSend.disabled = chatInput.value.trim() === ''; });
$$('#chat-suggestions .chip').forEach((chip) => {
  chip.addEventListener('click', () => sendChat(chip.textContent));
});

/* ---------------------------------------------------------------- history - */
let histPeriod = '24h';
let histMetric = 'aqi';

function drawHistory() {
  const metric = METRICS[histMetric];
  const empty = histPeriod === '30d' && histMetric === 'pm10';
  const chart = $('#history-chart');
  const emptyBox = $('#history-empty');
  const summary = $('#history-summary');

  const periodWord = histPeriod === '24h' ? 'last 24 hours' : histPeriod === '7d' ? 'last 7 days' : 'last 30 days';
  $('#history-title').textContent = `${metric.label} Â· ${periodWord}`;

  if (empty) {
    chart.hidden = true;
    emptyBox.hidden = false;
    summary.hidden = true;
    $('#history-src').textContent = 'No records stored for this range in the prototype — the gap is shown, not filled.';
    return;
  }
  emptyBox.hidden = true;
  summary.hidden = false;

  const base = SERIES[histPeriod];
  const values = base.map(metric.convert);
  const labels = periodLabels(histPeriod);
  renderChart(chart, values, labels, metric.unit);

  const avg = Math.round(values.reduce((a, b) => a + b, 0) / values.length);
  $('#h-avg').textContent = avg;
  $('#h-max').textContent = Math.max(...values);
  $('#h-min').textContent = Math.min(...values);
  $('#h-good').textContent = values.filter((v) => v <= metric.good).length;
  $('#h-good-label').textContent = (histPeriod === '24h' ? 'Good hours' : 'Good days') + ` (≤${metric.good})`;
  $('#history-src').textContent = `Illustrative demo series Â· metric: ${metric.label} (${metric.unit}) Â· planned source: Open-Meteo Air Quality API (modelled estimate)`;
  chart.setAttribute('aria-label',
    `Demo chart of ${metric.label} over the ${periodWord}, ranging from ${Math.min(...values)} to ${Math.max(...values)} ${metric.unit}`);
}

$$('#screen-history [data-period]').forEach((btn) => {
  btn.addEventListener('click', () => {
    histPeriod = btn.dataset.period;
    $$('#screen-history [data-period]').forEach((b) => {
      const on = b === btn;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-selected', String(on));
    });
    drawHistory();
  });
});
$$('#screen-history [data-metric]').forEach((btn) => {
  btn.addEventListener('click', () => {
    histMetric = btn.dataset.metric;
    $$('#screen-history [data-metric]').forEach((b) => {
      const on = b === btn;
      b.classList.toggle('is-active', on);
      b.setAttribute('aria-selected', String(on));
    });
    drawHistory();
  });
});

/* -------------------------------------------------------- global actions -- */
document.addEventListener('click', async (e) => {
  const goEl = e.target.closest('[data-go]');
  if (goEl) { e.preventDefault(); navigate(goEl.dataset.go); return; }

  const navEl = e.target.closest('[data-nav]');
  if (navEl) { e.preventDefault(); navigate(navEl.dataset.nav); return; }

  const actionEl = e.target.closest('[data-action]');
  if (actionEl) {
    if (actionEl.dataset.action === 'logout') {
      try {
        await HazeAPI.logout();
      } catch (err) {
        // already signed out server-side, or offline — fall through to local reset
      }
      currentUser = null;
      pendingEmail = '';
      renderUser(null);
      // Rule 3: with no account there is no saved row, so the card goes back to
      // reading whatever the browser reports. Leaving the flag up would keep the
      // now-dead saved city on screen for the rest of the session.
      if (window.HazeAir) window.HazeAir.usePreferredLocation(false);
      locationSync = null;
      toast('Signed out.');
      navigate('landing');
    } else {
      switch (actionEl.dataset.action) {
        case 'search-location':
          openPlaceSearch();
          break;
        case 'cancel-place-search':
          closePlaceSearch();
          break;
        case 'relocate-location':
          await relocateToCurrentPosition();
          break;
        case 'change-password':
          showPanel('password');
          $('#chg-current').focus();
          break;
        case 'cancel-password':
          hidePanel('password');
          $('#form-change-password').reset();
          break;
        case 'delete-account':
          showPanel('delete');
          $('#del-password').focus();
          break;
        case 'cancel-delete':
          hidePanel('delete');
          $('#form-delete-account').reset();
          break;
        default:
          toast('Prototype — this control is not wired up yet.');
      }
    }
  }
});

/* ------------------------------------------------------------------ init -- */
let resizeTimer = null;
window.addEventListener('resize', () => {
  clearTimeout(resizeTimer);
  resizeTimer = setTimeout(() => {
    if (currentScreen === 'dashboard') drawDashboardChart();
    if (currentScreen === 'history') drawHistory();
  }, 150);
});

const initialScreen = parseHash();
render(SCREENS[initialScreen] ? initialScreen : 'landing');

/* --------------------------------------------------------- session boot -- */
// Ask the server who we are (and pick up the CSRF token), then enforce the
// protected-route guard. If the API is unreachable we treat the visitor as
// signed out but still let the public shell render.
(async function initSession() {
  try {
    const data = await HazeAPI.bootstrap();
    currentUser = data.user || null;
  } catch (err) {
    currentUser = null;
  }
  sessionReady = true;
  // `render()` already ran for the initial screen, before we knew who this was,
  // so paint the identity now that the answer is in.
  renderUser(currentUser);
  if (SCREENS[currentScreen] && SCREENS[currentScreen].kind === 'app' && !currentUser) {
    toast('Please sign in to continue.', 'warn');
    navigate('login');
    return;
  }
  // The profile screen's own work — painting the stored preferences — was
  // skipped on a direct load, because `render()` ran while `currentUser` was
  // still null and `loadPreferences()` returns without one. Re-run it now that
  // the session is known. See onEnterScreen() for why render() cannot do it.
  //
  // First rather than after the sync: loadPreferences() awaits that same sync,
  // so a direct load of #profile still costs one detection and one save, and the
  // screen shows its saved city instead of waiting several seconds for a fix.
  onEnterScreen(currentScreen);

  // Rule 1, on every load and on any screen: while signed in, the saved
  // coordinates are replaced with the device's, and the card re-reads against
  // the row just written. It cannot live in onEnterScreen() alone, because that
  // only knows about the profile screen — the card is on the dashboard.
  //
  // Signed out this returns immediately: there is no row to write, and
  // airquality.js is already reading the device coordinates (rule 3).
  await syncLocationFromDevice();
})();
