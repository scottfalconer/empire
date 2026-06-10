# Empire Tools

Onboarding and setup orchestration for the **Empire** video site template:
channel resolution, feed provisioning, import orchestration, the admin
dashboard, and homepage composition.

Empire Tools is the engine behind the
[Empire recipe](https://github.com/scottfalconer/empire) — normally you apply the
recipe, which enables this module and the companion `empire_theme`.

## Requirements

- Drupal `^11`
- [Feeds](https://www.drupal.org/project/feeds) `^3`
- [Canvas](https://www.drupal.org/project/canvas) `^1.3.2`
- Core: Node, Media, Taxonomy

## Installation

Empire Tools is the engine behind the **[`drupal/empire`](https://www.drupal.org/project/empire)**
recipe. It expects the structure that recipe (plus `empire_theme`) provides — the
`empire_video` content type, the `empire_channel` vocabulary, the two
`empire_youtube_*` feed types, and the theme's SDC components — so the supported
install is the recipe as a whole:

```
composer require drupal/empire
drush recipe empire
```

Enabling this module on its own (`drush en empire_tools`) is only meaningful as
part of that recipe: on a site without the Empire structure the dashboard renders
zeros and setup/refresh have nothing to provision.

## Usage

1. Go to **Empire → Set up** (`/admin/empire/setup`) and paste a YouTube channel
   — a handle, a channel URL, a channel ID, or the channel feed URL.
2. Empire resolves the channel, provisions a media importer and a node importer,
   and runs the first import. Videos arrive as Remote Video media and editorial
   *Video* (`empire_video`) nodes.
3. Use the dashboard (`/admin/empire`) to check status, and **Refresh**
   (`/admin/empire/refresh`) to pull new videos.

### Curating

Edit a *Video* to retitle it, mark it **featured** (it becomes the hero), or
assign **collections**. Upload an image to the **Artwork** field to override the
YouTube thumbnail everywhere the video appears. Editor changes survive a
re-import.

Set a video's **Status** field to *Hidden* to drop it from all listings, or to
*Unavailable* to replace its player with a clear "currently unavailable" notice
instead of a broken embed.

## How it works

| Service | Responsibility |
| --- | --- |
| `ChannelInputResolver` | Resolve channel input → channel ID + feed URL. The only networked code: YouTube host allowlist, HTTPS-only + allowlist-checked redirects, timeouts, graceful failure. |
| `FeedInstanceManager` | Find/create the per-channel Feeds importers. |
| `EmpireImportOrchestrator` | Run the import media-first, dedupe, preserve editor edits + aged-out videos. |
| `EmpireSetupStatus` | Report setup/import status for the dashboard. |
| `HomepageBuilder` | Compose the homepage. |

## Permissions

*Access the Empire dashboard* · *Configure the Empire channel* · *Refresh Empire
imports* — granted to **Content editor** by the recipe.

## License

GPL-2.0-or-later.
