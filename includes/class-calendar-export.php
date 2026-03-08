<?php
if (!defined('ABSPATH')) exit;

class FS_Calendar_Export {
    
    public static function init() {
        add_action('init', array(__CLASS__, 'handle_export'));
    }
    
    public static function handle_export() {
        if (!isset($_GET['fs_export_calendar'])) {
            return;
        }
        
        $opportunity_id = intval($_GET['fs_export_calendar']);
        $shift_id = isset($_GET['shift_id']) ? intval($_GET['shift_id']) : null;
        
        global $wpdb;
        
        $opportunity = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
            $opportunity_id
        ));
        
        if (!$opportunity) {
            wp_die('Opportunity not found');
        }
        
        // Get shift details if provided
        $shift = null;
        if ($shift_id) {
            $shift = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_opportunity_shifts WHERE id = %d AND opportunity_id = %d",
                $shift_id,
                $opportunity_id
            ));
        }
        
        // Determine start and end times
        if ($shift) {
            // Use shift times
            $start_datetime = $opportunity->event_date . ' ' . $shift->shift_start_time;
            $end_datetime = $opportunity->event_date . ' ' . $shift->shift_end_time;
            $summary = $opportunity->title . ' (' . 
                      date('g:i A', strtotime($shift->shift_start_time)) . ' - ' . 
                      date('g:i A', strtotime($shift->shift_end_time)) . ')';
        } else {
            // All day event - use event_date
            $start_datetime = $opportunity->event_date . ' 09:00:00';
            $end_datetime = $opportunity->event_date . ' 17:00:00';
            $summary = $opportunity->title;
        }
        
        // Create DateTime objects
        $start = new DateTime($start_datetime);
        $end = new DateTime($end_datetime);
        
        // Format for iCal (local time format)
        $dtstart = $start->format('Ymd\THis');
        $dtend = $end->format('Ymd\THis');
        $dtstamp = gmdate('Ymd\THis\Z');
        
        // Generate unique ID
        $uid = md5($opportunity_id . '-' . ($shift_id ?? 0) . '-' . $start_datetime);
        $site_host = parse_url(home_url(), PHP_URL_HOST);
        
        // Build description
        $description = '';
        if ($opportunity->description) {
            $description .= strip_tags($opportunity->description) . '\\n\\n';
        }
        if ($opportunity->requirements) {
            $description .= 'Requirements: ' . strip_tags($opportunity->requirements) . '\\n\\n';
        }
        if ($shift) {
            $description .= 'Shift: ' . date('g:i A', strtotime($shift->shift_start_time)) . 
                          ' - ' . date('g:i A', strtotime($shift->shift_end_time));
        }
        
        // Clean description for iCal format
        $description = str_replace(array("\r\n", "\n", "\r"), '\\n', $description);
        $description = str_replace(',', '\\,', $description);
        $description = str_replace(';', '\\;', $description);
        
        // Build location
        $location = '';
        if ($opportunity->location) {
            $location = str_replace(array("\r\n", "\n", "\r", ',', ';'), array(' ', ' ', ' ', '\\,', '\\;'), $opportunity->location);
        }
        
        // Generate iCal content
        $ical = "BEGIN:VCALENDAR\r\n";
        $ical .= "VERSION:2.0\r\n";
        $ical .= "PRODID:-//FriendShyft//Volunteer Opportunity//EN\r\n";
        $ical .= "METHOD:PUBLISH\r\n";
        $ical .= "CALSCALE:GREGORIAN\r\n";
        $ical .= "BEGIN:VEVENT\r\n";
        $ical .= "UID:" . $uid . "@" . $site_host . "\r\n";
        $ical .= "DTSTAMP:" . $dtstamp . "\r\n";
        $ical .= "DTSTART:" . $dtstart . "\r\n";
        $ical .= "DTEND:" . $dtend . "\r\n";
        $ical .= "SUMMARY:" . self::escape_ical_string($summary) . "\r\n";
        
        if ($description) {
            $ical .= "DESCRIPTION:" . $description . "\r\n";
        }
        
        if ($location) {
            $ical .= "LOCATION:" . $location . "\r\n";
        }
        
        $ical .= "STATUS:CONFIRMED\r\n";
        $ical .= "SEQUENCE:0\r\n";
        $ical .= "END:VEVENT\r\n";
        $ical .= "END:VCALENDAR\r\n";
        
        // Send headers
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="volunteer-' . sanitize_file_name($opportunity->title) . '-' . $opportunity_id . '.ics"');
        header('Content-Length: ' . strlen($ical));
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
        
        echo $ical;
        exit;
    }
    
    /**
     * Escape special characters for iCal format
     */
    private static function escape_ical_string($string) {
        if (empty($string)) {
            return '';
        }
        
        // Replace line breaks with literal \n
        $string = str_replace(array("\r\n", "\n", "\r"), '\\n', $string);
        
        // Escape special characters
        $string = str_replace(',', '\\,', $string);
        $string = str_replace(';', '\\;', $string);
        $string = str_replace('\\', '\\\\', $string);
        
        return $string;
    }
    
    /**
     * Generate calendar export URL for an opportunity
     * 
     * @param int $opportunity_id Opportunity ID
     * @param int|null $shift_id Optional shift ID
     * @return string Full URL for calendar export
     */
    public static function get_export_url($opportunity_id, $shift_id = null) {
        $params = array('fs_export_calendar' => $opportunity_id);
        if ($shift_id) {
            $params['shift_id'] = $shift_id;
        }
        return add_query_arg($params, home_url('/'));
    }
}

FS_Calendar_Export::init();
