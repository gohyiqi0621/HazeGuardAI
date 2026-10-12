# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Stack

User-mandated (brief §3), not delegated: static HTML5/CSS3/vanilla JS frontend (no React/Vite/Tailwind), plain PHP 8 backend (no framework), Python Flask microservice for AI integration only, MySQL + phpMyAdmin, Leaflet + OpenStreetMap, Open-Meteo Air Quality API, Chart.js, backend-proxied LLM API, Linux VPS deployment (Nginx + PHP-FPM + HTTPS).

## Users

Primary: people in Malaysia checking local haze/air-quality conditions before outdoor activities, on a phone, in the moment. *(Inferred from the brief's APIMS/Malaysia haze context and mobile-first mandate; the brief never names a country for end users beyond the APIMS reference.)*

Secondary: hackathon judges evaluating the demo flow (brief §19).

## Product Purpose

Make local air-quality information understandable and actionable: check conditions near you, explore them on a map, plan outdoor activities with AI help, and ask air-quality questions. Success = a user answers "is the air okay for what I want to do today?" in seconds, with an honest view of where the data came from.

## Positioning

One free, mobile-first flow that ties air-quality data → map → activity planning → an AI assistant that cites real tool output instead of inventing readings, with model estimates clearly separated from official station measurements.

## Operating Context

- Mobile-first usage; desktop gets a sidebar layout.
- Data sources: Open-Meteo Air Quality API (modelled estimates) initially; Malaysia DoE APIMS station data only if programmatic access is verified.
- Free tiers preferred everywhere (map tiles, AI API, hosting).
- Deployed on a Linux VPS behind Nginx with HTTPS; phpMyAdmin for DB admin.
- Hackathon demo path (brief §19) must work with a labelled fallback when external services fail.

## Capabilities and Constraints

- Stage 1A (current): frontend prototype only — HTML/CSS/vanilla JS, demo data explicitly labelled, no backend calls.
- Later: registration/login (email+password, username+password, email OTP), sessions/CSRF, password reset, Haze Radar with Leaflet, BreatheBuddy AI chat with backend tool calls, AI activity planner with MySQL-persisted plans, history charts, optional alerts.
- All AI calls go through the Python AI service (proxied by PHP); API keys never reach frontend JS or PHP.
- No React/Vite/Tailwind; no hardcoded production secrets; demo data must never be presented as live data.
- Open decision: AI provider (to be chosen in Stage 4 with documented pricing/free-tier limits).
- Open decision: whether APIMS exposes usable programmatic access (verify in Stage 3 before promising official station data).

## Brand Commitments

- Name: HazeGuard AI. Tagline: "Understand the air. Plan smarter. Breathe better."
- Voice: calm, clear, environmentally literate, honest about data limits.
- No `<YQ/>`-style placeholder signatures; no copying any particular company's branding.
- Simulated readings are always visibly labelled as demo data.

## Evidence on Hand

No real data, assets, or user research exist yet (empty repo at Session 1). Any air-quality readings in Stage 1A are synthetic and must be labelled. Commercial claims, pricing, and live-data claims must not be invented.

## Product Principles

1. Honesty over polish: label demo data, distinguish estimates from measurements, admit missing/stale readings.
2. The answer comes first: air quality for the user's place and plans, above decoration.
3. Mobile one-hand usability; desktop adds room, not a different product.
4. Free-tier-first services so a student team can run it.
5. Code a student team can read: plain HTML/CSS/JS, small PHP endpoints, one small Python AI service.

## Accessibility & Inclusion

Visible focus indicators, ≥4.5:1 body contrast, large enough touch targets (≥44px), reduced-motion support, accessible names on navigation, keyboard-operable forms and chat. *(Standard implied by brief §8.2/8.14.)*
