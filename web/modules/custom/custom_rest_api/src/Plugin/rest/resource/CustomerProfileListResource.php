<?php

namespace Drupal\custom_rest_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
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
    // Check if user is authenticated
    if ($this->currentUser->isAnonymous()) {
      $this->logger->error('Access denied: User is anonymous for GET request');
      throw new AccessDeniedHttpException('Authentication required.');
    }

    try {
      $uid = $this->currentUser->id();
      
      $this->logger->info('Fetching all customer profiles for user: @username (uid: @uid)', [
        '@username' => $this->currentUser->getAccountName(),
        '@uid' => $uid,
      ]);

      // Query all customer profiles for the current user
      $query = \Drupal::entityQuery('node')
        ->condition('type', 'customer_profile')
        ->condition('uid', $uid)
        ->accessCheck(FALSE)
        ->sort('created', 'DESC');

      $nids = $query->execute();

      $profiles = [];
      
      if (!empty($nids)) {
        foreach ($nids as $nid) {
          $node = Node::load($nid);
          if ($node && $node->access('view', $this->currentUser)) {
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
      }

      $this->logger->info('Returning @count customer profiles for user @uid', [
        '@count' => count($profiles),
        '@uid' => $uid,
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

