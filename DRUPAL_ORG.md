# Publishing Letterbox Palette on Drupal.org

GitHub is already the public source. Drupal.org still needs a **one-time project creation** with your Drupal.org account (this cannot be automated from GitHub alone).

## Prerequisites

1. Drupal.org account with [Git access agreement](https://www.drupal.org/user/login?destination=/user/me/git) accepted
2. SSH key or personal access token on [git.drupalcode.org](https://git.drupalcode.org/-/user_settings/ssh_keys)

## Create the project

1. Open https://www.drupal.org/node/add/project-module
2. Fill in:
   - **Project title:** Letterbox Palette
   - **Short name (machine name):** `letterbox_palette` (must match this folder)
   - **Project type:** Full project
   - **Maintenance status:** Actively maintained
   - **Development status:** Under active development (then Under active development → Maintenance fixes only once stable)
   - **Description:** paste the README summary
   - **Categories:** Content, Media / Images
3. Save the project page

## Push this repository to Drupal.org

On the new project page, open the **Version control** tab and follow the commands. Typical flow:

```bash
cd /path/to/letterbox_palette
git remote add drupal git@git.drupal.org:project/letterbox_palette.git
git checkout -b 1.0.x
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
composer require drupal/letterbox_palette
```

Until then, install from GitHub (see README).
