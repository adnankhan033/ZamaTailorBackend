<?php

namespace Drupal\queue_sync\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Psr\Log\LoggerInterface;

/**
 * Service for running queue batches efficiently with memory management.
 */
class BatchRunner {
  use StringTranslationTrait;
  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

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
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a BatchRunner object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param object $logger_factory
   *   The logger factory service.
   */
  public function __construct(Connection $connection, QueueFactory $queue_factory, ConfigFactoryInterface $config_factory, $logger_factory) {
    $this->connection = $connection;
    $this->queueFactory = $queue_factory;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('queue_sync');
  }

  /**
   * Create batch from items with automatic chunking for large datasets.
   *
   * @param array $items
   *   Array of items to queue.
   * @param int $run_at_timestamp
   *   Timestamp when batch should run.
   * @param int|null $chunk_size
   *   Number of items to queue per chunk. If NULL, will auto-calculate.
   * @param bool $auto_chunk
   *   Whether to automatically split into multiple batches if data is too large.
   *
   * @return array|string
   *   Single batch ID if not chunked, or array of batch IDs if auto-chunked.
   */
  public function createBatch(array $items, $run_at_timestamp, ?int $chunk_size = NULL, bool $auto_chunk = TRUE) {
    $total_items = count($items);
    $now = time();

    // If auto-chunking is enabled, check capacity and split if needed.
    if ($auto_chunk && $total_items > 0) {
      try {
        $capacity_manager = \Drupal::service('queue_sync.capacity_manager');
      }
      catch (\Exception $e) {
        // Service not available, skip auto-chunking.
        $this->logger->warning('Capacity manager service not available, skipping auto-chunking');
        $auto_chunk = FALSE;
      }
    }
    
    if ($auto_chunk && $total_items > 0 && isset($capacity_manager)) {
      $config = $this->configFactory->get('queue_sync.settings');
      
      $capacity_config = [
        'max_memory_usage_mb' => (int) ($config->get('max_memory_usage_mb') ?? 256),
        'min_chunk_size' => (int) ($config->get('min_chunk_size') ?? 10),
        'max_chunk_size' => (int) ($config->get('max_chunk_size') ?? 1000),
      ];
      
      $capacity_check = $capacity_manager->checkCapacity($items, $capacity_config);
      
      // If data is too large, split into multiple batches.
      if ($capacity_check['needs_chunking']) {
        $this->logger->info('Large dataset detected. Auto-chunking into multiple batches: @reason', [
          '@reason' => $capacity_check['reason'],
        ]);
        
        $chunks = $capacity_manager->autoChunk($items, $capacity_config);
        $batch_ids = [];
        $delay_increment = 0;
        
        // Create separate batch for each chunk.
        // Use a safe chunk size if not provided.
        $safe_chunk_size = $chunk_size ?? 500;
        
        foreach ($chunks as $chunk_index => $chunk) {
          $chunk_run_at = $run_at_timestamp + ($delay_increment * 60); // Stagger by 1 minute per batch
          $batch_id = $this->createSingleBatch($chunk, $chunk_run_at, $safe_chunk_size);
          $batch_ids[] = $batch_id;
          $delay_increment++;
          
          $this->logger->info('Created batch @batch_id for chunk @chunk of @total_chunks items', [
            '@batch_id' => $batch_id,
            '@chunk' => $chunk_index + 1,
            '@total_chunks' => count($chunks),
          ]);
        }
        
        return $batch_ids;
      }
    }

    // Use provided chunk_size or calculate optimal one.
    if ($chunk_size === NULL) {
      try {
        $capacity_manager = \Drupal::service('queue_sync.capacity_manager');
      $config = $this->configFactory->get('queue_sync.settings');
      
      $capacity_config = [
        'max_memory_usage_mb' => (int) ($config->get('max_memory_usage_mb') ?? 256),
        'min_chunk_size' => (int) ($config->get('min_chunk_size') ?? 10),
        'max_chunk_size' => (int) ($config->get('max_chunk_size') ?? 1000),
      ];
      
        $memory_info = $capacity_manager->calculateMemoryUsage($items);
        $chunking_info = $capacity_manager->calculateSafeChunkSize(
          $total_items,
          $memory_info['memory_per_item_bytes'],
          $capacity_config
        );
        $chunk_size = $chunking_info['recommended_chunk_size'];
      }
      catch (\Exception $e) {
        // Service not available, use default.
        $this->logger->warning('Capacity manager service not available, using default chunk size');
        $chunk_size = 500;
      }
    }
    
    // Default chunk size if still null.
    if ($chunk_size === NULL || $chunk_size <= 0) {
      $chunk_size = 500;
    }
    
    // Ensure chunk_size is an integer.
    $chunk_size = (int) $chunk_size;

    return $this->createSingleBatch($items, $run_at_timestamp, $chunk_size);
  }

  /**
   * Create a single batch from items.
   *
   * @param array $items
   *   Array of items to queue.
   * @param int $run_at_timestamp
   *   Timestamp when batch should run.
   * @param int $chunk_size
   *   Number of items to queue per chunk.
   *
   * @return string
   *   Batch ID.
   */
  protected function createSingleBatch(array $items, int $run_at_timestamp, int $chunk_size) {
    $batch_id = 'batch_' . \Drupal::service('uuid')->generate();
    $queue_name = 'queue_sync_' . $batch_id;
    $now = time();
    $total_items = count($items);

    // Insert batch record.
    $this->connection->insert('qs_batches')
      ->fields([
        'batch_id' => $batch_id,
        'queue_name' => $queue_name,
        'items_total' => $total_items,
        'items_processed' => 0,
        'run_at' => $run_at_timestamp,
        'status' => 0,
        'created' => $now,
      ])->execute();

    // Create queue and push items in chunks to avoid memory issues.
    $queue = $this->queueFactory->get($queue_name);
    $queue_chunks = array_chunk($items, $chunk_size);

    foreach ($queue_chunks as $chunk_index => $chunk) {
      foreach ($chunk as $payload) {
        $queue->createItem([
          'data' => $payload,
          'batch_id' => $batch_id,
        ]);
      }

      // Free memory after each chunk.
      unset($chunk);

      // Log progress for large batches.
      if ($total_items > 1000 && ($chunk_index % 10 == 0)) {
        $processed = min(($chunk_index + 1) * $chunk_size, $total_items);
        $this->logger->info('Queued @processed of @total items for batch @batch_id', [
          '@processed' => $processed,
          '@total' => $total_items,
          '@batch_id' => $batch_id,
        ]);
      }
    }

    return $batch_id;
  }

  /**
   * Run queue processing with optimized batch handling and memory management.
   */
  public function run() {
    $now = time();
    $config = $this->configFactory->get('queue_sync.settings');
    $limit_per_batch = (int) ($config->get('items_per_run') ?? 50);
    $max_batches = (int) ($config->get('max_batches_per_run') ?? 20);
    $bulk_process_size = (int) ($config->get('bulk_process_size') ?? 10);
    $memory_limit = (int) ($config->get('memory_threshold_mb') ?? 128);

    // Check available memory before processing.
    $initial_memory = memory_get_usage(TRUE);
    $memory_limit_bytes = $memory_limit * 1024 * 1024;

    $query = $this->connection->select('qs_batches', 'b')
      ->fields('b')
      ->condition('run_at', $now, '<=')
      ->condition('status', [0, 1], 'IN')
      ->orderBy('b.run_at', 'ASC')
      ->orderBy('b.id', 'ASC')
      ->range(0, $max_batches);
    $batches = $query->execute()->fetchAll();

    $processed_batches = 0;
    foreach ($batches as $batch) {
      // Check memory usage before processing each batch.
      $current_memory = memory_get_usage(TRUE);
      if ($current_memory - $initial_memory > $memory_limit_bytes) {
        $this->logger->warning('Memory threshold reached. Stopping batch processing.');
        break;
      }

      $queue = $this->queueFactory->get($batch->queue_name);
      
      // Check how many items remain in this batch.
      $remaining_items = $batch->items_total - $batch->items_processed;
      
      // If remaining items are less than or equal to the limit, process ALL of them to complete the batch immediately.
      // This ensures small batches finish in one cron run instead of waiting.
      $items_to_process_count = $remaining_items <= $limit_per_batch 
        ? $remaining_items  // Process all remaining items
        : $limit_per_batch; // Process up to limit
        
      $this->logger->info('Processing batch @batch_id: @remaining remaining, processing @count items', [
        '@batch_id' => $batch->batch_id,
        '@remaining' => $remaining_items,
        '@count' => $items_to_process_count,
      ]);
      
      $processed_count = 0;
      $items_to_process = [];
      $batch_update_count = 0;

      // Collect items for bulk processing.
      for ($i = 0; $i < $items_to_process_count; $i++) {
        $item = $queue->claimItem();
        if (!$item) {
          // Queue is empty, no more items to process.
          break;
        }

        $items_to_process[] = $item;

        // Process in bulk when we reach the bulk size or at the end.
        if (count($items_to_process) >= $bulk_process_size || $i == $items_to_process_count - 1) {
          $processed_count += $this->processItemsBulk($items_to_process, $queue);
          $items_to_process = [];

          // Update batch status periodically to reduce DB queries.
          $batch_update_count++;
          if ($batch_update_count >= 5) {
            $this->updateBatchProgress($batch->id, $batch->items_processed + $processed_count);
            $batch_update_count = 0;
          }
        }
      }

      // Final batch status update.
      $final_processed = $batch->items_processed + $processed_count;
      $is_completed = $this->updateBatchProgress($batch->id, $final_processed, $batch->items_total);

      // If batch is completed, create nodes from synced user records.
      if ($is_completed) {
        $this->createNodesFromBatch($batch->id);
      }

      // Free memory.
      unset($queue, $items_to_process);
      $processed_batches++;

      // Prevent memory exhaustion with large number of batches.
      if ($processed_batches >= $max_batches) {
        break;
      }
    }

    // Force garbage collection if we processed many batches.
    if ($processed_batches > 5) {
      gc_collect_cycles();
    }
  }

  /**
   * Process multiple items in bulk for better performance.
   *
   * @param array $items
   *   Queue items to process.
   * @param \Drupal\Core\Queue\QueueInterface $queue
   *   The queue object.
   *
   * @return int
   *   Number of successfully processed items.
   */
  protected function processItemsBulk(array $items, $queue) {
    $processed = 0;
    $bulk_data = [];

    // Prepare bulk data for processing.
    foreach ($items as $item) {
      try {
        // Extract payload - handle different data structures.
        $payload = NULL;
        
        // Log raw item data for debugging (info level so it's visible).
        $this->logger->info('Processing queue item. Raw data structure: @data', [
          '@data' => json_encode($item->data ?? 'NULL'),
        ]);
        
        // Check if item->data exists.
        if (!isset($item->data)) {
          $this->logger->warning('Queue item has no data property, skipping');
          $queue->releaseItem($item);
          continue;
        }
        
        // Handle wrapped structure: ['data' => payload, 'batch_id' => ...]
        if (is_array($item->data) && isset($item->data['data'])) {
          $payload = $item->data['data'];
          $this->logger->info('Extracted payload from item->data[\'data\'] structure');
        }
        // Handle direct array payload (no wrapping).
        elseif (is_array($item->data)) {
          // Check if this looks like a wrapper structure with batch_id.
          if (isset($item->data['batch_id']) && isset($item->data['data'])) {
            $payload = $item->data['data'];
            $this->logger->info('Extracted payload from wrapper structure (batch_id found)');
          }
          // Otherwise, assume the entire array is the payload (direct user record).
          elseif (!isset($item->data['batch_id'])) {
            $payload = $item->data;
            $this->logger->info('Using item->data directly as payload (no batch_id key found)');
          }
          else {
            // We have a batch_id but no 'data' key - try using the whole structure.
            $payload = $item->data;
            $this->logger->warning('Item data has batch_id but no \'data\' key, using entire array as payload. Keys: @keys', [
              '@keys' => implode(', ', array_keys($item->data)),
            ]);
          }
        }
        // Handle serialized string.
        elseif (is_string($item->data)) {
          // Try JSON decode first, then unserialize.
          $decoded = json_decode($item->data, TRUE);
          if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $payload = $decoded;
            // If decoded result still has a 'data' key, extract it.
            if (isset($payload['data']) && is_array($payload['data'])) {
              $payload = $payload['data'];
            }
          }
          else {
            $unserialized = @unserialize($item->data);
            if ($unserialized !== FALSE && is_array($unserialized)) {
              $payload = $unserialized;
              // If unserialized result still has a 'data' key, extract it.
              if (isset($payload['data']) && is_array($payload['data'])) {
                $payload = $payload['data'];
              }
            }
          }
        }
        
        // Final validation: ensure payload is an array.
        if (!is_array($payload)) {
          $this->logger->warning('Queue item payload is not an array after extraction. Type: @type, Value: @value', [
            '@type' => gettype($payload),
            '@value' => is_scalar($payload) ? (string) $payload : json_encode($payload),
          ]);
          
          // Create a minimal array structure if payload is numeric (UID).
          if (is_numeric($payload) && $payload > 0) {
            $payload = [
              'uid' => (int) $payload,
              'username' => 'user_' . $payload,
              'email' => 'user_' . $payload . '@example.com',
              'status' => 1,
              'sync_timestamp' => time(),
            ];
            $this->logger->info('Created minimal user record from numeric payload: @uid', ['@uid' => $payload['uid']]);
          }
          else {
            $this->logger->error('Cannot create valid user record from payload, skipping item');
            $queue->releaseItem($item);
            continue;
          }
        }
        
        // Log extracted payload for debugging.
        $this->logger->info('Extracted payload: @payload', [
          '@payload' => json_encode(array_slice($payload, 0, 10, TRUE)),
        ]);
        
        // Ensure payload has required fields (at minimum uid).
        if (empty($payload['uid']) && empty($payload['user_id']) && empty($payload['id'])) {
          $this->logger->warning('Extracted payload missing uid/user_id/id fields. Keys: @keys', [
            '@keys' => implode(', ', array_keys($payload)),
          ]);
        }
        
        $bulk_data[] = [
          'item' => $item,
          'payload' => $payload,
        ];
      }
      catch (\Exception $e) {
        $this->logger->error('Error preparing item for bulk processing: @message. Item data: @item_data', [
          '@message' => $e->getMessage(),
          '@item_data' => isset($item->data) ? json_encode($item->data) : 'NULL',
        ]);
        $queue->releaseItem($item);
      }
    }

    // Process items using UserSyncService if available, otherwise fallback.
    if (!empty($bulk_data)) {
      try {
        $user_sync_service = \Drupal::service('queue_sync.user_sync');
        $user_records = array_map(function ($data) {
          return $data['payload'];
        }, $bulk_data);

        // Log what we're about to process for debugging.
        $this->logger->info('About to process @count records via UserSyncService. Sample payload: @sample', [
          '@count' => count($user_records),
          '@sample' => json_encode($user_records[0] ?? []),
        ]);

        // Process user records in bulk.
        $processed_count = $user_sync_service->processUserRecordsBulk($user_records, 'upsert');
        
        if ($processed_count > 0) {
          // Delete successfully processed items.
          foreach ($bulk_data as $data) {
            $queue->deleteItem($data['item']);
            $processed++;
          }
        }
        else {
          // If no records were processed, release items for retry.
          $this->logger->warning('No records were processed by UserSyncService. Releasing items for retry.');
          foreach ($bulk_data as $data) {
            $queue->releaseItem($data['item']);
          }
        }
      }
      catch (\Exception $e) {
        // Fallback: process items individually.
        foreach ($bulk_data as $data) {
          try {
            // Process individual user record.
            $user_sync_service = \Drupal::service('queue_sync.user_sync');
            $user_sync_service->processUserRecordsBulk([$data['payload']], 'upsert');
            $queue->deleteItem($data['item']);
            $processed++;
          }
          catch (\Exception $item_exception) {
            $this->logger->error('Error processing queue item: @message', [
              '@message' => $item_exception->getMessage(),
            ]);
            $queue->releaseItem($data['item']);
          }
        }
      }
    }

    return $processed;
  }

  /**
   * Update batch progress efficiently.
   *
   * @param int $batch_id
   *   The batch ID.
   * @param int $items_processed
   *   Number of items processed.
   * @param int|null $items_total
   *   Total items (optional, for status calculation).
   *
   * @return bool
   *   TRUE if batch is now completed, FALSE otherwise.
   */
  protected function updateBatchProgress(int $batch_id, int $items_processed, ?int $items_total = NULL) {
    $status = 1; // Running.
    $is_completed = FALSE;
    
    if ($items_total !== NULL && $items_processed >= $items_total) {
      $status = 2; // Completed.
      $is_completed = TRUE;
    }

    $this->connection->update('qs_batches')
      ->fields([
        'items_processed' => $items_processed,
        'status' => $status,
      ])
      ->condition('id', $batch_id)
      ->execute();
    
    return $is_completed;
  }

  /**
   * Create nodes from completed batch's user records.
   *
   * @param int $batch_id
   *   The batch ID.
   *
   * @return int
   *   Number of nodes created.
   */
  protected function createNodesFromBatch(int $batch_id) {
    $config = $this->configFactory->get('queue_sync.settings');
    $node_type = $config->get('node_type') ?? 'page';
    $auto_publish = (bool) ($config->get('auto_publish') ?? TRUE);

    // Get batch info.
    $batch = $this->connection->select('qs_batches', 'b')
      ->fields('b', ['batch_id', 'queue_name', 'items_total', 'created'])
      ->condition('b.id', $batch_id)
      ->execute()
      ->fetchObject();

    if (!$batch) {
      $this->logger->warning('Batch @id not found for node creation', [
        '@id' => $batch_id,
      ]);
      return 0;
    }

    $this->logger->info('Starting node creation for completed batch @batch_id (@queue_name)', [
      '@batch_id' => $batch_id,
      '@queue_name' => $batch->queue_name,
    ]);

    // Get all user records that were synced for this batch.
    // We'll get them from the synced records table based on sync_timestamp.
    // The sync happens during queue processing, so records should be within batch creation time window.
    $sync_time_start = $batch->created - 600; // 10 minutes before
    $sync_time_end = time() + 60; // Allow small buffer

    $user_records = $this->connection->select('queue_sync_user_records', 'r')
      ->fields('r', ['uid', 'username', 'email', 'status', 'user_data', 'sync_timestamp'])
      ->condition('r.sync_timestamp', [$sync_time_start, $sync_time_end], 'BETWEEN')
      ->orderBy('r.sync_timestamp', 'ASC')
      ->orderBy('r.uid', 'ASC')
      ->range(0, $batch->items_total) // Limit to batch size
      ->execute()
      ->fetchAll();

    if (empty($user_records)) {
      $this->logger->warning('No user records found for batch @batch_id (synced between @start and @end), skipping node creation', [
        '@batch_id' => $batch_id,
        '@start' => date('Y-m-d H:i:s', $sync_time_start),
        '@end' => date('Y-m-d H:i:s', $sync_time_end),
      ]);
      return 0;
    }

    $this->logger->info('Found @count user records for batch @batch_id, creating nodes', [
      '@count' => count($user_records),
      '@batch_id' => $batch_id,
    ]);

    // Validate node type exists.
    $node_type_manager = \Drupal::entityTypeManager()->getStorage('node_type');
    $node_type_entity = $node_type_manager->load($node_type);

    if (!$node_type_entity) {
      $this->logger->error('Node type "@type" does not exist. Cannot create nodes. Please configure a valid node type in Node Creation Settings.', [
        '@type' => $node_type,
      ]);
      return 0;
    }

    // Get field definitions for the node type.
    $field_definitions = \Drupal::service('entity_field.manager')
      ->getFieldDefinitions('node', $node_type);

    $nodes_created = 0;
    $date_formatter = \Drupal::service('date.formatter');

    // Create nodes from all user records in the batch.
    foreach ($user_records as $record) {
      try {
        $user_data = unserialize($record->user_data);
        if (!$user_data) {
          $user_data = [
            'uid' => $record->uid,
            'username' => $record->username,
            'email' => $record->email,
            'status' => $record->status,
          ];
        }

        // Prepare node data.
        $node_data = [
          'type' => $node_type,
          'title' => $this->t('User Record: @username (UID: @uid)', [
            '@username' => $user_data['username'] ?? $record->username,
            '@uid' => $user_data['uid'] ?? $record->uid,
          ]),
          'status' => $auto_publish ? 1 : 0,
          'uid' => 1, // System user.
        ];

        // Add body field if it exists.
        if (isset($field_definitions['body'])) {
          $body_content = $this->t('Synced user record information:') . "\n\n";
          $body_content .= $this->t('User ID: @uid', ['@uid' => $user_data['uid'] ?? $record->uid]) . "\n";
          $body_content .= $this->t('Username: @username', ['@username' => $user_data['username'] ?? $record->username]) . "\n";
          $body_content .= $this->t('Email: @email', ['@email' => $user_data['email'] ?? $record->email]) . "\n";
          $body_content .= $this->t('Status: @status', [
            '@status' => ($user_data['status'] ?? $record->status) ? $this->t('Active') : $this->t('Inactive'),
          ]) . "\n";
          $body_content .= $this->t('Synced: @time', [
            '@time' => $date_formatter->format($user_data['sync_timestamp'] ?? time()),
          ]);

          $node_data['body'] = [
            'value' => $body_content,
            'format' => 'plain_text',
          ];
        }

        // Create the node.
        $node = \Drupal\node\Entity\Node::create($node_data);
        $node->save();

        $nodes_created++;

        $this->logger->info('Created node @nid from user record UID @uid for batch @batch_id', [
          '@nid' => $node->id(),
          '@uid' => $user_data['uid'] ?? $record->uid,
          '@batch_id' => $batch_id,
        ]);
      }
      catch (\Exception $e) {
        $this->logger->error('Error creating node from user record UID @uid: @message', [
          '@uid' => $record->uid,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    if ($nodes_created > 0) {
      $this->logger->info('✅ Successfully created @count nodes from completed batch @batch_id using node type "@type" (Auto-publish: @publish)', [
        '@count' => $nodes_created,
        '@batch_id' => $batch_id,
        '@type' => $node_type,
        '@publish' => $auto_publish ? $this->t('Yes') : $this->t('No'),
      ]);
    }
    else {
      $this->logger->warning('⚠️ No nodes created from batch @batch_id. Check if user records were synced properly.', [
        '@batch_id' => $batch_id,
      ]);
    }

    return $nodes_created;
  }
}
