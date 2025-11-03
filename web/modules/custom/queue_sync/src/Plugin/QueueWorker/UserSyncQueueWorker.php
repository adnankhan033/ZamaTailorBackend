<?php

namespace Drupal\queue_sync\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\queue_sync\Service\UserSyncService;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Queue worker for processing user sync records.
 *
 * @QueueWorker(
 *   id = "queue_sync_user_worker",
 *   title = @Translation("User Sync Queue Worker"),
 *   cron = {"time" = 60}
 * )
 */
class UserSyncQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * The user sync service.
   *
   * @var \Drupal\queue_sync\Service\UserSyncService
   */
  protected $userSyncService;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a UserSyncQueueWorker object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\queue_sync\Service\UserSyncService $user_sync_service
   *   The user sync service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, UserSyncService $user_sync_service, LoggerInterface $logger) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->userSyncService = $user_sync_service;
    $this->logger = $logger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('queue_sync.user_sync'),
      $container->get('logger.factory')->get('queue_sync')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data) {
    // Process user record sync.
    try {
      $user_data = $data['data'] ?? $data;
      
      if (empty($user_data['uid'])) {
        throw new \InvalidArgumentException('User ID (uid) is required');
      }

      // Process user record in bulk context.
      // This allows for efficient batch processing.
      $this->userSyncService->processUserRecordsBulk([$user_data], 'upsert');

      $this->logger->info('Processed user sync record for uid: @uid', [
        '@uid' => $user_data['uid'],
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Error processing user sync item: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

}
