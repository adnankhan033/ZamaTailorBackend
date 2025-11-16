<?php

namespace Drupal\queue_sync\Helper;

use Drupal\Core\Database\Connection;
use Psr\Log\LoggerInterface;

/**
 * Helper class for batch progress operations.
 */
class BatchProgressHelper {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a BatchProgressHelper object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory service.
   */
  public function __construct(Connection $connection, $logger_factory) {
    $this->connection = $connection;
    $this->logger = $logger_factory->get('queue_sync');
  }

  /**
   * Update batch progress by batch_id (string).
   *
   * @param string $batch_id
   *   The batch ID string.
   * @param bool $increment
   *   If TRUE, increment the processed count. If FALSE, use provided count.
   * @param int|null $items_processed
   *   The number of items processed (only used if $increment is FALSE).
   *
   * @return bool
   *   TRUE if batch is completed, FALSE otherwise.
   */
  public function updateBatchProgressByBatchId($batch_id, $increment = TRUE, $items_processed = NULL) {
    if (!$batch_id) {
      return FALSE;
    }

    try {
      // Get current batch status
      $batch = $this->connection->select('qs_batches', 'b')
        ->fields('b', ['items_total', 'items_processed', 'status'])
        ->condition('batch_id', $batch_id)
        ->execute()
        ->fetchObject();

      if (!$batch) {
        return FALSE;
      }

      // Calculate new processed count
      if ($increment) {
        $new_processed = $batch->items_processed + 1;
      } else {
        $new_processed = $items_processed ?? $batch->items_processed;
      }

      $new_status = $batch->status;

      // Update status based on progress
      if ($new_processed >= $batch->items_total) {
        // All items processed - mark as completed
        $new_status = 2; // 2 = completed
      }
      elseif ($new_status == 0 && $new_processed > 0) {
        // First item processed - mark as running
        $new_status = 1; // 1 = running
      }

      // Update batch record
      $this->connection->update('qs_batches')
        ->fields([
          'items_processed' => $new_processed,
          'status' => $new_status,
        ])
        ->condition('batch_id', $batch_id)
        ->execute();

      return $new_status == 2;
    }
    catch (\Exception $e) {
      // Log error but don't fail
      $this->logger->warning('Error updating batch progress: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Update batch progress by ID (integer).
   *
   * @param int $batch_id
   *   The batch ID integer.
   * @param int $items_processed
   *   Number of items processed.
   * @param int|null $items_total
   *   Total items (optional, for status calculation).
   *
   * @return bool
   *   TRUE if batch is now completed, FALSE otherwise.
   */
  public function updateBatchProgressById($batch_id, $items_processed, $items_total = NULL) {
    if (!$batch_id) {
      return FALSE;
    }

    try {
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
    catch (\Exception $e) {
      $this->logger->warning('Error updating batch progress by ID: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Create a batch record in qs_batches table.
   *
   * @param string $batch_id
   *   The batch ID.
   * @param string $queue_name
   *   The queue name.
   * @param int $items_total
   *   Total number of items.
   * @param int $run_at
   *   Unix timestamp when batch should run.
   *
   * @return bool
   *   TRUE if successful, FALSE otherwise.
   */
  public function createBatchRecord($batch_id, $queue_name, $items_total, $run_at = NULL) {
    if (!$batch_id || !$queue_name) {
      return FALSE;
    }

    try {
      $now = time();
      $run_at = $run_at ?? $now;

      $this->connection->insert('qs_batches')
        ->fields([
          'batch_id' => $batch_id,
          'queue_name' => $queue_name,
          'items_total' => $items_total,
          'items_processed' => 0,
          'run_at' => $run_at,
          'status' => 0, // 0 = pending
          'created' => $now,
        ])->execute();

      return TRUE;
    }
    catch (\Exception $e) {
      $this->logger->error('Error creating batch record: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

}

