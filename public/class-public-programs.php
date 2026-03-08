<?php
if (!defined('ABSPATH')) exit;

class FS_Public_Programs {
    
    public static function init() {
        add_shortcode('volunteer_programs', array(__CLASS__, 'programs_shortcode'));
    }
    
    public static function programs_shortcode($atts) {
        // Parse attributes
        $atts = shortcode_atts(array(
            'show_cta' => 'yes', // Show call-to-action button
            'cta_text' => 'Get Involved',
            'cta_url' => '/volunteer-interest/' // Link to interest form
        ), $atts);
        
        global $wpdb;
        
        $programs = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}fs_programs 
            WHERE active_status = 'Active' 
            ORDER BY display_order ASC, name ASC"
        );
        
        if (empty($programs)) {
            return '<p>No programs are currently available.</p>';
        }
        
        ob_start();
        ?>
        <div class="fs-programs-showcase">
            <?php if ($atts['show_cta'] === 'yes'): ?>
                <div class="programs-intro">
                    <p class="intro-text">Explore our volunteer programs and find the perfect way to make a difference in your community.</p>
                    <a href="<?php echo esc_url($atts['cta_url']); ?>" class="cta-button">
                        <?php echo esc_html($atts['cta_text']); ?> →
                    </a>
                </div>
            <?php endif; ?>
            
            <div class="programs-grid">
                <?php foreach ($programs as $index => $program): ?>
                    <div class="program-card" data-index="<?php echo $index; ?>">
                        <div class="program-number"><?php echo str_pad($index + 1, 2, '0', STR_PAD_LEFT); ?></div>
                        <h3 class="program-title"><?php echo esc_html($program->name); ?></h3>
                        
                        <?php if ($program->short_description): ?>
                            <p class="program-tagline"><?php echo esc_html($program->short_description); ?></p>
                        <?php endif; ?>
                        
                        <?php if ($program->long_description): ?>
                            <div class="program-description">
                                <?php echo wpautop(wp_kses_post($program->long_description)); ?>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($atts['show_cta'] === 'yes'): ?>
                            <a href="<?php echo esc_url(add_query_arg('program', $program->id, $atts['cta_url'])); ?>" class="program-link">
                                Learn More & Sign Up →
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <style>
            .fs-programs-showcase {
                max-width: 1200px;
                margin: 0 auto;
                padding: 40px 20px;
            }
            .programs-intro {
                text-align: center;
                margin-bottom: 50px;
                padding: 30px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                border-radius: 12px;
                color: white;
            }
            .intro-text {
                font-size: 1.2em;
                margin: 0 0 20px 0;
                line-height: 1.6;
            }
            .cta-button {
                display: inline-block;
                padding: 15px 40px;
                background: white;
                color: #667eea;
                text-decoration: none;
                border-radius: 8px;
                font-weight: 600;
                font-size: 1.1em;
                transition: all 0.3s;
            }
            .cta-button:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 20px rgba(0,0,0,0.2);
                color: #667eea;
            }
            .programs-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 30px;
                max-width: 1000px;
                margin: 0 auto;
            }
            .program-card {
                background: white;
                border-radius: 12px;
                padding: 35px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.08);
                transition: all 0.3s;
                position: relative;
                overflow: hidden;
            }
            .program-card::before {
                content: '';
                position: absolute;
                top: 0;
                left: 0;
                right: 0;
                height: 5px;
                background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            }
            .program-card:hover {
                transform: translateY(-5px);
                box-shadow: 0 8px 24px rgba(0,0,0,0.12);
            }
            .program-number {
                font-size: 3em;
                font-weight: bold;
                color: #f0f0f0;
                margin: -10px 0 -20px 0;
                font-family: Georgia, serif;
            }
            .program-title {
                margin: 0 0 15px 0;
                color: #667eea;
                font-size: 1.6em;
                line-height: 1.2;
            }
            .program-tagline {
                font-size: 1.1em;
                color: #666;
                font-weight: 600;
                margin: 0 0 20px 0;
                line-height: 1.4;
            }
            .program-description {
                color: #555;
                line-height: 1.8;
                margin-bottom: 25px;
            }
            .program-link {
                display: inline-block;
                color: #667eea;
                text-decoration: none;
                font-weight: 600;
                font-size: 1.05em;
                transition: all 0.2s;
            }
            .program-link:hover {
                color: #764ba2;
                transform: translateX(5px);
            }
            @media (max-width: 768px) {
                .programs-grid {
                    grid-template-columns: 1fr;
                }
                .program-card {
                    padding: 25px;
                }
            }
        </style>
        <?php
        return ob_get_clean();
    }
}

FS_Public_Programs::init();