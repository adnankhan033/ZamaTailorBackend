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
use Drupal\Core\Cache\CacheableMetadata;

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
    // Check authentication
    if ($this->currentUser->isAnonymous()) {
      throw new AccessDeniedHttpException('Authentication required. Please provide a valid JWT token.');
    }

    try {
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

      // Query customer profiles for the JWT-authenticated user
      $query = \Drupal::entityQuery('node')
        ->condition('type', 'customer_profile')
        ->condition('uid', $uid)
        ->accessCheck(FALSE)
        ->sort('created', 'DESC');

      $nids = $query->execute();
      $profiles = [];
      $cache_tags = ['node_list:customer_profile'];
      
      if (!empty($nids)) {
        // Clear cache to get fresh data
        \Drupal::entityTypeManager()->getStorage('node')->resetCache($nids);
        $nodes = Node::loadMultiple($nids);
        
        foreach ($nodes as $node) {
          // Verify node belongs to JWT user
          if ($node->getOwnerId() != $uid || !$node->access('view', $user_account)) {
            continue;
          }
          
          // Add cache tag
          $cache_tags[] = 'node:' . $node->id();
          
          // Build profile data
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

          // Add optional fields
          if ($node->hasField('field_local_app_unique_id') && !$node->get('field_local_app_unique_id')->isEmpty()) {
            $profile_data['field_local_app_unique_id'] = $node->get('field_local_app_unique_id')->value;
          }

          if ($node->hasField('field_phone') && !$node->get('field_phone')->isEmpty()) {
            $profile_data['field_phone'] = $node->get('field_phone')->value;
          }

          if ($node->hasField('field_address') && !$node->get('field_address')->isEmpty()) {
            $address = $node->get('field_address')->getValue();
            $profile_data['field_address'] = $address[0]['value'] ?? NULL;
          }

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

      // Create response with cache tags for automatic invalidation
      $response = new ResourceResponse($profiles, 200);
      $cache_metadata = CacheableMetadata::createFromRenderArray([
        '#cache' => [
          'tags' => $cache_tags,
          'contexts' => ['user'],
        ],
      ]);
      $response->addCacheableDependency($cache_metadata);
      
      return $response;

    } catch (AccessDeniedHttpException $e) {
      throw $e;
    } catch (\Exception $e) {
      $this->logger->error('Error fetching customer profiles: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw new BadRequestHttpException('Failed to retrieve customer profiles: ' . $e->getMessage());
    }
  }

}
