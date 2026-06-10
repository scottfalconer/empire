# Changelog — Empire Tools

This project adheres to [Semantic Versioning](https://semver.org/).

## 1.0.0 — 2026-06-10

Initial release.

- Channel input resolver: `@handle` / channel URL / `UC…` ID / feed URL → channel
  details (incl. the channel's display name). SSRF-hardened — YouTube host
  allowlist, reconstructed fetch URLs, HTTPS-only and allowlist-checked
  redirects, connect/read timeouts, graceful failure.
- Feed provisioning (a media feed + a node feed per channel) and import
  orchestration: media-first ordering, dedupe, and preservation of editor edits
  and aged-out videos on re-import. Import failures are detected from the feed's
  reported state — not just thrown exceptions — so a failed refresh never reports
  a clean import or advances the "last imported" stamp.
- Setup wizard, import-status service, admin dashboard, and Canvas homepage
  composition. A clean first import redirects to the live site (`<front>`) with a
  "Your site is ready" message; partial / no-video / error outcomes land on the
  dashboard and warn before celebrating.
