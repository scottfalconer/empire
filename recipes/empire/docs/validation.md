# Validation & release checklist

Run before each release. Commands assume DDEV (`ddev drush …`, `ddev exec …`).

## Install / config

```bash
ddev composer validate recipes/empire/composer.json --no-check-publish                   # valid
ddev composer validate web/modules/custom/empire_tools/composer.json --no-check-publish  # valid
ddev composer validate web/themes/custom/empire_theme/composer.json --no-check-publish   # valid
bash scripts/install-clean.sh                                            # green from zero
ddev drush recipe /var/www/html/recipes/empire                          # applies, no error
ddev drush cache:rebuild
ddev drush config:status                                                 # no surprise diff
```

The recipe config must round-trip **flat** (uuid/`_core` stripped, like `haven`).

## Code quality

```bash
ddev exec "vendor/bin/phpcs"                          # phpcs.xml.dist — Drupal+DrupalPractice, both packages
ddev exec "vendor/bin/phpstan analyse --no-progress"  # phpstan.neon.dist — phpstan-drupal + deprecation-rules, level 5
ddev exec "vendor/bin/phpunit -c web/core/phpunit.xml.dist web/modules/custom/empire_tools"  # unit + kernel
ddev exec "vendor/bin/phpunit -c phpunit-existing-site.xml.dist"  # ExistingSite editorial/render (built site)
```

All three clean / green.

## Feeds ingestion (against a real public channel)

- [ ] A real channel imports; `remote_video` media are created; `field_media_oembed_video` populated.
- [ ] oEmbed player/thumbnail render.
- [ ] Media import dedupes on re-run.
- [ ] `empire_video` nodes are created and reference the correct media.
- [ ] Node import dedupes on re-run.
- [ ] Videos that age out of the feed are kept.
- [ ] Editor-curated fields (title/body/artwork/collection/featured) are not overwritten.

## Empire Tools

- [ ] Setup accepts `@handle`, channel URL, `UC…` ID, and feed URL.
- [ ] Bad input returns a plain-language error.
- [ ] Resolved channel creates/updates the `empire_channel` term.
- [ ] Feed instances are created; media feed runs first, node feed second.
- [ ] Dashboard shows status; **Refresh now** works.
- [ ] Happy path never requires the Feeds UI or `/admin/structure/*`.

## Rendering

- [ ] Homepage renders: hero, Latest row, collection rails, CTAs.
- [ ] Video detail page renders a playable embed.
- [ ] Collection pages render; `/videos` grid renders.
- [ ] Artwork fallback: custom artwork → oEmbed thumbnail → placeholder.
- [ ] Mobile layout works; no autoplay.

## Accessibility

- [ ] Cards and rails are keyboard reachable; rails scroll with the keyboard.
- [ ] Visible focus states; no hover-only actions.
- [ ] Artwork has alt handling; collections/rows use semantic headings.
- [ ] AA color contrast; no autoplaying video.

## Empty / first-run states

- [ ] Before setup: dashboard shows "Connect your YouTube channel… [Set up Empire]".
- [ ] Empty `/videos` and empty rails read well.
- [ ] Resolver/no-results failures show plain-language copy.
