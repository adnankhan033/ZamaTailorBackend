<?php

namespace Drupal\queue_sync\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\node\Entity\NodeType;

class QueueSyncSettingsForm extends ConfigFormBase {
  
  public function getFormId() {
    return 'queue_sync_settings_form';
  }

  protected function getEditableConfigNames() {
    return ['queue_sync.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('queue_sync.settings');
    $current_time = \Drupal::time()->getCurrentTime();
    $formatter = \Drupal::service('date.formatter');
    
    // Current time display
    $form['current_time'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Current Server Time'),
      '#description' => $this->t('This is the current server time. Use this as a reference when setting processing delays.'),
    ];
    
    $form['current_time']['display'] = [
      '#type' => 'markup',
      '#markup' => '<div class="current-time-display">' .
        '<strong>' . $this->t('Current Time:') . '</strong> ' .
        '<span class="current-time-value">' . $formatter->format($current_time, 'custom', 'Y-m-d H:i:s') . '</span>' .
        '<br><small>' . $this->t('Timezone: @tz', ['@tz' => date_default_timezone_get()]) . '</small>' .
        '</div>',
    ];
    
    // Processing settings
    $form['processing'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Processing Settings'),
      '#description' => $this->t('Configure how batches are processed and how many items are created per run.'),
    ];
    
    $form['processing']['items_per_run'] = [
      '#type' => 'number',
      '#title' => $this->t('Items to Process Per Run'),
      '#description' => $this->t('The maximum number of queue items processed per cron run for each batch. <strong>Smart behavior:</strong> If remaining items are less than or equal to this limit, ALL remaining items will be processed to complete the batch immediately. Recommended: 50-200 for large datasets.'),
      '#default_value' => $config->get('items_per_run') ?? 50,
      '#min' => 1,
      '#max' => 1000,
      '#required' => TRUE,
      '#field_suffix' => $this->t('items'),
    ];

    $form['processing']['bulk_process_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Bulk Process Size'),
      '#description' => $this->t('Number of items to process together in a single bulk operation. Higher values improve performance but use more memory.'),
      '#default_value' => $config->get('bulk_process_size') ?? 10,
      '#min' => 1,
      '#max' => 100,
      '#required' => TRUE,
      '#field_suffix' => $this->t('items'),
    ];

    $form['processing']['memory_threshold_mb'] = [
      '#type' => 'number',
      '#title' => $this->t('Memory Threshold (MB)'),
      '#description' => $this->t('Maximum memory increase (in MB) before stopping batch processing. This prevents memory exhaustion with large datasets.'),
      '#default_value' => $config->get('memory_threshold_mb') ?? 128,
      '#min' => 64,
      '#max' => 1024,
      '#required' => TRUE,
      '#field_suffix' => $this->t('MB'),
    ];

    $form['processing']['user_chunk_size'] = [
      '#type' => 'number',
      '#title' => $this->t('User Chunk Size'),
      '#description' => $this->t('Number of users to fetch and queue per chunk when creating sync batches. Lower values use less memory.'),
      '#default_value' => $config->get('user_chunk_size') ?? 100,
      '#min' => 10,
      '#max' => 1000,
      '#required' => TRUE,
      '#field_suffix' => $this->t('users'),
    ];

    // Capacity management settings.
    $form['capacity'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Capacity & Auto-Chunking Settings'),
      '#description' => $this->t('Configure automatic chunking for large datasets to prevent memory exhaustion.'),
    ];

    $form['capacity']['max_memory_usage_mb'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Memory Usage Per Batch (MB)'),
      '#description' => $this->t('Maximum memory that can be used per batch. If data exceeds this, it will be automatically split into multiple batches. Current PHP limit: @limit', [
        '@limit' => ini_get('memory_limit'),
      ]),
      '#default_value' => $config->get('max_memory_usage_mb') ?? 256,
      '#min' => 64,
      '#max' => 2048,
      '#required' => TRUE,
      '#field_suffix' => $this->t('MB'),
    ];

    $form['capacity']['min_chunk_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Minimum Chunk Size'),
      '#description' => $this->t('Minimum number of items per chunk when auto-chunking. Prevents creating too many small batches.'),
      '#default_value' => $config->get('min_chunk_size') ?? 10,
      '#min' => 1,
      '#max' => 100,
      '#required' => TRUE,
      '#field_suffix' => $this->t('items'),
    ];

    $form['capacity']['max_chunk_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Chunk Size'),
      '#description' => $this->t('Maximum number of items per chunk. Prevents creating batches that are too large.'),
      '#default_value' => $config->get('max_chunk_size') ?? 1000,
      '#min' => 100,
      '#max' => 10000,
      '#required' => TRUE,
      '#field_suffix' => $this->t('items'),
    ];
    
    // Delay configuration - minutes only with minutes info display
    $default_delay_minutes = $config->get('default_delay_minutes') ?? 1;
    
    $form['processing']['default_delay_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Default Delay Before Processing (Minutes)'),
      '#description' => $this->t('Default delay in minutes before a batch starts processing. This can be overridden when generating batches.'),
      '#default_value' => $default_delay_minutes,
      '#min' => 0,
      '#step' => 0.5,
      '#required' => TRUE,
      '#field_suffix' => $this->t('minutes'),
      '#attributes' => ['class' => ['delay-minutes']],
      '#suffix' => '<div class="delay-minutes-info">' .
        '<small><strong>' . $this->t('Info:') . '</strong> ' .
        '<span id="delay-minutes-display">' . $this->formatMinutes($default_delay_minutes) . '</span>' .
        '</small></div>',
    ];
    
    // Node creation settings
    $form['node_creation'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Node Creation Settings'),
      '#description' => $this->t('Configure how nodes are created when processing queue items.'),
    ];
    
    // Get available node types
    $node_types = NodeType::loadMultiple();
    $node_type_options = [];
    foreach ($node_types as $node_type) {
      $node_type_options[$node_type->id()] = $node_type->label();
    }
    
    if (!empty($node_type_options)) {
      $form['node_creation']['node_type'] = [
        '#type' => 'select',
        '#title' => $this->t('Node Type'),
        '#description' => $this->t('Select the node type to create when processing queue items.'),
        '#options' => $node_type_options,
        '#default_value' => $config->get('node_type') ?? 'page',
        '#required' => TRUE,
      ];
    } else {
      $form['node_creation']['node_type'] = [
        '#type' => 'markup',
        '#markup' => '<div class="messages messages--warning">' .
          $this->t('No node types available. Please create at least one content type.') .
          '</div>',
      ];
    }
    
    $form['node_creation']['auto_publish'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Automatically Publish Nodes'),
      '#description' => $this->t('If checked, created nodes will be published immediately. Otherwise, they will be saved as unpublished.'),
      '#default_value' => $config->get('auto_publish') ?? TRUE,
    ];
    
    // Batch settings
    $form['batch'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Batch Processing Settings'),
      '#description' => $this->t('Configure batch processing behavior.'),
    ];
    
    $form['batch']['max_batches_per_run'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum Batches Processed Per Cron Run'),
      '#description' => $this->t('Maximum number of batches that will be processed in a single cron run. This prevents overloading the system.'),
      '#default_value' => $config->get('max_batches_per_run') ?? 20,
      '#min' => 1,
      '#max' => 100,
      '#required' => TRUE,
    ];
    
    // Information section
    $form['info'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('How It Works'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];
    
    $form['info']['content'] = [
      '#type' => 'markup',
      '#markup' => '<div class="queue-sync-info">' .
        '<ul>' .
        '<li><strong>' . $this->t('Batches:') . '</strong> ' . 
        $this->t('Batches are created with a scheduled run time. Items are added to a queue for each batch.') . '</li>' .
        '<li><strong>' . $this->t('Processing:') . '</strong> ' . 
        $this->t('When cron runs, batches that are due (run_at time has passed) will be processed.') . '</li>' .
        '<li><strong>' . $this->t('Items Per Run:') . '</strong> ' . 
        $this->t('Only the specified number of items are processed per batch per cron run. Remaining items wait for the next cron run.') . '</li>' .
        '<li><strong>' . $this->t('Node Creation:') . '</strong> ' . 
        $this->t('Each processed queue item creates a node of the selected type with the item data (title, body, etc.).') . '</li>' .
        '<li><strong>' . $this->t('Status:') . '</strong> ' . 
        $this->t('Batches can be Pending (not started), Running (processing), or Completed (all items processed).') . '</li>' .
        '</ul>' .
        '</div>',
    ];
    
    // Attach library for styling
    $form['#attached']['library'][] = 'queue_sync/settings';
    
    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('queue_sync.settings');
    
    $config
      ->set('items_per_run', (int) $form_state->getValue('items_per_run'))
      ->set('default_delay_minutes', (int) $form_state->getValue('default_delay_minutes'))
      ->set('max_batches_per_run', (int) $form_state->getValue('max_batches_per_run'))
      ->set('bulk_process_size', (int) $form_state->getValue('bulk_process_size'))
      ->set('memory_threshold_mb', (int) $form_state->getValue('memory_threshold_mb'))
      ->set('user_chunk_size', (int) $form_state->getValue('user_chunk_size'))
      ->set('max_memory_usage_mb', (int) $form_state->getValue('max_memory_usage_mb'))
      ->set('min_chunk_size', (int) $form_state->getValue('min_chunk_size'))
      ->set('max_chunk_size', (int) $form_state->getValue('max_chunk_size'))
      ->set('auto_publish', (bool) $form_state->getValue('auto_publish'));
    
    // Only set node_type if it's available
    if ($form_state->hasValue('node_type')) {
      $config->set('node_type', $form_state->getValue('node_type'));
    }
    
    $config->save();
    
    parent::submitForm($form, $form_state);
    
    $this->messenger()->addMessage($this->t('Queue Sync settings have been saved successfully.'));
  }
  
  /**
   * Format minutes value for display in info.
   */
  protected function formatMinutes($minutes) {
    if ($minutes == 0.5) {
      return $this->t('half minute (30 seconds)');
    }
    if ($minutes == 1) {
      return $this->t('1 minute');
    }
    if ($minutes < 1) {
      $seconds = $minutes * 60;
      return $this->t('@minutes minutes (@seconds seconds)', [
        '@minutes' => $minutes,
        '@seconds' => $seconds,
      ]);
    }
    if (floor($minutes) == $minutes) {
      return $this->t('@minutes minutes', ['@minutes' => $minutes]);
    }
    $seconds = round(($minutes - floor($minutes)) * 60);
    $whole_minutes = floor($minutes);
    if ($seconds == 30) {
      return $this->t('@minutes and a half minutes', ['@minutes' => $whole_minutes]);
    }
    return $this->t('@minutes minutes (@seconds seconds)', [
      '@minutes' => $whole_minutes,
      '@seconds' => $seconds,
    ]);
  }
}
