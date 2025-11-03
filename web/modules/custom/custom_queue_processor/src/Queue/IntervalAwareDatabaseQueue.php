<?php

namespace Drupal\custom_queue_processor\Queue;

use Drupal\Core\Queue\DatabaseQueue;
use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Database queue that respects processing intervals.
 */
class IntervalAwareDatabaseQueue extends DatabaseQueue {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a \Drupal\Core\Queue\DatabaseQueue object.
   *
   * @param string $name
   *   The name of the queue.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection to use.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct($name, $connection, ConfigFactoryInterface $config_factory) {
    parent::__construct($name, $connection);
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public function claimItem($lease_time = 30) {
    $config = $this->configFactory->get('custom_queue_processor.settings');
    $processing_interval = $config->get('processing_interval') ?? 60;
    $current_time = \Drupal::time()->getCurrentTime();
    $ready_time = $current_time - ($processing_interval * 60);

    try {
      // Select an item that's ready for processing (created before ready_time).
      $query = $this->connection->select('queue', 'q')
        ->fields('q', ['item_id', 'data', 'created'])
        ->condition('q.name', $this->name)
        ->condition('q.expire', 0)
        ->condition('q.created', $ready_time, '<=')
        ->orderBy('q.created', 'ASC')
        ->range(0, 1);

      $item = $query->execute()->fetchObject();

      if ($item) {
        $item->data = unserialize($item->data);
        // Update the item to mark it as claimed.
        $update = $this->connection->update('queue')
          ->fields([
            'expire' => $current_time + $lease_time,
          ])
          ->condition('item_id', $item->item_id)
          ->condition('expire', 0);
        // If there are affected rows, this update succeeded.
        if ($update->execute()) {
          return $item;
        }
      }
    }
    catch (\Exception $e) {
      $this->catchException($e);
    }
    return FALSE;
  }

}

