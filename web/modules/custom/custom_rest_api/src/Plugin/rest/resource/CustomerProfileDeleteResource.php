<?php

namespace Drupal\custom_rest_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Psr\Log\LoggerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Queue\QueueFactory;

/**
 * Provides a Customer Profile Delete Resource with JWT Authentication.
 *
 * @RestResource(
 *   id = "customer_profile_delete_resource",
 *   label = @Translation("Customer Profile Delete Resource"),
 *   uri_paths = {
 *     "canonical" = "/api/tailor/customer_profile_delete/{field_phone}"
 *   }
 * )
 */
class CustomerProfileDeleteResource extends ResourceBase {

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
   * Constructs a new CustomerProfileDeleteResource object.
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
   * DELETE: Delete a customer profile by phone number.
   *
   * @param string $field_phone
   *   The phone number of the customer profile to delete.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The response containing deletion confirmation.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when user doesn't have permission.
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the customer profile is not found.
   */
  public function delete($field_phone) {
    // Check authentication
    if ($this->currentUser->isAnonymous()) {
      throw new AccessDeniedHttpException('Authentication required. Please provide a valid JWT token.');
    }

    try {
      // Validate phone number parameter
      if (empty($field_phone)) {
        throw new BadRequestHttpException('field_phone is required.');
      }

      // Extract UID from JWT token
      $request = \Drupal::request();
      $auth_header = $request->headers->get('Authorization');
      
      if (empty($auth_header) || strpos($auth_header, 'Bearer ') !== 0) {
        throw new AccessDeniedHttpException('Authentication required. Please provide a valid JWT token.');
      }
      
      $raw_jwt = substr($auth_header, 7);
      $jwt_uid = NULL;
      
      try {
        $transcoder = \Drupal::service('jwt.transcoder');
        $jwt = $transcoder->decode($raw_jwt);
        $payload = json_decode(json_encode($jwt->getPayload()), TRUE);
        
        if (isset($payload['drupal']['uid'])) {
          $jwt_uid = (int) $payload['drupal']['uid'];
        }
        
        if ($jwt_uid === NULL || $jwt_uid <= 0) {
          throw new AccessDeniedHttpException('Invalid JWT token: UID not found in token payload.');
        }
      } catch (\Exception $e) {
        throw new AccessDeniedHttpException('Invalid JWT token: ' . $e->getMessage());
      }
      
      // Use JWT token UID
      $uid = $jwt_uid;
      $user_account = \Drupal\user\Entity\User::load($uid);
      
      if (!$user_account) {
        throw new AccessDeniedHttpException('User from JWT token does not exist.');
      }

      // Query customer profile by phone number for the JWT-authenticated user
      $query = \Drupal::entityQuery('node')
        ->condition('type', 'customer_profile')
        ->condition('field_phone', $field_phone)
        ->condition('uid', $uid) // CRITICAL: Only find profiles owned by JWT user
        ->accessCheck(FALSE)
        ->range(0, 1); // Only get first match

      $nids = $query->execute();

      if (empty($nids)) {
        $this->logger->warning('Customer profile not found with field_phone: @phone for user @uid', [
          '@phone' => $field_phone,
          '@uid' => $uid,
        ]);
        throw new NotFoundHttpException('Customer profile not found with field_phone: ' . $field_phone . ' for current user.');
      }

      // Load the customer profile node
      $nid = reset($nids);
      $customer_profile = Node::load($nid);

      if (!$customer_profile || $customer_profile->bundle() !== 'customer_profile') {
        $this->logger->warning('Customer profile node @nid could not be loaded or is not a customer_profile', [
          '@nid' => $nid,
        ]);
        throw new NotFoundHttpException('Customer profile not found.');
      }

      // CRITICAL: Verify the node author matches the JWT-authenticated user
      // This is the key security check - only allow deletion of own content
      if ($customer_profile->getOwnerId() != $uid) {
        $this->logger->warning('Access denied: Profile @nid belongs to user @owner, but JWT user is @current', [
          '@nid' => $customer_profile->id(),
          '@owner' => $customer_profile->getOwnerId(),
          '@current' => $uid,
        ]);
        throw new AccessDeniedHttpException('You do not have permission to delete this customer profile. You can only delete profiles that you created.');
      }

      // Additional access check using Drupal's permission system
      if (!$customer_profile->access('delete', $user_account)) {
        $this->logger->warning('User @username does not have permission to delete customer profile @nid', [
          '@username' => $user_account->getAccountName(),
          '@nid' => $customer_profile->id(),
        ]);
        throw new AccessDeniedHttpException('You do not have permission to delete this customer profile.');
      }

      // Store data for response before queuing
      $response_data = [
        'message' => 'Customer profile deleted successfully',
        'field_phone' => $field_phone,
        'nid' => $customer_profile->id(),
        'title' => $customer_profile->getTitle(),
      ];

      // Add field_local_app_unique_id if it exists
      if ($customer_profile->hasField('field_local_app_unique_id') && !$customer_profile->get('field_local_app_unique_id')->isEmpty()) {
        $response_data['field_local_app_unique_id'] = $customer_profile->get('field_local_app_unique_id')->value;
      }

      // Queue the deletion instead of deleting immediately
      try {
        // Get queue_sync settings
        $config = \Drupal::config('queue_sync.settings');
        $run_at = time(); // Queue to run immediately

        // Create batch record in qs_batches table for tracking in admin dashboard
        $batch_id = 'customer_profile_delete_' . \Drupal::service('uuid')->generate();
        $queue_name = 'queue_sync_' . $batch_id;

        // Use helper to create batch record
        $batch_progress_helper = \Drupal::service('queue_sync.batch_progress_helper');
        $batch_progress_helper->createBatchRecord($batch_id, $queue_name, 1, $run_at);

        // Prepare deletion item for queue
        $delete_item = [
          'batch_id' => $batch_id,
          'nid' => $customer_profile->id(),
          'field_phone' => $field_phone,
          'uid' => $uid,
          'username' => $user_account->getAccountName(),
        ];

        // Queue item to customer_profile_delete_worker
        $queue = $this->queueFactory->get('customer_profile_delete_worker');
        $queue->createItem($delete_item);

        $this->logger->info('Queued customer profile deletion: @nid (phone: @phone) by user @username in batch @batch_id', [
          '@nid' => $customer_profile->id(),
          '@phone' => $field_phone,
          '@username' => $user_account->getAccountName(),
          '@batch_id' => $batch_id,
        ]);

        // Return success response immediately (deletion will happen via cron)
        return new ResourceResponse($response_data, 200);
      }
      catch (\Exception $e) {
        $this->logger->error('Error queuing customer profile deletion: @message', [
          '@message' => $e->getMessage(),
        ]);
        throw new BadRequestHttpException('Failed to queue customer profile deletion: ' . $e->getMessage());
      }

    } catch (AccessDeniedHttpException $e) {
      throw $e;
    } catch (NotFoundHttpException $e) {
      throw $e;
    } catch (BadRequestHttpException $e) {
      throw $e;
    } catch (\Exception $e) {
      $this->logger->error('Error deleting customer profile: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new BadRequestHttpException('Failed to delete customer profile: ' . $e->getMessage());
    }
  }

}

