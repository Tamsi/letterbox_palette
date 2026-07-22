/**
 * @file
 * Live letterbox preview for palette selection.
 */
(function (Drupal, once) {
  'use strict';

  function toHex(value) {
    if (!value) {
      return '#192127';
    }
    value = String(value).trim();
    if (value.charAt(0) !== '#') {
      value = '#' + value;
    }
    return value.toLowerCase();
  }

  function applyPreview(root) {
    var preview = root.querySelector('[data-letterbox-palette-preview]');
    if (!preview) {
      return;
    }

    var colorInput = root.querySelector('input[name="letterbox_palette[color]"]:checked');
    var gradientInput = root.querySelector('input[name="letterbox_palette[gradient]"]');

    var color = toHex(colorInput ? colorInput.value : preview.getAttribute('data-edge-center'));
    var gradient = gradientInput ? gradientInput.checked : false;
    var left = toHex(preview.getAttribute('data-edge-left') || color);
    var right = toHex(preview.getAttribute('data-edge-right') || color);

    if (gradient) {
      preview.style.backgroundImage = 'linear-gradient(to right, '
        + left + ' 0%, '
        + color + ' 28%, '
        + color + ' 36%, '
        + color + ' 64%, '
        + color + ' 72%, '
        + right + ' 100%)';
      preview.style.backgroundColor = '';
    }
    else {
      preview.style.backgroundImage = 'none';
      preview.style.backgroundColor = color;
    }
  }

  /**
   * Finds palette roots even when AJAX passes the wrapper itself as context.
   */
  function findPaletteRoots(context) {
    if (context instanceof Element && context.id === 'letterbox-palette-wrapper') {
      return once('letterbox-palette', context);
    }
    return once('letterbox-palette', '#letterbox-palette-wrapper', context);
  }

  Drupal.behaviors.letterboxPalette = {
    attach: function (context) {
      findPaletteRoots(context).forEach(function (root) {
        applyPreview(root);
        root.addEventListener('change', function () {
          applyPreview(root);
        });
      });
    }
  };
})(Drupal, once);
