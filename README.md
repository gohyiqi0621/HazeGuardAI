# HazeGuard AI — Joomla-style MVC layout (plain PHP)

This is the HazeGuard AI project reorganised to mirror **Joomla's component MVC  
conventions** in plain PHP. No Joomla framework, no Joomla template syntax, no  
Composer. Behaviour and the database schema are unchanged.

## Folder map

| Folder / file                                           | Joomla equivalent                          | What lives here                                                                                                              |
| ------------------------------------------------------- | ------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------- |
| `index.php`                                             | site `index.php`                           | Front controller + route table (`/api/*`, `/`, `/media/*`)                                                                   |
| `administrator/index.php`                               | `administrator/index.php`                  | Admin front controller (`?option=…&view=…&task=…`)                                                                           |
| `bootstrap.php`                                         | `includes/framework.php`                   | Autoloader, config, timezone, error handling                                                                                 |
| `libraries/HazeGuard/`                                  | `libraries/src/`                           | The shared "framework": `Config`, `Database`, `Session`, `Csrf`, `Request`, `Response`, `Validator`, `RateLimiter`, `Mailer` |
| `libraries/HazeGuard/Mvc/Controller/BaseController.php` | `Joomla\CMS\MVC\Controller\BaseController` | Runs one controller task                                                                                                     |
| `libraries/HazeGuard/Mvc/View/JsonView.php`             | `Joomla\CMS\MVC\View\JsonView`             | Renders the JSON envelope                                                                                                    |
| `libraries/HazeGuard/Mvc/View/HtmlView.php`             | `Joomla\CMS\MVC\View\HtmlView`             | Renders `tmpl/<view>/<layout>.php`                                                                                           |
| `components/com_hazeguard/Controller/`                  | `components/com_x/src/Controller`          | `AuthController` — one method per API route                                                                                  |
| `components/com_hazeguard/Model/`                       | `…/src/Model`                              | `AuthModel`, `TokenModel` — business rules and SQL                                                                           |
| `components/com_hazeguard/View/`                        | `…/src/View`                               | `View/Auth/JsonView` — the auth API's presentation                                                                           |
| `components/com_hazeguard/hazeguard.xml`                | component manifest                         | Documents the component                                                                                                      |
| `administrator/components/com_hazeguard/`               | same, admin side                           | Dashboard overview + four CRUD panels: `Controller`, `Model`, `View`, `tmpl`                                                 |
| `media/com_hazeguard/`                                  | `media/com_x`                              | Site css / js / fonts                                                                                                        |
| `templates/hazeguard/index.php`                         | `templates/<name>/index.php`               | The single-page app shell                                                                                                    |
| `database/`                                             | `sql/`                                     | `schema.sql`, `bootstrap.sql`, `migrations/`                                                                                 |
| `tests/`                                                | —                                          | Acceptance suite + static checks                                                                                             |
| `storage/`                                              | —                                          | Mail log (development driver only)                                                                                           |

## How one request flows through the MVC layers

```
POST /api/auth/login
  -> index.php                     route table: [AuthController::class, 'login']
  -> AuthController::login()       reads input (HazeGuard\Request)
  -> AuthModel::login()            business rules, SQL, session  (the Model)
  -> View\Auth\JsonView            builds the JSON envelope      (the View)
  -> HazeGuard\Response            status code + headers
```

The administrator side is the same three roles with an HTML view instead:

```
GET /administrator/index.php?option=com_hazeguard&view=dashboard&task=display
  -> administrator/index.php       validates option/view, runs the task
  -> DashboardController::display()  checks access
  -> DashboardModel                counts + newest accounts (read-only)
  -> View\Dashboard\HtmlView       renders tmpl/dashboard/default.php
```

A management panel follows the same shape. `view` names the entity, so one  
controller serves every panel:

```
GET /administrator/index.php?option=com_hazeguard&view=usergroup&task=edit&id=2
  -> administrator/index.php       view=usergroup -> new AdminController('usergroup')
  -> AdminController::edit()       administrator gate, then loads the row
  -> AdminModel                    list/find/create/update/delete + foreign-key checks
  -> View\Dashboard\HtmlView       renders tmpl/dashboard/panel.php
```

## Running it

```bash
cp .env.example .env                 # then set the database credentials
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'   # paste into CREDENTIAL_KEY
php -S localhost:8000 index.php      # site + JSON API
php tests/run.php                    # 278 backend acceptance checks
node tests/static-check.cjs          # 113 frontend static checks
node tests/locate-check.cjs          # geolocation promise sharing
php tests/encoding-check.php         # mojibake scan
node tests/serve.cjs 8123            # static server for tests/responsive.html
```

- App shell: <http://localhost:8000/>
- Admin dashboard: <http://localhost:8000/administrator/index.php?option=com_hazeguard\&view=dashboard>

Only `/api/*`, `/`, `/media/*` and the administrator entry point are reachable;  
every other path returns 404, so `.env` and the source files stay private.

## API routes

| Method | Path                            | Controller task                      |
| ------ | ------------------------------- | ------------------------------------ |
| GET    | `/api/auth/session`             | `AuthController::session`            |
| GET    | `/api/auth/me`                  | `AuthController::me`                 |
| POST   | `/api/auth/register`            | `AuthController::register`           |
| POST   | `/api/auth/verify-email`        | `AuthController::verifyEmail`        |
| POST   | `/api/auth/resend-verification` | `AuthController::resendVerification` |
| POST   | `/api/auth/login`               | `AuthController::login`              |
| POST   | `/api/auth/login/otp/request`   | `AuthController::requestOtp`         |
| POST   | `/api/auth/login/otp/verify`    | `AuthController::verifyOtp`          |
| POST   | `/api/auth/password/forgot`     | `AuthController::forgotPassword`     |
| POST   | `/api/auth/password/reset`      | `AuthController::resetPassword`      |
| POST   | `/api/auth/logout`              | `AuthController::logout`             |
| GET    | `/api/air/current`              | `AirController::current`             |
| GET    | `/api/places/search?q=`         | `PlacesController::search`           |
| GET    | `/api/places/reverse?lat=&lon=` | `PlacesController::reverse`         |
| GET    | `/api/profile/preferences`      | `ProfileController::preferences`     |
| PUT    | `/api/profile/activities`       | `ProfileController::saveActivities`  |
| PUT    | `/api/profile/location`         | `ProfileController::saveLocation`    |
| POST   | `/api/profile/password`         | `ProfileController::changePassword`  |
| DELETE | `/api/profile`                  | `ProfileController::deleteAccount`   |

Response shape: `{"ok": bool, "data": …, "error": {"code", "message", "fields"?}|null}`.  
Every `POST`, `PUT` and `DELETE` needs the `X-CSRF-Token` header. `GET /api/air/current` takes an  
optional `lat` and `lon`; with neither, or with either out of range, it answers  
for the default city and says so in `data.provenance`.

## Profile settings

`/api/profile/*` is the profile screen's stored state. **Every route takes the
account from the session cookie and never from the request** — there is no
`user_id` parameter anywhere in `ProfileController`, so there is nothing for a
caller to change.

| Route                  | What it does                                                     |
| ---------------------- | ---------------------------------------------------------------- |
| `GET /preferences`     | The activity chips and the saved location                         |
| `PUT /activities`      | Replaces the chosen activities                                    |
| `PUT /location`        | Saves or clears the preferred location (null coords clears it). **Coordinates only — the city name is resolved server-side** |
| `POST /password`       | Changes the password — **current password required**              |
| `DELETE /api/profile`  | Deletes the account and its data — **password required**          |

Three decisions worth knowing before changing any of it:

- **The current password is required even though the session already proves who
  this is.** It is the check that stops a borrowed or unattended session from
  locking the real owner out, and it is the one a reset link cannot offer.
- **Activities are a fixed allowlist**, not free text. A slug that is not in
  `ProfileModel::ACTIVITIES` is *refused* rather than silently dropped, so a typo
  in the client is visible instead of becoming a quietly smaller set.
- **Notifications are deliberately absent.** Nothing delivers a notification, so
  storing "daily summary: on" would record a promise the app cannot keep. The
  three switches are `disabled` in the markup and the screen says why. The
  column arrives with the alerts feature (T6.3) or not at all.

### Emptying a saved location

`PUT /api/profile/location` with no coordinates clears it. An earlier version of
`ProfileModel` took a partial set of columns and used
`location_lat = IF(:v IS NULL, location_lat, :v)` to leave absent columns alone —
which quietly made it **impossible to clear a location**, because clearing means
writing NULL and that expression refuses to. It now reads the one row and writes
all five columns. The behaviour is covered by a test named *"clearing the location
really writes NULL"*.

### Sessions after a password change

PHP keeps sessions in files, so a password change has no list of them to clear —
this was the gap T1B.5 carried since the first reset implementation. It is now
closed by `users.password_changed_at`: both `AuthModel::resetPassword()` and
`ProfileModel::changePassword()` stamp it, and `AuthController::session()` ends
any session older than that stamp. One extra query per page load, not per
request, because the client asks `GET /api/auth/session` once at boot. The issue
time lives in its own session key (`Session::issuedAt()`), *beside* the identity
rather than inside it, so the guarantee that the login response and the stored
identity are the same value still holds byte for byte.

## Signing in

Authentication is live end to end — nothing on the sign-in or profile path is a
placeholder. Three ways in, all reaching the same session:

| Method         | Endpoint                        | What it needs                          |
| -------------- | ------------------------------- | -------------------------------------- |
| Email password | `POST /api/auth/login`          | a verified account                     |
| Username + password | `POST /api/auth/login`     | the same identifier field              |
| Emailed code   | `POST /api/auth/login/otp/{request,verify}` | an `active` account         |

`register` → `verify-email` → `login` is the full path for a new account. An
unverified account is refused at sign-in with `email_not_verified` (the client
switches the user to the code panel); a suspended one with `account_suspended`.

**Where the pieces live.** `AuthModel` holds the rules and the SQL;
`AuthController` reads input and hands the result to `JsonView`;
`HazeGuard\Session` owns the cookie and the session payload; `media/…/js/api.js`
(`window.HazeAPI`) is the only thing that talks to those endpoints; `app.js`
owns `currentUser` and the one function that paints it, `renderUser()`.

**What the session holds.** `id`, `username`, `email` and `verified`. The first
three come from the row at sign-in; `verified` is read off `email_verified_at` at
the same moment, so it costs no extra query. It describes the session *as
granted*, not the row as it stands now — an account suspended mid-session keeps
its value until the session ends. Re-reading the row on every request would buy
only that one case. `AuthModel::publicUser()` is the single source for the shape,
and it is passed straight to `Session::login()`, so the identity the client
renders and the identity the server stored cannot drift apart. A session cookie
minted before `verified` existed has no such key; the client then says nothing
about verification rather than guessing.

**After a successful sign-in** the client sets `currentUser`, repaints the
identity, and routes to `dashboard` — the app screens are guarded, so a
signed-out visitor is bounced to `login`. Logout posts to `/api/auth/logout`,
clears `currentUser`, repaints the identity as empty and returns to `landing`.
The `Session::regenerate()` on both login and logout rotates the session id and
the CSRF token; `api.js` picks the new token up from the response.

### Password reset

Two steps, both unauthenticated: `password/forgot` emails a 6-digit code, and
`password/reset` redeems it together with the new password.

The credential is a **typed code, not a link**, and that is the whole point. A
link has to be built from `APP_URL` and then opened on a device that can reach
this server — both halves fail in ordinary use. A stale or subdirectory-less
`APP_URL` produced a link to a dead port, and a `localhost` link cannot be opened
at all when the mail is read on a phone. A code the visitor types has neither
problem, so the reset message contains no URL and `APP_URL` cannot break the flow.

Reset shares `email_otps` with verification and OTP login, under
`purpose='reset_password'`. The code is stored only as a SHA-256 hash, is
single-use, and expires on `OTP_TTL_SECONDS` (5 minutes). Unlike the 32-byte link
token this replaced, six digits *are* guessable, so a wrong-attempt cap applies:
`OTP_MAX_ATTEMPTS` (5) wrong guesses and the code is burned.

Five rules worth knowing before changing this:

- **`forgot` always reports success.** Only an address that exists *and* is
  active reaches the send, so any failure reported here would confirm that
  address is real. A mail failure is logged by `deliver()`, never returned —
  which is the opposite of `register()`, where a `mail_failed` is safe because
  the account was just created and there is nothing left to leak.
- **A wrong code, an absent code, and a code for an address with no account all
  answer identically.** Distinguishing them would turn this one endpoint into a
  way of testing whether an address is registered.
- **An expired code is the one thing that is told apart**, because a code has no
  room to explain itself and a visitor cannot tell "expired" from "mistyped"
  without being told. Worth being clear-eyed about: that does reveal that a code
  was issued, which reveals the account exists — but `register()` already answers
  *"That email is already registered"* outright, so this endpoint is not where
  that information is protected. If enumeration ever needs closing, start at
  `register()`, not here.
- **Only an active account is issued a code.** A pending account never proved
  the address; a suspended one must not be reachable this way.
- **A rejected password does not consume the code.** Validation runs before the
  code is redeemed, so a visitor who mistypes a short password can try again
  without requesting a fresh email.

Three rate limits guard the two endpoints: `reset:email` (3 per 15 minutes) and
`reset:ip` (10) on the request, and `resetverify:ip` (20) on the redeem. The last
one bounds spraying across many addresses from one client, which the per-code cap
alone does not — that cap only bounds guessing against a single address.

Resetting a password does **not** immediately sign out sessions that are already
open — PHP keeps sessions in files, so there is no list of them to destroy. It does
**end them the next time they are used**: see *Sessions after a password change*
under *Profile settings* below.

## Email

`HazeGuard\Mailer` has two drivers, chosen by `MAIL_DRIVER`:

| Driver  | What it does                                                                                                                                |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------------- |
| `log`   | Writes `.txt` files under `MAIL_LOG_DIR`. Development only — it refuses to run when `APP_ENV=production`, so codes cannot leak into a file. |
| `brevo` | Sends over the Brevo HTTP API.                                                                                                              |

Four messages go out, all from the auth flows:

| Message           | Sent when                                           |
| ----------------- | --------------------------------------------------- |
| Verification code | `register`, and `resend-verification`               |
| Welcome           | the code is accepted and the account becomes active |
| Sign-in code      | `login/otp/request`                                 |

### The Brevo API key

Resolved in this order:

1. `BREVO_API_KEY` in `.env` — the deployment override.
2. The **encrypted credential** named by `BREVO_CREDENTIAL` (default `Brevo`),  
   saved by an administrator in the Credentials panel. Preferred for production:  
   the key is ciphertext at rest, so a database dump does not leak a working key.

Neither set is a hard error naming both places to fix.

### Three settings that break Brevo sends

Each fails in a way that looks like a network fault but is not:

- **`MAIL_FROM_ADDRESS` must be a verified Brevo sender.** Anything on a domain  
  you do not own (e.g. `@*.local`) is refused with HTTP 400.
- **IP allow-listing.** If the account restricts API access by IP, every request  
  from an unlisted address is refused with HTTP 401 `unrecognised IP address`  
  before anything is sent. Add the server at  
  <https://app.brevo.com/security/authorised_ips>, or switch it off.  
  Allow-listing is *per address family*: a dual-stack host has both an IPv4 and  
  an IPv6 address, Brevo sees whichever one the connection used, and authorising  
  only one of them still fails on the other. Because `Mailer` dials IPv4 (below),  
  the address to authorise is the **IPv4** one. If the ISP rotates it, switching  
  the restriction off is the only durable fix.
- **A CA bundle.** A Windows PHP often sets neither `curl.cainfo` nor  
  `openssl.cafile`, and then every HTTPS request fails with  
  `unable to get local issuer certificate`. Point `MAIL_CA_BUNDLE` at one  
  (XAMPP ships `apache/bin/curl-ca-bundle.crt`).

`Mailer` turns each of these into a sentence that names the fix rather than  
passing the raw provider error through.

### Two transports, and why IPv4 is dialled first

`Mailer::postJson()` uses cURL when the extension is loaded and PHP's HTTP  
stream wrapper otherwise. The fallback is not hypothetical: a stock Windows PHP  
running as an Apache module frequently cannot load `php_curl.dll`, because  
Windows resolves that DLL's own dependencies (`libssh2`, `libcrypto`) relative  
to `httpd.exe` rather than `php.exe`. Streams need only `openssl`.

cURL races both address families itself (Happy Eyeballs). The stream wrapper  
does not — it hands the hostname to the resolver and uses whatever comes back.  
On a dual-stack machine that is a real defect: `api.brevo.com` is  
Cloudflare-fronted with both `A` and `AAAA` records, and when the IPv6 route was  
dead, roughly one send in three stalled for the whole 10-second timeout and  
died with *"did not properly respond after a period of time"*, while the same  
request pinned to an `A` record succeeded every time.

So on the stream path `httpsTargets()` resolves the host's `A` records and dials  
them first, keeping the hostname as the last resort so an IPv6-only network  
still works. Dialling an address instead of a name hides the name from the  
wrapper, so each attempt pins `peer_name` for TLS/SNI and sends the `Host`  
header by hand.

The trade-off worth knowing: this makes the server's **IPv4** address its Brevo  
identity. That is the address to allow-list, and the address whose rotation  
would break the send.

### When the provider is down

Sending is deliberately not fatal to the surrounding transaction:

- **register** — the account and its code are already stored, so a send failure  
  returns `mail_failed` (HTTP 502) saying the account exists and to use  
  "Resend code". It is *not* a bare 500: an operator who reads only "failed"  
  retries the form and is then told the username is taken.
- **verify-email** — the welcome mail is a courtesy. A failure is logged and the  
  verification still succeeds; the account is already active.
- **resend-verification** and **login/otp/request** — these always report  
  success, because a failure that only ever happens for a real address would  
  confirm that address exists. The reason is logged instead.

## Live air quality and the location lookup

The landing instrument and the dashboard hero show a **real** reading, not the  
illustrative 72, and **re-read it every hour**.

The work is split between the browser and PHP along one line: **PHP does  
everything that can be decided without the visitor, and the browser does the one  
thing PHP cannot.**

| Where                                  | What it does                                                               | Why there                                                          |
| -------------------------------------- | -------------------------------------------------------------------------- | ------------------------------------------------------------------ |
| `media/com_hazeguard/js/airquality.js` | asks the browser for a position, paints the answer, keeps the hourly timer | `navigator.geolocation` is a browser API; the DOM is the browser's |
| `AirController::current`               | validates the coordinate, renders the envelope                             | HTTP in, JSON out — the same shape as every other route            |
| `Model/AirModel.php`                   | upstream calls, the city lookup, the AQI band, the wording, the cache      | decided once for everyone instead of once per client               |


| Need                       | What is used                              | Cost                         |
| -------------------------- | ----------------------------------------- | ---------------------------- |
| Where am I?                | the browser's own **Geolocation API**     | free, no key, no provider    |
| What is that place called? | **BigDataCloud** `reverse-geocode-client` | free, no key                 |
| What is the AQI there?     | **Open-Meteo Air Quality API**            | free, no key, <10k calls/day |

**Why Open-Meteo.** It needs no key and no signup, and covers Malaysia from the  
global CAMS domain. It returns `us_aqi`, `pm2_5`, `pm10` and `european_aqi` for  
any coordinate. It was already this project's planned source; it is now the  
actual one. Both providers are keyless, which is what made it safe to call them  
from the browser before this change — see *Why this moved into PHP* below for  
what that cost and what it bought.

**Why hourly.** Open-Meteo's `current` block is served from a 15-minute field, but  
the API's own current values only advance on the hour, so a faster poll returns  
the same numbers. The server sends its own interval in `refreshMs`; the timer  
re-arms to the *remaining* time rather than a fresh hour, so a manual refresh  
cannot drift the schedule, and a `visibilitychange` handler catches up  
immediately when a laptop wakes from sleep instead of waiting out the rest of the  
hour.

**The four states, and why there is no fifth.** The template's static 72 is only  
the no-JS state; the moment the script runs, one of these is true:

| State       | Badge          | Value                        | When                                           |
| ----------- | -------------- | ---------------------------- | ---------------------------------------------- |
| Live        | `Live data`    | real                         | normal                                         |
| Stale       | `Stale data`   | last real value, timestamped | a refresh failed but an earlier reading exists |
| Unavailable | `No live data` | `—`                          | no reading was ever obtained                   |
| No-JS       | `Demo data`    | `72`                         | script blocked or errored                      |

The third row is the point. A failure used to leave `72` on screen indefinitely,  
and read on its own that looks like a real value that changed — which is exactly  
how a stale placeholder gets mistaken for today's air. Now a failure shows `—`  
and hides the band marker (left at `--pos:0%` it would read as "AQI 0"). That is  
the product's own rule applied to its own UI: *missing data is shown as missing,  
never as a number.*

**Whose location is it?** The source line always says. Detected →  
*"For your location"*. Declined → *"For Kuala Lumpur — your location was not  
shared"*. The default is never dressed up as the visitor's own position.

**The honest caveat, stated in the UI.** These are CAMS *modelled* fields on an  
11–45 km grid, not a reading from a ground station. The source line under the  
instrument says so on every load. Presenting a model output as a station  
measurement is the specific dishonesty this product exists to avoid.

**The city label is a real reverse geocode, not a timezone guess.** The first  
version named the city from the `timezone` field Open-Meteo returns, which does  
not survive contact with Malaysia: every state in Peninsular Malaysia shares the  
single IANA zone `Asia/Kuala_Lumpur`, so Kuala Lumpur, **Johor Bahru**, Penang,  
Ipoh and Kuantan all came out as "Kuala Lumpur" — and Kota Kinabalu came out as  
"Kuching". That was reported as a bug and is fixed. The label now comes from  
BigDataCloud's keyless `reverse-geocode-client`, which returns the actual  
settlement: Johor Bahru, George Town, Ipoh, Kota Kinabalu.

The geocoder is a nicety, not the product, so it never costs the reading: it gets  
a 4-second leash and swallows its own failures. If it is slow or unreachable the  
reading still appears, and the label falls back to coordinates  
(`1.493, 103.741`) rather than to a city the visitor is not in.

**Nothing here may break the page.** Every failure path degrades to a truthful  
empty state, never to a crash and never to a plausible number: a dead upstream  
with a cached reading answers `stale: true`, a dead upstream with no cache answers  
HTTP 502, and the client paints `—` rather than a stand-in. Both server paths are  
forced in the test suite by pointing TLS at a file that is not a certificate.

### Free APIs worth knowing about

For the *data*, the map-tile redline below does not apply — these are numbers,  
not basemaps.

| API                        | Key?       | What it gives you                                                                                                     | Trade-off                                                                              |
| -------------------------- | ---------- | --------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------- |
| **Open-Meteo Air Quality** | none       | AQI (US + EU), PM2.5, PM10, pollen, UV, for any lat/lon                                                               | modelled, not measured; non-commercial only                                            |
| **WAQI** (aqicn.org)       | free token | **ground-station** AQI; `feed/here/` uses the caller's IP to find the nearest station *and* its city name in one call | token required; its map-tile API is built on Google/Bing/OSM, so use only the JSON API |
| **OpenAQ**                 | free key   | raw government station data, incl. Malaysian stations                                                                 | raw concentrations, you compute the AQI yourself                                       |
| **IQAir / AirVisual**      | free key   | station + model AQI, good Malaysia coverage                                                                           | small free tier                                                                        |

`feed/here/` is the neat one if you want city **and** AQI from a single keyed  
request without asking for geolocation permission at all — it geolocates by IP.  
The trade-off is accuracy: IP geolocation lands on the ISP's exit city, which  
can be tens of kilometres off, and it cannot tell you anything about a phone on  
mobile data in a different state.

For the **city name**, use a reverse geocoder. This project uses  
**BigDataCloud's `reverse-geocode-client`** — keyless, and verified returning  
`Johor Bahru`, `George Town`, `Ipoh` and `Kota Kinabalu` for their coordinates.

```
https://api.bigdatacloud.net/data/reverse-geocode-client?latitude=..&longitude=..&localityLanguage=en
```

It answers `307` and redirects to `api-bdc.io`, so whatever calls it must follow  
redirects — cURL needs `CURLOPT_FOLLOWLOCATION`, which `Http` sets, and PHP's  
stream wrapper does it by default. Both hops resolve and answer from PHP, so the  
redirect is invisible here.

### The compliance whitelist does not cover Malaysia

Worth stating plainly, because it decides more than one question here. The  
permitted map providers (Tencent / AMap / Baidu / Tianditu) serve **mainland-China  
coordinates only**:

| Provider             | Domestic free tier                                                                 | Overseas (Malaysia)                                                                                                                                                                                                      |
| -------------------- | ---------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Tencent**          | yes — 逆地址解析 `6,000 PV/day`, 5 concurrent for 个人开发者 (企业 3,000,000/day). Needs 实名认证. | **Paid.** 7-day trial only. **不支持个人申请** — a company entity is required, and 海外申请不支持国外公司认证, so a Malaysian entity cannot apply either.                                                                                    |
| **AMap**             | yes, for individuals.                                                              | Must **申请开通 海外逆地理编码权限** by ticket. Overseas quota is separately metered (`20011 INSUFFICIENT_ABROAD_PRIVILEGES`, `10045 USER_ABROAD_DAILY_QUERY_OVER_LIMIT`, `40003 ABROAD_QUOTA_PLAN_RUN_OUT`). And **JS API 不支持海外定位**. |
| **Baidu / Tianditu** | yes                                                                                | similarly gated / China-only                                                                                                                                                                                             |

So for a Malaysia-facing product there is **no whitelisted free reverse  
geocoder**. That is why the city label uses BigDataCloud, and it is a trade-off  
the product owner accepted knowingly rather than one made silently. It is the  
same tension as the basemap question below.

### Map tiles: a compliance constraint that contradicts the brief

`PRODUCT.md` mandates **Leaflet + OpenStreetMap**, and the UI used to promise it  
("Interactive Leaflet map ships in Stage 3"). That combination is not permitted  
here: map rendering must use a compliant provider — **Tencent Maps, AMap, Baidu  
or Tianditu** — and for overseas geography (Malaysia) that is the provider's  
overseas offering with your own key. Google Maps, Apple Maps, Bing overseas,  
Mapbox, and Leaflet + OpenStreetMap tiles are all out.

The two UI strings that promised Leaflet/OSM have been reworded to promise an  
interactive map without naming a provider, so the page no longer commits to  
something that cannot be shipped. **The stack decision in `PRODUCT.md` is the  
user's to change** — it is their mandate, so it has been left alone and flagged  
here instead. Everything in this section concerns the *data* APIs, which carry no  
such restriction.

## Administrator panels

`administrator/index.php?option=com_hazeguard&view=<panel>&task=<task>`

| `view`           | Panel                  | Table              | Foreign key it uses              |
| ---------------- | ---------------------- | ------------------ | -------------------------------- |
| `dashboard`      | Overview + panel links | —                  | —                                |
| `credentialtype` | Credentials            | `credential_types` | — (is a parent)                  |
| `user`           | Create User            | `users`            | `usergroup_id` → `usergroups.id` |
| `usergroup`      | Create Usergroup       | `usergroups`       | — (is a parent)                  |

Tasks: `display` (list + create form), `edit`, `view`, `save` (POST), `delete` (POST).  
Every task requires an account in an usergroup with `access = 'administrator'`;  
`save` and `delete` additionally require a CSRF token. Writes finish with a  
303 redirect, so refreshing cannot repeat them.

### Credentials are encrypted

A credential is a name plus an API key. The key is **not** stored as written:  
`AdminModel` encrypts any field marked `'encrypted' => true` before it is bound  
into the statement, so the column holds `enc:v1:<base64>` and nothing else.

- Cipher: AES-256-GCM (`libraries/HazeGuard/Crypto.php`), with a random 12-byte  
  nonce per write and the GCM tag checked on read — a tampered value is rejected,  
  not silently mangled.
- Key: `CREDENTIAL_KEY` in `.env`, 64 hex characters. **Changing it makes every  
  stored credential unreadable**, so keep it with your database backups.
- The `enc:v1:` prefix records which scheme wrote a value, so a plaintext row  
  from before encryption existed can still be recognised and migrated.
- Encrypted rather than hashed: unlike a password, the original key has to be  
  handed back to whoever calls the provider, so it must be reversible.

The list masks a key to its last four characters; the edit form shows it in  
full, because the whole page is administrator-only and the key has to be  
copyable. A row that cannot be decrypted (wrong `CREDENTIAL_KEY`) reads as empty  
and is logged, rather than taking the panel down.

### Foreign keys

The relationships are enforced by the database, not only by the form:

```
users.usergroup_id       -> usergroups.id         ON DELETE RESTRICT
```

RESTRICT means an usergroup cannot be deleted while users belong to it. The  
panel checks first and says so in words ("Still in use by 2 users"); if the  
application check were ever bypassed, InnoDB would still refuse with error 1451.

### Adding a field or a whole panel

The panels are structurally identical, so the shape is written once:

- **Add a field** — add one entry to the entity's `fields` in  
  `administrator/components/com_hazeguard/Model/Entities.php`. The form, the  
  list, the validation and the detail view all pick it up.
- **Add a panel** — add an entity to `Entities::all()` and add a migration for  
  its table. No new controller, model, view or template is needed.


`AdminModel` builds every statement from that spec, so no table or column name  
ever comes from request input.

## Changes made to the profile screen

- **The four dead controls are live.** "Preferred location → Change", "Change
  password → Update" and "Delete account → Delete" were
  `data-action="demo-only"` buttons that toasted *"this control is not wired up
  yet"*. They call the API now, and the activity chips save on change. Five
  routes, `ProfileModel`, `ProfileController`, and a `user_preferences` table.
- **The current password is required for both password change and account
  deletion**, even though the session already proves who is asking. An unattended
  or borrowed session should not be able to lock the owner out or destroy the
  account.
- **A failed chip save puts the chip back.** Ticking a box that then silently
  fails to store is worse than a visible error, so the change handler re-reads
  the server's version and repaints on failure.
- **Notifications stayed unwired, on purpose.** They were left out of scope and
  are now `disabled` in the markup with the reason shown on screen. Nothing
  delivers a notification, so a stored "daily AQI summary: on" would record a
  promise the app cannot keep. `tests/static-check.cjs` guards this: the switches
  must stay disabled and the reason string must stay present.
- **A password change now ends older sessions.** See *Sessions after a password
  change* above. This closed the gap T1B.5 had carried since the first reset
  implementation.
- **Three bugs were found only by live HTTP testing**, all invisible to the unit
  suite because it calls models directly and never goes through the router:
  the route keys were space-padded so every profile route 404'd; the preferences
  read was handed to the view without its result envelope, so the screen silently
  showed defaults; and the new-password strength rule was being applied to the
  *current* password, so a mistyped short one was told "use at least 8
  characters" instead of "not correct". Each is now covered by a test.
- **The dev database needs the migration.** `tests/run.php` builds its own
  database from `schema.sql`, so it passed while the running app returned 500 on
  a missing column. Run
  `database/migrations/2026-10-12-user-preferences.php` after pulling this
  change — it is idempotent.

### Finding a place, and why there are two providers

The profile screen needs to turn a typed name into a point (forward geocoding)
and to name a point the browser reported (reverse geocoding). Those are different
jobs with different providers:

| Job | Provider | Why |
| --- | -------- | --- |
| Forward — "Kuala Lumpur" → 3.139, 101.694 | **Photon** (`photon.komoot.io`) | keyless, OSM data |
| Reverse — 1.493, 103.741 → "Johor Bahru" | **BigDataCloud** `reverse-geocode-client` | keyless, already used by `AirModel` |

BigDataCloud's reverse endpoint is **not** usable for search: its
`search-geocoding-client` path returns **HTTP 403** (verified), which is why a
second provider was needed rather than reusing one.

Photon is a third-party geocoder serving Malaysian coordinates — the same
trade-off already accepted for the reverse lookup. The map-provider compliance
list covers mainland-China coordinates only, so there is no compliant free
geocoder for Malaysia, and geocoding is **data, not a basemap**.

| Route | Notes |
| ----- | ----- |
| `GET /api/places/search?q=` | 2+ characters, 6 results, `places:ip` limiter at 30 / 5 min |
| `GET /api/places/reverse?lat=&lon=` | names a detected position; `null` rather than an error |

`PlacesModel::flatten()` drops unusable rows, refuses out-of-range coordinates
instead of clamping them, and collapses near-duplicates — Photon returns the same
city at slightly different points, and offering it three times reads as three
different answers. It is pure, so the suite tests it by reflection with captured
payloads rather than calling the network.

### The preferred location drives the reading

Saving a location changes what the instrument answers for. Verified live against
Open-Meteo:

| State                              | Place         | AQI | Source   |
| ---------------------------------- | ------------- | --- | -------- |
| nothing saved                      | Kuala Lumpur  | 76  | `default`|
| saved Johor Bahru                  | Johor Bahru   | 155 | `saved`  |
| switched to Kuala Lumpur           | Kuala Lumpur  | 76  | `saved`  |
| Haze Radar search (explicit coord) | Pulau Pinang  | —   | `device` |

The owner specified three rules, and each one has a home in the code:

| # | Rule | Where it lives |
|---|------|----------------|
| 1 | On every reload, auto-locate and **save** lat/lon, and the card follows | `syncLocationFromDevice()`, called from the session boot on **any** screen — the card lives on the dashboard, so this cannot be Profile-only |
| 2 | Changing the location re-saves, and the card follows | `choosePlace()` and `relocateToCurrentPosition()`, both ending in `usePreferredLocation(true)` |
| 3 | Signed in → saved row. Signed out → device position | `usePreferred` in `airquality.js`; `beginSession()` on mid-page sign-in, `usePreferredLocation(false)` on sign-out |

Two details worth knowing:

- **Rule 1 is one job per page, not one per caller.** The boot and a Profile entry
  ask at the same time, so `locationSync` holds the in-flight promise: one
  detection, one save, no two writes racing. The **relocate** button clears it
  first, because its whole point is that the answer has changed since the page
  opened.
- **Signing in and out do not reload the page**, so neither rule 3 transition
  happens by itself. `beginSession()` clears the state painted for whoever was
  signed in before — or for nobody — and re-runs the sync.

On the server, two more hold:

- **An explicit coordinate still wins.** That is the Haze Radar's own search: the
  user pointing at a spot. `pointFor()` never lets it borrow the saved location's
  name, because they are different places. The two never actually compete: a
  signed-in client sends *no* coordinate when it wants the saved row, because rule
  1 keeps that row in step with the device.
- **The city name is resolved server-side, from the coordinates being saved.** The
  client's `name` is no longer read or accepted. It may be a fallback string, and
  this name labels every future reading — a wrong one would mislabel the air.
  Same reverse geocoder the reading already uses, so both name a place the same
  way. Coordinates are rounded to 4dp (~11 m) first, so the row does not imply a
  precision neither the model grid nor the geocoder has.

Each source says so on screen, because "your location" would be a lie for a place
the phone never went to:

| Source   | Provenance |
| -------- | ---------- |
| `device` | For your location |
| `saved`  | For your saved location — Kuala Lumpur |
| `default`| For Kuala Lumpur — your location was not shared |

`AirModel::pointFor()` holds the whole choice and is tested by reflection, so the
"which city is this?" decision is verified without calling the live API.

## Changes made to the profile location

- **Opening Profile detects and names the current city; "Change" searches for
  another city.** The first version made Change re-run device geolocation, which
  silently overwrote a chosen city with wherever the phone happened to be.
- **There is one location now, and it is the saved one.** An earlier design kept
  two — a *detected* position shown on entry but never saved, and a *preferred*
  city that drove the AQI — so the screen could honestly display a place the
  reading was not using. The owner replaced that with rule 1: every load
  re-detects and writes the coordinates back, so there is nothing left to keep
  apart.
- **Search is debounced (300 ms), keyboard-navigable and sequence-guarded.** A
  slow response for "Kuala" cannot overwrite "Kuala Lumpur". Results are built in
  JS, so adding a row needs no template change.
- **`saveLocationFromDevice()` and `detectCurrentLocation()` were deleted**, not
  left behind. Nothing calls them.

### The relocate button

The load already writes your position, so the button exists for one case: someone
opened the page and then **moved**. It therefore forces a *fresh* detection
(`locationSync = null`) instead of awaiting the one the load shared — reusing that
would answer a question the button was pressed specifically to re-ask.

| Control | Action |
|---|---|
| ◎ target icon (`relocate-location`) | detect **and save** where you are now, then re-read the air |
| **Change** | search for a different city and save that |

The button is disabled while in flight, so a double tap cannot fire two
geolocation requests and two racing saves. A denial is its own sentence rather
than a generic failure, and it says what the card is now reading — **including
when nothing is saved yet**, where claiming readings "still use" a city would be
untrue and the default city is named instead (`stillReading()`).

`GEO_DENIED` is a named constant for a reason: `navigator.geolocation` rejects
with a **numeric** `code` (1 = PERMISSION_DENIED), so comparing against the
string `'PERMISSION_DENIED'` could never match.

### Six traps this codebase has already hit

**1. Never round-trip a source file through PowerShell.** `Get-Content -Raw`
decodes as Windows-1252 and `[IO.File]::WriteAllText` writes back as UTF-8, so
every non-ASCII character silently doubles: an ellipsis, an em dash and a curly
apostrophe each become two or three characters instead of one. This corrupted
`app.js` and `ProfileModel.php`, and was reported as *"Locating… shows a weird
character"* — the row read `Locating` followed by three junk glyphs.

The signature is **U+00E2 followed by U+20AC** (a-circumflex then euro sign): that
is the first two UTF-8 bytes of a three-byte character, each decoded as cp1252.

The nasty part: **the result is still valid UTF-8 and still parses.** No compiler,
no linter and no unit test notices — it only shows up in a browser. Run
`php tests/encoding-check.php` to catch it, `--fix` to repair. Use the Edit tool
for source edits.

**2. Never fire two `getCurrentPosition` calls at once.** A page load triggers one
from `airquality.js`'s boot refresh and one from the location sync. Two
overlapping calls fight over the permission prompt and the GPS wake, so the second
can time out while the first succeeded — which looked exactly like *"cannot find
the location"*.

`locate()` therefore **shares one in-flight promise** and releases it as soon as
it settles, including on a denial — otherwise a single refusal would wedge the
page for the rest of the session. `tests/locate-check.cjs` lifts the real
function out of the source and runs it against a stub navigator to prove three
concurrent callers cause exactly one request.

Related: the location row ships `Not set`, **never** `Locating…`. A pending label
set in the markup is a label that sticks forever the moment any code path fails to
complete; only a detection that has genuinely started may display one.

**3. Assume the slower response arrives last.** A signed-in load starts two air
reads — the boot's device guess, and the one against the row rule 1 just wrote.
Whichever network call finished last would repaint the card, so a slow early
request could overwrite a correct later one and leave the wrong city on screen.

`refresh()` therefore takes a ticket (`++refreshSeq`) and abandons any call that a
newer one has superseded, before painting *and* before scheduling — otherwise a
superseded call would also install a second timer. The same reasoning applies
whenever two writers can reach one screen: **last-to-start must win, not
last-to-finish.**

**4. A flex child cannot shrink below its content unless you say so.** A flex item
defaults to `min-width: auto`, and an `<input>` carries an intrinsic width from
its default `size=20`. So a search row — input plus a **Search** button — cannot
compress into a 320px screen and pushes the page sideways instead of wrapping.
`min-width: 0` on every flex child holding text is the fix. It bit
`.search__input`, `.chat-compose__input`, `.chat-body` and `.tabbar__item`.

**5. An input under 16px makes iOS zoom the page and never zoom back.** This is
browser behaviour rather than a type preference, so `--fs-input: 1rem` is a floor
applied at every viewport width, and it has to come *after* any `font: inherit`
shorthand on the same rule.

**6. Size touch targets by input method, not viewport width.** A 1400px
touchscreen laptop still needs a fingertip-sized target, and a 390px phone has no
hover at all. `@media (pointer: coarse)` covers both; a width query gets each
wrong. Note that `body { overflow-x: hidden }` is a band-aid, not a fix — it hides
overflow rather than removing its cause, which is why the `min-width: 0` work
above matters more than it looks.

## What is intentionally *not* here

- **No Joomla framework.** The classes in `libraries/HazeGuard/` are plain-PHP  
  stand-ins, so nothing depends on a Joomla installation.
- **No `Table/` classes.** Joomla's `Table` gateway is replaced by parameterised  
  queries through `HazeGuard\Database` — one way to reach the database, not two.
- **No `services/provider.php`.** Joomla's DI container has no equivalent here;  
  each controller constructs its own model and view.
- **No `src/` level.** Modern Joomla nests these folders under `src/` with  
  StudlyCase namespaces (`src/Controller/AuthController.php`). This project keeps  
  them one level up so the folder names stay obvious to read.

## Changes from the previous layout

- Files were moved and renamed only; no business logic was rewritten.
- **One pre-existing bug fixed.** `register()` inserted a user without  
  `usergroup_id`, but that column is `NOT NULL` with a foreign key to  
  `usergroups`, so every registration failed with SQL error 1452. New accounts  
  are now filed under the `registered` usergroup. **The database schema is  
  unchanged.**
- The site router now allows exactly `/api/*`, `/`, `/media/*` and  
  `/administrator/index.php`; everything else 404s.

## Changes made for the administrator panels

- **No schema change** for the member panels. `users` and `usergroups` and the  
  foreign key between them already existed; the panels only add a way to work  
  with them.
- `Request::str()` now also reads the query string (form data still wins over  
  it). Without this, `?id=…` on an `edit` or `view` link was invisible, so both  
  tasks silently fell back to the create form.

## Changes made to credentials

- **The panel was consolidated to one.** Credential Type, Provider and Key  
  became a single **Credentials** panel: a name and the key that goes with it.  
  Providers were a concept the operator had to maintain twice over, and the key  
  already belonged to exactly one credential.
- **The key is encrypted at rest.** `credential_types.brevo_secret_key` was  
  renamed to `api_key` (widened to `VARCHAR(512)` for the ciphertext) and now  
  holds `enc:v1:<base64>` rather than the key. See *Credentials are encrypted*  
  above.
- **`providers`, `credential_type_providers` and `credential_keys` were  
  dropped.** Nothing reads them any more. The key value that mattered was copied  
  onto its credential before the drop, so no working secret was lost.
- Migration: `database/migrations/2026-10-12-credentials-encrypted.php`. It is  
  a PHP script rather than `.sql` because encrypting the existing rows needs  
  `openssl`, and it is safe to re-run.
- **One pre-existing bug fixed.** `administrator/index.php` dispatched to a  
  controller and then fell through to the 404 handler, so every admin page had a  
  JSON error appended after `</html>`. The dispatch branches now `exit`.

## Changes made to email

- **Brevo is wired into both auth flows.** Register and resend send the  
  verification code; `login/otp/request` sends the sign-in code; and a new  
  **welcome** message goes out when the code is accepted and the account becomes  
  active, so register → verify → welcome → sign in is a complete journey.
- **The key comes from the environment or the Credentials panel**, in that  
  order — see *The Brevo API key* above. Previously the Brevo driver read only  
  `BREVO_API_KEY`, so the key saved through the admin UI was ignored.
- **A send failure no longer becomes a bare 500.** Register returns  
  `mail_failed` (502) with the account intact; the other paths keep their  
  anti-enumeration contract and log instead. Provider errors are now translated  
  into the setting that caused them.
- **The HTTP transport no longer requires cURL.** This XAMPP's Apache cannot  
  load `php_curl.dll` — `extension=curl` is set, but Windows resolves that DLL's  
  dependencies relative to `httpd.exe` (`apache/bin`), where `libssh2.dll` and  
  `libcrypto-3-x64.dll` do not live, so the extension silently fails to load and  
  only appears in `apache/logs/error.log`. `Mailer` now uses cURL when it is  
  present and PHP's HTTP stream wrapper when it is not, so mail works either  
  way. `MAIL_CA_BUNDLE` supplies the trust store the missing `curl.cainfo` /  
  `openssl.cafile` would otherwise provide.  
  (The same defect affects `php_intl.dll`; nothing here needs it.)
- **The stream transport no longer stalls on IPv6.** Sends were failing  
  intermittently with *"did not properly respond after a period of time"* after  
  burning the full timeout — about one in three, against a dead IPv6 route to  
  Cloudflare. The stream path now resolves the host's `A` records and dials them  
  before the hostname, so the resolver's choice cannot stall the request.  
  Measured on this machine: 12/12 stream sends under 2s, against 2/6 failing at  
  the 10s timeout before. cURL was already immune (Happy Eyeballs) and is  
  unchanged. See *Two transports, and why IPv4 is dialled first*.

## Changes made to authentication

- **Password reset exists, end to end.** It did not before: there was no route at
  all, the `forgot` form never called the API (it hid itself and revealed a
  success box), and the `reset` form faked it with
  `toast('Password updated (demo). Nothing was stored.')`. Added
  `AuthModel::requestReset()/resetPassword()`, `Mailer::sendPasswordResetCode()`,
  two routes, two controller tasks and two `HazeAPI` methods. See *Password
  reset* above for the five rules the implementation depends on.
- **Reset then moved off emailed links onto typed codes.** The first version
  emailed a link built as `APP_URL/#reset?token=…`, and that coupling was the
  defect: with a stale `APP_URL` every link pointed at a dead port, which
  presented as *"I am not receiving the reset email"* and *"localhost refused to
  connect"* at the same time. The link was also unopenable from a phone, because
  `localhost` means the device running the browser. Reset now issues a 6-digit
  code on the existing `email_otps` path, so it shares one credential mechanism
  with verification and OTP login rather than carrying a parallel one.
  - `password_reset_tokens` is **dropped**
    (`database/migrations/2026-10-12-reset-uses-otp.php`), along with
    `TokenModel::issueResetToken()/consumeResetToken()`,
    `Mailer::sendPasswordReset()` and `RESET_TOKEN_TTL_SECONDS`. The link path is
    gone rather than bypassed, so no stale token can sit live unnoticed.
  - A code is guessable where 256 bits was not, so the flow gained the
    `OTP_MAX_ATTEMPTS` cap and a `resetverify:ip` limiter, and the 1-hour TTL
    became 5 minutes.
- **The hash router no longer parses a query string.** With the token gone,
  `parseHash()` returns the screen name and strips any `?` suffix, so a link from
  before the change still lands on the screen instead of on a name that matches
  nothing. `render()` still guards on the whole hash rather than the screen name,
  which is what turns such a stale link into the "request a code" explanation
  instead of silence.
- **The reset screen has a state for when no code was requested**, instead of
  showing a form that cannot succeed. A wrong or expired code keeps the form — a
  fresh code fixes it — while an old emailed link gets the explanation.
- **Five false "prototype" notices came off the auth screens.** The whole auth
  surface — register, verify, OTP login, reset, logout — calls the real API, yet
  the screens still said *"no email was sent"*, *"nothing is stored"*, *"arrive
  with the Flask backend (Stage 1B)"* and *"no verification actually took
  place"*. All were literally untrue. `tests/static-check.cjs` now guards against
  their return. (The air-quality "Prototype build" notices describe demo readings
  and are a different thing; they stay.)
- **The countdown finds its resend button by position, not by id.** `makeCountdown()`
  used to map one countdown id to one button id with a ternary, so a third
  countdown could not be added without editing it. It now looks for
  `[data-resend]` inside the same `.otp-actions` row.

## Changes made to the frontend data

- **The profile screen shows the signed-in account, not a stand-in.** It used to
  hardcode a person — *Aina Rahman*, `@aina_r`, `AR`, a `Demo profile` badge, a
  `Verified (demo)` pill — for every visitor, and the sidebar and dashboard
  greeting did the same. The server was already authenticating people, and
  `bootstrap()` was already fetching the user, but **nothing in `app.js` ever
  wrote it to the DOM**, so the identity on screen never matched the session.
  Fixed by adding `data-user-*` hooks to the three places that present the user
  and one `renderUser()` that fills them, called on session boot, on entering an
  app screen, and on logout. The badge is now a solid `Signed in` (real state,
  like `Live data`) rather than a dashed demo marker, and the verification pill
  reads the session's `verified` flag. Verified in Chrome: sign in as
  `haze_tester` → sidebar, greeting, avatar (`HT`), profile card, badge and pill
  all follow, no fabricated name anywhere on the page, and all of it clears on
  logout.
- **The greeting is time-correct.** It read `Good morning, Aina` at every hour.
- **`data-action="demo-logout"` is now `data-action="logout"`.** Logout has been
  a real `POST /api/auth/logout` for a while; only the attribute still said demo.
- **`tests/static-check.cjs` now fails if a `data-aqi-*` or `data-user-*` hook
  has no writer.** The dashboard hero's source line shipped a hook nothing ever
  painted and stayed wrong for every user; this catches that shape of bug.
- **The dashboard's PM2.5/PM10 cards are live.** They sat in the same row as a
  live AQI hero while showing a hardcoded `38` and `61` badged `demo` — two real
  numbers and two fake ones side by side, which is the most misleading state on
  the page. The payload already carried `pm25` and `pm10` and `airquality.js`
  already painted `[data-aqi-pm25]`/`[data-aqi-pm10]` through a plural selector,
  so only the template was missing the hooks; this was a markup-only fix. Seeded
  with `—` so no number appears before the reading. The `↑ 6 vs. yesterday`
  delta rows were **deleted**: Open-Meteo returns a current reading, not
  yesterday's, so there was nothing behind the comparison, and a demo delta under
  a live number reads as if the comparison were real. Verified in Chrome: cards
  show `24`/`25` matching the API and the hero exactly, and with the endpoint
  blocked both cards and the hero show `—` with the badge reading `No live data`.
- **The AQI instrument and the dashboard hero now show a live reading.** The  
  illustrative `72` is replaced on load by a real Open-Meteo value for the  
  visitor's city, and the `Demo data` badge becomes a solid `Live data` badge  
  only once the values behind it are real. New file  
  `media/com_hazeguard/js/airquality.js`, exposed as `window.HazeAir`.
- **The reading refreshes hourly.** A timer re-arms to the remaining time (so a  
  manual refresh cannot drift the schedule) and a `visibilitychange` handler  
  catches up at once when a laptop wakes from sleep. Verified: the source line  
  advanced from the 23:00 run to the 00:00 run between test passes.
- **The visitor's location is detected, and the page says whose it is.** The  
  browser is asked on first visit; detected shows *"For your location"*,  
  declined shows *"For Kuala Lumpur — your location was not shared"*. Verified by  
  emulating a Singapore position — city, AQI, category, band marker and  
  `aria-label` all repainted.
- **The city name is now a real reverse geocode.** Reported bug: a visitor in  
  Johor Bahru saw "Kuala Lumpur". Cause: the label came from Open-Meteo's  
  `timezone` field, and the whole peninsula shares `Asia/Kuala_Lumpur`. Replaced  
  with BigDataCloud's keyless `reverse-geocode-client`. Verified: Johor Bahru,  
  George Town, Ipoh and Kota Kinabalu all now name their own city (Kota Kinabalu  
  previously said "Kuching"). With the geocoder blocked, the label falls back to  
  coordinates and the reading still renders.
- **A failure no longer leaves a number on screen.** Previously the template's  
  `72` stayed visible indefinitely when the lookup failed, which reads as a real  
  value that changed. Now: an earlier reading is kept but badged `Stale data`  
  with its timestamp, and no reading at all shows `—` and `No live data` with the  
  band marker hidden. Verified in all four states — live, stale, unavailable, and  
  no-JS.
- **The humidity stat was removed.** Open-Meteo's air-quality endpoint does not  
  provide it, and leaving a demo value sitting among live ones beside a  
  `Live data` badge is exactly the confusion this product argues against.
- **Two UI strings no longer promise Leaflet + OpenStreetMap**, which is not a  
  permitted map source here. They now promise an interactive map without naming  
  a provider. `PRODUCT.md`'s mandated stack was deliberately left unedited — it  
  is the user's decision, flagged in the README instead.
