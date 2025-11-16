<?php

namespace Drupal\custom_rest_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Psr\Log\LoggerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * Provides a Customer Profile Sync Resource with JWT Authentication.
 *
 * This resource queues customer profiles for sync using queue_sync module.
 *
 * @RestResource(
 *   id = "customer_profile_sync_resource",
 *   label = @Translation("Customer Profile Sync Resource"),
 *   uri_paths = {
 *     "create" = "/api/tailor/customer-profile-sync"
 *   }
 * )
 */
class CustomerProfileSyncResource extends ResourceBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The queue factory.
   *
   * @var \Drupal\Core\Queue\QueueFactory
   */
  protected $queueFactory;

  /**
   * Constructs a new CustomerProfileSyncResource object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param array $serializer_formats
   *   The available serialization formats.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   A current user instance.
   * @param \Drupal\Core\Queue\QueueFactory $queue_factory
   *   The queue factory.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    AccountProxyInterface $current_user,
    QueueFactory $queue_factory
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->currentUser = $current_user;
    $this->queueFactory = $queue_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->getParameter('serializer.formats'),
      $container->get('logger.factory')->get('custom_rest_api'),
      $container->get('current_user'),
      $container->get('queue')
    );
  }

  /**
   * POST: Queue customer profiles for sync.
   *
   * @param array $data
   *   The data array containing profiles to sync.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The response.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   Thrown when request data is invalid.
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when user doesn't have permission.
   */
  public function post(array $data) {
    // Check if user is authenticated
    if ($this->currentUser->isAnonymous()) {
      $this->logger->error('Access denied: User is anonymous for customer profile sync');
      throw new AccessDeniedHttpException('Authentication required.');
    }

    $this->logger->info('Customer profile sync request from authenticated user: @username', [
      '@username' => $this->currentUser->getAccountName(),
      'uid' => (int) $this->currentUser->id(),
    ]);

    // Validate request data
    if (empty($data['profiles']) || !is_array($data['profiles'])) {
      $this->logger->warning('Customer profile sync request with invalid profiles data');
      throw new BadRequestHttpException('Profiles array is required.');
    }

    $profiles = $data['profiles'];
    $total_profiles = count($profiles);

    if ($total_profiles === 0) {
      throw new BadRequestHttpException('At least one profile is required.');
    }

    // Prepare profiles for queuing
    $items_to_queue = [];
    foreach ($profiles as $profile_data) {
      if (empty($profile_data['data']) || !is_array($profile_data['data'])) {
        $this->logger->warning('Skipping invalid profile data');
        continue;
      }

      $action = $profile_data['action'] ?? 'create'; // 'create', 'update', or 'delete'
      
      // For delete action, queue to delete worker; otherwise use sync worker
      if ($action === 'delete') {
        // Prepare deletion item for delete queue
        $delete_item = [
          'field_phone' => $profile_data['data']['field_phone'] ?? NULL,
          'field_local_app_unique_id' => $profile_data['field_local_app_unique_id'] ?? NULL,
          'backend_id' => $profile_data['backend_id'] ?? NULL,
          'uid' => $this->currentUser->id(),
          'username' => $this->currentUser->getAccountName(),
        ];
        
        // Validate delete item has required fields
        if (empty($delete_item['field_phone']) && empty($delete_item['backend_id']) && empty($delete_item['field_local_app_unique_id'])) {
          $this->logger->warning('Skipping invalid delete item: missing identification fields');
          continue;
        }
        
        $items_to_queue[] = [
          'item' => $delete_item,
          'action' => 'delete',
        ];
      }
      else {
        // Prepare item for sync queue (create/update)
        $item = [
          'profile_data' => $profile_data['data'],
          'action' => $action,
          'field_local_app_unique_id' => $profile_data['field_local_app_unique_id'] ?? NULL,
          'backend_id' => $profile_data['backend_id'] ?? NULL,
          'uid' => $this->currentUser->id(),
          'username' => $this->currentUser->getAccountName(),
        ];

        $items_to_queue[] = [
          'item' => $item,
          'action' => $action,
        ];
      }
    }

    if (empty($items_to_queue)) {
      throw new BadRequestHttpException('No valid profiles to queue.');
    }

    try {
      // Get queue_sync settings
      $config = \Drupal::config('queue_sync.settings');
      $items_per_run = $config->get('items_per_run') ?? 5;
      $run_at = time(); // Queue to run immediately

      // Create batch record in qs_batches table for tracking in admin dashboard
      $batch_id = 'customer_profile_' . \Drupal::service('uuid')->generate();
      $queue_name = 'queue_sync_' . $batch_id;
      $total_items = count($items_to_queue);

      // Use helper to create batch record
      $batch_progress_helper = \Drupal::service('queue_sync.batch_progress_helper');
      $batch_progress_helper->createBatchRecord($batch_id, $queue_name, $total_items, $run_at);

      // Queue items to appropriate workers
      $sync_queue = $this->queueFactory->get('customer_profile_sync_worker');
      $delete_queue = $this->queueFactory->get('customer_profile_delete_worker');
      
      foreach ($items_to_queue as $queue_item) {
        $action = $queue_item['action'];
        $item = $queue_item['item'];
        
        // Add batch_id to item for tracking and batch status updates
        $item['batch_id'] = $batch_id;
        
        if ($action === 'delete') {
          // Queue to delete worker
          $delete_queue->createItem($item);
        }
        else {
          // Queue to sync worker (create/update)
          $sync_queue->createItem($item);
        }
      }

      $this->logger->info('Queued @count customer profiles for sync in batch @batch_id', [
        '@count' => $total_items,
        '@batch_id' => $batch_id,
      ]);

      // Return success response with batch information
      return new ResourceResponse([
        'success' => TRUE,
        'message' => 'Profiles queued for sync successfully',
        'profiles_queued' => $total_items,
        'batches_created' => 1,
        'batch_id' => $batch_id,
        'view_dashboard' => '/admin/config/queue-sync',
      ], 200);
    }
    catch (\Exception $e) {
      $this->logger->error('Error queuing customer profiles: @message', [
        '@message' => $e->getMessage(),
      ]);

      throw new BadRequestHttpException('Failed to queue profiles: ' . $e->getMessage());
    }
  }

}

