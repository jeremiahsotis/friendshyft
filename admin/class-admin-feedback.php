<?php
if (!defined('ABSPATH')) exit;

/**
 * Feedback Management Dashboard
 * Admin interface for surveys, suggestions, and testimonials
 */
class FS_Admin_Feedback {

    public static function init() {
        add_action('admin_post_fs_update_suggestion_status', array(__CLASS__, 'update_suggestion_status'));
        add_action('admin_post_fs_publish_testimonial', array(__CLASS__, 'publish_testimonial'));
        add_action('admin_post_fs_unpublish_testimonial', array(__CLASS__, 'unpublish_testimonial'));
        add_action('admin_post_fs_export_feedback', array(__CLASS__, 'export_feedback'));
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }

        $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'surveys';

        // Display success/error messages
        if (isset($_GET['suggestion_updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> Suggestion status and response have been updated.</p></div>';
        }
        if (isset($_GET['testimonial_published'])) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> Testimonial has been published.</p></div>';
        }
        if (isset($_GET['testimonial_unpublished'])) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Success!</strong> Testimonial has been unpublished.</p></div>';
        }

        ?>
        <div class="wrap">
            <h1>Volunteer Feedback</h1>
            <p>View and manage post-event surveys, suggestions, and testimonials from volunteers.</p>

            <!-- Tabs -->
            <h2 class="nav-tab-wrapper">
                <a href="?page=fs-feedback&tab=surveys" class="nav-tab <?php echo $tab === 'surveys' ? 'nav-tab-active' : ''; ?>">
                    Surveys
                </a>
                <a href="?page=fs-feedback&tab=suggestions" class="nav-tab <?php echo $tab === 'suggestions' ? 'nav-tab-active' : ''; ?>">
                    Suggestions
                </a>
                <a href="?page=fs-feedback&tab=testimonials" class="nav-tab <?php echo $tab === 'testimonials' ? 'nav-tab-active' : ''; ?>">
                    Testimonials
                </a>
            </h2>

            <?php
            switch ($tab) {
                case 'surveys':
                    self::render_surveys_tab();
                    break;
                case 'suggestions':
                    self::render_suggestions_tab();
                    break;
                case 'testimonials':
                    self::render_testimonials_tab();
                    break;
            }
            ?>
        </div>
        <?php
    }

    /**
     * Render surveys tab
     */
    private static function render_surveys_tab() {
        global $wpdb;

        // Get statistics
        $total_surveys = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_surveys");
        $avg_rating = $wpdb->get_var("SELECT AVG(rating) FROM {$wpdb->prefix}fs_surveys");
        $would_recommend_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_surveys WHERE would_recommend = 'yes'");
        $would_recommend_pct = $total_surveys > 0 ? round(($would_recommend_count / $total_surveys) * 100) : 0;

        // Get recent surveys
        $surveys = $wpdb->get_results(
            "SELECT s.*, v.name as volunteer_name, o.title as opportunity_title
             FROM {$wpdb->prefix}fs_surveys s
             JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
             JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
             ORDER BY s.submitted_at DESC
             LIMIT 50"
        );

        ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0;">
            <div style="background: white; border-left: 4px solid #0073aa; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #0073aa;"><?php echo number_format($total_surveys); ?></div>
                <div style="color: #666; margin-top: 5px;">Total Surveys</div>
            </div>
            <div style="background: white; border-left: 4px solid #ffc107; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #ffc107;"><?php echo number_format($avg_rating, 1); ?>/5</div>
                <div style="color: #666; margin-top: 5px;">Average Rating</div>
            </div>
            <div style="background: white; border-left: 4px solid #28a745; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #28a745;"><?php echo $would_recommend_pct; ?>%</div>
                <div style="color: #666; margin-top: 5px;">Would Recommend</div>
            </div>
        </div>

        <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2>Recent Surveys (<?php echo count($surveys); ?>)</h2>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                    <?php wp_nonce_field('fs_export_feedback', '_wpnonce_export'); ?>
                    <input type="hidden" name="action" value="fs_export_feedback">
                    <input type="hidden" name="type" value="surveys">
                    <button type="submit" class="button">
                        <span class="dashicons dashicons-download" style="margin-top: 3px;"></span> Export to CSV
                    </button>
                </form>
            </div>

            <?php if (empty($surveys)): ?>
                <p style="text-align: center; color: #666; padding: 40px;">No surveys submitted yet.</p>
            <?php else: ?>
                <?php foreach ($surveys as $survey): ?>
                    <div style="background: #f9f9f9; padding: 20px; margin-bottom: 15px; border-radius: 4px; border-left: 4px solid <?php echo self::get_rating_color($survey->rating); ?>;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                            <div>
                                <strong><?php echo esc_html($survey->volunteer_name); ?></strong> -
                                <span style="color: #666;"><?php echo esc_html($survey->opportunity_title); ?></span>
                            </div>
                            <div>
                                <?php echo self::render_stars($survey->rating); ?>
                                <span style="color: #666; font-size: 13px; margin-left: 10px;">
                                    <?php echo self::format_wp_datetime($survey->submitted_at, 'M j, Y'); ?>
                                </span>
                            </div>
                        </div>

                        <?php if ($survey->enjoyed_most): ?>
                            <p><strong>What they enjoyed most:</strong><br><?php echo nl2br(esc_html($survey->enjoyed_most)); ?></p>
                        <?php endif; ?>

                        <?php if ($survey->could_improve): ?>
                            <p><strong>What could be improved:</strong><br><?php echo nl2br(esc_html($survey->could_improve)); ?></p>
                        <?php endif; ?>

                        <?php if ($survey->additional_comments): ?>
                            <p><strong>Additional comments:</strong><br><?php echo nl2br(esc_html($survey->additional_comments)); ?></p>
                        <?php endif; ?>

                        <p style="margin: 0;">
                            <strong>Would recommend:</strong>
                            <?php if ($survey->would_recommend === 'yes'): ?>
                                <span style="color: #28a745;">✓ Yes</span>
                            <?php else: ?>
                                <span style="color: #dc3545;">✗ No</span>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render suggestions tab
     */
    private static function render_suggestions_tab() {
        global $wpdb;

        $status_filter = isset($_GET['status_filter']) ? sanitize_text_field($_GET['status_filter']) : '';

        $where = '';
        if ($status_filter) {
            $where = $wpdb->prepare(" WHERE s.status = %s", $status_filter);
        }

        $suggestions = $wpdb->get_results(
            "SELECT s.*, v.name as volunteer_name, v.email as volunteer_email
             FROM {$wpdb->prefix}fs_suggestions s
             JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
             $where
             ORDER BY s.submitted_at DESC"
        );

        $pending_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_suggestions WHERE status = 'pending'");
        $reviewed_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_suggestions WHERE status = 'reviewed'");
        $implemented_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_suggestions WHERE status = 'implemented'");

        ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0;">
            <div style="background: white; border-left: 4px solid #ffc107; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #ffc107;"><?php echo number_format($pending_count); ?></div>
                <div style="color: #666; margin-top: 5px;">Pending Review</div>
            </div>
            <div style="background: white; border-left: 4px solid #0073aa; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #0073aa;"><?php echo number_format($reviewed_count); ?></div>
                <div style="color: #666; margin-top: 5px;">Reviewed</div>
            </div>
            <div style="background: white; border-left: 4px solid #28a745; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #28a745;"><?php echo number_format($implemented_count); ?></div>
                <div style="color: #666; margin-top: 5px;">Implemented</div>
            </div>
        </div>

        <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2>Volunteer Suggestions (<?php echo count($suggestions); ?>)</h2>
                <div>
                    <select onchange="window.location='?page=fs-feedback&tab=suggestions&status_filter=' + this.value" style="margin-right: 10px;">
                        <option value="">All Statuses</option>
                        <option value="pending" <?php selected($status_filter, 'pending'); ?>>Pending</option>
                        <option value="reviewed" <?php selected($status_filter, 'reviewed'); ?>>Reviewed</option>
                        <option value="implemented" <?php selected($status_filter, 'implemented'); ?>>Implemented</option>
                    </select>
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                        <?php wp_nonce_field('fs_export_feedback', '_wpnonce_export'); ?>
                        <input type="hidden" name="action" value="fs_export_feedback">
                        <input type="hidden" name="type" value="suggestions">
                        <button type="submit" class="button">
                            <span class="dashicons dashicons-download" style="margin-top: 3px;"></span> Export to CSV
                        </button>
                    </form>
                </div>
            </div>

            <?php if (empty($suggestions)): ?>
                <p style="text-align: center; color: #666; padding: 40px;">No suggestions submitted yet.</p>
            <?php else: ?>
                <?php foreach ($suggestions as $suggestion): ?>
                    <div style="background: #f9f9f9; padding: 20px; margin-bottom: 15px; border-radius: 4px;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                            <div>
                                <strong style="font-size: 16px;"><?php echo esc_html($suggestion->subject); ?></strong><br>
                                <span style="color: #666; font-size: 13px;">
                                    From: <?php echo esc_html($suggestion->volunteer_name); ?> (<?php echo esc_html($suggestion->volunteer_email); ?>)
                                </span>
                            </div>
                            <div>
                                <span style="display: inline-block; padding: 4px 10px; background: <?php echo self::get_status_color($suggestion->status); ?>15; color: <?php echo self::get_status_color($suggestion->status); ?>; border-radius: 3px; font-size: 12px; font-weight: 600;">
                                    <?php echo ucfirst($suggestion->status); ?>
                                </span>
                            </div>
                        </div>

                        <p><strong>Category:</strong> <?php echo esc_html($suggestion->category); ?></p>
                        <p><strong>Suggestion:</strong><br><?php echo nl2br(esc_html($suggestion->suggestion)); ?></p>

                        <?php
                        // Get response history
                        $responses = $wpdb->get_results($wpdb->prepare(
                            "SELECT r.*, u.display_name as admin_name
                             FROM {$wpdb->prefix}fs_suggestion_responses r
                             LEFT JOIN {$wpdb->prefix}users u ON r.admin_user_id = u.ID
                             WHERE r.suggestion_id = %d
                             ORDER BY r.created_at ASC",
                            $suggestion->id
                        ));

                        if (!empty($responses)):
                        ?>
                            <div style="margin-top: 15px;">
                                <p style="font-weight: bold; color: #0073aa; margin-bottom: 10px;">Response History:</p>
                                <?php foreach ($responses as $response): ?>
                                    <div style="margin-bottom: 10px; padding: 15px; background: #e7f3ff; border-left: 4px solid #0073aa; border-radius: 4px;">
                                        <p style="margin: 0;"><?php echo nl2br(esc_html($response->response)); ?></p>
                                        <p style="margin: 10px 0 0 0; color: #666; font-size: 12px;">
                                            <strong><?php echo esc_html($response->admin_name ?: 'Admin'); ?></strong> -
                                            <?php echo self::format_wp_datetime($response->created_at); ?>
                                        </p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd;">
                            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: flex; gap: 10px; align-items: flex-start;">
                                <?php wp_nonce_field('fs_update_suggestion', '_wpnonce_suggestion'); ?>
                                <input type="hidden" name="action" value="fs_update_suggestion_status">
                                <input type="hidden" name="suggestion_id" value="<?php echo $suggestion->id; ?>">

                                <select name="status" class="button">
                                    <option value="pending" <?php selected($suggestion->status, 'pending'); ?>>Pending</option>
                                    <option value="reviewed" <?php selected($suggestion->status, 'reviewed'); ?>>Reviewed</option>
                                    <option value="implemented" <?php selected($suggestion->status, 'implemented'); ?>>Implemented</option>
                                </select>

                                <textarea name="admin_response" placeholder="Add a response to volunteer..." style="flex: 1; min-height: 60px; font-family: inherit;"></textarea>

                                <button type="submit" class="button button-primary">Update</button>
                            </form>
                            <p style="color: #666; font-size: 12px; margin: 10px 0 0 0;">
                                💡 Adding a response will send an email notification to the volunteer. Leave blank to only update status.
                            </p>
                        </div>

                        <p style="color: #666; font-size: 12px; margin: 10px 0 0 0;">
                            Submitted: <?php echo self::format_wp_datetime($suggestion->submitted_at); ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render testimonials tab
     */
    private static function render_testimonials_tab() {
        global $wpdb;

        $testimonials = $wpdb->get_results(
            "SELECT t.*, v.name as volunteer_name
             FROM {$wpdb->prefix}fs_testimonials t
             JOIN {$wpdb->prefix}fs_volunteers v ON t.volunteer_id = v.id
             ORDER BY t.submitted_at DESC"
        );

        $total_count = count($testimonials);
        $published_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_testimonials WHERE is_published = 1");
        $approved_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fs_testimonials WHERE permission_to_publish = 1");

        ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 30px 0;">
            <div style="background: white; border-left: 4px solid #0073aa; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #0073aa;"><?php echo number_format($total_count); ?></div>
                <div style="color: #666; margin-top: 5px;">Total Testimonials</div>
            </div>
            <div style="background: white; border-left: 4px solid #28a745; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #28a745;"><?php echo number_format($published_count); ?></div>
                <div style="color: #666; margin-top: 5px;">Published</div>
            </div>
            <div style="background: white; border-left: 4px solid #ffc107; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <div style="font-size: 32px; font-weight: bold; color: #ffc107;"><?php echo number_format($approved_count); ?></div>
                <div style="color: #666; margin-top: 5px;">Approved to Publish</div>
            </div>
        </div>

        <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2>Volunteer Testimonials (<?php echo count($testimonials); ?>)</h2>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                    <?php wp_nonce_field('fs_export_feedback', '_wpnonce_export'); ?>
                    <input type="hidden" name="action" value="fs_export_feedback">
                    <input type="hidden" name="type" value="testimonials">
                    <button type="submit" class="button">
                        <span class="dashicons dashicons-download" style="margin-top: 3px;"></span> Export to CSV
                    </button>
                </form>
            </div>

            <?php if (empty($testimonials)): ?>
                <p style="text-align: center; color: #666; padding: 40px;">No testimonials submitted yet.</p>
            <?php else: ?>
                <?php foreach ($testimonials as $testimonial): ?>
                    <div style="background: <?php echo $testimonial->is_published ? '#f0f8ff' : '#f9f9f9'; ?>; padding: 20px; margin-bottom: 15px; border-radius: 4px; border-left: 4px solid <?php echo $testimonial->is_published ? '#0073aa' : '#ccc'; ?>;">
                        <div style="display: flex; justify-content: space-between; margin-bottom: 15px;">
                            <div>
                                <strong><?php echo esc_html($testimonial->display_name); ?></strong>
                                <span style="color: #666;"> (<?php echo esc_html($testimonial->volunteer_name); ?>)</span>
                            </div>
                            <div>
                                <?php if ($testimonial->is_published): ?>
                                    <span style="background: #0073aa; color: white; padding: 4px 10px; border-radius: 3px; font-size: 12px; font-weight: 600;">
                                        ✓ Published
                                    </span>
                                <?php elseif ($testimonial->permission_to_publish): ?>
                                    <span style="background: #28a745; color: white; padding: 4px 10px; border-radius: 3px; font-size: 12px; font-weight: 600;">
                                        ✓ Approved
                                    </span>
                                <?php else: ?>
                                    <span style="background: #dc3545; color: white; padding: 4px 10px; border-radius: 3px; font-size: 12px; font-weight: 600;">
                                        No Permission
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <blockquote style="background: white; padding: 15px; border-left: 4px solid #0073aa; margin: 15px 0; font-style: italic;">
                            <?php echo nl2br(esc_html($testimonial->testimonial)); ?>
                        </blockquote>

                        <?php if ($testimonial->impact_story): ?>
                            <p><strong>Impact Story:</strong><br><?php echo nl2br(esc_html($testimonial->impact_story)); ?></p>
                        <?php endif; ?>

                        <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #ddd; display: flex; gap: 10px;">
                            <?php if ($testimonial->permission_to_publish && !$testimonial->is_published): ?>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                    <?php wp_nonce_field('fs_publish_testimonial', '_wpnonce_publish'); ?>
                                    <input type="hidden" name="action" value="fs_publish_testimonial">
                                    <input type="hidden" name="testimonial_id" value="<?php echo $testimonial->id; ?>">
                                    <button type="submit" class="button button-primary">Publish</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($testimonial->is_published): ?>
                                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                    <?php wp_nonce_field('fs_unpublish_testimonial', '_wpnonce_unpublish'); ?>
                                    <input type="hidden" name="action" value="fs_unpublish_testimonial">
                                    <input type="hidden" name="testimonial_id" value="<?php echo $testimonial->id; ?>">
                                    <button type="submit" class="button">Unpublish</button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <p style="color: #666; font-size: 12px; margin: 10px 0 0 0;">
                            Submitted: <?php echo self::format_wp_datetime($testimonial->submitted_at); ?>
                            <?php if ($testimonial->published_at): ?>
                                | Published: <?php echo self::format_wp_datetime($testimonial->published_at); ?>
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Update suggestion status
     */
    public static function update_suggestion_status() {
        check_admin_referer('fs_update_suggestion', '_wpnonce_suggestion');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $suggestion_id = isset($_POST['suggestion_id']) ? intval($_POST['suggestion_id']) : 0;
        $status = isset($_POST['status']) ? sanitize_text_field($_POST['status']) : '';
        $admin_response = isset($_POST['admin_response']) ? sanitize_textarea_field($_POST['admin_response']) : '';

        global $wpdb;

        // Update suggestion status
        $wpdb->update(
            "{$wpdb->prefix}fs_suggestions",
            array(
                'status' => $status,
                'reviewed_at' => current_time('mysql')
            ),
            array('id' => $suggestion_id)
        );

        // If there's a response, save it to history table
        if (!empty($admin_response)) {
            $wpdb->insert(
                "{$wpdb->prefix}fs_suggestion_responses",
                array(
                    'suggestion_id' => $suggestion_id,
                    'admin_user_id' => get_current_user_id(),
                    'response' => $admin_response,
                    'created_at' => current_time('mysql')
                )
            );

            // Update the main table's admin_response with the latest response
            $wpdb->update(
                "{$wpdb->prefix}fs_suggestions",
                array('admin_response' => $admin_response),
                array('id' => $suggestion_id)
            );
        }

        FS_Audit_Log::log('suggestion_updated', 'suggestion', $suggestion_id, array(
            'status' => $status,
            'has_response' => !empty($admin_response)
        ));

        // Send notification email to volunteer if there's a response
        if (!empty($admin_response)) {
            self::notify_volunteer_response($suggestion_id, $admin_response);
        }

        wp_redirect(admin_url('admin.php?page=fs-feedback&tab=suggestions&suggestion_updated=1'));
        exit;
    }

    /**
     * Notify volunteer of admin response
     */
    private static function notify_volunteer_response($suggestion_id, $latest_response) {
        global $wpdb;

        $suggestion = $wpdb->get_row($wpdb->prepare(
            "SELECT s.*, v.name, v.email, v.access_token
             FROM {$wpdb->prefix}fs_suggestions s
             JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
             WHERE s.id = %d",
            $suggestion_id
        ));

        if (!$suggestion) {
            return;
        }

        // Get all responses for context
        $all_responses = $wpdb->get_results($wpdb->prepare(
            "SELECT r.*, u.display_name as admin_name
             FROM {$wpdb->prefix}fs_suggestion_responses r
             LEFT JOIN {$wpdb->prefix}users u ON r.admin_user_id = u.ID
             WHERE r.suggestion_id = %d
             ORDER BY r.created_at ASC",
            $suggestion_id
        ));

        $portal_url = add_query_arg(array(
            'token' => $suggestion->access_token,
            'view' => 'feedback'
        ), home_url('/volunteer-portal/'));

        $subject = 'New response to your suggestion: ' . $suggestion->subject;

        // Build response history HTML
        $response_history_html = '';
        if (count($all_responses) > 1) {
            $response_history_html = "<div style='margin: 20px 0;'><strong>Response History:</strong></div>";
            foreach ($all_responses as $resp) {
                $timestamp = strtotime($resp->created_at . ' UTC');
                $formatted_date = date_i18n('M j, Y g:i A', $timestamp);
                $response_history_html .= "
                <div style='background: " . ($resp->response === $latest_response ? '#e7f3ff' : '#f5f5f5') . "; padding: 15px; border-left: 4px solid #0073aa; margin: 10px 0;'>
                    " . nl2br(esc_html($resp->response)) . "
                    <div style='margin-top: 10px; color: #666; font-size: 12px;'>
                        <strong>" . esc_html($resp->admin_name ?: 'Admin') . "</strong> - " . $formatted_date . "
                    </div>
                </div>";
            }
        } else {
            $response_history_html = "
            <div style='background: #e7f3ff; padding: 15px; border-left: 4px solid #0073aa; margin: 20px 0;'>
                <strong>Our response:</strong><br>
                " . nl2br(esc_html($latest_response)) . "
            </div>";
        }

        $message = "
        <html>
        <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333;'>
            <div style='max-width: 600px; margin: 0 auto; padding: 20px;'>
                <h2 style='color: #0073aa;'>We've responded to your suggestion!</h2>

                <p>Hi " . esc_html($suggestion->name) . ",</p>

                <p>Thank you for your suggestion about <strong>" . esc_html($suggestion->subject) . "</strong>.</p>

                <div style='background: #f5f5f5; padding: 15px; border-left: 4px solid #0073aa; margin: 20px 0;'>
                    <strong>Your suggestion:</strong><br>
                    " . nl2br(esc_html($suggestion->suggestion)) . "
                </div>

                " . $response_history_html . "

                <p><strong>Status:</strong> " . ucfirst($suggestion->status) . "</p>

                <div style='text-align: center; margin: 30px 0;'>
                    <a href='" . esc_url($portal_url) . "' style='display: inline-block; background: #0073aa; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;'>
                        View Your Feedback
                    </a>
                </div>

                <p style='color: #666; font-size: 14px;'>
                    We appreciate your input and hope to continue improving the volunteer experience.
                </p>
            </div>
        </body>
        </html>
        ";

        $headers = array('Content-Type: text/html; charset=UTF-8');
        wp_mail($suggestion->email, $subject, $message, $headers);
    }

    /**
     * Publish testimonial
     */
    public static function publish_testimonial() {
        check_admin_referer('fs_publish_testimonial', '_wpnonce_publish');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $testimonial_id = isset($_POST['testimonial_id']) ? intval($_POST['testimonial_id']) : 0;

        global $wpdb;

        $wpdb->update(
            "{$wpdb->prefix}fs_testimonials",
            array(
                'is_published' => 1,
                'published_at' => current_time('mysql')
            ),
            array('id' => $testimonial_id)
        );

        FS_Audit_Log::log('testimonial_published', 'testimonial', $testimonial_id);

        wp_redirect(admin_url('admin.php?page=fs-feedback&tab=testimonials&testimonial_published=1'));
        exit;
    }

    /**
     * Unpublish testimonial
     */
    public static function unpublish_testimonial() {
        check_admin_referer('fs_unpublish_testimonial', '_wpnonce_unpublish');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $testimonial_id = isset($_POST['testimonial_id']) ? intval($_POST['testimonial_id']) : 0;

        global $wpdb;

        $wpdb->update(
            "{$wpdb->prefix}fs_testimonials",
            array('is_published' => 0),
            array('id' => $testimonial_id)
        );

        FS_Audit_Log::log('testimonial_unpublished', 'testimonial', $testimonial_id);

        wp_redirect(admin_url('admin.php?page=fs-feedback&tab=testimonials&testimonial_unpublished=1'));
        exit;
    }

    /**
     * Export feedback to CSV
     */
    public static function export_feedback() {
        check_admin_referer('fs_export_feedback', '_wpnonce_export');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : 'surveys';

        global $wpdb;

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=feedback-' . $type . '-' . date('Y-m-d') . '.csv');

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        if ($type === 'surveys') {
            fputcsv($output, array('Date', 'Volunteer', 'Opportunity', 'Rating', 'Enjoyed Most', 'Could Improve', 'Would Recommend', 'Comments'));

            $surveys = $wpdb->get_results(
                "SELECT s.*, v.name as volunteer_name, o.title as opportunity_title
                 FROM {$wpdb->prefix}fs_surveys s
                 JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
                 JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
                 ORDER BY s.submitted_at DESC"
            );

            foreach ($surveys as $survey) {
                fputcsv($output, array(
                    date('Y-m-d H:i:s', strtotime($survey->submitted_at)),
                    $survey->volunteer_name,
                    $survey->opportunity_title,
                    $survey->rating,
                    $survey->enjoyed_most,
                    $survey->could_improve,
                    $survey->would_recommend,
                    $survey->additional_comments
                ));
            }
        } elseif ($type === 'suggestions') {
            fputcsv($output, array('Date', 'Volunteer', 'Email', 'Category', 'Subject', 'Suggestion', 'Status', 'Admin Response'));

            $suggestions = $wpdb->get_results(
                "SELECT s.*, v.name as volunteer_name, v.email as volunteer_email
                 FROM {$wpdb->prefix}fs_suggestions s
                 JOIN {$wpdb->prefix}fs_volunteers v ON s.volunteer_id = v.id
                 ORDER BY s.submitted_at DESC"
            );

            foreach ($suggestions as $suggestion) {
                fputcsv($output, array(
                    date('Y-m-d H:i:s', strtotime($suggestion->submitted_at)),
                    $suggestion->volunteer_name,
                    $suggestion->volunteer_email,
                    $suggestion->category,
                    $suggestion->subject,
                    $suggestion->suggestion,
                    $suggestion->status,
                    $suggestion->admin_response
                ));
            }
        } elseif ($type === 'testimonials') {
            fputcsv($output, array('Date', 'Display Name', 'Testimonial', 'Impact Story', 'Permission', 'Published'));

            $testimonials = $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}fs_testimonials ORDER BY submitted_at DESC"
            );

            foreach ($testimonials as $testimonial) {
                fputcsv($output, array(
                    date('Y-m-d H:i:s', strtotime($testimonial->submitted_at)),
                    $testimonial->display_name,
                    $testimonial->testimonial,
                    $testimonial->impact_story,
                    $testimonial->permission_to_publish ? 'Yes' : 'No',
                    $testimonial->is_published ? 'Yes' : 'No'
                ));
            }
        }

        fclose($output);
        exit;
    }

    /**
     * Helper: Render star rating
     */
    private static function render_stars($rating) {
        $output = '';
        for ($i = 1; $i <= 5; $i++) {
            if ($i <= $rating) {
                $output .= '<span style="color: #ffc107;">★</span>';
            } else {
                $output .= '<span style="color: #ddd;">★</span>';
            }
        }
        return $output;
    }

    /**
     * Helper: Get rating color
     */
    private static function get_rating_color($rating) {
        if ($rating >= 4) return '#28a745';
        if ($rating >= 3) return '#ffc107';
        return '#dc3545';
    }

    /**
     * Helper: Get status color
     */
    private static function get_status_color($status) {
        switch ($status) {
            case 'pending': return '#ffc107';
            case 'reviewed': return '#0073aa';
            case 'implemented': return '#28a745';
            default: return '#666';
        }
    }

    /**
     * Helper: Format datetime in WordPress timezone
     */
    private static function format_wp_datetime($datetime_string, $format = 'M j, Y g:i A') {
        if (!$datetime_string) {
            return '';
        }
        // Convert MySQL datetime to WordPress timezone
        $timestamp = strtotime($datetime_string . ' UTC');
        return date_i18n($format, $timestamp);
    }
}
