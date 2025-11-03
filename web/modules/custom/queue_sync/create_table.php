<?php
/**
 * @file
 * Helper script to create queue_sync_user_records table.
 * 
 * Run this via: drush php:script create_table.php
 * Or access via: /modules/custom/queue_sync/create_table.php (if web server allows)
 */

use Drupal\Core\Database\Database;

// Load Drupal bootstrap if not already loaded.
if (!defined('DRUPAL_ROOT')) {
  require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/vendor/autoload.php';
  require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/web/core/includes/bootstrap.inc';
  drupal_bootstrap(DRUPAL_BOOTSTRAP_FULL);
}

$database = Database::getConnection();
$schema = $database->schema();

if (!$schema->tableExists('queue_sync_user_records')) {
  echo "Creating queue_sync_user_records table...\n";
  
  // Load the schema definition.
  if (function_exists('queue_sync_schema')) {
    $schema_def = queue_sync_schema();
    if (isset($schema_def['queue_sync_user_records'])) {
      try {
        $schema->createTable('queue_sync_user_records', $schema_def['queue_sync_user_records']);
        
        // Verify creation.
        if ($schema->tableExists('queue_sync_user_records')) {
          echo "✅ Table created successfully!\n";
        } else {
          echo "❌ Table creation reported success but table does not exist.\n";
        }
      } catch (\Exception $e) {
        echo "❌ Error creating table: " . $e->getMessage() . "\n";
      }
    } else {
      echo "❌ Schema definition for queue_sync_user_records not found.\n";
    }
  } else {
    echo "❌ queue_sync_schema() function not found. Please ensure module is enabled.\n";
  }
} else {
  echo "✅ Table queue_sync_user_records already exists.\n";
}

// Also check and run update hook if needed.
if (function_exists('queue_sync_update_10001')) {
  try {
    $result = queue_sync_update_10001();
    echo "Update hook result: " . $result . "\n";
  } catch (\Exception $e) {
    echo "Update hook error: " . $e->getMessage() . "\n";
  }
}

