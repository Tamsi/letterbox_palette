# Publishing Letterbox Palette on Drupal.org

Project page: https://www.drupal.org/project/letterboxbg

| Concern | Value |
|---|---|
| Drupal.org short name | `letterboxbg` |
| Composer package | `drupal/letterboxbg` |
| Git remote | `git@git.drupal.org:project/letterboxbg.git` |
| Module machine name (code) | `letterbox_palette` |

## Push this repository to Drupal.org

```bash
cd /path/to/letterbox_palette
git remote add drupal git@git.drupal.org:project/letterboxbg.git
git checkout 1.0.x
git push -u drupal 1.0.x
```

Create a release tag when ready:

```bash
git tag 1.0.0
git push drupal 1.0.x
git push drupal 1.0.0
```

Then on Drupal.org: **Add new release** → select the `1.0.0` tag → publish.

## Composer

After the first Drupal.org release exists:

```bash
composer require drupal/letterboxbg
drush en letterbox_palette -y
```

Until then, install from GitHub (see README).

## Cloud Agent note

If a Cursor Cloud Agent should push for you, add **that agent environment's**
SSH public key on git.drupalcode.org (your laptop key is not available inside
the agent VM). Prefer connecting to `git@git.drupal.org` (origin) rather than
the Fastly CDN hostname when SSH handshakes reset.
