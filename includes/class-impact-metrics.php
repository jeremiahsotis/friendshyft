<?php
if (!defined('ABSPATH')) exit;

/**
 * Impact Metrics Calculator
 * Hours by program, community impact, donor-ready reports
 */
class FS_Impact_Metrics {

    public static function init() {
        // No specific hooks needed - this is a utility class
    }

    /**
     * Get hours contributed by program
     */
    public static function get_hours_by_program($start_date = null, $end_date = null) {
        global $wpdb;

        $date_where = "";
        if ($start_date && $end_date) {
            $date_where = $wpdb->prepare(
                "AND tr.check_out BETWEEN %s AND %s",
                $start_date,
                $end_date
            );
        }

        $results = $wpdb->get_results(
            "SELECT
                p.id,
                p.name,
                p.description,
                SUM(tr.hours) as total_hours,
                COUNT(DISTINCT tr.volunteer_id) as unique_volunteers,
                COUNT(tr.id) as total_shifts,
                AVG(tr.hours) as avg_hours_per_shift
             FROM {$wpdb->prefix}fs_programs p
             LEFT JOIN {$wpdb->prefix}fs_opportunities o ON p.id = o.program_id
             LEFT JOIN {$wpdb->prefix}fs_signups s ON o.id = s.opportunity_id
             LEFT JOIN {$wpdb->prefix}fs_time_records tr ON s.id = tr.signup_id
             WHERE tr.hours IS NOT NULL
             $date_where
             GROUP BY p.id
             ORDER BY total_hours DESC"
        );

        return $results;
    }

    /**
     * Calculate community impact metrics
     */
    public static function calculate_community_impact($start_date = null, $end_date = null) {
        global $wpdb;

        $date_where = "";
        if ($start_date && $end_date) {
            $date_where = $wpdb->prepare(
                "WHERE tr.check_out BETWEEN %s AND %s",
                $start_date,
                $end_date
            );
        }

        // Total hours contributed
        $total_hours = $wpdb->get_var(
            "SELECT SUM(hours) FROM {$wpdb->prefix}fs_time_records tr $date_where"
        );

        // Unique volunteers
        $unique_volunteers = $wpdb->get_var(
            "SELECT COUNT(DISTINCT volunteer_id) FROM {$wpdb->prefix}fs_time_records tr $date_where"
        );

        // Total shifts completed
        $total_shifts = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}fs_time_records tr $date_where"
        );

        // Average hours per volunteer
        $avg_hours_per_volunteer = $unique_volunteers > 0
            ? round($total_hours / $unique_volunteers, 1)
            : 0;

        // Calculate economic value (using Independent Sector's volunteer value)
        // As of 2023: $31.80/hour (update annually)
        $volunteer_hour_value = apply_filters('fs_volunteer_hour_value', 31.80);
        $economic_value = $total_hours * $volunteer_hour_value;

        // Program diversity (number of programs with activity)
        $active_programs = $wpdb->get_var(
            "SELECT COUNT(DISTINCT o.program_id)
             FROM {$wpdb->prefix}fs_opportunities o
             JOIN {$wpdb->prefix}fs_signups s ON o.id = s.opportunity_id
             JOIN {$wpdb->prefix}fs_time_records tr ON s.id = tr.signup_id
             $date_where"
        );

        // Volunteer retention rate (volunteers who returned)
        $returning_volunteers = $wpdb->get_var(
            "SELECT COUNT(DISTINCT volunteer_id)
             FROM {$wpdb->prefix}fs_time_records tr
             WHERE volunteer_id IN (
                 SELECT volunteer_id
                 FROM {$wpdb->prefix}fs_time_records
                 GROUP BY volunteer_id
                 HAVING COUNT(*) > 1
             )
             $date_where"
        );

        $retention_rate = $unique_volunteers > 0
            ? round(($returning_volunteers / $unique_volunteers) * 100)
            : 0;

        return array(
            'total_hours' => round($total_hours, 1),
            'unique_volunteers' => $unique_volunteers,
            'total_shifts' => $total_shifts,
            'avg_hours_per_volunteer' => $avg_hours_per_volunteer,
            'economic_value' => $economic_value,
            'volunteer_hour_value' => $volunteer_hour_value,
            'active_programs' => $active_programs,
            'retention_rate' => $retention_rate,
            'returning_volunteers' => $returning_volunteers
        );
    }

    /**
     * Generate donor-ready report data
     */
    public static function generate_donor_report($start_date, $end_date) {
        $impact = self::calculate_community_impact($start_date, $end_date);
        $by_program = self::get_hours_by_program($start_date, $end_date);

        // Top volunteers
        $top_volunteers = self::get_top_volunteers($start_date, $end_date, 10);

        // Monthly trend
        $monthly_trend = self::get_monthly_trend($start_date, $end_date);

        // Success stories (high engagement volunteers)
        $success_stories = self::get_success_stories($start_date, $end_date);

        return array(
            'period' => array(
                'start' => $start_date,
                'end' => $end_date,
                'label' => date('M j, Y', strtotime($start_date)) . ' - ' . date('M j, Y', strtotime($end_date))
            ),
            'impact_summary' => $impact,
            'by_program' => $by_program,
            'top_volunteers' => $top_volunteers,
            'monthly_trend' => $monthly_trend,
            'success_stories' => $success_stories,
            'key_messages' => self::generate_key_messages($impact, $by_program)
        );
    }

    /**
     * Get top volunteers by hours
     */
    private static function get_top_volunteers($start_date, $end_date, $limit = 10) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT
                v.id,
                v.name,
                SUM(tr.hours) as total_hours,
                COUNT(tr.id) as total_shifts,
                COUNT(DISTINCT o.program_id) as programs_count
             FROM {$wpdb->prefix}fs_volunteers v
             JOIN {$wpdb->prefix}fs_time_records tr ON v.id = tr.volunteer_id
             JOIN {$wpdb->prefix}fs_signups s ON tr.signup_id = s.id
             JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
             WHERE tr.check_out BETWEEN %s AND %s
             GROUP BY v.id
             ORDER BY total_hours DESC
             LIMIT %d",
            $start_date,
            $end_date,
            $limit
        ));
    }

    /**
     * Get monthly trend data
     */
    private static function get_monthly_trend($start_date, $end_date) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT
                DATE_FORMAT(tr.check_out, '%%Y-%%m') as month,
                SUM(tr.hours) as hours,
                COUNT(DISTINCT tr.volunteer_id) as volunteers,
                COUNT(tr.id) as shifts
             FROM {$wpdb->prefix}fs_time_records tr
             WHERE tr.check_out BETWEEN %s AND %s
             GROUP BY month
             ORDER BY month ASC",
            $start_date,
            $end_date
        ));
    }

    /**
     * Get success stories (highly engaged volunteers)
     */
    private static function get_success_stories($start_date, $end_date, $limit = 5) {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT
                v.id,
                v.name,
                SUM(tr.hours) as total_hours,
                COUNT(DISTINCT o.program_id) as programs_served,
                (SELECT COUNT(*) FROM {$wpdb->prefix}fs_volunteer_badges WHERE volunteer_id = v.id) as badges_earned
             FROM {$wpdb->prefix}fs_volunteers v
             JOIN {$wpdb->prefix}fs_time_records tr ON v.id = tr.volunteer_id
             JOIN {$wpdb->prefix}fs_signups s ON tr.signup_id = s.id
             JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
             WHERE tr.check_out BETWEEN %s AND %s
             GROUP BY v.id
             HAVING total_hours >= 20 OR programs_served >= 3
             ORDER BY total_hours DESC
             LIMIT %d",
            $start_date,
            $end_date,
            $limit
        ));
    }

    /**
     * Generate key messages for donors
     */
    private static function generate_key_messages($impact, $by_program) {
        $messages = array();

        // Impact message
        $messages[] = number_format($impact['unique_volunteers']) . ' volunteers contributed ' .
                     number_format($impact['total_hours']) . ' hours of service, valued at $' .
                     number_format($impact['economic_value'], 0) . '.';

        // Top program
        if (!empty($by_program)) {
            $top_program = $by_program[0];
            $messages[] = 'Our ' . $top_program->name . ' program engaged ' .
                         number_format($top_program->unique_volunteers) . ' volunteers for ' .
                         number_format($top_program->total_hours) . ' hours.';
        }

        // Retention
        if ($impact['retention_rate'] > 0) {
            $messages[] = number_format($impact['retention_rate']) . '% of volunteers returned for multiple shifts, ' .
                         'demonstrating strong program engagement.';
        }

        // Program diversity
        if ($impact['active_programs'] > 1) {
            $messages[] = 'Volunteers served across ' . $impact['active_programs'] .
                         ' different programs, showcasing the breadth of community impact.';
        }

        return $messages;
    }

    /**
     * Calculate program-specific impact metrics
     */
    public static function get_program_impact($program_id, $start_date = null, $end_date = null) {
        global $wpdb;

        $date_where = "";
        if ($start_date && $end_date) {
            $date_where = $wpdb->prepare(
                "AND tr.check_out BETWEEN %s AND %s",
                $start_date,
                $end_date
            );
        }

        $program = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_programs WHERE id = %d",
            $program_id
        ));

        $metrics = $wpdb->get_row($wpdb->prepare(
            "SELECT
                SUM(tr.hours) as total_hours,
                COUNT(DISTINCT tr.volunteer_id) as unique_volunteers,
                COUNT(tr.id) as total_shifts,
                AVG(tr.hours) as avg_shift_length,
                COUNT(DISTINCT o.id) as total_opportunities
             FROM {$wpdb->prefix}fs_opportunities o
             LEFT JOIN {$wpdb->prefix}fs_signups s ON o.id = s.opportunity_id
             LEFT JOIN {$wpdb->prefix}fs_time_records tr ON s.id = tr.signup_id
             WHERE o.program_id = %d
             AND tr.hours IS NOT NULL
             $date_where",
            $program_id
        ));

        // Calculate economic value
        $volunteer_hour_value = apply_filters('fs_volunteer_hour_value', 31.80);
        $economic_value = $metrics->total_hours * $volunteer_hour_value;

        // Get volunteer demographics for this program
        $demographics = $wpdb->get_results($wpdb->prepare(
            "SELECT
                COUNT(DISTINCT CASE WHEN tr.hours >= 10 THEN tr.volunteer_id END) as committed_volunteers,
                COUNT(DISTINCT CASE WHEN tr.hours < 10 AND tr.hours >= 5 THEN tr.volunteer_id END) as regular_volunteers,
                COUNT(DISTINCT CASE WHEN tr.hours < 5 THEN tr.volunteer_id END) as occasional_volunteers
             FROM {$wpdb->prefix}fs_opportunities o
             JOIN {$wpdb->prefix}fs_signups s ON o.id = s.opportunity_id
             JOIN {$wpdb->prefix}fs_time_records tr ON s.id = tr.signup_id
             WHERE o.program_id = %d
             $date_where",
            $program_id
        ));

        return array(
            'program' => $program,
            'total_hours' => round($metrics->total_hours, 1),
            'unique_volunteers' => $metrics->unique_volunteers,
            'total_shifts' => $metrics->total_shifts,
            'avg_shift_length' => round($metrics->avg_shift_length, 1),
            'total_opportunities' => $metrics->total_opportunities,
            'economic_value' => $economic_value,
            'volunteer_hour_value' => $volunteer_hour_value,
            'demographics' => $demographics[0]
        );
    }

    /**
     * Get year-over-year comparison
     */
    public static function get_year_over_year_comparison() {
        $current_year_start = date('Y-01-01');
        $current_year_end = date('Y-12-31');
        $last_year_start = date('Y-01-01', strtotime('-1 year'));
        $last_year_end = date('Y-12-31', strtotime('-1 year'));

        $current_year = self::calculate_community_impact($current_year_start, $current_year_end);
        $last_year = self::calculate_community_impact($last_year_start, $last_year_end);

        $comparison = array();

        foreach ($current_year as $key => $value) {
            if (is_numeric($value) && isset($last_year[$key]) && $last_year[$key] > 0) {
                $change = (($value - $last_year[$key]) / $last_year[$key]) * 100;
                $comparison[$key] = array(
                    'current' => $value,
                    'last_year' => $last_year[$key],
                    'change_percent' => round($change, 1),
                    'trend' => $change >= 0 ? 'up' : 'down'
                );
            }
        }

        return $comparison;
    }

    /**
     * Export donor report to CSV
     */
    public static function export_donor_report_csv($start_date, $end_date) {
        $report = self::generate_donor_report($start_date, $end_date);

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=impact-report-' . $start_date . '-to-' . $end_date . '.csv');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // Summary section
        fputcsv($output, array('FriendShyft Impact Report'));
        fputcsv($output, array('Period', $report['period']['label']));
        fputcsv($output, array(''));

        // Impact Summary
        fputcsv($output, array('IMPACT SUMMARY'));
        fputcsv($output, array('Total Volunteer Hours', $report['impact_summary']['total_hours']));
        fputcsv($output, array('Unique Volunteers', $report['impact_summary']['unique_volunteers']));
        fputcsv($output, array('Total Shifts Completed', $report['impact_summary']['total_shifts']));
        fputcsv($output, array('Economic Value', '$' . number_format($report['impact_summary']['economic_value'], 2)));
        fputcsv($output, array('Volunteer Retention Rate', $report['impact_summary']['retention_rate'] . '%'));
        fputcsv($output, array('Active Programs', $report['impact_summary']['active_programs']));
        fputcsv($output, array(''));

        // Hours by Program
        fputcsv($output, array('HOURS BY PROGRAM'));
        fputcsv($output, array('Program', 'Total Hours', 'Unique Volunteers', 'Total Shifts', 'Avg Hours/Shift'));
        foreach ($report['by_program'] as $program) {
            fputcsv($output, array(
                $program->name,
                round($program->total_hours, 1),
                $program->unique_volunteers,
                $program->total_shifts,
                round($program->avg_hours_per_shift, 1)
            ));
        }
        fputcsv($output, array(''));

        // Top Volunteers
        fputcsv($output, array('TOP VOLUNTEERS'));
        fputcsv($output, array('Name', 'Total Hours', 'Shifts', 'Programs'));
        foreach ($report['top_volunteers'] as $vol) {
            fputcsv($output, array(
                $vol->name,
                round($vol->total_hours, 1),
                $vol->total_shifts,
                $vol->programs_count
            ));
        }

        fclose($output);
        exit;
    }
}
