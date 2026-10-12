/* The Haze Radar.
 *
 * A real Leaflet map with real readings on it. Everything it shows comes from
 * /api/air/around, which asks Open-Meteo about nineteen points in ONE request
 * (the provider accepts comma-separated coordinates and answers with an array in
 * the same order), so the map costs the same single upstream call the dashboard
 * instrument already made.
 *
 * Two things this file is careful about:
 *
 *  - **It never invents a number.** A point with no reading is dropped rather
 *    than drawn as zero, and an outage shows the "no readings" panel instead of
 *    an empty grid that could be mistaken for clean air.
 *  - **It never implies precision it does not have.** CAMS reads on an 11–45 km
 *    grid, so nearby pins legitimately share a value. `provenance` is printed
 *    under the map saying so, rather than letting nineteen confident dots imply
 *    street-level truth.
 *
 * The tile provider is a single constant below. The brief mandates Leaflet +
 * OpenStreetMap; README.md records a compliance note about map providers that is
 * the product owner's to resolve. Nothing else here needs to change when that is
 * decided.
 */
(function () {
  'use strict';

  const TILES = {
    url: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
    maxZoom: 19,
  };

  const DEFAULT_ZOOM = 11;
  const RADIUS_KM = 30;

  let map = null;
  let markerLayer = null;
  let markers = new Map();     // "lat,lon" -> marker, so a repaint updates rather than duplicates
  let seq = 0;                 // newest load wins; a slow earlier one must not repaint over it
  let centre = null;
  let selectedKey = null;

  /* ------------------------------------------------------------- the states -- */

  function showState(name) {
    ['radar-loading', 'radar-denied', 'radar-unavailable'].forEach((id) => {
      const el = document.getElementById(id);
      if (el) el.hidden = id !== 'radar-' + name;
    });
  }

  function sayUnavailable(text) {
    const el = $('#radar-unavailable-text');
    if (el) el.textContent = text;
    showState('unavailable');
  }

  /* ------------------------------------------------------------------- map -- */

  let leafletPromise = null;

  /**
   * Fetch Leaflet the first time the radar is opened, and never again.
   *
   * It is 147 KB and eleven of the twelve screens have no map, so a plain <script>
   * tag would make every signed-out visitor on a phone download it to never use
   * it. Injected instead, and cached across openings so the second visit costs
   * nothing.
   *
   * Resolves false rather than rejecting: a missing map library must degrade to
   * the "no readings" panel, not to a blank screen and a console error.
   */
  function loadLeaflet() {
    if (window.L) return Promise.resolve(true);
    if (leafletPromise) return leafletPromise;

    leafletPromise = new Promise((resolve) => {
      const tag = document.createElement('script');
      tag.src = `${window.HAZE_BASE}/media/com_hazeguard/assets/leaflet/leaflet.js`;
      tag.onload = () => resolve(true);
      tag.onerror = () => resolve(false);
      document.head.appendChild(tag);
    });

    return leafletPromise;
  }

  /**
   * Create the map once, lazily.
   *
   * Leaflet measures its container at construction, and a screen that is still
   * `hidden` has no size — building it at page load would silently produce a map
   * with no tiles. So it is built the first time the screen is actually shown.
   */
  async function ensureMap() {
    if (map) {
      map.invalidateSize();
      return map;
    }

    const canvas = $('#radar-canvas');
    if (!canvas) return null;

    if (!(await loadLeaflet()) || !window.L) return null;

    map = L.map(canvas, {
      center: [3.1412, 101.68653],
      zoom: DEFAULT_ZOOM,
      zoomControl: true,
      // Touch-first: a haze app is opened on a phone in one hand.
      tap: true,
    });

    L.tileLayer(TILES.url, { attribution: TILES.attribution, maxZoom: TILES.maxZoom }).addTo(map);
    markerLayer = L.layerGroup().addTo(map);

    return map;
  }

  /* ----------------------------------------------------------------- pins -- */

  function pinIcon(point, isCentre) {
    return L.divIcon({
      className: '',
      html: `<span class="aqi-pin aqi-pin--${point.band}${isCentre ? ' aqi-pin--centre' : ''}">${point.aqi}</span>`,
      iconSize: [34, 34],
      iconAnchor: [17, 17],
      popupAnchor: [0, -15],
    });
  }

  function keyOf(lat, lon) {
    return `${Number(lat).toFixed(4)},${Number(lon).toFixed(4)}`;
  }

  function paint(data) {
    if (!markerLayer) return;

    markerLayer.clearLayers();
    markers.clear();

    const centreKey = keyOf(data.centre.lat, data.centre.lon);

    data.points.forEach((point) => {
      const isCentre = keyOf(point.lat, point.lon) === centreKey;
      const marker = L.marker([point.lat, point.lon], {
        icon: pinIcon(point, isCentre),
        keyboard: true,
        title: `US AQI ${point.aqi} — ${point.label}`,
        alt: `US AQI ${point.aqi}, ${point.label}`,
      });

      marker.bindPopup(
        `<strong>US AQI ${point.aqi}</strong> · ${point.label}`
        + `<br>PM2.5 ${point.pm25 === null ? 'not reported' : point.pm25 + ' µg/m³'}`
        + `<br>PM10 ${point.pm10 === null ? 'not reported' : point.pm10 + ' µg/m³'}`
        + '<br><small>A modelled estimate at a sampled point, not a measurement at this exact spot.</small>'
      );

      marker.on('click', () => select(point));
      marker.addTo(markerLayer);
      markers.set(keyOf(point.lat, point.lon), marker);
    });

    const provenance = $('#radar-provenance');
    if (provenance) {
      provenance.textContent = `${data.provenance} Observed ${data.observedLabel}. `
        + `${data.sampled} points sampled, ${data.returned} with readings.`;
    }

    const badge = $('#location-badge');
    if (badge) {
      badge.textContent = data.stale ? 'Stale' : 'Live';
      badge.classList.toggle('badge--demo', !!data.stale);
    }

    // Fit the grid rather than trusting the default zoom, so a 30 km grid reads
    // the same on a phone as on a desktop and the radius knob stays honest.
    const bounds = L.latLngBounds(data.points.map((p) => [p.lat, p.lon]));
    if (bounds.isValid()) map.fitBounds(bounds, { padding: [24, 24], maxZoom: 13 });

    centre = data.centre;
    const nearest = data.points.find((p) => keyOf(p.lat, p.lon) === centreKey) || data.points[0];
    if (nearest) select(nearest, data.centre.name);
  }

  /* ------------------------------------------------------------ the panel -- */

  function select(point, label) {
    selectedKey = keyOf(point.lat, point.lon);

    markers.forEach((marker, key) => {
      const el = marker.getElement();
      if (el && el.querySelector('.aqi-pin')) {
        el.querySelector('.aqi-pin').classList.toggle('is-selected', key === selectedKey);
      }
    });

    const name = label || (point.aqi !== undefined ? `${point.lat.toFixed(3)}, ${point.lon.toFixed(3)}` : '');
    const title = $('#location-name');
    if (title) {
      title.innerHTML = '<svg class="icon icon--sm" aria-hidden="true"><use href="#i-location-pin"/></svg> ';
      title.append(document.createTextNode(name));
    }

    const aqi = $('#location-aqi');
    if (aqi) {
      aqi.textContent = point.aqi;
      aqi.style.color = point.band <= 2 ? 'var(--aqi-1-ink)' : point.band === 3 ? 'var(--aqi-2-ink)' : 'var(--aqi-3-ink)';
    }
    const pm25 = $('#location-pm25');
    if (pm25) pm25.textContent = point.pm25 === null ? '—' : point.pm25;
    const pm10 = $('#location-pm10');
    if (pm10) pm10.textContent = point.pm10 === null ? '—' : point.pm10;

    const src = $('#location-src');
    if (src) {
      src.textContent = `${point.label} · Open-Meteo Air Quality API (CAMS model grid, a modelled estimate `
        + 'rather than a ground-station measurement). This pin is a sampled point, not this exact spot.';
    }
  }

  /* ------------------------------------------------------------------ load -- */

  async function loadArea(lat, lon) {
    if (!(await ensureMap())) {
      sayUnavailable('The map library did not load, so there is no map to draw. The reading panel below still works.');
      return;
    }

    const mine = ++seq;
    showState('loading');

    try {
      const data = await HazeAPI.airAround(lat, lon, RADIUS_KM);
      // A newer load owns the map now: a slow earlier one has nothing to say, and
      // painting it would leave whichever city happened to answer last on screen.
      if (mine !== seq) return;
      showState('');
      paint(data);
    } catch (err) {
      if (mine !== seq) return;
      // No stand-in grid. An empty map reads as "clear air", which is the exact
      // dishonesty this product argues against.
      sayUnavailable(`${(err && err.message) || 'The air-quality service could not be reached.'} `
        + 'Missing data is shown as missing, never as zero.');
    }
  }

  /* --------------------------------------------------------------- actions -- */

  /** Go to a place named by the search, and read it. Does not save anything. */
  async function goTo(name, lat, lon) {
    if (!(await ensureMap())) return;

    renderPlaceResults('radar', [], '');
    setPlaceListOpen('radar', false);
    map.setView([Number(lat), Number(lon)], DEFAULT_ZOOM, { animate: false });

    await loadArea(Number(lat), Number(lon));

    // The grid's centre is unnamed for an explicit coordinate, so the searched
    // name is the only one there is. Written after the load so it is not wiped
    // by the panel repaint that comes with it.
    const nameEl = $('#location-name');
    if (nameEl) {
      nameEl.innerHTML = '<svg class="icon icon--sm" aria-hidden="true"><use href="#i-location-pin"/></svg> ';
      nameEl.append(document.createTextNode(name));
    }
  }

  /**
   * "Use my location".
   *
   * Goes through HazeAir.locate(), which shares the one in-flight geolocation
   * request with the profile screen. This screen used to call
   * navigator.geolocation directly, which is exactly the two-overlapping-requests
   * bug that made the profile row hang on a refresh.
   */
  async function locateOnRadar() {
    showState('loading');

    if (!window.HazeAir) {
      sayUnavailable('This build cannot detect a location. Search for a place instead.');
      return;
    }

    let coords;
    try {
      coords = await window.HazeAir.locate();
    } catch (err) {
      const denied = err && err.code === 1;
      const text = $('#radar-denied-text');
      if (text) {
        text.textContent = denied
          ? 'Your browser denied access — that is fine. Search for a place instead, or allow location in your site settings and retry.'
          : 'Your position could not be determined in time. Search for a place instead, or try again.';
      }
      showState(denied ? 'denied' : 'unavailable');
      if (!denied) {
        sayUnavailable('Your position could not be determined in time. Search for a place instead, or try again.');
      }
      return;
    }

    if (await ensureMap()) {
      map.setView([coords.lat, coords.lon], DEFAULT_ZOOM, { animate: false });
    }
    await loadArea(coords.lat, coords.lon);
  }

  /* ------------------------------------------------------------------ wire -- */

  $('#radar-search-form').addEventListener('submit', (e) => {
    e.preventDefault();
    // Same widget as the profile screen: submit only triggers a search; picking a
    // row is what actually goes anywhere, so Enter in the box is not a shortcut
    // for "go to the first guess".
    const state = placeSearch('radar');
    clearTimeout(state.timer);
    runPlaceSearch('radar');
  });

  $('#btn-locate').addEventListener('click', () => locateOnRadar());

  $$('[data-radar-retry]').forEach((b) => b.addEventListener('click', () => locateOnRadar()));
  $$('[data-radar-search-focus]').forEach((b) => b.addEventListener('click', () => { showState(''); $('#radar-search').focus(); }));
  $$('[data-radar-reset]').forEach((b) => b.addEventListener('click', () => showState('')));

  /**
   * Called when the screen is entered, including the very first visit of a page
   * load that landed straight on #radar.
   *
   * With no coordinate it asks the server, which centres on the saved location or
   * the default city — the same precedence the dashboard instrument uses, so the
   * two can never disagree about where "here" is.
   */
  async function enter() {
    if (!(await ensureMap())) return;
    await loadArea(centre ? centre.lat : undefined, centre ? centre.lon : undefined);
  }

  window.HazeRadar = { enter, locateOnRadar, goTo, showState };
})();
