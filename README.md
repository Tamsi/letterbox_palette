# Letterbox Palette

Drupal 10 / 11 module that extracts dominant colors from a **portrait** image and
lets editors pick a letterbox background color (solid or edge gradient) with a
live preview on the entity form.

Ideal for hero / poster layouts where a vertical image sits on a wide desktop
canvas and needs matching side colors.

## Features

- Pure PHP **GD** extraction (no Composer color library required)
- Ranked swatch palette + left/right edge colors for gradients
- Live letterbox preview (AJAX-safe on image upload / remove)
- Configurable per content type via admin UI
- Stores the selected color as `#rrggbb` in a plain text field

## Requirements

- Drupal `^10 || ^11`
- PHP `>= 8.1` with the **GD** extension
- On each mapped bundle:
  - an **Image** field (source)
  - a **Text (plain)** field for the hex color (max length ≥ 7)
  - optionally a **Boolean** field for the gradient toggle

## Installation

### From Drupal.org

```bash
composer require drupal/letterbox_palette
drush en letterbox_palette -y
drush cr
```

### From GitHub (development)

```bash
composer config repositories.letterbox_palette vcs https://github.com/Tamsi/letterbox_palette.git
composer require drupal/letterbox_palette:dev-1.0.x
drush en letterbox_palette -y
drush cr
```

## Configuration

1. Create the color (and optional gradient) fields on your content type.
2. Go to **Configuration → Content authoring → Letterbox Palette**
   (`/admin/config/content/letterbox-palette`).
3. Add a mapping:
   - Content type
   - Image field machine name
   - Color field machine name
   - Optional gradient field + Field Group name
4. Edit a node: upload a **portrait** image → pick a swatch → save.

Front-end themes can read the stored hex / boolean fields and apply
`background-color` or a CSS gradient around `object-fit: contain` heroes.

## API / reuse

The extractor service is available as `letterbox_palette.extractor`:

```php
$palette = \Drupal::service('letterbox_palette.extractor')
  ->extractPortraitPalette($file);
// ['colors' => ['#aabbcc', ...], 'edges' => ['left' => ..., 'center' => ..., 'right' => ...]]
```

## Supporting this module

Bug reports and feature requests: the Drupal.org issue queue once the project
is published, or the GitHub repository until then.

## Origin

Extracted and generalized from a production France Télévisions (& Vous) festival
editorial workflow.

## Maintainers

- [Tamsi](https://www.drupal.org/u/tamsi) / [GitHub](https://github.com/Tamsi)

## License

GPL-2.0-or-later
