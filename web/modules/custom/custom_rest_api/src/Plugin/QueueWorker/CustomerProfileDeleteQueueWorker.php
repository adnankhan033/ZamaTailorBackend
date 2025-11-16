<?php

namespace Drupal\custom_rest_api\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\node\Entity\Node;
use Drupal\queue_sync\Helper\BatchProgressHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * Queue worker for deleting customer profiles.
 *
 * @QueueWorker(
 *   id = "customer_profile_delete_worker",
 *   title = @Translation("Customer Profile Delete Worker"),
 *   cron = {"time" = 60}
 * )
 */
class CustomerProfileDeleteQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The batch progress helper.
   *
   * @var \Drupal\queue_sync\Helper\BatchProgressHelper
   */
  protected $batchProgressHelper;

  /**
   * Constructs a CustomerProfileDeleteQueueWorker object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\queue_sync\Helper\BatchProgressHelper $batch_progress_helper
   *   The batch progress helper service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger, BatchProgressHelper $batch_progress_helper) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger;
    $this->batchProgressHelper = $batch_progress_helper;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')->get('custom_rest_api'),
      $container->get('queue_sync.batch_progress_helper')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $batch_id = $data['batch_id'] ?? NULL;
    
    try {
      $nid = $data['nid'] ?? NULL;
      $field_phone = $data['field_phone'] ?? NULL;
      $field_local_app_unique_id = $data['field_local_app_unique_id'] ?? NULL;
      $backend_id = $data['backend_id'] ?? NULL;
      $uid = $data['uid'] ?? NULL;

      if (!$uid) {
        $this->logger->error('Invalid delete data in queue item: missing uid');
        $this->batchProgressHelper->updateBatchProgressByBatchId($batch_id, TRUE);
        return;
      }

      // Try to find the profile by backend_id first, then by field_local_app_unique_id, then by phone
      $customer_profile = NULL;
      
      if ($backend_id) {
        $customer_profile = Node::load($backend_id);
      }
      
      if (!$customer_profile && $field_local_app_unique_id) {
        $query = \Drupal::entityQuery('node')
          ->condition('type', 'customer_profile')
          ->condition('field_local_app_unique_id', $field_local_app_unique_id)
          ->condition('uid', $uid)
          ->accessCheck(FALSE)
          ->range(0, 1);
        $nids = $query->execute();
        if (!empty($nids)) {
          $customer_profile = Node::load(reset($nids));
        }
      }
      
      if (!$customer_profile && $field_phone) {
        $query = \Drupal::entityQuery('node')
          ->condition('type', 'customer_profile')
          ->condition('field_phone', $field_phone)
          ->condition('uid', $uid)
          ->accessCheck(FALSE)
          ->range(0, 1);
        $nids = $query->execute();
        if (!empty($nids)) {
          $customer_profile = Node::load(reset($nids));
        }
      }

      // Load the customer profile node if we have nid
      if (!$customer_profile && $nid) {
        $customer_profile = Node::load($nid);
      }

      if (!$customer_profile || $customer_profile->bundle() !== 'customer_profile') {
        $this->logger->warning('Customer profile not found for deletion. Searched by: backend_id=@backend_id, unique_id=@unique_id, phone=@phone', [
          '@backend_id' => $backend_id ?? 'N/A',
          '@unique_id' => $field_local_app_unique_id ?? 'N/A',
          '@phone' => $field_phone ?? 'N/A',
        ]);
        $this->batchProgressHelper->updateBatchProgressByBatchId($batch_id, TRUE);
        return;
      }

      // CRITICAL: Verify the node author matches the user who queued the deletion
      // This ensures users can only delete their own content
      if ($customer_profile->getOwnerId() != $uid) {
        $this->logger->warning('Access denied in queue worker: Profile @nid belongs to user @owner, but queue user is @current', [
          '@nid' => $customer_profile->id(),
          '@owner' => $customer_profile->getOwnerId(),
          '@current' => $uid,
        ]);
        $this->batchProgressHelper->updateBatchProgressByBatchId($batch_id, TRUE);
        return;
      }

      // Log before deletion
      $this->logger->info('Processing customer profile deletion from queue: @nid (phone: @phone, unique_id: @unique_id) by user @uid', [
        '@nid' => $customer_profile->id(),
        '@phone' => $field_phone ?? 'N/A',
        '@unique_id' => $field_local_app_unique_id ?? 'N/A',
        '@uid' => $uid,
      ]);

      // Delete the customer profile
      $customer_profile->delete();

      // Log successful deletion
      $this->logger->info('Customer profile deleted successfully from queue: @nid', [
        '@nid' => $nid,
      ]);

      // Update batch progress after successful processing
      $this->batchProgressHelper->updateBatchProgressByBatchId($batch_id, TRUE);
    }
    catch (\Exception $e) {
      $this->logger->error('Error processing customer profile deletion queue item: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->batchProgressHelper->updateBatchProgressByBatchId($batch_id, TRUE);
      throw $e;
    }
  }

}

