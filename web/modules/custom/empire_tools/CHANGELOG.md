# Changelog — Empire Tools

This project adheres to [Semantic Versioning](https://semver.org/).

## 1.1.0 — 2026-06-09

Onboarding refresh (Claude Design handoff) — synchronous, no new subsystem.

- Warm, zero-jargon setup welcome ("Welcome to Empire / Let's build your video
  site") with a reassurance line and a 3-step "what happens next".
- A clean first import now redirects straight to the live site (`<front>`) with a
  "Your site is ready" message (the ta-da); partial / no-video / error outcomes
  still land on the dashboard and warn before celebrating.
- A coral brand accent on the dashboard "site is live" banner.

## 1.0.0 — 2026-06-09

Initial release.

- Channel input resolver: `@handle` / channel URL / `UC…` ID / feed URL → channel
  details. SSRF-hardened — YouTube host allowlist, reconstructed fetch URLs,
  HTTPS-only and allowlist-checked redirects, connect/read timeouts, graceful
  failure.
- Feed provisioning (a media feed + a node feed per channel) and import
  orchestration: media-first ordering, dedupe, and preservation of editor edits
  and aged-out videos on re-import.
- Setup wizard, import-status service, admin dashboard, and homepage composition.
