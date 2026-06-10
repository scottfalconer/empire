# Publishing Empire to drupal.org (maintainer guide)

Empire ships as three packages — the recipe (`drupal/empire`), the module
(`drupal/empire_tools`), and the theme (`drupal/empire_theme`). Today they
install by copying the three directories into a Drupal CMS site and applying the
recipe by path (see the repo README and `setup.md`). Publishing the packages on
drupal.org is what makes the one-command flow work:

```bash
composer require drupal/empire
drush recipe empire
```

This is the maintainer checklist for getting there. No code changes are
required — the recipe's `composer.json` already pins the dependency versions; you
only need the published packages + tags, then a docs update.

## Current status

- **Not yet published.** `recipes/empire/composer.json` requires
  `drupal/empire_theme:^1.0` and `drupal/empire_tools:^1.0`, which only resolve
  once those packages exist on drupal.org's Composer endpoint
  (`https://packages.drupal.org/8`).
- Until then, install by copying the three directories (repo README → "Install").

## 1. Create the three drupal.org projects

This monorepo holds three packages, and drupal.org wants one project per package.
For each, create a project (drupal.org → **Add project**) with the matching type:

| Directory | Project (machine name) | Project type |
| --- | --- | --- |
| `recipes/empire` | `empire` | Recipe |
| `web/modules/custom/empire_tools` | `empire_tools` | Module |
| `web/themes/custom/empire_theme` | `empire_theme` | Theme |

Each project gets its own Git repo on `git.drupalcode.org`
(`git@git.drupal.org:project/<machine_name>.git`). Push the **contents** of each
directory to the root of its project repo (so e.g. `empire_tools.info.yml` sits
at the repo root, not under `web/modules/custom/`).

## 2. Tag compatible releases

drupal.org packages releases from Git tags, and the recipe's `^1.0` constraints
must be satisfiable:

1. Tag each repo with a SemVer release, e.g. **`1.0.0`** (modern `^11` contrib
   uses SemVer tags).
2. Keep the three in the same major: the recipe pins `empire_theme` and
   `empire_tools` to `^1.0`, so both must publish a `1.x` release before (or with)
   the recipe's release.
3. Create the **release node** on each project page from its tag. drupal.org's
   packaging exposes the tags on `packages.drupal.org/8` within a few minutes.
4. (Optional) Apply for **security advisory coverage** once each project has a
   stable release, if you want SA coverage + the shield.

## 3. Flip the install docs to the published flow

Once `composer require drupal/empire` resolves, update the repo README "Install"
section to lead with:

```bash
composer require drupal/empire
drush recipe empire
```

and keep the copy-the-directories flow as the from-source fallback.

## Notes

- Keep the dependency constraints in sync with the tested Drupal CMS version when
  you tag: `empire_tools` declares its runtime deps (`drupal/feeds`,
  `drupal/canvas`) in `empire_tools.info.yml`, and the recipe composes the
  baseline recipes (`drupal_cms_site_template_base`, `drupal_cms_content_type_base`,
  `drupal_cms_forms`) — see `recipes/empire/composer.json` and `recipe.yml`.
- If you would rather not split the monorepo by hand, a subtree-split tool (e.g.
  `git subtree split` or `splitsh-lite`) can mirror each directory into its
  project repo on every push.
