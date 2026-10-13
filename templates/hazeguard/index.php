<?php
/**
 * HazeGuard AI — site template (Joomla: templates/<name>/index.php).
 *
 * The single-page app shell. The front controller (index.php) serves it at "/".
 * Static assets live under media/com_hazeguard/, which is where Joomla keeps a
 * component's css/js; the app talks to the JSON API at /api/*.
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="color-scheme" content="light dark">
  <meta name="theme-color" content="#000000">
  <!-- Flags JS before first paint so the hero sequence can start hidden. The timer
       is the safety net: if landing.js never loads, the page still reveals itself. -->
  <script>document.documentElement.classList.add('js');setTimeout(function(){document.documentElement.classList.add('is-ready')},3000);</script>
  <title>HazeGuard AI — Understand the air. Plan smarter. Breathe better.</title>
  <meta name="description" content="HazeGuard AI helps you understand local haze and air quality, explore it on a map, and plan outdoor activities with AI help.">
  <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%23e8e8e8'/%3E%3Cpath d='M6 13h13a3.5 3.5 0 1 0-3.5-3.5M6 18h16a3 3 0 1 1-3 3' fill='none' stroke='%230a0a0a' stroke-width='2.2' stroke-linecap='round'/%3E%3C/svg%3E">
  <!-- Apple devices use SF Pro through the system stack; everything else gets Inter. -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
  <link rel="stylesheet" href="<?= $base ?>/media/com_hazeguard/css/style.css">
  <!-- Leaflet 1.9.4, vendored (BSD-2-Clause, see assets/leaflet/LICENSE). The map is
       the only feature that needs it, so its CSS is deferred rather than blocking
       every screen behind 15 KB the other eleven never use. -->
  <link rel="stylesheet" href="<?= $base ?>/media/com_hazeguard/assets/leaflet/leaflet.css" media="print" onload="this.media='all'">
  <noscript><link rel="stylesheet" href="<?= $base ?>/media/com_hazeguard/assets/leaflet/leaflet.css"></noscript>
</head>
<body data-kind="page" data-screen="landing">

  <!-- Background stage: the decorative layer, one fixed negative-z box so video,
       scrim and mist can never overlap page content or intercept pointer events.
       The video only shows where the router wants it (body[data-kind]); see
       .bg-stage in style.css for the stack order and every fallback. -->
  <div class="bg-stage" aria-hidden="true">
    <video class="bg-video" autoplay muted loop playsinline disablepictureinpicture preload="auto"
           src="<?= $base ?>/media/com_hazeguard/assets/video/haze-loop.mp4"></video>
    <div class="bg-scrim"></div>
  </div>

  <!-- Initial-load splash: light, brief, removed on window load; never shown during in-app navigation -->
  <div class="splash" id="splash" role="status" aria-label="Loading HazeGuard AI">
    <div class="splash__stack">
      <span class="splash__ring" aria-hidden="true"><svg class="splash__mark" viewBox="0 0 32 32"><use href="#brand-mark"/></svg></span>
      <p class="splash__name">HazeGuard <em>AI</em></p>
      <span class="splash__dots" aria-hidden="true"><i></i><i></i><i></i></span>
    </div>
  </div>
  <noscript><style>.splash { display: none; }</style></noscript>

  <!-- Icon sprite: one stroke system, no emoji -->
  <svg xmlns="http://www.w3.org/2000/svg" style="position:absolute;width:0;height:0;overflow:hidden" aria-hidden="true" focusable="false">
    <defs><linearGradient id="brandGrad" gradientUnits="userSpaceOnUse" x1="0" y1="0" x2="32" y2="32"><stop stop-color="#ffffff"/><stop offset="1" stop-color="#b9c2bd"/></linearGradient></defs>
    <symbol id="i-home" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5.5 9.5V21h13V9.5"/><path d="M9.5 21v-6h5v6"/></g></symbol>
    <symbol id="i-radar" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="1.1"/><path d="M12 12l6-6.4"/></g></symbol>
    <symbol id="i-planner" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3.5" y="5" width="17" height="15.5" rx="2.5"/><path d="M8 3v4M16 3v4M3.5 10h17"/><path d="m9.5 15.5 1.8 1.8 3.4-3.6"/></g></symbol>
    <symbol id="i-chat" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 14.5a2 2 0 0 1-2 2H8.5L4 21V6a2 2 0 0 1 2-2h12.5a2 2 0 0 1 2 2z"/><path d="M8 9h8M8 12.5h5"/></g></symbol>
    <symbol id="i-user" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="3.6"/><path d="M5 20a7 7 0 0 1 14 0"/></g></symbol>
    <symbol id="i-search" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/></g></symbol>
    <symbol id="i-locate" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="6.5"/><circle cx="12" cy="12" r="2.2"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3"/></g></symbol>
    <symbol id="i-eye" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M2.5 12S6 6.5 12 6.5 21.5 12 21.5 12 18 17.5 12 17.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="2.6"/></g></symbol>
    <symbol id="i-eye-off" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8.5C2.9 10 2.5 12 2.5 12S6 17.5 12 17.5c1.4 0 2.6-.3 3.7-.8M9.5 7c.8-.3 1.6-.5 2.5-.5 6 0 9.5 5.5 9.5 5.5s-.9 1.6-2.6 3.1"/><path d="M4 4l16 16"/><path d="M10.2 10.3a2.6 2.6 0 0 0 3.6 3.6"/></g></symbol>
    <symbol id="i-arrow-right" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h15m-6-6 6 6-6 6"/></g></symbol>
    <symbol id="i-chevron-left" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m14.5 5.5-6.5 6.5 6.5 6.5"/></g></symbol>
    <symbol id="i-check" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12.8 4.6 4.7L19 7.5"/></g></symbol>
    <symbol id="i-alert" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4 2.8 20h18.4z"/><path d="M12 10.5v4M12 17.4v.2"/></g></symbol>
    <symbol id="i-clock" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/></g></symbol>
    <symbol id="i-send" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M20.5 3.5 3.5 10.2l7 2.8 2.8 7z"/><path d="M20.5 3.5 10.5 13"/></g></symbol>
    <symbol id="i-air" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M3 8.5h10.5a3 3 0 1 0-3-3"/><path d="M3 13h14a3 3 0 1 1-3 3"/><path d="M3 17.5h6"/></g></symbol>
    <symbol id="i-particles" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><circle cx="8" cy="8.5" r="2.4"/><circle cx="15.5" cy="10.5" r="3.4"/><circle cx="9.5" cy="16.5" r="1.7"/><path d="M4 13.5h1.5M18.5 6h2"/></g></symbol>
    <symbol id="i-map" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4 3.5 6.5v13L9 17l6 2.5 5.5-2.5v-13L15 6.5z"/><path d="M9 4v13M15 6.5v13"/></g></symbol>
    <symbol id="i-sparkle" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4.5l1.7 4.8 4.8 1.7-4.8 1.7L11 17.5l-1.7-4.8-4.8-1.7 4.8-1.7z"/><path d="M18 15.5l.7 2 2 .7-2 .7-.7 2-.7-2-2-.7 2-.7z"/></g></symbol>
    <symbol id="i-bell" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M6.5 9.5a5.5 5.5 0 1 1 11 0c0 4.5 2 5.8 2 5.8h-15s2-1.3 2-5.8z"/><path d="M10.2 19a2 2 0 0 0 3.6 0"/></g></symbol>
    <symbol id="i-logout" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M9.5 4H6.5a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h3"/><path d="m14.5 8 4 4-4 4M18.5 12H9.5"/></g></symbol>
    <symbol id="i-chart" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4v16h16"/><path d="m7.5 15 3.5-4.5 3 3 4.5-6"/></g></symbol>
    <symbol id="i-info" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="8.5"/><path d="M12 11.5v5M12 8v.2"/></g></symbol>
    <symbol id="i-x" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="m6.5 6.5 11 11M17.5 6.5l-11 11"/></g></symbol>
    <symbol id="i-refresh" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M19.5 12a7.5 7.5 0 1 1-2.2-5.3"/><path d="M19.5 4v5h-5"/></g></symbol>
    <symbol id="i-sliders" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7.5h8M17 7.5h3M4 16.5h3M12 16.5h8"/><circle cx="14.5" cy="7.5" r="2.2"/><circle cx="9.5" cy="16.5" r="2.2"/></g></symbol>
    <symbol id="i-location-pin" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s6.5-6 6.5-11a6.5 6.5 0 1 0-13 0C5.5 15 12 21 12 21z"/><circle cx="12" cy="10" r="2.4"/></g></symbol>
    <symbol id="i-shield" viewBox="0 0 24 24"><g fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3.5 5 6v6c0 4.4 3 7.4 7 8.5 4-1.1 7-4.1 7-8.5V6z"/><path d="m9.2 12 2 2 3.6-3.8"/></g></symbol>
    <symbol id="brand-mark" viewBox="0 0 32 32"><rect width="32" height="32" rx="9" fill="url(#brandGrad)"/><path d="M7 13.5h12a3.2 3.2 0 1 0-3.2-3.2M7 18.5h14a2.8 2.8 0 1 1-2.8 2.8" fill="none" stroke="#0a0a0a" stroke-width="2.2" stroke-linecap="round"/></symbol>
  </svg>

  <!-- ============================ LANDING ============================ -->
  <div class="screen screen--page" id="screen-landing">
    <header class="topbar" id="topbar">
      <div class="topbar__inner">
        <a class="brand" href="#landing" data-go="landing" aria-label="HazeGuard AI home">
          <svg class="brand__mark" viewBox="0 0 32 32" aria-hidden="true"><use href="#brand-mark"/></svg>
          <span class="brand__name">HazeGuard <em>AI</em></span>
        </a>
        <nav class="topbar__nav" aria-label="Landing">
          <a href="#features" class="topbar__link">Features</a>
          <a href="#how" class="topbar__link">How it works</a>
          <a href="#login" data-go="login" class="btn btn--quiet">Sign in</a>
          <a href="#register" data-go="register" class="btn btn--primary btn--sm">Create account</a>
        </nav>
      </div>
    </header>

    <!-- Hero: deep space behind white type, so the headline never competes with
         the planet. The live reading sits underneath as one glass card whose glow
         takes the colour of the current AQI band (landing.js sets data-band).
         Every data-aqi-* hook below is driven by airquality.js. -->
    <main class="hero" id="main-landing">
      <div class="hero__inner">
        <div class="hero__copy">
          <h1 class="display">
            <span class="display__line" style="--i:0"><span>Understand the air.</span></span>
            <span class="display__line" style="--i:1"><span>Plan smarter.</span></span>
            <span class="display__line" style="--i:2"><span>Breathe better.</span></span>
          </h1>
          <p class="hero__sub">Check the air where you are, explore it on a map, and get a clear answer for your plans.</p>
          <div class="hero__cta">
            <a href="#register" data-go="register" class="btn btn--primary btn--lg">Create free account <svg class="icon" aria-hidden="true"><use href="#i-arrow-right"/></svg></a>
            <a href="#login" data-go="login" class="btn btn--ghost btn--lg">Sign in</a>
          </div>
          <ul class="hero__trust" aria-label="What to expect">
            <li><svg class="icon icon--sm" aria-hidden="true"><use href="#i-check"/></svg> Free open data</li>
            <li><svg class="icon icon--sm" aria-hidden="true"><use href="#i-check"/></svg> No ads</li>
            <li><svg class="icon icon--sm" aria-hidden="true"><use href="#i-check"/></svg> Built mobile-first</li>
          </ul>
        </div>

        <!-- Pre-paint and no-JS state: no place is named and no number is shown until
             the server answers, because a hardcoded city is how a Johor Bahru visitor
             gets told "Kuala Lumpur" first. -->
        <figure class="instrument instrument--dock" aria-labelledby="instrument-caption">
          <figcaption class="instrument__head" id="instrument-caption">
            <span class="instrument__place"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-location-pin"/></svg> <span data-aqi-place>—</span></span>
            <span class="badge badge--loading" data-aqi-badge>Checking…</span>
          </figcaption>
          <div class="instrument__reading">
            <span class="instrument__aqi" data-aqi-value>—</span>
            <span class="instrument__meta">
              <span class="instrument__cat" data-aqi-cat>Waiting</span>
              <span class="instrument__label" data-aqi-label>US AQI · waiting for a reading</span>
            </span>
          </div>
          <div class="instrument__split">
            <div class="instrument__stat"><span class="instrument__stat-val"><span data-aqi-pm25>—</span> <small>µg/m³</small></span><span class="instrument__stat-label">PM2.5</span></div>
            <div class="instrument__stat"><span class="instrument__stat-val"><span data-aqi-pm10>—</span> <small>µg/m³</small></span><span class="instrument__stat-label">PM10</span></div>
          </div>
          <div class="aqi-band" data-aqi-band role="img" aria-label="Air quality index not yet available">
            <div class="aqi-band__track"><span class="aqi-band__seg aqi-band__seg--1"></span><span class="aqi-band__seg aqi-band__seg--2"></span><span class="aqi-band__seg aqi-band__seg--3"></span><span class="aqi-band__seg aqi-band__seg--4"></span><span class="aqi-band__seg aqi-band__seg--5"></span></div>
            <span class="aqi-band__marker" data-aqi-marker style="--pos:0%; display:none"></span>
            <div class="aqi-band__labels" aria-hidden="true"><span style="left:0%">0</span><span style="left:16.67%">50</span><span style="left:33.33%">100</span><span style="left:50%">150</span><span style="left:66.67%">200</span><span style="left:100%">300</span></div>
          </div>
          <p class="instrument__foot" data-aqi-src>Waiting for your location and the current reading.</p>
          <noscript><p class="instrument__foot">JavaScript is disabled, so no reading can be fetched. Nothing above is a measurement.</p></noscript>
        </figure>
      </div>
    </main>

    <!-- The whole idea in one sentence. landing.js lights it word by word as it scrolls. -->
    <section class="statement" id="story" aria-label="What HazeGuard AI does">
      <div class="section__inner">
        <p class="statement__text" data-words>HazeGuard AI turns local air-quality data into one simple answer: is today’s air okay for what you want to do?</p>
      </div>
    </section>

    <!-- Features: four tiles. Each names the feature, then says what it does for you. -->
    <section class="section section--alt" id="features" aria-labelledby="features-title">
      <div class="section__inner">
        <header class="section__head" data-in>
          <h2 id="features-title">A map, a planner, and an assistant.</h2>
          <p>All three answer from the same air-quality data.</p>
        </header>

        <div class="features">
          <article class="feature feature--radar" data-in>
            <div class="feature__copy">
              <p class="feature__label"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-map"/></svg> Haze Radar</p>
              <h3>See the haze before you head out.</h3>
              <p class="feature__text">Explore air quality around any neighbourhood on an interactive map. Search a place, drop your pin, and compare areas before you commit to a route.</p>
              <p class="feature__note">Live readings on a Leaflet map, sampled around wherever you point it.</p>
            </div>
            <div class="feature__visual">
              <div class="mini-map" aria-hidden="true">
                <span class="pin pin--good" style="--x:22%;--y:30%;--k:0"><i></i>48</span>
                <span class="pin pin--mod" style="--x:62%;--y:20%;--k:1"><i></i>72</span>
                <span class="pin pin--usg" style="--x:80%;--y:62%;--k:2"><i></i>118</span>
                <span class="pin pin--good" style="--x:30%;--y:74%;--k:3"><i></i>55</span>
                <span class="mini-map__me"></span>
              </div>
            </div>
          </article>

          <article class="feature feature--planner feature--dark" data-in style="--i:1">
            <div class="feature__copy">
              <p class="feature__label"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-planner"/></svg> AI Activity Planner</p>
              <h3>Get a yes or no for your plan.</h3>
              <p class="feature__text">Tell it what you want to do, where and when. You get a plain-language verdict with the reasoning, plus an indoor fallback for bad-air days.</p>
              <p class="feature__note"><span class="badge badge--demo">Demo recommendation</span></p>
            </div>
            <div class="feature__visual feature__visual--pad">
              <div class="mini-verdict">
                <span class="ribbon ribbon--good">Good window · AQI 64</span>
                <div class="mini-hours" aria-hidden="true">
                  <i style="--h:64%;--c:var(--aqi-2);--k:0"></i><i style="--h:71%;--c:var(--aqi-2);--k:1"></i><i style="--h:78%;--c:var(--aqi-2);--k:2"></i><i style="--h:84%;--c:var(--aqi-2);--k:3"></i><i style="--h:87%;--c:var(--aqi-2);--k:4"></i><i style="--h:89%;--c:var(--aqi-2);--k:5"></i><i style="--h:85%;--c:var(--aqi-2);--k:6"></i><i style="--h:80%;--c:var(--aqi-2);--k:7"></i><i style="--h:71%;--c:var(--aqi-2);--k:8"></i><i class="is-pick" style="--h:58%;--c:var(--aqi-1);--k:9"></i><i style="--h:53%;--c:var(--aqi-1);--k:10"></i><i style="--h:49%;--c:var(--aqi-1);--k:11"></i><i style="--h:45%;--c:var(--aqi-1);--k:12"></i>
                </div>
                <div class="mini-hours__axis" aria-hidden="true"><span>9 AM</span><span>3 PM</span><span>9 PM</span></div>
                <span class="mini-verdict__text">“Evening run at 6 PM is reasonable, with particulate levels easing after sunset.”</span>
              </div>
            </div>
          </article>

          <article class="feature feature--chat feature--green" data-in>
            <div class="feature__copy">
              <p class="feature__label"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-chat"/></svg> BreatheBuddy AI</p>
              <h3>Ask about the air in your own words.</h3>
              <p class="feature__text">BreatheBuddy answers from real air-quality tool calls, and says so when data is missing.</p>
              <p class="feature__note"><span class="badge badge--demo">Demo conversation</span></p>
            </div>
            <div class="feature__visual feature__visual--pad">
              <div class="mini-chat" aria-hidden="true">
                <span class="mini-chat__bubble mini-chat__bubble--me">Is today okay for my evening run?</span>
                <div class="mini-chat__reply">
                  <span class="mini-chat__typing"><i></i><i></i><i></i></span>
                  <span class="mini-chat__bubble mini-chat__bubble--them">AQI 72 (moderate) at 6 PM: fine for most people. Avoid main roads at rush hour.</span>
                </div>
              </div>
            </div>
          </article>

          <article class="feature feature--honest" data-in style="--i:1">
            <div class="feature__copy">
              <p class="feature__label"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-air"/></svg> Honest data</p>
              <h3>Estimates are labelled as estimates.</h3>
              <p class="feature__text">Model-based estimates are always marked as estimates and kept separate from official station measurements. Every reading shows its source, units and timestamp. When data is missing, we say missing, never zero.</p>
            </div>
            <ul class="legend-inline" aria-label="Air quality categories">
              <li><i class="dot dot--1"></i><span>Good</span><small>0–50</small></li>
              <li><i class="dot dot--2"></i><span>Moderate</span><small>51–100</small></li>
              <li><i class="dot dot--3"></i><span>Unhealthy for sensitive groups</span><small>101–150</small></li>
              <li><i class="dot dot--4"></i><span>Unhealthy</span><small>151–200</small></li>
              <li><i class="dot dot--5"></i><span>Very unhealthy</span><small>201+</small></li>
            </ul>
          </article>
        </div>
      </div>
    </section>

    <!-- How it works: this one really is a sequence, so the numbers stay. -->
    <section class="section" id="how" aria-labelledby="how-title">
      <div class="section__inner">
        <header class="section__head" data-in>
          <h2 id="how-title">From a location to a decision in three steps.</h2>
        </header>
        <ol class="steps">
          <li class="steps__item" data-in>
            <span class="steps__num" aria-hidden="true">1</span>
            <h3>Check your air</h3>
            <p>Tap “Use my location” and see AQI, PM2.5 and PM10 for where you are, with source and timestamp attached.</p>
          </li>
          <li class="steps__item" data-in style="--i:1">
            <span class="steps__num" aria-hidden="true">2</span>
            <h3>See it on the map</h3>
            <p>Open Haze Radar to compare areas, find cleaner routes and inspect individual readings.</p>
          </li>
          <li class="steps__item" data-in style="--i:2">
            <span class="steps__num" aria-hidden="true">3</span>
            <h3>Plan with AI</h3>
            <p>Ask BreatheBuddy or generate an activity plan. Recommendations cite the data they used, never invented readings.</p>
          </li>
        </ol>
      </div>
    </section>

    <section class="cta-band" aria-labelledby="cta-title">
      <div class="section__inner cta-band__inner" data-in>
        <h2 id="cta-title">Ready to check your air?</h2>
        <p>Create an account to save plans and preferences, or sign in to continue.</p>
        <div class="cta-band__actions">
          <a href="#register" data-go="register" class="btn btn--primary btn--lg">Create free account</a>
          <a href="#login" data-go="login" class="btn btn--ghost btn--lg">Sign in</a>
        </div>
      </div>
    </section>

    <footer class="footer">
      <div class="section__inner footer__inner">
        <div class="footer__brand">
          <span class="brand__name">HazeGuard <em>AI</em></span>
          <p>Understand the air. Plan smarter. Breathe better.</p>
        </div>
        <nav class="footer__links" aria-label="Footer">
          <a href="#features">Features</a>
          <a href="#how">How it works</a>
          <a href="#login" data-go="login">Sign in</a>
          <a href="#register" data-go="register">Create account</a>
        </nav>
        <p class="footer__legal">Prototype build. Air-quality values shown in this interface are clearly labelled demo data and are not live readings. HazeGuard AI provides environmental information, not medical advice. © 2026 HazeGuard AI.</p>
      </div>
    </footer>
  </div>

  <!-- ============================ AUTH SCREENS ============================ -->
  <div class="screen screen--auth" id="screen-login" hidden>
    <div class="auth">
      <a class="brand brand--auth" href="#landing" data-go="landing" aria-label="Back to HazeGuard AI home">
        <svg class="brand__mark" viewBox="0 0 32 32" aria-hidden="true"><use href="#brand-mark"/></svg>
        <span class="brand__name">HazeGuard <em>AI</em></span>
      </a>
      <div class="auth__card card liquid-glass-strong">
        <h1 class="auth__title">Welcome back</h1>
        <p class="auth__sub">Sign in to your HazeGuard account.</p>

        <div class="seg" role="tablist" aria-label="Sign-in method">
          <button type="button" class="seg__btn is-active" role="tab" aria-selected="true" id="tab-email" aria-controls="panel-login-email" data-tab="login-email">Email</button>
          <button type="button" class="seg__btn" role="tab" aria-selected="false" id="tab-username" aria-controls="panel-login-username" data-tab="login-username">Username</button>
          <button type="button" class="seg__btn" role="tab" aria-selected="false" id="tab-otp" aria-controls="panel-login-otp" data-tab="login-otp">Email OTP</button>
        </div>

        <!-- Method A: email + password -->
        <form class="tabpanel" id="panel-login-email" role="tabpanel" aria-labelledby="tab-email" data-tabpanel="login-email" novalidate>
          <div class="field">
            <label for="login-email">Email address</label>
            <input class="input" type="email" id="login-email" name="email" autocomplete="email" placeholder="you@example.com" required>
            <p class="field__error" data-error-for="login-email" hidden></p>
          </div>
          <div class="field">
            <label for="login-email-pw">Password</label>
            <div class="input-wrap">
              <input class="input" type="password" id="login-email-pw" name="password" autocomplete="current-password" placeholder="Your password" required>
              <button type="button" class="input-btn" data-toggle-password="login-email-pw" aria-label="Show password"><svg class="icon" aria-hidden="true"><use href="#i-eye"/></svg></button>
            </div>
            <p class="field__error" data-error-for="login-email-pw" hidden></p>
          </div>
          <div class="row row--end">
            <a class="link" href="#forgot" data-go="forgot">Forgot password?</a>
          </div>
          <button class="btn btn--primary btn--block" type="submit">Sign in</button>
        </form>

        <!-- Method B: username + password -->
        <form class="tabpanel" id="panel-login-username" role="tabpanel" aria-labelledby="tab-username" data-tabpanel="login-username" hidden novalidate>
          <div class="field">
            <label for="login-username">Username</label>
            <input class="input" type="text" id="login-username" name="username" autocomplete="username" placeholder="e.g. kl_walker" required>
            <p class="field__error" data-error-for="login-username" hidden></p>
          </div>
          <div class="field">
            <label for="login-username-pw">Password</label>
            <div class="input-wrap">
              <input class="input" type="password" id="login-username-pw" name="password" autocomplete="current-password" placeholder="Your password" required>
              <button type="button" class="input-btn" data-toggle-password="login-username-pw" aria-label="Show password"><svg class="icon" aria-hidden="true"><use href="#i-eye"/></svg></button>
            </div>
            <p class="field__error" data-error-for="login-username-pw" hidden></p>
          </div>
          <div class="row row--end">
            <a class="link" href="#forgot" data-go="forgot">Forgot password?</a>
          </div>
          <button class="btn btn--primary btn--block" type="submit">Sign in</button>
        </form>

        <!-- Method C: email OTP -->
        <div class="tabpanel" id="panel-login-otp" role="tabpanel" aria-labelledby="tab-otp" data-tabpanel="login-otp" hidden>
          <form id="form-otp-request" novalidate>
            <div class="field">
              <label for="otp-email">Email address</label>
              <input class="input" type="email" id="otp-email" name="email" autocomplete="email" placeholder="you@example.com" required>
              <p class="field__error" data-error-for="otp-email" hidden></p>
            </div>
            <button class="btn btn--primary btn--block" type="submit">Send one-time code</button>
          </form>

          <form id="form-otp-verify" hidden novalidate>
            <div class="field">
              <label for="otp-code">6-digit code</label>
              <input class="input input--otp" type="text" id="otp-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" required>
              <p class="field__hint" id="otp-hint">Codes expire after 5 minutes and work once.</p>
              <p class="field__error" data-error-for="otp-code" hidden></p>
            </div>
            <div class="row row--between otp-actions">
              <span class="otp-countdown" id="otp-countdown" aria-live="polite"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-clock"/></svg> Resend in <span data-otp-seconds>45</span>s</span>
              <button type="button" class="link" id="otp-resend" data-resend hidden>Resend code</button>
            </div>
            <button class="btn btn--primary btn--block" type="submit">Verify &amp; sign in</button>
            <button type="button" class="btn btn--quiet btn--block" id="otp-back">Use a different method</button>
          </form>
        </div>

        <p class="auth__switch">New to HazeGuard? <a class="link" href="#register" data-go="register">Create an account</a></p>
        <p class="auth__note"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-info"/></svg> Signing in opens a real session on this device. The code is emailed, expires after 5 minutes and works once.</p>
      </div>
    </div>
  </div>

  <div class="screen screen--auth" id="screen-register" hidden>
    <div class="auth">
      <a class="brand brand--auth" href="#landing" data-go="landing" aria-label="Back to HazeGuard AI home">
        <svg class="brand__mark" viewBox="0 0 32 32" aria-hidden="true"><use href="#brand-mark"/></svg>
        <span class="brand__name">HazeGuard <em>AI</em></span>
      </a>
      <div class="auth__card card liquid-glass-strong">
        <h1 class="auth__title">Create your account</h1>
        <p class="auth__sub">Free forever. Your preferences stay private to you.</p>

        <form id="form-register" novalidate>
          <div class="field">
            <label for="reg-username">Username</label>
            <input class="input" type="text" id="reg-username" autocomplete="username" placeholder="3–20 characters: letters, numbers, _" required>
            <p class="field__error" data-error-for="reg-username" hidden></p>
          </div>
          <div class="field">
            <label for="reg-email">Email address</label>
            <input class="input" type="email" id="reg-email" autocomplete="email" placeholder="you@example.com" required>
            <p class="field__error" data-error-for="reg-email" hidden></p>
          </div>
          <div class="field">
            <label for="reg-password">Password</label>
            <div class="input-wrap">
              <input class="input" type="password" id="reg-password" autocomplete="new-password" placeholder="At least 8 characters" required>
              <button type="button" class="input-btn" data-toggle-password="reg-password" aria-label="Show password"><svg class="icon" aria-hidden="true"><use href="#i-eye"/></svg></button>
            </div>
            <p class="field__error" data-error-for="reg-password" hidden></p>
          </div>
          <div class="field">
            <label for="reg-confirm">Confirm password</label>
            <div class="input-wrap">
              <input class="input" type="password" id="reg-confirm" autocomplete="new-password" placeholder="Repeat your password" required>
              <button type="button" class="input-btn" data-toggle-password="reg-confirm" aria-label="Show password"><svg class="icon" aria-hidden="true"><use href="#i-eye"/></svg></button>
            </div>
            <p class="field__error" data-error-for="reg-confirm" hidden></p>
          </div>
          <label class="check">
            <input type="checkbox" id="reg-terms" required>
            <span>I agree to the <a class="link" href="#register" onclick="return false" title="Placeholder in prototype">Terms</a> and <a class="link" href="#register" onclick="return false" title="Placeholder in prototype">Privacy Policy</a> (placeholder links in this prototype).</span>
          </label>
          <p class="field__error" data-error-for="reg-terms" hidden></p>
          <button class="btn btn--primary btn--block" type="submit">Create account</button>
        </form>

        <p class="auth__switch">Already have an account? <a class="link" href="#login" data-go="login">Sign in</a></p>
        <p class="auth__note"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-info"/></svg> We store your account securely — passwords are hashed, not kept in plain text, and you confirm your email with a code before the account goes live.</p>
      </div>
    </div>
  </div>

  <div class="screen screen--auth" id="screen-otp" hidden>
    <div class="auth">
      <a class="brand brand--auth" href="#landing" data-go="landing" aria-label="Back to HazeGuard AI home">
        <svg class="brand__mark" viewBox="0 0 32 32" aria-hidden="true"><use href="#brand-mark"/></svg>
        <span class="brand__name">HazeGuard <em>AI</em></span>
      </a>
      <div class="auth__card card liquid-glass-strong">
        <span class="auth__icon" aria-hidden="true"><svg class="icon"><use href="#i-send"/></svg></span>
        <h1 class="auth__title">Verify your email</h1>
        <p class="auth__sub">We sent a 6-digit code to <strong id="otp-target">your inbox</strong>. Enter it below to activate your account.</p>

        <form id="form-email-otp" novalidate>
          <div class="field">
            <label for="email-otp-code">6-digit code</label>
            <div class="otp-boxes" role="group" aria-label="6-digit verification code">
              <input class="otp-box" type="text" inputmode="numeric" maxlength="1" autocomplete="one-time-code" aria-label="Digit 1 of 6">
              <input class="otp-box" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 2 of 6">
              <input class="otp-box" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 3 of 6">
              <input class="otp-box" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 4 of 6">
              <input class="otp-box" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 5 of 6">
              <input class="otp-box" type="text" inputmode="numeric" maxlength="1" aria-label="Digit 6 of 6">
            </div>
            <p class="field__hint">Codes expire after 5 minutes and work once.</p>
            <p class="field__error" data-error-for="email-otp-code" hidden></p>
          </div>
          <div class="row row--between otp-actions">
            <span class="otp-countdown" id="email-otp-countdown" aria-live="polite"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-clock"/></svg> Resend in <span data-otp-seconds>45</span>s</span>
            <button type="button" class="link" id="email-otp-resend" data-resend hidden>Resend code</button>
          </div>
          <button class="btn btn--primary btn--block" type="submit">Verify email</button>
        </form>

        <p class="auth__switch"><a class="link" href="#login" data-go="login">Back to sign in</a></p>
        <p class="auth__note"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-info"/></svg> Codes are generated server-side: 5-minute expiry, single use, rate limited.</p>
      </div>
    </div>
  </div>

  <div class="screen screen--auth" id="screen-verify-result" hidden>
    <div class="auth">
      <a class="brand brand--auth" href="#landing" data-go="landing" aria-label="Back to HazeGuard AI home">
        <svg class="brand__mark" viewBox="0 0 32 32" aria-hidden="true"><use href="#brand-mark"/></svg>
        <span class="brand__name">HazeGuard <em>AI</em></span>
      </a>
      <div class="auth__card card liquid-glass-strong">
        <span class="auth__icon" id="verify-icon" aria-hidden="true"><svg class="icon"><use href="#i-check"/></svg></span>
        <h1 class="auth__title" id="verify-title">Email verified</h1>
        <p class="auth__sub" id="verify-text">Your account is active. You can sign in and start checking the air around you.</p>
        <div class="auth__actions" id="verify-actions">
          <a class="btn btn--primary btn--block" href="#login" data-go="login">Continue to sign in</a>
        </div>
        <p class="auth__note"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-info"/></svg> This is the server's reply to the code you entered.</p>
      </div>
    </div>
  </div>

  <div class="screen screen--auth" id="screen-forgot" hidden>
    <div class="auth">
      <a class="brand brand--auth" href="#landing" data-go="landing" aria-label="Back to HazeGuard AI home">
        <svg class="brand__mark" viewBox="0 0 32 32" aria-hidden="true"><use href="#brand-mark"/></svg>
        <span class="brand__name">HazeGuard <em>AI</em></span>
      </a>
      <div class="auth__card card liquid-glass-strong">
        <h1 class="auth__title">Reset your password</h1>
        <p class="auth__sub">Enter the email you registered with and we’ll send a 6-digit code.</p>

        <form id="form-forgot" novalidate>
          <div class="field">
            <label for="forgot-email">Email address</label>
            <input class="input" type="email" id="forgot-email" autocomplete="email" placeholder="you@example.com" required>
            <p class="field__error" data-error-for="forgot-email" hidden></p>
          </div>
          <button class="btn btn--primary btn--block" type="submit">Send reset code</button>
        </form>

        <p class="auth__switch"><a class="link" href="#login" data-go="login">Back to sign in</a></p>
        <p class="auth__note"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-info"/></svg> This page replies the same way whether or not that email is registered, so no one can use it to check who has an account.</p>
      </div>
    </div>
  </div>

  <div class="screen screen--auth" id="screen-reset" hidden>
    <div class="auth">
      <a class="brand brand--auth" href="#landing" data-go="landing" aria-label="Back to HazeGuard AI home">
        <svg class="brand__mark" viewBox="0 0 32 32" aria-hidden="true"><use href="#brand-mark"/></svg>
        <span class="brand__name">HazeGuard <em>AI</em></span>
      </a>
      <div class="auth__card card liquid-glass-strong">
        <h1 class="auth__title">Choose a new password</h1>
        <p class="auth__sub">Enter the 6-digit code we sent to <strong id="reset-target">your inbox</strong>, then choose a password you don’t use anywhere else.</p>

        <form id="form-reset" novalidate>
          <div class="field">
            <label for="reset-email">Email address</label>
            <input class="input" type="email" id="reset-email" autocomplete="email" placeholder="you@example.com" required>
            <p class="field__error" data-error-for="reset-email" hidden></p>
          </div>
          <div class="field">
            <label for="reset-code">6-digit code</label>
            <input class="input input--otp" type="text" id="reset-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" required>
            <p class="field__error" data-error-for="reset-code" hidden></p>
          </div>
          <div class="row row--between otp-actions">
            <span class="otp-countdown" id="reset-countdown" aria-live="polite" hidden><svg class="icon icon--sm" aria-hidden="true"><use href="#i-clock"/></svg> Resend in <span data-otp-seconds>45</span>s</span>
            <button type="button" class="link" id="reset-resend" data-resend hidden>Resend code</button>
          </div>
          <div class="field">
            <label for="reset-password">New password</label>
            <div class="input-wrap">
              <input class="input" type="password" id="reset-password" autocomplete="new-password" placeholder="At least 8 characters" required>
              <button type="button" class="input-btn" data-toggle-password="reset-password" aria-label="Show password"><svg class="icon" aria-hidden="true"><use href="#i-eye"/></svg></button>
            </div>
            <p class="field__error" data-error-for="reset-password" hidden></p>
          </div>
          <div class="field">
            <label for="reset-confirm">Confirm new password</label>
            <div class="input-wrap">
              <input class="input" type="password" id="reset-confirm" autocomplete="new-password" placeholder="Repeat your new password" required>
              <button type="button" class="input-btn" data-toggle-password="reset-confirm" aria-label="Show password"><svg class="icon" aria-hidden="true"><use href="#i-eye"/></svg></button>
            </div>
            <p class="field__error" data-error-for="reset-confirm" hidden></p>
          </div>
          <button class="btn btn--primary btn--block" type="submit">Update password</button>
        </form>

        <p class="auth__switch"><a class="link" href="#login" data-go="login">Back to sign in</a></p>
        <p class="auth__note"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-info"/></svg> The code is hashed, works once and expires after 5 minutes. Changing your password won't sign you out on other devices.</p>
      </div>
    </div>
  </div>

  <!-- ============================ APP SHELL ============================ -->
  <div class="shell" id="shell" hidden>
    <!-- Desktop sidebar -->
    <aside class="sidenav liquid-glass-strong">
      <a class="brand brand--side" href="#landing" data-go="landing" aria-label="HazeGuard AI home">
        <svg class="brand__mark" viewBox="0 0 32 32" aria-hidden="true"><use href="#brand-mark"/></svg>
        <span class="brand__name">HazeGuard <em>AI</em></span>
      </a>
      <nav class="sidenav__nav" aria-label="Primary">
        <button type="button" class="sidenav__item is-active" data-nav="dashboard" aria-current="page"><svg class="icon" aria-hidden="true"><use href="#i-home"/></svg> Home</button>
        <button type="button" class="sidenav__item" data-nav="radar"><svg class="icon" aria-hidden="true"><use href="#i-radar"/></svg> Radar</button>
        <button type="button" class="sidenav__item" data-nav="planner"><svg class="icon" aria-hidden="true"><use href="#i-planner"/></svg> Planner</button>
        <button type="button" class="sidenav__item" data-nav="chat"><svg class="icon" aria-hidden="true"><use href="#i-chat"/></svg> AI Chat</button>
        <button type="button" class="sidenav__item" data-nav="profile"><svg class="icon" aria-hidden="true"><use href="#i-user"/></svg> Profile</button>
        <hr class="sidenav__rule">
        <button type="button" class="sidenav__item sidenav__item--sub" data-nav="history"><svg class="icon" aria-hidden="true"><use href="#i-chart"/></svg> Air quality history</button>
      </nav>
      <div class="sidenav__foot">
        <div class="sidenav__user">
          <span class="avatar" data-user-initials aria-hidden="true">—</span>
          <span class="sidenav__user-text"><strong data-user-name>—</strong><small data-user-email>—</small></span>
        </div>
        <button type="button" class="sidenav__item sidenav__item--sub" data-action="logout"><svg class="icon" aria-hidden="true"><use href="#i-logout"/></svg> Log out</button>
      </div>
    </aside>

    <div class="shell__main">
      <main class="content" id="app-content" tabindex="-1">

        <!-- ---------------- Dashboard ---------------- -->
        <section class="screen screen--app" id="screen-dashboard" hidden>
          <header class="page-head">
            <div>
              <h1 class="page-head__greeting"><span data-user-daypart>Welcome back</span>, <span data-user-name>—</span></h1>
              <p class="page-head__sub"><span data-today>Saturday, 11 October 2026</span> · <span data-aqi-place>—</span></p>
            </div>
            <div class="page-head__actions">
              <button type="button" class="icon-btn" data-nav="profile" aria-label="Open profile"><svg class="icon" aria-hidden="true"><use href="#i-user"/></svg></button>
              <button type="button" class="icon-btn" data-action="logout" aria-label="Log out"><svg class="icon" aria-hidden="true"><use href="#i-logout"/></svg></button>
            </div>
          </header>

          <!-- AQI summary -->
          <article class="card liquid-glass-strong aqi-hero">
            <div class="aqi-hero__top">
              <span class="aqi-hero__place"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-location-pin"/></svg> <span data-aqi-place>—</span> · current conditions</span>
              <span class="badge badge--loading" data-aqi-badge>Checking…</span>
            </div>
            <div class="aqi-hero__main">
              <span class="aqi-hero__value" data-aqi-value>—</span>
              <div class="aqi-hero__desc">
                <span class="ribbon ribbon--none" data-aqi-ribbon>Waiting</span>
                <p>US AQI. Sensitive groups may want to reduce prolonged outdoor exertion at peak hours.</p>
              </div>
            </div>
            <div class="aqi-band" role="img" aria-label="Air quality index not yet available">
              <div class="aqi-band__track"><span class="aqi-band__seg aqi-band__seg--1"></span><span class="aqi-band__seg aqi-band__seg--2"></span><span class="aqi-band__seg aqi-band__seg--3"></span><span class="aqi-band__seg aqi-band__seg--4"></span><span class="aqi-band__seg aqi-band__seg--5"></span></div>
              <span class="aqi-band__marker" data-aqi-marker style="--pos:0%; display:none"></span>
            </div>
            <div class="aqi-band__labels" aria-hidden="true"><span style="left:0%">0</span><span style="left:16.67%">50</span><span style="left:33.33%">100</span><span style="left:50%">150</span><span style="left:66.67%">200</span><span style="left:100%">300</span></div>
            <p class="src-line" data-aqi-src>Waiting for your location and the current reading.</p>
          </article>

          <!-- Pollutant cards. The values are the same server payload the hero
               above uses, so they start as "—" rather than as a plausible
               number: a stand-in here would read as today's air. -->
          <div class="stat-grid">
            <article class="card liquid-glass stat">
              <span class="stat__icon" aria-hidden="true"><svg class="icon"><use href="#i-particles"/></svg></span>
              <span class="stat__val"><span data-aqi-pm25>—</span> <small>µg/m³</small></span>
              <span class="stat__label">PM2.5 · fine particulates</span>
            </article>
            <article class="card liquid-glass stat">
              <span class="stat__icon" aria-hidden="true"><svg class="icon"><use href="#i-air"/></svg></span>
              <span class="stat__val"><span data-aqi-pm10>—</span> <small>µg/m³</small></span>
              <span class="stat__label">PM10 · coarse particulates</span>
            </article>
          </div>

          <!-- Quick actions -->
          <nav class="quick" aria-label="Quick actions">
            <button type="button" class="quick__item" data-nav="radar">
              <span class="quick__icon" aria-hidden="true"><svg class="icon"><use href="#i-radar"/></svg></span>
              <span class="quick__label">Haze Radar</span>
              <span class="quick__hint">Explore the map</span>
            </button>
            <button type="button" class="quick__item" data-nav="planner">
              <span class="quick__icon" aria-hidden="true"><svg class="icon"><use href="#i-planner"/></svg></span>
              <span class="quick__label">Plan an activity</span>
              <span class="quick__hint">AI verdict in seconds</span>
            </button>
            <button type="button" class="quick__item" data-nav="chat">
              <span class="quick__icon" aria-hidden="true"><svg class="icon"><use href="#i-chat"/></svg></span>
              <span class="quick__label">Ask BreatheBuddy</span>
              <span class="quick__hint">Air-quality Q&amp;A</span>
            </button>
          </nav>

          <div class="dash-grid">
            <!-- Radar preview. The pins below are still hardcoded demo values, so
                 this card keeps its Demo label even though the radar itself is
                 live: a preview that borrows the real screen's status would be
                 showing invented numbers with a live badge. -->
            <article class="card liquid-glass-strong preview">
              <div class="mini-map mini-map--sm" aria-hidden="true">
                <span class="mini-map__grid"></span>
                <span class="pin pin--mod" style="--x:30%;--y:30%"><i></i>72</span>
                <span class="pin pin--good" style="--x:66%;--y:52%"><i></i>55</span>
                <span class="pin pin--usg" style="--x:84%;--y:24%"><i></i>118</span>
                <span class="mini-map__me"></span>
              </div>
              <div class="preview__body">
                <h2 class="preview__title">Haze Radar</h2>
                <p>Compare readings around wherever you are. <span class="badge badge--demo">Demo preview</span></p>
                <button type="button" class="btn btn--ghost btn--sm" data-nav="radar">Open radar <svg class="icon icon--sm" aria-hidden="true"><use href="#i-arrow-right"/></svg></button>
              </div>
            </article>

            <!-- AI recommendation preview -->
            <article class="card liquid-glass-strong preview">
              <div class="preview__body">
                <h2 class="preview__title">Today’s AI recommendation</h2>
                <div class="mini-verdict">
                  <span class="ribbon ribbon--good">Evening run: good after 7 PM</span>
                  <span class="mini-verdict__text">Particulate levels typically ease after sunset; AQI held near 64 in the demo data. <span class="badge badge--demo">Demo</span></span>
                </div>
                <button type="button" class="btn btn--ghost btn--sm" data-nav="planner">Open planner <svg class="icon icon--sm" aria-hidden="true"><use href="#i-arrow-right"/></svg></button>
              </div>
            </article>

            <!-- History chart -->
            <article class="card liquid-glass-strong preview preview--chart">
              <div class="preview__head">
                <h2 class="preview__title">Last 24 hours</h2>
                <span class="badge badge--demo">Demo data</span>
              </div>
              <div class="chart" id="dash-chart" role="img" aria-label="Line chart of demo AQI values over the last 24 hours, ranging between 54 and 83"></div>
              <div class="preview__body preview__body--foot">
                <p class="src-line">Illustrative demo series · metric: US AQI · planned source: Open-Meteo</p>
                <button type="button" class="btn btn--ghost btn--sm" data-nav="history">Full history <svg class="icon icon--sm" aria-hidden="true"><use href="#i-arrow-right"/></svg></button>
              </div>
            </article>
          </div>
        </section>

        <!-- ---------------- Haze Radar ---------------- -->
        <section class="screen screen--app" id="screen-radar" hidden>
          <header class="page-head">
            <div>
              <h1 class="page-head__greeting">Haze Radar</h1>
              <p class="page-head__sub">Explore air quality around any location</p>
            </div>
          </header>

          <div class="radar-controls">
            <form class="search" id="radar-search-form" role="search">
              <label class="visually-hidden" for="radar-search">Search for a place</label>
              <svg class="icon search__icon" aria-hidden="true"><use href="#i-search"/></svg>
              <input class="input search__input" type="search" id="radar-search" placeholder="Search a place (try “Kuala Lumpur”)" autocomplete="off"
                     role="combobox" aria-expanded="false" aria-controls="radar-results" aria-autocomplete="list">
              <button class="btn btn--primary btn--sm" type="submit">Search</button>
            </form>
            <button type="button" class="btn btn--ghost" id="btn-locate"><svg class="icon" aria-hidden="true"><use href="#i-locate"/></svg> Use my location</button>
          </div>

          <!-- The place search, shared with the profile screen. Built in JS, so
               this list is deliberately empty in the markup. -->
          <ul class="place-list" id="radar-results" role="listbox" aria-label="Matching places" hidden></ul>
          <p class="place-status" id="radar-place-status" role="status" aria-live="polite"></p>

          <div class="map card" id="radar-map">
            <!-- Leaflet mounts here. It is created lazily on first visit, because
                 Leaflet measures its container and a hidden one has no size. -->
            <div class="map__canvas" id="radar-canvas" role="application" aria-label="Map of air quality readings"></div>

            <!-- loading state -->
            <div class="map__state liquid-glass-strong" id="radar-loading" hidden>
              <span class="spinner" aria-hidden="true"></span>
              <p>Reading the air around here…</p>
            </div>
            <!-- permission denied state -->
            <div class="map__state liquid-glass-strong" id="radar-denied" hidden>
              <span class="map__state-icon map__state-icon--warn" aria-hidden="true"><svg class="icon"><use href="#i-alert"/></svg></span>
              <h2>Location permission blocked</h2>
              <p id="radar-denied-text">Your browser denied access — that’s fine. Search for a place instead, or allow location in your site settings and retry.</p>
              <div class="map__state-actions">
                <button type="button" class="btn btn--primary btn--sm" data-radar-retry>Try again</button>
                <button type="button" class="btn btn--ghost btn--sm" data-radar-search-focus>Search for a place</button>
              </div>
            </div>
            <!-- data unavailable state -->
            <div class="map__state liquid-glass-strong" id="radar-unavailable" hidden>
              <span class="map__state-icon" aria-hidden="true"><svg class="icon"><use href="#i-info"/></svg></span>
              <h2>No readings for that location</h2>
              <p id="radar-unavailable-text">We have no coverage there yet. Missing data is shown as missing, never as zero.</p>
              <div class="map__state-actions">
                <button type="button" class="btn btn--primary btn--sm" data-radar-reset>Back to map</button>
              </div>
            </div>
          </div>

          <ul class="legend" aria-label="Air quality legend">
            <li><i class="dot dot--1"></i> Good <span>0–50</span></li>
            <li><i class="dot dot--2"></i> Moderate <span>51–100</span></li>
            <li><i class="dot dot--3"></i> Unhealthy for sensitive <span>101–150</span></li>
            <li><i class="dot dot--4"></i> Unhealthy <span>151–200</span></li>
            <li><i class="dot dot--5"></i> Very unhealthy <span>201+</span></li>
          </ul>

          <!-- The grid is coarser than these pins, and the screen says so rather
               than letting nineteen dots imply street-level truth. -->
          <p class="src-line" id="radar-provenance" role="status" aria-live="polite"></p>

          <article class="card liquid-glass location-panel" id="location-panel">
            <div class="location-panel__head">
              <h2 id="location-name"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-location-pin"/></svg> Kuala Lumpur</h2>
              <span class="badge" id="location-badge">Live</span>
            </div>
            <div class="location-panel__readings">
              <div><span class="location-panel__val" id="location-aqi">—</span><span class="location-panel__label">US AQI</span></div>
              <div><span class="location-panel__val" id="location-pm25">—</span><span class="location-panel__label">PM2.5 µg/m³</span></div>
              <div><span class="location-panel__val" id="location-pm10">—</span><span class="location-panel__label">PM10 µg/m³</span></div>
            </div>
            <p class="src-line" id="location-src">Loading the air where you are…</p>
          </article>
        </section>

        <!-- ---------------- Planner ---------------- -->
        <section class="screen screen--app" id="screen-planner" hidden>
          <header class="page-head">
            <div>
              <h1 class="page-head__greeting">AI Activity Planner</h1>
              <p class="page-head__sub">Tell us your plan and get a clear yes-or-no with the reasons</p>
            </div>
          </header>

          <form class="card liquid-glass form-card" id="form-planner" novalidate>
            <div class="form-grid">
              <div class="field">
                <label for="plan-activity">Activity</label>
                <select class="input" id="plan-activity" required>
                  <option value="Running">Running / jogging</option>
                  <option value="Cycling">Cycling</option>
                  <option value="Football">Outdoor football</option>
                  <option value="Hiking">Hiking</option>
                  <option value="Walking">Walking / commute</option>
                  <option value="Picnic">Picnic with family</option>
                </select>
              </div>
              <div class="field">
                <label for="plan-location">Location</label>
                <select class="input" id="plan-location" required>
                  <option>Kuala Lumpur</option>
                  <option>Petaling Jaya</option>
                  <option>Shah Alam</option>
                  <option>Putrajaya</option>
                  <option>Klang</option>
                </select>
              </div>
              <div class="field">
                <label for="plan-date">Date</label>
                <input class="input" type="date" id="plan-date" value="2026-10-11" required>
              </div>
              <div class="field">
                <label for="plan-time">Time</label>
                <input class="input" type="time" id="plan-time" value="18:00" required>
              </div>
            </div>
            <fieldset class="field">
              <legend>Preferences</legend>
              <div class="chips">
                <label class="chip"><input type="checkbox" name="pref" value="lower-aqi" checked><span>Prefer lower AQI windows</span></label>
                <label class="chip"><input type="checkbox" name="pref" value="shade"><span>Avoid direct sun</span></label>
                <label class="chip"><input type="checkbox" name="pref" value="nearby" checked><span>Stay within 5 km</span></label>
                <label class="chip"><input type="checkbox" name="pref" value="short"><span>Keep it under 60 min</span></label>
              </div>
            </fieldset>
            <button class="btn btn--primary btn--block" type="submit" id="btn-generate">Generate plan</button>
            <p class="form-card__note"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-info"/></svg> Demo generation: the real AI + live data pipeline arrives in Stages 4–5.</p>
          </form>

          <div class="plan-loading" id="plan-loading" hidden>
            <span class="spinner" aria-hidden="true"></span>
            <p>Checking air quality for your plan…</p>
          </div>

          <article class="card liquid-glass plan-result" id="plan-result" hidden>
            <div class="plan-result__head">
              <h2>Your plan</h2>
              <span class="badge badge--demo">Demo recommendation</span>
            </div>
            <span class="ribbon ribbon--good" id="plan-ribbon">Good window · AQI 64</span>
            <p class="plan-result__summary" id="plan-summary">A 6 PM run in Kuala Lumpur is reasonable in this demo scenario.</p>
            <h3 class="plan-result__sub">Why</h3>
            <p class="plan-result__why" id="plan-why">The illustrative AQI for your time and place sits at 64 (Moderate, US AQI), below the 100 threshold where sensitive groups should cut back. Particulate levels ease after sunset in the demo pattern. Data used: US AQI 64, PM2.5 34 µg/m³ · illustrative timestamp 11 Oct 2026, 10:40 +08:00 · planned source Open-Meteo (modelled estimate).</p>
            <h3 class="plan-result__sub">Indoor alternative</h3>
            <p class="plan-result__alt" id="plan-alt">If AQI climbs above 100, switch to a covered stadium session or a 45-minute indoor workout; the air-quality cost of staying out isn’t worth it.</p>
            <div class="plan-result__actions">
              <button type="button" class="btn btn--primary" id="btn-save-plan">Save plan</button>
              <button type="button" class="btn btn--ghost" id="btn-regenerate">Regenerate</button>
            </div>
            <p class="src-line">Forecast-free: this verdict uses the demo reading for the selected time, not a guaranteed future value. Future air quality is never promised.</p>
          </article>
        </section>

        <!-- ---------------- Chat ---------------- -->
        <section class="screen screen--app screen--chat" id="screen-chat" hidden>
          <header class="chat-head liquid-glass-strong">
            <span class="chat-head__avatar" aria-hidden="true"><svg class="icon"><use href="#i-sparkle"/></svg></span>
            <div class="chat-head__text">
              <h1>BreatheBuddy</h1>
              <p>Demo assistant — replies are sample messages, not a live model</p>
            </div>
            <span class="badge badge--demo">Demo</span>
          </header>

          <div class="chat-body" id="chat-body">
            <div class="chat-empty" id="chat-empty">
              <span class="chat-empty__icon" aria-hidden="true"><svg class="icon"><use href="#i-chat"/></svg></span>
              <h2>Ask me about the air</h2>
              <p>Try one of these, or type your own question.</p>
              <div class="chat-suggestions" id="chat-suggestions">
                <button type="button" class="chip chip--btn">Is the air okay for a run today?</button>
                <button type="button" class="chip chip--btn">What does AQI 72 mean?</button>
                <button type="button" class="chip chip--btn">Why is it hazier in the afternoon?</button>
                <button type="button" class="chip chip--btn">Which area has cleaner air right now?</button>
              </div>
            </div>
          </div>

          <form class="chat-compose" id="chat-form">
            <label class="visually-hidden" for="chat-input">Message BreatheBuddy</label>
            <input class="input chat-compose__input" type="text" id="chat-input" placeholder="Ask about the air…" autocomplete="off" enterkeyhint="send">
            <button class="chat-compose__send" type="submit" id="chat-send" aria-label="Send message" disabled>
              <svg class="icon" aria-hidden="true"><use href="#i-send"/></svg>
            </button>
          </form>
        </section>

        <!-- ---------------- History ---------------- -->
        <section class="screen screen--app" id="screen-history" hidden>
          <header class="page-head">
            <div>
              <button type="button" class="back-link" data-nav="dashboard"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-chevron-left"/></svg> Home</button>
              <h1 class="page-head__greeting">Air quality history</h1>
              <p class="page-head__sub">Trends for Kuala Lumpur</p>
            </div>
          </header>

          <div class="history-controls">
            <div class="seg" role="tablist" aria-label="Time period">
              <button type="button" class="seg__btn is-active" role="tab" aria-selected="true" data-period="24h">24 hours</button>
              <button type="button" class="seg__btn" role="tab" aria-selected="false" data-period="7d">7 days</button>
              <button type="button" class="seg__btn" role="tab" aria-selected="false" data-period="30d">30 days</button>
            </div>
            <div class="seg" role="tablist" aria-label="Metric">
              <button type="button" class="seg__btn is-active" role="tab" aria-selected="true" data-metric="aqi">AQI</button>
              <button type="button" class="seg__btn" role="tab" aria-selected="false" data-metric="pm25">PM2.5</button>
              <button type="button" class="seg__btn" role="tab" aria-selected="false" data-metric="pm10">PM10</button>
            </div>
          </div>

          <article class="card liquid-glass history-chart-card">
            <div class="preview__head">
              <h2 class="preview__title" id="history-title">US AQI · last 24 hours</h2>
              <span class="badge badge--demo">Demo data</span>
            </div>
            <div class="chart" id="history-chart" role="img" aria-label="Historical demo chart"></div>
            <div class="chart__empty" id="history-empty" hidden>
              <svg class="icon" aria-hidden="true"><use href="#i-info"/></svg>
              <p><strong>No records for this range.</strong> The prototype has no stored readings for 30-day PM10. Missing periods stay visible as missing and are never filled with zeros.</p>
            </div>
            <p class="src-line" id="history-src">Illustrative demo series · planned source: Open-Meteo Air Quality API (modelled estimate) · units: US AQI</p>
          </article>

          <div class="stat-grid stat-grid--4" id="history-summary">
            <article class="card liquid-glass stat stat--sm"><span class="stat__val" id="h-avg">68</span><span class="stat__label">Average</span></article>
            <article class="card liquid-glass stat stat--sm"><span class="stat__val" id="h-max">83</span><span class="stat__label">Peak</span></article>
            <article class="card liquid-glass stat stat--sm"><span class="stat__val" id="h-min">54</span><span class="stat__label">Lowest</span></article>
            <article class="card liquid-glass stat stat--sm"><span class="stat__val" id="h-good">18</span><span class="stat__label" id="h-good-label">Good hours</span></article>
          </div>

          <article class="card liquid-glass notice">
            <svg class="icon" aria-hidden="true"><use href="#i-info"/></svg>
            <div>
              <strong>Gaps are honest</strong>
              <p>When real data arrives, missing periods will render as breaks in the chart, not silent zeros.</p>
            </div>
          </article>
        </section>

        <!-- ---------------- Profile ---------------- -->
        <section class="screen screen--app" id="screen-profile" hidden>
          <header class="page-head">
            <div>
              <h1 class="page-head__greeting">Your profile</h1>
              <p class="page-head__sub">Everything here comes from your account</p>
            </div>
          </header>

          <article class="card liquid-glass profile-card">
            <span class="avatar avatar--lg" data-user-initials aria-hidden="true">—</span>
            <div class="profile-card__text">
              <h2 data-user-name>—</h2>
              <p>@<span data-user-handle>—</span> · <span data-user-email>—</span></p>
            </div>
            <span class="badge badge--live" data-user-badge hidden>Signed in</span>
          </article>

          <section class="card liquid-glass settings-group" aria-labelledby="set-activity">
            <h2 id="set-activity" class="settings-group__title">What you like to do</h2>
            <div class="chips" id="pref-activities">
              <label class="chip"><input type="checkbox" value="running"><span>Running</span></label>
              <label class="chip"><input type="checkbox" value="cycling"><span>Cycling</span></label>
              <label class="chip"><input type="checkbox" value="outdoor_football"><span>Outdoor football</span></label>
              <label class="chip"><input type="checkbox" value="evening_walks"><span>Evening walks</span></label>
            </div>
            <p class="settings-group__note">Tap to choose — we save it as you go.</p>
            <p class="field__error" data-error-for="pref-activities" hidden></p>
          </section>

          <section class="card liquid-glass settings-group" aria-labelledby="set-location">
            <h2 id="set-location" class="settings-group__title">Your area</h2>
            <div class="settings-row">
              <span class="settings-row__label">
                <svg class="icon icon--sm" aria-hidden="true"><use href="#i-location-pin"/></svg>
                <span class="settings-row__stack">
                  <span data-pref-location-name>Not set</span>
                  <span class="settings-row__coords" data-pref-location-coords hidden></span>
                </span>
              </span>
              <span class="settings-row__actions">
                <button type="button" class="btn btn--quiet btn--sm btn--icon"
                        data-action="relocate-location"
                        aria-label="Use my current location"
                        title="Use my current location">
                  <svg class="icon icon--sm" aria-hidden="true"><use href="#i-locate"/></svg>
                </button>
                <button type="button" class="btn btn--quiet btn--sm" data-action="search-location">Change</button>
              </span>
            </div>
            <p class="settings-group__note" data-pref-location-note>We ask your browser where you are, and only use it after you say yes. Tap the target to save that spot, or Change to look up another place.</p>
            <p class="field__error" data-error-for="pref-location" hidden></p>

            <div class="place-search" data-panel="place-search" hidden>
              <div class="field">
                <label for="place-query">Search for a city</label>
                <input class="input" type="search" id="place-query" autocomplete="off"
                       placeholder="Kuala Lumpur, Penang, Kota Kinabalu…"
                       role="combobox" aria-expanded="false" aria-controls="place-results" aria-autocomplete="list">
                <p class="field__error" data-error-for="place-query" hidden></p>
              </div>
              <ul class="place-list" id="place-results" role="listbox" aria-label="Matching places"></ul>
              <p class="settings-group__note" data-place-status></p>
              <button type="button" class="link" data-action="cancel-place-search">Cancel</button>
            </div>
          </section>

          <section class="card liquid-glass settings-group" aria-labelledby="set-notify">
            <h2 id="set-notify" class="settings-group__title">Notifications</h2>
            <label class="settings-row settings-row--toggle">
              <span class="settings-row__label">Daily AQI summary</span>
              <input type="checkbox" class="switch" disabled>
            </label>
            <label class="settings-row settings-row--toggle">
              <span class="settings-row__label">Haze alerts above AQI 100</span>
              <input type="checkbox" class="switch" disabled>
            </label>
            <label class="settings-row settings-row--toggle">
              <span class="settings-row__label">Weekly air-quality digest</span>
              <input type="checkbox" class="switch" disabled>
            </label>
            <p class="settings-group__note">Not ready yet. We don't send these today, so the switches are off for now.</p>
          </section>

          <section class="card liquid-glass settings-group" aria-labelledby="set-account">
            <h2 id="set-account" class="settings-group__title">Account</h2>
            <div class="settings-row">
              <span class="settings-row__label">Email verification</span>
              <span class="pill" data-user-verified hidden>—</span>
            </div>
            <div class="settings-row" data-panel="password" hidden>
              <form id="form-change-password" novalidate>
                <div class="field">
                  <label for="chg-current">Current password</label>
                  <input class="input" type="password" id="chg-current" autocomplete="current-password" required>
                  <p class="field__error" data-error-for="chg-current" hidden></p>
                </div>
                <div class="field">
                  <label for="chg-new">New password</label>
                  <input class="input" type="password" id="chg-new" autocomplete="new-password" placeholder="At least 8 characters" required>
                  <p class="field__error" data-error-for="chg-new" hidden></p>
                </div>
                <div class="field">
                  <label for="chg-confirm">Confirm new password</label>
                  <input class="input" type="password" id="chg-confirm" autocomplete="new-password" required>
                  <p class="field__error" data-error-for="chg-confirm" hidden></p>
                </div>
                <div class="row row--between">
                  <button type="submit" class="btn btn--primary btn--sm">Save password</button>
                  <button type="button" class="link" data-action="cancel-password">Cancel</button>
                </div>
              </form>
            </div>
            <div class="settings-row">
              <span class="settings-row__label">Change password</span>
              <button type="button" class="btn btn--quiet btn--sm" data-action="change-password">Update</button>
            </div>
            <div class="settings-row" data-panel="delete" hidden>
              <form id="form-delete-account" novalidate>
                <p class="settings-group__note">This permanently deletes your account and everything in it. You can't undo it.</p>
                <div class="field">
                  <label for="del-password">Confirm with your password</label>
                  <input class="input" type="password" id="del-password" autocomplete="current-password" required>
                  <p class="field__error" data-error-for="del-password" hidden></p>
                </div>
                <div class="row row--between">
                  <button type="submit" class="btn btn--danger btn--sm">Delete permanently</button>
                  <button type="button" class="link" data-action="cancel-delete">Cancel</button>
                </div>
              </form>
            </div>
            <div class="settings-row">
              <span class="settings-row__label">Delete account</span>
              <button type="button" class="btn btn--quiet btn--sm btn--danger" data-action="delete-account">Delete</button>
            </div>
          </section>

          <p class="form-card__note"><svg class="icon icon--sm" aria-hidden="true"><use href="#i-info"/></svg> Your name, email, activities and location are saved to your account, so they're here when you return. Notifications aren't saved yet.</p>

          <button type="button" class="btn btn--ghost btn--block" data-action="logout"><svg class="icon" aria-hidden="true"><use href="#i-logout"/></svg> Log out</button>
        </section>

      </main>

      <!-- Mobile bottom navigation (exactly five items) -->
      <nav class="tabbar liquid-glass-strong" aria-label="Primary">
        <button type="button" class="tabbar__item is-active" data-nav="dashboard" aria-current="page">
          <svg class="icon" aria-hidden="true"><use href="#i-home"/></svg><span>Home</span>
        </button>
        <button type="button" class="tabbar__item" data-nav="radar">
          <svg class="icon" aria-hidden="true"><use href="#i-radar"/></svg><span>Radar</span>
        </button>
        <button type="button" class="tabbar__item" data-nav="planner">
          <svg class="icon" aria-hidden="true"><use href="#i-planner"/></svg><span>Planner</span>
        </button>
        <button type="button" class="tabbar__item" data-nav="chat">
          <svg class="icon" aria-hidden="true"><use href="#i-chat"/></svg><span>AI Chat</span>
        </button>
        <button type="button" class="tabbar__item" data-nav="profile">
          <svg class="icon" aria-hidden="true"><use href="#i-user"/></svg><span>Profile</span>
        </button>
      </nav>
    </div>
  </div>

  <div class="toast-region" id="toast-region" aria-live="polite" aria-atomic="false"></div>

  <!-- Where the app is mounted: "" at the web root, "/haze2" in a subdirectory.
       The API client prefixes its paths with this so both mounts work. -->
  <script>window.HAZE_BASE = <?= json_encode($base) ?>;</script>
  <script src="<?= $base ?>/media/com_hazeguard/js/api.js"></script>
  <script src="<?= $base ?>/media/com_hazeguard/js/airquality.js"></script>
  <!-- app.js before radar.js on purpose: both are classic scripts sharing one
       global scope, and radar.js wires its search box to helpers app.js owns
       (runPlaceSearch, placeSearch), which are only defined once app.js has run. -->
  <script src="<?= $base ?>/media/com_hazeguard/js/app.js"></script>
  <script src="<?= $base ?>/media/com_hazeguard/js/landing.js"></script>
  <script src="<?= $base ?>/media/com_hazeguard/js/radar.js"></script>
</body>
</html>