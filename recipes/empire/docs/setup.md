# Setup & troubleshooting

## Requirements

- **Drupal CMS 2.x** (Drupal core ^11.x) on a standard LAMP/LEMP or DDEV stack.
- The baseline recipes (`drupal_cms_site_template_base`,
  `drupal_cms_content_type_base`, `drupal_cms_forms`) and the `feeds` module —
  all pulled in automatically when the Empire recipe is applied.
- A working **cron** so Empire keeps importing new uploads from the channel feed.
- Outbound HTTP so the importer can reach the public YouTube Atom feed + oEmbed
  (no API key or OAuth required).

## Clean install from zero

Empire composes the `drupal_cms_site_template_base` baseline itself, so applying
the Empire recipe to a minimal site builds everything:

```bash
ddev drush site:install minimal -y
ddev drush recipe /var/www/html/recipes/empire   # composes the baseline, then builds Empire
ddev drush cache:rebuild
```

Applying `recipes/empire`:

1. composes the `drupal_cms_site_template_base` baseline (admin UI, auth, media
   incl. `remote_video`, privacy/consent, SEO, email), `drupal_cms_content_type_base`
   (shared content fields + the `content_editor` role), and `drupal_cms_forms`
   (contact form + anti-spam + webform tooling),
2. installs `feeds`, `empire_theme`, `empire_tools`,
3. creates the content model, taxonomies, Views, and feed types,
4. registers the SDC components as Canvas components,
5. sets `empire_theme` as the default theme,
6. grants the Empire permissions to `content_editor`,
7. composes the homepage (via the `empire_tools` recipe-applied subscriber).

## Connect a channel

1. Visit **`/admin/empire/setup`**.
2. Paste any of: `@handle`, `https://youtube.com/@handle`, a channel URL,
   a `UC…` channel ID, or a `feeds/videos.xml?channel_id=…` URL.
3. **Build my site.** Empire resolves the channel ID, builds the feed URL,
   creates an `empire_channel` term, provisions the two feeds, imports media
   first then video nodes, and stamps provenance.
4. Manage from **`/admin/empire`**: connected channel, counts, last import,
   **Refresh now**, **View site**.

## What Empire imports

**Why only about 15 videos?** Empire imports from the channel's **public YouTube
Atom feed**, which exposes only the channel's **~15 most recent uploads** — by
design. This keeps Empire **key-free**: no YouTube Data API key, no OAuth, and no
quota to manage. So a first import shows around 15 videos — that is expected
behaviour, not a bug — and Empire keeps watching the feed, importing **new**
uploads automatically from then on. A full back-catalogue import would require the
YouTube Data API, which v1 deliberately avoids to stay low-friction and key-free.

YouTube's Atom feed also does not expose video descriptions, so imported video
bodies start empty — editors can write them (curated text is preserved on
re-import).

## Curating videos

Open any **Video** (`empire_video`) to curate it. Your edits are preserved on
re-import — the importer never overwrites curated fields.

### Artwork override

By default a video shows YouTube's oEmbed thumbnail. To use your own image,
upload it to the video's **Artwork** (`field_artwork`) field and save. Empire
then uses your artwork **everywhere the video appears** — the homepage hero, the
collection rails, the videos grid, and the video detail page — in place of the
YouTube thumbnail. Remove the image to fall back to the YouTube thumbnail again.

### Featured & collections

- Toggle **Featured** to promote a video into the homepage hero.
- Assign **Collections** (Tutorials, Behind the scenes, …) to place a video in
  the matching homepage rail and on its collection page.

### Availability & status

Each video has a **Status** (`field_source_status`) with three values:

- **Active** (default) — the video shows normally everywhere.
- **Hidden** — the video drops out of every listing (homepage rails, videos
  grid, collection pages). Use it to quietly retire a video without deleting it.
- **Unavailable** — the video's page replaces the player with its artwork and a
  clear notice ("This video is currently unavailable. It may have been removed
  or made private on YouTube."). Set this when a video has been removed, made
  private, age-restricted, or embedding-blocked on YouTube, so visitors never
  hit a broken embed.

## Privacy & consent

Empire is privacy-first: it loads no third-party content until the visitor agrees.

- **No YouTube before consent.** Video players and the optional hero autoplay are
  gated by Klaro consent — nothing loads from YouTube until the visitor accepts.
  Until then the video page shows a "Load external content" placeholder and the
  hero shows a still poster.
- **Local thumbnails.** Video artwork is downloaded and served from your own site,
  so the homepage, rails, and grids render without contacting YouTube.
- **Withdraw any time.** A **Privacy settings** link in the footer reopens the
  consent manager, where visitors can decline or revoke consent for YouTube.
- **Newsletter signups** are stored in your Drupal database (Webform) and require
  an explicit consent checkbox; review them at
  `/admin/structure/webform/manage/empire_newsletter/results/submissions`.

## Troubleshooting

| Symptom | Cause / fix |
| --- | --- |
| Only about 15 videos imported | Expected — the public YouTube Atom feed exposes only the channel's ~15 most recent uploads (no API key needed). New uploads import automatically going forward. See "Why only about 15 videos?" above. |
| "We couldn't find that YouTube channel." | The handle/URL didn't resolve. Paste the full channel URL or the `UC…` ID. |
| "YouTube did not return any recent public videos." | The channel has no recent public uploads, or YouTube is rate-limiting. Try **Refresh** shortly. |
| Import created media but few nodes | Some videos may be private, removed, age-restricted, or blocked from embedding; their nodes are skipped. |
| Homepage rails are empty | Imported videos are uncategorized by default. Add videos to collections (Featured, Tutorials, …) and the rails appear. |
| Re-import didn't pick up my edits being kept | Expected — the node feed never overwrites editor-curated fields (`update_existing: 0`). |

## Re-import / refresh

`/admin/empire/refresh` (or `drush feeds:import <fid>`) re-runs the import. It
dedupes media and nodes, keeps videos that aged out of the feed, and never
overwrites your edits.

## Uninstall

Recipes apply configuration and content; Drupal has no one-click "un-apply". To
remove Empire from a site:

1. Switch the default theme away from **Empire** (Appearance).
2. Uninstall the **Empire Tools** (`empire_tools`) module.
3. Delete the imported **Video** content and the **Channel** / **Collection**
   taxonomy terms, and remove the two Empire feeds.

The shared baseline modules (Media, Webform, consent, SEO, …) are standard Drupal
CMS and can be kept or uninstalled individually.
