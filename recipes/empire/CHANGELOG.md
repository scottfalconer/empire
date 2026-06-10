# Changelog — Empire

All notable changes to the Empire recipe are documented here.
This project adheres to [Semantic Versioning](https://semver.org/).

## 1.0.0 — 2026-06-09

Initial release.

- Turns a YouTube channel into a streaming-style video site: paste a channel and
  Empire provisions Feeds ingestion (channel Atom feed), Remote Video media, and
  editorial `empire_video` nodes, then applies the cinematic `empire_theme`.
- Ships the content model (`empire_video`, collection taxonomy), feed types,
  media types, view modes, listing views, the `content_editor` permission set,
  and the setup wizard + dashboard tooling.
- Requires `drupal/empire_tools`, `drupal/empire_theme`, `drupal/feeds`,
  `drupal/canvas`.
