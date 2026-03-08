<?php
if (!defined('ABSPATH')) exit;

/**
 * Volunteer Portal Feedback UI
 * Forms for surveys, suggestions, and testimonials
 */
class FS_Portal_Feedback {

    public static function init() {
        // No specific init hooks needed
    }

    /**
     * Render feedback view for volunteer portal
     */
    public static function render_feedback_view($volunteer, $portal_url) {
        $opportunity_id = isset($_GET['opportunity_id']) ? intval($_GET['opportunity_id']) : 0;

        ob_start();
        ?>
        <style>
            .feedback-tabs {
                display: flex;
                gap: 10px;
                margin: 20px 0;
                border-bottom: 2px solid #ddd;
            }
            .feedback-tab {
                padding: 12px 24px;
                background: none;
                border: none;
                border-bottom: 3px solid transparent;
                cursor: pointer;
                font-size: 15px;
                font-weight: 600;
                color: #666;
                transition: all 0.2s;
            }
            .feedback-tab:hover {
                color: #0073aa;
            }
            .feedback-tab.active {
                color: #0073aa;
                border-bottom-color: #0073aa;
            }
            .feedback-content {
                display: none;
            }
            .feedback-content.active {
                display: block;
            }
            .feedback-card {
                background: white;
                padding: 30px;
                border-radius: 8px;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                margin: 20px 0;
            }
            .feedback-card h3 {
                margin-top: 0;
                color: #0073aa;
            }
            .form-group {
                margin-bottom: 20px;
            }
            .form-group label {
                display: block;
                margin-bottom: 8px;
                font-weight: 600;
                color: #333;
            }
            .form-group input[type="text"],
            .form-group textarea,
            .form-group select {
                width: 100%;
                padding: 10px 15px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 14px;
            }
            .form-group textarea {
                min-height: 120px;
                resize: vertical;
            }
            .star-rating {
                display: flex;
                flex-direction: row-reverse;
                justify-content: flex-end;
                gap: 5px;
                font-size: 36px;
            }
            .star-rating input {
                display: none;
            }
            .star-rating label {
                cursor: pointer;
                color: #ddd;
                transition: color 0.2s;
            }
            .star-rating label:hover,
            .star-rating label:hover ~ label,
            .star-rating input:checked ~ label {
                color: #ffc107;
            }
            .radio-group {
                display: flex;
                gap: 20px;
            }
            .radio-group label {
                display: flex;
                align-items: center;
                gap: 8px;
                cursor: pointer;
            }
            .btn-submit {
                background: #0073aa;
                color: white;
                padding: 12px 30px;
                border: none;
                border-radius: 4px;
                font-size: 15px;
                font-weight: 600;
                cursor: pointer;
                transition: background 0.2s;
            }
            .btn-submit:hover {
                background: #005177;
            }
            .btn-submit:disabled {
                background: #ccc;
                cursor: not-allowed;
            }
            .checkbox-group {
                display: flex;
                align-items: flex-start;
                gap: 10px;
            }
            .checkbox-group input[type="checkbox"] {
                margin-top: 4px;
            }
            .feedback-message {
                padding: 15px 20px;
                border-radius: 6px;
                margin-bottom: 20px;
            }
            .feedback-success {
                background: #d4edda;
                border: 1px solid #c3e6cb;
                color: #155724;
            }
            .feedback-error {
                background: #f8d7da;
                border: 1px solid #f5c6cb;
                color: #721c24;
            }
        </style>

        <h1>Share Your Feedback</h1>

        <div class="feedback-tabs">
            <button class="feedback-tab <?php echo $opportunity_id ? 'active' : ''; ?>" data-tab="survey">
                Post-Event Survey
            </button>
            <button class="feedback-tab <?php echo !$opportunity_id ? 'active' : ''; ?>" data-tab="suggestion">
                Suggestion Box
            </button>
            <button class="feedback-tab" data-tab="testimonial">
                Share Your Story
            </button>
        </div>

        <!-- Survey Tab -->
        <div class="feedback-content <?php echo $opportunity_id ? 'active' : ''; ?>" id="survey-tab">
            <?php echo self::render_survey_form($volunteer, $opportunity_id); ?>
        </div>

        <!-- Suggestion Tab -->
        <div class="feedback-content <?php echo !$opportunity_id ? 'active' : ''; ?>" id="suggestion-tab">
            <?php echo self::render_suggestion_form($volunteer); ?>
        </div>

        <!-- Testimonial Tab -->
        <div class="feedback-content" id="testimonial-tab">
            <?php echo self::render_testimonial_form($volunteer); ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Define ajaxurl for frontend
            var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';

            // Tab switching
            $('.feedback-tab').on('click', function() {
                var tab = $(this).data('tab');

                $('.feedback-tab').removeClass('active');
                $(this).addClass('active');

                $('.feedback-content').removeClass('active');
                $('#' + tab + '-tab').addClass('active');
            });

            // Survey form submission
            $('#survey-form').on('submit', function(e) {
                e.preventDefault();

                var $form = $(this);
                var $button = $form.find('button[type="submit"]');
                var $message = $('#survey-message');

                $button.prop('disabled', true).text('Submitting...');
                $message.hide();

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: $form.serialize() + '&action=fs_submit_survey',
                    success: function(response) {
                        if (response.success) {
                            $message.removeClass('feedback-error').addClass('feedback-success')
                                   .text(response.data).show();
                            $form[0].reset();
                        } else {
                            $message.removeClass('feedback-success').addClass('feedback-error')
                                   .text(response.data).show();
                            $button.prop('disabled', false).text('Submit Survey');
                        }
                    },
                    error: function() {
                        $message.removeClass('feedback-success').addClass('feedback-error')
                               .text('Connection error. Please try again.').show();
                        $button.prop('disabled', false).text('Submit Survey');
                    }
                });
            });

            // Suggestion form submission
            $('#suggestion-form').on('submit', function(e) {
                e.preventDefault();

                var $form = $(this);
                var $button = $form.find('button[type="submit"]');
                var $message = $('#suggestion-message');

                $button.prop('disabled', true).text('Submitting...');
                $message.hide();

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: $form.serialize() + '&action=fs_submit_suggestion',
                    success: function(response) {
                        if (response.success) {
                            $message.removeClass('feedback-error').addClass('feedback-success')
                                   .text(response.data).show();
                            $form[0].reset();
                            $button.prop('disabled', false).text('Submit Suggestion');
                        } else {
                            $message.removeClass('feedback-success').addClass('feedback-error')
                                   .text(response.data).show();
                            $button.prop('disabled', false).text('Submit Suggestion');
                        }
                    },
                    error: function() {
                        $message.removeClass('feedback-success').addClass('feedback-error')
                               .text('Connection error. Please try again.').show();
                        $button.prop('disabled', false).text('Submit Suggestion');
                    }
                });
            });

            // Testimonial form submission
            $('#testimonial-form').on('submit', function(e) {
                e.preventDefault();

                var $form = $(this);
                var $button = $form.find('button[type="submit"]');
                var $message = $('#testimonial-message');

                $button.prop('disabled', true).text('Submitting...');
                $message.hide();

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: $form.serialize() + '&action=fs_submit_testimonial',
                    success: function(response) {
                        if (response.success) {
                            $message.removeClass('feedback-error').addClass('feedback-success')
                                   .text(response.data).show();
                            $form[0].reset();
                            $button.prop('disabled', false).text('Submit Testimonial');
                        } else {
                            $message.removeClass('feedback-success').addClass('feedback-error')
                                   .text(response.data).show();
                            $button.prop('disabled', false).text('Submit Testimonial');
                        }
                    },
                    error: function() {
                        $message.removeClass('feedback-success').addClass('feedback-error')
                               .text('Connection error. Please try again.').show();
                        $button.prop('disabled', false).text('Submit Testimonial');
                    }
                });
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Render survey form
     */
    private static function render_survey_form($volunteer, $opportunity_id) {
        global $wpdb;

        // Get recent completed opportunities
        $opportunities = $wpdb->get_results($wpdb->prepare(
            "SELECT o.id, o.title, o.event_date
             FROM {$wpdb->prefix}fs_opportunities o
             JOIN {$wpdb->prefix}fs_signups s ON o.id = s.opportunity_id
             WHERE s.volunteer_id = %d
             AND s.status = 'confirmed'
             AND o.event_date <= CURDATE()
             AND s.volunteer_id NOT IN (
                 SELECT volunteer_id FROM {$wpdb->prefix}fs_surveys WHERE opportunity_id = o.id
             )
             ORDER BY o.event_date DESC
             LIMIT 10",
            $volunteer->id
        ));

        $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

        ob_start();
        ?>
        <div class="feedback-card">
            <h3>Post-Event Survey</h3>
            <p>Help us improve by sharing your experience!</p>

            <div id="survey-message" class="feedback-message" style="display: none;"></div>

            <?php if (empty($opportunities)): ?>
                <p style="color: #666;">You don't have any recent completed volunteer shifts to review.</p>
            <?php else: ?>
                <form id="survey-form">
                    <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">

                    <div class="form-group">
                        <label for="survey_opportunity">Select Shift *</label>
                        <select id="survey_opportunity" name="opportunity_id" required>
                            <option value="">-- Choose a shift --</option>
                            <?php foreach ($opportunities as $opp): ?>
                                <option value="<?php echo $opp->id; ?>" <?php selected($opportunity_id, $opp->id); ?>>
                                    <?php echo esc_html($opp->title); ?> (<?php echo date('M j, Y', strtotime($opp->event_date)); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Overall Rating *</label>
                        <div class="star-rating">
                            <input type="radio" name="rating" id="star5" value="5" required>
                            <label for="star5">★</label>
                            <input type="radio" name="rating" id="star4" value="4">
                            <label for="star4">★</label>
                            <input type="radio" name="rating" id="star3" value="3">
                            <label for="star3">★</label>
                            <input type="radio" name="rating" id="star2" value="2">
                            <label for="star2">★</label>
                            <input type="radio" name="rating" id="star1" value="1">
                            <label for="star1">★</label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="enjoyed_most">What did you enjoy most?</label>
                        <textarea id="enjoyed_most" name="enjoyed_most" placeholder="Tell us what you liked..."></textarea>
                    </div>

                    <div class="form-group">
                        <label for="could_improve">What could be improved?</label>
                        <textarea id="could_improve" name="could_improve" placeholder="Suggestions for improvement..."></textarea>
                    </div>

                    <div class="form-group">
                        <label>Would you recommend this opportunity? *</label>
                        <div class="radio-group">
                            <label>
                                <input type="radio" name="would_recommend" value="yes" required>
                                Yes
                            </label>
                            <label>
                                <input type="radio" name="would_recommend" value="no">
                                No
                            </label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="additional_comments">Additional Comments</label>
                        <textarea id="additional_comments" name="additional_comments" placeholder="Anything else you'd like to share..."></textarea>
                    </div>

                    <button type="submit" class="btn-submit">Submit Survey</button>
                </form>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render suggestion form
     */
    private static function render_suggestion_form($volunteer) {
        $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

        ob_start();
        ?>
        <div class="feedback-card">
            <h3>Suggestion Box</h3>
            <p>We value your ideas! Share suggestions to help us improve.</p>

            <div id="suggestion-message" class="feedback-message" style="display: none;"></div>

            <form id="suggestion-form">
                <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">

                <div class="form-group">
                    <label for="suggestion_category">Category *</label>
                    <select id="suggestion_category" name="category" required>
                        <option value="">-- Select a category --</option>
                        <option value="opportunity">Volunteer Opportunities</option>
                        <option value="scheduling">Scheduling & Availability</option>
                        <option value="communication">Communication</option>
                        <option value="training">Training & Onboarding</option>
                        <option value="recognition">Recognition & Rewards</option>
                        <option value="portal">Volunteer Portal</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="suggestion_subject">Subject *</label>
                    <input type="text" id="suggestion_subject" name="subject" required placeholder="Brief description of your suggestion">
                </div>

                <div class="form-group">
                    <label for="suggestion_text">Your Suggestion *</label>
                    <textarea id="suggestion_text" name="suggestion" required placeholder="Tell us your idea in detail..."></textarea>
                </div>

                <button type="submit" class="btn-submit">Submit Suggestion</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render testimonial form
     */
    private static function render_testimonial_form($volunteer) {
        $token = isset($_GET['token']) ? sanitize_text_field($_GET['token']) : '';

        ob_start();
        ?>
        <div class="feedback-card">
            <h3>Share Your Story</h3>
            <p>Your testimonial helps inspire others to volunteer and shows the impact of our work.</p>

            <div id="testimonial-message" class="feedback-message" style="display: none;"></div>

            <form id="testimonial-form">
                <input type="hidden" name="token" value="<?php echo esc_attr($token); ?>">

                <div class="form-group">
                    <label for="testimonial_text">Your Testimonial *</label>
                    <textarea id="testimonial_text" name="testimonial" required placeholder="Share your volunteer experience..."></textarea>
                    <small style="color: #666;">What did you enjoy? How did volunteering impact you?</small>
                </div>

                <div class="form-group">
                    <label for="impact_story">Impact Story (Optional)</label>
                    <textarea id="impact_story" name="impact_story" placeholder="Tell us about the difference you made..."></textarea>
                    <small style="color: #666;">Did you see the results of your work? How did it affect the community?</small>
                </div>

                <div class="form-group">
                    <label for="display_name">Display Name *</label>
                    <input type="text" id="display_name" name="display_name" value="<?php echo esc_attr($volunteer->name); ?>" required>
                    <small style="color: #666;">How should we credit you if we publish your testimonial?</small>
                </div>

                <div class="form-group">
                    <div class="checkbox-group">
                        <input type="checkbox" id="permission_publish" name="permission_to_publish" value="1">
                        <label for="permission_publish">
                            I give permission for this testimonial to be published on your website, social media, or promotional materials.
                        </label>
                    </div>
                    <small style="color: #666; margin-left: 30px; display: block; margin-top: 5px;">
                        We'll review and may contact you before publishing.
                    </small>
                </div>

                <button type="submit" class="btn-submit">Submit Testimonial</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}
