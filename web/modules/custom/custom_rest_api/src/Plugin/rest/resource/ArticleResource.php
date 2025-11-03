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
 * Provides an Article Resource with JWT Authentication.
 *
 * @RestResource(
 *   id = "article_resource",
 *   label = @Translation("Article Resource"),
 *   uri_paths = {
 *     "create" = "/api/tailor/article"
 *   }
 * )
 */
class ArticleResource extends ResourceBase {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * Constructs a new ArticleResource object.
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
   * Responds to POST requests for creating articles.
   *
   * @param array $data
   *   The request data containing article fields.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response object with created article data.
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
    $this->logger->info('Article creation request from authenticated user: @username', [
      '@username' => $this->currentUser->getAccountName(),
      'uid' => (int) $this->currentUser->id(),
    ]);

    // Extract and validate required fields
    $title = !empty($data['title']) ? $data['title'] : NULL;
    $body = !empty($data['body']) ? $data['body'] : NULL;
    $body_format = !empty($data['body_format']) ? $data['body_format'] : 'basic_html';

    // Validate required fields
    if (empty($title)) {
      $this->logger->warning('Article creation attempt with missing title');
      throw new BadRequestHttpException('Title is required.');
    }

    try {
      // Create article node
      $article = Node::create([
        'type' => 'article', // Change to your actual article bundle name
        'title' => $title,
        'body' => [
          'value' => $body ?: '',
          'format' => $body_format,
        ],
        'uid' => $this->currentUser->id(),
        'status' => !empty($data['status']) ? (bool) $data['status'] : 1, // 1 = published
      ]);

      // Add custom fields if provided
      if (!empty($data['field_image']) && isset($data['field_image']['data'])) {
        // Handle image field if exists
        // This is optional depending on your setup
      }

      // Save the article
      $article->save();

      // Log successful creation
      $this->logger->info('Article created successfully: @nid by user @username', [
        '@nid' => $article->id(),
        '@username' => $this->currentUser->getAccountName(),
      ]);

      // Prepare response data
      $response_data = [
        'message' => 'Article created successfully',
        'nid' => $article->id(),
        'title' => $article->getTitle(),
        'body' => $article->get('body')->value,
        'status' => $article->isPublished(),
        'created' => $article->getCreatedTime(),
        'author' => [
          'uid' => $article->getOwnerId(),
          'name' => $this->currentUser->getAccountName(),
        ],
      ];

      return new ResourceResponse($response_data, 201);

    }
    catch (\Exception $e) {
      $this->logger->error('Article creation error: @message', [
        '@message' => $e->getMessage(),
      ]);

      throw new BadRequestHttpException('Failed to create article: ' . $e->getMessage());
    }
  }

}

