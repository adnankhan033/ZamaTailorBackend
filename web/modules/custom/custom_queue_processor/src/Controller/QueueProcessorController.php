<?php

namespace Drupal\custom_queue_processor\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for Queue Processor management.
 */
class QueueProcessorController extends ControllerBase {

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * Constructs a QueueProcessorController object.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(QueueFactory $queue_factory, ConfigFactoryInterface $config_factory, MessengerInterface $messenger) {
    $this->queueFactory = $queue_factory;
    $this->configFactory = $config_factory;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('queue'),
      $container->get('config.factory'),
      $container->get('messenger')
    );
  }

  /**
   * Main overview page.
   */
  public function overview() {
    $build = [];

    $build['description'] = [
      '#type' => 'markup',
      '#markup' => '<div class="queue-processor-overview">' .
        '<h3>' . $this->t('Queue Processor Overview') . '</h3>' .
        '<p>' . $this->t('Manage your queue items, process specific records, and configure settings.') . '</p>' .
        '</div>',
    ];

    // Get queue statistics.
    $database = \Drupal::database();
    $total_items = $database->select('queue', 'q')
      ->condition('q.name', 'custom_queue_processor')
      ->countQuery()
      ->execute()
      ->fetchField();

    $config = $this->configFactory->get('custom_queue_processor.settings');
    $processing_interval = $config->get('processing_interval') ?? 60;

    // Count ready items.
    $current_time = \Drupal::time()->getCurrentTime();
    $ready_time = $current_time - ($processing_interval * 60);
    $ready_items = $database->select('queue', 'q')
      ->condition('q.name', 'custom_queue_processor')
      ->condition('q.created', $ready_time, '<=')
      ->condition('q.expire', 0)
      ->countQuery()
      ->execute()
      ->fetchField();

    $pending_items = $total_items - $ready_items;

    $build['stats'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['queue-stats', 'clearfix']],
    ];

    $build['stats']['total'] = [
      '#type' => 'markup',
      '#markup' => '<div class="stat-box"><strong>' . $this->t('Total Items') . '</strong><br><span class="stat-number">' . $total_items . '</span></div>',
    ];

    $build['stats']['ready'] = [
      '#type' => 'markup',
      '#markup' => '<div class="stat-box"><strong>' . $this->t('Ready to Process') . '</strong><br><span class="stat-number ready">' . $ready_items . '</span></div>',
    ];

    $build['stats']['pending'] = [
      '#type' => 'markup',
      '#markup' => '<div class="stat-box"><strong>' . $this->t('Pending') . '</strong><br><span class="stat-number pending">' . $pending_items . '</span></div>',
    ];

    $batch_size = $config->get('batch_size') ?? 10;

    $build['stats']['interval'] = [
      '#type' => 'markup',
      '#markup' => '<div class="stat-box"><strong>' . $this->t('Processing Interval') . '</strong><br><span class="stat-number">' . $processing_interval . ' min</span></div>',
    ];

    $build['stats']['batch_size'] = [
      '#type' => 'markup',
      '#markup' => '<div class="stat-box"><strong>' . $this->t('Batch Size') . '</strong><br><span class="stat-number">' . $batch_size . ' items</span></div>',
    ];

    $build['auto_processing_info'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--status" style="margin: 1em 0;"><p><strong>' . 
        $this->t('✓ Automatic Processing Enabled') . '</strong><br>' .
        $this->t('Ready items are automatically processed during cron runs (no need to click "Process Ready Items"). Up to @batch items will be processed each time cron executes, and Basic Page nodes will be created automatically.', [
          '@batch' => $batch_size,
        ]) .
        '</p></div>',
    ];

    // Automatically process ready items if any exist.
    // Only process batch_size number, not all ready items.
    if ($ready_items > 0) {
      // Process ONLY the batch_size number of items automatically.
      $processed = $this->autoProcessReadyItems($batch_size, $processing_interval);
      
      if ($processed['count'] > 0) {
        $remaining_ready = max(0, $ready_items - $processed['count']);
        $message = $this->t('Automatically processed @count item(s) (batch size: @batch). Basic Page nodes created.', [
          '@count' => $processed['count'],
          '@batch' => $batch_size,
        ]);
        
        if ($remaining_ready > 0) {
          $message .= ' ' . $this->t('@remaining more items are ready and will be processed in the next batch.', [
            '@remaining' => $remaining_ready,
          ]);
        }
        
        $this->messenger->addMessage($message);
        // Reload page to show updated stats.
        return new RedirectResponse(Url::fromRoute('custom_queue_processor.overview')->toString());
      }
      
      // If processing failed, show info.
      if ($processed['failed'] > 0) {
        $build['process_info'] = [
          '#type' => 'markup',
          '#markup' => '<div class="messages messages--warning"><p>' .
            $this->t('There are @count ready items. Processing failed for some items. Check logs for details.', [
              '@count' => $ready_items,
            ]) .
            '</p></div>',
        ];
      }
    }

    $build['#attached']['library'][] = 'custom_queue_processor/queue_processor';

    return $build;
  }

  /**
   * Lists all queue items.
   */
  public function list() {
    $queue = $this->queueFactory->get('custom_queue_processor');
    $config = $this->configFactory->get('custom_queue_processor.settings');
    $processing_interval = $config->get('processing_interval') ?? 60;

    // Get queue items from database.
    $database = \Drupal::database();
    $items = $database->select('queue', 'q')
      ->fields('q', ['item_id', 'name', 'data', 'created', 'expire'])
      ->condition('q.name', 'custom_queue_processor')
      ->orderBy('q.created', 'DESC')
      ->execute()
      ->fetchAll();

    $build = [];

    // Add action buttons.
    $build['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['action-links']],
    ];

    $build['actions']['create_dummy'] = [
      '#type' => 'link',
      '#title' => $this->t('Create 5 Dummy Records'),
      '#url' => Url::fromRoute('custom_queue_processor.create_dummy'),
      '#attributes' => ['class' => ['button', 'button--primary']],
    ];

    $build['actions']['process_item'] = [
      '#type' => 'link',
      '#title' => $this->t('Process Specific Item'),
      '#url' => Url::fromRoute('custom_queue_processor.process_form'),
      '#attributes' => ['class' => ['button']],
    ];

    // Add process ready items button.
    $current_time = \Drupal::time()->getCurrentTime();
    $ready_time = $current_time - ($processing_interval * 60);
    $ready_count = $database->select('queue', 'q')
      ->condition('q.name', 'custom_queue_processor')
      ->condition('q.created', $ready_time, '<=')
      ->condition('q.expire', 0)
      ->countQuery()
      ->execute()
      ->fetchField();

    if ($ready_count > 0) {
      $batch_size = $config->get('batch_size') ?? 10;
      $build['actions']['process_ready'] = [
        '#type' => 'link',
        '#title' => $this->t('Process Ready Items (@count ready, up to @batch)', [
          '@count' => $ready_count,
          '@batch' => $batch_size,
        ]),
        '#url' => Url::fromRoute('custom_queue_processor.process_ready'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }

    $build['info'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--status"><p>' . 
        $this->t('<strong>Processing Interval:</strong> @interval minutes. Only items that have waited at least this long can be processed.', 
        ['@interval' => $processing_interval]) . 
        '</p></div>',
    ];

    // Build table of queue items.
    $header = [
      $this->t('Item ID'),
      $this->t('Title'),
      $this->t('Created'),
      $this->t('Status'),
      $this->t('Time Until Processing'),
      $this->t('Operations'),
    ];

    $rows = [];
    foreach ($items as $item) {
      $data = unserialize($item->data);
      $created_time = $item->created;
      $current_time = \Drupal::time()->getCurrentTime();
      $time_diff = $created_time + ($processing_interval * 60) - $current_time;
      
      // Calculate status: Pending or Ready
      $status = 'pending';
      $status_text = '<span class="status-pending">' . $this->t('Pending') . '</span>';
      if ($time_diff <= 0) {
        $status = 'ready';
        $status_text = '<span class="status-ready">' . $this->t('Ready') . '</span>';
      }

      $time_display = $time_diff > 0 
        ? $this->t('@minutes minutes', ['@minutes' => round($time_diff / 60)])
        : '<strong>' . $this->t('Ready now') . '</strong>';

      $operations = [];
      if ($status === 'ready') {
        $operations['process'] = [
          'title' => $this->t('Process'),
          'url' => Url::fromRoute('custom_queue_processor.process', ['item_id' => $item->item_id]),
          'attributes' => ['class' => ['button', 'button--small']],
        ];
      }
      else {
        $operations['view'] = [
          'title' => $this->t('View Details'),
          'url' => Url::fromRoute('custom_queue_processor.process_form', [], ['query' => ['item_id' => $item->item_id]]),
        ];
      }

      $rows[] = [
        'data' => [
          ['data' => $item->item_id, 'class' => ['item-id']],
          ['data' => $data['title'] ?? $this->t('N/A'), 'class' => ['item-title']],
          ['data' => \Drupal::service('date.formatter')->format($created_time, 'short'), 'class' => ['item-created']],
          ['data' => ['#markup' => $status_text], 'class' => ['item-status', $status]],
          ['data' => ['#markup' => $time_display], 'class' => ['item-time']],
          ['data' => [
            '#type' => 'operations',
            '#links' => $operations,
          ], 'class' => ['item-operations']],
        ],
        'class' => [$status],
      ];
    }

    if (empty($rows)) {
      $build['empty'] = [
        '#type' => 'markup',
        '#markup' => '<p>' . $this->t('No queue items found. Create dummy records to get started.') . '</p>',
      ];
    }
    else {
      $build['table'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#empty' => $this->t('No queue items found.'),
        '#attributes' => ['class' => ['queue-items-table']],
        '#sticky' => TRUE,
      ];
    }

    $build['#attached']['library'][] = 'custom_queue_processor/queue_processor';

    return $build;
  }

  /**
   * Creates 5 dummy queue records.
   */
  public function createDummy() {
    $queue = $this->queueFactory->get('custom_queue_processor');
    $titles = [
      'Sample Record 1',
      'Sample Record 2',
      'Sample Record 3',
      'Sample Record 4',
      'Sample Record 5',
    ];

    foreach ($titles as $index => $title) {
      $data = [
        'title' => $title,
        'description' => "This is a dummy record #" . ($index + 1),
        'created' => \Drupal::time()->getCurrentTime(),
      ];
      $queue->createItem($data);
    }

    $this->messenger->addMessage($this->t('Created 5 dummy queue records.'));
    return new RedirectResponse(Url::fromRoute('custom_queue_processor.overview')->toString());
  }

  /**
   * Processes a specific queue item.
   */
  public function processItem($item_id) {
    $queue = $this->queueFactory->get('custom_queue_processor');
    $config = $this->configFactory->get('custom_queue_processor.settings');
    $processing_interval = $config->get('processing_interval') ?? 60;

    // Get the specific item from database.
    $database = \Drupal::database();
    $item = $database->select('queue', 'q')
      ->fields('q', ['item_id', 'data', 'created'])
      ->condition('q.item_id', $item_id)
      ->condition('q.name', 'custom_queue_processor')
      ->execute()
      ->fetchObject();

    if (!$item) {
      $this->messenger->addError($this->t('Queue item not found.'));
      return new RedirectResponse(Url::fromRoute('custom_queue_processor.overview')->toString());
    }

    $created_time = $item->created;
    $current_time = \Drupal::time()->getCurrentTime();
    $time_diff = $created_time + ($processing_interval * 60) - $current_time;

    if ($time_diff > 0) {
      $this->messenger->addWarning($this->t('Item is not ready for processing yet. Wait @minutes more minutes.', [
        '@minutes' => round($time_diff / 60),
      ]));
      return new RedirectResponse(Url::fromRoute('custom_queue_processor.overview')->toString());
    }

    // Process the item.
    $data = unserialize($item->data);
    $queue_worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('custom_queue_processor');
    $queue_worker->processItem($data);

    // Delete the processed item from the database.
    $database->delete('queue')
      ->condition('item_id', $item_id)
      ->condition('name', 'custom_queue_processor')
      ->execute();

    $this->messenger->addMessage($this->t('Successfully processed queue item: @title. A Basic Page node has been created.', ['@title' => $data['title']]));
    return new RedirectResponse(Url::fromRoute('custom_queue_processor.overview')->toString());
  }

  /**
   * Processes multiple ready queue items using the configured batch size.
   * Only processes the specific batch_size number, not all ready items.
   */
  public function processReadyItems() {
    $config = $this->configFactory->get('custom_queue_processor.settings');
    $processing_interval = $config->get('processing_interval') ?? 60;
    $batch_size = $config->get('batch_size') ?? 10;

    $database = \Drupal::database();
    $current_time = \Drupal::time()->getCurrentTime();
    $ready_time = $current_time - ($processing_interval * 60);

    // Get ONLY batch_size number of ready items (not all ready items).
    $ready_items = $database->select('queue', 'q')
      ->fields('q', ['item_id', 'data', 'created'])
      ->condition('q.name', 'custom_queue_processor')
      ->condition('q.created', $ready_time, '<=')
      ->condition('q.expire', 0)
      ->orderBy('q.created', 'ASC')
      ->range(0, (int) $batch_size) // Process ONLY batch_size items
      ->execute()
      ->fetchAll();

    if (empty($ready_items)) {
      $this->messenger->addWarning($this->t('No items are ready for processing at this time.'));
      return new RedirectResponse(Url::fromRoute('custom_queue_processor.overview')->toString());
    }

    $queue_worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('custom_queue_processor');
    $processed_count = 0;
    $failed_count = 0;
    $processed_titles = [];

    // Process exactly batch_size items (or fewer if not enough ready).
    foreach ($ready_items as $item) {
      // Safety check: never process more than batch_size.
      if ($processed_count >= (int) $batch_size) {
        break;
      }

      try {
        $data = unserialize($item->data);
        
        // Process the item.
        $queue_worker->processItem($data);

        // Delete the processed item from the database.
        $database->delete('queue')
          ->condition('item_id', $item->item_id)
          ->condition('name', 'custom_queue_processor')
          ->execute();

        $processed_count++;
        $processed_titles[] = $data['title'] ?? 'Item #' . $item->item_id;
      }
      catch (\Exception $e) {
        $failed_count++;
        \Drupal::logger('custom_queue_processor')->error('Failed to process queue item @id: @message', [
          '@id' => $item->item_id,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // Display results.
    if ($processed_count > 0) {
      $message = $this->t('Successfully processed @count item(s) (batch size: @batch).', [
        '@count' => $processed_count,
        '@batch' => $batch_size,
      ]);
      
      if ($failed_count > 0) {
        $message .= ' ' . $this->t('@failed failed.', ['@failed' => $failed_count]);
      }
      
      $this->messenger->addMessage($message);

      if (count($processed_titles) <= 5) {
        // Show titles if not too many.
        $this->messenger->addMessage($this->t('Processed items: @titles', [
          '@titles' => implode(', ', $processed_titles),
        ]));
      }
    }

    if ($failed_count > 0) {
      $this->messenger->addError($this->t('Failed to process @count item(s). Check the logs for details.', [
        '@count' => $failed_count,
      ]));
    }

    return new RedirectResponse(Url::fromRoute('custom_queue_processor.overview')->toString());
  }

  /**
   * Automatically processes ready items (called automatically).
   * Only processes the specific batch_size number, not all ready items.
   */
  protected function autoProcessReadyItems($batch_size, $processing_interval) {
    $database = \Drupal::database();
    $current_time = \Drupal::time()->getCurrentTime();
    $ready_time = $current_time - ($processing_interval * 60);

    // Get ONLY batch_size number of ready items (not all ready items).
    $ready_items = $database->select('queue', 'q')
      ->fields('q', ['item_id', 'data', 'created'])
      ->condition('q.name', 'custom_queue_processor')
      ->condition('q.created', $ready_time, '<=')
      ->condition('q.expire', 0)
      ->orderBy('q.created', 'ASC')
      ->range(0, (int) $batch_size) // Process ONLY batch_size items
      ->execute()
      ->fetchAll();

    if (empty($ready_items)) {
      return ['count' => 0, 'failed' => 0];
    }

    $queue_worker = \Drupal::service('plugin.manager.queue_worker')->createInstance('custom_queue_processor');
    $processed_count = 0;
    $failed_count = 0;

    // Process exactly batch_size items (or fewer if not enough ready).
    foreach ($ready_items as $item) {
      // Safety check: never process more than batch_size.
      if ($processed_count >= (int) $batch_size) {
        break;
      }

      try {
        $data = unserialize($item->data);
        
        // Process the item - creates Basic Page node.
        $queue_worker->processItem($data);

        // Delete the processed item.
        $database->delete('queue')
          ->condition('item_id', $item->item_id)
          ->condition('name', 'custom_queue_processor')
          ->execute();

        $processed_count++;
      }
      catch (\Exception $e) {
        $failed_count++;
        \Drupal::logger('custom_queue_processor')->error('Auto-processing failed for item @id: @message', [
          '@id' => $item->item_id,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return ['count' => $processed_count, 'failed' => $failed_count];
  }

}

