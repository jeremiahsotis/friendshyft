<?php
if (!defined('ABSPATH')) exit;

/**
 * Event group data access and assignment helpers.
 */
class FS_Event_Groups {

    const SELECTION_ALL = 'ALL';
    const SELECTION_DAYS_ONLY = 'DAYS_ONLY';
    const SELECTION_SESSIONS_ANY = 'SESSIONS_ANY';

    /**
     * Fetch a single event group.
     */
    public static function get($group_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_event_groups WHERE id = %d",
            (int) $group_id
        ));
    }

    /**
     * Return all groups for admin listing.
     */
    public static function list_all() {
        global $wpdb;

        return $wpdb->get_results(
            "SELECT g.*,
                (SELECT COUNT(*) FROM {$wpdb->prefix}fs_opportunities o WHERE o.event_group_id = g.id) AS session_count
             FROM {$wpdb->prefix}fs_event_groups g
             ORDER BY g.created_at DESC"
        );
    }

    /**
     * Create or update an event group and sync assigned opportunities.
     */
    public static function save_group($group_data, $opportunity_ids = array()) {
        global $wpdb;

        $group_id = !empty($group_data['id']) ? (int) $group_data['id'] : 0;
        $now = current_time('mysql');

        $selection_mode = strtoupper((string) ($group_data['selection_mode'] ?? self::SELECTION_SESSIONS_ANY));
        if (!in_array($selection_mode, array(self::SELECTION_ALL, self::SELECTION_DAYS_ONLY, self::SELECTION_SESSIONS_ANY), true)) {
            $selection_mode = self::SELECTION_SESSIONS_ANY;
        }

        $day_label_mode = strtoupper((string) ($group_data['day_label_mode'] ?? 'AUTO'));
        if (!in_array($day_label_mode, array('AUTO', 'DATE_ONLY', 'DATE_AND_TIME'), true)) {
            $day_label_mode = 'AUTO';
        }

        $record = array(
            'title' => sanitize_text_field($group_data['title'] ?? ''),
            'description' => wp_kses_post($group_data['description'] ?? ''),
            'location' => sanitize_text_field($group_data['location'] ?? ''),
            'selection_mode' => $selection_mode,
            'min_select' => isset($group_data['min_select']) && $group_data['min_select'] !== '' ? (int) $group_data['min_select'] : null,
            'max_select' => isset($group_data['max_select']) && $group_data['max_select'] !== '' ? (int) $group_data['max_select'] : null,
            'day_label_mode' => $day_label_mode,
            'requires_minor_permission' => !empty($group_data['requires_minor_permission']) ? 1 : 0,
            'minor_age_threshold' => isset($group_data['minor_age_threshold']) ? (int) $group_data['minor_age_threshold'] : 18,
            'signshyft_template_version_id' => sanitize_text_field($group_data['signshyft_template_version_id'] ?? ''),
            'reminder_final_hours' => isset($group_data['reminder_final_hours']) && $group_data['reminder_final_hours'] !== '' ? (int) $group_data['reminder_final_hours'] : null,
            'reminder_recipients' => sanitize_text_field($group_data['reminder_recipients'] ?? ''),
            'updated_at' => $now,
        );

        if ($group_id > 0) {
            $wpdb->update(
                $wpdb->prefix . 'fs_event_groups',
                $record,
                array('id' => $group_id)
            );
        } else {
            $record['created_at'] = $now;
            $wpdb->insert($wpdb->prefix . 'fs_event_groups', $record);
            $group_id = (int) $wpdb->insert_id;
        }

        if ($group_id <= 0) {
            return new WP_Error('event_group_save_failed', 'Unable to save event group.');
        }

        self::sync_group_opportunities($group_id, $opportunity_ids);
        return $group_id;
    }

    /**
     * Assign opportunities to event group and unassign removed ones.
     */
    public static function sync_group_opportunities($group_id, $opportunity_ids) {
        global $wpdb;

        $group_id = (int) $group_id;
        $opportunity_ids = array_values(array_unique(array_filter(array_map('intval', (array) $opportunity_ids))));

        // Clear this group's existing assignments first.
        $wpdb->update(
            $wpdb->prefix . 'fs_opportunities',
            array('event_group_id' => null),
            array('event_group_id' => $group_id),
            array('%d'),
            array('%d')
        );

        if (empty($opportunity_ids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($opportunity_ids), '%d'));
        $query = "UPDATE {$wpdb->prefix}fs_opportunities
                  SET event_group_id = %d
                  WHERE id IN ($placeholders)";

        $params = array_merge(array($group_id), $opportunity_ids);
        $wpdb->query($wpdb->prepare($query, ...$params));
    }

    /**
     * Fetch sessions for group with shift-aware remaining capacity.
     */
    public static function get_sessions($group_id) {
        global $wpdb;

        $opportunities = $wpdb->get_results($wpdb->prepare(
            "SELECT o.*
             FROM {$wpdb->prefix}fs_opportunities o
             WHERE o.event_group_id = %d
             ORDER BY COALESCE(o.datetime_start, CONCAT(o.event_date, ' 00:00:00')) ASC, o.id ASC",
            (int) $group_id
        ));

        if (empty($opportunities)) {
            return array();
        }

        $sessions = array();
        foreach ($opportunities as $opportunity) {
            $shifts = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_opportunity_shifts
                 WHERE opportunity_id = %d AND is_template = 0
                 ORDER BY display_order ASC, shift_start_time ASC",
                (int) $opportunity->id
            ));

            if (empty($shifts)) {
                $remaining = (int) $opportunity->spots_available - (int) $opportunity->spots_filled;
                $sessions[] = array(
                    'opportunity_id' => (int) $opportunity->id,
                    'shift_id' => null,
                    'session_key' => self::build_session_key($opportunity->id, null),
                    'event_date' => $opportunity->event_date,
                    'datetime_start' => $opportunity->datetime_start,
                    'datetime_end' => $opportunity->datetime_end,
                    'title' => $opportunity->title,
                    'remaining_spots' => max(0, $remaining),
                    'spots_available' => (int) $opportunity->spots_available,
                    'spots_filled' => (int) $opportunity->spots_filled,
                    'is_shift_based' => false,
                );
                continue;
            }

            foreach ($shifts as $shift) {
                $remaining = (int) $shift->spots_available - (int) $shift->spots_filled;
                $start_dt = $opportunity->event_date . ' ' . $shift->shift_start_time;
                $end_dt = $opportunity->event_date . ' ' . $shift->shift_end_time;

                $sessions[] = array(
                    'opportunity_id' => (int) $opportunity->id,
                    'shift_id' => (int) $shift->id,
                    'session_key' => self::build_session_key($opportunity->id, $shift->id),
                    'event_date' => $opportunity->event_date,
                    'datetime_start' => $start_dt,
                    'datetime_end' => $end_dt,
                    'title' => $opportunity->title,
                    'remaining_spots' => max(0, $remaining),
                    'spots_available' => (int) $shift->spots_available,
                    'spots_filled' => (int) $shift->spots_filled,
                    'is_shift_based' => true,
                );
            }
        }

        return $sessions;
    }

    /**
     * Session key helper used by public submit handlers.
     */
    public static function build_session_key($opportunity_id, $shift_id = null) {
        $opportunity_id = (int) $opportunity_id;
        $shift_id = $shift_id === null ? 'none' : (string) (int) $shift_id;
        return $opportunity_id . ':' . $shift_id;
    }
}
