# Empire (theme)

Streaming-style cinematic dark theme for the **Empire** video site template.
Single-Directory Components (SDC), view modes, and design tokens — renders
`empire_video` nodes as cards, collection rails, and a featured hero.

Normally installed by the [Empire recipe](https://www.drupal.org/project/empire).

## Requirements

- Drupal `^11.3` (the theme reads its hero-motion setting via the
  `ThemeSettingsProvider` service, introduced in 11.3)

## Features

- Cinematic dark palette with an accent-suffused coral accent (the channel's
  colour washes the UI via `color-mix` glows/tints); self-hosted fonts (Manrope +
  Bricolage Grotesque). Designed toward WCAG AA and a Lighthouse performance
  target of 100 — verified during development; re-run Lighthouse/axe to confirm
  on your own build and content.
- **Hero motion** (theme setting → Appearance → Settings → Empire): the featured
  hero shows a muted, looping autoplay preview by default — but it is
  **consent-gated**: it stays a still poster until the visitor consents to
  YouTube via Klaro, and never loads external content before consent (§19). Set
  the option to **poster** to disable the preview entirely.
- The chrome (nav + footer) lives in `page.html.twig`; the theme renders
  `page.content` only — no block regions (a single `content` region).
- Styled setup forms, listing/collection pages, and editorial surfaces.
- **Artwork override:** an uploaded `field_artwork` image replaces the YouTube
  oEmbed thumbnail wherever the video is shown.

## Structure

| Path | Contents |
| --- | --- |
| `components/` | SDC: badge, button, card, grid, heading, hero-billboard, image, section, share-bar, slider, video-meta, video-stage |
| `css/` | Design tokens + component styles (loaded via the `empire_theme/global` library) |
| `templates/` | `page.html.twig` + node / views template overrides |
| `js/` | `nav.js` (mobile nav) + `hero-preview.js` (consent-gated hero preview) |
| `fonts/` | Self-hosted Manrope + Bricolage Grotesque |
| `empire_theme.theme` | Preprocessing — artwork override and page titles |

## License

GPL-2.0-or-later.
