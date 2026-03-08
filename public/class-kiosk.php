<?php
if (!defined('ABSPATH')) exit;

class FS_Kiosk {

    public static function init() {
        add_shortcode('volunteer_kiosk', array(__CLASS__, 'kiosk_shortcode'));
        add_action('rest_api_init', array(__CLASS__, 'register_kiosk_endpoints'));
    }

    /**
     * Register custom REST API endpoints for kiosk
     */
    public static function register_kiosk_endpoints() {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Registering kiosk REST API endpoints');
        }

        // Verify PIN/QR endpoint
        register_rest_route('kiosk-api', '/verify-pin', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'api_verify_pin'),
            'permission_callback' => '__return_true', // Public endpoint
        ));

        // Check-in endpoint
        register_rest_route('kiosk-api', '/check-in', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'api_check_in'),
            'permission_callback' => '__return_true', // Public endpoint
        ));

        // Check-out endpoint
        register_rest_route('kiosk-api', '/check-out', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'api_check_out'),
            'permission_callback' => '__return_true', // Public endpoint
        ));

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('FriendShyft: Kiosk REST API endpoints registered successfully');
        }
    }
    
    public static function kiosk_shortcode($atts) {
        // Check if QR code provided
        $qr_code = isset($_GET['qr']) ? sanitize_text_field($_GET['qr']) : '';
        
        ob_start();
        ?>
        <div class="fs-kiosk-container">
            <!-- Step 1: Enter PIN -->
            <div class="kiosk-screen" id="pin-screen">
                <div class="kiosk-header">
                    <h1>🙋 Volunteer Check-In</h1>
                    <p>Enter your 4-digit PIN to get started</p>
                </div>
                
                <div class="pin-display">
                    <div class="pin-dots">
                        <span class="pin-dot"></span>
                        <span class="pin-dot"></span>
                        <span class="pin-dot"></span>
                        <span class="pin-dot"></span>
                    </div>
                </div>
                
                <div class="pin-pad">
                    <button class="pin-btn" data-num="1">1</button>
                    <button class="pin-btn" data-num="2">2</button>
                    <button class="pin-btn" data-num="3">3</button>
                    <button class="pin-btn" data-num="4">4</button>
                    <button class="pin-btn" data-num="5">5</button>
                    <button class="pin-btn" data-num="6">6</button>
                    <button class="pin-btn" data-num="7">7</button>
                    <button class="pin-btn" data-num="8">8</button>
                    <button class="pin-btn" data-num="9">9</button>
                    <button class="pin-btn clear-btn" data-action="clear">Clear</button>
                    <button class="pin-btn" data-num="0">0</button>
                    <button class="pin-btn back-btn" data-action="back">←</button>
                </div>
                
                <div class="kiosk-footer">
                    <p class="help-text">Need help? Contact staff</p>
                </div>
            </div>
            
            <!-- Step 2: Select Opportunity -->
            <div class="kiosk-screen" id="opportunity-screen" style="display: none;">
                <div class="kiosk-header">
                    <h2 id="volunteer-greeting"></h2>
                    <p>Select your volunteer activity</p>
                </div>
                
                <div id="opportunities-list" class="opportunities-list">
                    <!-- Populated via AJAX -->
                </div>
                
                <button class="btn-secondary" onclick="resetKiosk()">← Start Over</button>
            </div>
            
            <!-- Step 2b: Team Member Count (for team check-ins only) -->
            <div class="kiosk-screen" id="team-count-screen" style="display: none;">
                <div class="kiosk-header">
                    <h2 id="team-count-title">Team Check-In</h2>
                    <p id="team-count-subtitle">How many people are here today?</p>
                </div>

                <div style="text-align: center; padding: 40px 0;">
                    <div style="margin-bottom: 30px;">
                        <input type="number"
                               id="people-count-input"
                               min="1"
                               value="1"
                               style="font-size: 3em; width: 150px; padding: 20px; text-align: center; border: 3px solid #007bff; border-radius: 10px;">
                    </div>

                    <div style="margin-bottom: 20px;">
                        <button class="btn-number" onclick="adjustPeopleCount(-5)">-5</button>
                        <button class="btn-number" onclick="adjustPeopleCount(-1)">-1</button>
                        <button class="btn-number" onclick="adjustPeopleCount(1)">+1</button>
                        <button class="btn-number" onclick="adjustPeopleCount(5)">+5</button>
                    </div>

                    <button class="btn-primary" id="confirm-team-checkin-btn" style="font-size: 1.5em; padding: 20px 60px; margin-top: 20px;">
                        Check In Team
                    </button>
                </div>

                <button class="btn-secondary" onclick="backToOpportunities()">← Back</button>
            </div>

            <!-- Step 3: Confirmation -->
            <div class="kiosk-screen" id="confirmation-screen" style="display: none;">
                <div class="confirmation-content">
                    <div class="success-icon">✓</div>
                    <h2 id="confirmation-message"></h2>
                    <p id="confirmation-details"></p>
                </div>
            </div>
        </div>
        
        <style>
            .fs-kiosk-container {
                max-width: 600px;
                margin: 0 auto;
                padding: 20px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            }
            .kiosk-screen {
                background: white;
                border-radius: 20px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.1);
                padding: 40px;
            }
            .kiosk-header {
                text-align: center;
                margin-bottom: 40px;
            }
            .kiosk-header h1 {
                font-size: 2.5em;
                margin: 0 0 10px 0;
                color: #333;
            }
            .kiosk-header h2 {
                font-size: 2em;
                margin: 0 0 10px 0;
                color: #333;
            }
            .kiosk-header p {
                color: #666;
                font-size: 1.2em;
                margin: 0;
            }
            .pin-display {
                text-align: center;
                margin-bottom: 40px;
            }
            .pin-dots {
                display: inline-flex;
                gap: 20px;
            }
            .pin-dot {
                width: 20px;
                height: 20px;
                border: 3px solid #ddd;
                border-radius: 50%;
                display: inline-block;
                transition: all 0.2s;
            }
            .pin-dot.filled {
                background: #0073aa;
                border-color: #0073aa;
            }
            .pin-pad {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 15px;
                margin-bottom: 30px;
            }
            .pin-btn {
                padding: 30px;
                font-size: 2em;
                font-weight: bold;
                border: 2px solid #ddd;
                background: white;
                border-radius: 15px;
                cursor: pointer;
                transition: all 0.2s;
            }
            .pin-btn:hover {
                background: #f0f0f0;
                border-color: #0073aa;
            }
            .pin-btn:active {
                transform: scale(0.95);
            }
            .clear-btn, .back-btn {
                background: #f8f9fa;
                font-size: 1.2em;
            }
            .kiosk-footer {
                text-align: center;
                margin-top: 30px;
            }
            .help-text {
                color: #999;
                font-size: 0.9em;
            }
            .opportunities-list {
                display: flex;
                flex-direction: column;
                gap: 15px;
                margin-bottom: 30px;
            }
            .opportunity-option {
                padding: 25px;
                border: 3px solid #ddd;
                border-radius: 15px;
                cursor: pointer;
                transition: all 0.2s;
                background: white;
            }
            .opportunity-option:hover {
                border-color: #0073aa;
                background: #f0f6fc;
            }
            .opportunity-option.suggested {
                border-color: #28a745;
                background: #d4edda;
            }
            .opportunity-option h3 {
                margin: 0 0 10px 0;
                font-size: 1.4em;
            }
            .opportunity-option p {
                margin: 0;
                color: #666;
            }
            .suggested-badge {
                display: inline-block;
                padding: 4px 10px;
                background: #28a745;
                color: white;
                border-radius: 12px;
                font-size: 0.8em;
                font-weight: 600;
                margin-left: 10px;
            }
            .btn-secondary {
                width: 100%;
                padding: 15px;
                font-size: 1.1em;
                border: 2px solid #ddd;
                background: white;
                border-radius: 10px;
                cursor: pointer;
            }
            .btn-secondary:hover {
                background: #f0f0f0;
            }
            .btn-number {
                padding: 15px 25px;
                margin: 5px;
                font-size: 1.2em;
                border: 2px solid #007bff;
                background: white;
                color: #007bff;
                border-radius: 8px;
                cursor: pointer;
                min-width: 80px;
            }
            .btn-number:hover {
                background: #007bff;
                color: white;
            }
            .confirmation-content {
                text-align: center;
                padding: 40px 0;
            }
            .success-icon {
                width: 120px;
                height: 120px;
                background: #28a745;
                color: white;
                font-size: 4em;
                border-radius: 50%;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                margin-bottom: 30px;
            }
            .confirmation-content h2 {
                font-size: 2em;
                margin: 0 0 15px 0;
                color: #333;
            }
            .confirmation-content p {
                font-size: 1.2em;
                color: #666;
            }
            @media (max-width: 768px) {
                .fs-kiosk-container {
                    padding: 10px;
                }
                .kiosk-screen {
                    padding: 20px;
                }
                .pin-btn {
                    padding: 20px;
                    font-size: 1.5em;
                }
            }
        </style>
        
        <script>
        (function() {
            let pinValue = '';
            let volunteerId = null;
            let volunteerName = '';
            let activeCheckin = null;
            let isTeamCheckin = false;
            let teamId = null;
            let teamName = '';
            let teamDefaultSize = 1;
            let currentOpportunityId = null;
            let currentOpportunityTitle = '';
            
            // Auto-load QR code if present
            <?php if ($qr_code): ?>
            handleQRCode('<?php echo esc_js($qr_code); ?>');
            <?php endif; ?>
            
            // PIN pad buttons
            document.querySelectorAll('.pin-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    const num = this.dataset.num;
                    const action = this.dataset.action;
                    
                    if (action === 'clear') {
                        pinValue = '';
                        updatePinDisplay();
                    } else if (action === 'back') {
                        pinValue = pinValue.slice(0, -1);
                        updatePinDisplay();
                    } else if (num && pinValue.length < 4) {
                        pinValue += num;
                        updatePinDisplay();
                        
                        if (pinValue.length === 4) {
                            verifyPin();
                        }
                    }
                });
            });
            
            function updatePinDisplay() {
                document.querySelectorAll('.pin-dot').forEach(function(dot, index) {
                    if (index < pinValue.length) {
                        dot.classList.add('filled');
                    } else {
                        dot.classList.remove('filled');
                    }
                });
            }
            
            function verifyPin() {
                const formData = new URLSearchParams();
                formData.append('pin', pinValue);

                fetch('<?php echo rest_url('kiosk-api/verify-pin'); ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: formData.toString()
                })
                .then(r => r.json())
                .then(function(data) {
                    if (data.success) {
                        volunteerId = data.data.volunteer_id;
                        volunteerName = data.data.volunteer_name;
                        activeCheckin = data.data.active_checkin;

                        // Capture team data if present
                        isTeamCheckin = data.data.is_team_checkin || false;
                        teamId = data.data.team_id || null;
                        teamName = data.data.team_name || '';
                        teamDefaultSize = data.data.team_default_size || 1;

                        showOpportunities(data.data.opportunities, data.data.suggested_id);
                    } else {
                        alert(data.data.message || 'Invalid PIN. Please try again.');
                        pinValue = '';
                        updatePinDisplay();
                    }
                });
            }
            
            function handleQRCode(qrCode) {
                const formData = new URLSearchParams();
                formData.append('qr', qrCode);

                fetch('<?php echo rest_url('kiosk-api/verify-pin'); ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: formData.toString()
                })
                .then(r => r.json())
                .then(function(data) {
                    if (data.success) {
                        volunteerId = data.data.volunteer_id;
                        volunteerName = data.data.volunteer_name;
                        activeCheckin = data.data.active_checkin;

                        // Capture team data if present
                        isTeamCheckin = data.data.is_team_checkin || false;
                        teamId = data.data.team_id || null;
                        teamName = data.data.team_name || '';
                        teamDefaultSize = data.data.team_default_size || 1;

                        showOpportunities(data.data.opportunities, data.data.suggested_id);
                    } else {
                        alert(data.data.message || 'Invalid QR code.');
                    }
                });
            }
            
            function showOpportunities(opportunities, suggestedId) {
                document.getElementById('pin-screen').style.display = 'none';
                document.getElementById('opportunity-screen').style.display = 'block';
                
                const greeting = activeCheckin ? 
                    'Welcome back, ' + volunteerName + '!' : 
                    'Hello, ' + volunteerName + '!';
                document.getElementById('volunteer-greeting').textContent = greeting;
                
                const list = document.getElementById('opportunities-list');
                list.innerHTML = '';
                
                // If already checked in, show checkout option
                if (activeCheckin) {
                    const checkoutDiv = document.createElement('div');
                    checkoutDiv.className = 'opportunity-option suggested';
                    checkoutDiv.innerHTML = '<h3>Check Out <span class="suggested-badge">Currently Active</span></h3>' +
                        '<p>End your shift for: ' + activeCheckin.title + '</p>';
                    checkoutDiv.onclick = function() { checkOut(); };
                    list.appendChild(checkoutDiv);
                    return;
                }
                
                // Show opportunities
                if (opportunities.length === 0) {
                    list.innerHTML = '<p style="text-align:center; color: #666;">No opportunities available right now.</p>';
                    return;
                }
                
                opportunities.forEach(function(opp) {
                    const div = document.createElement('div');
                    div.className = 'opportunity-option' + (opp.id == suggestedId ? ' suggested' : '');
                    
                    const badge = opp.id == suggestedId ? ' <span class="suggested-badge">You\'re signed up!</span>' : '';
                    
                    div.innerHTML = '<h3>' + opp.title + badge + '</h3>' +
                        '<p>' + opp.datetime_start_formatted + '</p>';
                    
                    div.onclick = function() { checkIn(opp.id, opp.title); };
                    list.appendChild(div);
                });
            }
            
            function checkIn(opportunityId, opportunityTitle) {
                // Store opportunity info for team check-in flow
                currentOpportunityId = opportunityId;
                currentOpportunityTitle = opportunityTitle;

                // If this is a team check-in, show the people counter screen
                if (isTeamCheckin) {
                    showTeamCountScreen();
                    return;
                }

                // Otherwise, proceed with normal individual check-in
                performCheckIn(opportunityId, opportunityTitle, 1);
            }

            function performCheckIn(opportunityId, opportunityTitle, peopleCount) {
                const formData = new URLSearchParams();
                formData.append('volunteer_id', volunteerId);
                formData.append('opportunity_id', opportunityId);
                formData.append('people_count', peopleCount);

                fetch('<?php echo rest_url('kiosk-api/check-in'); ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: formData.toString()
                })
                .then(r => r.json())
                .then(function(data) {
                    if (data.success) {
                        const message = isTeamCheckin ?
                            'Team Checked In Successfully!' :
                            'Checked In Successfully!';
                        const details = isTeamCheckin ?
                            teamName + ' (' + peopleCount + ' people) - Have a great shift at ' + opportunityTitle + '!' :
                            'Have a great shift at ' + opportunityTitle + '!';

                        showConfirmation(message, details);
                    } else {
                        alert(data.data.message || 'Failed to check in.');
                    }
                });
            }
            
            function checkOut() {
                const formData = new URLSearchParams();
                formData.append('volunteer_id', volunteerId);

                fetch('<?php echo rest_url('kiosk-api/check-out'); ?>', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: formData.toString()
                })
                .then(r => r.json())
                .then(function(data) {
                    if (data.success) {
                        // Support multiple response formats
                        let hours = '0';
                        if (data.data && data.data.total_hours) {
                            hours = data.data.total_hours;
                        } else if (data.data && data.data.data && data.data.data.total_hours) {
                            hours = data.data.data.total_hours;
                        }
                        showConfirmation(
                            'Checked Out Successfully!',
                            'Thank you for volunteering! Total time: ' + hours + ' hours'
                        );
                    } else {
                        let errorMsg = 'Failed to check out.';
                        if (data.data && data.data.message) {
                            errorMsg = data.data.message;
                        } else if (data.message) {
                            errorMsg = data.message;
                        }
                        alert(errorMsg);
                    }
                })
                .catch(function(error) {
                    console.error('Check-out error:', error);
                    alert('An error occurred during check-out. Please try again.');
                });
            }
            
            function showTeamCountScreen() {
                document.getElementById('opportunity-screen').style.display = 'none';
                document.getElementById('team-count-screen').style.display = 'block';

                // Update title and subtitle with team name
                document.getElementById('team-count-title').textContent = 'Team Check-In: ' + teamName;
                document.getElementById('team-count-subtitle').textContent = 'How many people are here today?';

                // Set default value to team's default size
                document.getElementById('people-count-input').value = teamDefaultSize;

                // Attach event listener to confirm button
                document.getElementById('confirm-team-checkin-btn').onclick = function() {
                    const peopleCount = parseInt(document.getElementById('people-count-input').value);
                    if (peopleCount < 1) {
                        alert('Please enter at least 1 person');
                        return;
                    }
                    performCheckIn(currentOpportunityId, currentOpportunityTitle, peopleCount);
                };
            }

            window.adjustPeopleCount = function(delta) {
                const input = document.getElementById('people-count-input');
                const currentValue = parseInt(input.value) || 1;
                const newValue = Math.max(1, currentValue + delta);
                input.value = newValue;
            };

            window.backToOpportunities = function() {
                document.getElementById('team-count-screen').style.display = 'none';
                document.getElementById('opportunity-screen').style.display = 'block';
            };

            function showConfirmation(title, details) {
                document.getElementById('opportunity-screen').style.display = 'none';
                document.getElementById('team-count-screen').style.display = 'none';
                document.getElementById('confirmation-screen').style.display = 'block';
                document.getElementById('confirmation-message').textContent = title;
                document.getElementById('confirmation-details').textContent = details;
                
                setTimeout(resetKiosk, 3000);
            }
            
            window.resetKiosk = function() {
                pinValue = '';
                volunteerId = null;
                volunteerName = '';
                activeCheckin = null;
                isTeamCheckin = false;
                teamId = null;
                teamName = '';
                teamDefaultSize = 1;
                currentOpportunityId = null;
                currentOpportunityTitle = '';
                updatePinDisplay();
                document.getElementById('pin-screen').style.display = 'block';
                document.getElementById('opportunity-screen').style.display = 'none';
                document.getElementById('team-count-screen').style.display = 'none';
                document.getElementById('confirmation-screen').style.display = 'none';
            };
        })();
        </script>
        <?php
        return ob_get_clean();
    }
    
    public static function api_verify_pin($request) {
        // Support both REST API request object and $_POST for backwards compatibility
        $pin = '';
        $qr = '';

        if (is_a($request, 'WP_REST_Request')) {
            $pin = $request->get_param('pin') ? sanitize_text_field($request->get_param('pin')) : '';
            $qr = $request->get_param('qr') ? sanitize_text_field($request->get_param('qr')) : '';
        } else {
            $pin = isset($_POST['pin']) ? sanitize_text_field($_POST['pin']) : '';
            $qr = isset($_POST['qr']) ? sanitize_text_field($_POST['qr']) : '';
        }

        global $wpdb;
        $volunteer = null;

        if ($pin) {
            // First try to find volunteer by PIN
            $volunteer = FS_Time_Tracking::get_volunteer_by_pin($pin);

            // If not found, check if it's a team PIN
            if (!$volunteer) {
                $team = $wpdb->get_row($wpdb->prepare(
                    "SELECT t.*, v.id as leader_id, v.name as leader_name
                     FROM {$wpdb->prefix}fs_teams t
                     LEFT JOIN {$wpdb->prefix}fs_volunteers v ON t.team_leader_volunteer_id = v.id
                     WHERE t.pin = %s AND t.status = 'active'",
                    $pin
                ));

                if ($team && $team->leader_id) {
                    // Use team leader as the volunteer for check-in
                    $volunteer = $wpdb->get_row($wpdb->prepare(
                        "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
                        $team->leader_id
                    ));

                    if ($volunteer) {
                        // Add team info to volunteer object
                        $volunteer->is_team_checkin = true;
                        $volunteer->team_id = $team->id;
                        $volunteer->team_name = $team->name;
                    }
                } elseif ($team) {
                    return new WP_REST_Response(array('success' => false, 'data' => array('message' => 'Team has no leader assigned. Please contact an administrator.')), 400);
                }
            }
        } elseif ($qr) {
            $volunteer = FS_Time_Tracking::get_volunteer_by_qr($qr);
        } else {
            return new WP_REST_Response(array('success' => false, 'data' => array('message' => 'PIN or QR code required')), 400);
        }

        if (!$volunteer) {
            return new WP_REST_Response(array('success' => false, 'data' => array('message' => 'Invalid PIN or QR code')), 404);
        }

        // Get current opportunities for this volunteer
        $opportunities = FS_Time_Tracking::get_current_opportunities($volunteer->id);

        // Find suggested opportunity (signed up for)
        $suggested_id = null;
        foreach ($opportunities as $opp) {
            if (isset($opp->signup_status)) {
                $suggested_id = $opp->id;
                break;
            }
        }

        // Format opportunities for display
        $formatted_opps = array();
        foreach ($opportunities as $opp) {
            $formatted_opps[] = array(
                'id' => $opp->id,
                'title' => $opp->title,
                'datetime_start_formatted' => date('g:i A', strtotime($opp->datetime_start))
            );
        }

        // Check if already checked in
        $active_checkin = FS_Time_Tracking::get_active_checkin($volunteer->id);

        $active_data = null;
        if ($active_checkin) {
            global $wpdb;
            $opp = $wpdb->get_row($wpdb->prepare(
                "SELECT title FROM {$wpdb->prefix}fs_opportunities WHERE id = %d",
                $active_checkin->opportunity_id
            ));

            $active_data = array(
                'id' => $active_checkin->id,
                'title' => $opp->title,
                'check_in' => $active_checkin->check_in
            );
        }

        $response_data = array(
            'volunteer_id' => $volunteer->id,
            'volunteer_name' => $volunteer->name,
            'opportunities' => $formatted_opps,
            'suggested_id' => $suggested_id,
            'active_checkin' => $active_data
        );

        // Add team data if this is a team check-in
        if (isset($volunteer->is_team_checkin) && $volunteer->is_team_checkin) {
            $response_data['is_team_checkin'] = true;
            $response_data['team_id'] = $volunteer->team_id;
            $response_data['team_name'] = $volunteer->team_name;

            // Get team default size
            $team = $wpdb->get_row($wpdb->prepare(
                "SELECT default_size FROM {$wpdb->prefix}fs_teams WHERE id = %d",
                $volunteer->team_id
            ));
            $response_data['team_default_size'] = $team ? $team->default_size : 1;
        }

        return new WP_REST_Response(array(
            'success' => true,
            'data' => $response_data
        ), 200);
    }
    
    public static function api_check_in($request) {
        // Support both REST API request object and $_POST for backwards compatibility
        if (is_a($request, 'WP_REST_Request')) {
            $volunteer_id = intval($request->get_param('volunteer_id'));
            $opportunity_id = intval($request->get_param('opportunity_id'));
            $people_count = intval($request->get_param('people_count') ?? 1);
        } else {
            $volunteer_id = intval($_POST['volunteer_id'] ?? 0);
            $opportunity_id = intval($_POST['opportunity_id'] ?? 0);
            $people_count = intval($_POST['people_count'] ?? 1);
        }

        if (!$volunteer_id || !$opportunity_id) {
            return new WP_REST_Response(array('success' => false, 'data' => array('message' => 'Missing required data')), 400);
        }

        // Use individual or team check-in based on people count
        if ($people_count > 1) {
            // Team check-in with people count
            $result = FS_Time_Tracking::check_in_with_count($volunteer_id, $opportunity_id, $people_count);
        } else {
            // Individual check-in
            $result = FS_Time_Tracking::check_in($volunteer_id, $opportunity_id);
        }

        if ($result['success']) {
            return new WP_REST_Response(array('success' => true, 'data' => $result), 200);
        } else {
            return new WP_REST_Response(array('success' => false, 'data' => $result), 400);
        }
    }
    
    public static function api_check_out($request) {
        // Support both REST API request object and $_POST for backwards compatibility
        if (is_a($request, 'WP_REST_Request')) {
            $volunteer_id = intval($request->get_param('volunteer_id'));
        } else {
            $volunteer_id = intval($_POST['volunteer_id'] ?? 0);
        }

        if (!$volunteer_id) {
            return new WP_REST_Response(array('success' => false, 'data' => array('message' => 'Missing volunteer ID')), 400);
        }

        $result = FS_Time_Tracking::check_out($volunteer_id);

        if ($result['success']) {
            return new WP_REST_Response(array('success' => true, 'data' => $result), 200);
        } else {
            return new WP_REST_Response(array('success' => false, 'data' => $result), 400);
        }
    }
}

FS_Kiosk::init();