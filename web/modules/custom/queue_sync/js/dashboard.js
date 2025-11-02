/**
 * @file
 * Dashboard JavaScript for Queue Sync module.
 */

(function (Drupal) {
  'use strict';

  /**
   * Auto-refresh functionality for the dashboard (optional enhancement).
   */
  Drupal.behaviors.queueSyncDashboard = {
    attach: function (context, settings) {
      // Add any interactive enhancements here
      // For example: auto-refresh, tooltips, etc.
      
      // Example: Add refresh button functionality
      const refreshButton = context.querySelector('.queue-sync-refresh-btn');
      if (refreshButton) {
        refreshButton.addEventListener('click', function(e) {
          e.preventDefault();
          window.location.reload();
        });
      }
    }
  };

})(Drupal);

