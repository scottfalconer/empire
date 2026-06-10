# Empire

**Paste your YouTube channel. Empire builds your video site.**

Empire is a Drupal CMS site template that turns a YouTube channel into a
streaming-style video site — a cinematic homepage, browsable collections, and
editable video pages — without the site owner needing to understand Drupal.

## What you get

- A **hero billboard**, **Latest** row, and **collection rails** on the homepage.
- A **video page** for every imported video, with the YouTube player embedded via
  consent-aware oEmbed.
- **Editorial control**: change titles, write descriptions, upload custom artwork
  (which overrides the YouTube thumbnail), organize videos into collections, and
  feature videos — none of which is overwritten when new videos import.
- **Audience capture**: contact form and newsletter signup.

## The three packages

| Package | Type | Owns |
| --- | --- | --- |
| `drupal/empire` | `drupal-recipe` | Content model, Feeds feed types, Views, default content, roles, docs |
| `drupal/empire_theme` | theme | SDC components, view modes, design tokens, Canvas registration |
| `drupal/empire_tools` | module | Onboarding + homepage layout: channel resolver, feed provisioning, import orchestration, dashboard, Canvas homepage composition (`HomepageBuilder`) |

> **Feeds does the ingestion. Drupal does the content/rendering. Empire Tools makes it feel like a product.**

## Install

Empire **composes** the `drupal_cms_site_template_base` baseline, so applying the
recipe on a minimal site builds everything. Under DDEV, `drush` runs from the
docroot, so pass the absolute in-container path to the recipe:

```bash
ddev drush recipe /var/www/html/recipes/empire
ddev drush cache:rebuild
```

This applies the baseline, installs the theme + tools, builds the content model
and Views, and composes the homepage. (See `docs/setup.md` for the from-zero
clean install.)

Empire isn't on drupal.org yet, so it installs from source — copy the three
directories into your site and apply the recipe by path (above). Once the
packages are published, `composer require drupal/empire` + `drush recipe empire`
will work with no code change; see [`docs/PUBLISHING.md`](docs/PUBLISHING.md) for
the maintainer publishing plan.

## Use it

1. Go to **`/admin/empire/setup`**.
2. Paste your channel — an `@handle`, a channel URL, a `UC…` ID, or a feed URL.
3. Click **Build my site**. Empire resolves the channel, provisions the feeds,
   imports recent videos, and you are done.
4. Manage everything from the **`/admin/empire`** dashboard.

## Documentation

- [`docs/setup.md`](docs/setup.md) — setup, importing, curating videos, privacy & consent, troubleshooting
- [`docs/architecture.md`](docs/architecture.md) — how and why it is built this way
