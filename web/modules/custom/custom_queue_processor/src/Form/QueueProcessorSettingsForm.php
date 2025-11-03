<?php

namespace Drupal\custom_queue_processor\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configuration form for Queue Processor settings.
 */
class QueueProcessorSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['custom_queue_processor.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'custom_queue_processor_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('custom_queue_processor.settings');

    $form['processing_interval'] = [
      '#type' => 'select',
      '#title' => $this->t('Processing Interval'),
      '#description' => $this->t('Select the minimum time (in minutes) that must pass before a queue item can be processed.'),
      '#options' => [
        1 => $this->t('1 minute'),
        5 => $this->t('5 minutes'),
        10 => $this->t('10 minutes'),
        15 => $this->t('15 minutes'),
        30 => $this->t('30 minutes'),
        60 => $this->t('60 minutes'),
        120 => $this->t('120 minutes (2 hours)'),
        240 => $this->t('240 minutes (4 hours)'),
      ],
      '#default_value' => $config->get('processing_interval') ?? 60,
      '#required' => TRUE,
    ];

    $form['batch_size'] = [
      '#type' => 'number',
      '#title' => $this->t('Items to Process at a Time'),
      '#description' => $this->t('<strong>IMPORTANT:</strong> This is the EXACT number of items that will be processed per batch. Even if more items are ready, only this many will be processed each time. The remaining ready items will be processed in subsequent batches.'),
      '#default_value' => $config->get('batch_size') ?? 10,
      '#required' => TRUE,
      '#min' => 1,
      '#max' => 100,
      '#step' => 1,
      '#field_suffix' => $this->t('items'),
    ];

    $form['auto_processing'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Automatic Processing'),
      '#description' => $this->t('Queue items will be automatically processed when they become ready (after the processing interval has passed).'),
    ];

    $form['auto_processing']['info'] = [
      '#type' => 'markup',
      '#markup' => '<div class="messages messages--status"><p>' .
        $this->t('<strong>✓ Automatic Processing Enabled:</strong>') .
        '</p><ul>' .
        '<li>' . $this->t('Items start as <strong>Pending</strong> status when created') . '</li>' .
        '<li>' . $this->t('After the processing interval (e.g., 1 minute), items automatically change to <strong>Ready</strong> status') . '</li>' .
        '<li>' . $this->t('When cron runs, <strong>exactly @batch item(s)</strong> will be processed (not all ready items)', [
          '@batch' => $config->get('batch_size') ?? 10,
        ]) . '</li>' .
        '<li>' . $this->t('Basic Page nodes are created automatically for the processed items') . '</li>' .
        '<li>' . $this->t('Remaining ready items wait for the next cron run') . '</li>' .
        '</ul><p><em>' . 
        $this->t('Note: Make sure cron is configured to run regularly (e.g., every minute) for best results.') .
        '</em></p></div>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('custom_queue_processor.settings')
      ->set('processing_interval', $form_state->getValue('processing_interval'))
      ->set('batch_size', $form_state->getValue('batch_size'))
      ->save();

    parent::submitForm($form, $form_state);
    $this->messenger()->addMessage($this->t('Queue Processor settings have been saved.'));
  }

}

