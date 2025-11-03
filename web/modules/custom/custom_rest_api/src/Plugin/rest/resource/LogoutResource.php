<?php

namespace Drupal\custom_rest_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Psr\Log\LoggerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provides a Logout Resource for JWT authenticated users.
 *
 * @RestResource(
 *   id = "logout_resource",
 *   label = @Translation("Logout Resource"),
 *   uri_paths = {
 *     "create" = "/api/tailor/logout"
 *   }
 * )
 */
class LogoutResource extends ResourceBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a new LogoutResource object.
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
   * Responds to POST requests for user logout.
   *
   * @param array $data
   *   The request data (optional).
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request object.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response object with logout confirmation.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when user doesn't have permission.
   */
  public function post(array $data = [], ?Request $request = NULL) {
    // Check if user is authenticated via JWT
    if ($this->currentUser->isAnonymous()) {
      $this->logger->error('Logout attempt by anonymous user');
      throw new AccessDeniedHttpException('Authentication required.');
    }

    // Get current user information before logout
    $uid = $this->currentUser->id();
    $username = $this->currentUser->getAccountName();
    $ip = \Drupal::request()->getClientIp();

    // Extract JWT token from request for blacklisting
    $request = \Drupal::request();
    $auth_header = $request->headers->get('Authorization');
    $raw_jwt = FALSE;
    
    if (!empty($auth_header) && strpos($auth_header, 'Bearer ') === 0) {
      $raw_jwt = substr($auth_header, 7);
    }
    
    $token_blacklisted = FALSE;
    
    // Blacklist the JWT token if we can extract it
    if ($raw_jwt) {
      try {
        // Decode JWT to get token claims
        $transcoder = \Drupal::service('jwt.transcoder');
        $jwt = $transcoder->decode($raw_jwt);
        $payload = $jwt->getPayload();
        
        // Convert payload to array recursively
        $payload = json_decode(json_encode($payload), TRUE);
        
        // Get current user ID from the token
        $token_uid = NULL;
        if (isset($payload['drupal']['uid'])) {
          $token_uid = $payload['drupal']['uid'];
        }
        elseif (isset($payload['drupal']) && is_object($payload['drupal'])) {
          // Fallback if drupal is still an object
          $drupal = json_decode(json_encode($payload['drupal']), TRUE);
          if (isset($drupal['uid'])) {
            $token_uid = $drupal['uid'];
          }
        }
        
        // Create unique identifier for this token
        // Use jti if available, otherwise create from uid + iat
        $token_id = NULL;
        if (isset($payload['jti'])) {
          $token_id = $payload['jti'];
        }
        elseif ($token_uid && isset($payload['iat'])) {
          $token_id = $token_uid . '_' . $payload['iat'];
        }
        elseif ($token_uid) {
          $token_id = $token_uid . '_' . time();
        }
        
        if ($token_id) {
          // Store in blacklist cache
          $cache = \Drupal::cache('data');
          $cache_key = 'jwt_blacklist_' . hash('sha256', $token_id);
          
          // Set expiration to 1 hour (typical JWT expiration)
          $expiration_time = time() + 3600;
          
          $cache->set($cache_key, [
            'token_id' => $token_id,
            'uid' => $uid,
            'username' => $username,
            'blacklisted_at' => time(),
            'expires_at' => $expiration_time,
          ], $expiration_time);
          
          $token_blacklisted = TRUE;
          
          $this->logger->info('JWT token blacklisted: @username (UID: @uid, Token ID: @token_id)', [
            '@username' => $username,
            '@uid' => $uid,
            '@token_id' => $token_id,
            'uid' => (int) $uid,
          ]);
        }
      }
      catch (\Exception $e) {
        $this->logger->error('Error blacklisting JWT token: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }

    // Destroy any Drupal session
    $session_manager = \Drupal::service('session_manager');
    if ($session_manager->isStarted()) {
      $session_manager->destroy();
    }

    // Log the logout event with user context
    $this->logger->info('API logout via JWT: @username (UID: @uid) from IP: @ip @token_status', [
      '@username' => $username,
      '@uid' => $uid,
      '@ip' => $ip,
      '@token_status' => $token_blacklisted ? '(token blacklisted)' : '(token not extracted)',
      'uid' => (int) $uid, // Force integer type for DB
    ]);

    // Prepare response data
    $response_data = [
      'message' => 'Logout successful',
      'uid' => $uid,
      'name' => $username,
      'logged_out' => time(),
      'session_destroyed' => TRUE,
      'token_blacklisted' => $token_blacklisted,
      'note' => $token_blacklisted 
        ? 'JWT token has been invalidated and cannot be used anymore.' 
        : 'JWT token should be discarded by client.',
    ];

    return new ResourceResponse($response_data, 200);
  }

}

