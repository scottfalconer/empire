# Publishing Empire to drupal.org (maintainer guide)

Empire ships as three packages — the recipe (`drupal/empire`), the module
(`drupal/empire_tools`), and the theme (`drupal/empire_theme`). Today they
install by copying the three directories into a Drupal CMS site and applying the
recipe by path (see the repo README and `setup.md`). Publishing the packages on
drupal.org is what makes the normal one-command flow work:

```bash
composer require drupal/empire
drush recipe empire
```

No code changes are required — each `composer.json` is already correct and the
recipe pins the dependency versions; you only need the published projects + tags,
then a docs update.

## Current status

- **Module + theme published at 1.0.0.** `drupal/empire_tools` and
  `drupal/empire_theme` are live on drupal.org, so the recipe's `^1.0`
  constraints now resolve from the Composer endpoint
  (`https://packages.drupal.org/8`). Steps 1–2 below are done.
- **The recipe project is the last to publish** (step 3). Until its release node
  exists, install from source (repo README → "From source").

## drupal.org project types

drupal.org has **no dedicated "Recipe" project type** — recipes are published as
**General projects** (the project-creation docs list "generic PHP libraries
including Drupal recipes" under *General project*). The drupal.org project type is
only the listing category; the `type` in each `composer.json` is unchanged and is
what actually makes Composer install each package correctly:

| Directory | Project (machine name) | drupal.org project type | composer `type` |
| --- | --- | --- | --- |
| `web/modules/custom/empire_tools` | `empire_tools` | **Module** | `drupal-module` |
| `web/themes/custom/empire_theme` | `empire_theme` | **Theme** | `drupal-theme` |
| `recipes/empire` | `empire` | **General project** | `drupal-recipe` |

## Order of operations

Publish the dependencies (module + theme) **first** so the recipe's `^1.0`
constraints resolve when you publish it.

For each project: drupal.org → **Add project** (`drupal.org/project/add`) → choose
the type → set the machine name → **Save**, then follow the project's **Version
control** tab to push the code. Push the **contents** of each directory to the
repo root (so e.g. `empire_tools.info.yml` is at the repo root, not under
`web/modules/custom/`). drupal.org adds the `version:`/`project:`/`datestamp:`
`.info.yml` keys automatically when it packages a release — do not commit those.
(A committed `LICENSE.txt` is fine; the packaged release includes it either way.)

### 1. `empire_tools` — Module project
1. Add project → **Module** → machine name `empire_tools` → Save.
2. Push the contents of `web/modules/custom/empire_tools/`.
3. Tag **`1.0.0`** and create the release node from the tag.

### 2. `empire_theme` — Theme project
1. Add project → **Theme** → machine name `empire_theme` → Save.
2. Push the contents of `web/themes/custom/empire_theme/`.
3. Tag **`1.0.0`** and create the release node.

### 3. `empire` — General project (the recipe)
1. Add project → **General project** → machine name `empire` → Save.
2. Push the contents of `recipes/empire/` **excluding the monorepo-only files**
   (`AGENTS.md`, `docs/ai/`, and `docs/validation.md` — an internal CI checklist
   whose commands reference monorepo-root paths), which are not shipped recipe.
3. Tag **`1.0.0`** and create the release node. The recipe's `composer.json`
   pins `drupal/empire_theme:^1.0` + `drupal/empire_tools:^1.0`, so their `1.0.0`
   releases (steps 1–2) satisfy it.

### 4. Verify from a completely fresh Drupal CMS project
```bash
composer require drupal/empire
drush recipe empire
drush cr
```
Then confirm onboarding at `/admin/empire/setup` works end to end.

### 5. Flip the install docs
Update the repo README "Install" to **lead** with the Composer flow above, and
move the current copy-the-directories flow into a "Source / development install"
fallback for contributors. Optionally update the `homepage` field in each
`composer.json` from the GitHub URL to the drupal.org project page.

## Notes

- **Branch/tag convention:** create a SemVer dev branch (e.g. `1.0.x`) and tag
  releases `1.0.0`, `1.0.1`, … The Version control tab shows the exact commands.
- (Optional) Apply for **security advisory coverage** once each project has a
  stable release, for the SA shield + coverage.
- Keep the dependency constraints in sync with the tested Drupal CMS version when
  you tag (`empire_tools` declares `drupal/feeds` + `drupal/canvas`; the recipe
  composes the `drupal_cms_*` baseline recipes).
- A subtree-split tool (`git subtree split` / `splitsh-lite`) can automate
  mirroring each directory into its project repo on future pushes.
