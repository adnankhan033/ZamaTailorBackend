Queue Sync - Drupal custom module
---------------------------------
Install: copy the 'queue_sync' folder into modules/custom/ and enable the module.

Features:
- Admin UI at /admin/config/queue-sync
- Generate dummy batch with a button
- Configurable 'Items to process per run' at /admin/config/queue-sync/settings
- Processes due batches via hook_cron(), creating Basic Page nodes from items.
