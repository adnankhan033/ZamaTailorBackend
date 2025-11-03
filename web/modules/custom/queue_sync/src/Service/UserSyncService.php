<?php

namespace Drupal\queue_sync\Service;

use Drupal\Core\Database\Connection;
use Drupal\user\UserStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for efficiently syncing user records in batches.
 */
class UserSyncService {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * The user storage.
   *
   * @var \Drupal\user\UserStorageInterface
   */
  protected $userStorage;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a UserSyncService object.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger service.
   */
  public function __construct(Connection $connection, EntityTypeManagerInterface $entity_type_manager, $logger_factory) {
    $this->connection = $connection;
    $this->entityTypeManager = $entity_type_manager;
    $this->userStorage = $entity_type_manager->getStorage('user');
    $this->logger = $logger_factory->get('queue_sync');
  }

  /**
   * Get users in chunks for efficient processing.
   *
   * @param int $limit
   *   Number of users per chunk.
   * @param int $offset
   *   Offset for pagination.
   * @param array $conditions
   *   Optional conditions (e.g., ['status' => 1] for active users only).
   *
   * @return array
   *   Array of user data prepared for queuing.
   */
  public function getUserChunk(int $limit = 100, int $offset = 0, array $conditions = []) {
    $query = $this->connection->select('users_field_data', 'u');
    $query->fields('u', [
      'uid',
      'name',
      'mail',
      'status',
      'created',
      'access',
      'login',
    ]);

    // Add optional conditions.
    foreach ($conditions as $field => $value) {
      $query->condition('u.' . $field, $value);
    }

    // Exclude anonymous user (uid 0).
    $query->condition('u.uid', 0, '>');

    $query->orderBy('u.uid', 'ASC');
    $query->range($offset, $limit);

    $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    $users = [];
    foreach ($results as $user_data) {
      // Prepare user data for syncing.
      $users[] = $this->prepareUserData($user_data);
    }

    return $users;
  }

  /**
   * Get total count of users to sync.
   *
   * @param array $conditions
   *   Optional conditions.
   *
   * @return int
   *   Total user count.
   */
  public function getUserCount(array $conditions = []) {
    $query = $this->connection->select('users_field_data', 'u');
    $query->addExpression('COUNT(u.uid)', 'count');

    // Add optional conditions.
    foreach ($conditions as $field => $value) {
      $query->condition('u.' . $field, $value);
    }

    // Exclude anonymous user.
    $query->condition('u.uid', 0, '>');

    return (int) $query->execute()->fetchField();
  }

  /**
   * Prepare user data for queue processing.
   *
   * @param array $user_data
   *   Raw user data from database.
   *
   * @return array
   *   Prepared user data array.
   */
  protected function prepareUserData(array $user_data) {
    return [
      'uid' => (int) $user_data['uid'],
      'username' => $user_data['name'],
      'email' => $user_data['mail'],
      'status' => (int) $user_data['status'],
      'created' => (int) $user_data['created'],
      'access' => $user_data['access'] ? (int) $user_data['access'] : NULL,
      'login' => $user_data['login'] ? (int) $user_data['login'] : NULL,
      'sync_timestamp' => time(),
    ];
  }

  /**
   * Get users that need syncing (e.g., modified since last sync).
   *
   * @param int $last_sync_timestamp
   *   Timestamp of last sync.
   * @param int $limit
   *   Number of users per chunk.
   * @param int $offset
   *   Offset for pagination.
   *
   * @return array
   *   Array of user data that needs syncing.
   */
  public function getUsersNeedingSync(int $last_sync_timestamp, int $limit = 100, int $offset = 0) {
    $query = $this->connection->select('users_field_data', 'u');
    $query->fields('u', [
      'uid',
      'name',
      'mail',
      'status',
      'created',
      'access',
      'login',
    ]);

    // Get users modified or accessed since last sync.
    $or_group = $query->orConditionGroup()
      ->condition('u.changed', $last_sync_timestamp, '>')
      ->condition('u.access', $last_sync_timestamp, '>');

    $query->condition($or_group);
    $query->condition('u.uid', 0, '>');
    $query->orderBy('u.uid', 'ASC');
    $query->range($offset, $limit);

    $results = $query->execute()->fetchAll(\PDO::FETCH_ASSOC);

    $users = [];
    foreach ($results as $user_data) {
      $users[] = $this->prepareUserData($user_data);
    }

    return $users;
  }

  /**
   * Process user records in bulk for efficiency.
   *
   * @param array $user_records
   *   Array of user data records to process.
   * @param string $operation
   *   Operation: 'insert', 'update', or 'upsert'.
   *
   * @return int
   *   Number of records processed.
   */
  public function processUserRecordsBulk(array $user_records, string $operation = 'upsert') {
    if (empty($user_records)) {
      return 0;
    }

    // Ensure the table exists before processing (call this FIRST, before any validation).
    try {
      $this->ensureTableExists();
    }
    catch (\Exception $e) {
      $this->logger->error('CRITICAL: Cannot ensure table exists. All records will fail. Error: @message', [
        '@message' => $e->getMessage(),
      ]);
      // Return 0 so we don't process anything without a table.
      return 0;
    }

    // Log incoming data for debugging.
    $this->logger->info('Processing @count user records. First record sample: @sample', [
      '@count' => count($user_records),
      '@sample' => json_encode(reset($user_records)),
    ]);

    // Validate and filter records to ensure they have required fields.
    $valid_records = [];
    $invalid_count = 0;
    
    foreach ($user_records as $index => $record) {
      // Ensure record is an array.
      if (!is_array($record)) {
        $this->logger->warning('Invalid user record at index @index: not an array. Type: @type', [
          '@index' => $index,
          '@type' => gettype($record),
          'data' => is_object($record) ? serialize($record) : (string) $record,
        ]);
        $invalid_count++;
        continue;
      }

      // Check for uid - can be in various places.
      $uid = NULL;
      
      // First, check standard uid fields.
      if (isset($record['uid']) && !empty($record['uid'])) {
        $uid = $record['uid'];
      }
      elseif (isset($record['user_id']) && !empty($record['user_id'])) {
        $uid = $record['user_id'];
      }
      elseif (isset($record['id']) && !empty($record['id'])) {
        // Only use 'id' if it looks like a user ID (positive integer)
        $potential_uid = $record['id'];
        if (is_numeric($potential_uid) && $potential_uid > 0 && $potential_uid < 1000000) {
          $uid = $potential_uid;
        }
      }
      
      // Also check for numeric keys that might be UIDs (0, 1, 2, etc. as array indices).
      // But skip this as it's unreliable.

      if (empty($uid)) {
        // Try one more time - check if record itself is numeric (edge case).
        if (is_numeric($record) && $record > 0) {
          $uid = (int) $record;
          $record = ['uid' => $uid]; // Convert to proper array structure.
        }
        // Try to extract UID from nested data structures.
        elseif (isset($record['data']['uid'])) {
          $uid = $record['data']['uid'];
          // Merge nested data into main record.
          if (is_array($record['data'])) {
            $record = array_merge($record['data'], $record);
            unset($record['data']);
          }
        }
        elseif (isset($record['data']) && is_numeric($record['data']) && $record['data'] > 0) {
          $uid = (int) $record['data'];
          $record['uid'] = $uid;
        }
        else {
          $this->logger->warning('Invalid user record at index @index: missing uid field. Available keys: @keys. Full record: @record', [
            '@index' => $index,
            '@keys' => is_array($record) ? implode(', ', array_keys($record)) : 'N/A (not array)',
            '@record' => is_array($record) ? json_encode($record) : print_r($record, TRUE),
          ]);
          $invalid_count++;
          continue;
        }
      }

      // Ensure uid is an integer.
      $uid = (int) $uid;
      if ($uid <= 0) {
        $this->logger->warning('Invalid user record at index @index: invalid uid value @uid', [
          '@index' => $index,
          '@uid' => $uid,
        ]);
        $invalid_count++;
        continue;
      }

      // Set uid in record.
      $record['uid'] = $uid;

      // Set default values for missing fields.
      $record['username'] = $record['username'] ?? $record['name'] ?? $record['user_name'] ?? 'user_' . $uid;
      $record['email'] = $record['email'] ?? $record['mail'] ?? $record['email_address'] ?? '';
      $record['status'] = isset($record['status']) ? (int) $record['status'] : (isset($record['active']) ? (int) $record['active'] : 1);
      $record['sync_timestamp'] = $record['sync_timestamp'] ?? $record['created'] ?? time();

      $valid_records[] = $record;
    }

    if (empty($valid_records)) {
      $this->logger->error('No valid user records to process. Total records: @total, Invalid: @invalid', [
        '@total' => count($user_records),
        '@invalid' => $invalid_count,
      ]);
      return 0;
    }

    if ($invalid_count > 0) {
      $this->logger->info('Processed @valid valid records out of @total total (skipped @invalid invalid)', [
        '@valid' => count($valid_records),
        '@total' => count($user_records),
        '@invalid' => $invalid_count,
      ]);
    }

    try {
      // Use bulk operations based on operation type.
      switch ($operation) {
        case 'insert':
          return $this->bulkInsert($valid_records);

        case 'update':
          return $this->bulkUpdate($valid_records);

        case 'upsert':
        default:
          return $this->bulkUpsert($valid_records);
      }
    }
    catch (\Exception $e) {
      $this->logger->error('Error processing user records bulk: @message', [
        '@message' => $e->getMessage(),
      ]);
      throw $e;
    }
  }

  /**
   * Bulk insert user records.
   *
   * @param array $user_records
   *   User records to insert.
   *
   * @return int
   *   Number of records inserted.
   */
  protected function bulkInsert(array $user_records) {
    // Ensure table exists before inserting.
    $this->ensureTableExists();
    
    $insert = $this->connection->insert('queue_sync_user_records')
        ->fields(['uid', 'username', 'email', 'status', 'user_data', 'sync_timestamp', 'last_sync']);

    foreach ($user_records as $record) {
      // Security: Sanitize inputs before inserting.
      $insert->values([
        'uid' => (int) $record['uid'],
        'username' => isset($record['username']) ? htmlspecialchars(strip_tags($record['username']), ENT_QUOTES, 'UTF-8') : '',
        'email' => isset($record['email']) ? filter_var($record['email'], FILTER_SANITIZE_EMAIL) : '',
        'status' => isset($record['status']) ? (int) $record['status'] : 0,
        'user_data' => serialize($record),
        'sync_timestamp' => $record['sync_timestamp'],
        'last_sync' => time(),
      ]);
    }

    return $insert->execute();
  }

  /**
   * Bulk update user records.
   *
   * @param array $user_records
   *   User records to update.
   *
   * @return int
   *   Number of records updated.
   */
  protected function bulkUpdate(array $user_records) {
    // Ensure table exists before updating.
    $this->ensureTableExists();
    
    $count = 0;
    foreach ($user_records as $record) {
      // Security: Sanitize inputs before updating.
      $this->connection->update('queue_sync_user_records')
        ->fields([
          'username' => isset($record['username']) ? htmlspecialchars(strip_tags($record['username']), ENT_QUOTES, 'UTF-8') : '',
          'email' => isset($record['email']) ? filter_var($record['email'], FILTER_SANITIZE_EMAIL) : '',
          'status' => isset($record['status']) ? (int) $record['status'] : 0,
          'user_data' => serialize($record),
          'sync_timestamp' => $record['sync_timestamp'],
          'last_sync' => time(),
        ])
        ->condition('uid', $record['uid'])
        ->execute();
      $count++;
    }
    return $count;
  }

  /**
   * Bulk upsert (insert or update) user records.
   *
   * @param array $user_records
   *   User records to upsert.
   *
   * @return int
   *   Number of records processed.
   */
  protected function bulkUpsert(array $user_records) {
    // Use merge/upsert if available, otherwise do insert with ignore duplicates.
    $count = 0;
    foreach ($user_records as $record) {
      // Double-check uid exists (defensive programming).
      if (empty($record['uid']) || !is_numeric($record['uid'])) {
        // Security: Don't log full record data which may contain sensitive information.
        $this->logger->warning('Skipping record without valid uid in bulkUpsert', [
          '@uid' => isset($record['uid']) ? 'invalid: ' . gettype($record['uid']) : 'missing',
        ]);
        continue;
      }

      $uid = (int) $record['uid'];
      
      try {
        // Ensure table exists before attempting merge.
        $this->ensureTableExists();
        
        $this->connection->merge('queue_sync_user_records')
          ->key('uid', $uid)
          ->fields([
            // Security: Sanitize string inputs to prevent XSS/stored XSS in database.
            // Note: FILTER_SANITIZE_STRING is deprecated, using htmlspecialchars + strip_tags instead.
            'username' => isset($record['username']) ? htmlspecialchars(strip_tags($record['username']), ENT_QUOTES, 'UTF-8') : '',
            'email' => isset($record['email']) ? filter_var($record['email'], FILTER_SANITIZE_EMAIL) : '',
            'status' => $record['status'] ?? 0,
            'user_data' => serialize($record),
            'sync_timestamp' => $record['sync_timestamp'] ?? time(),
            'last_sync' => time(),
          ])
          ->execute();
        $count++;
      }
      catch (\Exception $e) {
        $error_message = $e->getMessage();
        $is_table_error = (strpos($error_message, 'Base table or view not found') !== FALSE || 
                          strpos($error_message, "doesn't exist") !== FALSE);
        
        // If table doesn't exist, try to create it and retry.
        if ($is_table_error) {
          $this->logger->warning('Table error detected, attempting to create table: @message', [
            '@message' => $error_message,
          ]);
          try {
            $this->ensureTableExists();
            // Retry the merge after creating the table.
            $this->connection->merge('queue_sync_user_records')
              ->key('uid', $uid)
              ->fields([
                // Security: Sanitize inputs.
                'username' => isset($record['username']) ? htmlspecialchars(strip_tags($record['username']), ENT_QUOTES, 'UTF-8') : '',
                'email' => isset($record['email']) ? filter_var($record['email'], FILTER_SANITIZE_EMAIL) : '',
                'status' => isset($record['status']) ? (int) $record['status'] : 0,
                'user_data' => serialize($record),
                'sync_timestamp' => $record['sync_timestamp'] ?? time(),
                'last_sync' => time(),
              ])
              ->execute();
            $count++;
            continue; // Success, move to next record.
          }
          catch (\Exception $retry_exception) {
            $this->logger->error('Failed to create table and retry: @message', [
              '@message' => $retry_exception->getMessage(),
            ]);
            // Fall through to update/insert fallback.
          }
        }

        $this->logger->warning('Merge failed, trying update/insert fallback: @message', [
          '@message' => $error_message,
          '@uid' => $uid,
        ]);

        // If merge fails, try update then insert.
        try {
          // Ensure table exists before update/insert.
          $this->ensureTableExists();
          
          $updated = $this->connection->update('queue_sync_user_records')
            ->fields([
              'username' => $record['username'] ?? '',
              'email' => $record['email'] ?? '',
              'status' => $record['status'] ?? 0,
              'user_data' => serialize($record),
              'sync_timestamp' => $record['sync_timestamp'] ?? time(),
              'last_sync' => time(),
            ])
            ->condition('uid', $uid)
            ->execute();

          if (!$updated) {
            $this->connection->insert('queue_sync_user_records')
              ->fields([
                'uid' => $uid,
                'username' => $record['username'] ?? '',
                'email' => $record['email'] ?? '',
                'status' => $record['status'] ?? 0,
                'user_data' => serialize($record),
                'sync_timestamp' => $record['sync_timestamp'] ?? time(),
                'last_sync' => time(),
              ])
              ->execute();
          }
          $count++;
        }
        catch (\Exception $fallback_exception) {
          $fallback_error = $fallback_exception->getMessage();
          $is_table_error_fallback = (strpos($fallback_error, 'Base table or view not found') !== FALSE || 
                                     strpos($fallback_error, "doesn't exist") !== FALSE);
          
          if ($is_table_error_fallback) {
            $this->logger->error('Table still does not exist after creation attempts. Please run: drush updb. Error: @message', [
              '@message' => $fallback_error,
              '@uid' => $uid,
            ]);
          }
          else {
            $this->logger->error('Failed to upsert user record: @message', [
              '@message' => $fallback_error,
              '@uid' => $uid,
            ]);
          }
          // Continue to next record instead of breaking.
        }
      }
    }
    return $count;
  }

  /**
   * Ensure the queue_sync_user_records table exists.
   *
   * Creates the table if it doesn't exist (for installations that haven't run updates).
   */
  protected function ensureTableExists() {
    // Double-check table existence first.
    if ($this->connection->schema()->tableExists('queue_sync_user_records')) {
      return; // Table exists, we're good.
    }

    $this->logger->warning('queue_sync_user_records table does not exist. Attempting to create it.');
    
    // Try multiple methods to get the schema.
    $schema = NULL;
    
    // Method 1: Try calling the schema function directly.
    if (function_exists('queue_sync_schema')) {
      try {
        $schema = queue_sync_schema();
        $this->logger->info('Retrieved schema via queue_sync_schema() function.');
      }
      catch (\Exception $e) {
        $this->logger->warning('Failed to call queue_sync_schema() directly: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }
    
    // Method 2: Try loading the install file and calling the function.
    if (!$schema || !isset($schema['queue_sync_user_records'])) {
      $module_path = \Drupal::service('extension.list.module')->getPath('queue_sync');
      // Security: Validate that the path is within the module directory.
      $install_file = $module_path . '/queue_sync.install';
      // Additional security check: ensure file path is valid and within expected location.
      $real_install_file = realpath($install_file);
      $real_module_path = realpath($module_path);
      if ($real_install_file && $real_module_path && 
          strpos($real_install_file, $real_module_path) === 0 && 
          basename($real_install_file) === 'queue_sync.install') {
        // Include the install file to ensure the function is available.
        include_once $real_install_file;
        
        if (function_exists('queue_sync_schema')) {
          try {
            $schema = queue_sync_schema();
            $this->logger->info('Retrieved schema via queue_sync_schema() after including install file.');
          }
          catch (\Exception $e) {
            $this->logger->warning('Failed to call queue_sync_schema() after including install file: @message', [
              '@message' => $e->getMessage(),
            ]);
          }
        }
      }
    }
    
    // If we still don't have the schema, build it manually.
    if (!$schema || !isset($schema['queue_sync_user_records'])) {
      $this->logger->info('Schema function not available, building schema manually.');
      $schema = [
        'queue_sync_user_records' => [
          'description' => 'Synced user records for queue_sync',
          'fields' => [
            'id' => ['type' => 'serial', 'not null' => TRUE],
            'uid' => ['type' => 'int', 'unsigned' => TRUE, 'not null' => TRUE],
            'username' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE],
            'email' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE],
            'status' => ['type' => 'int', 'not null' => TRUE, 'default' => 0],
            'user_data' => ['type' => 'blob', 'size' => 'big', 'not null' => FALSE],
            'sync_timestamp' => ['type' => 'int', 'not null' => TRUE],
            'last_sync' => ['type' => 'int', 'not null' => TRUE],
          ],
          'primary key' => ['id'],
          'unique keys' => [
            'uid' => ['uid'],
          ],
          'indexes' => [
            'username' => ['username'],
            'email' => ['email'],
            'sync_timestamp' => ['sync_timestamp'],
            'last_sync' => ['last_sync'],
          ],
        ],
      ];
    }
    
    // Now try to create the table.
    if (isset($schema['queue_sync_user_records'])) {
      try {
        $this->logger->info('Attempting to create queue_sync_user_records table...');
        $this->connection->schema()->createTable('queue_sync_user_records', $schema['queue_sync_user_records']);
        
        // Verify the table was created (no sleep needed, should be immediate).
        if ($this->connection->schema()->tableExists('queue_sync_user_records')) {
          $this->logger->info('✅ Successfully created queue_sync_user_records table automatically.');
          return; // Success!
        }
        else {
          throw new \Exception('Table creation reported success but table still does not exist after verification.');
        }
      }
      catch (\Exception $e) {
        $error_msg = $e->getMessage();
        $this->logger->error('❌ Failed to create queue_sync_user_records table: @message', [
          '@message' => $error_msg,
        ]);
        
        // Try to run the update hook as a fallback.
        $this->logger->info('Attempting to run update hook as fallback...');
        try {
          // Ensure the install file is included (with security validation).
          $module_path = \Drupal::service('extension.list.module')->getPath('queue_sync');
          $install_file = $module_path . '/queue_sync.install';
          // Security: Validate file path to prevent directory traversal.
          $real_install_file = realpath($install_file);
          $real_module_path = realpath($module_path);
          if ($real_install_file && $real_module_path && 
              strpos($real_install_file, $real_module_path) === 0 && 
              basename($real_install_file) === 'queue_sync.install') {
            include_once $real_install_file;
          }
          
          if (function_exists('queue_sync_update_10001')) {
            $result = queue_sync_update_10001();
            $this->logger->info('Update hook returned: @result', ['@result' => $result]);
            
            // Verify again.
            if ($this->connection->schema()->tableExists('queue_sync_user_records')) {
              $this->logger->info('✅ Table created via update hook fallback.');
              return; // Success via fallback!
            }
          }
        }
        catch (\Exception $hook_exception) {
          $this->logger->error('Update hook fallback also failed: @message', [
            '@message' => $hook_exception->getMessage(),
          ]);
        }
        
        // Last resort: try direct SQL creation.
        $this->logger->warning('Attempting direct SQL creation as last resort...');
        try {
          // Get table prefix and validate it (security: ensure no SQL injection).
          $prefix = $this->connection->tablePrefix();
          // Sanitize table name to prevent SQL injection.
          $table_name_base = 'queue_sync_user_records';
          // Validate that the base table name contains only alphanumeric and underscores.
          if (!preg_match('/^[a-zA-Z0-9_]+$/', $table_name_base)) {
            throw new \Exception('Invalid table name format.');
          }
          $table_name = $prefix . $table_name_base;
          // Escape table name properly using database-specific quoting.
          $quoted_table_name = $this->connection->escapeTable($table_name);
          
          $sql = "CREATE TABLE IF NOT EXISTS `{$quoted_table_name}` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `uid` int(10) unsigned NOT NULL,
            `username` varchar(255) NOT NULL,
            `email` varchar(255) NOT NULL,
            `status` int(11) NOT NULL DEFAULT 0,
            `user_data` longblob,
            `sync_timestamp` int(11) NOT NULL,
            `last_sync` int(11) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uid` (`uid`),
            KEY `username` (`username`),
            KEY `email` (`email`),
            KEY `sync_timestamp` (`sync_timestamp`),
            KEY `last_sync` (`last_sync`)
          ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
          
          $this->connection->query($sql);
          
          // Verify table creation.
          if ($this->connection->schema()->tableExists('queue_sync_user_records')) {
            $this->logger->info('✅ Table created via direct SQL fallback.');
            return; // Success via SQL!
          }
          else {
            $this->logger->warning('Direct SQL executed but table still does not exist after creation.');
          }
        }
        catch (\Exception $sql_exception) {
          $this->logger->error('Direct SQL creation also failed: @message', [
            '@message' => $sql_exception->getMessage(),
          ]);
        }
        
        // If all methods failed, log the error but don't throw - let the calling code handle it.
        $this->logger->critical('❌ All table creation methods failed. Please run: drush updb or create the table manually.');
        throw new \Exception('Cannot create queue_sync_user_records table. All creation methods failed. Please run: drush updb');
      }
    }
    else {
      throw new \Exception('Cannot determine schema for queue_sync_user_records table.');
    }
  }

}
