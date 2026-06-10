# Architecture

> Feeds does the ingestion. Drupal does the content/rendering. Empire Tools makes
> it feel like a product.

## Why Feeds (not a custom importer)

The two-feed pipeline was proven empirically and does everything v1 needs:
fetch the YouTube Atom feed, create + dedupe entities, keep aged-out items, and
never overwrite edits. A custom importer would be more code to maintain and
secure for no benefit. `feeds_ex` and a YouTube API-key mode are explicitly out
of v1 scope.

## Why two feed types

Separating the playback asset from the editorial page keeps each concern simple
and dedupe reliable:

1. **`empire_youtube_media`** → `remote_video` media. Maps the watch URL into
   `field_media_oembed_video` (marked unique → dedupe). `update_non_existent: _keep`
   so media that ages out of the feed is retained.
2. **`empire_youtube_videos`** → `empire_video` nodes. Attaches the matching media
   via `reference_by: field_media_oembed_video`, unique key `field_source_guid`.
   `update_existing: 0` so **editor-curated fields are never overwritten**;
   `update_non_existent: _keep` so videos are never deleted/unpublished.

`empire_tools` runs the media feed first and the node feed second, so nodes always
find their media.

## Why `empire_video` nodes

```
remote_video media = playback asset / oEmbed wrapper
empire_video node  = the editable public page (title, body, artwork, collection, SEO)
```

Editors work on nodes. Imported videos auto-publish (not moderated). The
display-artwork fallback — **custom artwork → oEmbed thumbnail → placeholder** —
lives once, in `empire_theme`'s node preprocess, so it is consistent
across cards, rails, and the hero.

## Why `empire_tools`

The no-custom-module path would force users into channel IDs/feed URLs and the
Feeds UI, which breaks the zero-Drupal-knowledge promise. `empire_tools` owns
onboarding only:

- **`ChannelInputResolver`** — the only networked code. Resolves @handle / URL /
  ID / feed URL → channel ID + feed URL. Allow-lists YouTube hosts, https-only +
  allowlist-checked redirects, short timeouts, never hard-crashes. Heavily unit-tested.
- **`FeedInstanceManager`** — creates the `empire_channel` term + the two feed
  instances; stores the term↔feed association in state.
- **`EmpireImportOrchestrator`** — media feed then node feed; stamps `field_channel`
  provenance (only-if-empty); records last import; reports counts/errors.
- **`EmpireSetupStatus` + Dashboard/Setup/Refresh** — the `/admin/empire` UX.
- **`HomepageBuilder`** — composes the Canvas homepage after the recipe applies
  (the baseline ships an empty Home page and core content import skips existing).

## Rendering stack

```
empire_video node → view mode (card / hero / full) → SDC component (empire_theme)
→ Views block → Canvas page (homepage)
```

Public listings query `empire_video` nodes, never raw media. The homepage is a
Canvas page composed of Views blocks + SDC — no hardcoded loops, controllers, or
static markup.

## Multi-channel posture

V1's UI is single-channel, but the data model is multi-channel-ready: one
`empire_channel` term per channel, two feed instances per channel, the same two
feed types for every channel, and `field_channel` on every `empire_video`.
