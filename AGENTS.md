# AGENTS.md — HazeGuard AI

Project instruction file for all OpenCode sessions. Read this and `progress.md` before any work.

## Purpose

HazeGuard AI is a mobile-first web app that helps people understand local haze/air quality, explore it on a map, and plan outdoor activities with AI help. Tagline: *Understand the air. Plan smarter. Breathe better.*

## Technology stack (mandatory)

- **Frontend:** HTML5, CSS3, vanilla JavaScript, Fetch API. No React, Vite, Tailwind, or frontend frameworks.
- **Backend:** Plain PHP 8 (no framework), JSON APIs for interactive features. PDO with parameterised queries, PHP sessions, native `password_hash`. No Composer dependency required.
- **AI integration:** Small Python Flask microservice, called by PHP over HTTP (localhost). The LLM key lives only in the Python env; PHP never reads it. AI is the only part of the stack in Python.
- **Database:** MySQL, phpMyAdmin for admin, parameterised queries only, SQL schema files in `database/`.
- **Maps:** Leaflet.js + OpenStreetMap tiles (respect tile provider usage policy), browser Geolocation API on explicit user action.
- **Air quality:** Open-Meteo Air Quality API first; verify APIMS programmatic access before claiming official station data. Always label model estimates as estimates.
- **Charts:** Chart.js (Stage 6+; Stage 1A uses small hand-built SVG charts labelled demo).
- **Deploy:** Linux VPS, Nginx + PHP-FPM for the backend, a process supervisor (systemd) for the AI service, HTTPS, env vars for secrets.
- Technology changes require a reason recorded in `progress.md`.

## Directory structure

The project mirrors Joomla's component MVC layout in plain PHP — no Joomla
framework and no Joomla template code. `README.md` holds the full folder map.

```
haze2/
├── index.php                   # site front controller + route table (/api/*, /, /media/*)
├── bootstrap.php               # autoloader, config, error handling
├── administrator/
│   ├── index.php               # administrator front controller (?option=&view=&task=)
│   └── components/com_hazeguard/{Controller,Model,View,tmpl}/   # dashboard + 4 CRUD panels
├── components/com_hazeguard/   # site component: Controller/, Model/, View/, hazeguard.xml
├── libraries/HazeGuard/        # shared plain-PHP classes + Mvc/Controller, Mvc/View
├── media/com_hazeguard/        # site css/, js/, assets/fonts/
├── templates/hazeguard/        # single-page app shell (index.php)
├── database/                   # schema.sql, bootstrap.sql, migrations/
├── tests/                      # backend acceptance suite + frontend static checks
├── storage/                    # dev mail log (gitignored)
├── ai-service/                 # Python Flask microservice (Stage 4+, not built yet)
└── .env.example
```

Do not create empty backend directories before their stage. Do not duplicate files or restructure without reason.

## Coding conventions

- Frontend: one `style.css` with CSS custom properties (design tokens), one `app.js` with small named functions, no build step. Inline SVG icons (stroke-consistent), never emoji as icons.
- Backend: small PHP endpoint files, PDO parameterised SQL, validation at input boundaries, JSON responses follow the documented API convention in `progress.md`. `strict_types=1`, no framework conventions.
- Comments only for non-obvious "why". No stray `console.log` in shipped frontend code.
- All simulated data carries a visible `Demo` label in the UI.

## Security requirements

- Never commit `.env`; provide `.env.example`. No hardcoded secrets.
- Passwords hashed via PHP `password_hash` (bcrypt/argon2), sessions server-side + HTTP-only cookies, CSRF on state-changing requests, PDO parameterised SQL, output escaping, ownership checks on all user data. The AI service binds to localhost and validates requests from PHP only.
- OTP/reset: hashed token storage, expiry, single use, rate limits, no logging of codes in production.
- Stage 7 runs the full security checklist before release.

## Testing requirements

- Never mark a task `COMPLETED` without running its acceptance checks and recording actual results in `progress.md`.
- Frontend: browser verification (screenshot + console + interaction checks via OpenCode browser tools) at 360/390/768/1024/1440 widths using `tests/responsive.html`; run the Impeccable detector on changed UI files.
- Backend (Stage 1B+): PHP test scripts covering the acceptance tests of each stage (run via `php tests/run.php`); AI service tested with pytest.
- Never hide test failures or invent results.

## Git safety

Git is not installed in the current environment (Session 1). When available: no destructive commands (`reset --hard`, `force push`), never commit `.env`, never overwrite unrelated user changes, inspect before editing.

## Required skills

Use only if installed: `@impeccable` and `@apple-design` for design work (frontend stages), `@ponytail` for concise code, `@ponytail-review` after implementation milestones. `@php-domain-best-practices` (global conventions) and `@application-layer` (service layer) apply to the PHP backend; framework-integration skills do not (no PHP framework). Check availability with the skill tool before invoking; never invent skill capabilities.

## Maintaining progress.md

- `progress.md` is the single source of truth: roadmap, task IDs, acceptance criteria, API/DB design, test log, blockers, next task.
- Update before starting work, after every milestone, after testing, on blockers, and at session end.
- Statuses: `NOT STARTED`, `IN PROGRESS`, `BLOCKED`, `COMPLETED`, `DESCOPED`.
- Progress % = completed applicable tasks / total applicable tasks × 100 (descoped excluded). Never inflate.

## Resuming work in a later session

1. Read `AGENTS.md`, then `progress.md` (especially "Current stage" and "Exact next task").
2. Inspect the repository; check Git if installed.
3. Verify the last reported work still holds, then continue the highest-priority unfinished task.
4. End the session by updating `progress.md` (status, files, tests, results, next task).

## Avoiding duplicate work

- Inspect existing files before writing; extend the current prototype instead of rebuilding it (Stage 1B+ must preserve the Stage 1A UI).
- One screen per feature area; no parallel "v2" files or leftover experiments.
- If a feature already exists in the codebase, reuse its pattern rather than adding a second implementation.
