<?php
if (!defined('ABSPATH')) exit;

/**
 * Predictive Analytics Engine
 * Forecast volunteer needs, predict no-shows, optimize scheduling
 */
class FS_Predictive_Analytics {

    public static function init() {
        // Daily cron to update predictions
        add_action('fs_update_predictions_cron', array(__CLASS__, 'update_all_predictions'));
    }

    /**
     * Create database tables
     */
    public static function create_tables() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $predictions_table = $wpdb->prefix . 'fs_predictions';
        $sql = "CREATE TABLE IF NOT EXISTS $predictions_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            prediction_type varchar(50) NOT NULL,
            entity_type varchar(50) NOT NULL,
            entity_id bigint(20) unsigned NOT NULL,
            prediction_value decimal(10,2) NOT NULL,
            confidence_score decimal(5,2) NOT NULL,
            factors text NULL,
            calculated_at datetime NOT NULL,
            valid_until datetime NULL,
            PRIMARY KEY (id),
            KEY prediction_type (prediction_type),
            KEY entity_type (entity_type),
            KEY entity_id (entity_id),
            KEY calculated_at (calculated_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Forecast volunteer needs for upcoming opportunities
     * Based on historical fill rates, program demand, seasonality
     */
    public static function forecast_volunteer_needs($days_ahead = 30) {
        global $wpdb;

        $forecast = array();
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d', strtotime("+{$days_ahead} days"));

        // Get opportunities in date range (status 'Open' or NULL for legacy data)
        $opportunities = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities
             WHERE event_date BETWEEN %s AND %s
             AND (status = 'Open' OR status IS NULL)
             ORDER BY event_date ASC",
            $start_date,
            $end_date
        ));

        foreach ($opportunities as $opp) {
            $predicted_signups = self::predict_signup_rate($opp);
            $spots_available = $opp->spots_available > 0 ? $opp->spots_available : 1;
            $predicted_fill_rate = $predicted_signups / $spots_available;
            $confidence = self::calculate_confidence($opp);

            // Return as object for consistency with admin display code
            $forecast[] = (object) array(
                'opportunity_id' => $opp->id,
                'title' => $opp->title,
                'event_date' => $opp->event_date,
                'spots_available' => $opp->spots_available,
                'spots_filled' => $opp->spots_filled,
                'predicted_signups' => $predicted_signups,
                'predicted_fill_rate' => min($predicted_fill_rate, 1.0), // Cap at 100%
                'predicted_no_shows' => self::predict_no_show_count($opp),
                'recommended_buffer' => self::calculate_buffer_spots($opp),
                'confidence' => $confidence / 100 // Convert to decimal for display
            );
        }

        return $forecast;
    }

    /**
     * Predict signup rate for an opportunity
     */
    private static function predict_signup_rate($opportunity) {
        global $wpdb;

        // Get historical data for similar opportunities (same program, day of week)
        $day_of_week = date('w', strtotime($opportunity->event_date));
        $days_until = (strtotime($opportunity->event_date) - time()) / 86400;

        $historical = $wpdb->get_results($wpdb->prepare(
            "SELECT o.spots_available, o.spots_filled,
             DATEDIFF(o.event_date, o.created_at) as lead_time
             FROM {$wpdb->prefix}fs_opportunities o
             WHERE o.program_id = %d
             AND DAYOFWEEK(o.event_date) = %d
             AND o.event_date < CURDATE()
             AND o.status = 'published'
             ORDER BY o.event_date DESC
             LIMIT 10",
            $opportunity->program_id,
            $day_of_week + 1 // MySQL DAYOFWEEK is 1-indexed
        ));

        if (empty($historical)) {
            // No historical data, use current fill rate
            return $opportunity->spots_filled;
        }

        // Calculate average fill rate
        $total_rate = 0;
        foreach ($historical as $hist) {
            $fill_rate = $hist->spots_available > 0
                ? ($hist->spots_filled / $hist->spots_available)
                : 0;
            $total_rate += $fill_rate;
        }
        $avg_fill_rate = $total_rate / count($historical);

        // Adjust for time remaining (closer dates fill faster)
        $time_factor = 1.0;
        if ($days_until < 7) {
            $time_factor = 1.2; // 20% boost for upcoming week
        } elseif ($days_until > 21) {
            $time_factor = 0.8; // 20% reduction for far future
        }

        $predicted = ($opportunity->spots_available * $avg_fill_rate * $time_factor);
        return round(max($opportunity->spots_filled, $predicted));
    }

    /**
     * Predict no-show likelihood for volunteer
     */
    public static function predict_no_show_likelihood($volunteer_id, $opportunity_id = null) {
        global $wpdb;

        // Get volunteer's historical attendance
        $total_signups = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups
             WHERE volunteer_id = %d AND status IN ('confirmed', 'no_show')",
            $volunteer_id
        ));

        if ($total_signups == 0) {
            return array(
                'likelihood' => 0.15, // Default 15% for new volunteers
                'confidence' => 0.3,
                'factors' => array('New volunteer - limited history')
            );
        }

        $no_shows = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups
             WHERE volunteer_id = %d AND status = 'no_show'",
            $volunteer_id
        ));

        $base_rate = $no_shows / $total_signups;

        // Adjust for recent behavior (last 5 signups)
        $recent_signups = $wpdb->get_results($wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}fs_signups
             WHERE volunteer_id = %d
             ORDER BY created_at DESC
             LIMIT 5",
            $volunteer_id
        ));

        $recent_no_shows = 0;
        foreach ($recent_signups as $signup) {
            if ($signup->status === 'no_show') {
                $recent_no_shows++;
            }
        }
        $recent_rate = count($recent_signups) > 0 ? ($recent_no_shows / count($recent_signups)) : 0;

        // Weighted average (60% recent, 40% historical)
        $predicted_rate = ($recent_rate * 0.6) + ($base_rate * 0.4);

        // Adjust for opportunity-specific factors
        if ($opportunity_id) {
            $opportunity = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
                $opportunity_id
            ));

            // Check if volunteer has history with this program
            $program_signups = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups s
                 JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
                 WHERE s.volunteer_id = %d AND o.program_id = %d",
                $volunteer_id,
                $opportunity->program_id
            ));

            if ($program_signups > 3) {
                $predicted_rate *= 0.9; // 10% reduction for familiar programs
            }
        }

        $factors = array();
        if ($base_rate > 0.3) {
            $factors[] = 'High historical no-show rate (' . round($base_rate * 100) . '%)';
        }
        if ($recent_no_shows >= 2) {
            $factors[] = 'Recent no-shows in last 5 signups';
        }
        if ($total_signups < 5) {
            $factors[] = 'Limited signup history';
        }

        return array(
            'likelihood' => round($predicted_rate, 2),
            'confidence' => min($total_signups / 20, 1.0), // Max confidence at 20+ signups
            'factors' => $factors
        );
    }

    /**
     * Predict no-show count for opportunity
     */
    private static function predict_no_show_count($opportunity) {
        global $wpdb;

        // Get all confirmed signups for this opportunity
        $signups = $wpdb->get_results($wpdb->prepare(
            "SELECT volunteer_id FROM {$wpdb->prefix}fs_signups
             WHERE opportunity_id = %d AND status = 'confirmed'",
            $opportunity->id
        ));

        $total_predicted_no_shows = 0;

        foreach ($signups as $signup) {
            $prediction = self::predict_no_show_likelihood($signup->volunteer_id, $opportunity->id);
            $total_predicted_no_shows += $prediction['likelihood'];
        }

        return round($total_predicted_no_shows);
    }

    /**
     * Calculate recommended buffer spots
     */
    private static function calculate_buffer_spots($opportunity) {
        $predicted_no_shows = self::predict_no_show_count($opportunity);
        $predicted_signups = self::predict_signup_rate($opportunity);

        $spots_needed = $opportunity->spots_available;
        $current_filled = $opportunity->spots_filled;
        $predicted_total = $predicted_signups + $predicted_no_shows;

        if ($predicted_total < $spots_needed) {
            return ceil($spots_needed - $predicted_signups);
        }

        return 0;
    }

    /**
     * Calculate confidence score
     */
    private static function calculate_confidence($opportunity) {
        global $wpdb;

        $factors = 0;
        $total_factors = 3;

        // Factor 1: Historical data availability
        $historical_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_opportunities
             WHERE program_id = %d AND event_date < CURDATE()",
            $opportunity->program_id
        ));

        if ($historical_count >= 10) {
            $factors++;
        } elseif ($historical_count >= 5) {
            $factors += 0.5;
        }

        // Factor 2: Volunteer attendance history
        $signup_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups
             WHERE opportunity_id = %d",
            $opportunity->id
        ));

        if ($signup_count >= 5) {
            $factors++;
        } elseif ($signup_count >= 2) {
            $factors += 0.5;
        }

        // Factor 3: Time until event
        $days_until = (strtotime($opportunity->event_date) - time()) / 86400;
        if ($days_until <= 7) {
            $factors++; // High confidence for imminent events
        } elseif ($days_until <= 14) {
            $factors += 0.7;
        } elseif ($days_until <= 30) {
            $factors += 0.4;
        }

        return round(($factors / $total_factors) * 100);
    }

    /**
     * Optimize shift scheduling recommendations
     * Returns summary statistics for admin display
     */
    public static function optimize_shift_scheduling($program_id = null, $days_ahead = 60) {
        global $wpdb;

        // Analyze historical patterns by day of week
        $where = $program_id ? $wpdb->prepare("AND o.program_id = %d", $program_id) : "";

        $patterns = $wpdb->get_results(
            "SELECT
                DAYNAME(o.event_date) as day_name,
                DAYOFWEEK(o.event_date) as day_num,
                AVG(CASE WHEN o.spots_available > 0 THEN o.spots_filled / o.spots_available ELSE 0 END) as avg_fill_rate,
                AVG(o.spots_filled) as avg_volunteers,
                COUNT(*) as opportunity_count
             FROM {$wpdb->prefix}fs_opportunities o
             WHERE o.event_date < CURDATE()
             AND (o.status = 'Open' OR o.status = 'Closed' OR o.status IS NULL)
             $where
             GROUP BY day_num
             ORDER BY avg_fill_rate DESC"
        );

        // Calculate overall average fill rate
        $overall_stats = $wpdb->get_row(
            "SELECT
                AVG(CASE WHEN o.spots_available > 0 THEN o.spots_filled / o.spots_available ELSE 0 END) as avg_fill_rate,
                AVG(DATEDIFF(o.event_date, o.created_at)) as avg_lead_time
             FROM {$wpdb->prefix}fs_opportunities o
             WHERE o.event_date < CURDATE()
             AND (o.status = 'Open' OR o.status = 'Closed' OR o.status IS NULL)
             $where"
        );

        // Find optimal lead time (days notice that correlates with best fill rates)
        $lead_time_analysis = $wpdb->get_row(
            "SELECT AVG(DATEDIFF(o.event_date, o.created_at)) as optimal_lead_time
             FROM {$wpdb->prefix}fs_opportunities o
             WHERE o.event_date < CURDATE()
             AND (o.status = 'Open' OR o.status = 'Closed' OR o.status IS NULL)
             AND o.spots_available > 0
             AND (o.spots_filled / o.spots_available) >= 0.7
             $where"
        );

        // Best day is first in results (ordered by fill rate desc)
        $best_day = !empty($patterns) ? $patterns[0] : null;

        // Return summary for admin display
        return array(
            'best_day_name' => $best_day ? $best_day->day_name : 'N/A',
            'best_day_avg' => $best_day ? round($best_day->avg_volunteers, 1) : 0,
            'avg_fill_rate' => $overall_stats ? ($overall_stats->avg_fill_rate ?? 0) : 0,
            'optimal_lead_time' => $lead_time_analysis ? round($lead_time_analysis->optimal_lead_time ?? 14) : 14,
            'patterns' => $patterns, // Include detailed patterns if needed
            'recommendations' => self::generate_day_recommendations($patterns)
        );
    }

    /**
     * Generate recommendations for each day of the week
     */
    private static function generate_day_recommendations($patterns) {
        $recommendations = array();

        foreach ($patterns as $pattern) {
            $demand_level = 'Low';
            if ($pattern->avg_fill_rate >= 0.9) {
                $demand_level = 'Very High';
            } elseif ($pattern->avg_fill_rate >= 0.7) {
                $demand_level = 'High';
            } elseif ($pattern->avg_fill_rate >= 0.5) {
                $demand_level = 'Medium';
            }

            $recommendations[] = array(
                'day' => $pattern->day_name,
                'demand_level' => $demand_level,
                'avg_fill_rate' => round($pattern->avg_fill_rate * 100),
                'avg_volunteers' => round($pattern->avg_volunteers),
                'sample_size' => $pattern->opportunity_count,
                'recommendation' => self::generate_scheduling_recommendation($pattern)
            );
        }

        return $recommendations;
    }

    /**
     * Generate scheduling recommendation text
     */
    private static function generate_scheduling_recommendation($pattern) {
        $fill_rate = $pattern->avg_fill_rate;

        if ($fill_rate >= 0.9) {
            return "High demand day. Consider adding more opportunities or increasing capacity.";
        } elseif ($fill_rate >= 0.7) {
            return "Good volunteer engagement. Current scheduling appears optimal.";
        } elseif ($fill_rate >= 0.5) {
            return "Moderate demand. Consider reducing spots or improving promotion.";
        } else {
            return "Low fill rate. Consider consolidating shifts or offering different time slots.";
        }
    }

    /**
     * Update all predictions (cron job)
     */
    public static function update_all_predictions() {
        global $wpdb;

        // Clear old predictions
        $wpdb->query(
            "DELETE FROM {$wpdb->prefix}fs_predictions
             WHERE calculated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        // Update volunteer no-show predictions
        $volunteers = $wpdb->get_results(
            "SELECT DISTINCT volunteer_id
             FROM {$wpdb->prefix}fs_signups
             WHERE status = 'confirmed'
             AND opportunity_id IN (
                 SELECT id FROM {$wpdb->prefix}fs_opportunities
                 WHERE event_date >= CURDATE()
             )"
        );

        foreach ($volunteers as $vol) {
            $prediction = self::predict_no_show_likelihood($vol->volunteer_id);

            $wpdb->replace(
                "{$wpdb->prefix}fs_predictions",
                array(
                    'prediction_type' => 'no_show_likelihood',
                    'entity_type' => 'volunteer',
                    'entity_id' => $vol->volunteer_id,
                    'prediction_value' => $prediction['likelihood'] * 100,
                    'confidence_score' => $prediction['confidence'] * 100,
                    'factors' => json_encode($prediction['factors']),
                    'calculated_at' => current_time('mysql'),
                    'valid_until' => date('Y-m-d H:i:s', strtotime('+7 days'))
                )
            );
        }
    }

    /**
     * Get prediction for specific entity
     */
    public static function get_prediction($entity_type, $entity_id, $prediction_type) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_predictions
             WHERE entity_type = %s
             AND entity_id = %d
             AND prediction_type = %s
             AND (valid_until IS NULL OR valid_until > NOW())
             ORDER BY calculated_at DESC
             LIMIT 1",
            $entity_type,
            $entity_id,
            $prediction_type
        ));
    }

    /**
     * Get high-risk no-show volunteers for an opportunity
     */
    public static function get_high_risk_volunteers($opportunity_id, $threshold = 0.3) {
        global $wpdb;

        $signups = $wpdb->get_results($wpdb->prepare(
            "SELECT s.*, v.name, v.email
             FROM {$wpdb->prefix}fs_signups s
             JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
             WHERE s.opportunity_id = %d
             AND s.status = 'confirmed'",
            $opportunity_id
        ));

        $high_risk = array();

        foreach ($signups as $signup) {
            $prediction = self::predict_no_show_likelihood($signup->volunteer_id, $opportunity_id);

            if ($prediction['likelihood'] >= $threshold) {
                $high_risk[] = array(
                    'volunteer_id' => $signup->volunteer_id,
                    'name' => $signup->name,
                    'email' => $signup->email,
                    'risk_score' => $prediction['likelihood'],
                    'confidence' => $prediction['confidence'],
                    'factors' => $prediction['factors']
                );
            }
        }

        // Sort by risk score descending
        usort($high_risk, function($a, $b) {
            return $b['risk_score'] <=> $a['risk_score'];
        });

        return $high_risk;
    }
}
