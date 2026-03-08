<?php
if (!defined('ABSPATH')) exit;

class FS_Badges {
    
    public static function init() {
        add_action('fs_check_badges', array(__CLASS__, 'check_volunteer_badges'), 10, 1);

        // Trigger badge check after time tracking
        add_action('fs_volunteer_checkout', array(__CLASS__, 'check_volunteer_badges'), 10, 1);

        // Trigger badge check after signup
        add_action('fs_volunteer_signup', array(__CLASS__, 'check_volunteer_badges'), 10, 3);

        // Trigger badge check after workflow step completion
        add_action('fs_step_completed', array(__CLASS__, 'check_badges_after_step'), 10, 3);
    }

    /**
     * Check badges after workflow step completion
     *
     * @param object $volunteer Volunteer object
     * @param string $workflow_name Workflow name
     * @param string $step_name Step name
     */
    public static function check_badges_after_step($volunteer, $workflow_name, $step_name) {
        if ($volunteer && isset($volunteer->id)) {
            self::check_volunteer_badges($volunteer->id);
        }
    }
    
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();
        
        // Create badges table
        $badges_table = $wpdb->prefix . 'fs_volunteer_badges';
        
        $sql = "CREATE TABLE IF NOT EXISTS $badges_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            volunteer_id bigint(20) NOT NULL,
            badge_type varchar(50) NOT NULL,
            badge_level varchar(50) NOT NULL,
            earned_date datetime NOT NULL,
            notification_sent tinyint(1) DEFAULT 0,
            PRIMARY KEY (id),
            KEY volunteer_id (volunteer_id),
            KEY badge_type (badge_type),
            UNIQUE KEY unique_badge (volunteer_id, badge_type, badge_level)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Define all available badges
     */
    public static function get_badge_definitions() {
        return array(
            'hours' => array(
                'name' => 'Time Champion',
                'icon' => '⏰',
                'levels' => array(
                    '10' => array('name' => 'Getting Started', 'hours' => 10, 'color' => '#bronze'),
                    '25' => array('name' => 'Dedicated Helper', 'hours' => 25, 'color' => '#silver'),
                    '50' => array('name' => 'Committed Volunteer', 'hours' => 50, 'color' => '#gold'),
                    '100' => array('name' => 'Super Volunteer', 'hours' => 100, 'color' => '#platinum'),
                    '250' => array('name' => 'Hero', 'hours' => 250, 'color' => '#diamond'),
                    '500' => array('name' => 'Legend', 'hours' => 500, 'color' => '#ruby')
                )
            ),
            'signups' => array(
                'name' => 'Commitment Star',
                'icon' => '⭐',
                'levels' => array(
                    '5' => array('name' => 'Regular', 'count' => 5, 'color' => '#bronze'),
                    '10' => array('name' => 'Reliable', 'count' => 10, 'color' => '#silver'),
                    '25' => array('name' => 'Dependable', 'count' => 25, 'color' => '#gold'),
                    '50' => array('name' => 'Rock Star', 'count' => 50, 'color' => '#platinum'),
                    '100' => array('name' => 'All-Star', 'count' => 100, 'color' => '#diamond')
                )
            ),
            'streak' => array(
                'name' => 'Consistency Champion',
                'icon' => '🔥',
                'levels' => array(
                    '3' => array('name' => 'On a Roll', 'weeks' => 3, 'color' => '#bronze'),
                    '5' => array('name' => 'Hot Streak', 'weeks' => 5, 'color' => '#silver'),
                    '10' => array('name' => 'Unstoppable', 'weeks' => 10, 'color' => '#gold'),
                    '20' => array('name' => 'Marathon Runner', 'weeks' => 20, 'color' => '#platinum')
                )
            ),
            'early_bird' => array(
                'name' => 'Early Bird',
                'icon' => '🐦',
                'levels' => array(
                    '1' => array('name' => 'Early Bird', 'description' => 'Checked in 15+ minutes early', 'color' => '#gold')
                )
            ),
            'mentor' => array(
                'name' => 'Mentor',
                'icon' => '🎓',
                'levels' => array(
                    '1' => array('name' => 'Mentor', 'description' => 'Helped onboard a new volunteer', 'color' => '#platinum')
                )
            ),
            'anniversary' => array(
                'name' => 'Anniversary',
                'icon' => '🎂',
                'levels' => array(
                    '1' => array('name' => '1 Year', 'years' => 1, 'color' => '#silver'),
                    '2' => array('name' => '2 Years', 'years' => 2, 'color' => '#gold'),
                    '3' => array('name' => '3 Years', 'years' => 3, 'color' => '#platinum'),
                    '5' => array('name' => '5 Years', 'years' => 5, 'color' => '#diamond'),
                    '10' => array('name' => '10 Years', 'years' => 10, 'color' => '#ruby')
                )
            )
        );
    }
    
    /**
     * Check and award badges for a volunteer
     */
    public static function check_volunteer_badges($volunteer_id) {
        global $wpdb;
        
        $volunteer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
            $volunteer_id
        ));
        
        if (!$volunteer) {
            return;
        }
        
        $newly_earned = array();
        
        // Check hours badges
        $total_hours = FS_Time_Tracking::get_volunteer_hours($volunteer_id);
        $newly_earned = array_merge($newly_earned, self::check_hours_badges($volunteer_id, $total_hours));
        
        // Check signups badges
        $total_signups = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups 
             WHERE volunteer_id = %d AND status = 'confirmed'",
            $volunteer_id
        ));
        $newly_earned = array_merge($newly_earned, self::check_signups_badges($volunteer_id, $total_signups));
        
        // Check streak badges
        $newly_earned = array_merge($newly_earned, self::check_streak_badges($volunteer_id));
        
        // Check anniversary badges
        $newly_earned = array_merge($newly_earned, self::check_anniversary_badges($volunteer_id, $volunteer->created_date));
        
        // Send notifications for newly earned badges
        if (!empty($newly_earned) && class_exists('FS_Notifications')) {
            FS_Notifications::send_badge_notification($volunteer, $newly_earned);
        }
        
        return $newly_earned;
    }
    
    private static function check_hours_badges($volunteer_id, $total_hours) {
        $definitions = self::get_badge_definitions();
        $hours_badges = $definitions['hours']['levels'];
        $newly_earned = array();
        
        foreach ($hours_badges as $level_key => $level_data) {
            if ($total_hours >= $level_data['hours']) {
                $awarded = self::award_badge($volunteer_id, 'hours', $level_key, $level_data['name']);
                if ($awarded) {
                    $newly_earned[] = array(
                        'type' => 'hours',
                        'level' => $level_key,
                        'name' => $level_data['name'],
                        'icon' => $definitions['hours']['icon']
                    );
                }
            }
        }
        
        return $newly_earned;
    }
    
    private static function check_signups_badges($volunteer_id, $total_signups) {
        $definitions = self::get_badge_definitions();
        $signup_badges = $definitions['signups']['levels'];
        $newly_earned = array();
        
        foreach ($signup_badges as $level_key => $level_data) {
            if ($total_signups >= $level_data['count']) {
                $awarded = self::award_badge($volunteer_id, 'signups', $level_key, $level_data['name']);
                if ($awarded) {
                    $newly_earned[] = array(
                        'type' => 'signups',
                        'level' => $level_key,
                        'name' => $level_data['name'],
                        'icon' => $definitions['signups']['icon']
                    );
                }
            }
        }
        
        return $newly_earned;
    }
    
    private static function check_streak_badges($volunteer_id) {
        global $wpdb;
        
        // Calculate consecutive weeks with at least one signup
        $weeks = $wpdb->get_results($wpdb->prepare(
            "SELECT DISTINCT YEARWEEK(o.event_date, 1) as year_week
             FROM {$wpdb->prefix}fs_signups s
             JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
             WHERE s.volunteer_id = %d
             AND s.status = 'confirmed'
             AND o.event_date <= CURDATE()
             ORDER BY year_week DESC",
            $volunteer_id
        ));
        
        if (empty($weeks)) {
            return array();
        }
        
        // Find longest consecutive streak
        $current_streak = 1;
        $longest_streak = 1;
        
        for ($i = 0; $i < count($weeks) - 1; $i++) {
            $current_week = intval($weeks[$i]->year_week);
            $next_week = intval($weeks[$i + 1]->year_week);
            
            if ($current_week - $next_week === 1) {
                $current_streak++;
                $longest_streak = max($longest_streak, $current_streak);
            } else {
                $current_streak = 1;
            }
        }
        
        $definitions = self::get_badge_definitions();
        $streak_badges = $definitions['streak']['levels'];
        $newly_earned = array();
        
        foreach ($streak_badges as $level_key => $level_data) {
            if ($longest_streak >= $level_data['weeks']) {
                $awarded = self::award_badge($volunteer_id, 'streak', $level_key, $level_data['name']);
                if ($awarded) {
                    $newly_earned[] = array(
                        'type' => 'streak',
                        'level' => $level_key,
                        'name' => $level_data['name'],
                        'icon' => $definitions['streak']['icon']
                    );
                }
            }
        }
        
        return $newly_earned;
    }
    
    private static function check_anniversary_badges($volunteer_id, $created_date) {
        $years_volunteering = floor((time() - strtotime($created_date)) / (365 * 24 * 60 * 60));
        
        if ($years_volunteering < 1) {
            return array();
        }
        
        $definitions = self::get_badge_definitions();
        $anniversary_badges = $definitions['anniversary']['levels'];
        $newly_earned = array();
        
        foreach ($anniversary_badges as $level_key => $level_data) {
            if ($years_volunteering >= $level_data['years']) {
                $awarded = self::award_badge($volunteer_id, 'anniversary', $level_key, $level_data['name']);
                if ($awarded) {
                    $newly_earned[] = array(
                        'type' => 'anniversary',
                        'level' => $level_key,
                        'name' => $level_data['name'],
                        'icon' => $definitions['anniversary']['icon']
                    );
                }
            }
        }
        
        return $newly_earned;
    }
    
    /**
     * Award a badge to a volunteer
     * Returns true if newly awarded, false if already has it
     */
    private static function award_badge($volunteer_id, $badge_type, $badge_level, $badge_name) {
        global $wpdb;

        // Check if already has this badge
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}fs_volunteer_badges
             WHERE volunteer_id = %d AND badge_type = %s AND badge_level = %s",
            $volunteer_id,
            $badge_type,
            $badge_level
        ));

        if ($existing) {
            return false; // Already has it
        }

        // Award the badge
        $wpdb->insert(
            $wpdb->prefix . 'fs_volunteer_badges',
            array(
                'volunteer_id' => $volunteer_id,
                'badge_type' => $badge_type,
                'badge_level' => $badge_level,
                'earned_date' => current_time('mysql'),
                'notification_sent' => 0
            )
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("FriendShyft: Awarded badge '{$badge_name}' to volunteer {$volunteer_id}");
        }

        return true;
    }

    /**
     * Manually award a badge to a volunteer (public wrapper for manual awarding)
     * Returns true if newly awarded, false if already has it
     */
    public static function manual_award_badge($volunteer_id, $badge_type, $badge_level) {
        $definitions = self::get_badge_definitions();

        // Validate badge type and level
        if (!isset($definitions[$badge_type])) {
            return false;
        }

        if (!isset($definitions[$badge_type]['levels'][$badge_level])) {
            return false;
        }

        $badge_name = $definitions[$badge_type]['levels'][$badge_level]['name'];

        return self::award_badge($volunteer_id, $badge_type, $badge_level, $badge_name);
    }

    /**
     * Remove a badge from a volunteer
     * Returns true if removed, false if badge doesn't exist
     */
    public static function remove_badge($badge_id) {
        global $wpdb;

        // Get badge details before deleting
        $badge = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteer_badges WHERE id = %d",
            $badge_id
        ));

        if (!$badge) {
            return false;
        }

        // Delete the badge
        $wpdb->delete(
            $wpdb->prefix . 'fs_volunteer_badges',
            array('id' => $badge_id),
            array('%d')
        );

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("FriendShyft: Removed badge (ID: {$badge_id}) from volunteer {$badge->volunteer_id}");
        }

        return $badge;
    }
    
    /**
     * Get all badges earned by a volunteer
     */
    public static function get_volunteer_badges($volunteer_id) {
        global $wpdb;
        
        $badges = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_volunteer_badges 
             WHERE volunteer_id = %d 
             ORDER BY earned_date DESC",
            $volunteer_id
        ));
        
        $definitions = self::get_badge_definitions();
        $enriched_badges = array();
        
        foreach ($badges as $badge) {
            $def = $definitions[$badge->badge_type] ?? null;
            if ($def) {
                $level_def = $def['levels'][$badge->badge_level] ?? null;
                if ($level_def) {
                    $enriched_badges[] = array(
                        'id' => $badge->id,
                        'type' => $badge->badge_type,
                        'level' => $badge->badge_level,
                        'name' => $level_def['name'],
                        'icon' => $def['icon'],
                        'color' => $level_def['color'],
                        'earned_date' => $badge->earned_date
                    );
                }
            }
        }
        
        return $enriched_badges;
    }
    
    /**
     * Get volunteer's badge progress (next badges they can earn)
     */
    public static function get_badge_progress($volunteer_id) {
        global $wpdb;
        
        // Get current stats
        $total_hours = FS_Time_Tracking::get_volunteer_hours($volunteer_id);
        $total_signups = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups 
             WHERE volunteer_id = %d AND status = 'confirmed'",
            $volunteer_id
        ));
        
        $definitions = self::get_badge_definitions();
        $progress = array();
        
        // Hours progress
        foreach ($definitions['hours']['levels'] as $level_key => $level_data) {
            $has_badge = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fs_volunteer_badges 
                 WHERE volunteer_id = %d AND badge_type = 'hours' AND badge_level = %s",
                $volunteer_id,
                $level_key
            ));
            
            if (!$has_badge) {
                $progress[] = array(
                    'type' => 'hours',
                    'name' => $level_data['name'],
                    'icon' => $definitions['hours']['icon'],
                    'current' => $total_hours,
                    'target' => $level_data['hours'],
                    'percentage' => min(100, round(($total_hours / $level_data['hours']) * 100))
                );
                break; // Only show next badge
            }
        }
        
        // Signups progress
        foreach ($definitions['signups']['levels'] as $level_key => $level_data) {
            $has_badge = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}fs_volunteer_badges 
                 WHERE volunteer_id = %d AND badge_type = 'signups' AND badge_level = %s",
                $volunteer_id,
                $level_key
            ));
            
            if (!$has_badge) {
                $progress[] = array(
                    'type' => 'signups',
                    'name' => $level_data['name'],
                    'icon' => $definitions['signups']['icon'],
                    'current' => $total_signups,
                    'target' => $level_data['count'],
                    'percentage' => min(100, round(($total_signups / $level_data['count']) * 100))
                );
                break; // Only show next badge
            }
        }
        
        return $progress;
    }
}

FS_Badges::init();
