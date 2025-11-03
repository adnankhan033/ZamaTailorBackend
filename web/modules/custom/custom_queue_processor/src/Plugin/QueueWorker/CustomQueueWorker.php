<?php

namespace Drupal\custom_queue_processor\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\node\Entity\Node;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Processes queue items by creating Basic Page nodes.
 *
 * Note: This queue worker does NOT use automatic cron processing.
 * Processing is handled by hook_cron() to respect batch_size settings.
 * Cron attribute is omitted to prevent automatic processing of all items.
 *
 * @QueueWorker(
 *   id = "custom_queue_processor",
 *   title = @Translation("Custom Queue Processor")
 * )
 */
class CustomQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * Constructs a CustomQueueWorker object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager, AccountInterface $current_user) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // Check if node type 'page' exists.
    $node_type = $this->entityTypeManager->getStorage('node_type')->load('page');
    if (!$node_type) {
      \Drupal::logger('custom_queue_processor')->error('Basic Page content type does not exist.');
      throw new \Exception('Basic Page content type does not exist. Please ensure the node module is enabled and the page content type is available.');
    }

    // Create a Basic Page node.
    $node_data = [
      'type' => 'page',
      'title' => $data['title'] ?? 'Queue Processed Item',
      'uid' => $this->currentUser->id(),
      'status' => 1,
    ];

    // Add body field if it exists on the content type.
    $field_definitions = $this->entityFieldManager->getFieldDefinitions('node', 'page');
    if (isset($field_definitions['body'])) {
      // Use plain_text format as it's always available.
      $node_data['body'] = [
        'value' => $data['description'] ?? 'This node was created from a processed queue item.',
        'format' => 'plain_text',
      ];
    }

    $node = Node::create($node_data);

    $node->save();

    \Drupal::logger('custom_queue_processor')->info('Created Basic Page node from queue item: @title', [
      '@title' => $data['title'] ?? 'Queue Processed Item',
    ]);

    return $node;
  }

}

