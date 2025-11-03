<?php

namespace Drupal\queue_sync\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\queue_sync\Service\UserSyncService;
use Drupal\queue_sync\Service\BatchRunner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for syncing user records.
 */
class SyncUsersForm extends FormBase {

  /**
   * The user sync service.
   *
   * @var \Drupal\queue_sync\Service\UserSyncService
   */
  protected $userSyncService;

  /**
   * The batch runner service.
   *
   * @var \Drupal\queue_sync\Service\BatchRunner
   */
  protected $batchRunner;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a SyncUsersForm object.
   *
   * @param \Drupal\queue_sync\Service\UserSyncService $user_sync_service
   *   The user sync service.
   * @param \Drupal\queue_sync\Service\BatchRunner $batch_runner
   *   The batch runner service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(UserSyncService $user_sync_service, BatchRunner $batch_runner, ConfigFactoryInterface $config_factory) {
    $this->userSyncService = $user_sync_service;
    $this->batchRunner = $batch_runner;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('queue_sync.user_sync'),
      $container->get('queue_sync.runner'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'queue_sync_sync_users_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->configFactory->get('queue_sync.settings');
    $current_time = \Drupal::time()->getCurrentTime();
    $formatter = \Drupal::service('date.formatter');

    // Show statistics.
    $total_users = $this->userSyncService->getUserCount(['status' => 1]);
    $active_users = $this->userSyncService->getUserCount(['status' => 1]);
    $inactive_users = $this->userSyncService->getUserCount(['status' => 0]);

    // Get capacity information.
    try {
      $capacity_manager = \Drupal::service('queue_sync.capacity_manager');
      $capacity_report = $capacity_manager->getCapacityReport();
      $limits = $capacity_report['system_limits'];
    }
    catch (\Exception $e) {
      // Service not available, use defaults.
      $limits = [
        'memory_limit_str' => ini_get('memory_limit'),
        'memory_limit_mb' => 256,
        'current_memory_mb' => round(memory_get_usage(TRUE) / 1024 / 1024, 2),
        'available_memory_mb' => 256,
        'max_execution_time' => ini_get('max_execution_time'),
      ];
    }

    $form['statistics'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('User Statistics'),
    ];

    $form['statistics']['info'] = [
      '#type' => 'markup',
      '#markup' => '<div class="user-sync-statistics">' .
        '<p><strong>' . $this->t('Total Users:') . '</strong> ' . $total_users . '</p>' .
        '<p><strong>' . $this->t('Active Users:') . '</strong> ' . $active_users . '</p>' .
        '<p><strong>' . $this->t('Inactive Users:') . '</strong> ' . $inactive_users . '</p>' .
        '</div>',
    ];

    // Show capacity information.
    $form['capacity_info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('System Capacity Information'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];

    $capacity_info_html = '<div class="capacity-info">' .
      '<p><strong>' . $this->t('PHP Memory Limit:') . '</strong> ' . $limits['memory_limit_str'] . 
      ' (' . $limits['memory_limit_mb'] . ' MB)' . '</p>' .
      '<p><strong>' . $this->t('Current Memory Usage:') . '</strong> ' . $limits['current_memory_mb'] . ' MB' . '</p>' .
      '<p><strong>' . $this->t('Available Memory:') . '</strong> ' . $limits['available_memory_mb'] . ' MB' . '</p>' .
      '<p><strong>' . $this->t('Max Execution Time:') . '</strong> ' . 
      ($limits['max_execution_time'] ? $limits['max_execution_time'] . ' seconds' : $this->t('Unlimited')) . '</p>' .
      '<p class="capacity-note"><em>' . $this->t('Large datasets will be automatically split into multiple batches to prevent memory exhaustion.') . '</em></p>' .
      '</div>';

    $form['capacity_info']['content'] = [
      '#type' => 'markup',
      '#markup' => $capacity_info_html,
    ];

    // Sync options.
    $form['sync_options'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Sync Options'),
    ];

    $form['sync_options']['sync_type'] = [
      '#type' => 'radios',
      '#title' => $this->t('Sync Type'),
      '#options' => [
        'all' => $this->t('Sync all users'),
        'active' => $this->t('Sync active users only'),
        'inactive' => $this->t('Sync inactive users only'),
        'modified' => $this->t('Sync only modified users (since last sync)'),
      ],
      '#default_value' => 'active',
      '#required' => TRUE,
    ];

    $default_delay = $config->get('default_delay_minutes') ?? 1;
    $user_chunk_size = $config->get('user_chunk_size') ?? 100;

    $form['sync_options']['delay_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Delay (minutes) before processing'),
      '#description' => $this->t('Number of minutes to wait before this batch starts processing.'),
      '#default_value' => $default_delay,
      '#min' => 0,
      '#step' => 0.5,
      '#required' => TRUE,
      '#field_suffix' => $this->t('minutes'),
    ];

    $form['sync_options']['chunk_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Users per batch chunk'),
      '#description' => $this->t('Number of users to include in each batch chunk. Recommended: 100-500 for large datasets.'),
      '#default_value' => $user_chunk_size,
      '#min' => 10,
      '#max' => 1000,
      '#required' => TRUE,
      '#field_suffix' => $this->t('users'),
    ];

    $form['current_time'] = [
      '#type' => 'markup',
      '#markup' => '<div class="current-time-info">' .
        '<strong>' . $this->t('Current Time:') . '</strong> ' .
        $formatter->format($current_time, 'custom', 'Y-m-d H:i:s') .
        '</div>',
    ];

    $form['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Queue User Sync'),
    ];

    $form['#attached']['library'][] = 'queue_sync/settings';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Security: Validate and sanitize form inputs.
    $sync_type = $form_state->getValue('sync_type');
    $allowed_sync_types = ['all', 'active', 'inactive', 'modified'];
    if (!in_array($sync_type, $allowed_sync_types)) {
      $sync_type = 'active'; // Default to safe value.
    }
    
    $delay_minutes = (float) $form_state->getValue('delay_minutes');
    // Security: Ensure delay is within reasonable bounds (0 to 10080 minutes = 1 week max).
    $delay_minutes = max(0, min(10080, $delay_minutes));
    
    $chunk_size = (int) $form_state->getValue('chunk_size');
    // Security: Ensure chunk size is within reasonable bounds.
    $chunk_size = max(10, min(1000, $chunk_size));
    $run_at = time() + ($delay_minutes * 60);
    $config = $this->configFactory->get('queue_sync.settings');

    // Determine conditions based on sync type.
    $conditions = [];
    if ($sync_type === 'active') {
      $conditions['status'] = 1;
    }
    elseif ($sync_type === 'inactive') {
      $conditions['status'] = 0;
    }

    $total_users = $this->userSyncService->getUserCount($conditions);
    $batches_created = 0;
    $offset = 0;

    // Process users in chunks.
    while ($offset < $total_users) {
      if ($sync_type === 'modified') {
        // Get last sync timestamp from config.
        $last_sync = $config->get('last_sync_timestamp') ?? 0;
        $users = $this->userSyncService->getUsersNeedingSync($last_sync, $chunk_size, $offset);
      }
      else {
        $users = $this->userSyncService->getUserChunk($chunk_size, $offset, $conditions);
      }

      if (empty($users)) {
        break;
      }

      // Create batch for this chunk (auto-chunking enabled).
      $result = $this->batchRunner->createBatch($users, $run_at, $chunk_size, TRUE);
      
      // Handle both single batch ID and array of batch IDs (from auto-chunking).
      if (is_array($result)) {
        $batches_created += count($result);
      }
      else {
        $batches_created++;
      }
      
      $offset += $chunk_size;

      // Update progress for large syncs.
      if ($total_users > 1000) {
        $processed = min($offset, $total_users);
        \Drupal::logger('queue_sync')->info('Queued @processed of @total users for sync', [
          '@processed' => $processed,
          '@total' => $total_users,
        ]);
      }
    }

    // Update last sync timestamp.
    if ($sync_type === 'modified') {
      $config->set('last_sync_timestamp', time())->save();
    }

    $this->messenger()->addMessage($this->t('Queued @count users for sync in @batches batch(es).', [
      '@count' => $total_users,
      '@batches' => $batches_created,
    ]));

    $form_state->setRedirectUrl(Url::fromRoute('queue_sync.admin'));
  }

}

