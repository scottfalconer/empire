# Changelog — Empire (theme)

This project adheres to [Semantic Versioning](https://semver.org/).

## 1.1.0 — 2026-06-09

Design refresh (Claude Design handoff).

- Accent-suffused theming: coral accent (`#ff3b5c`) with a `color-mix` tint
  system (`--glow` / `--glow-soft` / `--accent-hi`), a suffused page backdrop,
  button accent-glow, collection-tile rings, and an accent-suffused newsletter/
  contact band. Accent text stays WCAG AA (5.65:1 on `--bg`).
- Signature accent ticks before page/row titles and a rule after the hero eyebrow.
- New **Hero motion** theme setting (Appearance → Settings → Empire): the hero is
  a still poster by default (privacy-first, no pre-consent embed); optionally a
  muted, looping autoplay preview (an owner-enabled §19/§24 divergence — see the
  setting description). Implemented outside the hero SDC schema, so the recipe's
  Canvas registration and the clean-install gate are unaffected.

## 1.0.0 — 2026-06-09

Initial release.

- Streaming-style cinematic dark theme built on SDC components (video card, hero,
  collection rails, category tiles), view modes, and design tokens.
- Renders `empire_video` nodes as cards, collection rails, and a featured hero;
  styles the listing/collection pages, setup forms, and editorial surfaces.
- Self-hosted fonts (Manrope + Bricolage Grotesque), AA-accessible on the dark
  palette, Lighthouse performance 100.
