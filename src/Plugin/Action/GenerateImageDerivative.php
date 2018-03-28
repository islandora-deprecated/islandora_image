<?php

namespace Drupal\islandora_image\Plugin\Action;

use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\islandora\DerivativeUtils;
use Drupal\islandora\EventGenerator\EmitEvent;
use Drupal\islandora\EventGenerator\EventGeneratorInterface;
use Drupal\jwt\Authentication\Provider\JwtAuth;
use Drupal\media_entity\Entity\MediaBundle;
use Drupal\node\Entity\NodeType;
use Stomp\StatefulStomp;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Emits a Node event.
 *
 * @Action(
 *   id = "generate_image_derivative",
 *   label = @Translation("Generate an image derivative"),
 *   type = "node"
 * )
 */
class GenerateImageDerivative extends EmitEvent {

  /**
   * Derivative utilities.
   *
   * @var \Drupal\islandora\DerivativeUtils
   */
  protected $utils;

  /**
   * Constructs a EmitEvent action.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Current user.
   * @param \Drupal\Core\Entity\EntityTypeManager $entity_type_manager
   *   Entity type manager.
   * @param \Drupal\islandora\EventGenerator\EventGeneratorInterface $event_generator
   *   EventGenerator service to serialize AS2 events.
   * @param \Stomp\StatefulStomp $stomp
   *   Stomp client.
   * @param \Drupal\jwt\Authentication\Provider\JwtAuth $auth
   *   JWT Auth client.
   * @param \Drupal\islandora\DerivativeUtils $utils
   *   Derivative utilities.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    AccountInterface $account,
    EntityTypeManager $entity_type_manager,
    EventGeneratorInterface $event_generator,
    StatefulStomp $stomp,
    JwtAuth $auth,
    DerivativeUtils $utils
  ) {
    parent::__construct(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $account,
      $entity_type_manager,
      $event_generator,
      $stomp,
      $auth
    );
    $this->utils = $utils;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('islandora.eventgenerator'),
      $container->get('islandora.stomp'),
      $container->get('jwt.authentication.jwt'),
      $container->get('islandora.derivative_utils')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function defaultConfiguration() {
    return [
      'queue' => 'islandora-connector-houdini',
      'event' => 'Generate Derivative',
      'source' => '',
      'destination' => '',
      'bundle' => '',
      'mimetype' => 'image/jpeg',
      'args' => '',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildConfigurationForm($form, $form_state);
    $form['event']['#disabled'] = 'disabled';

    $media_reference_fields = $this->generateFieldOptions();

    $form['source'] = [
      '#type' => 'select',
      '#title' => t('Source field'),
      '#default_value' => $this->configuration['source'],
      '#required' => TRUE,
      '#options' => $this->generateFieldOptions(),
      '#description' => t('Field referencing the Media to use as the source of the derivative.'),
    ];
    $form['destination'] = [
      '#type' => 'select',
      '#title' => t('Destination field'),
      '#default_value' => $this->configuration['destination'],
      '#required' => TRUE,
      '#options' => $this->generateFieldOptions(),
      '#description' => t('Entity reference field for media where derivative will be ingested.'),
    ];
    $form['bundle'] = [
      '#type' => 'select',
      '#title' => t('Bundle'),
      '#default_value' => $this->configuration['bundle'],
      '#required' => TRUE,
      '#options' => $this->generateBundleOptions(),
      '#description' => t('Bundle to create for derivative media'),
    ];
    $form['mimetype'] = [
      '#type' => 'textfield',
      '#title' => t('Mimetype'),
      '#default_value' => $this->configuration['mimetype'],
      '#required' => TRUE,
      '#rows' => '8',
      '#description' => t('Mimetype to convert to (e.g. image/jpeg, image/png, etc...)'),
    ];
    $form['args'] = [
      '#type' => 'textfield',
      '#title' => t('Additional arguments'),
      '#default_value' => $this->configuration['args'],
      '#rows' => '8',
      '#description' => t('Additional command line arguments for ImageMagick convert (e.g. -resize 50%'),
    ];
    return $form;
  }

  /**
   * Generates a 2D array for field options.
   *
   * @return array
   *   Array formatted for a 2d select form element.
   */
  protected function generateFieldOptions() {
    $node_types = $this->entityTypeManager->getStorage('node_type')->loadMultiple();
    $node_types = array_map(
      function (NodeType $node_type) {
        return $node_type->label();
      },
      $node_types
    );
    $node_types = array_flip($node_types);
    $fields = array_map(
      function (string $node_type_id) {
        return $this->utils->getMediaReferenceFields('node', $node_type_id);
      },
      $node_types
    );
    $fields = array_filter(
      $fields,
      function (array $fields) {
        return !empty($fields);
      }
    );
    foreach (array_keys($fields) as $key) {
      $fields[$key] = array_map(
        function (FieldConfig $field) {
          return $field->label();
        },
        $fields[$key]
      );
    }
    return $fields;
  }

  /**
   * Generates an array for bundle options.
   *
   * @return array
   *   Media bundle labels, keyed by bundle ids.
   */
  protected function generateBundleOptions() {
    $bundles = $this->entityTypeManager->getStorage('media_bundle')->loadMultiple();
    return array_map(
      function (MediaBundle $bundle) {
        return $bundle->label();
      },
      $bundles
    );
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    parent::submitConfigurationForm($form, $form_state);
    $this->configuration['source'] = $form_state->getValue('source');
    $this->configuration['destination'] = $form_state->getValue('destination');
    $this->configuration['bundle'] = $form_state->getValue('bundle');
    $this->configuration['mimetype'] = $form_state->getValue('mimetype');
    $this->configuration['args'] = $form_state->getValue('args');
  }

}
