/**
 * Browser pass over the OTP password-reset screens.
 *
 * The 217 acceptance checks prove the server. Nothing proves the client, and
 * this session rewrote the reset markup, the hash router (parseHash now returns
 * a bare screen name) and makeCountdown() (resend button found via [data-resend]
 * inside .otp-actions). A wrong selector in any of those passes every server
 * check green, so it has to be driven in a browser.
 *
 * The happy path needs the real six digits, which are only ever stored as a
 * SHA-256 hash, so the caller must have MAIL_DRIVER=log pointed at a directory
 * this script can read. It is pointed there and restored by the wrapper.
 *
 * Usage:  node tests/browser-reset.cjs [--headed]
 */
'use strict';

// Deliberately no node:child_process. On this machine Node cannot spawn any
// external binary — every spawn fails EBUSY, mysql.exe and chrome.exe alike —
// so Chrome is launched from the shell beforehand and this script only speaks
// CDP over its debug port.
const fs = require('node:fs');
const path = require('node:path');

const APP = 'http://localhost/haze2';
const PORT = 9222;
const OUT = path.join(__dirname, '..', 'storage');
const MAIL_DIR = path.join(OUT, 'mail-test');
const TEST_EMAIL = 'haze_tester@example.test';

const results = [];
function check(name, ok, detail = '') {
  results.push({ name, ok, detail });
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  -- ' + detail : ''}`);
}
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));


/** Newest 6-digit reset code the log mail driver wrote to $email, or null. */
function latestCode(email) {
  if (!fs.existsSync(MAIL_DIR)) return null;
  const files = fs.readdirSync(MAIL_DIR).filter((f) => f.endsWith('.txt')).sort().reverse();
  for (const f of files) {
    const body = fs.readFileSync(path.join(MAIL_DIR, f), 'utf8');
    if (body.includes(`To: ${email}\n`) && /reset the password/i.test(body)) {
      const m = /\b(\d{6})\b/.exec(body);
      if (m) return m[1];
    }
  }
  return null;
}

// ------------------------------------------------------------------ CDP glue
let ws = null;
let msgId = 0;
const pending = new Map();
const jsErrors = [];

function send(method, params = {}, sessionId) {
  const id = ++msgId;
  ws.send(JSON.stringify({ id, method, params, ...(sessionId ? { sessionId } : {}) }));
  return new Promise((resolve, reject) => pending.set(id, { resolve, reject }));
}

async function evaluate(sessionId, expression) {
  const r = await send('Runtime.evaluate',
    { expression, awaitPromise: true, returnByValue: true }, sessionId);
  if (r.exceptionDetails) {
    throw new Error(r.exceptionDetails.exception?.description || 'evaluate threw');
  }
  return r.result.value;
}

async function shot(sessionId, name) {
  const r = await send('Page.captureScreenshot', { format: 'png' }, sessionId);
  fs.writeFileSync(path.join(OUT, name), Buffer.from(r.data, 'base64'));
}

async function connect() {
  for (let i = 0; i < 80; i++) {
    try {
      const v = await (await fetch(`http://127.0.0.1:${PORT}/json/version`)).json();
      return v.webSocketDebuggerUrl;
    } catch {
      await sleep(500);
    }
  }
  throw new Error('Chrome never opened its debug port');
}

// ---------------------------------------------------------------------- main
(async () => {
  
  // Rate-limit budget: 3 reset requests per address per 15 minutes. Cleared from
  // the shell before this runs — Node cannot spawn mysql.exe here.
  fs.mkdirSync(MAIL_DIR, { recursive: true });
  const before = new Set(fs.readdirSync(MAIL_DIR));

  // Chrome is already listening on PORT; connect to it rather than launching it.
  const wsUrl = await connect();
  ws = new WebSocket(wsUrl);
  await new Promise((res, rej) => {
    ws.addEventListener('open', res, { once: true });
    ws.addEventListener('error', rej, { once: true });
  });
  ws.addEventListener('message', (ev) => {
    const m = JSON.parse(ev.data);
    if (m.id && pending.has(m.id)) {
      const { resolve, reject } = pending.get(m.id);
      pending.delete(m.id);
      m.error ? reject(new Error(JSON.stringify(m.error))) : resolve(m.result);
    } else if (m.method === 'Runtime.exceptionThrown') {
      jsErrors.push(m.params?.exceptionDetails?.exception?.description || 'exception');
    }
  });

  const { targetId } = await send('Target.createTarget', { url: 'about:blank' });
  const { sessionId } = await send('Target.attachToTarget', { targetId, flatten: true });
  await send('Page.enable', {}, sessionId);
  await send('Runtime.enable', {}, sessionId);

  const FILL = `(pairs) => {
    for (const [id, v] of pairs) {
      const i = document.querySelector('#' + id);
      i.value = v;
      i.dispatchEvent(new Event('input', { bubbles: true }));
    }
    document.querySelector(FORM).requestSubmit();
    return true;
  }`;
  const fillAndSubmit = (form, pairs) =>
    evaluate(sessionId, `(${FILL})(FORM=${JSON.stringify(form)}, PAIRS=${JSON.stringify(pairs)})`
      .replace('FORM=', '').replace('PAIRS=', ''));

  try {
    // ---- 1. the forgot screen ------------------------------------------
    await send('Page.navigate', { url: `${APP}/?t=${Date.now()}#forgot` }, sessionId);
    await sleep(1400);

    check('the forgot screen renders',
      await evaluate(sessionId, `!document.querySelector('#screen-forgot').hidden`));
    check('the success box is gone (it used to claim a mail had been sent)',
      await evaluate(sessionId, `document.querySelector('#forgot-success') === null`));
    check('the copy says code, not link',
      await evaluate(sessionId, `/6-digit code/.test(
        document.querySelector('#screen-forgot .auth__sub').textContent)`));
    check('no auth screen still claims to be a prototype',
      await evaluate(sessionId, `!/Prototype: no email was sent/.test(
        document.body.innerText)`));
    await shot(sessionId, 'reset-1-forgot.png');

    // ---- 2. submitting moves to the reset screen ------------------------
    const submitForgot = (email) => evaluate(sessionId, `(() => {
      const i = document.querySelector('#forgot-email');
      i.value = ${JSON.stringify(email)};
      i.dispatchEvent(new Event('input', { bubbles: true }));
      document.querySelector('#form-forgot').requestSubmit();
      return true;
    })()`);

    await submitForgot(TEST_EMAIL);
    await sleep(1800);

    check('submitting the address lands on the reset screen',
      await evaluate(sessionId, `!document.querySelector('#screen-reset').hidden`));
    check('the form is shown and usable (the address is a field, not a gate)',
      await evaluate(sessionId, `!document.querySelector('#form-reset').hidden`));
    check('the address is pre-filled from the request',
      await evaluate(sessionId,
        `document.querySelector('#reset-email').value === ${JSON.stringify(TEST_EMAIL)}`),
      await evaluate(sessionId, `document.querySelector('#reset-email').value`));
    check('the screen names the address the code went to',
      await evaluate(sessionId,
        `document.querySelector('#reset-target').textContent === ${JSON.stringify(TEST_EMAIL)}`),
      await evaluate(sessionId, `document.querySelector('#reset-target').textContent`));
    check('the code field is a 6-digit numeric input',
      await evaluate(sessionId, `(() => {
        const i = document.querySelector('#reset-code');
        return !!i && i.maxLength === 6 && i.inputMode === 'numeric';
      })()`));
    check('no URL query is left on the route',
      await evaluate(sessionId, `!location.hash.includes('token')`), 'hash = ' +
      (await evaluate(sessionId, 'location.hash')));
    await shot(sessionId, 'reset-2-code-entry.png');

    // ---- 3. countdown + resend wiring ------------------------------------
    const secs = await evaluate(sessionId,
      `document.querySelector('#reset-countdown [data-otp-seconds]').textContent`);
    check('the countdown is running',
      Number(secs) > 0 && Number(secs) < 45, 'seconds = ' + secs);
    check('the countdown found its resend button (no id ternary)',
      await evaluate(sessionId, `!!document.querySelector('#reset-countdown')
        .closest('.otp-actions').querySelector('[data-resend]')`));
    check('the resend button is hidden while the countdown runs',
      await evaluate(sessionId, `document.querySelector('#reset-resend').hidden`));

    await evaluate(sessionId, `(() => {
      document.querySelector('#reset-countdown [data-otp-seconds]').textContent = '1';
      return true;
    })()`);
    await sleep(1800);
    check('the resend button appears when the countdown ends',
      await evaluate(sessionId, `!document.querySelector('#reset-resend').hidden
        && document.querySelector('#reset-countdown').hidden`));
    await shot(sessionId, 'reset-3-resend-revealed.png');

    // ---- 4. a wrong code is refused, form survives ------------------------
    await evaluate(sessionId, `(() => {
      const set = (id, v) => {
        const i = document.querySelector('#' + id);
        i.value = v; i.dispatchEvent(new Event('input', { bubbles: true }));
      };
        set('reset-email', ${JSON.stringify(TEST_EMAIL)});
        set('reset-code', '000000');
        set('reset-password', 'browser-pass-1');
        set('reset-confirm', 'browser-pass-1');
      document.querySelector('#form-reset').requestSubmit();
      return true;
    })()`);
    await sleep(1800);

    const errText = await evaluate(sessionId,
      `document.querySelector('[data-error-for="reset-code"]').textContent`);
    check('a wrong code is reported on the code field',
      await evaluate(sessionId, `(() => {
        const b = document.querySelector('[data-error-for="reset-code"]');
        return !!b && !b.hidden && b.textContent.length > 0;
      })()`), errText);
    check('the form stays usable after a wrong code (a fresh code fixes it)',
      await evaluate(sessionId, `!document.querySelector('#form-reset').hidden`));
    check('the wrong code signed nobody in',
      await evaluate(sessionId, `document.querySelector('#shell').hidden`));
    await shot(sessionId, 'reset-4-wrong-code.png');

    // ---- 5. the real code resets the password ---------------------------
    const code = latestCode(TEST_EMAIL);
    check('the emailed code is readable from the mail log', code !== null, code || 'none');

    if (code) {
      const newPw = 'browser-reset-' + Date.now();
      await evaluate(sessionId, `(() => {
        const set = (id, v) => {
          const i = document.querySelector('#' + id);
          i.value = v; i.dispatchEvent(new Event('input', { bubbles: true }));
        };
        set('reset-email', ${JSON.stringify(TEST_EMAIL)});
        set('reset-code', ${JSON.stringify(code)});
        set('reset-password', ${JSON.stringify(newPw)});
        set('reset-confirm', ${JSON.stringify(newPw)});
        document.querySelector('#form-reset').requestSubmit();
        return true;
      })()`);
      await sleep(2200);

      check('the real code resets the password and lands on sign-in',
        await evaluate(sessionId, `!document.querySelector('#screen-login').hidden`),
        'hash = ' + (await evaluate(sessionId, 'location.hash')));
      await shot(sessionId, 'reset-5-success-to-login.png');

      // A reload loses the in-memory pre-fill, but the address is a field on the
      // screen — the code may have been read on a phone — so the form must stay
      // usable and simply ask for the address again.
      await send('Page.navigate', { url: `${APP}/?t=${Date.now()}#reset` }, sessionId);
      await sleep(1400);
      check('after a reload the form is still usable (the address is a field)',
        await evaluate(sessionId, `!document.querySelector('#form-reset').hidden`));
      check('and the pre-fill is gone, with the screen naming no address',
        await evaluate(sessionId, `document.querySelector('#reset-email').value === ''
          && document.querySelector('#reset-target').textContent === 'your inbox'`));
      check('the countdown does not offer a resend for a request this tab never made',
        await evaluate(sessionId, `document.querySelector('#reset-countdown').hidden`));

      // Prove the new password works, through the real login form.
      await send('Page.navigate', { url: `${APP}/?t=${Date.now()}#login` }, sessionId);
      await sleep(1400);
      await evaluate(sessionId, `(() => {
        const set = (id, v) => {
          const i = document.querySelector('#' + id);
          i.value = v; i.dispatchEvent(new Event('input', { bubbles: true }));
        };
        set('login-email', ${JSON.stringify(TEST_EMAIL)});
        set('login-email-pw', ${JSON.stringify(newPw)});
        // The email+password panel is #panel-login-email: the login screen holds
        // three tabpanels and no form with the id form-login.
        document.querySelector('#panel-login-email').requestSubmit();
        return true;
      })()`);
      await sleep(2500);
      check('the new password signs in',
        await evaluate(sessionId, `!document.querySelector('#shell').hidden`),
        'screen = ' + (await evaluate(sessionId, "document.body.dataset.screen || '?'")));
      await shot(sessionId, 'reset-6-signed-in.png');
      console.log(`\nnew password for ${TEST_EMAIL}: ${newPw}`);
    }

    // ---- 6. a stale emailed link lands somewhere sensible ----------------
    await send('Page.navigate',
      { url: `${APP}/?t=${Date.now()}#reset?token=${'a'.repeat(64)}` }, sessionId);
    await sleep(1400);
    check('a stale #reset?token=... link lands on a working reset screen',
      await evaluate(sessionId, `!document.querySelector('#screen-reset').hidden
        && !document.querySelector('#form-reset').hidden`),
      'hash = ' + (await evaluate(sessionId, 'location.hash')));
    check('no withdrawn-notice element is left behind from the old link design',
      await evaluate(sessionId, `document.querySelector('#reset-invalid') === null`));
    check('the screen never names an address the visitor did not give it',
      await evaluate(sessionId, `document.querySelector('#reset-target').textContent === 'your inbox'`));
    await shot(sessionId, 'reset-7-stale-link.png');

    // ---- 7. nothing threw -----------------------------------------------
    check('no uncaught JavaScript error in the whole pass',
      jsErrors.length === 0, jsErrors.join(' | ') || 'clean');

    const newMail = fs.readdirSync(MAIL_DIR).filter((f) => !before.has(f));
    console.log(`\nmail files written this pass: ${newMail.length}`);
    for (const f of newMail) console.log('  ' + f);

    const failed = results.filter((r) => !r.ok).length;
    console.log('');
    console.log(failed === 0
      ? `ALL ${results.length} BROWSER CHECKS PASS`
      : `${failed} of ${results.length} BROWSER CHECKS FAILED`);
    process.exitCode = failed === 0 ? 0 : 1;
  } finally {
    // The browser was launched outside this process, so closing the socket is all
    // it leaves behind; killing it here would close the harness, not Chrome.
    try { ws.close(); } catch {}
  }
})().catch((err) => {
  console.error('browser pass failed:', err.message);
  process.exitCode = 1;
});
