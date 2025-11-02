<?php

namespace Drupal\custom_queue_processor\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form to process a specific queue item by ID.
 */
class ProcessQueueItemForm extends FormBase {

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
   * Constructs a ProcessQueueItemForm object.
   *
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(QueueFactory $queue_factory, ConfigFactoryInterface $config_factory) {
    $this->queueFactory = $queue_factory;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('queue'),
      $container->get('config.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'process_queue_item_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->configFactory->get('custom_queue_processor.settings');
    $processing_interval = $config->get('processing_interval') ?? 60;

    // Check if item_id was passed in query string.
    $request = \Drupal::request();
    $query_item_id = $request->query->get('item_id');
    $default_item_id = '';
    if ($query_item_id) {
      $default_item_id = $query_item_id;
    }

    $form['#attributes']['class'][] = 'process-item-form';

    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--info"><p><strong>' . 
        $this->t('Process Specific Queue Item') . '</strong><br>' .
        $this->t('Enter a queue item ID below to process a specific record. Only items that have waited at least @interval minutes can be processed.', 
        ['@interval' => $processing_interval]) . 
        '</p></div>',
    ];

    // Get available queue items for reference.
    $database = \Drupal::database();
    $items = $database->select('queue', 'q')
      ->fields('q', ['item_id', 'data', 'created'])
      ->condition('q.name', 'custom_queue_processor')
      ->orderBy('q.created', 'DESC')
      ->range(0, 10)
      ->execute()
      ->fetchAll();

    if (!empty($items)) {
      $options = [];
      foreach ($items as $item) {
        $data = unserialize($item->data);
        $created_time = $item->created;
        $current_time = \Drupal::time()->getCurrentTime();
        $time_diff = $created_time + ($processing_interval * 60) - $current_time;
        
        $status = ($time_diff <= 0) ? '✓ Ready' : '⏳ Pending';
        $title = $data['title'] ?? 'N/A';
        $options[$item->item_id] = "ID: {$item->item_id} - {$title} ({$status})";
      }

      $form['quick_select'] = [
        '#type' => 'select',
        '#title' => $this->t('Or select from recent items'),
        '#options' => ['0' => $this->t('- Select an item -')] + $options,
        '#description' => $this->t('Select a recent queue item to quickly fill in the ID field.'),
      ];
    }

    $form['item_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Queue Item ID'),
      '#description' => $this->t('Enter the ID of the queue item you want to process.'),
      '#required' => TRUE,
      '#min' => 1,
      '#step' => 1,
      '#default_value' => $default_item_id,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Process Item'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => Url::fromRoute('custom_queue_processor.list'),
      '#attributes' => ['class' => ['button']],
    ];

    // Add AJAX handler for quick select.
    if (!empty($items)) {
      $form['quick_select']['#ajax'] = [
        'callback' => '::ajaxUpdateItemId',
        'wrapper' => 'item-id-wrapper',
        'event' => 'change',
      ];

      $form['item_id']['#prefix'] = '<div id="item-id-wrapper">';
      $form['item_id']['#suffix'] = '</div>';
    }

    $form['#attached']['library'][] = 'custom_queue_processor/queue_processor';

    return $form;
  }

  /**
   * AJAX callback to update item ID field.
   */
  public function ajaxUpdateItemId(array &$form, FormStateInterface $form_state) {
    $selected_id = $form_state->getValue('quick_select');
    if ($selected_id && $selected_id != '0') {
      $form['item_id']['#value'] = $selected_id;
    }
    return $form['item_id'];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $item_id = $form_state->getValue('item_id');

    // Check if quick select was used.
    $quick_select = $form_state->getValue('quick_select');
    if ($quick_select && $quick_select != '0') {
      $form_state->setValue('item_id', $quick_select);
      $item_id = $quick_select;
    }

    if (!$item_id) {
      $form_state->setErrorByName('item_id', $this->t('Please enter a valid queue item ID.'));
      return;
    }

    // Check if item exists.
    $database = \Drupal::database();
    $item = $database->select('queue', 'q')
      ->fields('q', ['item_id', 'data', 'created'])
      ->condition('q.item_id', $item_id)
      ->condition('q.name', 'custom_queue_processor')
      ->execute()
      ->fetchObject();

    if (!$item) {
      $form_state->setErrorByName('item_id', $this->t('Queue item with ID @id does not exist.', ['@id' => $item_id]));
      return;
    }

    // Check if item is ready for processing.
    $config = $this->configFactory->get('custom_queue_processor.settings');
    $processing_interval = $config->get('processing_interval') ?? 60;
    $created_time = $item->created;
    $current_time = \Drupal::time()->getCurrentTime();
    $time_diff = $created_time + ($processing_interval * 60) - $current_time;

    if ($time_diff > 0) {
      $minutes = round($time_diff / 60);
      $form_state->setErrorByName('item_id', $this->t('This item is not ready for processing yet. Wait @minutes more minute(s).', ['@minutes' => $minutes]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $item_id = $form_state->getValue('item_id');

    // Check if quick select was used.
    $quick_select = $form_state->getValue('quick_select');
    if ($quick_select && $quick_select != '0') {
      $item_id = $quick_select;
    }

    // Redirect to process the item.
    $url = Url::fromRoute('custom_queue_processor.process', ['item_id' => $item_id]);
    $form_state->setRedirectUrl($url);
  }

}

