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
   * Responds to GET requests for retrieving a customer profile.
   *
   * @param int $nid
   *   The customer profile node ID.
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

    $this->logger->info('Customer profile retrieval request for nid @nid from user: @username', [
      '@nid' => $nid,
      '@username' => $this->currentUser->getAccountName(),
    ]);

    try {
      // Load the customer profile node
      $customer_profile = Node::load($nid);

      // Check if customer profile exists
      if (!$customer_profile || $customer_profile->bundle() !== 'customer_profile') {
        $this->logger->warning('Customer profile not found: @nid', ['@nid' => $nid]);
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

      
      if ($customer_profile->hasField('field_address') && !$customer_profile->get('field_address')->isEmpty()) {
        $address = $customer_profile->get('field_address')->getValue();
        $response_data['field_address'] = $address[0]['value'];
      }
      
      

      // Add body field if it exists
      if ($customer_profile->hasField('body') && !$customer_profile->get('body')->isEmpty()) {
        $response_data['body'] = $customer_profile->get('body')->value;
      }

      // Add paragraph field data if it exists
      if ($customer_profile->hasField('field_measurement') && !$customer_profile->get('field_measurement')->isEmpty()) {
        $paragraph = $customer_profile->get('field_measurement')->entity;
        if ($paragraph) {
          $response_data['field_measurement'] = [
            'pid' => $paragraph->id(),
            'field_family_members' => $paragraph->hasField('field_family_members') && !$paragraph->get('field_family_members')->isEmpty() 
              ? $paragraph->get('field_family_members')->value 
              : NULL,

            'field_kam_lenght' => $paragraph->hasField('field_kam_lenght') && !$paragraph->get('field_kam_lenght')->isEmpty() 
              ? $paragraph->get('field_kam_lenght')->value 
              : NULL,

          ];
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
    // Check if user is authenticated first
    // Note: We check auth status BEFORE logging to avoid showing Anonymous user
    // in logs before Drupal's auth listeners have run
    if ($this->currentUser->isAnonymous()) {
      $request = \Drupal::request();
      $auth_header = $request->headers->get('Authorization');
      $this->logger->error('Access denied: User is anonymous. Auth header: @header', [
        '@header' => $auth_header ? 'present' : 'missing',
      ]);
      throw new AccessDeniedHttpException('Authentication required.');
    }

    // Log request only after confirming user is authenticated
    // This prevents duplicate log entries (Anonymous followed by authenticated user)
    $this->logger->info('Customer profile creation request from authenticated user: @username', [
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
// add field field_phone 
      if ($customer_profile->hasField('field_phone')) {
        $customer_profile->set('field_phone', $data['field_phone']);
      }

      // Add field text value multiple family member
      if ($customer_profile->hasField('field_address')) {
        $customer_profile->set('field_address', $data['field_address']);
      }


      // Handle paragraph field: field_measurement
      if (!empty($data['field_measurement']) && $customer_profile->hasField('field_measurement')) {
        // Get paragraph data (can be object or array)
        $paragraph_data = $data['field_measurement'];
        
        // Create paragraph entity
        $paragraph = Paragraph::create([
          'type' => 'measurement',
        ]);
// field_family_members  same like below 
        if (isset($paragraph_data['field_family_members'])) {
          $paragraph->set('field_family_members', $paragraph_data['field_family_members']);
        }
        // Set field_kam_lenght if provided
        if (isset($paragraph_data['field_kam_lenght'])) {
          $paragraph->set('field_kam_lenght', $paragraph_data['field_kam_lenght']);
        }



        // Save the paragraph first
        $paragraph->save();

        // Set the paragraph reference on the customer profile (limit 1 as per field config)
        $customer_profile->set('field_measurement', $paragraph);
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

      // Add field field_phone 
      if ($customer_profile->hasField('field_phone')) {
        $response_data['field_phone'] = $customer_profile->get('field_phone')->value;
      }

      // Add field text value multiple family member
      if ($customer_profile->hasField('field_address')) {
        $address = $customer_profile->get('field_address')->getValue();
        $response_data['field_address'] = $address;
      }


      // Add paragraph field data if it exists
      if ($customer_profile->hasField('field_measurement') && !$customer_profile->get('field_measurement')->isEmpty()) {
        $paragraph = $customer_profile->get('field_measurement')->entity;
        if ($paragraph) {
          $response_data['field_measurement'] = [
            'pid' => $paragraph->id(),
            'field_family_members' => $paragraph->hasField('field_family_members') && !$paragraph->get('field_family_members')->isEmpty() 
              ? $paragraph->get('field_family_members')->value 
              : NULL,

            'field_kam_lenght' => $paragraph->hasField('field_kam_lenght') && !$paragraph->get('field_kam_lenght')->isEmpty() 
              ? $paragraph->get('field_kam_lenght')->value 
              : NULL,
          ];
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
   * @param int $nid
   *   The customer profile node ID.
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
    // Check if user is authenticated
    if ($this->currentUser->isAnonymous()) {
      $this->logger->error('Access denied: User is anonymous for PATCH request');
      throw new AccessDeniedHttpException('Authentication required.');
    }

    $this->logger->info('Customer profile update request for nid @nid from user: @username', [
      '@nid' => $nid,
      '@username' => $this->currentUser->getAccountName(),
    ]);

    try {
      // Load the customer profile node
      $customer_profile = Node::load($nid);

      // Check if customer profile exists
      if (!$customer_profile || $customer_profile->bundle() !== 'customer_profile') {
        $this->logger->warning('Customer profile not found for update: @nid', ['@nid' => $nid]);
        throw new NotFoundHttpException('Customer profile not found.');
      }

      // Check if user has permission to update this customer profile
      if (!$customer_profile->access('update', $this->currentUser)) {
        $this->logger->warning('User @username does not have permission to update customer profile @nid', [
          '@username' => $this->currentUser->getAccountName(),
          '@nid' => $nid,
        ]);
        throw new AccessDeniedHttpException('You do not have permission to update this customer profile.');
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

      // Update field field_phone 
      if (isset($data['field_phone']) && $customer_profile->hasField('field_phone')) {
        $customer_profile->set('field_phone', $data['field_phone']);
      }

      // Update field text value multiple family member
      if (isset($data['field_address']) && $customer_profile->hasField('field_address')) {
        $customer_profile->set('field_address', $data['field_address']);
      }


    
      // Update paragraph field: field_measurement
      if (isset($data['field_measurement']) && $customer_profile->hasField('field_measurement')) {
        $paragraph_data = $data['field_measurement'];
        
        // Get existing paragraph or create new one
        $existing_paragraph = NULL;
        if (!$customer_profile->get('field_measurement')->isEmpty()) {
          $existing_paragraph = $customer_profile->get('field_measurement')->entity;
        }
        
        if ($existing_paragraph) {
          // Update existing paragraph
          if (isset($paragraph_data['field_family_members'])) {
            $existing_paragraph->set('field_family_members', $paragraph_data['field_family_members']);
          }
          if (isset($paragraph_data['field_kam_lenght'])) {
            $existing_paragraph->set('field_kam_lenght', $paragraph_data['field_kam_lenght']);
          }
          $existing_paragraph->save();
        }
        else {
          // Create new paragraph
          $paragraph = Paragraph::create([
            'type' => 'Measurement',
          ]);
          
          if (isset($paragraph_data['field_family_members'])) {
            $paragraph->set('field_family_members', $paragraph_data['field_family_members']);
          }
          if (isset($paragraph_data['field_kam_lenght'])) {
            $paragraph->set('field_kam_lenght', $paragraph_data['field_kam_lenght']);
          }
          
          $paragraph->save();
          $customer_profile->set('field_measurement', $paragraph);
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

      // Save the customer profile
      $customer_profile->save();

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

      // Add field field_phone 
      if ($customer_profile->hasField('field_phone')) {
        $response_data['field_phone'] = $customer_profile->get('field_phone')->value;
      }

      // Add field text value multiple family member
      if ($customer_profile->hasField('field_address')) {
        $address = $customer_profile->get('field_address')->getValue();
        $response_data['field_address'] = $address;
      }


      // Add paragraph field data if it exists
      if ($customer_profile->hasField('field_measurement') && !$customer_profile->get('field_measurement')->isEmpty()) {
        $paragraph = $customer_profile->get('field_measurement')->entity;
        if ($paragraph) {
          $response_data['field_measurement'] = [
            'pid' => $paragraph->id(),
            'field_family_members' => $paragraph->hasField('field_family_members') && !$paragraph->get('field_family_members')->isEmpty() 
              ? $paragraph->get('field_family_members')->value 
              : NULL, 
              


            'field_kam_lenght' => $paragraph->hasField('field_kam_lenght') && !$paragraph->get('field_kam_lenght')->isEmpty() 
              ? $paragraph->get('field_kam_lenght')->value 
              : NULL,
          ];
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
   * @param int $nid
   *   The customer profile node ID.
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

    $this->logger->info('Customer profile delete request for nid @nid from user: @username', [
      '@nid' => $nid,
      '@username' => $this->currentUser->getAccountName(),
    ]);

    try {
      // Load the customer profile node
      $customer_profile = Node::load($nid);

      // Check if customer profile exists
      if (!$customer_profile || $customer_profile->bundle() !== 'customer_profile') {
        $this->logger->warning('Customer profile not found for deletion: @nid', ['@nid' => $nid]);
        throw new NotFoundHttpException('Customer profile not found.');
      }

      // Check if user has permission to delete this customer profile
      if (!$customer_profile->access('delete', $this->currentUser)) {
        $this->logger->warning('User @username does not have permission to delete customer profile @nid', [
          '@username' => $this->currentUser->getAccountName(),
          '@nid' => $nid,
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

