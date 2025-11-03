<?php

namespace Drupal\queue_sync\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\Core\Link;
use Drupal\Core\Database\Database;
use Drupal\Core\Queue\QueueFactory;

class QueueSyncController extends ControllerBase {

  /**
   * Professional dashboard overview with comprehensive statistics.
   */
  public function overview() {
    $build = [];
    $build['#attached']['library'][] = 'queue_sync/dashboard';
    
    $connection = Database::getConnection();
    $queueFactory = \Drupal::service('queue');
    $config = \Drupal::config('queue_sync.settings');
    $items_per_run = $config->get('items_per_run') ?? 5;
    $current_time = \Drupal::time()->getCurrentTime();

    // Get all batches with detailed statistics.
    $query = $connection->select('qs_batches', 'b')
      ->fields('b')
      ->orderBy('b.created', 'DESC');
    $batches = $query->execute()->fetchAll();

    // Calculate comprehensive statistics.
    $stats = $this->calculateStatistics($batches, $connection, $queueFactory, $current_time);

    // Header section with title and action buttons.
    $build['header'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['queue-sync-header']],
    ];
    
    $build['header']['title'] = [
      '#type' => 'markup',
      '#markup' => '<h1 class="queue-sync-title">' . $this->t('Queue Sync Dashboard') . '</h1>',
    ];

    $build['header']['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['queue-sync-actions']],
    ];

    $build['header']['actions']['generate'] = [
      '#type' => 'link',
      '#title' => $this->t('Generate Batch'),
      '#url' => Url::fromRoute('queue_sync.generate'),
      '#attributes' => [
        'class' => ['button', 'button--primary', 'queue-sync-btn'],
      ],
    ];

    // Use direct path instead of route to avoid cache dependency.
    $build['header']['actions']['generate_large'] = [
      '#type' => 'link',
      '#title' => $this->t('Generate Large Test Data'),
      '#url' => Url::fromUri('internal:/admin/config/queue-sync/generate-large'),
      '#attributes' => [
        'class' => ['button', 'button--primary', 'queue-sync-btn'],
        'title' => $this->t('Generate huge amounts of test data to verify auto-chunking (10,000 - 500,000+ items)'),
      ],
    ];

    $build['header']['actions']['settings'] = [
      '#type' => 'link',
      '#title' => $this->t('Settings'),
      '#url' => Url::fromRoute('queue_sync.settings'),
      '#attributes' => [
        'class' => ['button', 'queue-sync-btn'],
      ],
    ];

    // Statistics cards section.
    $build['statistics'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['queue-sync-stats-container']],
    ];

    // Card 1: Total Batches
    $build['statistics']['total_batches'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['stat-card', 'stat-card--primary']],
    ];
    $build['statistics']['total_batches']['content'] = [
      '#type' => 'markup',
      '#markup' => '<div class="stat-icon">📦</div>' .
        '<div class="stat-content">' .
        '<div class="stat-label">' . $this->t('Total Batches') . '</div>' .
        '<div class="stat-value">' . $stats['total_batches'] . '</div>' .
        '</div>',
    ];

    // Card 2: Pending Batches
    $build['statistics']['pending_batches'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['stat-card', 'stat-card--warning']],
    ];
    $build['statistics']['pending_batches']['content'] = [
      '#type' => 'markup',
      '#markup' => '<div class="stat-icon">⏳</div>' .
        '<div class="stat-content">' .
        '<div class="stat-label">' . $this->t('Pending Batches') . '</div>' .
        '<div class="stat-value">' . $stats['pending_batches'] . '</div>' .
        '</div>',
    ];

    // Card 3: Running Batches
    $build['statistics']['running_batches'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['stat-card', 'stat-card--info']],
    ];
    $build['statistics']['running_batches']['content'] = [
      '#type' => 'markup',
      '#markup' => '<div class="stat-icon">🔄</div>' .
        '<div class="stat-content">' .
        '<div class="stat-label">' . $this->t('Running Batches') . '</div>' .
        '<div class="stat-value">' . $stats['running_batches'] . '</div>' .
        '</div>',
    ];

    // Card 4: Completed Batches
    $build['statistics']['completed_batches'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['stat-card', 'stat-card--success']],
    ];
    $build['statistics']['completed_batches']['content'] = [
      '#type' => 'markup',
      '#markup' => '<div class="stat-icon">✅</div>' .
        '<div class="stat-content">' .
        '<div class="stat-label">' . $this->t('Completed Batches') . '</div>' .
        '<div class="stat-value">' . $stats['completed_batches'] . '</div>' .
        '</div>',
    ];

    // Queue Statistics Section.
    $build['queue_stats'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['queue-sync-queue-stats']],
    ];

    $build['queue_stats']['title'] = [
      '#type' => 'markup',
      '#markup' => '<h2 class="section-title">' . $this->t('Queue Statistics') . '</h2>',
    ];

    $build['queue_stats']['content'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['queue-stats-grid']],
    ];

    // Total Items Pending
    $build['queue_stats']['content']['total_pending'] = [
      '#type' => 'markup',
      '#markup' => '<div class="queue-stat-item">' .
        '<span class="queue-stat-label">' . $this->t('Total Items Pending') . ':</span>' .
        '<span class="queue-stat-value queue-stat-value--warning">' . $stats['total_pending_items'] . '</span>' .
        '</div>',
    ];

    // Total Items Processed
    $build['queue_stats']['content']['total_processed'] = [
      '#type' => 'markup',
      '#markup' => '<div class="queue-stat-item">' .
        '<span class="queue-stat-label">' . $this->t('Total Items Processed') . ':</span>' .
        '<span class="queue-stat-value queue-stat-value--success">' . $stats['total_processed_items'] . '</span>' .
        '</div>',
    ];

    // Total Items
    $build['queue_stats']['content']['total_items'] = [
      '#type' => 'markup',
      '#markup' => '<div class="queue-stat-item">' .
        '<span class="queue-stat-label">' . $this->t('Total Items') . ':</span>' .
        '<span class="queue-stat-value">' . $stats['total_items'] . '</span>' .
        '</div>',
    ];

    // Processing Rate
    $processing_rate = $stats['total_items'] > 0 
      ? round(($stats['total_processed_items'] / $stats['total_items']) * 100, 1) 
      : 0;
    $build['queue_stats']['content']['processing_rate'] = [
      '#type' => 'markup',
      '#markup' => '<div class="queue-stat-item">' .
        '<span class="queue-stat-label">' . $this->t('Processing Rate') . ':</span>' .
        '<span class="queue-stat-value">' . $processing_rate . '%</span>' .
        '</div>',
    ];

    // Items Per Run
    $build['queue_stats']['content']['items_per_run'] = [
      '#type' => 'markup',
      '#markup' => '<div class="queue-stat-item">' .
        '<span class="queue-stat-label">' . $this->t('Items Per Run') . ':</span>' .
        '<span class="queue-stat-value">' . $items_per_run . '</span>' .
        '</div>',
    ];

    // Next Batch Run
    $next_run = $stats['next_batch_run_time'] > 0 
      ? date('Y-m-d H:i:s', $stats['next_batch_run_time'])
      : $this->t('No pending batches');
    $build['queue_stats']['content']['next_run'] = [
      '#type' => 'markup',
      '#markup' => '<div class="queue-stat-item">' .
        '<span class="queue-stat-label">' . $this->t('Next Batch Run') . ':</span>' .
        '<span class="queue-stat-value">' . $next_run . '</span>' .
        '</div>',
    ];

    // Detailed Batches Table.
    $build['batches_section'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['queue-sync-batches-section']],
    ];

    $build['batches_section']['title'] = [
      '#type' => 'markup',
      '#markup' => '<h2 class="section-title">' . $this->t('Batch Details') . '</h2>',
    ];

    if (empty($batches)) {
      $build['batches_section']['empty'] = [
        '#type' => 'markup',
        '#markup' => '<div class="queue-sync-empty">' .
          '<p>' . $this->t('No batches found. Use the Generate Batch button to create your first batch.') . '</p>' .
          '</div>',
      ];
    } else {
      $header = [
        'batch_id' => $this->t('Batch ID'),
        'queue_name' => $this->t('Queue Name'),
        'progress' => $this->t('Progress'),
        'items' => $this->t('Items'),
        'scheduled' => $this->t('Scheduled / Next Run'),
        'created' => $this->t('Created'),
        'status' => $this->t('Status'),
        'actions' => $this->t('Actions'),
      ];

      $rows = [];
      $config = \Drupal::config('queue_sync.settings');
      $items_per_run = (int) ($config->get('items_per_run') ?? 5);
      $next_cron_time = $this->getNextCronTime();
      $cron_interval = $this->getCronInterval();
      
      foreach ($batches as $batch) {
        $progress = $batch->items_total > 0 
          ? round(($batch->items_processed / $batch->items_total) * 100, 1) 
          : 0;
        
        $status_badge = $this->getStatusBadge($batch->status);
        $progress_bar = '<div class="progress-bar-container">' .
          '<div class="progress-bar" style="width: ' . $progress . '%"></div>' .
          '<span class="progress-text">' . $batch->items_processed . ' / ' . $batch->items_total . '</span>' .
          '</div>';

        $items_info = '<div class="items-info">' .
          '<span class="items-total">' . $batch->items_total . '</span>' .
          '<span class="items-separator">→</span>' .
          '<span class="items-processed">' . $batch->items_processed . '</span>' .
          '</div>';

        // Calculate next run time for running batches
        $scheduled_display = $this->getScheduledDisplay($batch, $next_cron_time, $items_per_run, $current_time, $cron_interval);

        $rows[] = [
          'data' => [
            'batch_id' => [
              'data' => [
                '#type' => 'markup',
                // Security: Escape output to prevent XSS.
                '#markup' => '<code class="batch-id">' . $this->t('@id...', [
                  '@id' => htmlspecialchars(substr($batch->batch_id, 0, 20), ENT_QUOTES, 'UTF-8'),
                ]) . '</code>',
              ],
            ],
            'queue_name' => [
              'data' => [
                '#type' => 'markup',
                // Security: Escape output to prevent XSS.
                '#markup' => '<code class="queue-name">' . htmlspecialchars($batch->queue_name, ENT_QUOTES, 'UTF-8') . '</code>',
              ],
            ],
            'progress' => [
              'data' => [
                '#type' => 'markup',
                '#markup' => $progress_bar,
              ],
            ],
            'items' => [
              'data' => [
                '#type' => 'markup',
                '#markup' => $items_info,
              ],
            ],
            'scheduled' => [
              'data' => [
                '#type' => 'markup',
                '#markup' => $scheduled_display,
              ],
            ],
            'created' => [
              'data' => [
                '#type' => 'markup',
                '#markup' => '<div class="datetime-cell">' . 
                  date('Y-m-d', $batch->created) . '<br>' .
                  '<small>' . date('H:i:s', $batch->created) . '</small>' .
                  '</div>',
              ],
            ],
            'status' => [
              'data' => [
                '#type' => 'markup',
                '#markup' => $status_badge,
              ],
            ],
            'actions' => [
              'data' => [
                '#type' => 'markup',
                '#markup' => $this->getActionLinks($batch),
              ],
            ],
          ],
          'class' => ['batch-row', 'batch-row--status-' . $batch->status],
        ];
      }

      $build['batches_section']['table'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#attributes' => ['class' => ['queue-sync-table', 'sticky-enabled']],
        '#sticky' => TRUE,
      ];
    }

    return $build;
  }

  /**
   * Calculate comprehensive statistics.
   */
  protected function calculateStatistics($batches, $connection, $queueFactory, $current_time) {
    $stats = [
      'total_batches' => 0,
      'pending_batches' => 0,
      'running_batches' => 0,
      'completed_batches' => 0,
      'total_items' => 0,
      'total_processed_items' => 0,
      'total_pending_items' => 0,
      'next_batch_run_time' => 0,
    ];

    $next_run_times = [];

    foreach ($batches as $batch) {
      $stats['total_batches']++;
      $stats['total_items'] += $batch->items_total;
      $stats['total_processed_items'] += $batch->items_processed;
      $stats['total_pending_items'] += ($batch->items_total - $batch->items_processed);

      // Count by status: 0 = Pending, 1 = Running, 2 = Done
      if ($batch->status == 0) {
        $stats['pending_batches']++;
        if ($batch->run_at > $current_time) {
          $next_run_times[] = $batch->run_at;
        }
      } elseif ($batch->status == 1) {
        $stats['running_batches']++;
      } elseif ($batch->status == 2) {
        $stats['completed_batches']++;
      }

      // Also check actual queue items
      try {
        $queue = $queueFactory->get($batch->queue_name);
        $number_of_items = $queue->numberOfItems();
        // Queue might have more items than what's tracked in batch (edge case)
      } catch (\Exception $e) {
        // Queue doesn't exist or error
      }
    }

    // Find the earliest next run time
    if (!empty($next_run_times)) {
      $stats['next_batch_run_time'] = min($next_run_times);
    }

    return $stats;
  }

  /**
   * Get status badge HTML.
   */
  protected function getStatusBadge($status) {
    $statuses = [
      0 => ['label' => $this->t('Pending'), 'class' => 'status-badge--pending'],
      1 => ['label' => $this->t('Running'), 'class' => 'status-badge--running'],
      2 => ['label' => $this->t('Completed'), 'class' => 'status-badge--completed'],
    ];

    $status_info = $statuses[$status] ?? ['label' => $this->t('Unknown'), 'class' => 'status-badge--unknown'];
    
    return '<span class="status-badge ' . $status_info['class'] . '">' . 
      $status_info['label'] . 
      '</span>';
  }

  /**
   * Get action links for a batch.
   */
  protected function getActionLinks($batch) {
    $links = [];
    
    // View queue items link (could be enhanced to show queue items)
    if ($batch->status != 2) {
      $links[] = Link::createFromRoute(
        $this->t('View'),
        'queue_sync.generate',
        [],
        ['attributes' => ['class' => ['action-link']]]
      )->toString();
    }

    return '<div class="action-links">' . implode(' ', $links) . '</div>';
  }

  /**
   * Get scheduled/next run display based on batch status.
   */
  protected function getScheduledDisplay($batch, $next_cron_time, $items_per_run, $current_time, $cron_interval = 3600) {
    // For running batches, show next run time
    if ($batch->status == 1) {
      $remaining_items = $batch->items_total - $batch->items_processed;
      
      if ($remaining_items > 0 && $next_cron_time > 0) {
        $runs_needed = ceil($remaining_items / $items_per_run);
        
        $time_until_next = $next_cron_time - $current_time;
        $hours = floor($time_until_next / 3600);
        $minutes = floor(($time_until_next % 3600) / 60);
        
        // Format countdown nicely
        $countdown_text = '';
        if ($hours > 0) {
          $countdown_text = $this->t('@hours h @min min', ['@hours' => $hours, '@min' => $minutes]);
        } elseif ($minutes > 0) {
          $countdown_text = $this->t('@min minutes', ['@min' => $minutes]);
        } else {
          $countdown_text = $this->t('Less than 1 minute');
        }
        
        return '<div class="datetime-cell datetime-cell--next-run">' .
          '<div class="next-run-label">' . $this->t('Next Run:') . '</div>' .
          '<div class="next-run-time">' . date('Y-m-d H:i:s', $next_cron_time) . '</div>' .
          '<small class="next-run-countdown">' . $countdown_text . '</small>' .
          '<small class="next-run-info">' . 
            $this->t('Est. @runs run(s) remaining', ['@runs' => $runs_needed]) .
          '</small>' .
          '</div>';
      } else {
        return '<div class="datetime-cell datetime-cell--next-run">' .
          '<div class="next-run-time">' . $this->t('Completing soon...') . '</div>' .
          '</div>';
      }
    }
    
    // For pending batches, show scheduled time
    if ($batch->status == 0) {
      $is_past = $batch->run_at <= $current_time;
      $label = $is_past ? $this->t('Ready to run') : $this->t('Scheduled');
      
      return '<div class="datetime-cell">' . 
        '<div class="scheduled-label">' . $label . ':</div>' .
        date('Y-m-d', $batch->run_at) . '<br>' .
        '<small>' . date('H:i:s', $batch->run_at) . '</small>' .
        '</div>';
    }
    
    // For completed batches, show original scheduled time
    return '<div class="datetime-cell">' . 
      date('Y-m-d', $batch->run_at) . '<br>' .
      '<small>' . date('H:i:s', $batch->run_at) . '</small>' .
      '</div>';
  }

  /**
   * Get the next cron run time.
   */
  protected function getNextCronTime() {
    $state = \Drupal::state();
    $last_cron = $state->get('system.cron_last');
    $current_time = \Drupal::time()->getCurrentTime();
    
    // Try to get automated_cron interval if enabled
    $cron_interval = 3600; // Default to 1 hour
    $module_handler = \Drupal::moduleHandler();
    if ($module_handler->moduleExists('automated_cron')) {
      $automated_cron_config = \Drupal::config('automated_cron.settings');
      $cron_interval = (int) $automated_cron_config->get('interval');
      if ($cron_interval <= 0) {
        $cron_interval = 3600; // Fallback to 1 hour
      }
    }
    
    // Calculate next cron run
    if ($last_cron) {
      $next_cron = $last_cron + $cron_interval;
      // If next cron is in the past, assume it will run very soon
      // (either cron is overdue or external cron is being used)
      if ($next_cron < $current_time) {
        // If it's been less than 15 minutes since last cron, estimate 5 min from now
        // Otherwise, assume it's overdue and will run within next cron interval
        $time_since_last = $current_time - $last_cron;
        if ($time_since_last < 900) { // Less than 15 minutes
          $next_cron = $current_time + 300; // 5 minutes from now
        } else {
          // Estimate based on typical cron schedule (every hour for most setups)
          $next_cron = $current_time + 3600;
        }
      }
    } else {
      // If cron has never run, estimate next run soon
      $next_cron = $current_time + 300; // 5 minutes from now
    }
    
    return $next_cron;
  }

  /**
   * Get the cron interval in seconds.
   */
  protected function getCronInterval() {
    $cron_interval = 3600; // Default to 1 hour
    $module_handler = \Drupal::moduleHandler();
    if ($module_handler->moduleExists('automated_cron')) {
      $automated_cron_config = \Drupal::config('automated_cron.settings');
      $cron_interval = (int) $automated_cron_config->get('interval');
      if ($cron_interval <= 0) {
        $cron_interval = 3600; // Fallback to 1 hour
      }
    }
    return $cron_interval;
  }
}
