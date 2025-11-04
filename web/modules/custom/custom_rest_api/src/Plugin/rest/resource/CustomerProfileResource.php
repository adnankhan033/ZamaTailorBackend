<?php

namespace Drupal\custom_rest_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Psr\Log\LoggerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Provides a Customer Profile Resource with JWT Authentication.
 *
 * @RestResource(
 *   id = "customer_profile_resource",
 *   label = @Translation("Customer Profile Resource"),
 *   uri_paths = {
 *     "create" = "/api/tailor/customer_profile",
 *     "canonical" = "/api/tailor/customer_profile/{nid}"
 *   }
 * )
 */
class CustomerProfileResource extends ResourceBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a new CustomerProfileResource object.
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
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    array $serializer_formats,
    LoggerInterface $logger,
    AccountProxyInterface $current_user
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $serializer_formats, $logger);
    $this->currentUser = $current_user;
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
      $container->get('current_user')
    );
  }

  /**
   * Checks if a phone number already exists in customer profiles for the current user.
   *
   * @param string $phone
   *   The phone number to check.
   * @param int $exclude_nid
   *   Optional. Node ID to exclude from the check (for update operations).
   *
   * @return bool
   *   TRUE if phone exists, FALSE otherwise.
   */
  protected function checkPhoneExists($phone, $exclude_nid = NULL) {
    if (empty($phone)) {
      return FALSE;
    }

    $query = \Drupal::entityQuery('node')
      ->condition('type', 'customer_profile')
      ->condition('field_phone', $phone)
      ->condition('uid', $this->currentUser->id())
      ->accessCheck(FALSE);

    // Exclude current node if updating
    if ($exclude_nid) {
      $query->condition('nid', $exclude_nid, '<>');
    }

    $nids = $query->execute();

    return !empty($nids);
  }

  /**
   * Finds a customer profile node by field_local_app_unique_id for the current user.
   *
   * @param string $local_app_unique_id
   *   The local app unique ID to search for.
   *
   * @return \Drupal\node\Entity\Node|null
   *   The customer profile node if found, NULL otherwise.
   */
  protected function findCustomerProfileByLocalAppId($local_app_unique_id) {
    if (empty($local_app_unique_id)) {
      return NULL;
    }

    // Try to find by field_local_app_unique_id
    // First try without uid filter to find the record
    $query = \Drupal::entityQuery('node')
      ->condition('type', 'customer_profile')
      ->condition('field_local_app_unique_id', $local_app_unique_id)
      ->accessCheck(FALSE)
      ->range(0, 1);

    $nids = $query->execute();

    if (empty($nids)) {
      // Try direct database query as fallback (in case entity query has issues)
      try {
        $connection = \Drupal::database();
        // Try to find via direct SQL query on field storage table
        $field_storage_table = 'node__field_local_app_unique_id';
        if ($connection->schema()->tableExists($field_storage_table)) {
          $db_query = $connection->select($field_storage_table, 'f')
            ->fields('f', ['entity_id'])
            ->condition('field_local_app_unique_id_value', $local_app_unique_id)
            ->range(0, 1);
          
          $db_nids = $db_query->execute()->fetchCol();
          if (!empty($db_nids)) {
            $nids = $db_nids;
            $this->logger->info('Found customer profile via direct database query: @unique_id, nid: @nid', [
              '@unique_id' => $local_app_unique_id,
              '@nid' => reset($nids),
            ]);
          }
        }
      }
      catch (\Exception $e) {
        $this->logger->warning('Direct database query failed: @message', [
          '@message' => $e->getMessage(),
        ]);
      }

      // If still not found, try loading all customer profiles and checking manually
      if (empty($nids)) {
        $query2 = \Drupal::entityQuery('node')
          ->condition('type', 'customer_profile')
          ->condition('uid', $this->currentUser->id())
          ->accessCheck(FALSE)
          ->range(0, 100); // Limit to reasonable number
        
        $all_nids = $query2->execute();
        if (!empty($all_nids)) {
          foreach ($all_nids as $test_nid) {
            $test_profile = Node::load($test_nid);
            if ($test_profile && $test_profile->hasField('field_local_app_unique_id')) {
              $test_unique_id = $test_profile->get('field_local_app_unique_id')->isEmpty() 
                ? NULL 
                : $test_profile->get('field_local_app_unique_id')->value;
              
              if ($test_unique_id === $local_app_unique_id) {
                $nids = [$test_nid];
                $this->logger->info('Found customer profile via manual check: @unique_id, nid: @nid', [
                  '@unique_id' => $local_app_unique_id,
                  '@nid' => $test_nid,
                ]);
                break;
              }
            }
          }
        }
      }
    }

    if (!empty($nids)) {
      $nid = reset($nids);
      $customer_profile = Node::load($nid);
      
      if (!$customer_profile) {
        $this->logger->warning('Customer profile node @nid could not be loaded', ['@nid' => $nid]);
        return NULL;
      }
      
      // Verify it belongs to current user
      if ($customer_profile->getOwnerId() == $this->currentUser->id()) {
        $this->logger->info('Found customer profile by field_local_app_unique_id: @unique_id, nid: @nid', [
          '@unique_id' => $local_app_unique_id,
          '@nid' => $nid,
        ]);
        return $customer_profile;
      }
      else {
        $this->logger->warning('Customer profile found by field_local_app_unique_id but belongs to different user: @unique_id, nid: @nid, owner: @owner, current: @current', [
          '@unique_id' => $local_app_unique_id,
          '@nid' => $nid,
          '@owner' => $customer_profile->getOwnerId(),
          '@current' => $this->currentUser->id(),
        ]);
      }
    }
    else {
      $this->logger->warning('No customer profile found by field_local_app_unique_id: @unique_id for user @uid', [
        '@unique_id' => $local_app_unique_id,
        '@uid' => $this->currentUser->id(),
      ]);
    }

    return NULL;
  }

  /**
   * Responds to GET requests for retrieving a customer profile.
   *
   * @param string $nid
   *   The customer profile field_local_app_unique_id or node ID.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response object with customer profile data.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when user doesn't have permission.
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the customer profile is not found.
   */
  public function get($nid) {
    // Check if user is authenticated
    if ($this->currentUser->isAnonymous()) {
      $this->logger->error('Access denied: User is anonymous for GET request');
      throw new AccessDeniedHttpException('Authentication required.');
    }

    try {
      // Try to find customer profile by field_local_app_unique_id first
      $customer_profile = $this->findCustomerProfileByLocalAppId($nid);

      // Fall back to node ID if not found by field_local_app_unique_id
      if (!$customer_profile && is_numeric($nid)) {
        $customer_profile = Node::load($nid);
      }

      $this->logger->info('Customer profile retrieval request for identifier @identifier from user: @username', [
        '@identifier' => $nid,
        '@username' => $this->currentUser->getAccountName(),
      ]);

      // Check if customer profile exists
      if (!$customer_profile || $customer_profile->bundle() !== 'customer_profile') {
        $this->logger->warning('Customer profile not found: @identifier', ['@identifier' => $nid]);
        throw new NotFoundHttpException('Customer profile not found.');
      }

      // Check if user has permission to view this customer profile
      if (!$customer_profile->access('view', $this->currentUser)) {
        $this->logger->warning('User @username does not have permission to view customer profile @nid', [
          '@username' => $this->currentUser->getAccountName(),
          '@nid' => $nid,
        ]);
        throw new AccessDeniedHttpException('You do not have permission to view this customer profile.');
      }

      // Prepare response data
      $response_data = [
        'nid' => $customer_profile->id(),
        'title' => $customer_profile->getTitle(),
        'status' => $customer_profile->isPublished(),
        'created' => $customer_profile->getCreatedTime(),
        'updated' => $customer_profile->getChangedTime(),
        'author' => [
          'uid' => $customer_profile->getOwnerId(),
          'name' => $customer_profile->getOwner()->getAccountName(),
        ],
      ];

      // add field field_local_app_unique_id
      if ($customer_profile->hasField('field_local_app_unique_id') && !$customer_profile->get('field_local_app_unique_id')->isEmpty()) {
        $response_data['field_local_app_unique_id'] = $customer_profile->get('field_local_app_unique_id')->value;
      }

      if ($customer_profile->hasField('field_address') && !$customer_profile->get('field_address')->isEmpty()) {
        $address = $customer_profile->get('field_address')->getValue();
        $response_data['field_address'] = $address[0]['value'];
      }
      
      

      // Add body field if it exists
      if ($customer_profile->hasField('body') && !$customer_profile->get('body')->isEmpty()) {
        $response_data['body'] = $customer_profile->get('body')->value;
      }

      // Add paragraph field data if it exists (multiple paragraphs)
      if ($customer_profile->hasField('field_measurement') && !$customer_profile->get('field_measurement')->isEmpty()) {
        $paragraphs_data = [];
        foreach ($customer_profile->get('field_measurement') as $paragraph_item) {
          $paragraph = $paragraph_item->entity;
          if ($paragraph) {
            $paragraphs_data[] = [
              'field_family_members' => $paragraph->hasField('field_family_members') && !$paragraph->get('field_family_members')->isEmpty() 
                ? $paragraph->get('field_family_members')->value 
                : NULL,
              'field_kam_lenght' => $paragraph->hasField('field_kam_lenght') && !$paragraph->get('field_kam_lenght')->isEmpty() 
                ? $paragraph->get('field_kam_lenght')->value 
                : NULL,
            ];
          }
        }
        if (!empty($paragraphs_data)) {
          $response_data['field_measurement'] = $paragraphs_data;
        }
      }

      return new ResourceResponse($response_data, 200);

    }
    catch (NotFoundHttpException $e) {
      throw $e;
    }
    catch (AccessDeniedHttpException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      $this->logger->error('Customer profile retrieval error: @message', [
        '@message' => $e->getMessage(),
      ]);

      throw new BadRequestHttpException('Failed to retrieve customer profile: ' . $e->getMessage());
    }
  }

  /**
   * Responds to POST requests for creating customer profiles.
   *
   * @param array $data
   *   The request data containing customer profile fields.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response object with created customer profile data.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   Thrown when the request data is invalid.
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when user doesn't have permission.
   */
  public function post(array $data) {
    // Log that this is a POST (CREATE) method, not PATCH (UPDATE)
    $request = \Drupal::request();
    $this->logger->info('=== POST METHOD CALLED (CREATE OPERATION) === Method: @method, Path: @path', [
      '@method' => $request->getMethod(),
      '@path' => $request->getPathInfo(),
    ]);

    // Check if user is authenticated first
    // Note: We check auth status BEFORE logging to avoid showing Anonymous user
    // in logs before Drupal's auth listeners have run
    if ($this->currentUser->isAnonymous()) {
      $auth_header = $request->headers->get('Authorization');
      $this->logger->error('Access denied: User is anonymous. Auth header: @header', [
        '@header' => $auth_header ? 'present' : 'missing',
      ]);
      throw new AccessDeniedHttpException('Authentication required.');
    }

    // Log request only after confirming user is authenticated
    // This prevents duplicate log entries (Anonymous followed by authenticated user)
    $this->logger->info('Customer profile CREATE (POST) request from authenticated user: @username', [
      '@username' => $this->currentUser->getAccountName(),
      'uid' => (int) $this->currentUser->id(),
    ]);

    // Extract and validate required fields
    $title = !empty($data['title']) ? $data['title'] : NULL;
    $body = !empty($data['body']) ? $data['body'] : NULL;
    $body_format = !empty($data['body_format']) ? $data['body_format'] : 'basic_html';

    // Validate required fields
    if (empty($title)) {
      $this->logger->warning('Customer profile creation attempt with missing title');
      throw new BadRequestHttpException('Title is required.');
    }

    // Check if profile with field_local_app_unique_id already exists
    // If it exists, this should be an update (PATCH), not a create (POST)
    if (!empty($data['field_local_app_unique_id'])) {
      $existing_profile = $this->findCustomerProfileByLocalAppId($data['field_local_app_unique_id']);
      if ($existing_profile) {
        $this->logger->warning('Customer profile creation attempt but profile already exists with field_local_app_unique_id: @unique_id. Use PATCH to update.', [
          '@unique_id' => $data['field_local_app_unique_id'],
          '@nid' => $existing_profile->id(),
        ]);
        throw new BadRequestHttpException('A customer profile with this unique ID already exists. Use PATCH method to update the existing profile.');
      }
    }

    // Validate phone number uniqueness if provided (per user)
    if (!empty($data['field_phone'])) {
      if ($this->checkPhoneExists($data['field_phone'])) {
        $this->logger->warning('Customer profile creation attempt with duplicate phone number: @phone for user @uid', [
          '@phone' => $data['field_phone'],
          '@uid' => $this->currentUser->id(),
        ]);
        throw new BadRequestHttpException('Phone number already exists in your customer profiles. Please use a different phone number.');
      }
    }

    try {
      // Create customer profile node
      $customer_profile = Node::create([
        'type' => 'customer_profile',
        'title' => $title,
        'uid' => $this->currentUser->id(),
        'status' => !empty($data['status']) ? (bool) $data['status'] : 1, // 1 = published
      ]);

      // Add body field if it exists on the content type
      if ($customer_profile->hasField('body')) {
        $customer_profile->set('body', [
          'value' => $body ?: '',
          'format' => $body_format,
        ]);
      }
      
      // Set field_local_app_unique_id from request data
      if (isset($data['field_local_app_unique_id']) && $customer_profile->hasField('field_local_app_unique_id')) {
        $customer_profile->set('field_local_app_unique_id', $data['field_local_app_unique_id']);
      }

// add field field_phone 
      if ($customer_profile->hasField('field_phone')) {
        $customer_profile->set('field_phone', $data['field_phone']);
      }

      // Add field text value multiple family member
      if ($customer_profile->hasField('field_address')) {
        $customer_profile->set('field_address', $data['field_address']);
      }


      // Handle paragraph field: field_measurement (multiple paragraphs)
      if (!empty($data['field_measurement']) && is_array($data['field_measurement']) && $customer_profile->hasField('field_measurement')) {
        $paragraphs = [];
        
        // Loop through each paragraph data
        foreach ($data['field_measurement'] as $paragraph_data) {
          // Create paragraph entity
          $paragraph = Paragraph::create([
            'type' => 'measurement',
          ]);
          
          // Set field_family_members if provided
          if (isset($paragraph_data['field_family_members'])) {
            $paragraph->set('field_family_members', $paragraph_data['field_family_members']);
          }
          
          // Set field_kam_lenght if provided
          if (isset($paragraph_data['field_kam_lenght'])) {
            $paragraph->set('field_kam_lenght', $paragraph_data['field_kam_lenght']);
          }
          
          // Save the paragraph
          $paragraph->save();
          $paragraphs[] = $paragraph;
        }
        
        // Set all paragraphs on the customer profile
        if (!empty($paragraphs)) {
          $customer_profile->set('field_measurement', $paragraphs);
        }
      }

      // Add custom fields if provided
      if (!empty($data['field_image']) && isset($data['field_image']['data'])) {
        // Handle image field if exists
        // This is optional depending on your setup
      }

      // Save the customer profile
      $customer_profile->save();

      // Log successful creation
      $this->logger->info('Customer profile created successfully: @nid by user @username', [
        '@nid' => $customer_profile->id(),
        '@username' => $this->currentUser->getAccountName(),
      ]);

      // Prepare response data
      $response_data = [
        'message' => 'Customer profile created successfully',
        'nid' => $customer_profile->id(),
        'title' => $customer_profile->getTitle(),
        'status' => $customer_profile->isPublished(),
        'created' => $customer_profile->getCreatedTime(),
        'author' => [
          'uid' => $customer_profile->getOwnerId(),
          'name' => $this->currentUser->getAccountName(),
        ],
      ];

      // Add body field if it exists
      if ($customer_profile->hasField('body') && !$customer_profile->get('body')->isEmpty()) {
        $response_data['body'] = $customer_profile->get('body')->value;
      }

      // add field field_local_app_unique_id
      if ($customer_profile->hasField('field_local_app_unique_id') && !$customer_profile->get('field_local_app_unique_id')->isEmpty()) {
        $response_data['field_local_app_unique_id'] = $customer_profile->get('field_local_app_unique_id')->value;
      }
      // Add field field_phone 
      if ($customer_profile->hasField('field_phone')) {
        $response_data['field_phone'] = $customer_profile->get('field_phone')->value;
      }

      // Add field text value multiple family member
      if ($customer_profile->hasField('field_address')) {
        $address = $customer_profile->get('field_address')->getValue();
        $response_data['field_address'] = $address;
      }


      // Add paragraph field data if it exists (multiple paragraphs)
      if ($customer_profile->hasField('field_measurement') && !$customer_profile->get('field_measurement')->isEmpty()) {
        $paragraphs_data = [];
        foreach ($customer_profile->get('field_measurement') as $paragraph_item) {
          $paragraph = $paragraph_item->entity;
          if ($paragraph) {
            $paragraphs_data[] = [
              'field_family_members' => $paragraph->hasField('field_family_members') && !$paragraph->get('field_family_members')->isEmpty() 
                ? $paragraph->get('field_family_members')->value 
                : NULL,
              'field_kam_lenght' => $paragraph->hasField('field_kam_lenght') && !$paragraph->get('field_kam_lenght')->isEmpty() 
                ? $paragraph->get('field_kam_lenght')->value 
                : NULL,
            ];
          }
        }
        if (!empty($paragraphs_data)) {
          $response_data['field_measurement'] = $paragraphs_data;
        }
      }

      return new ResourceResponse($response_data, 201);

    }
    catch (\Exception $e) {
      $this->logger->error('Customer profile creation error: @message', [
        '@message' => $e->getMessage(),
      ]);

      throw new BadRequestHttpException('Failed to create customer profile: ' . $e->getMessage());
    }
  }

  /**
   * Responds to PATCH requests for updating customer profiles.
   *
   * @param string $nid
   *   The customer profile field_local_app_unique_id or node ID.
   * @param array $data
   *   The request data containing fields to update.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response object with updated customer profile data.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   Thrown when the request data is invalid.
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when user doesn't have permission.
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the customer profile is not found.
   */
  public function patch($nid, array $data) {
    // Log that this is a PATCH (UPDATE) method, not POST (CREATE)
    $request = \Drupal::request();
    $this->logger->info('=== PATCH METHOD CALLED (UPDATE OPERATION) === Identifier: @identifier, Method: @method, Path: @path', [
      '@identifier' => $nid,
      '@method' => $request->getMethod(),
      '@path' => $request->getPathInfo(),
      '@username' => $this->currentUser->getAccountName(),
    ]);

    // Check if user is authenticated
    if ($this->currentUser->isAnonymous()) {
      $this->logger->error('Access denied: User is anonymous for PATCH request');
      throw new AccessDeniedHttpException('Authentication required.');
    }

    try {
      $this->logger->info('Customer profile UPDATE (PATCH) request for identifier @identifier from user: @username', [
        '@identifier' => $nid,
        '@username' => $this->currentUser->getAccountName(),
      ]);

      // Try to find customer profile by field_local_app_unique_id from route parameter
      $customer_profile = $this->findCustomerProfileByLocalAppId($nid);

      // Fall back to node ID if not found by field_local_app_unique_id
      if (!$customer_profile && is_numeric($nid)) {
        $customer_profile = Node::load($nid);
        if ($customer_profile && $customer_profile->getOwnerId() != $this->currentUser->id()) {
          $this->logger->warning('Customer profile found by nid but belongs to different user: @nid', ['@nid' => $nid]);
          $customer_profile = NULL;
        }
      }

      // Check if customer profile exists
      if (!$customer_profile || $customer_profile->bundle() !== 'customer_profile') {
        // CRITICAL: Never create a new node in PATCH - this is an update operation only
        $this->logger->error('Customer profile not found for PATCH update: @identifier. This is an UPDATE operation - will NOT create new record. Use POST to create.', [
          '@identifier' => $nid,
          'user_id' => $this->currentUser->id(),
          'username' => $this->currentUser->getAccountName(),
        ]);
        throw new NotFoundHttpException('Customer profile not found for update. Profile with identifier "' . $nid . '" does not exist. Use POST method at /api/tailor/customer_profile to create a new profile.');
      }

      // Double-check: Make sure we're updating, not creating
      if (!$customer_profile->id() || $customer_profile->isNew()) {
        $this->logger->error('CRITICAL: Attempted to update a new/unsaved node. This should never happen in PATCH method.');
        throw new BadRequestHttpException('Invalid node state for update operation.');
      }

      // Check if user has permission to update this customer profile
      if (!$customer_profile->access('update', $this->currentUser)) {
        $this->logger->warning('User @username does not have permission to update customer profile @nid', [
          '@username' => $this->currentUser->getAccountName(),
          '@nid' => $customer_profile->id(),
        ]);
        throw new AccessDeniedHttpException('You do not have permission to update this customer profile.');
      }

      // Get the node ID for validation and logging
      $node_id = $customer_profile->id();

      // Validate phone number uniqueness if provided (per user, exclude current node)
      // Only check if phone number is being changed
      $current_phone = $customer_profile->hasField('field_phone') && !$customer_profile->get('field_phone')->isEmpty()
        ? $customer_profile->get('field_phone')->value
        : NULL;
      
      if (isset($data['field_phone']) && !empty($data['field_phone']) && $customer_profile->hasField('field_phone')) {
        // Only validate if phone number is different from current
        if ($current_phone !== $data['field_phone']) {
          if ($this->checkPhoneExists($data['field_phone'], $node_id)) {
            $this->logger->warning('Customer profile update attempt with duplicate phone number: @phone for nid @nid, user @uid', [
              '@phone' => $data['field_phone'],
              '@nid' => $node_id,
              '@uid' => $this->currentUser->id(),
            ]);
            throw new BadRequestHttpException('Phone number already exists in your customer profiles. Please use a different phone number.');
          }
        }
      }

      // Validate that field_local_app_unique_id is unique (if being changed)
      // The field_local_app_unique_id should NOT change on update, but if it does, check uniqueness
      $current_unique_id = $customer_profile->hasField('field_local_app_unique_id') && !$customer_profile->get('field_local_app_unique_id')->isEmpty()
        ? $customer_profile->get('field_local_app_unique_id')->value
        : NULL;
      
      if (isset($data['field_local_app_unique_id']) && !empty($data['field_local_app_unique_id'])) {
        // If unique ID is being changed, check if new one already exists
        if ($current_unique_id !== $data['field_local_app_unique_id']) {
          $existing_profile = $this->findCustomerProfileByLocalAppId($data['field_local_app_unique_id']);
          if ($existing_profile && $existing_profile->id() != $node_id) {
            $this->logger->warning('Customer profile update attempt with duplicate field_local_app_unique_id: @unique_id', [
              '@unique_id' => $data['field_local_app_unique_id'],
            ]);
            throw new BadRequestHttpException('The unique ID already exists in another customer profile.');
          }
        }
      }

      // Update title if provided
      if (isset($data['title'])) {
        $customer_profile->setTitle($data['title']);
      }

      // Update body if provided
      if (isset($data['body']) && $customer_profile->hasField('body')) {
        $body_format = !empty($data['body_format']) ? $data['body_format'] : 'basic_html';
        $customer_profile->set('body', [
          'value' => $data['body'],
          'format' => $body_format,
        ]);
      }

      // Update field_local_app_unique_id from request data (if provided and different)
      // Note: field_local_app_unique_id should typically NOT change, but we allow it if explicitly provided
      if (isset($data['field_local_app_unique_id']) && !empty($data['field_local_app_unique_id']) && $customer_profile->hasField('field_local_app_unique_id')) {
        // Only update if it's different from current value
        $current_unique_id = $customer_profile->get('field_local_app_unique_id')->isEmpty() 
          ? NULL 
          : $customer_profile->get('field_local_app_unique_id')->value;
        
        if ($current_unique_id !== $data['field_local_app_unique_id']) {
          $this->logger->info('Updating field_local_app_unique_id from @old to @new for nid @nid', [
            '@old' => $current_unique_id ?? 'empty',
            '@new' => $data['field_local_app_unique_id'],
            '@nid' => $customer_profile->id(),
          ]);
          $customer_profile->set('field_local_app_unique_id', $data['field_local_app_unique_id']);
        }
      }
      // If field_local_app_unique_id is not provided in update, ensure it's preserved
      elseif ($customer_profile->hasField('field_local_app_unique_id') && $customer_profile->get('field_local_app_unique_id')->isEmpty()) {
        // If current profile has no unique_id, try to generate one from phone and user
        if (!empty($data['field_phone']) || ($customer_profile->hasField('field_phone') && !$customer_profile->get('field_phone')->isEmpty())) {
          $phone = !empty($data['field_phone']) ? $data['field_phone'] : $customer_profile->get('field_phone')->value;
          $unique_id = $this->currentUser->id() . '_' . preg_replace('/\D/', '', $phone);
          $customer_profile->set('field_local_app_unique_id', $unique_id);
          $this->logger->info('Generated field_local_app_unique_id for existing profile: @unique_id', [
            '@unique_id' => $unique_id,
          ]);
        }
      }
      
      // Update field field_phone 
      if (isset($data['field_phone']) && $customer_profile->hasField('field_phone')) {
        $customer_profile->set('field_phone', $data['field_phone']);
      }

      // Update field text value multiple family member
      if (isset($data['field_address']) && $customer_profile->hasField('field_address')) {
        $customer_profile->set('field_address', $data['field_address']);
      }


    
      // Update paragraph field: field_measurement (multiple paragraphs)
      if (isset($data['field_measurement']) && is_array($data['field_measurement']) && $customer_profile->hasField('field_measurement')) {
        // Delete existing paragraphs first
        if (!$customer_profile->get('field_measurement')->isEmpty()) {
          foreach ($customer_profile->get('field_measurement') as $paragraph_item) {
            $existing_paragraph = $paragraph_item->entity;
            if ($existing_paragraph) {
              $existing_paragraph->delete();
            }
          }
        }
        
        // Create new paragraphs from the provided data
        $paragraphs = [];
        foreach ($data['field_measurement'] as $paragraph_data) {
          // Create paragraph entity
          $paragraph = Paragraph::create([
            'type' => 'measurement',
          ]);
          
          // Set field_family_members if provided
          if (isset($paragraph_data['field_family_members'])) {
            $paragraph->set('field_family_members', $paragraph_data['field_family_members']);
          }
          
          // Set field_kam_lenght if provided
          if (isset($paragraph_data['field_kam_lenght'])) {
            $paragraph->set('field_kam_lenght', $paragraph_data['field_kam_lenght']);
          }
          
          // Save the paragraph
          $paragraph->save();
          $paragraphs[] = $paragraph;
        }
        
        // Set all paragraphs on the customer profile
        if (!empty($paragraphs)) {
          $customer_profile->set('field_measurement', $paragraphs);
        }
      }

      // Update status if provided
      if (isset($data['status'])) {
        $status = (bool) $data['status'];
        if ($status) {
          $customer_profile->setPublished();
        }
        else {
          $customer_profile->setUnpublished();
        }
      }

      // CRITICAL: Verify we're updating an existing node, not creating a new one
      // This is the final safety check before saving
      if ($customer_profile->isNew()) {
        $this->logger->error('CRITICAL ERROR: Attempted to save a NEW node in PATCH method. Node should already exist!');
        throw new BadRequestHttpException('Cannot create new node in PATCH method. This is an update operation only. Node ID: ' . ($customer_profile->id() ?? 'NULL'));
      }

      // Verify the node ID exists and is valid (must be > 0)
      $existing_node_id = $customer_profile->id();
      if (!$existing_node_id || $existing_node_id <= 0) {
        $this->logger->error('CRITICAL ERROR: Node has invalid ID: @id', ['@id' => $existing_node_id]);
        throw new BadRequestHttpException('Invalid node ID for update operation. ID: ' . ($existing_node_id ?? 'NULL'));
      }

      // Log the update operation with details
      $this->logger->info('Updating existing customer profile node @nid (NOT creating new). Unique ID: @unique_id, Changes: @changes', [
        '@nid' => $existing_node_id,
        '@unique_id' => $customer_profile->hasField('field_local_app_unique_id') && !$customer_profile->get('field_local_app_unique_id')->isEmpty()
          ? $customer_profile->get('field_local_app_unique_id')->value
          : 'N/A',
        '@changes' => implode(', ', array_keys(array_filter($data))),
      ]);

      // Save the customer profile (UPDATE operation - this should NOT create a new node)
      $customer_profile->save();

      // Verify after save that it's still the same node (not a new one)
      if ($customer_profile->id() != $existing_node_id) {
        $this->logger->error('CRITICAL ERROR: Node ID changed after save! Original: @original, New: @new', [
          '@original' => $existing_node_id,
          '@new' => $customer_profile->id(),
        ]);
        throw new BadRequestHttpException('Node ID changed after save. This should never happen in an update operation.');
      }

      // Log successful update
      $this->logger->info('Customer profile updated successfully: @nid by user @username', [
        '@nid' => $customer_profile->id(),
        '@username' => $this->currentUser->getAccountName(),
      ]);

      // Prepare response data
      $response_data = [
        'message' => 'Customer profile updated successfully',
        'nid' => $customer_profile->id(),
        'title' => $customer_profile->getTitle(),
        'status' => $customer_profile->isPublished(),
        'updated' => $customer_profile->getChangedTime(),
        'author' => [
          'uid' => $customer_profile->getOwnerId(),
          'name' => $customer_profile->getOwner()->getAccountName(),
        ],
      ];

      // Add body field if it exists
      if ($customer_profile->hasField('body') && !$customer_profile->get('body')->isEmpty()) {
        $response_data['body'] = $customer_profile->get('body')->value;
      }

      // field_local_app_unique_id 
      if ($customer_profile->hasField('field_local_app_unique_id') && !$customer_profile->get('field_local_app_unique_id')->isEmpty()) {
        $response_data['field_local_app_unique_id'] = $customer_profile->get('field_local_app_unique_id')->value;
      }
      
      // Add field field_phone 
      if ($customer_profile->hasField('field_phone')) {
        $response_data['field_phone'] = $customer_profile->get('field_phone')->value;
      }

      // Add field text value multiple family member
      if ($customer_profile->hasField('field_address')) {
        $address = $customer_profile->get('field_address')->getValue();
        $response_data['field_address'] = $address;
      }


      // Add paragraph field data if it exists (multiple paragraphs)
      if ($customer_profile->hasField('field_measurement') && !$customer_profile->get('field_measurement')->isEmpty()) {
        $paragraphs_data = [];
        foreach ($customer_profile->get('field_measurement') as $paragraph_item) {
          $paragraph = $paragraph_item->entity;
          if ($paragraph) {
            $paragraphs_data[] = [
              'field_family_members' => $paragraph->hasField('field_family_members') && !$paragraph->get('field_family_members')->isEmpty() 
                ? $paragraph->get('field_family_members')->value 
                : NULL,
              'field_kam_lenght' => $paragraph->hasField('field_kam_lenght') && !$paragraph->get('field_kam_lenght')->isEmpty() 
                ? $paragraph->get('field_kam_lenght')->value 
                : NULL,
            ];
          }
        }
        if (!empty($paragraphs_data)) {
          $response_data['field_measurement'] = $paragraphs_data;
        }
      }

      return new ResourceResponse($response_data, 200);

    }
    catch (NotFoundHttpException $e) {
      throw $e;
    }
    catch (AccessDeniedHttpException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      $this->logger->error('Customer profile update error: @message', [
        '@message' => $e->getMessage(),
      ]);

      throw new BadRequestHttpException('Failed to update customer profile: ' . $e->getMessage());
    }
  }

  /**
   * Responds to DELETE requests for deleting customer profiles.
   *
   * @param string $nid
   *   The customer profile field_local_app_unique_id or node ID.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response object with deletion confirmation.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when user doesn't have permission.
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the customer profile is not found.
   */
  public function delete($nid) {
    // Check if user is authenticated
    if ($this->currentUser->isAnonymous()) {
      $this->logger->error('Access denied: User is anonymous for DELETE request');
      throw new AccessDeniedHttpException('Authentication required.');
    }

    try {
      // Try to find customer profile by field_local_app_unique_id first
      $customer_profile = $this->findCustomerProfileByLocalAppId($nid);

      // Fall back to node ID if not found by field_local_app_unique_id
      if (!$customer_profile && is_numeric($nid)) {
        $customer_profile = Node::load($nid);
      }

      $this->logger->info('Customer profile delete request for identifier @identifier from user: @username', [
        '@identifier' => $nid,
        '@username' => $this->currentUser->getAccountName(),
      ]);

      // Check if customer profile exists
      if (!$customer_profile || $customer_profile->bundle() !== 'customer_profile') {
        $this->logger->warning('Customer profile not found for deletion: @identifier', ['@identifier' => $nid]);
        throw new NotFoundHttpException('Customer profile not found.');
      }

      // Check if user has permission to delete this customer profile
      if (!$customer_profile->access('delete', $this->currentUser)) {
        $this->logger->warning('User @username does not have permission to delete customer profile @nid', [
          '@username' => $this->currentUser->getAccountName(),
          '@nid' => $customer_profile->id(),
        ]);
        throw new AccessDeniedHttpException('You do not have permission to delete this customer profile.');
      }

      // Store data for response before deletion
      $response_data = [
        'message' => 'Customer profile deleted successfully',
        'nid' => $customer_profile->id(),
        'title' => $customer_profile->getTitle(),
      ];

      // Delete the customer profile
      $customer_profile->delete();

      // Log successful deletion
      $this->logger->info('Customer profile deleted successfully: @nid by user @username', [
        '@nid' => $nid,
        '@username' => $this->currentUser->getAccountName(),
      ]);

      return new ResourceResponse($response_data, 200);

    }
    catch (NotFoundHttpException $e) {
      throw $e;
    }
    catch (AccessDeniedHttpException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      $this->logger->error('Customer profile deletion error: @message', [
        '@message' => $e->getMessage(),
      ]);

      throw new BadRequestHttpException('Failed to delete customer profile: ' . $e->getMessage());
    }
  }

}

