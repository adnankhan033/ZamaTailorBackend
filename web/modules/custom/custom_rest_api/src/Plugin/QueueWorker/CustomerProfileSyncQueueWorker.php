<?php

namespace Drupal\custom_rest_api\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;

/**
 * Queue worker for syncing customer profiles.
 *
 * @QueueWorker(
 *   id = "customer_profile_sync_worker",
 *   title = @Translation("Customer Profile Sync Worker"),
 *   cron = {"time" = 60}
 * )
 */
class CustomerProfileSyncQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a CustomerProfileSyncQueueWorker object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('logger.factory')->get('custom_rest_api')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    $batch_id = $data['batch_id'] ?? NULL;
    
    try {
      $profile_data = $data['profile_data'] ?? NULL;
      $action = $data['action'] ?? 'create';
      $field_local_app_unique_id = $data['field_local_app_unique_id'] ?? NULL;
      $backend_id = $data['backend_id'] ?? NULL;
      $uid = $data['uid'] ?? NULL;

      if (!$profile_data || !is_array($profile_data)) {
        $this->logger->error('Invalid profile data in queue item');
        $this->updateBatchProgress($batch_id, FALSE);
        return;
      }

      // CRITICAL: Always check if profile exists by field_local_app_unique_id first
      // This ensures we UPDATE if it exists, CREATE if it doesn't
      // regardless of what the 'action' field says
      $profile_exists = FALSE;
      if ($field_local_app_unique_id && $uid) {
        $profile_exists = $this->checkProfileExists($field_local_app_unique_id, $uid);
        
        $this->logger->info('Queue worker check: field_local_app_unique_id=@unique_id, exists=@exists, action=@action', [
          '@unique_id' => $field_local_app_unique_id,
          '@exists' => $profile_exists ? 'YES' : 'NO',
          '@action' => $action,
        ]);
      }

      // If profile exists, always UPDATE (ignore action field)
      // If profile doesn't exist, always CREATE (ignore action field)
      if ($profile_exists && $field_local_app_unique_id) {
        $this->logger->info('Profile exists - UPDATING: @unique_id', [
          '@unique_id' => $field_local_app_unique_id,
        ]);
        $this->updateCustomerProfile($field_local_app_unique_id, $profile_data, $uid);
      }
      else {
        $this->logger->info('Profile does not exist - CREATING with unique_id: @unique_id', [
          '@unique_id' => $field_local_app_unique_id ?? 'N/A',
        ]);
        $this->createCustomerProfile($profile_data, $uid);
      }

      // Update batch progress after successful processing
      $this->updateBatchProgress($batch_id, TRUE);
    }
    catch (\Exception $e) {
      $this->logger->error('Error processing customer profile queue item: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->updateBatchProgress($batch_id, FALSE);
      throw $e;
    }
  }

  /**
   * Check if a customer profile exists by field_local_app_unique_id.
   *
   * @param string $field_local_app_unique_id
   *   The unique ID to check.
   * @param int $uid
   *   The user ID.
   *
   * @return bool
   *   TRUE if profile exists, FALSE otherwise.
   */
  protected function checkProfileExists($field_local_app_unique_id, $uid) {
    try {
      // First try entity query (most reliable)
      $query = \Drupal::entityQuery('node')
        ->condition('type', 'customer_profile')
        ->condition('field_local_app_unique_id', $field_local_app_unique_id)
        ->condition('uid', $uid)
        ->accessCheck(FALSE)
        ->range(0, 1);

      $nids = $query->execute();

      if (!empty($nids)) {
        $this->logger->debug('Profile found via entity query: @nid', [
          '@nid' => reset($nids),
        ]);
        return TRUE;
      }

      // Fallback: Direct database query with field join
      $connection = \Drupal::database();
      
      // Try to find the field table name
      $field_storage = \Drupal::entityTypeManager()
        ->getStorage('field_storage_config')
        ->load('node.field_local_app_unique_id');
      
      if ($field_storage) {
        $field_table = 'node__field_local_app_unique_id';
        $field_column = 'field_local_app_unique_id_value';
        
        $result = $connection->select($field_table, 'f')
          ->fields('f', ['entity_id'])
          ->condition('f.' . $field_column, $field_local_app_unique_id)
          ->condition('f.bundle', 'customer_profile')
          ->condition('f.deleted', 0)
          ->range(0, 1)
          ->execute();
        
        $row = $result->fetchObject();
        if ($row) {
          // Verify the node belongs to the correct user
          $node = Node::load($row->entity_id);
          if ($node && $node->get('uid')->target_id == $uid) {
            $this->logger->debug('Profile found via direct DB query: @nid', [
              '@nid' => $row->entity_id,
            ]);
            return TRUE;
          }
        }
      }

      // Final fallback: Manual check by loading all user's profiles
      $all_nids = \Drupal::entityQuery('node')
        ->condition('type', 'customer_profile')
        ->condition('uid', $uid)
        ->accessCheck(FALSE)
        ->execute();

      foreach ($all_nids as $nid) {
        $node = Node::load($nid);
        if ($node && $node->hasField('field_local_app_unique_id')) {
          $unique_id_value = $node->get('field_local_app_unique_id')->value;
          if ($unique_id_value === $field_local_app_unique_id) {
            $this->logger->debug('Profile found via manual check: @nid', [
              '@nid' => $nid,
            ]);
            return TRUE;
          }
        }
      }

      return FALSE;
    }
    catch (\Exception $e) {
      $this->logger->error('Error checking if profile exists: @message', [
        '@message' => $e->getMessage(),
      ]);
      return FALSE;
    }
  }

  /**
   * Update batch progress in qs_batches table.
   *
   * @param string|null $batch_id
   *   The batch ID.
   * @param bool $success
   *   Whether the item was processed successfully.
   */
  protected function updateBatchProgress($batch_id, $success) {
    if (!$batch_id) {
      return;
    }

    try {
      $connection = \Drupal::database();
      
      // Get current batch status
      $batch = $connection->select('qs_batches', 'b')
        ->fields('b', ['items_total', 'items_processed', 'status'])
        ->condition('batch_id', $batch_id)
        ->execute()
        ->fetchObject();

      if (!$batch) {
        return;
      }

      // Increment processed count
      $new_processed = $batch->items_processed + 1;
      $new_status = $batch->status;

      // Update status based on progress
      if ($new_processed >= $batch->items_total) {
        // All items processed - mark as completed
        $new_status = 2; // 2 = completed
      }
      elseif ($new_status == 0 && $new_processed > 0) {
        // First item processed - mark as running
        $new_status = 1; // 1 = running
      }

      // Update batch record
      $connection->update('qs_batches')
        ->fields([
          'items_processed' => $new_processed,
          'status' => $new_status,
        ])
        ->condition('batch_id', $batch_id)
        ->execute();
    }
    catch (\Exception $e) {
      // Log error but don't fail the queue item processing
      $this->logger->warning('Error updating batch progress: @message', [
        '@message' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Create a new customer profile.
   *
   * @param array $data
   *   The profile data.
   * @param int $uid
   *   The user ID.
   */
  protected function createCustomerProfile(array $data, $uid) {
    try {
      $title = $data['title'] ?? NULL;
      if (empty($title)) {
        throw new \Exception('Title is required for customer profile');
      }

      // Create customer profile node
      $customer_profile = Node::create([
        'type' => 'customer_profile',
        'title' => $title,
        'uid' => $uid,
        'status' => !empty($data['status']) ? (bool) $data['status'] : 1,
      ]);

      // Set field_local_app_unique_id
      if (!empty($data['field_local_app_unique_id']) && $customer_profile->hasField('field_local_app_unique_id')) {
        $customer_profile->set('field_local_app_unique_id', $data['field_local_app_unique_id']);
      }

      // Set field_phone
      if (!empty($data['field_phone']) && $customer_profile->hasField('field_phone')) {
        $customer_profile->set('field_phone', $data['field_phone']);
      }

      // Set field_address
      if (!empty($data['field_address']) && $customer_profile->hasField('field_address')) {
        $customer_profile->set('field_address', $data['field_address']);
      }

      // Handle paragraph field: field_measurement
      if (!empty($data['field_measurement']) && is_array($data['field_measurement']) && $customer_profile->hasField('field_measurement')) {
        $paragraphs = [];
        
        foreach ($data['field_measurement'] as $paragraph_data) {
          $paragraph = Paragraph::create([
            'type' => 'measurement',
          ]);
          
          if (isset($paragraph_data['field_family_members'])) {
            $paragraph->set('field_family_members', $paragraph_data['field_family_members']);
          }
          
          if (isset($paragraph_data['field_kam_lenght'])) {
            $paragraph->set('field_kam_lenght', $paragraph_data['field_kam_lenght']);
          }
          
          $paragraph->save();
          $paragraphs[] = $paragraph;
        }
        
        if (!empty($paragraphs)) {
          $customer_profile->set('field_measurement', $paragraphs);
        }
      }

      // Save the customer profile
      $customer_profile->save();

      $this->logger->info('Customer profile created successfully: @nid', [
        '@nid' => $customer_profile->id(),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Error creating customer profile: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Update an existing customer profile.
   *
   * @param string $field_local_app_unique_id
   *   The unique ID to find the profile.
   * @param array $data
   *   The profile data.
   * @param int $uid
   *   The user ID.
   */
  protected function updateCustomerProfile($field_local_app_unique_id, array $data, $uid) {
    try {
      // Find existing profile by field_local_app_unique_id
      $query = \Drupal::entityQuery('node')
        ->condition('type', 'customer_profile')
        ->condition('field_local_app_unique_id', $field_local_app_unique_id)
        ->condition('uid', $uid)
        ->accessCheck(FALSE)
        ->range(0, 1);

      $nids = $query->execute();

      if (empty($nids)) {
        // Profile not found, create it instead
        $this->logger->warning('Profile not found for update, creating new: @unique_id', [
          '@unique_id' => $field_local_app_unique_id,
        ]);
        $this->createCustomerProfile($data, $uid);
        return;
      }

      $nid = reset($nids);
      $customer_profile = Node::load($nid);

      if (!$customer_profile) {
        throw new \Exception('Customer profile node not found');
      }

      // Update title
      if (!empty($data['title'])) {
        $customer_profile->setTitle($data['title']);
      }

      // Update field_phone
      if (!empty($data['field_phone']) && $customer_profile->hasField('field_phone')) {
        $customer_profile->set('field_phone', $data['field_phone']);
      }

      // Update field_address
      if (!empty($data['field_address']) && $customer_profile->hasField('field_address')) {
        $customer_profile->set('field_address', $data['field_address']);
      }

      // Update field_measurement
      if (!empty($data['field_measurement']) && is_array($data['field_measurement']) && $customer_profile->hasField('field_measurement')) {
        // Remove existing paragraphs
        $field_measurement = $customer_profile->get('field_measurement');
        if (method_exists($field_measurement, 'referencedEntities')) {
          $existing_paragraphs = $field_measurement->referencedEntities();
          foreach ($existing_paragraphs as $paragraph) {
            $paragraph->delete();
          }
        }

        // Create new paragraphs
        $paragraphs = [];
        foreach ($data['field_measurement'] as $paragraph_data) {
          $paragraph = Paragraph::create([
            'type' => 'measurement',
          ]);
          
          if (isset($paragraph_data['field_family_members'])) {
            $paragraph->set('field_family_members', $paragraph_data['field_family_members']);
          }
          
          if (isset($paragraph_data['field_kam_lenght'])) {
            $paragraph->set('field_kam_lenght', $paragraph_data['field_kam_lenght']);
          }
          
          $paragraph->save();
          $paragraphs[] = $paragraph;
        }
        
        if (!empty($paragraphs)) {
          $customer_profile->set('field_measurement', $paragraphs);
        }
      }

      // Save the customer profile
      $customer_profile->save();

      $this->logger->info('Customer profile updated successfully: @nid', [
        '@nid' => $customer_profile->id(),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Error updating customer profile: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

}

