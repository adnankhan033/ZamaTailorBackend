<?php

namespace Drupal\custom_rest_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Psr\Log\LoggerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Provides a Customer Profile List Resource with JWT Authentication.
 *
 * @RestResource(
 *   id = "customer_profile_list_resource",
 *   label = @Translation("Customer Profile List Resource"),
 *   uri_paths = {
 *     "canonical" = "/api/tailor/customer_profiles"
 *   }
 * )
 */
class CustomerProfileListResource extends ResourceBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a new CustomerProfileListResource object.
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
   * GET: Retrieve all customer profiles for the current user.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The response containing all customer profiles for the user.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when user doesn't have permission.
   */
  public function get() {
    // Check if user is authenticated via JWT
    if ($this->currentUser->isAnonymous()) {
      $this->logger->error('Access denied: User is anonymous for GET request');
      throw new AccessDeniedHttpException('Authentication required. Please provide a valid JWT token.');
    }

    try {
      // CRITICAL: Extract UID directly from JWT token to ensure we use the correct user
      // The current_user service might be cached or incorrect, so we decode the token explicitly
      $request = \Drupal::request();
      $auth_header = $request->headers->get('Authorization');
      $jwt_uid = NULL;
      
      if (!empty($auth_header) && strpos($auth_header, 'Bearer ') === 0) {
        $raw_jwt = substr($auth_header, 7);
        try {
          // Decode JWT to get the actual UID from token
          $transcoder = \Drupal::service('jwt.transcoder');
          $jwt = $transcoder->decode($raw_jwt);
          $payload = $jwt->getPayload();
          
          // Convert payload to array recursively
          $payload = json_decode(json_encode($payload), TRUE);
          
          // Get UID from token payload
          if (isset($payload['drupal']['uid'])) {
            $jwt_uid = $payload['drupal']['uid'];
          } elseif (isset($payload['drupal']) && is_array($payload['drupal']) && isset($payload['drupal']['uid'])) {
            $jwt_uid = $payload['drupal']['uid'];
          }
          
          $this->logger->info('JWT Token decoded - UID from token: @jwt_uid, current_user service UID: @current_uid', [
            '@jwt_uid' => $jwt_uid,
            '@current_uid' => $this->currentUser->id(),
          ]);
        } catch (\Exception $e) {
          $this->logger->warning('Failed to decode JWT token: @message', [
            '@message' => $e->getMessage(),
          ]);
        }
      }
      
      // Use JWT token UID if available, otherwise fall back to current_user service
      $uid = $jwt_uid !== NULL ? (int) $jwt_uid : $this->currentUser->id();
      $username = $this->currentUser->getAccountName();
      
      // CRITICAL: Verify JWT token user ID matches the current user
      // This ensures we're using the authenticated user from the JWT token
      $this->logger->info('JWT Authenticated User - Username: @username, UID: @uid (from JWT token)', [
        '@username' => $username,
        '@uid' => $uid,
      ]);
      
      // If JWT UID doesn't match current_user service, log a warning
      if ($jwt_uid !== NULL && $jwt_uid != $this->currentUser->id()) {
        $this->logger->warning('JWT UID mismatch! JWT token UID: @jwt_uid, current_user service UID: @current_uid. Using JWT token UID.', [
          '@jwt_uid' => $jwt_uid,
          '@current_uid' => $this->currentUser->id(),
        ]);
      }

      // Query all customer profiles STRICTLY for the current JWT-authenticated user (author)
      // Only return profiles where the node author (uid) matches the JWT token user ID
      $query = \Drupal::entityQuery('node')
        ->condition('type', 'customer_profile')
        ->condition('uid', $uid) // CRITICAL: Only profiles where author uid = JWT token user uid
        ->accessCheck(FALSE)
        ->sort('created', 'DESC');

      $nids = $query->execute();
      
      $this->logger->info('Found @count node IDs for user @uid', [
        '@count' => count($nids),
        '@uid' => $uid,
      ]);

      $profiles = [];
      
      if (!empty($nids)) {
        foreach ($nids as $nid) {
          $node = Node::load($nid);
          
          if (!$node) {
            $this->logger->warning('Cannot load node @nid', ['@nid' => $nid]);
            continue;
          }
          
          // CRITICAL: Verify node author (uid) matches JWT token user ID
          // This is the core security check - only return profiles where author = JWT user
          $nodeOwnerId = $node->getOwnerId();
          
          if ($nodeOwnerId != $uid) {
            $this->logger->warning('SECURITY: Skipping profile @nid - author mismatch. Node author: @owner, JWT user: @uid', [
              '@nid' => $nid,
              '@owner' => $nodeOwnerId,
              '@uid' => $uid,
            ]);
            continue; // Skip profiles that don't belong to JWT-authenticated user
          }
          
          // Additional check: ensure user has view permission
          if (!$node->access('view', $this->currentUser)) {
            $this->logger->warning('Skipping profile @nid - user does not have view permission', [
              '@nid' => $nid,
            ]);
            continue;
          }
          
          // Profile passed all checks - belongs to JWT user
          $this->logger->debug('Including profile @nid - author @owner matches JWT user @uid', [
            '@nid' => $nid,
            '@owner' => $nodeOwnerId,
            '@uid' => $uid,
          ]);
            $profile_data = [
              'nid' => $node->id(),
              'title' => $node->getTitle(),
              'status' => $node->isPublished(),
              'created' => $node->getCreatedTime(),
              'updated' => $node->getChangedTime(),
              'author' => [
                'uid' => $node->getOwnerId(),
                'name' => $node->getOwner()->getAccountName(),
              ],
            ];

            // Add field_local_app_unique_id
            if ($node->hasField('field_local_app_unique_id') && !$node->get('field_local_app_unique_id')->isEmpty()) {
              $profile_data['field_local_app_unique_id'] = $node->get('field_local_app_unique_id')->value;
            }

            // Add field_phone
            if ($node->hasField('field_phone') && !$node->get('field_phone')->isEmpty()) {
              $profile_data['field_phone'] = $node->get('field_phone')->value;
            }

            // Add field_address
            if ($node->hasField('field_address') && !$node->get('field_address')->isEmpty()) {
              $address = $node->get('field_address')->getValue();
              $profile_data['field_address'] = $address[0]['value'];
            }

            // Add field_measurement (paragraphs)
            if ($node->hasField('field_measurement') && !$node->get('field_measurement')->isEmpty()) {
              $paragraphs_data = [];
              foreach ($node->get('field_measurement') as $paragraph_item) {
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
                $profile_data['field_measurement'] = $paragraphs_data;
              }
            }

            $profiles[] = $profile_data;
        }
      }

      // Final verification: Ensure all profiles belong to JWT user
      $invalidProfiles = [];
      foreach ($profiles as $profile) {
        $profileAuthorId = isset($profile['author']['uid']) ? $profile['author']['uid'] : NULL;
        if ($profileAuthorId != $uid) {
          $invalidProfiles[] = $profile['nid'];
        }
      }
      
      if (!empty($invalidProfiles)) {
        $this->logger->error('SECURITY ERROR: Found profiles with wrong author! Node IDs: @nids', [
          '@nids' => implode(', ', $invalidProfiles),
        ]);
        // Remove invalid profiles
        $profiles = array_filter($profiles, function($profile) use ($uid) {
          $profileAuthorId = isset($profile['author']['uid']) ? $profile['author']['uid'] : NULL;
          return $profileAuthorId == $uid;
        });
        $profiles = array_values($profiles); // Re-index array
      }

      $this->logger->info('Returning @count customer profiles for JWT user @uid (@username)', [
        '@count' => count($profiles),
        '@uid' => $uid,
        '@username' => $username,
      ]);

      return new ResourceResponse($profiles, 200);
    }
    catch (\Exception $e) {
      $this->logger->error('Error fetching all customer profiles: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new BadRequestHttpException('Failed to retrieve customer profiles: ' . $e->getMessage());
    }
  }

}

