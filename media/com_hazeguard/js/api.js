/* ==========================================================================
   HazeGuard AI — API client
   Thin fetch wrapper around the PHP JSON API (the site front controller, index.php).

   Responsibilities:
   - send/receive the JSON envelope { ok, data, error }
   - keep the CSRF token in sync (fetched from GET /api/auth/session, and
     refreshed automatically when a response returns a rotated one after login)
   - turn failures into typed errors: err.code, err.fields, err.status

   Exposed as window.HazeAPI. No framework, no build step.
   ========================================================================== */
(function () {
  'use strict';

  let csrfToken = null;

  // Set by the site template: "" at the web root, "/haze2" in a subdirectory.
  const BASE = window.HAZE_BASE || '';

  async function api(path, options) {
    const opts = options || {};
    const method = (opts.method || 'GET').toUpperCase();
    const headers = { Accept: 'application/json' };
    if (opts.body !== undefined && opts.body !== null) {
      headers['Content-Type'] = 'application/json';
    }
    if (method !== 'GET' && csrfToken) {
      headers['X-CSRF-Token'] = csrfToken;
    }

    let res;
    try {
      res = await fetch(BASE + path, {
        method,
        headers,
        credentials: 'same-origin',
        body: opts.body === undefined || opts.body === null ? undefined : JSON.stringify(opts.body),
      });
    } catch (networkError) {
      const err = new Error('Could not reach the server. Check your connection and try again.');
      err.code = 'network_error';
      err.fields = {};
      err.status = 0;
      throw err;
    }

    let payload = null;
    try { payload = await res.json(); } catch (parseError) { payload = null; }

    // Login rotates the CSRF token; keep the client copy current.
    if (payload && payload.data && typeof payload.data.csrf_token === 'string') {
      csrfToken = payload.data.csrf_token;
    }

    if (!res.ok || !payload || payload.ok === false) {
      const info = (payload && payload.error) || {};
      const err = new Error(info.message || 'Something went wrong. Please try again.');
      err.code = info.code || 'http_' + res.status;
      err.fields = info.fields || {};
      err.status = res.status;
      throw err;
    }

    return payload.data;
  }

  window.HazeAPI = {
    request: api,

    /** Fetch the CSRF token and current user in one call (used on app load). */
    bootstrap: () => api('/api/auth/session'),

    register: (username, email, password) =>
      api('/api/auth/register', { method: 'POST', body: { username, email, password } }),

    verifyEmail: (email, code) =>
      api('/api/auth/verify-email', { method: 'POST', body: { email, code } }),

    resendVerification: (email) =>
      api('/api/auth/resend-verification', { method: 'POST', body: { email } }),

    login: (identifier, password) =>
      api('/api/auth/login', { method: 'POST', body: { identifier, password } }),

    requestLoginOtp: (email) =>
      api('/api/auth/login/otp/request', { method: 'POST', body: { email } }),

    verifyLoginOtp: (email, code) =>
      api('/api/auth/login/otp/verify', { method: 'POST', body: { email, code } }),

    requestPasswordReset: (email) =>
      api('/api/auth/password/forgot', { method: 'POST', body: { email } }),

    resetPassword: (email, code, password) =>
      api('/api/auth/password/reset', { method: 'POST', body: { email, code, password } }),

    logout: () => api('/api/auth/logout', { method: 'POST', body: {} }),

    me: () => api('/api/auth/me'),

    /* The Haze Radar's grid: one upstream call for the whole area, so the map
       costs no more than the single reading already in front of it. Coordinates
       are optional — with none, the server centres on the saved location, then
       the default city, exactly as /air/current does. */
    airAround: (lat, lon, radiusKm) => {
      const q = [];
      if (lat !== undefined && lat !== null) q.push('lat=' + encodeURIComponent(lat));
      if (lon !== undefined && lon !== null) q.push('lon=' + encodeURIComponent(lon));
      if (radiusKm) q.push('radiusKm=' + encodeURIComponent(radiusKm));

      return api('/api/air/around' + (q.length ? '?' + q.join('&') : ''));
    },

    /* ---- profile: activity preferences, preferred location, account ----
       All of these resolve the account from the session cookie. There is no id
       in any of them, because the server never accepts one. */
    getPreferences: () => api('/api/profile/preferences'),

    saveActivities: (activities) =>
      api('/api/profile/activities', { method: 'PUT', body: { activities } }),

    /* Only lat/lon are sent. The server resolves the city name from them with the
       same reverse geocoder the reading uses, so a name cannot be smuggled in from
       the client and end up labelling every future reading. */
    saveLocation: (coords) =>
      api('/api/profile/location', { method: 'PUT', body: coords || { lat: null, lon: null } }),

    changePassword: (currentPassword, password) =>
      api('/api/profile/password', { method: 'POST', body: { current_password: currentPassword, password } }),

    deleteAccount: (password) =>
      api('/api/profile', { method: 'DELETE', body: { password } }),

    /* ---- places: a typed name becomes candidate places, a detected position
       becomes a name. Both reads, so no CSRF token is involved. */
    searchPlaces: (q) =>
      api('/api/places/search?q=' + encodeURIComponent(q)),

    reverseGeocode: (lat, lon) =>
      api(`/api/places/reverse?lat=${encodeURIComponent(lat)}&lon=${encodeURIComponent(lon)}`),

    get csrfToken() { return csrfToken; },
  };
})();
