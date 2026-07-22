<?php

declare(strict_types=1);

namespace Drupal\letterbox_palette;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\file\Element\ManagedFile;
use Drupal\file\FileInterface;
use Drupal\letterbox_palette\Service\DominantColorExtractor;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Builds the letterbox palette UI on configured entity forms.
 */
class PaletteFormAlter implements ContainerInjectionInterface {

  use StringTranslationTrait;

  public function __construct(
    protected DominantColorExtractor $extractor,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('letterbox_palette.extractor'),
      $container->get('entity_type.manager'),
      $container->get('file_url_generator'),
      $container->get('config.factory'),
    );
  }

  /**
   * Alters node forms that match a configured mapping.
   */
  public function alterForm(array &$form, FormStateInterface $form_state, string $form_id): void {
    $mapping = $this->resolveMapping($form_state);
    if ($mapping === NULL) {
      return;
    }

    $image_field = $mapping['image_field'];
    $color_field = $mapping['color_field'];
    $gradient_field = $mapping['gradient_field'];

    if (!isset($form[$image_field])) {
      return;
    }

    $form['#attached']['library'][] = 'letterbox_palette/palette_form';
    $form['#letterbox_palette_mapping'] = $mapping;
    $form['#validate'][] = [static::class, 'mapPaletteValues'];
    $form['#entity_builders'][] = [static::class, 'buildEntity'];
    if (isset($form['actions']['submit']['#submit'])) {
      array_unshift($form['actions']['submit']['#submit'], [static::class, 'mapPaletteValues']);
    }

    if (isset($form[$color_field])) {
      $form[$color_field]['#access'] = FALSE;
    }
    if ($gradient_field !== '' && isset($form[$gradient_field])) {
      $form[$gradient_field]['#access'] = FALSE;
    }

    $file = $this->resolveImageFile($form, $form_state, $image_field);
    $this->resetPaletteSelectionIfFileChanged($form_state, $file);
    $palette = ($file instanceof FileInterface) ? $this->extractor->extractPortraitPalette($file) : NULL;

    $form['letterbox_palette'] = $this->buildPaletteElement($form_state, $file, $palette, $mapping);
    $form['letterbox_palette']['#weight'] = ($form[$image_field]['#weight'] ?? 0) + 0.1;
    if (!empty($mapping['form_group'])) {
      $form['letterbox_palette']['#group'] = $mapping['form_group'];
    }

    if (isset($form[$image_field]['widget'][0])) {
      $form[$image_field]['widget'][0]['#process'][] = [
        static::class,
        'processImageWidget',
      ];
      $form[$image_field]['widget'][0]['#after_build'][] = [
        static::class,
        'imageWidgetAfterBuild',
      ];
    }
  }

  /**
   * Process callback: wire upload/remove AJAX to also refresh palette.
   */
  public static function processImageWidget(array &$element, FormStateInterface $form_state, array &$complete_form): array {
    static::attachPaletteAjax($element);
    return $element;
  }

  /**
   * After-build: ensure upload/remove AJAX still refreshes palette.
   */
  public static function imageWidgetAfterBuild(array $element, FormStateInterface $form_state): array {
    static::attachPaletteAjax($element);
    return $element;
  }

  /**
   * Overrides managed-file AJAX callbacks so the palette panel is replaced too.
   */
  protected static function attachPaletteAjax(array &$element): void {
    foreach (['upload_button', 'remove_button'] as $button) {
      if (!isset($element[$button]['#ajax']) || !is_array($element[$button]['#ajax'])) {
        continue;
      }
      $element[$button]['#ajax']['callback'] = [
        static::class,
        'imageAjax',
      ];
    }
  }

  /**
   * AJAX callback: refresh image widget + palette panel.
   */
  public static function imageAjax(array &$form, FormStateInterface $form_state): AjaxResponse {
    $palette = static::extractProcessedPaletteElement($form);
    if (is_array($palette)) {
      unset($palette['#group']);
    }

    $response = ManagedFile::uploadAjaxCallback($form, $form_state, \Drupal::request());
    if (is_array($palette)) {
      $response->addCommand(new ReplaceCommand('#letterbox-palette-wrapper', $palette));
    }
    return $response;
  }

  /**
   * Returns the processed letterbox_palette element from the rebuilt form.
   */
  protected static function extractProcessedPaletteElement(array $form): ?array {
    if (isset($form['letterbox_palette']) && is_array($form['letterbox_palette'])) {
      return $form['letterbox_palette'];
    }
    foreach ($form as $key => $child) {
      if (!is_array($child) || !str_starts_with((string) $key, 'group_')) {
        continue;
      }
      if (isset($child['letterbox_palette']) && is_array($child['letterbox_palette'])) {
        return $child['letterbox_palette'];
      }
    }
    return NULL;
  }

  /**
   * Clears a previous palette choice when the source image changes.
   */
  protected function resetPaletteSelectionIfFileChanged(FormStateInterface $form_state, ?FileInterface $file): void {
    $fid = $file instanceof FileInterface ? (int) $file->id() : 0;
    $previous = $form_state->get('letterbox_palette_fid');
    if ($previous === $fid) {
      return;
    }
    $form_state->set('letterbox_palette_fid', $fid);
    if ($previous !== NULL) {
      $form_state->unsetValue('letterbox_palette');
      $input = $form_state->getUserInput();
      unset($input['letterbox_palette']);
      $form_state->setUserInput($input);
    }
  }

  /**
   * Copies custom palette values into the real fields before entity save.
   */
  public static function mapPaletteValues(array &$form, FormStateInterface $form_state): void {
    $mapping = $form['#letterbox_palette_mapping'] ?? NULL;
    if (!is_array($mapping)) {
      return;
    }

    $hex = static::normalizeColorValue($form_state->getValue(['letterbox_palette', 'color']));
    $gradient = (bool) $form_state->getValue(['letterbox_palette', 'gradient']);
    $color_field = $mapping['color_field'];
    $gradient_field = $mapping['gradient_field'] ?? '';

    if ($hex !== NULL) {
      $form_state->setValue([$color_field, 0, 'value'], $hex);
    }
    else {
      $form_state->setValue($color_field, []);
    }

    if ($gradient_field !== '') {
      $form_state->setValue([$gradient_field, 0, 'value'], $gradient ? 1 : 0);
    }
  }

  /**
   * Entity builder: persist palette fields even when widgets are inaccessible.
   */
  public static function buildEntity(string $entity_type, NodeInterface $entity, array &$form, FormStateInterface $form_state): void {
    $mapping = $form['#letterbox_palette_mapping'] ?? NULL;
    if (!is_array($mapping) || $entity->bundle() !== $mapping['bundle']) {
      return;
    }

    $hex = static::normalizeColorValue($form_state->getValue(['letterbox_palette', 'color']));
    $gradient = (bool) $form_state->getValue(['letterbox_palette', 'gradient']);

    $color_field = $mapping['color_field'];
    if ($entity->hasField($color_field)) {
      $entity->set($color_field, $hex);
    }

    $gradient_field = $mapping['gradient_field'] ?? '';
    if ($gradient_field !== '' && $entity->hasField($gradient_field)) {
      $entity->set($gradient_field, $gradient ? 1 : 0);
    }
  }

  /**
   * Normalizes a radio value (with or without leading #) to #rrggbb.
   */
  protected static function normalizeColorValue(mixed $color): ?string {
    if (!is_string($color) || $color === '') {
      return NULL;
    }
    $color = strtolower(ltrim(trim($color), '#'));
    if (!preg_match('/^[0-9a-f]{6}$/', $color)) {
      return NULL;
    }
    return '#' . $color;
  }

  /**
   * Builds the palette fieldset (swatches + preview + gradient).
   */
  protected function buildPaletteElement(FormStateInterface $form_state, ?FileInterface $file, ?array $palette, array $mapping): array {
    $settings = $this->configFactory->get('letterbox_palette.settings');
    $aspect = (string) ($settings->get('preview_aspect_ratio') ?: '16 / 7');

    $element = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'letterbox-palette-wrapper',
        'class' => ['letterbox-palette'],
        'style' => '--letterbox-preview-aspect: ' . $aspect . ';',
      ],
      '#tree' => TRUE,
    ];

    if ($file instanceof FileInterface && $this->extractor->isLandscape($file)) {
      $element['empty'] = [
        '#type' => 'item',
        '#markup' => '<p class="letterbox-palette__hint">' . $this->t('Landscape image: the letterbox palette is only proposed for portrait images.') . '</p>',
      ];
      return $element;
    }

    if ($palette === NULL || $file === NULL) {
      $element['empty'] = [
        '#type' => 'item',
        '#markup' => '<p class="letterbox-palette__hint">' . $this->t('Upload a portrait image to extract a letterbox color palette.') . '</p>',
      ];
      return $element;
    }

    $color_field = $mapping['color_field'];
    $gradient_field = $mapping['gradient_field'] ?? '';

    $default_color = $form_state->getValue(['letterbox_palette', 'color']);
    if (!is_string($default_color) || $default_color === '') {
      $default_color = $form_state->getValue([$color_field, 0, 'value']);
    }
    if (!is_string($default_color) || $default_color === '') {
      $entity = $this->getMappedNode($form_state, $mapping);
      if ($entity && $entity->hasField($color_field) && !$entity->get($color_field)->isEmpty()) {
        $default_color = (string) $entity->get($color_field)->value;
      }
    }

    // Keys must NOT start with "#" — Drupal treats those as render properties.
    $color_keys = [];
    foreach ($palette['colors'] as $hex) {
      $key = ltrim(strtolower((string) $hex), '#');
      if (preg_match('/^[0-9a-f]{6}$/', $key)) {
        $color_keys[$key] = '#' . $key;
      }
    }

    $default_key = is_string($default_color) ? ltrim(strtolower($default_color), '#') : '';
    if ($default_key === '' || !isset($color_keys[$default_key])) {
      $default_key = (string) array_key_first($color_keys);
    }
    $default_color = $color_keys[$default_key] ?? ($palette['edges']['center'] ?? '#192127');

    $default_gradient = $form_state->getValue(['letterbox_palette', 'gradient']);
    if ($default_gradient === NULL && $gradient_field !== '') {
      $entity = $this->getMappedNode($form_state, $mapping);
      $default_gradient = $entity
        && $entity->hasField($gradient_field)
        && (bool) $entity->get($gradient_field)->value;
    }

    $edges = $palette['edges'];
    $image_url = $this->buildPreviewImageUrl($file);

    $element['title'] = [
      '#type' => 'item',
      '#title' => $this->t('Letterbox background palette'),
      '#description' => $this->t('Dominant colors extracted from the portrait image. Pick the background used around the hero image.'),
    ];

    $element['preview'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['letterbox-palette-preview'],
        'data-letterbox-palette-preview' => '1',
        'data-edge-left' => $edges['left'],
        'data-edge-center' => $edges['center'],
        'data-edge-right' => $edges['right'],
        'style' => $this->previewStyle($default_color, (bool) $default_gradient, $edges),
      ],
      'image' => [
        '#type' => 'html_tag',
        '#tag' => 'img',
        '#attributes' => [
          'src' => $image_url,
          'alt' => (string) $this->t('Image preview'),
          'class' => ['letterbox-palette-preview__image'],
          'loading' => 'lazy',
        ],
      ],
      'caption' => [
        '#markup' => '<p class="letterbox-palette-preview__caption">' . $this->t('Preview (desktop letterbox)') . '</p>',
      ],
    ];

    $element['color'] = [
      '#type' => 'radios',
      '#title' => $this->t('Background color'),
      '#options' => $color_keys,
      '#default_value' => $default_key,
      '#required' => TRUE,
      '#prefix' => '<div class="letterbox-palette__swatches-wrap">',
      '#suffix' => '</div>',
      '#attributes' => [
        'class' => ['letterbox-palette__swatches'],
      ],
      '#after_build' => [[static::class, 'decorateSwatchRadios']],
    ];

    if ($gradient_field !== '') {
      $element['gradient'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Use gradient background'),
        '#description' => $this->t('When enabled, the letterbox fades from the image edge colors through the selected color.'),
        '#default_value' => (bool) $default_gradient,
        '#attributes' => [
          'data-letterbox-palette-gradient' => '1',
        ],
      ];
    }

    return $element;
  }

  /**
   * Adds a colored chip beside each palette radio label.
   */
  public static function decorateSwatchRadios(array $element, FormStateInterface $form_state): array {
    foreach (Element::children($element) as $key) {
      if (!preg_match('/^[0-9a-f]{6}$/', (string) $key)) {
        continue;
      }
      $hex = '#' . $key;
      $element[$key]['#title'] = new FormattableMarkup(
        '<span class="letterbox-palette__swatch-chip" style="background:@color" title="@color"></span>',
        ['@color' => $hex]
      );
      $element[$key]['#wrapper_attributes']['class'][] = 'letterbox-palette__swatch-item';
      $element[$key]['#attributes']['data-swatch'] = $hex;
    }
    return $element;
  }

  /**
   * Builds a browser-usable URL for the preview image.
   */
  protected function buildPreviewImageUrl(FileInterface $file): string {
    $style_id = (string) $this->configFactory->get('letterbox_palette.settings')->get('preview_image_style');
    if ($style_id !== '') {
      $style = $this->entityTypeManager->getStorage('image_style')->load($style_id);
      if ($style) {
        return $style->buildUrl($file->getFileUri());
      }
    }
    return $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
  }

  /**
   * Builds inline CSS for the letterbox preview box.
   */
  protected function previewStyle(string $color, bool $gradient, array $edges): string {
    if ($gradient) {
      $bg = sprintf(
        'linear-gradient(to right, %s 0%%, %s 28%%, %s 36%%, %s 64%%, %s 72%%, %s 100%%)',
        $edges['left'],
        $color,
        $color,
        $color,
        $color,
        $edges['right']
      );
      return 'background-image: ' . $bg . '; --preview-color: ' . $color . ';';
    }
    return 'background-color: ' . $color . '; background-image: none; --preview-color: ' . $color . ';';
  }

  /**
   * Resolves the image file currently selected in the form.
   */
  protected function resolveImageFile(array $form, FormStateInterface $form_state, string $image_field): ?FileInterface {
    $fid = $this->extractFid($form_state->getValue([$image_field, 0]));

    if (!$fid) {
      $input = $form_state->getUserInput();
      $fid = $this->extractFid($input[$image_field][0] ?? NULL);
    }

    if (!$fid) {
      $widget = $form[$image_field]['widget'][0] ?? NULL;
      if (is_array($widget)) {
        $fid = $this->extractFid($widget['#value'] ?? NULL);
        if (!$fid && !empty($widget['fids']['#value'])) {
          $fid = $this->extractFid(['fids' => $widget['fids']['#value']]);
        }
      }
    }

    $trigger = $form_state->getTriggeringElement();
    $trigger_name = is_array($trigger) ? (string) ($trigger['#name'] ?? '') : '';
    $is_remove = str_contains($trigger_name, $image_field)
      && str_contains($trigger_name, 'remove_button');

    if (!$fid && !$is_remove) {
      $mapping = $form['#letterbox_palette_mapping'] ?? $this->resolveMapping($form_state);
      $entity = is_array($mapping) ? $this->getMappedNode($form_state, $mapping) : NULL;
      if ($entity && $entity->hasField($image_field) && !$entity->get($image_field)->isEmpty()) {
        $fid = (int) $entity->get($image_field)->target_id;
      }
    }

    if ($fid < 1) {
      return NULL;
    }
    $file = $this->entityTypeManager->getStorage('file')->load($fid);
    return $file instanceof FileInterface ? $file : NULL;
  }

  /**
   * Extracts a file id from a managed-file value array or scalar.
   */
  protected function extractFid(mixed $value): int {
    if (!is_array($value) || empty($value['fids'])) {
      return 0;
    }
    $fids = $value['fids'];
    if (is_array($fids)) {
      $fids = array_filter($fids);
      $first = reset($fids);
      return $first !== FALSE ? (int) $first : 0;
    }
    $parts = preg_split('/\s+/', trim((string) $fids)) ?: [];
    return isset($parts[0]) && $parts[0] !== '' ? (int) $parts[0] : 0;
  }

  /**
   * Returns the mapping for the node currently being edited, if any.
   */
  protected function resolveMapping(FormStateInterface $form_state): ?array {
    $entity = NULL;
    $form_object = $form_state->getFormObject();
    if ($form_object instanceof EntityForm) {
      $candidate = $form_object->getEntity();
      if ($candidate instanceof NodeInterface) {
        $entity = $candidate;
      }
    }
    if (!$entity) {
      return NULL;
    }

    $mappings = $this->configFactory->get('letterbox_palette.settings')->get('mappings') ?? [];
    foreach ($mappings as $mapping) {
      if (!is_array($mapping)) {
        continue;
      }
      if (($mapping['entity_type'] ?? 'node') !== 'node') {
        continue;
      }
      if (($mapping['bundle'] ?? '') === $entity->bundle()) {
        return [
          'entity_type' => 'node',
          'bundle' => (string) $mapping['bundle'],
          'image_field' => (string) ($mapping['image_field'] ?? ''),
          'color_field' => (string) ($mapping['color_field'] ?? ''),
          'gradient_field' => (string) ($mapping['gradient_field'] ?? ''),
          'form_group' => (string) ($mapping['form_group'] ?? ''),
        ];
      }
    }
    return NULL;
  }

  /**
   * Returns the mapped node from the form object, if any.
   */
  protected function getMappedNode(FormStateInterface $form_state, array $mapping): ?NodeInterface {
    $form_object = $form_state->getFormObject();
    if ($form_object instanceof EntityForm) {
      $entity = $form_object->getEntity();
      if ($entity instanceof NodeInterface && $entity->bundle() === $mapping['bundle']) {
        return $entity;
      }
    }
    return NULL;
  }

}
