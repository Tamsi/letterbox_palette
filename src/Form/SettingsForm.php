<?php

declare(strict_types=1);

namespace Drupal\letterbox_palette\Form;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Letterbox Palette field mappings.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The bundle info service.
   */
  protected EntityTypeBundleInfoInterface $bundleInfo;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    $instance->bundleInfo = $container->get('entity_type.bundle.info');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'letterbox_palette_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['letterbox_palette.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('letterbox_palette.settings');
    $mappings = $config->get('mappings') ?? [];
    if ($form_state->get('mappings') !== NULL) {
      $mappings = $form_state->get('mappings');
    }
    $form_state->set('mappings', $mappings);

    $styles = image_style_options(FALSE);
    $form['preview_image_style'] = [
      '#type' => 'select',
      '#title' => $this->t('Preview image style'),
      '#options' => $styles,
      '#empty_option' => $this->t('- Original image -'),
      '#default_value' => $config->get('preview_image_style') ?: 'medium',
      '#description' => $this->t('Image style used in the letterbox preview. Falls back to the original file URL when empty or missing.'),
    ];

    $form['preview_aspect_ratio'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Preview aspect ratio'),
      '#default_value' => $config->get('preview_aspect_ratio') ?: '16 / 7',
      '#description' => $this->t('CSS <code>aspect-ratio</code> value for the preview box (e.g. <code>16 / 7</code> or <code>16 / 9</code>).'),
      '#required' => TRUE,
    ];

    $form['mappings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Field mappings'),
      '#tree' => TRUE,
      '#prefix' => '<div id="letterbox-palette-mappings">',
      '#suffix' => '</div>',
    ];

    $form['mappings']['help'] = [
      '#markup' => '<p>' . $this->t('For each content type, map a portrait <em>image</em> field to a plain text color field (<code>#rrggbb</code>) and an optional boolean gradient field. Create those fields first on the bundle.') . '</p>',
    ];

    foreach ($mappings as $delta => $mapping) {
      $form['mappings'][$delta] = $this->buildMappingRow((int) $delta, is_array($mapping) ? $mapping : []);
    }

    $form['mappings']['actions'] = [
      '#type' => 'actions',
      'add' => [
        '#type' => 'submit',
        '#value' => $this->t('Add mapping'),
        '#submit' => ['::addMapping'],
        '#ajax' => [
          'callback' => '::ajaxMappings',
          'wrapper' => 'letterbox-palette-mappings',
        ],
        '#limit_validation_errors' => [],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * Builds one mapping row.
   */
  protected function buildMappingRow(int $delta, array $mapping): array {
    $bundles = [];
    foreach ($this->bundleInfo->getBundleInfo('node') as $id => $info) {
      $bundles[$id] = $info['label'];
    }

    $row = [
      '#type' => 'details',
      '#title' => $this->t('Mapping @num', ['@num' => $delta + 1]),
      '#open' => TRUE,
    ];

    $row['entity_type'] = [
      '#type' => 'value',
      '#value' => 'node',
    ];

    $row['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Content type'),
      '#options' => $bundles,
      '#default_value' => $mapping['bundle'] ?? array_key_first($bundles),
      '#required' => TRUE,
    ];

    $row['image_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image field machine name'),
      '#default_value' => $mapping['image_field'] ?? '',
      '#required' => TRUE,
      '#description' => $this->t('Example: <code>field_image</code>'),
    ];

    $row['color_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Background color field machine name'),
      '#default_value' => $mapping['color_field'] ?? '',
      '#required' => TRUE,
      '#description' => $this->t('Plain text field storing <code>#rrggbb</code>.'),
    ];

    $row['gradient_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Gradient field machine name'),
      '#default_value' => $mapping['gradient_field'] ?? '',
      '#description' => $this->t('Boolean field. Leave empty to hide the gradient checkbox.'),
    ];

    $row['form_group'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Optional field_group'),
      '#default_value' => $mapping['form_group'] ?? '',
      '#description' => $this->t('If Field Group is used, place the palette UI inside this group (e.g. <code>group_image</code>).'),
    ];

    $row['remove'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove'),
      '#name' => 'remove_mapping_' . $delta,
      '#submit' => ['::removeMapping'],
      '#ajax' => [
        'callback' => '::ajaxMappings',
        'wrapper' => 'letterbox-palette-mappings',
      ],
      '#limit_validation_errors' => [],
      '#attributes' => ['data-delta' => (string) $delta],
    ];

    return $row;
  }

  /**
   * AJAX rebuild for mappings.
   */
  public function ajaxMappings(array &$form, FormStateInterface $form_state): array {
    return $form['mappings'];
  }

  /**
   * Adds an empty mapping row.
   */
  public function addMapping(array &$form, FormStateInterface $form_state): void {
    $mappings = $form_state->get('mappings') ?? [];
    $mappings[] = [
      'entity_type' => 'node',
      'bundle' => '',
      'image_field' => '',
      'color_field' => '',
      'gradient_field' => '',
      'form_group' => '',
    ];
    $form_state->set('mappings', $mappings);
    $form_state->setRebuild();
  }

  /**
   * Removes a mapping row.
   */
  public function removeMapping(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $delta = isset($trigger['#attributes']['data-delta'])
      ? (int) $trigger['#attributes']['data-delta']
      : NULL;
    $mappings = $form_state->get('mappings') ?? [];
    if ($delta !== NULL && isset($mappings[$delta])) {
      unset($mappings[$delta]);
      $form_state->set('mappings', array_values($mappings));
    }
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $raw = $form_state->getValue('mappings') ?? [];
    $mappings = [];
    foreach ($raw as $key => $row) {
      if (!is_numeric($key) || !is_array($row)) {
        continue;
      }
      $image = trim((string) ($row['image_field'] ?? ''));
      $color = trim((string) ($row['color_field'] ?? ''));
      $bundle = trim((string) ($row['bundle'] ?? ''));
      if ($image === '' || $color === '' || $bundle === '') {
        continue;
      }
      $mappings[] = [
        'entity_type' => 'node',
        'bundle' => $bundle,
        'image_field' => $image,
        'color_field' => $color,
        'gradient_field' => trim((string) ($row['gradient_field'] ?? '')),
        'form_group' => trim((string) ($row['form_group'] ?? '')),
      ];
    }

    $this->config('letterbox_palette.settings')
      ->set('preview_image_style', (string) $form_state->getValue('preview_image_style'))
      ->set('preview_aspect_ratio', (string) $form_state->getValue('preview_aspect_ratio'))
      ->set('mappings', $mappings)
      ->save();

    parent::submitForm($form, $form_state);
  }

}
