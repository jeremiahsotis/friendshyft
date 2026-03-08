<?php
if (!defined('ABSPATH')) exit;

class FS_Public_Opportunities {
    
    public static function init() {
        add_shortcode('browse_opportunities', array(__CLASS__, 'browse_shortcode'));
    }
    
    public static function browse_shortcode($atts) {
        // Parse attributes
        $atts = shortcode_atts(array(
            'conference' => '', // Filter by specific conference
            'limit' => 0, // Limit number shown (0 = all)
            'show_signup' => 'yes' // Show signup buttons or not
        ), $atts);
        
        global $wpdb;
        
        // Build query
        $where = "status = 'Open' AND datetime_start >= NOW()";
        
        if (!empty($atts['conference'])) {
            $where .= $wpdb->prepare(" AND conference = %s", $atts['conference']);
        }
        
        $limit_clause = '';
        if ($atts['limit'] > 0) {
            $limit_clause = ' LIMIT ' . intval($atts['limit']);
        }
        
        // Get opportunities
        $opportunities = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_opportunities 
            WHERE $where
            ORDER BY datetime_start ASC" . $limit_clause
        );
        
        $is_logged_in = is_user_logged_in();
        $show_signup = ($atts['show_signup'] === 'yes');
        
        ob_start();
        ?>
        <div class="fs-public-opportunities">
            <?php if (empty($opportunities)): ?>
                <div class="no-opportunities">
                    <p>No upcoming opportunities at this time. Check back soon!</p>
                </div>
            <?php else: ?>
                <div class="opportunities-grid">
                    <?php foreach ($opportunities as $opp): ?>
                        <div class="opportunity-card">
                            <div class="opp-header">
                                <h3><?php echo esc_html($opp->title); ?></h3>
                                <?php if ($opp->conference): ?>
                                    <span class="conference-badge"><?php echo esc_html($opp->conference); ?></span>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($opp->description): ?>
                                <div class="opp-description">
                                    <?php echo wpautop(wp_kses_post($opp->description)); ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="opp-details">
                                <div class="detail-item">
                                    <strong>📅 When:</strong><br>
                                    <?php echo date('l, F j, Y', strtotime($opp->datetime_start)); ?><br>
                                    <?php echo date('g:i A', strtotime($opp->datetime_start)); ?>
                                    <?php if ($opp->datetime_end): ?>
                                        - <?php echo date('g:i A', strtotime($opp->datetime_end)); ?>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($opp->location): ?>
                                    <div class="detail-item">
                                        <strong>📍 Where:</strong><br>
                                        <?php echo esc_html($opp->location); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="detail-item">
                                    <strong>👥 Capacity:</strong><br>
                                    <?php echo $opp->spots_filled; ?> / <?php echo $opp->spots_available; ?> spots filled
                                    <?php if ($opp->spots_filled >= $opp->spots_available): ?>
                                        <span class="full-indicator">FULL</span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($opp->requirements): ?>
                                    <div class="detail-item">
                                        <strong>Requirements:</strong><br>
                                        <?php echo wpautop(wp_kses_post($opp->requirements)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            
                            <?php if ($show_signup): ?>
                                <div class="opp-actions">
    <?php if ($opp->spots_filled >= $opp->spots_available): ?>
        <button class="button-signup disabled" disabled>Opportunity Full</button>
    <?php elseif (!$is_logged_in): ?>
        <a href="<?php echo wp_login_url(get_permalink()); ?>" class="button-signup">
            Log In to Sign Up
        </a>
    <?php else: ?>
        <a href="<?php echo home_url('/volunteer-portal/opportunities/'); ?>" class="button-signup">
            Sign Up →
        </a>
    <?php endif; ?>
    
    <a href="<?php echo FS_Calendar_Export::get_export_url($opp->id); ?>" 
       class="button-calendar" 
       title="Add to Calendar">
        📅 Add to Calendar
    </a>
</div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <style>
            .fs-public-opportunities {
                max-width: 1200px;
                margin: 0 auto;
                padding: 20px;
            }
            .no-opportunities {
                text-align: center;
                padding: 60px 20px;
                background: #f8f9fa;
                border-radius: 8px;
            }
            .opportunities-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
                gap: 25px;
            }
            .opportunity-card {
                background: white;
                border: 2px solid #e0e0e0;
                border-radius: 8px;
                padding: 25px;
                display: flex;
                flex-direction: column;
                transition: all 0.3s ease;
                box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            }
            .opportunity-card:hover {
                border-color: #0073aa;
                box-shadow: 0 4px 12px rgba(0,115,170,0.15);
            }
            .opp-header {
                margin-bottom: 15px;
                padding-bottom: 15px;
                border-bottom: 2px solid #f0f0f0;
            }
            .opp-header h3 {
                margin: 0 0 10px 0;
                font-size: 1.4em;
                color: #333;
            }
            .conference-badge {
                display: inline-block;
                padding: 4px 12px;
                background: #e7f3ff;
                color: #0066cc;
                border-radius: 12px;
                font-size: 13px;
                font-weight: 600;
            }
            .opp-description {
                margin-bottom: 20px;
                color: #666;
                font-size: 15px;
                line-height: 1.6;
            }
            .opp-details {
                display: flex;
                flex-direction: column;
                gap: 15px;
                margin-bottom: 20px;
                padding: 20px;
                background: #f8f9fa;
                border-radius: 6px;
            }
            .detail-item {
                font-size: 14px;
                line-height: 1.6;
            }
            .detail-item strong {
                color: #333;
                font-size: 15px;
            }
            .full-indicator {
                display: inline-block;
                margin-left: 10px;
                padding: 2px 8px;
                background: #dc3545;
                color: white;
                border-radius: 4px;
                font-size: 12px;
                font-weight: 600;
            }
            .opp-actions {
                margin-top: auto;
            }
            .button-signup {
                display: block;
                width: 100%;
                background: #0073aa;
                color: white;
                padding: 14px;
                border: none;
                border-radius: 6px;
                font-size: 16px;
                font-weight: 600;
                cursor: pointer;
                transition: background 0.2s;
                text-align: center;
                text-decoration: none;
            }
            .button-signup:hover {
                background: #005177;
                color: white;
            }
            .button-signup.disabled {
                background: #ccc;
                cursor: not-allowed;
            }
            .opp-actions {
    margin-top: auto;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}
.button-signup {
    flex: 1;
}
.button-calendar {
    padding: 14px 20px;
    background: #667eea;
    color: white;
    text-decoration: none;
    border-radius: 6px;
    font-size: 14px;
    font-weight: 600;
    text-align: center;
    transition: background 0.2s;
}
.button-calendar:hover {
    background: #5568d3;
    color: white;
}
            @media (max-width: 768px) {
                .opportunities-grid {
                    grid-template-columns: 1fr;
                }
            }
        </style>
        <?php
        return ob_get_clean();
    }
}

FS_Public_Opportunities::init();