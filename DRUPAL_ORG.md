# Publishing Letterbox Palette on Drupal.org

GitHub is the working source for this repository. Publishing on Drupal.org still
needs a **one-time project creation** with your Drupal.org account (this cannot
be automated from GitHub alone).

## Prerequisites

1. Drupal.org account with the
   [Git access agreement](https://www.drupal.org/user/login?destination=/user/me/git)
   accepted
2. SSH key on
   [git.drupalcode.org SSH keys](https://git.drupalcode.org/-/user_settings/ssh_keys)

## Create the project (required once)

The machine name `letterbox_palette` is **not reserved yet** on Drupal.org.

1. Open https://www.drupal.org/node/add/project-module
2. Fill in:
   - **Project title:** Letterbox Palette
   - **Short name (machine name):** `letterbox_palette` (must match this folder)
   - **Project type:** Full project
   - **Maintenance status:** Actively maintained
   - **Development status:** Under active development
   - **Description:** paste the README summary
   - **Categories:** Content, Media / Images
3. Save the project page

## Push this repository to Drupal.org

On the new project page, open the **Version control** tab and follow the
commands. Typical flow:

```bash
cd /path/to/letterbox_palette
git remote add drupal git@git.drupal.org:project/letterbox_palette.git
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

## Cloud Agent note

If a Cursor Cloud Agent should push for you, add **that agent environment's**
SSH public key on git.drupalcode.org (your laptop key is not available inside
the agent VM). Prefer connecting to `git@git.drupal.org` (origin) rather than
the Fastly CDN hostname when SSH handshakes reset.

## Composer

After the first Drupal.org release exists:

```bash
composer require drupal/letterbox_palette
```

Until then, install from GitHub (see README).
