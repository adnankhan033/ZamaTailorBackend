<?php

namespace Drupal\queue_sync\Service;

use Psr\Log\LoggerInterface;

/**
 * Service to manage processing capacity and automatic chunking.
 */
class CapacityManager {

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a CapacityManager object.
   *
   * @param object $logger_factory
   *   The logger factory service.
   */
  public function __construct($logger_factory) {
    $this->logger = $logger_factory->get('queue_sync');
  }

  /**
   * Calculate estimated memory usage for items.
   *
   * @param array $items
   *   Array of items to process.
   * @param int $sample_size
   *   Number of items to sample for calculation (default: 10).
   *
   * @return array
   *   Array with 'estimated_memory_bytes' and 'memory_per_item_bytes'.
   */
  public function calculateMemoryUsage(array $items, int $sample_size = 10) {
    $total_items = count($items);
    
    if ($total_items === 0) {
      return [
        'estimated_memory_bytes' => 0,
        'memory_per_item_bytes' => 0,
        'estimated_memory_mb' => 0,
      ];
    }

    // Sample items to calculate average memory usage.
    $sample = array_slice($items, 0, min($sample_size, $total_items));
    $sample_memory = 0;
    
    foreach ($sample as $item) {
      $sample_memory += strlen(serialize($item));
    }
    
    $avg_memory_per_item = $sample_memory / count($sample);
    $estimated_total_memory = $avg_memory_per_item * $total_items;
    
    return [
      'estimated_memory_bytes' => $estimated_total_memory,
      'memory_per_item_bytes' => $avg_memory_per_item,
      'estimated_memory_mb' => round($estimated_total_memory / 1024 / 1024, 2),
    ];
  }

  /**
   * Get PHP system limits.
   *
   * @return array
   *   Array with PHP limit information.
   */
  public function getSystemLimits() {
    $memory_limit_str = ini_get('memory_limit');
    $memory_limit_bytes = $this->parseSize($memory_limit_str);
    $max_execution_time = ini_get('max_execution_time');
    $current_memory = memory_get_usage(TRUE);
    $peak_memory = memory_get_peak_usage(TRUE);
    $available_memory = $memory_limit_bytes - $current_memory;

    return [
      'memory_limit_bytes' => $memory_limit_bytes,
      'memory_limit_mb' => round($memory_limit_bytes / 1024 / 1024, 2),
      'memory_limit_str' => $memory_limit_str,
      'current_memory_bytes' => $current_memory,
      'current_memory_mb' => round($current_memory / 1024 / 1024, 2),
      'available_memory_bytes' => max(0, $available_memory),
      'available_memory_mb' => round(max(0, $available_memory) / 1024 / 1024, 2),
      'peak_memory_bytes' => $peak_memory,
      'peak_memory_mb' => round($peak_memory / 1024 / 1024, 2),
      'max_execution_time' => $max_execution_time ? (int) $max_execution_time : 0,
    ];
  }

  /**
   * Calculate safe chunk size based on system limits.
   *
   * @param int $total_items
   *   Total number of items to process.
   * @param float $memory_per_item_bytes
   *   Estimated memory per item in bytes.
   * @param array $config
   *   Optional configuration overrides.
   *
   * @return array
   *   Array with chunking recommendations.
   */
  public function calculateSafeChunkSize(int $total_items, float $memory_per_item_bytes, array $config = []) {
    $limits = $this->getSystemLimits();
    
    // Get configuration values.
    $max_memory_usage_mb = $config['max_memory_usage_mb'] ?? 256;
    $max_memory_usage_bytes = $max_memory_usage_mb * 1024 * 1024;
    $min_chunk_size = $config['min_chunk_size'] ?? 10;
    $max_chunk_size = $config['max_chunk_size'] ?? 1000;
    $safety_factor = $config['safety_factor'] ?? 0.7; // Use only 70% of available memory
    
    // Calculate based on available memory with safety factor.
    $safe_available_memory = min(
      $limits['available_memory_bytes'] * $safety_factor,
      $max_memory_usage_bytes
    );
    
    // Calculate chunk size based on memory.
    $chunk_size_by_memory = 0;
    if ($memory_per_item_bytes > 0) {
      $chunk_size_by_memory = (int) floor($safe_available_memory / $memory_per_item_bytes);
    }
    
    // Use configured max chunk size as upper limit.
    $recommended_chunk_size = min(
      max($chunk_size_by_memory, $min_chunk_size),
      $max_chunk_size
    );
    
    // Calculate how many batches will be needed.
    $batches_needed = (int) ceil($total_items / $recommended_chunk_size);
    
    return [
      'total_items' => $total_items,
      'recommended_chunk_size' => $recommended_chunk_size,
      'batches_needed' => $batches_needed,
      'safe_memory_usage_mb' => round($safe_available_memory / 1024 / 1024, 2),
      'memory_per_item_bytes' => $memory_per_item_bytes,
      'can_process_in_one_batch' => $batches_needed === 1,
      'limits' => $limits,
    ];
  }

  /**
   * Check if data can be processed in one batch.
   *
   * @param array $items
   *   Array of items to check.
   * @param array $config
   *   Configuration options.
   *
   * @return array
   *   Decision result with recommendations.
   */
  public function checkCapacity(array $items, array $config = []) {
    $total_items = count($items);
    
    if ($total_items === 0) {
      return [
        'can_process' => TRUE,
        'recommended_action' => 'process',
        'reason' => 'No items to process',
      ];
    }

    // Calculate memory usage.
    $memory_info = $this->calculateMemoryUsage($items);
    $limits = $this->getSystemLimits();
    
    // Calculate safe chunk size.
    $chunking_info = $this->calculateSafeChunkSize(
      $total_items,
      $memory_info['memory_per_item_bytes'],
      $config
    );
    
    $estimated_memory_mb = $memory_info['estimated_memory_mb'];
    $safe_memory_mb = $chunking_info['safe_memory_usage_mb'];
    
    // Decision logic.
    $can_process_in_one = $chunking_info['can_process_in_one_batch'];
    $needs_chunking = $chunking_info['batches_needed'] > 1;
    
    $reason = '';
    if ($needs_chunking) {
      $reason = sprintf(
        'Data too large: %d items (~%.2f MB) exceeds safe capacity (~%.2f MB). Needs %d batches of %d items each.',
        $total_items,
        $estimated_memory_mb,
        $safe_memory_mb,
        $chunking_info['batches_needed'],
        $chunking_info['recommended_chunk_size']
      );
    }
    else {
      $reason = sprintf(
        'Data within limits: %d items (~%.2f MB) can be processed in one batch.',
        $total_items,
        $estimated_memory_mb
      );
    }
    
    return [
      'can_process' => TRUE,
      'can_process_in_one_batch' => $can_process_in_one,
      'needs_chunking' => $needs_chunking,
      'recommended_action' => $needs_chunking ? 'chunk' : 'process',
      'reason' => $reason,
      'total_items' => $total_items,
      'estimated_memory_mb' => $estimated_memory_mb,
      'safe_memory_mb' => $safe_memory_mb,
      'chunking_info' => $chunking_info,
      'limits' => $limits,
      'memory_info' => $memory_info,
    ];
  }

  /**
   * Automatically chunk items into optimal batch sizes.
   *
   * @param array $items
   *   Items to chunk.
   * @param array $config
   *   Configuration options.
   *
   * @return array
   *   Array of chunks, each chunk is an array of items.
   */
  public function autoChunk(array $items, array $config = []) {
    $total_items = count($items);
    
    if ($total_items === 0) {
      return [];
    }

    // Calculate memory and safe chunk size.
    $memory_info = $this->calculateMemoryUsage($items);
    $chunking_info = $this->calculateSafeChunkSize(
      $total_items,
      $memory_info['memory_per_item_bytes'],
      $config
    );
    
    $chunk_size = $chunking_info['recommended_chunk_size'];
    
    // Split into chunks.
    $chunks = array_chunk($items, $chunk_size);
    
    $this->logger->info('Auto-chunked @total items into @chunks chunks of ~@size items each', [
      '@total' => $total_items,
      '@chunks' => count($chunks),
      '@size' => $chunk_size,
    ]);
    
    return $chunks;
  }

  /**
   * Parse size string (e.g., "128M", "2G") to bytes.
   *
   * @param string $size
   *   Size string.
   *
   * @return int
   *   Size in bytes.
   */
  protected function parseSize(string $size) {
    $size = trim($size);
    $last = strtolower($size[strlen($size) - 1]);
    $size = (int) $size;
    
    switch ($last) {
      case 'g':
        $size *= 1024;
        // Fall through.
      case 'm':
        $size *= 1024;
        // Fall through.
      case 'k':
        $size *= 1024;
    }
    
    return $size;
  }

  /**
   * Get capacity report for display.
   *
   * @param array $items
   *   Optional items to analyze.
   *
   * @return array
   *   Capacity report array.
   */
  public function getCapacityReport(array $items = []) {
    $limits = $this->getSystemLimits();
    $report = [
      'system_limits' => $limits,
    ];
    
    if (!empty($items)) {
      $memory_info = $this->calculateMemoryUsage($items);
      $capacity_check = $this->checkCapacity($items);
      $report['current_data'] = [
        'item_count' => count($items),
        'memory_info' => $memory_info,
        'capacity_check' => $capacity_check,
      ];
    }
    
    return $report;
  }

}

