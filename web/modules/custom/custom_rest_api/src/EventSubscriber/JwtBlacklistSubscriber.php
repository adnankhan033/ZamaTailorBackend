<?php

namespace Drupal\custom_rest_api\EventSubscriber;

use Drupal\jwt\Authentication\Event\JwtAuthEvents;
use Drupal\jwt\Authentication\Event\JwtAuthValidateEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to JWT authentication events to check blacklist.
 */
class JwtBlacklistSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    $events[JwtAuthEvents::VALIDATE][] = ['onValidate', 100];
    return $events;
  }

  /**
   * Validates the JWT against the blacklist.
   *
   * @param \Drupal\jwt\Authentication\Event\JwtAuthValidateEvent $event
   *   The JWT authentication validate event.
   */
  public function onValidate(JwtAuthValidateEvent $event) {
    $jwt = $event->getToken();
    $payload = $jwt->getPayload();
    
    // Convert payload to array recursively
    $payload = json_decode(json_encode($payload), TRUE);
    
    // Get current user ID from the token
    $token_uid = NULL;
    if (isset($payload['drupal']['uid'])) {
      $token_uid = $payload['drupal']['uid'];
    }
    elseif (isset($payload['drupal']) && is_array($payload['drupal']) && isset($payload['drupal']['uid'])) {
      $token_uid = $payload['drupal']['uid'];
    }
    
    // Create a unique identifier for this token
    // Using jti (JWT ID) if available, otherwise uid + iat
    $token_id = NULL;
    if (isset($payload['jti'])) {
      $token_id = $payload['jti'];
    }
    elseif ($token_uid && isset($payload['iat'])) {
      $token_id = $token_uid . '_' . $payload['iat'];
    }
    // Fallback: try to get raw token hash
    elseif ($token_uid) {
      // Use a combination that makes the token unique
      $token_id = $token_uid . '_' . (isset($payload['exp']) ? $payload['exp'] : time());
    }
    
    if ($token_id) {
      // Create the cache key
      $cache_key = 'jwt_blacklist_' . hash('sha256', $token_id);
      
      // Check if token is in blacklist
      $cache = \Drupal::cache('data');
      $blacklisted = $cache->get($cache_key);
      
      if ($blacklisted) {
        // Token is blacklisted, mark as invalid
        $event->invalidate('Token has been invalidated (logged out)');
        
        // Log the attempt to use a blacklisted token
        \Drupal::logger('custom_rest_api')->warning('Attempted to use blacklisted JWT token (UID: @uid)', [
          '@uid' => $blacklisted->data['uid'] ?? 'unknown',
        ]);
      }
    }
  }

}
