<?php

namespace Drupal\custom_rest_api\Plugin\rest\resource;

use Drupal\rest\Plugin\ResourceBase;
use Drupal\rest\ResourceResponse;
use Drupal\node\Entity\Node;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Psr\Log\LoggerInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Provides an Article Resource with JWT Authentication.
 *
 * @RestResource(
 *   id = "article_resource",
 *   label = @Translation("Article Resource"),
 *   uri_paths = {
 *     "create" = "/api/tailor/article",
 *     "canonical" = "/api/tailor/article/{nid}"
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
   * Responds to GET requests for retrieving an article.
   *
   * @param int $nid
   *   The article node ID.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response object with article data.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when user doesn't have permission.
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the article is not found.
   */
  public function get($nid) {
    // Check if user is authenticated
    if ($this->currentUser->isAnonymous()) {
      $this->logger->error('Access denied: User is anonymous for GET request');
      throw new AccessDeniedHttpException('Authentication required.');
    }

    $this->logger->info('Article retrieval request for nid @nid from user: @username', [
      '@nid' => $nid,
      '@username' => $this->currentUser->getAccountName(),
    ]);

    try {
      // Load the article node
      $article = Node::load($nid);

      // Check if article exists
      if (!$article || $article->bundle() !== 'article') {
        $this->logger->warning('Article not found: @nid', ['@nid' => $nid]);
        throw new NotFoundHttpException('Article not found.');
      }

      // Check if user has permission to view this article
      if (!$article->access('view', $this->currentUser)) {
        $this->logger->warning('User @username does not have permission to view article @nid', [
          '@username' => $this->currentUser->getAccountName(),
          '@nid' => $nid,
        ]);
        throw new AccessDeniedHttpException('You do not have permission to view this article.');
      }

      // Prepare response data
      $response_data = [
        'nid' => $article->id(),
        'title' => $article->getTitle(),
        'body' => $article->get('body')->value,
        'status' => $article->isPublished(),
        'created' => $article->getCreatedTime(),
        'updated' => $article->getChangedTime(),
        'author' => [
          'uid' => $article->getOwnerId(),
          'name' => $article->getOwner()->getAccountName(),
        ],
      ];

      return new ResourceResponse($response_data, 200);

    }
    catch (NotFoundHttpException $e) {
      throw $e;
    }
    catch (AccessDeniedHttpException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      $this->logger->error('Article retrieval error: @message', [
        '@message' => $e->getMessage(),
      ]);

      throw new BadRequestHttpException('Failed to retrieve article: ' . $e->getMessage());
    }
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

  /**
   * Responds to PATCH requests for updating articles.
   *
   * @param int $nid
   *   The article node ID.
   * @param array $data
   *   The request data containing fields to update.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response object with updated article data.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\BadRequestHttpException
   *   Thrown when the request data is invalid.
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when user doesn't have permission.
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the article is not found.
   */
  public function patch($nid, array $data) {
    // Check if user is authenticated
    if ($this->currentUser->isAnonymous()) {
      $this->logger->error('Access denied: User is anonymous for PATCH request');
      throw new AccessDeniedHttpException('Authentication required.');
    }

    $this->logger->info('Article update request for nid @nid from user: @username', [
      '@nid' => $nid,
      '@username' => $this->currentUser->getAccountName(),
    ]);

    try {
      // Load the article node
      $article = Node::load($nid);

      // Check if article exists
      if (!$article || $article->bundle() !== 'article') {
        $this->logger->warning('Article not found for update: @nid', ['@nid' => $nid]);
        throw new NotFoundHttpException('Article not found.');
      }

      // Check if user has permission to update this article
      if (!$article->access('update', $this->currentUser)) {
        $this->logger->warning('User @username does not have permission to update article @nid', [
          '@username' => $this->currentUser->getAccountName(),
          '@nid' => $nid,
        ]);
        throw new AccessDeniedHttpException('You do not have permission to update this article.');
      }

      // Update title if provided
      if (isset($data['title'])) {
        $article->setTitle($data['title']);
      }

      // Update body if provided
      if (isset($data['body'])) {
        $body_format = !empty($data['body_format']) ? $data['body_format'] : 'basic_html';
        $article->set('body', [
          'value' => $data['body'],
          'format' => $body_format,
        ]);
      }

      // Update status if provided
      if (isset($data['status'])) {
        $status = (bool) $data['status'];
        if ($status) {
          $article->setPublished();
        }
        else {
          $article->setUnpublished();
        }
      }

      // Save the article
      $article->save();

      // Log successful update
      $this->logger->info('Article updated successfully: @nid by user @username', [
        '@nid' => $article->id(),
        '@username' => $this->currentUser->getAccountName(),
      ]);

      // Prepare response data
      $response_data = [
        'message' => 'Article updated successfully',
        'nid' => $article->id(),
        'title' => $article->getTitle(),
        'body' => $article->get('body')->value,
        'status' => $article->isPublished(),
        'updated' => $article->getChangedTime(),
        'author' => [
          'uid' => $article->getOwnerId(),
          'name' => $article->getOwner()->getAccountName(),
        ],
      ];

      return new ResourceResponse($response_data, 200);

    }
    catch (NotFoundHttpException $e) {
      throw $e;
    }
    catch (AccessDeniedHttpException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      $this->logger->error('Article update error: @message', [
        '@message' => $e->getMessage(),
      ]);

      throw new BadRequestHttpException('Failed to update article: ' . $e->getMessage());
    }
  }

  /**
   * Responds to DELETE requests for deleting articles.
   *
   * @param int $nid
   *   The article node ID.
   *
   * @return \Drupal\rest\ResourceResponse
   *   The HTTP response object with deletion confirmation.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   *   Thrown when user doesn't have permission.
   * @throws \Symfony\Component\HttpKernel\Exception\NotFoundHttpException
   *   Thrown when the article is not found.
   */
  public function delete($nid) {
    // Check if user is authenticated
    if ($this->currentUser->isAnonymous()) {
      $this->logger->error('Access denied: User is anonymous for DELETE request');
      throw new AccessDeniedHttpException('Authentication required.');
    }

    $this->logger->info('Article delete request for nid @nid from user: @username', [
      '@nid' => $nid,
      '@username' => $this->currentUser->getAccountName(),
    ]);

    try {
      // Load the article node
      $article = Node::load($nid);

      // Check if article exists
      if (!$article || $article->bundle() !== 'article') {
        $this->logger->warning('Article not found for deletion: @nid', ['@nid' => $nid]);
        throw new NotFoundHttpException('Article not found.');
      }

      // Check if user has permission to delete this article
      if (!$article->access('delete', $this->currentUser)) {
        $this->logger->warning('User @username does not have permission to delete article @nid', [
          '@username' => $this->currentUser->getAccountName(),
          '@nid' => $nid,
        ]);
        throw new AccessDeniedHttpException('You do not have permission to delete this article.');
      }

      // Store data for response before deletion
      $response_data = [
        'message' => 'Article deleted successfully',
        'nid' => $article->id(),
        'title' => $article->getTitle(),
      ];

      // Delete the article
      $article->delete();

      // Log successful deletion
      $this->logger->info('Article deleted successfully: @nid by user @username', [
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
      $this->logger->error('Article deletion error: @message', [
        '@message' => $e->getMessage(),
      ]);

      throw new BadRequestHttpException('Failed to delete article: ' . $e->getMessage());
    }
  }

}

