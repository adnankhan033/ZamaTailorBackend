/**
 * @file
 * Settings JavaScript for Queue Sync module.
 */

(function (Drupal, $) {
  'use strict';

  /**
   * Update the batch run time preview and minutes display when delay changes.
   */
  Drupal.behaviors.queueSyncSettings = {
    attach: function (context, settings) {
      const $delayMinutes = $('input[name="default_delay_minutes"], input[name="delay_minutes"], input.delay-minutes', context);
      const $delayMinutesDisplay = $('#delay-minutes-display', context);
      const $runTimePreview = $('#run-time-preview', context);
      
      if ($delayMinutes.length && settings.queueSync) {
        const currentTime = settings.queueSync.currentTime;
        
        // Function to format minutes for display
        const formatMinutes = function(minutes) {
          minutes = parseFloat(minutes) || 0;
          
          if (minutes == 0.5) {
            return 'half minute (30 seconds)';
          }
          if (minutes == 1) {
            return '1 minute';
          }
          if (minutes < 1) {
            const seconds = Math.round(minutes * 60);
            return minutes + ' minutes (' + seconds + ' seconds)';
          }
          
          const wholeMinutes = Math.floor(minutes);
          const decimalPart = minutes - wholeMinutes;
          
          if (decimalPart == 0) {
            return minutes + ' minutes';
          }
          
          const seconds = Math.round(decimalPart * 60);
          if (seconds == 30) {
            return wholeMinutes + ' and a half minutes';
          }
          
          return wholeMinutes + ' minutes (' + seconds + ' seconds)';
        };
        
        // Function to update previews
        const updatePreviews = function() {
          const delayMinutes = parseFloat($delayMinutes.val()) || 0;
          const totalDelaySeconds = delayMinutes * 60;
          const runTime = currentTime + totalDelaySeconds;
          
          // Update minutes display
          if ($delayMinutesDisplay.length) {
            $delayMinutesDisplay.text(formatMinutes(delayMinutes));
          }
          
          // Format the date and update run time preview
          if ($runTimePreview.length) {
            const date = new Date(runTime * 1000);
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, '0');
            const day = String(date.getDate()).padStart(2, '0');
            const hours = String(date.getHours()).padStart(2, '0');
            const mins = String(date.getMinutes()).padStart(2, '0');
            const secs = String(date.getSeconds()).padStart(2, '0');
            
            const formatted = year + '-' + month + '-' + day + ' ' + hours + ':' + mins + ':' + secs;
            $runTimePreview.text(formatted);
          }
        };
        
        // Update preview on input change
        $delayMinutes.on('input change', updatePreviews);
        
        // Initial update
        updatePreviews();
      }
    }
  };

})(Drupal, jQuery);

