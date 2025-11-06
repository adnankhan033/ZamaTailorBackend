<?php

namespace Drupal\custom_rest_api\Plugin\QueueWorker;

use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\file\Entity\File;
use Drupal\file\FileInterface;
use Drupal\queue_sync\Helper\BatchProgressHelper;
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
   * The batch progress helper.
   *
   * @var \Drupal\queue_sync\Helper\BatchProgressHelper
   */
  protected $batchProgressHelper;

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
      $profile_data = $data['profile_data'] ?? NULL;
      $action = $data['action'] ?? 'create';
      $field_local_app_unique_id = $data['field_local_app_unique_id'] ?? NULL;
      $backend_id = $data['backend_id'] ?? NULL;
      $uid = $data['uid'] ?? NULL;

      if (!$profile_data || !is_array($profile_data)) {
        $this->logger->error('Invalid profile data in queue item');
        $this->batchProgressHelper->updateBatchProgressByBatchId($batch_id, TRUE);
        return;
      }

      // CRITICAL: Determine if this is an update or create
      // Priority order:
      // 1. If backend_id (node ID) is provided and action is 'update', use it directly
      // 2. Check if profile exists by new field_local_app_unique_id
      // 3. If action is 'update' but not found, try to find by phone number (for phone number changes)
      $profile_exists = FALSE;
      $existing_profile_nid = NULL;
      
      // Priority 1: Check by backend_id (node ID) - most reliable for updates
      if ($backend_id && $action === 'update') {
        $node = Node::load($backend_id);
        if ($node && $node->getType() === 'customer_profile' && $node->get('uid')->target_id == $uid) {
          $existing_profile_nid = $backend_id;
          $profile_exists = TRUE;
          $this->logger->info('Found profile by backend_id (node ID): @nid', [
            '@nid' => $backend_id,
          ]);
        }
      }
      
      // Priority 2: Check by field_local_app_unique_id (if not found by backend_id)
      if (!$profile_exists && $field_local_app_unique_id && $uid) {
        $profile_exists = $this->checkProfileExists($field_local_app_unique_id, $uid);
        if ($profile_exists) {
          // Get the node ID
          $query = \Drupal::entityQuery('node')
            ->condition('type', 'customer_profile')
            ->condition('field_local_app_unique_id', $field_local_app_unique_id)
            ->condition('uid', $uid)
            ->accessCheck(FALSE)
            ->range(0, 1);
          $nids = $query->execute();
          if (!empty($nids)) {
            $existing_profile_nid = reset($nids);
          }
        }
        
        $this->logger->info('Queue worker check: field_local_app_unique_id=@unique_id, exists=@exists, action=@action, backend_id=@backend_id', [
          '@unique_id' => $field_local_app_unique_id,
          '@exists' => $profile_exists ? 'YES' : 'NO',
          '@action' => $action,
          '@backend_id' => $backend_id ?? 'N/A',
        ]);
      }
      
      // Priority 3: If action is 'update' but not found yet, try finding by old unique_id pattern
      // This handles cases where phone number changed (and thus unique_id changed)
      // We'll check if there's a profile with the same user that was recently modified
      // or check if there's only one profile for this user (likely the one to update)
      if (!$profile_exists && $action === 'update' && $uid) {
        $this->logger->info('Action is update but profile not found, trying alternative methods...');
        
        // Get all customer profiles for this user
        $all_user_profiles = \Drupal::entityQuery('node')
          ->condition('type', 'customer_profile')
          ->condition('uid', $uid)
          ->accessCheck(FALSE)
          ->sort('changed', 'DESC')
          ->range(0, 10) // Check last 10 profiles
          ->execute();
        
        if (!empty($all_user_profiles)) {
          // If there's only one profile for this user, it's likely the one to update
          if (count($all_user_profiles) === 1) {
            $existing_profile_nid = reset($all_user_profiles);
            $profile_exists = TRUE;
            $this->logger->info('Found single profile for user - assuming this is the one to update: @nid', [
              '@nid' => $existing_profile_nid,
            ]);
          }
          else {
            // If multiple profiles, try to find by matching title (most recent)
            // This is a fallback - ideally backend_id should be provided
            if (!empty($profile_data['title'])) {
              foreach ($all_user_profiles as $nid) {
                $node = Node::load($nid);
                if ($node && $node->getTitle() === $profile_data['title']) {
                  $existing_profile_nid = $nid;
                  $profile_exists = TRUE;
                  $this->logger->info('Found profile by matching title: @nid', [
                    '@nid' => $nid,
                  ]);
                  break;
                }
              }
            }
          }
        }
      }

      // If profile exists, always UPDATE (even if unique_id changed)
      // If profile doesn't exist, always CREATE
      if ($profile_exists && $existing_profile_nid) {
        $this->logger->info('Profile exists - UPDATING node @nid (new unique_id: @unique_id)', [
          '@nid' => $existing_profile_nid,
          '@unique_id' => $field_local_app_unique_id ?? 'N/A',
        ]);
        // Update using the existing node ID, not the unique_id (which may have changed)
        $this->updateCustomerProfileByNid($existing_profile_nid, $profile_data, $uid);
      }
      else {
        $this->logger->info('Profile does not exist - CREATING with unique_id: @unique_id', [
          '@unique_id' => $field_local_app_unique_id ?? 'N/A',
        ]);
        $this->createCustomerProfile($profile_data, $uid);
      }

      // Update batch progress after successful processing
      $this->batchProgressHelper->updateBatchProgressByBatchId($batch_id, TRUE);
    }
    catch (\Exception $e) {
      $this->logger->error('Error processing customer profile queue item: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->batchProgressHelper->updateBatchProgressByBatchId($batch_id, TRUE);
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
          
          // Set field_old_measurement_book_image if provided
          if (isset($paragraph_data['field_old_measurement_book_image']) && !empty($paragraph_data['field_old_measurement_book_image'])) {
            // Handle image field - expects base64 encoded image data
            if ($paragraph->hasField('field_old_measurement_book_image')) {
              $image_data = $paragraph_data['field_old_measurement_book_image'];
              
              // Handle object format (like records) with data, filename, mime
              if (is_array($image_data) && isset($image_data['data'])) {
                $image_data = $image_data['data'];
              }
              
              // If it's a base64 string, decode and create file
              // Skip if it's already a URL (from existing Drupal file)
              if (is_string($image_data) && !preg_match('/^https?:\/\//', $image_data)) {
                // Extract base64 data if it's a data URL
                $base64_string = $image_data;
                if (strpos($image_data, 'data:image') === 0) {
                  $base64_data = explode(',', $image_data);
                  if (count($base64_data) > 1) {
                    $base64_string = $base64_data[1];
                  }
                }
                
                // Decode base64 image
                $decoded_image = base64_decode($base64_string, true);
                
                if ($decoded_image !== false) {
                  // Create file entity
                  $file_system = \Drupal::service('file_system');
                  
                  // Generate unique filename
                  $filename = 'measurement_' . uniqid() . '.jpg';
                  $directory = 'public://measurement_images/';
                  $file_system->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
                  
                  $file_uri = $directory . $filename;
                  $file_system->saveData($decoded_image, $file_uri, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
                  
                  // Create file entity
                  $file = File::create([
                    'uri' => $file_uri,
                    'uid' => 1, // System user for queue processing
                    'status' => FileInterface::STATUS_PERMANENT,
                  ]);
                  $file->save();
                  
                  // Set the image field
                  $paragraph->set('field_old_measurement_book_image', [
                    'target_id' => $file->id(),
                    'alt' => 'Measurement Book Image',
                  ]);
                }
              }
            }
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
   * Update an existing customer profile by node ID.
   *
   * @param int $nid
   *   The node ID.
   * @param array $data
   *   The profile data.
   * @param int $uid
   *   The user ID.
   */
  protected function updateCustomerProfileByNid($nid, array $data, $uid) {
    try {
      $customer_profile = Node::load($nid);

      if (!$customer_profile) {
        throw new \Exception('Customer profile node not found: ' . $nid);
      }

      // Verify it's a customer profile and belongs to the user
      if ($customer_profile->getType() !== 'customer_profile') {
        throw new \Exception('Node is not a customer profile: ' . $nid);
      }

      if ($customer_profile->get('uid')->target_id != $uid) {
        throw new \Exception('Customer profile does not belong to user: ' . $uid);
      }

      // Update title
      if (!empty($data['title'])) {
        $customer_profile->setTitle($data['title']);
      }

      // Update field_local_app_unique_id (may have changed if phone changed)
      if (!empty($data['field_local_app_unique_id']) && $customer_profile->hasField('field_local_app_unique_id')) {
        $customer_profile->set('field_local_app_unique_id', $data['field_local_app_unique_id']);
        $this->logger->info('Updating field_local_app_unique_id to: @unique_id', [
          '@unique_id' => $data['field_local_app_unique_id'],
        ]);
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
        try {
          /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $field_measurement */
          if ($field_measurement instanceof \Drupal\Core\Field\EntityReferenceFieldItemListInterface) {
            $existing_paragraphs = $field_measurement->referencedEntities();
            foreach ($existing_paragraphs as $paragraph) {
              $paragraph->delete();
            }
          }
        }
        catch (\Exception $e) {
          // If referencedEntities doesn't work, skip deletion
          $this->logger->warning('Could not get referenced entities: @message', [
            '@message' => $e->getMessage(),
          ]);
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
          
          // Set field_old_measurement_book_image if provided
          if (isset($paragraph_data['field_old_measurement_book_image']) && !empty($paragraph_data['field_old_measurement_book_image'])) {
            // Handle image field - expects base64 encoded image data
            if ($paragraph->hasField('field_old_measurement_book_image')) {
              $image_data = $paragraph_data['field_old_measurement_book_image'];
              
              // Handle object format (like records) with data, filename, mime
              if (is_array($image_data) && isset($image_data['data'])) {
                $image_data = $image_data['data'];
              }
              
              // If it's a base64 string, decode and create file
              // Skip if it's already a URL (from existing Drupal file)
              if (is_string($image_data) && !preg_match('/^https?:\/\//', $image_data)) {
                // Extract base64 data if it's a data URL
                $base64_string = $image_data;
                if (strpos($image_data, 'data:image') === 0) {
                  $base64_data = explode(',', $image_data);
                  if (count($base64_data) > 1) {
                    $base64_string = $base64_data[1];
                  }
                }
                
                // Decode base64 image
                $decoded_image = base64_decode($base64_string, true);
                
                if ($decoded_image !== false) {
                  // Create file entity
                  $file_system = \Drupal::service('file_system');
                  
                  // Generate unique filename
                  $filename = 'measurement_' . uniqid() . '.jpg';
                  $directory = 'public://measurement_images/';
                  $file_system->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
                  
                  $file_uri = $directory . $filename;
                  $file_system->saveData($decoded_image, $file_uri, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
                  
                  // Create file entity
                  $file = File::create([
                    'uri' => $file_uri,
                    'uid' => 1, // System user for queue processing
                    'status' => FileInterface::STATUS_PERMANENT,
                  ]);
                  $file->save();
                  
                  // Set the image field
                  $paragraph->set('field_old_measurement_book_image', [
                    'target_id' => $file->id(),
                    'alt' => 'Measurement Book Image',
                  ]);
                }
              }
            }
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

      $this->logger->info('Customer profile updated successfully by node ID: @nid', [
        '@nid' => $customer_profile->id(),
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Error updating customer profile by node ID: @message', [
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
        try {
          /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $field_measurement */
          if ($field_measurement instanceof \Drupal\Core\Field\EntityReferenceFieldItemListInterface) {
            $existing_paragraphs = $field_measurement->referencedEntities();
            foreach ($existing_paragraphs as $paragraph) {
              $paragraph->delete();
            }
          }
        }
        catch (\Exception $e) {
          // If referencedEntities doesn't work, skip deletion
          $this->logger->warning('Could not get referenced entities: @message', [
            '@message' => $e->getMessage(),
          ]);
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
          
          // Set field_old_measurement_book_image if provided
          if (isset($paragraph_data['field_old_measurement_book_image']) && !empty($paragraph_data['field_old_measurement_book_image'])) {
            // Handle image field - expects base64 encoded image data
            if ($paragraph->hasField('field_old_measurement_book_image')) {
              $image_data = $paragraph_data['field_old_measurement_book_image'];
              
              // Handle object format (like records) with data, filename, mime
              if (is_array($image_data) && isset($image_data['data'])) {
                $image_data = $image_data['data'];
              }
              
              // If it's a base64 string, decode and create file
              // Skip if it's already a URL (from existing Drupal file)
              if (is_string($image_data) && !preg_match('/^https?:\/\//', $image_data)) {
                // Extract base64 data if it's a data URL
                $base64_string = $image_data;
                if (strpos($image_data, 'data:image') === 0) {
                  $base64_data = explode(',', $image_data);
                  if (count($base64_data) > 1) {
                    $base64_string = $base64_data[1];
                  }
                }
                
                // Decode base64 image
                $decoded_image = base64_decode($base64_string, true);
                
                if ($decoded_image !== false) {
                  // Create file entity
                  $file_system = \Drupal::service('file_system');
                  
                  // Generate unique filename
                  $filename = 'measurement_' . uniqid() . '.jpg';
                  $directory = 'public://measurement_images/';
                  $file_system->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
                  
                  $file_uri = $directory . $filename;
                  $file_system->saveData($decoded_image, $file_uri, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
                  
                  // Create file entity
                  $file = File::create([
                    'uri' => $file_uri,
                    'uid' => 1, // System user for queue processing
                    'status' => FileInterface::STATUS_PERMANENT,
                  ]);
                  $file->save();
                  
                  // Set the image field
                  $paragraph->set('field_old_measurement_book_image', [
                    'target_id' => $file->id(),
                    'alt' => 'Measurement Book Image',
                  ]);
                }
              }
            }
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

