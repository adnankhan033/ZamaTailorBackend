<?php

/**
 * @file
 * Post-update hooks for the custom_rest_api module.
 */

/**
 * Set field_app_order_shrd to 999 for all nodes in specified bundles.
 */
function custom_rest_api_post_update_set_field_app_order_shrd(&$sandbox)
{
    $storage = \Drupal::entityTypeManager()->getStorage('node');
    //   define bundles to target
    $target_bundles = ['page', 'article'];

    // Initialize sandbox on first run.
    if (!isset($sandbox['progress'])) {
        // Count total nodes to process across all target bundles.
        $query = $storage->getQuery()
            ->accessCheck(FALSE)
            ->condition('type', $target_bundles, 'IN');
        $sandbox['total'] = $query->count()->execute();
        $sandbox['progress'] = 0;
        $sandbox['current'] = 0;
        $sandbox['bundles'] = $target_bundles;
    } else {
        $target_bundles = $sandbox['bundles'];
    }

    // Get batch of nodes to process (100 at a time).
    $limit = 100;
    $query = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', $target_bundles, 'IN')
        ->range($sandbox['current'], $limit)
        ->sort('nid', 'ASC');
    $nids = $query->execute();

    if (empty($nids)) {
        $sandbox['#finished'] = 1;
        return \Drupal::translation()->translate('Completed setting field_app_order_shrd to 999 for all nodes in target bundles.');
    }

    // Load and update nodes.
    $nodes = $storage->loadMultiple($nids);
    $logger = \Drupal::logger('custom_rest_api');

    foreach ($nodes as $node) {
        /** @var \Drupal\node\Entity\Node $node */
        // Check if the field exists on this bundle.
        if (!$node->hasField('field_app_order_shrd')) {
            $logger->warning('Node @nid (bundle: @bundle) does not have field_app_order_shrd field.', [
                '@nid' => $node->id(),
                '@bundle' => $node->bundle(),
            ]);
            continue;
        }

        $field = $node->get('field_app_order_shrd');

        // Check if field is empty - use Drupal's isEmpty() method.
        // This handles NULL, empty strings, and unset values correctly.
        if ($field->isEmpty()) {
            // Set field_app_order_shrd to 999 if empty.
            $node->set('field_app_order_shrd', 999);
            $node->save();
            $sandbox['progress']++;
            $logger->info('Updated node @nid: Set field_app_order_shrd to 999.', ['@nid' => $node->id()]);
        }
    }

    $sandbox['current'] += $limit;
    $sandbox['#finished'] = $sandbox['progress'] / $sandbox['total'];

    return \Drupal::translation()->translate('Processed @current of @total nodes.', [
        '@current' => $sandbox['progress'],
        '@total' => $sandbox['total'],
    ]);
}
