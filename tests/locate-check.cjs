/* Verify the shared geolocation promise: N concurrent locate() calls must produce
   exactly ONE getCurrentPosition request, and the promise must be released so a
   later call is a fresh request.

   Run: node tests/locate-check.cjs

   The function is lifted out of airquality.js and evaluated against a stub
   navigator, so this exercises the real implementation rather than a copy of it. */
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');

// Pull locate() out of airquality.js by evaluating it in a sandbox with a stubbed
// navigator, so the real function is exercised rather than a copy of it.
const src = fs.readFileSync(
  path.resolve(__dirname, '..', 'media/com_hazeguard/js/airquality.js'), 'utf8');

let calls = 0;
let ok = null;
let fail = null;

const sandbox = {
  navigator: {
    geolocation: {
      getCurrentPosition(onOk, onFail) {
        calls += 1;
        ok = onOk;                         // settled manually by the test
        fail = onFail;
      },
    },
  },
  document: { addEventListener() {}, visibilityState: 'visible' },
  window: {},
  setTimeout() { return 0; },
  clearTimeout() {},
  console,
};

// Minimal stand-ins for the module's own helpers.
sandbox.all = (sel) => [];
sandbox.text = () => {};

const locateSrc = src.slice(src.indexOf('  function locate()'));
const endOfLocate = locateSrc.indexOf('\n  }');
const body = locateSrc.slice(0, endOfLocate + 4);

const fn = new Function(
  'navigator', 'let_inflightHolder', 'refreshMsRef',
  `let inflightFix = null; let refreshMs = 3600000;
   ${body.replace('inflightFix', 'inflightFix')}
   return locate;`
);

const locate = fn(sandbox.navigator);

// Three concurrent callers, as a page load produces (boot refresh + profile).
const a = locate();
const b = locate();
const c = locate();

assert.strictEqual(calls, 1, `expected 1 getCurrentPosition call, got ${calls}`);
console.log('PASS  three concurrent callers issue exactly one request');

// All three receive the same answer.
ok({ coords: { latitude: 1.4927, longitude: 103.7414 } });
Promise.all([a, b, c]).then(([ra, rb, rc]) => {
  assert.deepStrictEqual(ra, { lat: 1.4927, lon: 103.7414 });
  assert.deepStrictEqual(rb, ra);
  assert.deepStrictEqual(rc, ra);
  console.log('PASS  every caller receives the same position');

  // Released after settling: a later call must be a NEW request, not a replay.
  const d = locate();
  assert.strictEqual(calls, 2, `expected a second request after settle, got ${calls}`);
  console.log('PASS  the request is released once settled, so a later fix is fresh');
  ok({ coords: { latitude: 3.1412, longitude: 101.68653 } });
  return d;
}).then(() => {
  // And a rejection must release it too, or one denial would wedge the page.
  const e = locate();
  assert.strictEqual(calls, 3);
  fail({ code: 1, message: 'denied' });
  return e.catch(() => 'rejected');
}).then((v) => {
  assert.strictEqual(v, 'rejected');
  console.log('PASS  a denial is delivered to the caller');
  const f = locate();
  assert.strictEqual(calls, 4, 'a denial must not wedge later requests');
  console.log('PASS  a denial releases the request too');
  console.log('\nALL LOCATE CHECKS PASS');
}).catch((err) => {
  console.error('FAIL ', err.message);
  process.exit(1);
});