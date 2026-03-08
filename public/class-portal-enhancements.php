<?php
if (!defined('ABSPATH')) exit;

/**
 * Volunteer Portal Enhancements
 * Search, filters, favorites, and mobile optimizations
 */
class FS_Portal_Enhancements {

    public static function init() {
        // AJAX handlers for search and filters
        add_action('wp_ajax_fs_search_opportunities', array(__CLASS__, 'ajax_search_opportunities'));
        add_action('wp_ajax_nopriv_fs_search_opportunities', array(__CLASS__, 'ajax_search_opportunities'));

        // AJAX handlers for favorites
        add_action('wp_ajax_fs_toggle_favorite', array(__CLASS__, 'ajax_toggle_favorite'));
        add_action('wp_ajax_nopriv_fs_toggle_favorite', array(__CLASS__, 'ajax_toggle_favorite'));

        // Enqueue enhanced portal scripts
        add_action('wp_enqueue_scripts', array(__CLASS__, 'enqueue_portal_enhancements'));

        // Create favorites table on activation
        register_activation_hook(FRIENDSHYFT_PLUGIN_DIR . 'friendshyft.php', array(__CLASS__, 'create_favorites_table'));
    }

    /**
     * Create favorites table
     */
    public static function create_favorites_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $table_name = $wpdb->prefix . 'fs_favorites';

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            volunteer_id bigint(20) NOT NULL,
            opportunity_id bigint(20) NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY volunteer_id (volunteer_id),
            KEY opportunity_id (opportunity_id),
            UNIQUE KEY unique_favorite (volunteer_id, opportunity_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Enqueue enhanced portal scripts and styles
     */
    public static function enqueue_portal_enhancements() {
        // Only enqueue on pages with portal shortcode
        global $post;
        if (!is_a($post, 'WP_Post') || !has_shortcode($post->post_content, 'volunteer_portal')) {
            return;
        }

        // Enqueue jQuery if not already loaded
        wp_enqueue_script('jquery');

        // Enqueue custom portal enhancement script
        wp_add_inline_script('jquery', self::get_inline_portal_script());

        // Add mobile-optimized styles
        wp_add_inline_style('friendshyft-portal', self::get_mobile_styles());
    }

    /**
     * Get inline JavaScript for portal enhancements
     */
    private static function get_inline_portal_script() {
        $nonce = wp_create_nonce('fs_portal_enhancements');

        return "
        jQuery(document).ready(function($) {
            // Initialize search and filter functionality
            let searchTimeout;

            // Search input handler with debounce
            $(document).on('input', '#fs-opportunity-search', function() {
                clearTimeout(searchTimeout);
                const searchTerm = $(this).val();

                searchTimeout = setTimeout(function() {
                    filterOpportunities();
                }, 300); // Debounce 300ms
            });

            // Filter change handlers
            $(document).on('change', '#fs-program-filter, #fs-location-filter, #fs-date-from, #fs-date-to', function() {
                filterOpportunities();
            });

            // Clear filters button
            $(document).on('click', '#fs-clear-filters', function() {
                $('#fs-opportunity-search').val('');
                $('#fs-program-filter').val('');
                $('#fs-location-filter').val('');
                $('#fs-date-from').val('');
                $('#fs-date-to').val('');
                filterOpportunities();
            });

            // Toggle favorite
            $(document).on('click', '.fs-favorite-btn', function(e) {
                e.preventDefault();
                const button = $(this);
                const opportunityId = button.data('opportunity-id');
                const volunteerId = button.data('volunteer-id');

                button.prop('disabled', true);

                $.ajax({
                    url: '" . admin_url('admin-ajax.php') . "',
                    method: 'POST',
                    data: {
                        action: 'fs_toggle_favorite',
                        opportunity_id: opportunityId,
                        volunteer_id: volunteerId,
                        token: button.data('token'),
                        nonce: '" . $nonce . "'
                    },
                    success: function(response) {
                        if (response.success) {
                            if (response.data.is_favorite) {
                                button.addClass('is-favorite').html('★ Favorited');
                            } else {
                                button.removeClass('is-favorite').html('☆ Add to Favorites');
                            }
                        }
                        button.prop('disabled', false);
                    },
                    error: function() {
                        button.prop('disabled', false);
                    }
                });
            });

            // Filter opportunities via AJAX
            function filterOpportunities() {
                const searchTerm = $('#fs-opportunity-search').val();
                const program = $('#fs-program-filter').val();
                const location = $('#fs-location-filter').val();
                const dateFrom = $('#fs-date-from').val();
                const dateTo = $('#fs-date-to').val();
                const token = $('#fs-volunteer-token').val();

                $('#fs-opportunities-list').html('<p style=\"text-align: center; padding: 40px;\"><span class=\"spinner\" style=\"visibility: visible; float: none; margin: 0;\"></span> Loading opportunities...</p>');

                $.ajax({
                    url: '" . admin_url('admin-ajax.php') . "',
                    method: 'POST',
                    data: {
                        action: 'fs_search_opportunities',
                        search: searchTerm,
                        program: program,
                        location: location,
                        date_from: dateFrom,
                        date_to: dateTo,
                        token: token,
                        nonce: '" . $nonce . "'
                    },
                    success: function(response) {
                        if (response.success) {
                            $('#fs-opportunities-list').html(response.data.html);
                        } else {
                            $('#fs-opportunities-list').html('<p style=\"text-align: center; color: #dc3545;\">Error loading opportunities.</p>');
                        }
                    },
                    error: function() {
                        $('#fs-opportunities-list').html('<p style=\"text-align: center; color: #dc3545;\">Connection error. Please try again.</p>');
                    }
                });
            }

            // Add touch-friendly hover effects for mobile
            if ('ontouchstart' in window) {
                $('.fs-opportunity-card').on('touchstart', function() {
                    $(this).addClass('touch-active');
                });

                $('.fs-opportunity-card').on('touchend', function() {
                    const card = $(this);
                    setTimeout(function() {
                        card.removeClass('touch-active');
                    }, 300);
                });
            }
        });
        ";
    }

    /**
     * Get mobile-optimized CSS
     */
    private static function get_mobile_styles() {
        return "
        /* Enhanced Mobile Styles */
        @media (max-width: 768px) {
            .fs-portal-filters {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            .fs-portal-filters input,
            .fs-portal-filters select {
                width: 100% !important;
                max-width: 100% !important;
                font-size: 16px; /* Prevents iOS zoom */
            }

            .fs-opportunity-card {
                padding: 15px !important;
                margin-bottom: 15px !important;
            }

            .fs-opportunity-card h3 {
                font-size: 18px !important;
            }

            .fs-opportunity-actions {
                display: flex;
                flex-direction: column;
                gap: 10px;
            }

            .fs-opportunity-actions button,
            .fs-opportunity-actions a {
                width: 100% !important;
                text-align: center !important;
                padding: 12px 20px !important;
                font-size: 16px !important;
            }

            .fs-favorite-btn {
                min-height: 44px; /* Touch target size */
                font-size: 18px !important;
            }
        }

        /* Touch-friendly improvements */
        .fs-opportunity-card {
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .fs-opportunity-card.touch-active {
            transform: scale(0.98);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }

        .fs-favorite-btn {
            cursor: pointer;
            user-select: none;
            -webkit-tap-highlight-color: transparent;
        }

        .fs-favorite-btn.is-favorite {
            color: #ffc107;
            font-weight: bold;
        }

        /* Improved filter UI */
        .fs-portal-filters {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .fs-filter-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .fs-filter-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        /* Loading spinner */
        .spinner {
            background: url('" . admin_url('images/spinner.gif') . "') no-repeat;
            background-size: 20px 20px;
            display: inline-block;
            width: 20px;
            height: 20px;
            vertical-align: middle;
        }

        /* Responsive grid for opportunities */
        .fs-opportunities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
        }

        @media (max-width: 768px) {
            .fs-opportunities-grid {
                grid-template-columns: 1fr;
            }
        }
        ";
    }

    /**
     * AJAX handler for searching and filtering opportunities
     */
    public static function ajax_search_opportunities() {
        check_ajax_referer('fs_portal_enhancements', 'nonce');

        // Get volunteer
        $volunteer = self::get_volunteer_from_request();
        if (!$volunteer) {
            wp_send_json_error('Volunteer not found');
            return;
        }

        // Get filter parameters
        $search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
        $program = isset($_POST['program']) ? sanitize_text_field($_POST['program']) : '';
        $location = isset($_POST['location']) ? sanitize_text_field($_POST['location']) : '';
        $date_from = isset($_POST['date_from']) ? sanitize_text_field($_POST['date_from']) : '';
        $date_to = isset($_POST['date_to']) ? sanitize_text_field($_POST['date_to']) : '';

        global $wpdb;

        // Build query
        $where_clauses = array("o.event_date >= CURDATE()", "o.status = 'active'");
        $params = array();

        if (!empty($search)) {
            $where_clauses[] = "(o.title LIKE %s OR o.description LIKE %s OR o.location LIKE %s)";
            $search_term = '%' . $wpdb->esc_like($search) . '%';
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }

        if (!empty($program)) {
            $where_clauses[] = "o.conference = %s";
            $params[] = $program;
        }

        if (!empty($location)) {
            $where_clauses[] = "o.location LIKE %s";
            $params[] = '%' . $wpdb->esc_like($location) . '%';
        }

        if (!empty($date_from)) {
            $where_clauses[] = "o.event_date >= %s";
            $params[] = $date_from;
        }

        if (!empty($date_to)) {
            $where_clauses[] = "o.event_date <= %s";
            $params[] = $date_to;
        }

        $where_sql = implode(' AND ', $where_clauses);

        $query = "SELECT o.*,
                  (SELECT COUNT(*) FROM {$wpdb->prefix}fs_signups s
                   WHERE s.opportunity_id = o.id AND s.volunteer_id = %d AND s.status = 'confirmed') as is_signed_up,
                  (SELECT COUNT(*) FROM {$wpdb->prefix}fs_favorites f
                   WHERE f.opportunity_id = o.id AND f.volunteer_id = %d) as is_favorite
                  FROM {$wpdb->prefix}fs_opportunities o
                  WHERE $where_sql
                  ORDER BY o.event_date ASC
                  LIMIT 50";

        // Prepend volunteer ID twice for the subqueries
        array_unshift($params, $volunteer->id, $volunteer->id);

        $opportunities = $wpdb->get_results($wpdb->prepare($query, $params));

        // Generate HTML
        $html = '';
        if (empty($opportunities)) {
            $html = '<div style="text-align: center; padding: 60px 20px; color: #666;">
                <p style="font-size: 18px; margin-bottom: 10px;">No opportunities found</p>
                <p>Try adjusting your filters or search terms.</p>
            </div>';
        } else {
            $html .= '<div class="fs-opportunities-grid">';
            foreach ($opportunities as $opp) {
                $html .= self::render_opportunity_card($opp, $volunteer);
            }
            $html .= '</div>';
        }

        wp_send_json_success(array('html' => $html, 'count' => count($opportunities)));
    }

    /**
     * Render an opportunity card
     */
    private static function render_opportunity_card($opp, $volunteer) {
        $is_signed_up = $opp->is_signed_up > 0;
        $is_favorite = $opp->is_favorite > 0;
        $is_full = $opp->spots_filled >= $opp->spots_available;

        $card = '<div class="fs-opportunity-card" style="background: white; border: 1px solid #ddd; border-radius: 8px; padding: 20px; position: relative;">';

        // Favorite button
        $favorite_class = $is_favorite ? 'is-favorite' : '';
        $favorite_text = $is_favorite ? '★ Favorited' : '☆ Add to Favorites';
        $card .= '<button class="fs-favorite-btn ' . $favorite_class . '"
                    data-opportunity-id="' . $opp->id . '"
                    data-volunteer-id="' . $volunteer->id . '"
                    data-token="' . esc_attr($volunteer->access_token) . '"
                    style="position: absolute; top: 15px; right: 15px; background: none; border: none; font-size: 16px; cursor: pointer;">' .
                    $favorite_text .
                  '</button>';

        $card .= '<h3 style="margin-top: 0; color: #0073aa;">' . esc_html($opp->title) . '</h3>';

        if ($opp->conference) {
            $card .= '<p style="color: #666; font-size: 14px; margin: 5px 0;"><strong>Program:</strong> ' . esc_html($opp->conference) . '</p>';
        }

        $card .= '<p style="color: #666; margin: 5px 0;"><span class="dashicons dashicons-calendar" style="font-size: 16px; vertical-align: middle;"></span> ' . date('l, F j, Y', strtotime($opp->event_date)) . '</p>';

        if ($opp->location) {
            $card .= '<p style="color: #666; margin: 5px 0;"><span class="dashicons dashicons-location" style="font-size: 16px; vertical-align: middle;"></span> ' . esc_html($opp->location) . '</p>';
        }

        if ($opp->description) {
            $card .= '<p style="margin: 15px 0;">' . esc_html(wp_trim_words($opp->description, 30)) . '</p>';
        }

        // Spots availability
        $spots_color = $is_full ? '#dc3545' : '#28a745';
        $card .= '<p style="margin: 10px 0;"><strong>Available Spots:</strong> <span style="color: ' . $spots_color . ';">' . $opp->spots_filled . ' / ' . $opp->spots_available . '</span></p>';

        // Actions
        $card .= '<div class="fs-opportunity-actions" style="margin-top: 15px;">';

        if ($is_signed_up) {
            $card .= '<button class="button" disabled style="background: #28a745; color: white; border: none; cursor: default;">✓ Signed Up</button>';
        } elseif ($is_full) {
            $card .= '<button class="button" disabled style="background: #dc3545; color: white; border: none; cursor: default;">Full</button>';
        } else {
            $card .= '<button class="button button-primary signup-opportunity-btn" data-opportunity-id="' . $opp->id . '" data-volunteer-id="' . $volunteer->id . '">Sign Up</button>';
        }

        $card .= '</div>';
        $card .= '</div>';

        return $card;
    }

    /**
     * AJAX handler for toggling favorites
     */
    public static function ajax_toggle_favorite() {
        check_ajax_referer('fs_portal_enhancements', 'nonce');

        // Get volunteer
        $volunteer = self::get_volunteer_from_request();
        if (!$volunteer) {
            wp_send_json_error('Volunteer not found');
            return;
        }

        $opportunity_id = intval($_POST['opportunity_id']);

        global $wpdb;
        $table_name = $wpdb->prefix . 'fs_favorites';

        // Check if already favorited
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE volunteer_id = %d AND opportunity_id = %d",
            $volunteer->id,
            $opportunity_id
        ));

        if ($exists) {
            // Remove favorite
            $wpdb->delete($table_name, array(
                'volunteer_id' => $volunteer->id,
                'opportunity_id' => $opportunity_id
            ));
            wp_send_json_success(array('is_favorite' => false));
        } else {
            // Add favorite
            $wpdb->insert($table_name, array(
                'volunteer_id' => $volunteer->id,
                'opportunity_id' => $opportunity_id,
                'created_at' => current_time('mysql')
            ));
            wp_send_json_success(array('is_favorite' => true));
        }
    }

    /**
     * Get volunteer from request (token or logged-in user)
     */
    private static function get_volunteer_from_request() {
        global $wpdb;

        // Try token first
        if (isset($_POST['token']) && !empty($_POST['token'])) {
            $token = sanitize_text_field($_POST['token']);
            $volunteer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE access_token = %s",
                $token
            ));
            if ($volunteer) {
                return $volunteer;
            }
        }

        // Try logged-in user
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $volunteer = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE wp_user_id = %d",
                $user_id
            ));
            if ($volunteer) {
                return $volunteer;
            }
        }

        return null;
    }

    /**
     * Render search and filter UI
     */
    public static function render_filters($volunteer) {
        global $wpdb;

        // Get unique programs
        $programs = $wpdb->get_col(
            "SELECT DISTINCT conference FROM {$wpdb->prefix}fs_opportunities
             WHERE conference IS NOT NULL AND conference != ''
             ORDER BY conference ASC"
        );

        // Get unique locations
        $locations = $wpdb->get_col(
            "SELECT DISTINCT location FROM {$wpdb->prefix}fs_opportunities
             WHERE location IS NOT NULL AND location != ''
             ORDER BY location ASC"
        );

        ob_start();
        ?>
        <div class="fs-portal-filters">
            <input type="hidden" id="fs-volunteer-token" value="<?php echo esc_attr($volunteer->access_token); ?>">

            <div class="fs-filter-row">
                <div>
                    <label for="fs-opportunity-search" style="display: block; margin-bottom: 5px; font-weight: 600;">Search</label>
                    <input type="text"
                           id="fs-opportunity-search"
                           placeholder="Search opportunities..."
                           style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>

                <div>
                    <label for="fs-program-filter" style="display: block; margin-bottom: 5px; font-weight: 600;">Program</label>
                    <select id="fs-program-filter" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="">All Programs</option>
                        <?php foreach ($programs as $program): ?>
                            <option value="<?php echo esc_attr($program); ?>"><?php echo esc_html($program); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="fs-location-filter" style="display: block; margin-bottom: 5px; font-weight: 600;">Location</label>
                    <select id="fs-location-filter" style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                        <option value="">All Locations</option>
                        <?php foreach ($locations as $location): ?>
                            <option value="<?php echo esc_attr($location); ?>"><?php echo esc_html($location); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="fs-filter-row">
                <div>
                    <label for="fs-date-from" style="display: block; margin-bottom: 5px; font-weight: 600;">From Date</label>
                    <input type="date"
                           id="fs-date-from"
                           style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>

                <div>
                    <label for="fs-date-to" style="display: block; margin-bottom: 5px; font-weight: 600;">To Date</label>
                    <input type="date"
                           id="fs-date-to"
                           style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>

                <div style="display: flex; align-items: flex-end;">
                    <button id="fs-clear-filters" class="button" style="padding: 8px 20px;">Clear Filters</button>
                </div>
            </div>
        </div>

        <div id="fs-opportunities-list">
            <p style="text-align: center; padding: 40px;">Loading opportunities...</p>
        </div>
        <?php
        return ob_get_clean();
    }
}
