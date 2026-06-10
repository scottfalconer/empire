# Empire — a Drupal CMS site template for video creators

[![CI](https://github.com/scottfalconer/empire/actions/workflows/ci.yml/badge.svg)](https://github.com/scottfalconer/empire/actions/workflows/ci.yml)

Turn a YouTube channel into a cinematic, Netflix-style streaming site. Paste a
channel and Empire imports the recent videos, builds a cinematic homepage,
organises collections, and gives you editable video pages — **no YouTube API key
or login required**.

This repository bundles the three Empire packages:

| Path | What it is |
| --- | --- |
| `recipes/empire` | The Drupal **recipe** — content model, Views, Canvas pages, config, and default content. Composes the Drupal CMS baseline rather than reinventing it. |
| `web/modules/custom/empire_tools` | Onboarding wizard, channel resolver, Feeds import orchestration (incl. local maxres thumbnail upgrade), dashboard, and SEO (Open Graph / Twitter / JSON-LD `VideoObject`). |
| `web/themes/custom/empire_theme` | The cinematic dark theme — Single-Directory Components (Canvas-compatible), view modes, and design tokens. |

## Requirements

- **Drupal CMS 2.x** (Drupal core `^11.3`)
- The baseline recipes Empire composes: `drupal_cms_site_template_base`,
  `drupal_cms_content_type_base`, `drupal_cms_forms`
- `drupal/feeds`, `drupal/canvas`

## Install

Starting from a Drupal CMS site:

1. Copy the packages into your project (the layout here mirrors a Drupal project):
   - `recipes/empire` → `recipes/empire`
   - `web/modules/custom/empire_tools` → `web/modules/custom/empire_tools`
   - `web/themes/custom/empire_theme` → `web/themes/custom/empire_theme`
2. Apply the recipe:
   ```
   drush recipe recipes/empire
   ```
3. Onboard your channel at **`/admin/empire/setup`** — paste a handle (with or
   without the `@`), a channel URL, or a channel ID, and hit **Build my site**.

> The custom module/theme aren't on Packagist yet, so install by copying the
> directories (above) rather than `composer require`. The recipe's `composer.json`
> documents the intended package dependencies for when they are published. See
> [`recipes/empire/docs/PUBLISHING.md`](recipes/empire/docs/PUBLISHING.md) for the
> drupal.org publishing plan.

## What you get

- A cinematic **homepage** (a Canvas page you can recompose), plus `/videos`,
  `/collections`, watch pages, and a **`/featured` campaign landing page** — both
  Canvas-editable, composed from the theme's components.
- **Editorial control:** pick the hero, organise collections, and override the
  artwork — all from the video edit form; the import never clobbers your changes.
- **Privacy-first:** YouTube embeds are consent-gated (Klaro) and thumbnails are
  stored locally (no third-party hotlinking).
- Built to **WCAG AA**, with rich SEO (meta description, Open Graph, Twitter
  cards, JSON-LD `VideoObject`) out of the box.

## Continuous integration

CI runs `composer validate` and PHPCS (Drupal + DrupalPractice coding standards)
on every push and pull request. The heavier gates — PHPStan, PHPUnit, a
clean-slate recipe apply, and the feeds / rendering / accessibility review — need
a full Drupal CMS site with the recipe's contrib dependencies, and stay the
manual release checklist in [`recipes/empire/docs/validation.md`](recipes/empire/docs/validation.md).

## License

GPL-2.0-or-later. See [`LICENSE`](LICENSE).
