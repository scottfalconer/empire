# Changelog — Empire (theme)

This project adheres to [Semantic Versioning](https://semver.org/).

## 1.0.0 — 2026-06-10

Initial release.

- Streaming-style cinematic dark theme built on SDC components (video card, hero,
  collection rails, category tiles), view modes, and design tokens.
- Renders `empire_video` nodes as cards, collection rails, and a featured hero;
  styles the listing/collection pages, setup forms, and editorial surfaces.
- Accent-suffused theming: a coral accent with a `color-mix` tint system, a
  suffused page backdrop, button accent-glow, and collection-tile rings. Accent
  text is designed toward WCAG AA.
- **Hero motion** theme setting (Appearance → Settings → Empire): the hero shows
  a muted, looping autoplay preview by default — consent-gated, so it stays a
  still poster until the visitor consents to YouTube via Klaro and never loads
  external content before consent (§19); set it to **poster** to disable.
- Self-hosted fonts (Manrope + Bricolage Grotesque); designed toward WCAG AA on
  the dark palette and a Lighthouse performance target of 100 (re-run to confirm).
