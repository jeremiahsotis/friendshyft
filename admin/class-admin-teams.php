<?php
if (!defined('ABSPATH')) exit;

/**
 * Teams Admin Page
 * List and manage teams
 */

class FS_Admin_Teams {
    
    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu_page'), 15);
        add_action('admin_post_fs_create_team', array(__CLASS__, 'create_team'));
        add_action('admin_post_fs_update_team', array(__CLASS__, 'update_team'));
        add_action('admin_post_fs_delete_team', array(__CLASS__, 'delete_team'));
        add_action('admin_init', array(__CLASS__, 'handle_team_pin_qr_actions'));
    }
    
    public static function add_menu_page() {
        add_submenu_page(
            'friendshyft',
            'Teams',
            'Teams',
            'edit_posts',
            'fs-teams',
            array(__CLASS__, 'render_page')
        );
    }
    
    public static function render_page() {
        $action = isset($_GET['action']) ? $_GET['action'] : 'list';
        
        switch ($action) {
            case 'edit':
                self::render_edit_page();
                break;
            case 'new':
                self::render_new_page();
                break;
            default:
                self::render_list_page();
                break;
        }
    }
    
    /**
     * List all teams
     */
    private static function render_list_page() {
        $status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'all';
        $type_filter = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'all';
        
        $teams = FS_Team_Manager::get_teams(array(
            'status' => $status_filter,
            'type' => $type_filter
        ));
        
        ?>
        <div class="wrap">
            <h1>
                Teams
                <a href="<?php echo admin_url('admin.php?page=fs-teams&action=new'); ?>" class="page-title-action">
                    Add New Team
                </a>
            </h1>
            
            <?php if (isset($_GET['created'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Team created successfully!</p>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Team updated successfully!</p>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['deleted'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Team deleted successfully!</p>
                </div>
            <?php endif; ?>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <select onchange="window.location.href='<?php echo admin_url('admin.php?page=fs-teams&status='); ?>' + this.value + '&type=<?php echo $type_filter; ?>'">
                        <option value="all" <?php selected($status_filter, 'all'); ?>>All Statuses</option>
                        <option value="active" <?php selected($status_filter, 'active'); ?>>Active</option>
                        <option value="inactive" <?php selected($status_filter, 'inactive'); ?>>Inactive</option>
                    </select>
                    
                    <select onchange="window.location.href='<?php echo admin_url('admin.php?page=fs-teams&type='); ?>' + this.value + '&status=<?php echo $status_filter; ?>'">
                        <option value="all" <?php selected($type_filter, 'all'); ?>>All Types</option>
                        <option value="recurring" <?php selected($type_filter, 'recurring'); ?>>Recurring</option>
                        <option value="one-time" <?php selected($type_filter, 'one-time'); ?>>One-Time</option>
                    </select>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Team Name</th>
                        <th>Type</th>
                        <th>Team Leader</th>
                        <th>Default Size</th>
                        <th>Members Tracked</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($teams)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 40px;">
                                <p>No teams yet.</p>
                                <p>
                                    <a href="<?php echo admin_url('admin.php?page=fs-teams&action=new'); ?>" 
                                       class="button button-primary">
                                        Create Your First Team
                                    </a>
                                </p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($teams as $team): ?>
                            <tr>
                                <td>
                                    <strong>
                                        <a href="<?php echo admin_url('admin.php?page=fs-teams&action=edit&team_id=' . $team->id); ?>">
                                            <?php echo esc_html($team->name); ?>
                                        </a>
                                    </strong>
                                </td>
                                <td>
                                    <span class="dashicons dashicons-<?php echo $team->type === 'recurring' ? 'update' : 'calendar-alt'; ?>"></span>
                                    <?php echo esc_html(ucfirst($team->type)); ?>
                                </td>
                                <td>
                                    <?php if ($team->leader_name): ?>
                                        <?php echo esc_html($team->leader_name); ?>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int)$team->default_size; ?> people</td>
                                <td>
                                    <?php if ($team->member_count > 0): ?>
                                        <a href="<?php echo admin_url('admin.php?page=fs-teams&action=edit&team_id=' . $team->id . '#members'); ?>">
                                            <?php echo (int)$team->member_count; ?> members
                                        </a>
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($team->status === 'active'): ?>
                                        <span style="color: green;">● Active</span>
                                    <?php else: ?>
                                        <span style="color: gray;">● Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=fs-teams&action=edit&team_id=' . $team->id); ?>" 
                                       class="button button-small">
                                        Edit
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * New team form
     */
    private static function render_new_page() {
        // Get all volunteers for team leader dropdown
        global $wpdb;
        $volunteers = $wpdb->get_results(
            "SELECT id, name, email FROM {$wpdb->prefix}fs_volunteers 
             ORDER BY name ASC"
        );
        
        ?>
        <div class="wrap">
            <h1>Add New Team</h1>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="fs_create_team">
                <?php wp_nonce_field('fs_create_team'); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="name">Team Name *</label></th>
                        <td>
                            <input type="text" name="name" id="name" class="regular-text" required>
                            <p class="description">e.g., "Smith Family", "Lincoln Elementary 5th Grade"</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="type">Team Type *</label></th>
                        <td>
                            <select name="type" id="type">
                                <option value="recurring">Recurring</option>
                                <option value="one-time">One-Time</option>
                            </select>
                            <p class="description">Recurring teams volunteer multiple times, one-time teams are for single events</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="team_leader_volunteer_id">Team Leader</label></th>
                        <td>
                            <select name="team_leader_volunteer_id" id="team_leader_volunteer_id" class="regular-text">
                                <option value="">No Leader Assigned</option>
                                <?php foreach ($volunteers as $vol): ?>
                                    <option value="<?php echo $vol->id; ?>">
                                        <?php echo esc_html($vol->name); ?> (<?php echo esc_html($vol->email); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="description">Optional: Primary contact for this team</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="default_size">Default Team Size *</label></th>
                        <td>
                            <input type="number" name="default_size" id="default_size" value="1" min="1" required>
                            <p class="description">Typical number of people in this team</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="description">Description</label></th>
                        <td>
                            <textarea name="description" id="description" rows="4" class="large-text"></textarea>
                            <p class="description">Optional notes about this team</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="status">Status</label></th>
                        <td>
                            <select name="status" id="status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary">Create Team</button>
                    <a href="<?php echo admin_url('admin.php?page=fs-teams'); ?>" class="button">Cancel</a>
                </p>
            </form>
        </div>
        <?php
    }
    
    /**
     * Edit team form
     */
    private static function render_edit_page() {
        $team_id = isset($_GET['team_id']) ? (int)$_GET['team_id'] : 0;
        $team = FS_Team_Manager::get_team($team_id);
        
        if (!$team) {
            wp_die('Team not found');
        }
        
        $members = FS_Team_Manager::get_members($team_id);
        $signups = FS_Team_Manager::get_team_signups($team_id);
        
        // Get all volunteers for dropdowns
        global $wpdb;
        $volunteers = $wpdb->get_results(
            "SELECT id, name, email FROM {$wpdb->prefix}fs_volunteers 
             ORDER BY name ASC"
        );
        
        ?>
        <div class="wrap">
            <h1>Edit Team: <?php echo esc_html($team->name); ?></h1>
            
            <?php if (isset($_GET['member_added'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Member added successfully!</p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['pin_generated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Team PIN generated successfully!</p>
                </div>
            <?php endif; ?>

            <?php if (isset($_GET['qr_generated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>Team QR code generated successfully!</p>
                </div>
            <?php endif; ?>
            
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                <input type="hidden" name="action" value="fs_update_team">
                <input type="hidden" name="team_id" value="<?php echo $team->id; ?>">
                <?php wp_nonce_field('fs_update_team_' . $team->id); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="name">Team Name *</label></th>
                        <td>
                            <input type="text" name="name" id="name" class="regular-text" 
                                   value="<?php echo esc_attr($team->name); ?>" required>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="type">Team Type *</label></th>
                        <td>
                            <select name="type" id="type">
                                <option value="recurring" <?php selected($team->type, 'recurring'); ?>>Recurring</option>
                                <option value="one-time" <?php selected($team->type, 'one-time'); ?>>One-Time</option>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="team_leader_volunteer_id">Team Leader</label></th>
                        <td>
                            <select name="team_leader_volunteer_id" id="team_leader_volunteer_id" class="regular-text">
                                <option value="">No Leader Assigned</option>
                                <?php foreach ($volunteers as $vol): ?>
                                    <option value="<?php echo $vol->id; ?>" 
                                            <?php selected($team->team_leader_volunteer_id, $vol->id); ?>>
                                        <?php echo esc_html($vol->name); ?> (<?php echo esc_html($vol->email); ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="default_size">Default Team Size *</label></th>
                        <td>
                            <input type="number" name="default_size" id="default_size" 
                                   value="<?php echo esc_attr($team->default_size); ?>" min="1" required>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="description">Description</label></th>
                        <td>
                            <textarea name="description" id="description" rows="4" class="large-text"><?php 
                                echo esc_textarea($team->description); 
                            ?></textarea>
                        </td>
                    </tr>
                    
                    <tr>
                        <th><label for="status">Status</label></th>
                        <td>
                            <select name="status" id="status">
                                <option value="active" <?php selected($team->status, 'active'); ?>>Active</option>
                                <option value="inactive" <?php selected($team->status, 'inactive'); ?>>Inactive</option>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <button type="submit" class="button button-primary">Update Team</button>
                    <a href="<?php echo admin_url('admin.php?page=fs-teams'); ?>" class="button">Back to Teams</a>
                </p>
            </form>

            <!-- Team PIN and QR Code Section -->
            <hr>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
                <!-- PIN Section -->
                <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
                    <h3>🔢 Team PIN</h3>
                    <p class="description">PIN number for team kiosk check-in</p>

                    <?php if (!empty($team->pin)): ?>
                        <div style="background: #f0f0f1; padding: 15px; border-radius: 4px; margin: 15px 0;">
                            <div style="font-size: 32px; font-weight: bold; text-align: center; color: #2271b1;">
                                <?php echo esc_html($team->pin); ?>
                            </div>
                        </div>
                        <form method="post" style="margin-top: 10px;">
                            <?php wp_nonce_field('fs_team_action'); ?>
                            <input type="hidden" name="team_id" value="<?php echo $team->id; ?>">
                            <input type="hidden" name="action" value="regenerate_team_pin">
                            <button type="submit" class="button" onclick="return confirm('Regenerate PIN? The old PIN will no longer work.');">
                                Regenerate PIN
                            </button>
                        </form>
                    <?php else: ?>
                        <p>No PIN generated yet.</p>
                        <form method="post">
                            <?php wp_nonce_field('fs_team_action'); ?>
                            <input type="hidden" name="team_id" value="<?php echo $team->id; ?>">
                            <input type="hidden" name="action" value="generate_team_pin">
                            <button type="submit" class="button button-primary">Generate PIN</button>
                        </form>
                    <?php endif; ?>
                </div>

                <!-- QR Code Section -->
                <div style="background: #fff; padding: 20px; border: 1px solid #ddd; border-radius: 4px;">
                    <h3>📱 Team QR Code</h3>
                    <p class="description">QR code for team kiosk check-in</p>

                    <?php if (!empty($team->qr_code)): ?>
                        <div style="background: #f0f0f1; padding: 15px; border-radius: 4px; margin: 15px 0; text-align: center;">
                            <div style="font-family: monospace; font-size: 14px; word-break: break-all; margin-bottom: 10px;">
                                <?php echo esc_html($team->qr_code); ?>
                            </div>
                            <small style="color: #666;">Scan this code at kiosk</small>
                        </div>
                        <form method="post" style="margin-top: 10px;">
                            <?php wp_nonce_field('fs_team_action'); ?>
                            <input type="hidden" name="team_id" value="<?php echo $team->id; ?>">
                            <input type="hidden" name="action" value="regenerate_team_qr">
                            <button type="submit" class="button" onclick="return confirm('Regenerate QR Code? The old code will no longer work.');">
                                Regenerate QR Code
                            </button>
                        </form>
                    <?php else: ?>
                        <p>No QR code generated yet.</p>
                        <form method="post">
                            <?php wp_nonce_field('fs_team_action'); ?>
                            <input type="hidden" name="team_id" value="<?php echo $team->id; ?>">
                            <input type="hidden" name="action" value="generate_team_qr">
                            <button type="submit" class="button button-primary">Generate QR Code</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <hr>

            <!-- Team Members Section -->
            <div id="members">
                <h2>Team Members (<?php echo count($members); ?>)</h2>
                <p class="description">Optional: Track individual members for follow-up communications</p>
                
                <?php if (!empty($members)): ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Role</th>
                                <th>Email</th>
                                <th>Notes</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($members as $member): ?>
                                <tr>
                                    <td>
                                        <?php if ($member->volunteer_id): ?>
                                            <a href="<?php echo admin_url('admin.php?page=fs-volunteer-detail&id=' . $member->volunteer_id); ?>">
                                                <?php echo esc_html($member->volunteer_name); ?>
                                            </a>
                                        <?php else: ?>
                                            <?php echo esc_html($member->name); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo esc_html(ucfirst($member->role)); ?></td>
                                    <td><?php echo $member->email ? esc_html($member->email) : '—'; ?></td>
                                    <td><?php echo $member->notes ? esc_html($member->notes) : '—'; ?></td>
                                    <td>
                                        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display: inline;">
                                            <input type="hidden" name="action" value="fs_remove_team_member">
                                            <input type="hidden" name="member_id" value="<?php echo $member->id; ?>">
                                            <input type="hidden" name="team_id" value="<?php echo $team_id; ?>">
                                            <?php wp_nonce_field('fs_remove_member_' . $member->id); ?>
                                            <button type="submit" class="button button-small" 
                                                    onclick="return confirm('Remove this member?');">
                                                Remove
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                
                <h3>Add Team Member</h3>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="card" style="max-width: 600px; padding: 15px;">
                    <input type="hidden" name="action" value="fs_add_team_member">
                    <input type="hidden" name="team_id" value="<?php echo $team_id; ?>">
                    <?php wp_nonce_field('fs_add_member_' . $team_id); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th><label for="volunteer_id">Existing Volunteer</label></th>
                            <td>
                                <select name="volunteer_id" id="volunteer_id" class="regular-text">
                                    <option value="">Select volunteer...</option>
                                    <?php foreach ($volunteers as $vol): ?>
                                        <option value="<?php echo $vol->id; ?>">
                                            <?php echo esc_html($vol->name); ?> (<?php echo esc_html($vol->email); ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="2" style="text-align: center;">— OR —</td>
                        </tr>
                        <tr>
                            <th><label for="member_name">Name (not in system)</label></th>
                            <td>
                                <input type="text" name="member_name" id="member_name" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="role">Role</label></th>
                            <td>
                                <select name="role" id="role">
                                    <option value="member">Member</option>
                                    <option value="leader">Leader</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="notes">Notes</label></th>
                            <td>
                                <input type="text" name="notes" id="notes" class="regular-text">
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="submit" class="button">Add Member</button>
                    </p>
                </form>
            </div>
            
            <hr>
            
            <!-- Team Signups Section -->
            <h2>Recent Signups (<?php echo count($signups); ?>)</h2>
            <?php if (!empty($signups)): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Opportunity</th>
                            <th>Date</th>
                            <th>Scheduled Size</th>
                            <th>Actual Attendance</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($signups as $signup): ?>
                            <tr>
                                <td><?php echo esc_html($signup->opportunity_name); ?></td>
                                <td>
                                    <?php if ($signup->shift_date): ?>
                                        <?php echo date('M j, Y', strtotime($signup->shift_date)); ?>
                                        <?php if ($signup->start_time): ?>
                                            @ <?php echo date('g:ia', strtotime($signup->start_time)); ?>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        Ongoing
                                    <?php endif; ?>
                                </td>
                                <td><?php echo (int)$signup->scheduled_size; ?> people</td>
                                <td>
                                    <?php if ($signup->actual_attendance): ?>
                                        <?php echo (int)$signup->actual_attendance; ?> people
                                    <?php else: ?>
                                        —
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html(ucfirst($signup->status)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p>No signups yet for this team.</p>
            <?php endif; ?>
            
            <hr>
            
            <!-- Delete Team Section -->
            <div class="card" style="max-width: 600px; border-left: 4px solid #dc3232;">
                <h3>Delete Team</h3>
                <p>Permanently delete this team. This cannot be undone.</p>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" 
                      onsubmit="return confirm('Are you sure you want to permanently delete this team? This cannot be undone.');">
                    <input type="hidden" name="action" value="fs_delete_team">
                    <input type="hidden" name="team_id" value="<?php echo $team->id; ?>">
                    <?php wp_nonce_field('fs_delete_team_' . $team->id); ?>
                    <button type="submit" class="button">Delete Team</button>
                </form>
            </div>
        </div>
        <?php
    }
    
    /**
     * Handle team creation
     */
    public static function create_team() {
        check_admin_referer('fs_create_team');
        
        if (!current_user_can('edit_posts')) {
            wp_die('Unauthorized');
        }
        
        $data = array(
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'type' => sanitize_text_field($_POST['type'] ?? ''),
            'team_leader_volunteer_id' => !empty($_POST['team_leader_volunteer_id']) ? (int)$_POST['team_leader_volunteer_id'] : null,
            'default_size' => (int)($_POST['default_size'] ?? 0),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'status' => sanitize_text_field($_POST['status'] ?? 'active')
        );

        $result = FS_Team_Manager::create_team($data);

        if (is_wp_error($result)) {
            wp_die($result->get_error_message());
        }

        // Log team creation
        FS_Audit_Log::log('team_created', 'team', $result, array(
            'team_name' => $data['name'],
            'team_type' => $data['type'],
            'default_size' => $data['default_size'],
            'status' => $data['status']
        ));

        wp_redirect(admin_url('admin.php?page=fs-teams&created=1'));
        exit;
    }
    
    /**
     * Handle team update
     */
    public static function update_team() {
        $team_id = (int)($_POST['team_id'] ?? 0);
        check_admin_referer('fs_update_team_' . $team_id);

        if (!current_user_can('edit_posts')) {
            wp_die('Unauthorized');
        }

        $data = array(
            'name' => sanitize_text_field($_POST['name'] ?? ''),
            'type' => sanitize_text_field($_POST['type'] ?? ''),
            'team_leader_volunteer_id' => !empty($_POST['team_leader_volunteer_id']) ? (int)$_POST['team_leader_volunteer_id'] : null,
            'default_size' => (int)($_POST['default_size'] ?? 0),
            'description' => sanitize_textarea_field($_POST['description'] ?? ''),
            'status' => sanitize_text_field($_POST['status'] ?? 'active')
        );

        FS_Team_Manager::update_team($team_id, $data);

        // Log team update
        FS_Audit_Log::log('team_updated', 'team', $team_id, array(
            'team_name' => $data['name'],
            'team_type' => $data['type'],
            'default_size' => $data['default_size'],
            'status' => $data['status']
        ));

        wp_redirect(admin_url('admin.php?page=fs-teams&action=edit&team_id=' . $team_id . '&updated=1'));
        exit;
    }
    
    /**
     * Handle team deletion
     */
    public static function delete_team() {
        $team_id = (int)($_POST['team_id'] ?? 0);
        check_admin_referer('fs_delete_team_' . $team_id);

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        // Get team details before deletion for audit log
        global $wpdb;
        $team = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_teams WHERE id = %d",
            $team_id
        ));

        $result = FS_Team_Manager::delete_team($team_id);

        if (is_wp_error($result)) {
            wp_die($result->get_error_message());
        }

        // Log team deletion
        if ($team) {
            FS_Audit_Log::log('team_deleted', 'team', $team_id, array(
                'team_name' => $team->name,
                'team_type' => $team->type,
                'default_size' => $team->default_size,
                'status' => $team->status
            ));
        }

        wp_redirect(admin_url('admin.php?page=fs-teams&deleted=1'));
        exit;
    }

    /**
     * Handle team PIN and QR code generation actions
     */
    public static function handle_team_pin_qr_actions() {
        if (!isset($_POST['action']) || !isset($_POST['team_id'])) {
            return;
        }

        // Only handle specific PIN/QR actions
        $action = sanitize_text_field($_POST['action']);
        if (!in_array($action, array('generate_team_pin', 'regenerate_team_pin', 'generate_team_qr', 'regenerate_team_qr'))) {
            return;
        }

        if (!check_admin_referer('fs_team_action')) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $team_id = intval($_POST['team_id'] ?? 0);

        // Get team details for audit log
        global $wpdb;
        $team = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}fs_teams WHERE id = %d",
            $team_id
        ));

        if (!$team) {
            wp_die('Team not found');
        }

        switch ($action) {
            case 'generate_team_pin':
            case 'regenerate_team_pin':
                $pin = FS_Team_Manager::generate_team_pin($team_id);

                if (!is_wp_error($pin)) {
                    // Log PIN generation
                    FS_Audit_Log::log('team_pin_generated', 'team', $team_id, array(
                        'team_name' => $team->name,
                        'action' => $action === 'regenerate_team_pin' ? 'regenerated' : 'generated'
                    ));

                    $redirect = add_query_arg(
                        array('page' => 'fs-teams', 'action' => 'edit', 'team_id' => $team_id, 'pin_generated' => '1'),
                        admin_url('admin.php')
                    );
                    wp_redirect($redirect);
                    exit;
                }
                break;

            case 'generate_team_qr':
            case 'regenerate_team_qr':
                $qr_code = FS_Team_Manager::generate_team_qr_code($team_id);

                if (!is_wp_error($qr_code)) {
                    // Log QR code generation
                    FS_Audit_Log::log('team_qr_generated', 'team', $team_id, array(
                        'team_name' => $team->name,
                        'action' => $action === 'regenerate_team_qr' ? 'regenerated' : 'generated'
                    ));

                    $redirect = add_query_arg(
                        array('page' => 'fs-teams', 'action' => 'edit', 'team_id' => $team_id, 'qr_generated' => '1'),
                        admin_url('admin.php')
                    );
                    wp_redirect($redirect);
                    exit;
                }
                break;
        }
    }
}

// Add member/remove member handlers
add_action('admin_post_fs_add_team_member', function() {
    $team_id = (int)($_POST['team_id'] ?? 0);
    check_admin_referer('fs_add_member_' . $team_id);

    if (!current_user_can('edit_posts')) {
        wp_die('Unauthorized');
    }

    $data = array(
        'volunteer_id' => !empty($_POST['volunteer_id']) ? (int)$_POST['volunteer_id'] : null,
        'name' => !empty($_POST['member_name']) ? sanitize_text_field($_POST['member_name']) : null,
        'role' => sanitize_text_field($_POST['role'] ?? ''),
        'notes' => sanitize_text_field($_POST['notes'] ?? '')
    );

    $member_id = FS_Team_Manager::add_member($team_id, $data);

    // Log team member addition
    global $wpdb;
    $team = $wpdb->get_row($wpdb->prepare(
        "SELECT name FROM {$wpdb->prefix}fs_teams WHERE id = %d",
        $team_id
    ));

    if ($team) {
        $member_name = $data['volunteer_id']
            ? $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}fs_volunteers WHERE id = %d", $data['volunteer_id']))
            : $data['name'];

        FS_Audit_Log::log('team_member_added', 'team', $team_id, array(
            'team_name' => $team->name,
            'member_name' => $member_name,
            'role' => $data['role']
        ));
    }

    wp_redirect(admin_url('admin.php?page=fs-teams&action=edit&team_id=' . $team_id . '&member_added=1#members'));
    exit;
});

add_action('admin_post_fs_remove_team_member', function() {
    $member_id = (int)($_POST['member_id'] ?? 0);
    $team_id = (int)($_POST['team_id'] ?? 0);
    check_admin_referer('fs_remove_member_' . $member_id);

    if (!current_user_can('edit_posts')) {
        wp_die('Unauthorized');
    }

    // Get member and team details before removal for audit log
    global $wpdb;
    $member = $wpdb->get_row($wpdb->prepare(
        "SELECT m.*, t.name as team_name
         FROM {$wpdb->prefix}fs_team_members m
         LEFT JOIN {$wpdb->prefix}fs_teams t ON m.team_id = t.id
         WHERE m.id = %d",
        $member_id
    ));

    FS_Team_Manager::remove_member($member_id);

    // Log team member removal
    if ($member) {
        $member_name = $member->volunteer_id
            ? $wpdb->get_var($wpdb->prepare("SELECT name FROM {$wpdb->prefix}fs_volunteers WHERE id = %d", $member->volunteer_id))
            : $member->name;

        FS_Audit_Log::log('team_member_removed', 'team', $team_id, array(
            'team_name' => $member->team_name,
            'member_name' => $member_name,
            'role' => $member->role
        ));
    }

    wp_redirect(admin_url('admin.php?page=fs-teams&action=edit&team_id=' . $team_id . '#members'));
    exit;
});
