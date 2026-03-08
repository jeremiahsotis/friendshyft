
<?php
if (!defined('ABSPATH')) exit;

class FS_Eligibility_Checker {
    
    public static function check($volunteer, $opportunity) {
        global $wpdb;
        
        // Check if opportunity is still open
        if ($opportunity->status !== 'Open') {
            return array(
                'eligible' => false,
                'reason' => 'This opportunity is no longer open'
            );
        }
        
        // Check if spots available
        if ($opportunity->spots_filled >= $opportunity->spots_available) {
            return array(
                'eligible' => false,
                'reason' => 'This opportunity is full'
            );
        }
        
        // Check if in the past - use event_date, not datetime_start
        $event_timestamp = strtotime($opportunity->event_date . ' 23:59:59'); // End of event day
        if ($event_timestamp < time()) {
            return array(
                'eligible' => false,
                'reason' => 'This opportunity has already passed'
            );
        }
        
        // Check volunteer status
        if ($volunteer->volunteer_status !== 'Active') {
            return array(
                'eligible' => false,
                'reason' => 'Your volunteer status must be Active to sign up'
            );
        }
        
        // Check background check if required
        if (!empty($opportunity->requirements) && stripos($opportunity->requirements, 'background check') !== false) {
            if ($volunteer->background_check_status !== 'Approved') {
                return array(
                    'eligible' => false,
                    'reason' => 'Background check required for this opportunity'
                );
            }
            
            // Check if expired
            if ($volunteer->background_check_expiration && strtotime($volunteer->background_check_expiration) < time()) {
                return array(
                    'eligible' => false,
                    'reason' => 'Your background check has expired'
                );
            }
        }
        
        // All checks passed
        return array('eligible' => true, 'reason' => '');
    }
}
