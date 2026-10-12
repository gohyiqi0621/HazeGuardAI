/* Static checks for the Stage 1A prototype (no browser needed).
   Usage: node tests/static-check.cjs */
'use strict';
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const html = fs.readFileSync(path.join(root, 'templates/hazeguard/index.php'), 'utf8');
const css = fs.readFileSync(path.join(root, 'media/com_hazeguard/css/style.css'), 'utf8');
const js = fs.readFileSync(path.join(root, 'media/com_hazeguard/js/app.js'), 'utf8');
const api = fs.readFileSync(path.join(root, 'media/com_hazeguard/js/api.js'), 'utf8');
const air = fs.readFileSync(path.join(root, 'media/com_hazeguard/js/airquality.js'), 'utf8');
// radar.js is a classic script sharing app.js's global scope, so its element
// references are exactly as checkable as app.js's — and it is the only file that
// touches Leaflet, so nothing else would notice it drifting out of the template.
const radar = fs.readFileSync(path.join(root, 'media/com_hazeguard/js/radar.js'), 'utf8');
const scripts = js + '\n' + radar;

let failures = 0;
function check(name, ok, detail) {
  console.log((ok ? 'PASS' : 'FAIL') + '  ' + name + (ok || !detail ? '' : ' — ' + detail));
  if (!ok) failures += 1;
}

// 1. required screens exist, each exactly once, with no duplicate ids anywhere
const requiredScreens = ['landing','login','register','otp','verify-result','forgot','reset','dashboard','radar','planner','chat','history','profile'];
requiredScreens.forEach((s) => {
  const n = (html.match(new RegExp(`id="screen-${s.replace('-', '\\-')}"`, 'g')) || []).length;
  check(`screen exists: ${s}`, n === 1, `found ${n}`);
});
const allIds = [...html.matchAll(/\sid="([^"]+)"/g)].map((m) => m[1]);
const dupes = allIds.filter((id, i) => allIds.indexOf(id) !== i);
check('no duplicate id attributes', dupes.length === 0, dupes.join(', '));

// 2. every icon reference resolves to a defined symbol
const symbols = new Set([...html.matchAll(/<symbol id="([^"]+)"/g)].map((m) => m[1]));
const iconRefs = new Set([...(html + js).matchAll(/href="(#i-[^"]+)"/g)].map((m) => m[1].slice(1)));
const badIcons = [...iconRefs].filter((r) => !symbols.has(r));
check(`all ${iconRefs.size} icon references resolve`, badIcons.length === 0, badIcons.join(', '));

// 3. every element id referenced from JS exists in the HTML
const jsIds = new Set([
  ...[...scripts.matchAll(/\$\('#([a-zA-Z0-9\-_]+)'\)/g)].map((m) => m[1]),
  ...[...scripts.matchAll(/getElementById\('([^']+)'\)/g)].map((m) => m[1]),
  ...[...scripts.matchAll(/data-error-for="([^"$]+)"/g)].map((m) => m[1]),
]);
const missingIds = [...jsIds].filter((id) => !allIds.includes(id) && !html.includes(`data-error-for="${id}"`));
check(`all ${jsIds.size} JS-referenced ids exist in HTML`, missingIds.length === 0, missingIds.join(', '));

// 4. data-go / data-nav targets are real screens
const goTargets = new Set([...(html + scripts).matchAll(/data-(?:go|nav)="([^"]+)"/g)].map((m) => m[1]));
const badTargets = [...goTargets].filter((t) => !requiredScreens.includes(t));
check('all navigation targets are screens', badTargets.length === 0, badTargets.join(', '));

// 5. JS-defined screen table matches HTML screens
const jsScreens = [...js.matchAll(/^\s*'([a-z\-]+)':\s+\{ kind:/gm)].map((m) => m[1]);
const screenDiff = requiredScreens.filter((s) => !jsScreens.includes(s)).concat(jsScreens.filter((s) => !requiredScreens.includes(s)));
check('router screen table matches HTML', screenDiff.length === 0, screenDiff.join(', '));

// 6. exactly five bottom-nav items, with the mandated labels
const tabbar = html.match(/<nav class="tabbar[^"]*"[\s\S]*?<\/nav>/);
const items = tabbar ? [...tabbar[0].matchAll(/<span>([^<]+)<\/span>/g)].map((m) => m[1]) : [];
check('bottom nav has exactly 5 items', items.length === 5, JSON.stringify(items));
check('bottom nav labels are Home/Radar/Planner/AI Chat/Profile',
  JSON.stringify(items) === JSON.stringify(['Home','Radar','Planner','AI Chat','Profile']), JSON.stringify(items));

// 7. CSS integrity: balanced braces, tokens defined before use
check('CSS braces balanced', (css.match(/\{/g) || []).length === (css.match(/\}/g) || []).length,
  `${(css.match(/\{/g) || []).length} open vs ${(css.match(/\}/g) || []).length} close`);
const declared = new Set([...css.matchAll(/(--[a-z0-9\-]+)\s*:/g)].map((m) => m[1]));
const used = new Set([...css.matchAll(/var\((--[a-z0-9\-]+)/g)].map((m) => m[1]));
const inlineSet = new Set([...html.matchAll(/--([a-z0-9\-]+):/g)].map((m) => '--' + m[1]));
const undeclared = [...used].filter((v) => !declared.has(v) && !inlineSet.has(v));
check('all CSS variables declared', undeclared.length === 0, undeclared.join(', '));

// 7b. Phase 2 — the monochrome glass restyle. Three things rot silently here:
//     a hue creeping back in, the glass edge ring swallowing clicks, and the
//     background video disappearing behind a wrong path or data-kind.
const mono = css + js + html;
function isNeutralHex(h) {
  const s = (h.length === 3 || h.length === 4)
    ? [...h.slice(0, 3)].map((c) => c + c).join('')
    : h.slice(0, 6);
  if (s.length !== 6) return false;
  const t = s.toLowerCase();
  return t.slice(0, 2) === t.slice(2, 4) && t.slice(2, 4) === t.slice(4, 6);
}
const strayHexes = [...mono.matchAll(/(?:#|%23)([0-9a-fA-F]{3,8})\b/g)]
  .map((m) => m[1])
  .filter((h) => !isNeutralHex(h));
check('every hex colour in the shipped UI is neutral gray', strayHexes.length === 0, strayHexes.join(', '));
const strayHues = [...css.matchAll(/hsla?\(\s*(-?\d+)/g)].map((m) => Number(m[1])).filter((h) => h !== 0);
check('every hsl() hue in style.css is 0 (strict grayscale)', strayHues.length === 0, strayHues.join(', '));
check('no rgba()/rgb()/hsla() colour calls left in style.css',
  !/\brgba?\(|\bhsla\(/.test(css), 'found legacy colour syntax');
check('Manrope fully replaced by self-hosted Poppins',
  !/manrope/i.test(mono), 'Manrope still referenced');

const glassApplied = (html.match(/liquid-glass/g) || []).length;
check(`glass surfaces applied in markup (>= 20)`, glassApplied >= 20, `found ${glassApplied}`);
check('both glass classes are defined in style.css',
  /\.liquid-glass\s*\{/.test(css) && /\.liquid-glass-strong\s*\{/.test(css), 'missing .liquid-glass or .liquid-glass-strong rule');
check('glass edge ring is click-inert',
  /\.liquid-glass::before[\s\S]{0,600}?pointer-events:\s*none/.test(css), 'no pointer-events:none on the edge pseudo');
check('glass edge ring is masked to the border box',
  /mask-composite:\s*exclude/.test(css), 'mask-composite missing');
check('chrome bars carry strong glass and keep their own position',
  ['topbar', 'tabbar', 'sidenav'].every((c) => new RegExp(`class="${c}[^"]*liquid-glass-strong`).test(html))
    && /\.topbar\.liquid-glass-strong[\s\S]{0,120}position:\s*sticky/.test(css));

const videoFile = 'media/com_hazeguard/assets/video/haze-loop.mp4';
check('background stage markup is in the template',
  /class="bg-stage"/.test(html) && /class="bg-video"/.test(html) && /class="bg-scrim"/.test(html));
check('self-hosted video file exists on disk', fs.existsSync(path.join(root, videoFile)));
check('markup points at the self-hosted video', html.includes(videoFile));
check('video is decorative and cannot capture input',
  /class="bg-stage" aria-hidden="true"/.test(html)
    && /\.bg-stage\s*\{[\s\S]{0,200}?pointer-events:\s*none/.test(css)
    && /class="bg-video"[^>]*\bmuted\b/.test(html));
check('video is off on app screens via data-kind',
  css.includes('body[data-kind="app"] .bg-video { display: none; }'));
check('video is off under prefers-reduced-motion',
  /@media \(prefers-reduced-motion: reduce\)[\s\S]{0,400}?\.bg-video\s*\{\s*display:\s*none/.test(css));
check('the stage has a static frame fallback behind the video',
  /\.bg-stage\s*\{[\s\S]{0,400}?linear-gradient\(/.test(css));

const fontUrls = [...css.matchAll(/url\('(\.\.\/assets\/fonts\/[^']+)'\)/g)].map((m) => m[1]);
const missingFonts = fontUrls.filter((u) => !fs.existsSync(path.join(root, 'media/com_hazeguard/css', u)));
check(`all ${fontUrls.length} @font-face files exist on disk`, missingFonts.length === 0, missingFonts.join(', '));
check('the display weight (800) is self-hosted', fontUrls.some((u) => u.includes('800')));
check('the serif emphasis face is self-hosted', fontUrls.some((u) => /source-serif/i.test(u)));

// 8. no console logging left in shipped JS
check('no console.* in app.js', !/console\.(log|debug|info)\(/.test(js), 'found console usage');

// 9. no emoji used as interface icons in markup (basic sweep of common ones)
const emoji = html.match(/[\u{1F300}-\u{1FAFF}\u{2600}-\u{27BF}]/gu) || [];
check('no emoji in markup', emoji.length === 0, emoji.join(' '));

// 10. every demo-data surface is labelled
const demoBadges = (html.match(/badge--demo/g) || []).length;
check('demo badges present (>= 8)', demoBadges >= 8, `found ${demoBadges}`);

// 11. no invented identity in the markup. The profile used to ship a hardcoded
//     person; if one comes back, the screen claims a user who is not signed in.
const fabricated = ['Aina Rahman', 'aina_r', 'aina@example.com', 'demo account', 'Demo profile'];
const foundFabricated = fabricated.filter((s) => html.includes(s));
check('no hardcoded account identity in markup', foundFabricated.length === 0, foundFabricated.join(', '));
check('logout is no longer flagged as a demo action', !html.includes('demo-logout') && html.includes('data-action="logout"'));

// 12. every value the JS is supposed to paint is actually painted somewhere.
//     The dashboard header shipped a hook nothing ever wrote to, so it stayed
//     wrong for every user; this catches that shape of bug.
const painted = [...new Set([...html.matchAll(/\sdata-(aqi|user)-([a-z]+)/g)].map((m) => `data-${m[1]}-${m[2]}`))];
const bundle = scripts + '\n' + air;
const unpainted = painted.filter((hook) => !bundle.includes(hook));
check(`all ${painted.length} JS-painted data hooks are written by the client`, unpainted.length === 0, unpainted.join(', '));

// 13. the identity is rendered, and rendered from the session rather than a literal
check('app.js renders the signed-in identity', /function renderUser\(user\)/.test(js));
check('identity is rendered on boot, not only on navigation',
    (js.match(/renderUser\(currentUser\)/g) || []).length >= 2, 'expected render() + session boot');
check('identity is cleared on logout', /renderUser\(null\)/.test(js));
check('the verified pill reads real state', /user\.verified/.test(js));

// 14. the dashboard's pollutant cards must read the live payload. They once sat
//     in the same row as a live hero while showing a hardcoded 38 and 61, which
//     reads as two real numbers and two fake ones side by side.
const statGrid = (html.match(/<div class="stat-grid">[\s\S]*?<\/div>/) || [''])[0];
check('the dashboard pollutant cards are wired to the live payload',
  /data-aqi-pm25/.test(statGrid) && /data-aqi-pm10/.test(statGrid), statGrid.slice(0, 80));
check('they show no value before the reading arrives',
  /data-aqi-pm25>—</.test(statGrid) && /data-aqi-pm10>—</.test(statGrid));
check('no invented "vs. yesterday" delta sits under a live number',
  !/vs\. yesterday/.test(statGrid));

// 15. password reset must be wired to the API, not stubbed, and must carry a
//     credential the visitor types rather than one baked into a URL. It used to
//     be an emailed link built from APP_URL, which pointed at a dead port
//     whenever APP_URL was wrong and could not be opened at all from a phone.
check('the forgot form calls the API', /HazeAPI\.requestPasswordReset\(/.test(js));
check('the reset form calls the API', /HazeAPI\.resetPassword\(/.test(js));
check('the reset code is typed by the visitor, never carried in the URL',
  /id="reset-code"[^>]*maxlength="6"/.test(html) && /'reset-code':\s*\(v\)/.test(js));
check('no reset credential travels in the URL any more',
  !/params\.get\('token'\)/.test(js) && !/token=/.test(html));
check('the hash router still understands a bare screen name',
  /function parseHash\(\)/.test(js) && /raw\.indexOf\('\?'\)/.test(js));
// The reset screen no longer gates on "a code was requested in this tab". That gate
// made a code that had genuinely arrived read as a failed verification whenever the
// mail was opened on another device or a second tab, so the address became a field
// the visitor can correct and `pendingResetEmail` is only a pre-fill.
check('the reset screen identifies the account by address, not by this tab\'s memory',
  /id="reset-email"/.test(html) && /showResetState/.test(js) && /pendingResetEmail/.test(js)
  && !/reset-invalid/.test(html));
check('the remembered address only pre-fills, and never overwrites, the field',
  /!field\.value\.trim\(\) && pendingResetEmail !== ''/.test(js));
check('the reset screen names the address the code went to',
  /id="reset-target"/.test(html) && /\$\('#reset-target'\)\.textContent/.test(js));
check('the reset screen offers a resend with a countdown',
  /id="reset-countdown"/.test(html) && /id="reset-resend"/.test(html)
  && /HazeAPI\.requestPasswordReset\(pendingResetEmail\)/.test(js));
check('no password-reset copy still claims to be a prototype',
  !/no password is stored or changed/i.test(html)
  && !/No email was actually sent/i.test(html)
  && !/Request accepted \(demo\)/i.test(html));
check('no password-reset copy still describes a link',
  !/reset link/i.test(html) && !/links work once/i.test(html));
check('the forgot screen does not offer a shortcut past the emailed code',
  !/Continue to reset password/.test(html));

// 16. the whole auth surface is live, so no auth screen may still promise a
//     backend or disclaim having done anything. Every string below was literally
//     false: register, verify, OTP login and reset all call the API above.
//     (The air-quality "Prototype build" notices are a different thing and stay.)
check('no auth screen still promises a Stage 1B backend',
  !/arrive in Stage 1B/i.test(html) && !/arrive with the Flask backend/i.test(html));
check('no auth screen still says no email was sent',
  !/no email was sent/i.test(html));
check('no auth screen still disclaims having stored anything',
  !/nothing is stored/i.test(html) && !/no verification actually took place/i.test(html));

// 17. the countdown finds its resend button by looking beside it in .otp-actions,
//     so a countdown added without one silently loses its resend affordance.
check('every resend button is reachable from its countdown',
  (html.match(/data-resend/g) || []).length === 3 && !/root\.id ===/.test(js));

// 18. the profile screen's saved controls call the API. Each of these used to be
//     a `data-action="demo-only"` button that toasted "not wired up yet".
check('the activity chips save through the API',
  /HazeAPI\.saveActivities\(/.test(js) && /id="pref-activities"/.test(html));
check('the preferred location saves through the API',
  /HazeAPI\.saveLocation\(/.test(js) && /data-pref-location-name/.test(html));
check('changing the password calls the API',
  /HazeAPI\.changePassword\(/.test(js) && /id="form-change-password"/.test(html));
check('deleting the account calls the API',
  /HazeAPI\.deleteAccount\(/.test(js) && /id="form-delete-account"/.test(html));
check('no profile control is left on the demo action any more',
  !/data-action="demo-only"/.test(html));
check('preferences load when the profile screen is entered',
  /function onEnterScreen\(name\)[\s\S]{0,200}loadPreferences\(\)/.test(js));
check('the confirmation field is validated against its source',
  /chg-confirm/.test(js));

// 19. notifications must stay unwired. Nothing delivers them, so a switch that
//     looked live — or a stored preference claiming they were on — would promise
//     something the app cannot do. This is the one deliberate gap on the screen.
const notifyBlock = html.slice(html.indexOf('id="set-notify"'), html.indexOf('id="set-account"'));
check('the notification switches are disabled',
  (notifyBlock.match(/type="checkbox" class="switch" disabled/g) || []).length === 3);
check('the notification group says why it is unavailable',
  /Not (ready|available) yet/.test(notifyBlock));
check('nothing writes a notification preference',
  !/notif/i.test(js) || !/saveNotif/.test(js));

// 20. the saved location shows a real city name with the coordinates beneath it in
//     small type, and the client cannot dictate the name: the server resolves it
//     from the coordinates, so a client-supplied label cannot end up on the
//     instrument.
check('the saved location shows its coordinates',
  /data-pref-location-coords/.test(html) && /data-pref-location-coords/.test(js));
check('the coordinate line is styled small',
  /\.settings-row__coords\s*\{[^}]*font-size:\s*var\(--fs-xs\)/.test(css));
check('the client sends only coordinates when saving a location',
  /saveLocation:\s*\(coords\)\s*=>[\s\S]{0,200}?\{ lat: null, lon: null \}/.test(api));
check('the client does not send a place name to save',
  !/saveLocation[\s\S]{0,200}?name:/.test(api));
check('saving a location re-reads the air straight away',
  /usePreferredLocation\(/.test(js) && /usePreferred/.test(air));
check('the device position is not sent once a location is saved',
  /if \(usePreferred\)/.test(air) && /usePreferred = !!on/.test(air));

// 21. There is one location and it lives in the account: every signed-in load
//     re-writes it from the device, changing it re-writes it too, and the card
//     reads the saved row when signed in and the device when not.
check('the location is written back from the device on every signed-in load',
  /function syncLocationFromDevice\(\)/.test(js)
  && /async function saveDeviceLocation\(\)/.test(js)
  // Coordinates only: the server names the place with the same geocoder the
  // reading uses, so the card and this screen cannot disagree about the city.
  && /HazeAPI\.saveLocation\(\{ lat, lon \}\)/.test(js)
  && !/reverseGeocode\(/.test(js));
check('the session boot writes it, not only the profile screen',
  // The dashboard card lives outside Profile, so a load of any other screen has
  // to reach this too — otherwise rule 1 only holds when Profile happens to open.
  /sessionReady = true;[\s\S]{0,1600}await syncLocationFromDevice\(\)/.test(js));
check('the boot and the profile screen share one detection and one save',
  /if \(!locationSync\) locationSync = saveDeviceLocation\(\)/.test(js));
check('a signed-out visitor writes nothing, because there is nowhere to write it',
  /function syncLocationFromDevice\(\) \{[\s\S]{0,80}if \(!currentUser\) return false;/.test(js));
check('signing in without a reload hands the card over to the saved row',
  /function beginSession\(\)/.test(js)
  && (js.match(/beginSession\(\);/g) || []).length === 3);
check('logging out returns the card to the device reading',
  /usePreferredLocation\(false\)/.test(js));
check('Change opens a city search, not another device fix',
  /data-action="search-location"/.test(html) && /openPlaceSearch/.test(js)
  && !/data-action="save-location"/.test(html));
check('the search results are keyboard reachable',
  /ArrowDown/.test(js) && /ArrowUp/.test(js) && /moveActiveOption/.test(js)
  && /role="listbox"/.test(html) && /role="combobox"/.test(html));
check('the search is debounced and stale responses are discarded',
  /PLACE_SEARCH_DELAY/.test(js) && /state\.seq/.test(js) && /seq !== state\.seq/.test(js));
check('picking a result saves it as the preferred location',
  /function choosePlace/.test(js) && /HazeAPI\.saveLocation/.test(js)
  // The result rows are built in JS, not written in the template.
  && /place-list__item/.test(js) && /closest\('\.place-list__item'\)/.test(js));

// 22. the relocate button: a fresh fix for someone who has moved since the page
//     was opened, rather than waiting for the next reload.
check('a relocate control exists on the profile screen',
  /data-action="relocate-location"/.test(html));
check('it uses the locate icon and says what it does',
  /data-action="relocate-location"[\s\S]{0,200}#i-locate/.test(html)
  && /aria-label="Use my current location"/.test(html));
check('it forces a fresh detection rather than reusing the load\u2019s',
  /function relocateToCurrentPosition[\s\S]{0,500}locationSync = null;/.test(js));
check('it saves and then re-reads the air',
  /function relocateToCurrentPosition[\s\S]{0,500}syncLocationFromDevice\(\)/.test(js)
  // Every path out of saveDeviceLocation() re-reads: after a successful save it
  // has to, and on a refused fix or a failed save the card still has to leave the
  // device guess and go back to reading the saved row.
  && (js.match(/usePreferredLocation\(true\)/g) || []).length >= 3);
check('it cannot be double-fired while locating',
  /btn\.disabled = true/.test(js) && /btn\.disabled = false/.test(js));
check('a denied geolocation leaves the saved city untouched',
  /const GEO_DENIED = 1/.test(js)
  && /err\.code === GEO_DENIED/.test(js)
  // The card keeps reading the saved row, so the note says which one that is.
  && /Readings still use/.test(js)
  && /Location access was declined\./.test(js));
check('the icon-only button is square so the glyph stays centred',
  /\.btn--icon\s*\{[^}]*justify-content:\s*center/.test(css));

// 23. two regressions found by the owner on a real page load:
//     (a) the row shipped "Locating…" as its markup, so any detection path that
//         did not complete left it waiting forever with no explanation;
//     (b) the page fired two overlapping getCurrentPosition calls — the boot
//         refresh and the profile's own detection — which fight over the
//         permission prompt and can time out the second one.
check('the location row does not ship in a waiting state',
  !/data-pref-location-name[^>]*>Locating/.test(html)
  && /data-pref-location-name[^>]*>Not set/.test(html));
check('only a detection that has started may show Locating',
  (html.match(/Locating/g) || []).length === 0
  && /if \(nameEl\) nameEl\.textContent = 'Locating…'/.test(js));
check('every detection exit path leaves a definite label',
  // paintPreferences() is the one place a final label is written, and both
  // failure branches repaint through it — so nothing can fall through still
  // saying "Locating…".
  /nameEl\.textContent = loc \? \(loc\.name \|\| 'Unnamed place'\) : 'Not set';/.test(js)
  && (js.match(/paintPreferences\(\{ activities: currentActivities\(\), location: savedLocation \}\);/g) || []).length >= 2);
check('geolocation requests are shared, never concurrent',
  /let inflightFix = null/.test(air) && /if \(inflightFix\) \{ return inflightFix; \}/.test(air));
check('the shared request is released once it settles',
  /inflightFix\.then\(clear, clear\)/.test(air));

// 24. a signed-in load starts two air reads — the boot's device guess and the one
//     against the row just written — and the slower would otherwise repaint over
//     the newer, leaving whichever city happened to return last.
check('a slow air read cannot repaint over a newer one',
  /let refreshSeq = 0/.test(air)
  && (air.match(/if \(seq !== refreshSeq\) return;/g) || []).length >= 3
  && /const seq = \+\+refreshSeq;/.test(air));

// 25. the Haze Radar. Every one of these is a decision that is easy to undo by
//     accident and expensive to notice afterwards.
check('the map mounts into a real canvas, and the placeholder is gone',
  /id="radar-canvas"/.test(html) && !/id="map-placeholder"/.test(html)
  && !/Static preview: the interactive map arrives in Stage 3/.test(html));
check('Leaflet is vendored and loaded on demand, not as a blocking tag',
  fs.existsSync(path.join(root, 'media/com_hazeguard/assets/leaflet/leaflet.js'))
  && /document\.createElement\('script'\)/.test(radar)
  && /assets\/leaflet\/leaflet\.js/.test(radar)
  // and no eager <script src=…leaflet.js> in the template, which would make
  // eleven non-map screens download 147 KB they never use.
  && !/<script src="[^"]*leaflet\.js/.test(html));
check('the tile provider is one constant, so the compliance decision is one edit',
  (radar.match(/const TILES = \{/) || []).length === 1 && /tileLayer\(TILES\.url/.test(radar)
  && /attribution:/.test(radar));
check('the map is built on entry, because a hidden container has no size',
  /window\.HazeRadar\.enter\(\)/.test(js) && /function enter\(\)/.test(radar)
  && /invalidateSize\(\)/.test(radar));
check('the radar reads real grid data, and never the demo table',
  /HazeAPI\.airAround\(/.test(radar) && /\/api\/air\/around/.test(api)
  // PLACES/DEMO_SRC belonged to the old radar panel; the planner and dashboard
  // preview still use them, but the radar must not have gone back to them.
  && !/PLACES\[/.test(radar) && !/DEMO_SRC/.test(radar));
check('the radar never fabricates a reading',
  // a point with no data is dropped, not painted as zero
  /pm25 === null \? '—'/.test(radar) && /never a stand-in|Missing data is shown as missing/.test(radar));
check('the coarse model grid is stated on screen, not just in the payload',
  /data\.provenance/.test(radar) && /id="radar-provenance"/.test(html)
  && /coarser than the map/.test(fs.readFileSync(path.join(root, 'components/com_hazeguard/Model/AirModel.php'), 'utf8')));
check('the radar asks the browser through the shared fix, not its own call',
  /window\.HazeAir\.locate\(\)/.test(radar)
  // a call, not the word: the comment explaining why it used to be called
  // directly is allowed to say so.
  && !/navigator\.geolocation\.getCurrentPosition/.test(radar));
check('a slow grid load cannot repaint over a newer one',
  /const mine = \+\+seq/.test(radar) && /if \(mine !== seq\) return;/.test(radar));
check('both screens share one place-search widget',
  /PLACE_SEARCHES = \{/.test(js) && /onPick: choosePlace/.test(js) && /onPick: goToRadarPlace/.test(js)
  && /runPlaceSearch\(key\)/.test(js));
check('the radar list is a combobox popup, like the profile one',
  /id="radar-results"[^>]*role="listbox"/.test(html)
  && /id="radar-search"[\s\S]{0,200}role="combobox"/.test(html));
check('the radar no longer shows a Demo badge on real readings',
  /id="location-badge"/.test(html) && !/id="location-badge"[^>]*badge--demo/.test(html));

// 26. mobile. The owner reported three symptoms on a phone: cramped, the page
//     zooming in when a field is focused, and sideways scroll. Each maps to one
//     rule below, and each is cheap to undo by accident.
check('fields are never under 16px, or iOS zooms the page and never zooms back',
  // The specific regression: `font: inherit` over a 0.95rem body computed 15.2px.
  /--fs-input:\s*1rem/.test(css)
  && /\.input\s*\{[^}]*font:\s*inherit;[^}]*font-size:\s*var\(--fs-input\)/.test(css));
check('the layout is mobile-first, not desktop-first',
  // Only one max-width query, and it is the narrow end. A design that starts at
  // desktop loads every desktop rule on a phone.
  (css.match(/@media \(min-width:/g) || []).length >= 4
  && (css.match(/@media \(max-width:/g) || []).length <= 2
  && !/@media \(max-width:\s*10(2[4-9]|[3-9]\d)px\)/.test(css));
check('flex text children may shrink, or a long row scrolls the page sideways',
  // The trap: a flex item defaults to min-width:auto and refuses to shrink below
  // its content. An <input> carries an intrinsic width from its default size=20,
  // so the search row could not compress and pushed the page wide instead.
  /\.search__input\s*\{[^}]*min-width:\s*0/.test(css)
  && /\.chat-compose__input\s*\{[^}]*min-width:\s*0/.test(css)
  && /\.settings-row > \* \{ min-width: 0; \}/.test(css));
check('touch targets are sized by input method, not by viewport width',
  // A 1400px touchscreen laptop still needs a fingertip target, and a 390px
  // phone has no hover at all. A width query gets both wrong.
  /@media \(pointer: coarse\)/.test(css)
  && /min-height:\s*48px/.test(css));
check('the notched phone is handled, not just the short one',
  /viewport-fit=cover/.test(html)
  && (css.match(/env\(safe-area-inset-bottom\)/g) || []).length >= 3);
check('coordinates may not wrap mid-number, but the city name beside them may',
  /\.settings-row__coords\s*\{[^}]*white-space:\s*nowrap/.test(css)
  && /\.settings-row__stack > :first-child \{ overflow-wrap: anywhere; \}/.test(css));

// 27. the landing header was four controls wide on a 390px phone. Measured, the
//     brand wordmark plus "Features", "How it works", "Sign in" and "Create
//     account" need about 590px, which is why the page scrolled sideways.
check('the mobile header keeps only the two account actions',
  // The anchors are the ones to drop: the hero below covers the same ground and
  // they are the only items in the bar that are not a task.
  /@media \(max-width: 767px\) \{[\s\S]{0,200}?\.topbar__link \{ display: none; \}/.test(css)
  // and they come back at the breakpoint the layout goes two-column, not at an
  // arbitrary width that merely happens to fit.
  && !/@media \(max-width: 400px\) \{[\s\S]{0,120}?\.topbar__link \{ display: none; \}/.test(css));
check('the brand mark still names the site when the wordmark is dropped',
  /\.brand__name \{ display: none; \}/.test(css)
  // Otherwise hiding the visible text would also drop the accessible name.
  && /class="brand"[^>]*aria-label="HazeGuard AI home"/.test(html));

// 24. a boot-order bug the owner hit as "auto reload, then it says Not set".
//     render() runs for the initial screen BEFORE initSession() resolves, so on a
//     direct load of #profile it ran with currentUser still null and
//     loadPreferences() returned immediately. The per-screen work has to be
//     re-run once the session is known, and re-calling render() cannot do it
//     because render() bails on `route === currentRoute`.
check('per-screen session work runs again once the session is known',
  // Both call sites must exist: the normal render, and the session boot. Matched
  // by call shape so the definition and the comment do not satisfy this.
  /^\s*onEnterScreen\(name\);$/m.test(js)
  && /^\s*onEnterScreen\(currentScreen\);$/m.test(js));
check('the session boot calls it for the screen already rendered',
  /sessionReady = true;[\s\S]{0,900}onEnterScreen\(currentScreen\)/.test(js));
check('it is guarded on being signed in, since boot may resolve signed out',
  /if \(name === 'profile' && currentUser\)/.test(js));

console.log('');
console.log(failures === 0 ? `ALL ${'STATIC'} CHECKS PASS` : `${failures} CHECK(S) FAILED`);
process.exit(failures === 0 ? 0 : 1);
