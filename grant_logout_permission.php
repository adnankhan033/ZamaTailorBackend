<?php

/**
 * One-time script to grant logout permission to authenticated users.
 * Run this using: drush php:script grant_logout_permission.php
 */

use Drupal\user\Entity\Role;

$authenticated_role = Role::load('authenticated');

if ($authenticated_role) {
  $authenticated_role->grantPermission('restful post logout_resource');
  $authenticated_role->save();
  
  echo "✓ Logout permission granted to authenticated users.\n";
} else {
  echo "✗ Authenticated role not found.\n";
}
