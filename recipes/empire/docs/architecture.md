# Architecture

> Feeds does the ingestion. Drupal does the content/rendering. Empire Tools makes
> it feel like a product.

## Site-on-Site composition

Empire is itself a `type: Site` recipe, and it composes the `type: Site` baseline
`drupal_cms_site_template_base` (plus `drupal_cms_content_type_base` and
`drupal_cms_forms`). Composing a Site recipe inside another Site recipe is a
deliberate deviation from the usual "apply the baseline first, then a content
recipe" flow: it makes Empire self-contained, so a single `drush recipe empire`
(or a marketplace `composer require drupal/empire`) installs the whole site —
admin UI, authentication, media, privacy/consent, SEO, HTML email — without the
operator needing to know to apply the baseline first.

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
feed types for every channel, and `field_channel` on every `empire_video`. When
more than one channel term exists, the dashboard, Refresh, and the public
Subscribe link follow the most-recently-connected channel.

## Roles and permissions

The recipe grants the standard `content_editor` role the Empire-specific
permissions — *access Empire dashboard*, *configure Empire channel*, and
*refresh Empire imports* — plus create/edit/delete for `empire_video` content.
This is a deliberate trust decision: an editor can connect a channel and refresh
imports without needing full administrator access.

`configure Empire channel` and `refresh Empire imports` are operational — they
make outbound HTTP requests to YouTube and create content in bulk — so both are
flagged `restrict access: true` (Drupal shows a security warning for them on the
permissions page). The channel lookup is SSRF-hardened: a YouTube-only host
allow-list, a request timeout, a redirect cap, and a response-size cap (see
`ChannelInputResolver`).

To tighten this for a multi-author or untrusted-editor site, revoke *configure
Empire channel* and *refresh Empire imports* from `content_editor` and grant
them to a dedicated site-manager/admin role instead; the curate permissions
(create/edit/delete `empire_video`) can stay with editors.

### Content authorship (known limitation)

Imported videos and the demo pages (About/Privacy/Terms) are authored by the
install-time superuser (`uid 1`) — the conventional owner for a fresh recipe
apply, where no other user exists yet. This is cosmetic: it has no access-control
or data consequence. For distinct authorship on a multi-author site, create an
editor user, reassign the existing content, and set `owner_feed_author: true` on
the two `feeds.feed_type.empire_youtube_*` configs so future imports are owned by
the feed's owner rather than `uid 1`.
