/* ==========================================================================
   HazeGuard AI — live air-quality readings (client half)

   The reading is no longer computed here. `GET /api/air/current` makes the
   upstream calls, resolves the city, picks the AQI band and writes every
   user-facing string; see components/com_hazeguard/Model/AirModel.php and
   components/com_hazeguard/Controller/AirController.php.

   What is left is only what a server cannot do:
     1. Ask the browser for a position. navigator.geolocation is a browser API
        mediated by the OS — no amount of PHP can call it, so the coordinate
        has to be collected here and handed over.
     2. Write the server's answer into the DOM.
     3. Keep the hourly timer, so a page left open does not present last hour's
        reading as if it were the current one.

   Three rules this file exists to keep:
     - The timestamp is always shown, so a stale number can never be mistaken
       for a current one.
     - A failure never leaves a placeholder number on screen wearing a live
       badge. If there is no reading, the instrument says so.
     - A city name is never invented: the server falls back to coordinates
       rather than guessing, and this file never prints a place it did not
       receive.
   ========================================================================== */
(function () {
  'use strict';

  const ENDPOINT = '/api/air/current';

  /* Replaced by the server's own figure on the first successful response, so
     the refresh policy lives with the data rather than being restated here. */
  let refreshMs = 60 * 60 * 1000;

  let timer = null;       // the hourly refresh
  let lastFetchAt = 0;    // when the last attempt finished
  let lastGood = null;    // the last successful payload

  /* When true, refresh() sends no coordinate at all and lets the server use the
     account's saved location. Set by app.js for any signed-in load — that is
     rule 3, the card reads the saved row — and again the moment a different
     city is chosen.

     The device position is deliberately NOT sent in that case: the saved row is
     the place this account reads for, so a phone that happens to be elsewhere
     must not quietly win. An explicit coordinate from the Haze Radar's own
     search is a different thing — that is the user pointing at a spot — so
     lookup() keeps sending coordinates and this flag does not affect it. */
  let usePreferred = false;

  /* Highest refresh() number so far. Newest wins: a signed-in load starts two
     reads — the boot's device guess and the one against the coordinates just
     saved — and without a sequence the slower of the two would decide the card
     by repainting over the other, whichever order they were started in. */
  let refreshSeq = 0;

  function all(selector) { return Array.prototype.slice.call(document.querySelectorAll(selector)); }
  function text(selector, value) { all(selector).forEach((el) => { el.textContent = value; }); }

  /* The browser's own location, as a promise. Rejects on denial or timeout.
     The one piece of this feature that cannot move to PHP.

     One request at a time, shared. A page load fires this from two places at
     once — the boot refresh here, and the profile screen's own detection — and
     two overlapping getCurrentPosition calls fight each other: the second queues
     behind the first's permission prompt or GPS wake and can come back timed out
     while the first succeeded. Every caller awaits the same in-flight request
     instead, so a page load costs one prompt and one fix. */
  let inflightFix = null;

  function locate() {
    if (inflightFix) { return inflightFix; }

    inflightFix = new Promise((resolve, reject) => {
      if (!navigator.geolocation) {
        reject(new Error('this browser has no Geolocation API'));
        return;
      }
      navigator.geolocation.getCurrentPosition(
        (pos) => resolve({ lat: pos.coords.latitude, lon: pos.coords.longitude }),
        (err) => reject(err),
        /* An hour of tolerance: the browser can answer from its own cache
           instead of waking the GPS, which matters on a phone. */
        { timeout: 10000, maximumAge: refreshMs, enableHighAccuracy: false }
      );
    });

    // Cleared as soon as it settles, so a later fix is a fresh request rather
    // than a replayed one. The .then chain rather than .finally so the stored
    // promise is cleared before the caller's handler runs.
    const clear = () => { inflightFix = null; };
    inflightFix.then(clear, clear);

    return inflightFix;
  }

  /* The server's reading for a position, or for its default city when no
     position is given. Uses the shared API client, so the envelope, the base
     path and the error shapes are handled in one place. */
  async function reading(coords) {
    if (!window.HazeAPI) {
      throw new Error('The air-quality service is unavailable in this build.');
    }

    const query = coords
      ? `?lat=${encodeURIComponent(coords.lat)}&lon=${encodeURIComponent(coords.lon)}`
      : '';

    const data = await window.HazeAPI.request(ENDPOINT + query);

    if (Number.isFinite(data.refreshMs) && data.refreshMs > 0) {
      refreshMs = data.refreshMs;
    }

    return data;
  }

  function showBadge(variant, label) {
    all('[data-aqi-badge]').forEach((b) => {
      b.className = 'badge badge--' + variant;
      b.textContent = label;
    });
  }

  /* The server's payload, placed into the DOM. No arithmetic, no banding and
     no wording decisions happen below this line. */
  function paint(data) {
    const cat = data.category || { label: 'Unavailable', ribbon: 'ribbon--none' };

    text('[data-aqi-place]', data.place.name);
    text('[data-aqi-value]', data.aqi);
    text('[data-aqi-cat]', cat.label);
    text('[data-aqi-label]', 'US AQI · live reading');

    const band = document.querySelector('[data-aqi-band]');
    if (band) {
      band.setAttribute('aria-label', data.bandLabel);
    }
    all('[data-aqi-marker]').forEach((m) => {
      m.style.display = '';
      m.style.setProperty('--pos', `${data.bandPos}%`);
    });

    const ribbon = document.querySelector('[data-aqi-ribbon]');
    if (ribbon) {
      ribbon.className = 'ribbon ' + cat.ribbon;
      ribbon.textContent = cat.label;
    }

    text('[data-aqi-pm25]', data.pm25 === null ? '—' : data.pm25);
    text('[data-aqi-pm10]', data.pm10 === null ? '—' : data.pm10);

    const freshness = data.stale
      ? `Could not refresh just now, so this is the reading from ${data.observedLabel}.`
      : `Updated ${data.observedLabel}.`;

    text('[data-aqi-src]', `${data.provenance} · ${freshness} ${data.sourceNote}`);

    // The badge flips last: it must not read "live" before the values are real.
    showBadge(data.stale ? 'stale' : 'live', data.stale ? 'Stale data' : 'Live data');
  }

  /* No reading at all. Deliberately shows no number: a placeholder wearing a
     live badge is how a stale 72 gets read as today's air. */
  function paintUnavailable(reason) {
    text('[data-aqi-value]', '—');
    text('[data-aqi-cat]', 'Unavailable');
    text('[data-aqi-label]', 'US AQI · no live reading');
    text('[data-aqi-pm25]', '—');
    text('[data-aqi-pm10]', '—');

    const band = document.querySelector('[data-aqi-band]');
    if (band) { band.setAttribute('aria-label', 'Air quality index unavailable'); }
    // The marker sits at --pos; leaving it at 0% would read as "AQI 0".
    all('[data-aqi-marker]').forEach((m) => { m.style.display = 'none'; });

    const ribbon = document.querySelector('[data-aqi-ribbon]');
    if (ribbon) { ribbon.className = 'ribbon ribbon--none'; ribbon.textContent = 'Unavailable'; }

    showBadge('stale', 'No live data');
    text('[data-aqi-src]', `${reason} No stand-in value is shown — missing data is shown as missing, never as a number.`);
  }

  function schedule() {
    clearTimeout(timer);
    const due = Math.max(refreshMs - (Date.now() - lastFetchAt), 1000);
    timer = setTimeout(refresh, due);
  }

  async function refresh() {
    const seq = ++refreshSeq;
    let coords = null;

    if (usePreferred) {
      /* Deliberately no geolocation call and no query string: the server already
         knows the saved location, and asking the browser again would only risk
         overriding the choice with wherever the device happens to be. */
      coords = null;
    } else {
      try {
        coords = await locate();
      } catch (e) {
        /* Denied, timed out, or unsupported. Not a failure: the server answers
           for its own default city and labels it as not being theirs. */
      }
    }

    // Superseded while waiting on the fix: a newer refresh owns the card now,
    // and this one has nothing left to say — including no schedule(), since the
    // newer one already set its own timer.
    if (seq !== refreshSeq) return;

    lastFetchAt = Date.now();

    try {
      const data = await reading(coords);
      if (seq !== refreshSeq) return;
      lastGood = data;
      paint(data);
    } catch (e) {
      if (seq !== refreshSeq) return;
      console.warn('[hazeguard] live air quality unavailable:', e.message);
      // A failed refresh must not throw away a reading we already have — it
      // just has to stop claiming to be current.
      if (lastGood) {
        paint({ ...lastGood, stale: true });
      } else {
        paintUnavailable(e.message);
      }
    }

    schedule();
  }

  /* Public: used by the Haze Radar's "locate me" button, which needs the same
     reading for arbitrary coordinates. Returns the shape updateLocationPanel wants. */
  async function lookup(lat, lon) {
    const data = await reading({ lat, lon });

    return {
      name: data.place.name,
      aqi: data.aqi,
      pm25: data.pm25 === null ? '—' : data.pm25,
      pm10: data.pm10 === null ? '—' : data.pm10,
      src: data.sourceShort,
    };
  }

  /* A laptop that slept through its timer should catch up the moment it is
     looked at again, not an hour later. */
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') { return; }
    if (lastFetchAt && Date.now() - lastFetchAt >= refreshMs) { refresh(); }
  });

  window.HazeAir = {
    refresh,
    lookup,
    locate,
    reading,

    /* Point every future reading at the account's saved location, and refresh
       immediately so the instrument changes now rather than at the next hourly
       tick. app.js calls this after loading preferences and after saving one. */
    usePreferredLocation(on = true) {
      usePreferred = !!on;
      return refresh();
    },

    get refreshMs() { return refreshMs; },
    get usingPreferredLocation() { return usePreferred; },
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', refresh);
  } else {
    refresh();
  }
})();
