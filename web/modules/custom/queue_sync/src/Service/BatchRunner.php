<?php

namespace Drupal\queue_sync\Service;

use Drupal\Core\Database\Database;
use Drupal\Core\Queue\QueueFactory;
use Drupal\node\Entity\Node;
use Drupal\Core\Config\ConfigFactoryInterface;

class BatchRunner {
  protected $connection;
  protected $queueFactory;
  protected $configFactory;

  public function __construct(Database $database = NULL, QueueFactory $queue_factory = NULL, ConfigFactoryInterface $config_factory = NULL) {
    // Use static calls to avoid complex service wiring in this example.
    $this->connection = \Drupal::database();
    $this->queueFactory = \Drupal::service('queue');
    $this->configFactory = \Drupal::configFactory();
  }

  public function createBatch(array $items, $run_at_timestamp) {
    $batch_id = 'batch_' . \Drupal::service('uuid')->generate();
    $queue_name = 'queue_sync_' . $batch_id;
    $now = time();

    // Insert batch record.
    $this->connection->insert('qs_batches')
      ->fields([
        'batch_id' => $batch_id,
        'queue_name' => $queue_name,
        'items_total' => count($items),
        'items_processed' => 0,
        'run_at' => $run_at_timestamp,
        'status' => 0,
        'created' => $now,
      ])->execute();

    // Create queue and push items.
    $queue = $this->queueFactory->get($queue_name);
    foreach ($items as $payload) {
      $queue->createItem([
        'data' => $payload,
        'batch_id' => $batch_id,
      ]);
    }
    return $batch_id;
  }

  public function run() {
    $now = time();
    $config = $this->configFactory->get('queue_sync.settings');
    $limit_per_batch = (int) ($config->get('items_per_run') ?? 5);
    $max_batches = (int) ($config->get('max_batches_per_run') ?? 20);
    $node_type = $config->get('node_type') ?? 'page';
    $auto_publish = (bool) ($config->get('auto_publish') ?? TRUE);

    $query = $this->connection->select('qs_batches', 'b')
      ->fields('b')
      ->condition('run_at', $now, '<=')
      ->condition('status', 0)
      ->range(0, $max_batches);
    $result = $query->execute();
    foreach ($result as $batch) {
      $queue = $this->queueFactory->get($batch->queue_name);
      for ($i = 0; $i < $limit_per_batch; $i++) {
        $item = $queue->claimItem();
        if (!$item) {
          break;
        }
        try {
          $payload = $item->data['data'] ?? $item->data;
          
          // Create node based on configured type and settings
          $node_data = [
            'type' => $node_type,
            'title' => $payload['title'] ?? 'Untitled',
            'status' => $auto_publish ? 1 : 0,
          ];
          
          // Add body field if it exists for this node type
          $field_definitions = \Drupal::service('entity_field.manager')
            ->getFieldDefinitions('node', $node_type);
          if (isset($field_definitions['body'])) {
            $body_value = $payload['body'] ?? $payload['description'] ?? '';
            $node_data['body'] = [
              'value' => $body_value,
              'format' => 'basic_html',
            ];
          }
          
          $node = Node::create($node_data);
          $node->save();
          
          $queue->deleteItem($item);
          // Update processed count.
          $this->connection->update('qs_batches')
            ->fields(['items_processed' => $batch->items_processed + 1])
            ->condition('id', $batch->id)
            ->execute();
          $batch->items_processed++;
        } catch (\Exception $e) {
          watchdog_exception('queue_sync', $e);
          // Release item for retry.
          $queue->releaseItem($item);
        }
      }

      if ($batch->items_processed >= $batch->items_total) {
        $this->connection->update('qs_batches')
          ->fields(['status' => 2])
          ->condition('id', $batch->id)
          ->execute();
      } else {
        $this->connection->update('qs_batches')
          ->fields(['status' => 1])
          ->condition('id', $batch->id)
          ->execute();
      }
    }
  }
}
