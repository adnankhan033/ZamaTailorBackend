<?php

namespace Drupal\queue_sync\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\queue_sync\Service\BatchRunner;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for generating large amounts of test data.
 */
class GenerateLargeTestDataForm extends FormBase {

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
   * Constructs a GenerateLargeTestDataForm object.
   *
   * @param \Drupal\queue_sync\Service\BatchRunner $batch_runner
   *   The batch runner service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(BatchRunner $batch_runner, ConfigFactoryInterface $config_factory) {
    $this->batchRunner = $batch_runner;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('queue_sync.runner'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'queue_sync_generate_large_test_data_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->configFactory->get('queue_sync.settings');
    $current_time = \Drupal::time()->getCurrentTime();
    $formatter = \Drupal::service('date.formatter');

    $form['info'] = [
      '#type' => 'markup',
      '#markup' => '<div class="large-test-data-info">' .
        '<h3>' . $this->t('Generate Large Test Dataset') . '</h3>' .
        '<p>' . $this->t('Use this form to generate huge amounts of test data to verify auto-chunking and capacity management.') . '</p>' .
        '<p><strong>' . $this->t('The system will automatically:') . '</strong></p>' .
        '<ul>' .
        '<li>' . $this->t('Calculate memory requirements') . '</li>' .
        '<li>' . $this->t('Split large datasets into multiple batches') . '</li>' .
        '<li>' . $this->t('Process chunks sequentially to prevent memory exhaustion') . '</li>' .
        '</ul>' .
        '</div>',
    ];

    // Test data size options.
    $form['test_size'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Test Data Size'),
    ];

    $form['test_size']['preset'] = [
      '#type' => 'radios',
      '#title' => $this->t('Quick Presets'),
      '#options' => [
        'small' => $this->t('Small (1,000 items) - Single batch'),
        'medium' => $this->t('Medium (10,000 items) - ~10 batches'),
        'large' => $this->t('Large (50,000 items) - ~50 batches'),
        'huge' => $this->t('Huge (100,000 items) - ~100 batches'),
        'extreme' => $this->t('Extreme (500,000 items) - ~500 batches'),
        'custom' => $this->t('Custom amount'),
      ],
      '#default_value' => 'medium',
      '#required' => TRUE,
    ];

    $preset_sizes = [
      'small' => 1000,
      'medium' => 10000,
      'large' => 50000,
      'huge' => 100000,
      'extreme' => 500000,
    ];

    $form['test_size']['custom_amount'] = [
      '#type' => 'number',
      '#title' => $this->t('Custom Amount'),
      '#description' => $this->t('Enter custom number of items (1 to 1,000,000)'),
      '#default_value' => 10000,
      '#min' => 1,
      '#max' => 1000000,
      '#step' => 1,
      '#states' => [
        'visible' => [
          ':input[name="preset"]' => ['value' => 'custom'],
        ],
        'required' => [
          ':input[name="preset"]' => ['value' => 'custom'],
        ],
      ],
    ];

    // Data generation options.
    $form['options'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Data Options'),
    ];

    $default_delay = $config->get('default_delay_minutes') ?? 1;

    $form['options']['delay_minutes'] = [
      '#type' => 'number',
      '#title' => $this->t('Delay (minutes) before processing'),
      '#description' => $this->t('Number of minutes to wait before batches start processing.'),
      '#default_value' => $default_delay,
      '#min' => 0,
      '#step' => 0.5,
      '#required' => TRUE,
      '#field_suffix' => $this->t('minutes'),
    ];

    $form['options']['data_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Test Data Type'),
      '#options' => [
        'user_like' => $this->t('User-like records (uid, username, email)'),
        'mixed' => $this->t('Mixed data with various fields'),
        'minimal' => $this->t('Minimal data (just IDs)'),
      ],
      '#default_value' => 'user_like',
    ];

    // Capacity estimation.
    $form['capacity_estimation'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Estimated Capacity'),
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
    ];

    $form['capacity_estimation']['estimation_note'] = [
      '#type' => 'markup',
      '#markup' => '<div class="capacity-estimation">' .
        '<p>' . $this->t('Based on your current settings:') . '</p>' .
        '<ul>' .
        '<li>' . $this->t('Max Memory per Batch: @mb MB', [
          '@mb' => $config->get('max_memory_usage_mb') ?? 256,
        ]) . '</li>' .
        '<li>' . $this->t('Max Chunk Size: @size items', [
          '@size' => $config->get('max_chunk_size') ?? 1000,
        ]) . '</li>' .
        '</ul>' .
        '<p><em>' . $this->t('The system will automatically calculate optimal batch sizes based on your data and system capacity.') . '</em></p>' .
        '</div>',
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
      '#value' => $this->t('Generate Large Test Dataset'),
      '#attributes' => ['class' => ['button--primary']],
    ];

    $form['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('queue_sync.admin'),
      '#attributes' => ['class' => ['button']],
    ];

    $form['#attached']['library'][] = 'queue_sync/settings';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $preset = $form_state->getValue('preset');
    $delay_minutes = (float) $form_state->getValue('delay_minutes');
    $data_type = $form_state->getValue('data_type');
    $run_at = time() + ($delay_minutes * 60);

    // Determine number of items.
    $preset_sizes = [
      'small' => 1000,
      'medium' => 10000,
      'large' => 50000,
      'huge' => 100000,
      'extreme' => 500000,
    ];

    $total_items = ($preset === 'custom') 
      ? (int) $form_state->getValue('custom_amount')
      : $preset_sizes[$preset];

    // Generate test data.
    $items = $this->generateTestData($total_items, $data_type);

    // Create batch (with auto-chunking enabled).
    $this->logger('queue_sync')->info('Creating test batch with @count items', [
      '@count' => $total_items,
    ]);

    $result = $this->batchRunner->createBatch($items, $run_at, NULL, TRUE);

    // Handle both single batch ID and array of batch IDs.
    if (is_array($result)) {
      $batches_created = count($result);
      $message = $this->t('Created large test dataset with @count items split into @batches batches. The system automatically chunked the data to prevent memory issues.', [
        '@count' => $total_items,
        '@batches' => $batches_created,
      ]);
    }
    else {
      $batches_created = 1;
      $message = $this->t('Created test batch with @count items in a single batch.', [
        '@count' => $total_items,
      ]);
    }

    $this->messenger()->addMessage($message);
    $this->logger('queue_sync')->info('Test data generation complete: @batches batches', [
      '@batches' => $batches_created,
    ]);

    $form_state->setRedirectUrl(Url::fromRoute('queue_sync.admin'));
  }

  /**
   * Generate test data.
   *
   * @param int $count
   *   Number of items to generate.
   * @param string $type
   *   Data type: 'user_like', 'mixed', or 'minimal'.
   *
   * @return array
   *   Array of test data items.
   */
  protected function generateTestData(int $count, string $type) {
    $items = [];
    $timestamp = time();

    for ($i = 1; $i <= $count; $i++) {
      switch ($type) {
        case 'user_like':
          $items[] = [
            'uid' => $i,
            'username' => 'test_user_' . $i,
            'email' => 'test_user_' . $i . '@example.com',
            'status' => ($i % 2 == 0) ? 1 : 0,
            'created' => $timestamp - ($i * 60),
            'access' => $timestamp - (($i % 100) * 60),
            'login' => $timestamp - (($i % 50) * 60),
            'sync_timestamp' => $timestamp,
            'description' => 'Test user record #' . $i . ' generated for capacity testing.',
          ];
          break;

        case 'mixed':
          $items[] = [
            'uid' => $i,
            'username' => 'user_' . str_pad($i, 8, '0', STR_PAD_LEFT),
            'email' => 'user' . $i . '@test.example.com',
            'status' => ($i % 3 == 0) ? 0 : 1,
            'created' => $timestamp - ($i * 30),
            'access' => $timestamp - (($i % 200) * 30),
            'login' => $timestamp - (($i % 75) * 30),
            'sync_timestamp' => $timestamp,
            'metadata' => [
              'source' => 'test_generation',
              'batch_number' => floor($i / 1000) + 1,
              'sequence' => $i,
              'random_field' => md5('test_' . $i),
            ],
            'description' => 'Mixed test data record #' . $i,
          ];
          break;

        case 'minimal':
        default:
          $items[] = [
            'uid' => $i,
            'sync_timestamp' => $timestamp,
          ];
          break;
      }

      // Free memory periodically for huge datasets.
      if ($count > 10000 && $i % 10000 == 0) {
        \Drupal::logger('queue_sync')->info('Generated @current of @total test items', [
          '@current' => $i,
          '@total' => $count,
        ]);
      }
    }

    return $items;
  }


}

