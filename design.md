# HazeGuard AI — Liquid Glass Redesign (`design.md`)

Phase 1 deliverable, **revision 2**. Proposal only — no source file has been changed.
Visual reference: the Bloom landing page spec (aesthetic, glass system, strictly grayscale, Poppins / Source Serif 4).
Bloom's branding, logo, copy, floral assets and content are **not** used.

Revision 2 resolves: strict monochrome (including AQI scale and brand mark), safe token merge,
valid HSL syntax, defined-variable contracts, verified stacking order, verified video toggle against
the real router, and a call-site audit of every colour literal the restyle must reach.

---

## 1. Project Analysis

### What the site is
HazeGuard AI is a mobile-first single-page app: one PHP-rendered shell (`templates/hazeguard/index.php`)
with 13 screens toggled by a vanilla-JS router (`SCREENS` in `media/com_hazeguard/js/app.js`), talking to a
plain-PHP JSON API at `/api/*`.

| Group | `data-kind` (set on `<body>` by the router) | Screens |
|---|---|---|
| Marketing | `page` | `landing` |
| Auth | `auth` | `login`, `register`, `otp`, `verify-result`, `forgot`, `reset` |
| App | `app` | `dashboard`, `radar`, `planner`, `chat`, `history`, `profile` |

Navigation: `.topbar` on landing → `.sidenav` (≥1024px) / `.tabbar` (mobile) inside `#shell`,
both driven by `data-nav` attributes.

### Current visual system (`media/com_hazeguard/css/style.css`, ~1730 lines)
- **Light theme**, green-tinted: `--bg #f4f7f5`, `--surface #ffffff`, text `#0b1f19`.
- **Accents:** emerald `--accent #047857`, brand gradient `--grad` (emerald→cyan), structural hues
  `--sky / --violet / --amber` with `--grad-sky / --grad-violet / --grad-amber`.
- **AQI scale** `--aqi-1..5` (+ `-ink` variants) — the file already states *"colour carries meaning only here"*.
- **Type:** self-hosted Manrope (`assets/fonts/manrope-latin.woff2`), `--fs-*` clamp scale,
  `--fs-input: 1rem` floor (iOS zoom guard).
- **Layout/motion:** `--sp-*`, `--r-sm..pill`, `--t-fast 120ms` / `--t 220ms`, `--tabbar-h`, `--content-x`.
- **Breakpoints:** 400 / 600 / 768 / 1024 / 1440 plus `pointer: coarse` and
  `prefers-reduced-motion` / `prefers-reduced-transparency` / `prefers-contrast` blocks.
- Existing translucency: `.topbar` (blur 16px), `.tabbar` (blur 18px), sidenav at 1024px — the project
  already leans glassy; the redesign formalises it.
- **No `@layer`, no `@supports`, no CSS framework.** Plain CSS only.

### Structural facts any redesign must respect (verified this session)
1. **Two `:root` blocks.** The main token block is lines 13–108; a second `:root` at **lines 1393–1400**
   (the "Enhancement pass") re-overrides `--text-2` / `--text-3` and *defines* `--shadow-3` and `--ring`.
   Editing only the first block leaves green `--ring` and dark-on-light `--text-2/3` in force.
2. **`--shadow-1` and `--shadow-rest` are already defined** at `:root` (lines 99 and 68) — they are not
   undefined; they are *re-tinted* in this plan. `--shadow-3` is defined at line 1398.
3. **The router writes `document.body.dataset.kind = info.kind`** (`app.js:58`) with exactly three values
   (`page`, `auth`, `app`), and hides `#shell` unless `kind === 'app'` (`app.js:60`).
4. **The chart is hand-built SVG**, not Chart.js (`renderChart()` in `app.js`, ~line 1248). It carries
   exactly two hex literals (`#34d399` area-gradient stops at lines 1251–1252); the line/dot/grid colours
   come from CSS classes (`.chart__line`, `.chart__dot`, `.chart__grid`).
5. **`body::before` (the mist layer) is `position: fixed; z-index: -1`** and works today — proof that
   negative-z fixed layers render above the page background in this document.

### Strengths to keep
Semantic sections, one stroke icon system (sprite, no emoji), visible focus rings, ≥44px targets,
`min-height: 100dvh`, demo data always labelled, source/timestamp lines under every reading.

### Weaknesses the redesign fixes
Flat white cards with generic borders/shadows; a four-hue accent system (emerald + cyan + violet + amber)
against a strict monochrome brief; the landing hero's instrument sits on the same flat background as
everything else; opaque section bands (`.section--alt`, `.footer`) that would hide any background layer;
ambient gradients that are colour, not atmosphere.

---

## 2. Design Direction

**"Instrument on glass."** The app already presents readings as an instrument (`.instrument`,
`.aqi-hero`, `.aqi-band`). The redesign puts that instrument inside a strictly monochrome liquid-glass
world: an atmospheric background behind everything, frosted panels floating above it, white type, and
the AQI number as the brightest object on screen.

- **Palette:** strictly grayscale — **all** colour is removed, including the AQI scale and the brand mark (§3).
- **Surfaces:** replace opaque white cards with `.liquid-glass` / `.liquid-glass-strong` (§5).
- **Type:** Poppins for display + body, Source Serif 4 italic for selective emphasis (§4).
- **Motion:** restrained — press/hover feedback, soft entry, no decorative motion on data screens (§9).
- **Depth:** one light source (top-centre), shadows tinted neutral black, never coloured.

The name, tagline, mark *shape*, wordmark, voice, content and every AQI label/number stay exactly as they are.
Only the colour world changes.

### Which components get the redesign (priority order)
1. `.hero` + `.instrument` — the split hero becomes the signature moment.
2. `.topbar`, `.tabbar`, `.sidenav`, `.splash` — chrome becomes glass (already half-way there).
3. `.card` family — `.aqi-hero`, `.stat`, `.preview`, `.bento__item`, `.auth__card`, `.settings-group`.
4. `.cta-band` — aurora blobs recoloured to grayscale mist over the background.
5. Buttons, badges, ribbons, form fields — pills, glass edges, white focus rings.
6. Radar overlays — glass over an opaque Leaflet map (tiles stay untouched).
7. Footer, `.state` panels, chart colours.

---

## 3. Color Palette

**Rule: strictly grayscale. No hue anywhere — chrome, gradients, AQI scale, brand mark, errors, charts.**

### 3.1 HSL syntax convention (valid everywhere)
Every new colour uses **comma form with a separate alpha argument** — this is the one form that is
unambiguously valid in CSS Color 3 *and* 4 parsers, and it is what the revision standardises on:

```css
--gray-900: hsl(0, 0%, 6%);          /* hue 0, saturation 0% → pure gray */
--white-06:  hsla(0, 0%, 100%, 0.06); /* alpha always the 4th comma argument */
```
**Never** mix space-separated channels with a comma alpha (`hsla(0 0% 100%, 0.06)` is invalid), and never
mix slash alpha with comma alpha in the same declaration. Existing `rgba()` literals that are already
neutral (e.g. `rgba(255, 255, 255, 0.85)`) may stay; every hue-carrying literal must be converted.

### 3.2 Token merge — surgical edits, not a replacement

**Preserved untouched (do not edit):** `--sp-1..10`, `--r-sm/md/lg/xl/pill`, `--fs-display/h1/h2/h3/body/sm/xs/input`,
`--font` *(family value changes in §4, the token itself stays)*, `--t-fast`, `--t`, `--tabbar-h`, `--content-x`,
and all non-colour base rules.

**Colour-only tokens — replaced in place** (first `:root`, lines 13–108):

| Token | New value | Notes |
|---|---|---|
| `--bg` | `hsl(0, 0%, 6%)` | app base behind the background stage |
| `--bg-alt` | `hsl(0, 0%, 9%)` | alternate band — used opaquely today, see §9.3 |
| `--surface` | `hsl(0, 0%, 12%)` | solid fallback when glass is off |
| `--surface-2` | `hsl(0, 0%, 16%)` | |
| `--surface-3` | `hsl(0, 0%, 22%)` | |
| `--line` | `hsla(0, 0%, 100%, 0.12)` | |
| `--line-strong` | `hsla(0, 0%, 100%, 0.22)` | |
| `--text` | `hsl(0, 0%, 100%)` | primary: white |
| `--text-2` | `hsla(0, 0%, 100%, 0.78)` | secondary — **also edit line 1395** |
| `--text-3` | `hsla(0, 0%, 100%, 0.58)` | labels — **also edit line 1396** |
| `--accent` | `hsl(0, 0%, 100%)` | repointed to white: serves `:focus-visible` outline, `caret-color`, `.display__accent`, footer hover |
| `--accent-strong` | `hsl(0, 0%, 94%)` | defined but currently unused; kept for compatibility |
| `--accent-2` | `hsla(0, 0%, 100%, 0.78)` | links, `.eyebrow`, group ticks, notice icons → secondary text tier |
| `--accent-ink` | `hsl(0, 0%, 8%)` | ink **on top of** the now-white `--grad` fills |
| `--grad` | `linear-gradient(135deg, hsl(0, 0%, 100%), hsl(0, 0%, 84%) 130%)` | primary button becomes a white pill |
| `--grad-hover` | `linear-gradient(135deg, hsl(0, 0%, 96%), hsl(0, 0%, 80%) 130%)` | |
| `--aqi-1..5` | `hsl(0, 0%, 40%)`, `52%`, `64%`, `76%`, `90%` | band/dots/pins ramp — see §3.3 |
| `--aqi-1-ink..5-ink` | `hsl(0, 0%, 72%)`, `78%`, `84%`, `90%`, `97%` | text tier on dark glass, AA-checked |
| `--danger` | `hsl(0, 0%, 86%)` | see open question O1 |
| `--ok` | `hsl(0, 0%, 100%)` | |
| `--sky` | `hsl(0, 0%, 74%)` | structural tone (was cyan) — `#how` eyebrow, section title |
| `--violet` | `hsl(0, 0%, 66%)` | radar greeting |
| `--amber` | `hsl(0, 0%, 58%)` | planner greeting |
| `--grad-sky` | `linear-gradient(135deg, hsl(0, 0%, 90%), hsl(0, 0%, 74%) 130%)` | icon tiles keep a light face so dark ink icons read |
| `--grad-violet` | `linear-gradient(135deg, hsl(0, 0%, 86%), hsl(0, 0%, 70%) 130%)` | |
| `--grad-amber` | `linear-gradient(135deg, hsl(0, 0%, 82%), hsl(0, 0%, 66%) 130%)` | |
| `--shadow-rest` | `0 1px 2px hsla(0, 0%, 0%, 0.35), 0 14px 30px -18px hsla(0, 0%, 0%, 0.55)` | retinted only |
| `--shadow-1` | `0 1px 2px hsla(0, 0%, 0%, 0.4), 0 10px 30px -14px hsla(0, 0%, 0%, 0.5)` | **defined at line 99, kept** |
| `--shadow-2` | `0 2px 4px hsla(0, 0%, 0%, 0.45), 0 24px 48px -20px hsla(0, 0%, 0%, 0.6)` | |
| `--shadow-3` | `0 4px 10px hsla(0, 0%, 0%, 0.45), 0 32px 64px -30px hsla(0, 0%, 0%, 0.6)` | **defined at line 1398 — edit there** |
| `--ring` | `0 0 0 4px hsla(0, 0%, 100%, 0.28)` | **defined at line 1399 — edit there** |

**Added tokens:**

```css
/* Glass fills + edges */
--glass-1:      hsla(0, 0%, 100%, 0.06);
--glass-2:      hsla(0, 0%, 100%, 0.10);
--glass-edge:   hsla(0, 0%, 100%, 0.55);
--glass-edge-2: hsla(0, 0%, 100%, 0.05);
/* Focus outline colour (replaces the emerald `outline`) */
--focus:        hsl(0, 0%, 100%);
/* Display emphasis face */
--font-em:      'Source Serif 4', Georgia, serif;
```

**No token is deleted.** Repointing `--accent`, `--grad`, `--sky`, `--violet`, `--amber` and the three
`--grad-*` keeps ~45 existing call sites compiling and working while making every one of them grayscale —
this is what makes the merge safe.

### 3.3 AQI indicator → grayscale (labels, readings and numbers unchanged)
The five-step scale becomes a **brightness ramp: higher AQI = brighter = more salient.** Everything a user
*reads* is untouched: category names (Good / Moderate / Unhealthy (sensitive) / Unhealthy / Very unhealthy),
the numeric reading, the 0–300 band ticks, the ribbon text, the legend labels, `aria-label` text, and the
`src-line` provenance all stay exactly as they are.

```css
--aqi-1: hsl(0, 0%, 40%);  --aqi-1-ink: hsl(0, 0%, 72%);   /* Good */
--aqi-2: hsl(0, 0%, 52%);  --aqi-2-ink: hsl(0, 0%, 78%);   /* Moderate */
--aqi-3: hsl(0, 0%, 64%);  --aqi-3-ink: hsl(0, 0%, 84%);   /* Unhealthy (sensitive) */
--aqi-4: hsl(0, 0%, 76%);  --aqi-4-ink: hsl(0, 0%, 90%);   /* Unhealthy */
--aqi-5: hsl(0, 0%, 90%);  --aqi-5-ink: hsl(0, 0%, 97%);   /* Very unhealthy */
```
Ink values sit on `hsl(0, 0%, 12%)`-class surfaces: the lowest (`72%`) computes to ≈8:1 contrast — AA at
body size. Colour was already redundant coding here (every ribbon, dot, legend entry and pin carries text),
so nothing becomes unreadable.

**Hue-carrying literals that must also be converted** (they are *not* tokens, so a token swap misses them):

| Location | Literal | Becomes |
|---|---|---|
| `.ribbon--good/--1..4` (391–394) | `rgba(52,211,153,·)`, `rgba(250,204,21,·)`, `rgba(251,146,60,·)`, `rgba(248,113,113,·)` | `hsla(0, 0%, 100%, 0.10)` fill / `var(--aqi-N)` border per step |
| `.pill--ok` (407) | `rgba(52,211,153,·)` ×2 | `hsla(0, 0%, 100%, 0.12)` / `hsla(0, 0%, 100%, 0.4)` |
| `.btn--danger` (209–210) | `rgba(248,113,113,·)` ×2 | `hsla(0, 0%, 100%, 0.35)` / `hsla(0, 0%, 100%, 0.12)` |
| `.map__state-icon--warn` (1046) | `rgba(250,204,21,·)` ×2 | `hsla(0, 0%, 100%, 0.12)` / `hsla(0, 0%, 100%, 0.45)` |
| `.topbar__link:hover` (495) | `rgba(11,31,25,0.05)` | `hsla(0, 0%, 100%, 0.08)` |
| `.hero` (497) | emerald/cyan gradient | `linear-gradient(180deg, hsla(0, 0%, 100%, 0.07), hsla(0, 0%, 100%, 0.02) 55%, transparent)` |
| `.section--alt` (1425–1429) | cyan/emerald wash over `--bg-alt` | grayscale wash + translucency (§9.3) |
| `.btn--primary:hover` (1446) | `rgba(4,60,42,0.45)` | `hsla(0, 0%, 0%, 0.55)` |
| `body::before` (129–132) | emerald/cyan radials | `hsla(0, 0%, 100%, 0.06)` radials |
| `::selection` (135) / `caret-color` (136) / `:focus-visible` outline (138) | emerald | white / `var(--focus)` |
| `#brand-mark` sprite (template line 67) + favicon (line 17) | `#34d399→#22d3ee`, `#04140d` | `#ffffff→hsl(0,0%,55%)`, ink `hsl(0,0%,8%)` |
| `renderChart()` (`app.js` 1251–1252) | `#34d399` stops ×2 | `hsl(0, 0%, 100%)` at 0.22 / 0 opacity |

**Brand mark:** shape, geometry, stroke width and the wordmark are untouched — only the two gradient stops
and the ink stroke go grayscale, in the sprite, the favicon data-URI and `media/com_hazeguard/` copies if any.
This satisfies the strictly-monochrome requirement while preserving the logo as an identifiable mark.

---

## 4. Typography

### Families (self-hosted `.woff2`, same pattern as the existing Manrope face)
- **Poppins** — display and body → `media/com_hazeguard/assets/fonts/poppins-latin-{400,500,600,700}.woff2`.
  One `@font-face` per weight (Poppins is not variable), `font-display: swap`.
- **Source Serif 4 italic** → `source-serif-4-latin-italic-400.woff2`, used **only** for emphasis.

```css
--font:    'Poppins', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif;  /* value swap, token kept */
--font-em: 'Source Serif 4', Georgia, serif;                                    /* new token */
em, .font-em { font-family: var(--font-em); font-style: italic; }
```
The Manrope `@font-face` and its file are removed only after the swap is verified (Phase 2, last).

### Scale — all existing `--fs-*` values unchanged (tuned for 360px readability)

| Token | Value | Use | Weight | Tracking | Line-height |
|---|---|---|---|---|---|
| `--fs-display` | `clamp(2.1rem, 7vw, 3.4rem)` | `.display` | 700 | `-0.03em` | `1.04` |
| `--fs-h1` | `clamp(1.45rem, 4vw, 1.9rem)` | `.page-head__greeting`, `.auth__title` | 600 | `-0.02em` | `1.15` |
| `--fs-h2` | `1.16rem` | section/card `h2` | 600 | `-0.01em` | `1.3` |
| `--fs-h3` | `1rem` | bento/steps `h3` | 600 | `0` | `1.35` |
| `--fs-body` | `1rem` | paragraphs | 400 | `0` | `1.6` |
| `--fs-sm` | `0.875rem` | labels, `.src-line` | 500 | `0` | `1.5` |
| `--fs-xs` | `0.8125rem` | badges, ticks | 500 | `+0.02em` | `1.45` |
| `--fs-input` | `1rem` | **hard floor — never lower, iOS zoom guard** | 400 | `0` | — |

- **Emphasis:** `<em>` → Source Serif 4 italic, inheriting size. Allowed only in: the wordmark
  (*HazeGuard AI*), the hero line *Breathe better.*, `.mini-verdict__text` quotes, `.src-line` source names.
- **Readings:** `.instrument__aqi`, `.aqi-hero__value`, `.stat__val` → `font-variant-numeric: tabular-nums`
  so digits don't jitter when values update.
- `strong` stays Poppins 600 (already used in `.hero__sub`).
- **Regression risk:** Poppins runs ~6–8% wider than Manrope at equal size. The paid-for mobile fixes
  (`min-width: 0`, `overflow-wrap: anywhere` on place names, `nowrap` on coordinates, `--fs-input` floor)
  must be re-verified at 360/390px after the swap — the single most likely regression.

---

## 5. Liquid Glass System

Plain CSS classes — **no `@layer`** (the project has none; verified). Every `var()` below is defined in §3.2/§3.1.

```css
/* ---------- light glass: chrome, pills, quiet panels ---------- */
.liquid-glass {
  position: relative;
  background: var(--glass-1);
  -webkit-backdrop-filter: blur(4px) saturate(140%);
  backdrop-filter: blur(4px) saturate(140%);
  box-shadow:
    inset 0 1px 0 hsla(0, 0%, 100%, 0.35),   /* inset white highlight */
    inset 0 -1px 0 hsla(0, 0%, 100%, 0.06),
    var(--shadow-rest);                       /* defined at :root line 68 */
  border-radius: var(--r-lg);
}
/* gradient edge — replaces a conventional border */
.liquid-glass::before {
  content: "";
  position: absolute; inset: 0;
  border-radius: inherit;
  padding: 1px;                               /* edge thickness */
  background: linear-gradient(140deg,
    var(--glass-edge), var(--glass-edge-2) 38%,
    var(--glass-edge-2) 62%, hsla(0, 0%, 100%, 0.30));
  -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
  -webkit-mask-composite: xor;
          mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
          mask-composite: exclude;
  pointer-events: none;
}

/* ---------- strong glass: text panels, prominent surfaces ---------- */
.liquid-glass-strong {
  position: relative;
  background: var(--glass-2);
  -webkit-backdrop-filter: blur(50px) saturate(160%);
  backdrop-filter: blur(50px) saturate(160%);
  box-shadow:
    inset 0 1px 0 hsla(0, 0%, 100%, 0.45),
    0 24px 60px -28px hsla(0, 0%, 0%, 0.85),
    var(--shadow-1);                          /* defined at :root line 99 */
  border-radius: var(--r-xl);
}
.liquid-glass-strong::before {
  content: "";
  position: absolute; inset: 0;
  border-radius: inherit;
  padding: 1px;
  background: linear-gradient(140deg,
    hsla(0, 0%, 100%, 0.75), hsla(0, 0%, 100%, 0.10) 40%,
    hsla(0, 0%, 100%, 0.05) 60%, hsla(0, 0%, 100%, 0.45));
  -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
  -webkit-mask-composite: xor;
          mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
          mask-composite: exclude;
  pointer-events: none;
}
```

**Variable contract for the two classes** — `--glass-1`, `--glass-2`, `--glass-edge`, `--glass-edge-2`
(new), `--shadow-rest` (line 68), `--shadow-1` (line 99), `--r-lg`, `--r-xl` (lines 96). All defined;
no `var()` in these blocks resolves to nothing.

### Usage rule (prevents legibility collapse)
- **`-strong`** wherever body text lives: `.auth__card`, `.aqi-hero`, `.instrument`, `.bento__item`,
  `.preview`, `.settings-group`, `.state`, `.topbar`, `.tabbar`, `.sidenav`, `.radar-panel`.
- **light** where there is no body copy: `.quick__item`, `.badge`, `.btn--quiet`, `.icon-btn`,
  `.steps__num`, map control chips, `.mini-map` pins.
- **Never stack one glass on top of another** — an element inside a glass card gets a flat
  `hsla(0, 0%, 100%, 0.05)` fill instead, because stacked blurs collapse contrast.
- **One or two `-strong` panels per viewport** — 50px blur is the expensive part (§8.3).

### Fallbacks
```css
@supports not ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px))) {
  .liquid-glass, .liquid-glass-strong { background: hsla(0, 0%, 100%, 0.92); color: hsl(0, 0%, 8%); }
  .liquid-glass::before, .liquid-glass-strong::before { display: none; }
}
@media (prefers-reduced-transparency: reduce) {
  .liquid-glass, .liquid-glass-strong { background: var(--surface); -webkit-backdrop-filter: none; backdrop-filter: none; }
}
@media (prefers-contrast: more) {
  .liquid-glass, .liquid-glass-strong { background: hsla(0, 0%, 0%, 0.97); box-shadow: inset 0 0 0 1px hsl(0, 0%, 100%); }
}
```
The file already has `prefers-reduced-transparency` and `prefers-contrast` blocks (lines 1722–1727) —
these generalise them to the new classes.

---

## 6. Layout Plan

### Landing — split hero (the reference composition, already half-built)
At ≥1024px `.hero__inner` becomes `display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 0.85fr);`
gap `var(--sp-8)`, `max-width: 1200px`, centred.

- **Left:** eyebrow (Source Serif italic, `var(--text-3)`), `.display` white with *Breathe better.* in
  Source Serif italic, `.hero__sub`, CTA pair, trust line. Left-aligned, never centred.
- **Right:** `.instrument` → `.liquid-glass-strong`; AQI number `clamp(3.4rem, 9vw, 5rem)`, tabular-nums;
  ribbon, `.aqi-band` (grayscale ramp, ticks unchanged), PM split, source line.
- Below 1024px it stacks copy → instrument, with hero padding `--sp-8` → `--sp-6` so the instrument still
  lands above the fold on 390px.

### Topbar
`.liquid-glass`, sticky, unchanged height and the two mobile rules (`.topbar__link` hidden <768px,
`.brand__name` hidden <400px with `aria-label` preserved).

### Features (`.bento`)
Card order and copy untouched. Each `.bento__item` → `.liquid-glass-strong` with **varied radii**
(map card `--r-xl`, planner/chat `--r-lg`, wide card `--r-xl`). Icon tiles (`.stat__icon`, `.quick__icon`,
`.steps__num`) become `.liquid-glass` circles. `.bento__item--map` spans both columns ≥1024px with the
mini-map as a full-bleed top edge.

### How it works / CTA / footer
Numbers in light glass, connectors `hsla(0, 0%, 100%, 0.15)`. CTA band → `.liquid-glass-strong --r-xl`
with grayscale mist blobs, actions right-aligned ≥768 / stacked below. Footer keeps its link groups and
legal text; its background must become translucent (§9.3).

### Auth screens
`.auth__card` → `.liquid-glass-strong`, max-width 450px (existing `.auth` rule), centred over the background.
Fields: `hsla(0, 0%, 100%, 0.06)` fill, `var(--line)` border, white `:focus-visible` outline.

### App shell
- **`.sidenav` (≥1024px):** `.liquid-glass` at `blur(50px)`; active item gets `hsla(0, 0%, 100%, 0.12)`.
- **`.tabbar` (<1024px):** floating glass pill — `inset: auto 12px calc(12px + env(safe-area-inset-bottom))`,
  `border-radius: var(--r-pill)`, `blur(50px)`, **its hardcoded `rgba(255,255,255,0.85)` becomes `var(--glass-2)`**.
  `.content` bottom padding grows to `tabbar-h + 28 + 12`.
- **`.content`:** no background — the static stage behind it is the surface (§9.3).

### Dashboard
`.aqi-hero` → `-strong` (the one hero panel); `.stat`, `.quick__item` → light glass; `.preview` → `-strong`.
`.aqi-hero::before`'s emerald 3px top edge becomes `var(--grad)` (now white→gray).

### Radar
Leaflet tiles stay **opaque** (they are the data) inside `border-radius: var(--r-lg); overflow: hidden`.
Only overlays become glass: place-search row, reading panel, map control chips.

### Planner / Chat / History / Profile
Cards → `-strong`. Chat bubbles: them = light glass with `--text`; me = `hsl(0, 0%, 92%)` with
`hsl(0, 0%, 8%)` ink (highest-contrast pairing for the outgoing bubble). Chart: `.chart__grid`
`hsla(0, 0%, 100%, 0.08)`, `.chart__axis-label` `var(--text-3)`, `.chart__line` white 2px,
`.chart__dot` white, area fill = the `renderChart()` gradient now white at 0.22 → 0.

---

## 7. Background Treatment — layered (video on landing/auth, static in-app)

A full-screen video on all 13 screens would cost decode + blur GPU on the screens people use while
walking, and app screens are dense with data that must stay legible. The immersive treatment belongs on
the persuasive surfaces, exactly where the reference uses it.

### 7.1 Layer stack — verified against this document's painting order

```
painting order (root stacking context)               who provides it
────────────────────────────────────────────────────────────────────────
1. canvas background                                 body { background: var(--bg) }
                                                     → PROPAGATED because <html> has no background
2. z-index: -1  →  .bg-stage (one stacking context)
      child z 0: .bg-video      (page/auth only)
      child z 1: .bg-scrim      (gradient over video/poster)
      child z 2: .bg-stage::after  (grayscale mist)
3. in-flow content                                   .screen, .shell, sections, cards
4. positioned z 40/50/90/200                         .topbar, .tabbar, .toast, .splash
```

Rules that make this correct — **all four are verified, none speculative:**
1. **`html` must get no `background`.** If it does, `body`'s background stops being propagated and paints
   *in stage 3*, covering the video. Keep `background` on `body` only.
2. **`body` must not gain `transform` / `opacity` / `filter` / `isolation`** — that would create a new
   stacking context and trap `.bg-stage` in the wrong place. (None is present today.)
3. **One negative z-index, one element.** `.bg-stage` is the single `z-index: -1` layer; video, scrim and
   mist live *inside* it at 0/1/2. This avoids the ambiguity of two sibling `-1` layers (which would fall
   back to DOM order — and the existing `body::before` mist would then paint *over* the video).
   `body::before`'s mist is **moved into `.bg-stage::after`** and the old rule is emptied.
4. **The old `body::before` at `z-index: -1` is proof the pattern works here** — it renders behind content
   today, so a fixed negative-z layer is safe in this document.

Content that must not paint over the background: nothing — but two bands currently would (opaque
`var(--bg-alt)` on `.section--alt` and `.footer`); see §9.3.

### 7.2 Markup (added in Phase 2, immediately after `<body>`; presentation only)

```html
<div class="bg-stage" aria-hidden="true">
  <video class="bg-video" autoplay muted loop playsinline preload="metadata"
         poster="<?= $base ?>/media/com_hazeguard/assets/video/haze-poster.jpg">
    <source src="<?= $base ?>/media/com_hazeguard/assets/video/haze-loop.mp4" type="video/mp4">
  </video>
  <div class="bg-scrim"></div>
</div>
```
`aria-hidden="true"` on the wrapper, `tabindex="-1"` and no controls; the video is decorative and never
focusable.

```css
.bg-stage {
  position: fixed; inset: 0; z-index: -1; overflow: hidden;
  background: var(--bg) url('../assets/video/haze-poster.jpg') center / cover no-repeat;
}
.bg-video { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; z-index: 0;
            filter: grayscale(1) contrast(1.05); }
.bg-scrim { position: absolute; inset: 0; z-index: 1;
  background: linear-gradient(180deg, hsla(0, 0%, 4%, 0.62), hsla(0, 0%, 4%, 0.78)),
              radial-gradient(900px 520px at 50% -10%, hsla(0, 0%, 100%, 0.10), transparent 60%); }
.bg-stage::after { content: ""; position: absolute; inset: 0; z-index: 2; pointer-events: none;
  background: radial-gradient(1100px 600px at 85% -10%, hsla(0, 0%, 100%, 0.06), transparent 60%),
              radial-gradient(900px 520px at -10% 12%, hsla(0, 0%, 100%, 0.07), transparent 55%); }

/* the strategy toggle — matches router values page | auth | app (app.js:58) */
body[data-kind="app"] .bg-video { display: none; }
body[data-kind="app"] .bg-stage { background-image: none; }   /* static base only, in-app */
```

### 7.3 Toggle verification against the real markup and router
- `render()` sets `document.body.dataset.kind = info.kind` on **every** navigation (`app.js:58`), and the
  three values in `SCREENS` are exactly `page`, `auth`, `app` (`app.js:14–26`).
- The static markup starts at `<body data-kind="page" data-screen="landing">` (template line 25), so the
  **first paint already shows the video with no JS flash**; auth screens (`auth`) intentionally keep it.
- The selector `body[data-kind="app"] .bg-video` is therefore correct and complete: no new JS, no change to
  `SCREENS`, no change to `render()`. It also covers the pre-JS state, failed-JS state (stays on landing),
  and every later navigation, because `dataset.kind` is rewritten each time.
- `#shell` is hidden for `page`/`auth` and shown for `app` (`app.js:60`) — unchanged behaviour.
- A `data-screen` selector is available later if per-screen control is ever wanted; **not used now** (YAGNI).

### 7.4 Fallback ladder (poster → playback → reduced motion → data saver → no video)
1. **Poster as stage background** (`background-image`), so it is visible *whenever the video isn't* —
   blocked autoplay, codec failure, 404, `saveData`, or reduced motion all land on the same static frame,
   always under the scrim, so text contrast never depends on the video being there.
2. **Autoplay:** `autoplay muted loop playsinline` — muted+inline is the combination every current browser
   plays without a gesture. `filter: grayscale(1)` forces the footage monochrome regardless of source tint.
3. **Load error:** a `source`/`video` `error` listener adds `.bg-stage--failed`, which just hides the
   video element — the poster, scrim and mist remain, so a dead file never leaves a visual hole.
4. **Reduced motion:** `@media (prefers-reduced-motion: reduce) { .bg-video { display: none; } }` →
   poster + mist, no motion. This satisfies the brief's reduced-motion requirement without a JS check.
5. **Data saver:** in `app.js`, if `navigator.connection?.saveData`, remove the `<video>` element before
   playback starts (~3 lines, non-blocking, wrapped in optional chaining).
6. **No video at all (O2):** the design still holds on poster-free static mist alone — every other rule
   in this document is unchanged.

**Asset:** the supplied mp4 should be **downloaded into** `media/com_hazeguard/assets/video/` in Phase 2
rather than hot-linked — a third-party CDN URL can expire or be rate-limited, and self-hosting keeps the
page single-origin. The provided URL is the source file for that download (open question O3).

---

## 8. Responsive Design

Breakpoints stay **exactly** as they are (400 / 600 / 768 / 1024 / 1440 + `pointer: coarse`).

| Width | Hero | Nav | Cards |
|---|---|---|---|
| 320–400 | stacked, copy first, instrument above fold; display clamps to 2.1rem | tabbar pill; wordmark hidden (<400) | 1 column, `--r-lg`, `--sp-4` padding |
| 401–767 | stacked | tabbar pill; `.topbar__link` hidden (<768) | 1–2 col (`.stat-grid`, `.quick`) |
| 768–1023 | stacked, wider gutters | tabbar pill | `.bento` 2-col, `.dash-grid` 2-col |
| 1024–1439 | **split hero** | `.sidenav` glass rail | `.bento` 2-col, map card spans |
| ≥1440 | split, 1200px max centred | sidenav | same, extra whitespace only |

- `pointer: coarse` keeps ≥44px targets; `--fs-input: 1rem` floor and `min-width: 0` fixes untouched.
- Long place names keep `overflow-wrap: anywhere`; coordinates keep `nowrap` (existing rules).
- One video source is fine — `object-fit: cover` crops it at every width; no art-direction variants.
- **Mobile glass cost:** only the tabbar and one hero panel use `-strong` per screen; everything else light.

### 8.3 Performance guard
`blur(50px)` over playing video is the expensive combination on mid-range Android. Mitigations:
the video is `display:none` on app screens (so in-app glass blurs a static layer), at most one or two
`-strong` panels per viewport, and no `will-change` (blur is already compositor-side).

---

## 9. Interaction Design + Regression Audit

### 9.1 Interaction
- **Press:** `scale(0.97)` at 90ms on `.btn`, `.icon-btn`, `.quick__item`, `.tabbar__item` (already present — keep).
- **Hover** (`@media (hover: hover)`): cards `translateY(-2px)` + edge brightening at `--t`;
  primary button lifts with `var(--ring)`. Existing `--shadow-3` hover lifts (line 1435) are retinted, not removed.
- **Focus:** `:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; box-shadow: var(--ring); }`
  — white, visible on every glass surface. Never removed.
- **Entry:** the CSS-only `fade-rise` stagger already in the file (lines 1458–1477) is kept — **no new JS
  observer is required** (revision 2 dropped the `IntersectionObserver` idea; the existing per-screen
  animation already does it, and it runs on `hidden` → shown so it replays per navigation).
- **Reduced motion:** `@media (prefers-reduced-motion: reduce)` — video off, `fade-rise` off, hover lifts
  off, `scroll-behavior: auto`. Press scale stays: it is feedback, not decoration.
- No `window.alert()`, no new modals — inline states only (unchanged).

### 9.2 Files touched in Phase 2 (and nothing else)
| File | Change |
|---|---|
| `media/com_hazeguard/css/style.css` | colour tokens in **both** `:root` blocks; glass classes; chrome/cards; literal sweep (§3.3) |
| `templates/hazeguard/index.php` | `.bg-stage` markup; `#brand-mark` sprite stops; favicon data-URI |
| `media/com_hazeguard/js/app.js` | 2 chart gradient stops (1251–1252); optional `saveData` line |
| `media/com_hazeguard/assets/fonts/` | add Poppins ×4 + Source Serif 4 italic; remove Manrope last |
| `media/com_hazeguard/assets/video/` | add `haze-loop.mp4` + `haze-poster.jpg` (new dir) |
| `tests/static-check.cjs` | add checks (§10) |

**Untouched:** `index.php` front controller, `bootstrap.php`, every `administrator/` and `components/`
file, `libraries/HazeGuard/*`, `database/`, `radar.js` (except inherited CSS classes), routes, API,
DB, sessions/CSRF/OTP, `.env`, all `data-*` hooks, all `aria-*`, `<noscript>`, Leaflet assets.

### 9.3 Conflicting existing styles that would break the look (must change with the tokens)
1. **`.section--alt` (586 and 1425) and `.footer` (716)** paint `var(--bg-alt)` **opaquely** — over the
   background stage these become solid bands that hide the video. Becomes
   `background: hsla(0, 0%, 100%, 0.04)` (with the border kept).
2. **`.topbar` `rgba(255,255,255,0.8)` (480)** and **`.tabbar` `rgba(255,255,255,0.85)` (806)** — hardcoded
   light fills; become `var(--glass-2)` + `-strong`.
3. **Second `:root` (1393–1400)** — if skipped, `--text-2/3` revert to dark-on-light and `--ring` to emerald
   on *every* screen. Both blocks are edited in the same pass.
4. **Per-hue section rules (1528–1559)** — `#how .eyebrow`, radar/planner/chat greetings, bento/quick/stat
   icon gradients. They keep working via the repointed `--sky/--violet/--amber/--grad-*` tokens (no selector
   edits), but must be eyeballed: the icons now sit on gray tiles and need `--accent-ink` on top (they already do).
5. **`--text` on `--surface`**: all body copy flips to white; any rule that hardcodes a dark colour literal
   (rather than a token) is caught by the §3.3 sweep.
6. **Leaflet chrome:** `.leaflet-bar a` inherits light-theme defaults — must be restyled to glass or it will
   render as white squares on the dark map shell.
7. **`prefers-reduced-transparency` block (1722)** currently forces `background: #ffffff` on the topbar/tabbar —
   that would be a white bar in the dark theme; re-point to `var(--surface)`.

---

## 10. Implementation Plan (Phase 2, ordered)

Each step is independently testable; run the full suite after each.

1. **Tokens** — surgical edits to colour lines only in `:root` #1 (13–108) *and* `:root` #2 (1393–1400) per §3.2;
   add the new glass/focus/`--font-em` tokens. Verify with a `var()` audit that every referenced token resolves.
2. **Literal sweep** — apply the §3.3 table (ribbons, pills, danger button, map warn, hero/section gradients,
   selection/caret/focus, `body::before`, brand sprite, favicon, chart stops) + §9.3 items 1/2/7 (opaque bands,
   chrome fills, reduced-transparency block) + Leaflet control styling.
3. **Fonts** — add Poppins + Source Serif 4 `.woff2`, switch `--font` value, add `em` rule and `tabular-nums`;
   remove Manrope last.
4. **Background stage** — add `.bg-stage` markup (§7.2) to the template; empty `body::before`'s mist (moved
   into `.bg-stage::after`); download mp4 + generate poster into `assets/video/`.
5. **Glass system** — append §5 (plain classes + three fallback blocks).
6. **Chrome** — `.topbar`, `.tabbar` (floating pill), `.sidenav`, `.splash` → glass; re-verify the <768 / <400
   header rules and `aria-label` fallback.
7. **Landing** — hero grid ≥1024, `.instrument` → `-strong`, bento radii/spans, steps, CTA, footer translucency.
8. **Auth** — `.auth__card` → `-strong`; field fills/focus.
9. **App screens** — `.aqi-hero`, `.stat`, `.preview`, `.quick`, `.settings-group`, chat bubbles, `.state`
   panels, radar overlays, chart colours.
10. **Motion + fallbacks** — reduced-motion block, optional `saveData` line, video `error` listener.
11. **Verify (all required before claiming done):**
    `C:\xampp\php\php.exe tests/run.php` (294 backend checks — must stay green),
    `node tests/static-check.cjs` (113 existing; **add**: `.liquid-glass`/`.liquid-glass-strong` exist as plain
    CSS, no `@layer`, no undefined `var()`, video has `muted loop playsinline aria-hidden`, `--fs-input` floor
    intact, no remaining hue literals — extend `tests/encoding-check.php` coverage), `node tests/locate-check.cjs`,
    `C:\xampp\php\php.exe tests/encoding-check.php`, `php -l` on changed PHP, `node --check` on changed JS,
    browser pass with `tests/responsive.html` at 360/390/768/1024/1440 + the Impeccable detector.
    **No desktop browser is attached to this session** — if that holds in Phase 2, the visual pass must be done
    by you or in a session with a browser; static checks alone do not prove the look.

**Not doing:** no new dependencies, no build step, no `@layer`/Tailwind/framework, no router or API changes,
no copy rewrites, no `.env`/route/DB changes, no second implementation of anything.

---

## 11. Preservation Requirements

- **Routes & backend:** `/`, `/api/*`, `index.php` front controller, all PHP controllers/models,
  `administrator/` panel, PDO parameterised queries, sessions/CSRF, OTP, password reset.
- **Integrations:** Open-Meteo (PHP-proxied), vendored Leaflet 1.9.4 at `assets/leaflet/`, the `TILES`
  constant in `radar.js`, the AI service contract.
- **JS hooks:** every `data-*` (`data-nav`, `data-action`, `data-aqi-*`, `data-user-*`, `PLACE_SEARCHES`),
  every `id` the router reads, `SCREENS` / `onEnterScreen()` / `render()` wiring, `document.body.dataset` behaviour.
- **Semantics & a11y:** all `role`/`aria-*`, heading order, `<noscript>` fallbacks, focus rings, ≥44px targets,
  `prefers-*` support, visible `Demo` labels, AQI category names, readings, ticks, `aria-label`s and provenance lines.
- **Content:** headlines, tagline, feature copy, steps, footer, legal links — unchanged.
- **Paid-for responsive fixes:** `--fs-input` floor, `min-width: 0`, `.topbar__link` <768, wordmark <400,
  coordinate `nowrap`, place-name `overflow-wrap`.
- **Structure:** same folders, same files, same test commands; only `assets/video/` and font files are added.
- **Tests:** 294 + 113 + 5 must all still pass; new checks are added, none relaxed.

---

## 12. Remaining Decisions Before Phase 2

| # | Decision | Recommendation |
|---|---|---|
| **O1** | **Error/danger colour.** `--danger` / `.btn--danger` / `.input[aria-invalid]` / `.form-error` currently read red. The brief names only the AQI scale and brand mark, but strict grayscale implies these too. | **Convert to grayscale** (`hsl(0, 0%, 86%)` + brighter border + the existing alert icon + text) — error meaning is already carried by icon and copy, never colour alone. Confirm, or say *"keep red for errors"* and it becomes a third documented exception. |
| **O2** | **AQI brightness direction.** I chose *higher AQI → brighter* (worse air is the most salient thing on screen). | Confirm, or invert to *higher AQI → darker* if you read the ramp the other way. Labels and numbers are unaffected either way. |
| **O3** | **Video asset.** Download the supplied mp4 (+ poster frame) into `media/com_hazeguard/assets/video/` in Phase 2, or hot-link the CDN URL? | **Download** — self-hosted, single-origin, immune to CDN expiry. This adds ~2 files and one new folder. |
| **O4** | **Video scope.** Keep the layered plan — video on `page` + `auth`, static in-app? | Keep as specified in §7. Alternative (static everywhere) is a one-line change and drops the poster too. |

Awaiting your explicit approval (and O1–O4) before Phase 2.
