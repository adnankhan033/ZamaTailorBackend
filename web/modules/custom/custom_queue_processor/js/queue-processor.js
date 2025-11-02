/**
 * @file
 * JavaScript for Queue Processor module.
 */

(function (Drupal) {
  'use strict';

  Drupal.behaviors.queueProcessor = {
    attach: function (context, settings) {
      // Add any interactive JavaScript here if needed
      var quickSelect = document.querySelector('select[name="quick_select"]');
      var itemIdField = document.querySelector('input[name="item_id"]');
      
      if (quickSelect && itemIdField) {
        quickSelect.addEventListener('change', function() {
          if (this.value && this.value !== '0') {
            itemIdField.value = this.value;
          }
        });
      }
    }
  };

})(Drupal);

