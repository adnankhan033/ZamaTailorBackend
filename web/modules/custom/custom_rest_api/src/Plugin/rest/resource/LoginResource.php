<?php

namespace Drupal\custom_rest_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Psr\Log\LoggerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Provides a Login Resource with JWT Token.
 *
 * @RestResource(
 *   id = "login_resource",
 *   label = @Translation("Login Resource"),
 *   uri_paths = {
 *     "create" = "/api/tailor/login"
 *   }
 * )
 */
class LoginResource extends ResourceBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a new LoginResource object.
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
   * Responds to POST requests for user login.
   *
   * @param array $data
   *   The request data containing username and password.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response object with JWT token.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   Thrown when the request data is invalid.
   * @throws \Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException
   *   Thrown when authentication fails.
   */
  public function post(array $data) {
    // Note: We don't log the initial attempt here to avoid showing Anonymous
    // The actual authentication attempts are logged after validation

    // Extract and validate input data
    $username = !empty($data['username']) ? $data['username'] : (!empty($data['name'][0]['value']) ? $data['name'][0]['value'] : NULL);
    $password = !empty($data['password']) ? $data['password'] : (!empty($data['pass'][0]['value']) ? $data['pass'][0]['value'] : NULL);

    // Validate required fields
    if (empty($username) || empty($password)) {
      $this->logger->warning('Login attempt with missing credentials');
      throw new BadRequestHttpException('Username and password are required.');
    }

    try {
      // Load user by username or email
      $users = \Drupal::entityTypeManager()
        ->getStorage('user')
        ->loadByProperties(['name' => $username]);

      // If not found by username, try email
      if (empty($users)) {
        $users = \Drupal::entityTypeManager()
          ->getStorage('user')
          ->loadByProperties(['mail' => $username]);
      }

      if (empty($users)) {
        $this->logger->warning('Login attempt with invalid username: @username', [
          '@username' => $username,
        ]);
        throw new UnauthorizedHttpException('', 'Invalid username or password.');
      }

      // Load the user entity
      $user = \Drupal\user\Entity\User::load(reset($users)->id());

      // Log login attempt with user context to show correct username in log viewer
      // The 'uid' in context is what determines the "User" column in watchdog
      $this->logger->info('API Login attempt for: @username from IP: @ip', [
        '@ip' => \Drupal::request()->getClientIp(),
        '@username' => $username,
        'uid' => (int) $user->id(), // Force integer type for DB
      ]);

      // Verify the password
      $auth = \Drupal::service('user.auth');
      $uid = $auth->authenticate($username, $password);

      if (!$uid || $uid !== $user->id()) {
        $this->logger->warning('Failed login attempt for user: @username', [
          '@username' => $username,
        ]);
        throw new UnauthorizedHttpException('', 'Invalid username or password.');
      }

      // Check if user is active (status = 1 means active, 0 means blocked)
      $status = $user->get('status')->value;
      if ($status == 0) {
        $this->logger->warning('Login attempt for blocked user: @username', [
          '@username' => $username,
        ]);
        throw new UnauthorizedHttpException('', 'Your account has been blocked. Please contact the administrator.');
      }

      // Generate JWT token with proper user context
      // Temporarily set the current user so the token gets the correct UID
      $temp_original_user = \Drupal::currentUser();
      \Drupal::getContainer()->get('account_switcher')->switchTo($user);
      
      try {
        $jwt = \Drupal::service('jwt.authentication.jwt');
        $token = $jwt->generateToken();
      }
      finally {
        // Always restore the original user
        \Drupal::getContainer()->get('account_switcher')->switchTo($temp_original_user);
      }

      if (!$token) {
        $this->logger->error('Failed to generate JWT token for user: @username', [
          '@username' => $username,
          'uid' => (int) $user->id(),
        ]);
        throw new BadRequestHttpException('Unable to generate authentication token.');
      }

      // Get user roles
      $roles = $user->getRoles();
      $user_roles = array_values($roles);

      // Log successful login with user context to show correct username in log viewer
      // The 'uid' in context is what determines the "User" column in watchdog
      $this->logger->info('Successful API login: @username (UID: @uid) from IP: @ip', [
        '@username' => $username,
        '@uid' => $user->id(),
        '@ip' => \Drupal::request()->getClientIp(),
        'uid' => (int) $user->id(), // Force integer type for DB
      ]);

      // Prepare response data
      $response_data = [
        'token' => $token,
        'uid' => $user->id(),
        'name' => $user->getAccountName(),
        'mail' => $user->getEmail(),
        'status' => $user->get('status')->value,
        'roles' => $user_roles,
        'created' => $user->get('created')->value,
        'access' => $user->get('access')->value,
        'login' => $user->get('login')->value,
      ];

      return new ResourceResponse($response_data, 200);

    } catch (\Exception $e) {
      $this->logger->error('Login error: @message', [
        '@message' => $e->getMessage(),
      ]);

      // Re-throw HTTP exceptions as-is
      if ($e instanceof UnauthorizedHttpException || $e instanceof BadRequestHttpException) {
        throw $e;
      }

      // Generic error for unexpected exceptions
      throw new BadRequestHttpException('An error occurred during login: ' . $e->getMessage());
    }
  }

}

