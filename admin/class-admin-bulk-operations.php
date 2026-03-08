<?php
if (!defined('ABSPATH')) exit;

/**
 * Admin Bulk Operations
 * Bulk opportunity creation, volunteer imports, and batch email sending
 */
class FS_Admin_Bulk_Operations {

    public static function init() {
        add_action('admin_post_fs_bulk_create_opportunities', array(__CLASS__, 'handle_bulk_create_opportunities'));
        add_action('admin_post_fs_import_volunteers', array(__CLASS__, 'handle_import_volunteers'));
        add_action('admin_post_fs_batch_email', array(__CLASS__, 'handle_batch_email'));
        add_action('admin_post_fs_bulk_assign_roles', array(__CLASS__, 'handle_bulk_assign_roles'));
    }

    public static function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('You do not have permission to access this page.');
        }

        // Handle success/error messages
        if (isset($_GET['created'])) {
            echo '<div class="notice notice-success"><p>Successfully created ' . intval($_GET['created']) . ' opportunities!</p></div>';
        }
        if (isset($_GET['imported'])) {
            echo '<div class="notice notice-success"><p>Successfully imported ' . intval($_GET['imported']) . ' volunteers!</p></div>';
        }
        if (isset($_GET['emails_sent'])) {
            echo '<div class="notice notice-success"><p>Successfully sent ' . intval($_GET['emails_sent']) . ' emails!</p></div>';
        }
        if (isset($_GET['roles_assigned'])) {
            echo '<div class="notice notice-success"><p>Successfully assigned roles to ' . intval($_GET['roles_assigned']) . ' volunteers!</p></div>';
        }
        if (isset($_GET['error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html($_GET['error']) . '</p></div>';
        }

        ?>
        <div class="wrap">
            <h1>Bulk Operations</h1>
            <p>Perform batch operations to save time managing volunteers and opportunities.</p>

            <!-- Bulk Opportunity Creation -->
            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h2>Bulk Create Opportunities</h2>
                <p>Create multiple opportunities at once by specifying a date range and recurrence pattern.</p>

                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
                    <?php wp_nonce_field('fs_bulk_create_opportunities', '_wpnonce_bulk_create'); ?>
                    <input type="hidden" name="action" value="fs_bulk_create_opportunities">

                    <table class="form-table">
                        <tr>
                            <th><label for="bulk_title">Title Template</label></th>
                            <td>
                                <input type="text" name="bulk_title" id="bulk_title" class="regular-text" required
                                       placeholder="e.g., Weekly Food Bank Shift">
                                <p class="description">Use {date} placeholder for dynamic dates (e.g., "Shift - {date}")</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="bulk_description">Description</label></th>
                            <td>
                                <textarea name="bulk_description" id="bulk_description" rows="3" class="large-text"></textarea>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="bulk_location">Location</label></th>
                            <td>
                                <input type="text" name="bulk_location" id="bulk_location" class="regular-text">
                            </td>
                        </tr>
                        <tr>
                            <th><label for="bulk_program">Program</label></th>
                            <td>
                                <select name="bulk_program" id="bulk_program">
                                    <option value="">None</option>
                                    <?php
                                    global $wpdb;
                                    $programs = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}fs_programs ORDER BY name ASC");
                                    foreach ($programs as $program) {
                                        echo '<option value="' . esc_attr($program->name) . '">' . esc_html($program->name) . '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="bulk_spots">Spots Available</label></th>
                            <td>
                                <input type="number" name="bulk_spots" id="bulk_spots" value="10" min="1" max="1000" required>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="bulk_start_date">Start Date</label></th>
                            <td>
                                <input type="date" name="bulk_start_date" id="bulk_start_date" required>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="bulk_end_date">End Date</label></th>
                            <td>
                                <input type="date" name="bulk_end_date" id="bulk_end_date" required>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="bulk_frequency">Frequency</label></th>
                            <td>
                                <select name="bulk_frequency" id="bulk_frequency" required>
                                    <option value="daily">Daily</option>
                                    <option value="weekly">Weekly</option>
                                    <option value="biweekly">Bi-weekly</option>
                                    <option value="monthly">Monthly</option>
                                </select>
                            </td>
                        </tr>
                        <tr id="bulk_days_row" style="display: none;">
                            <th><label>Days of Week</label></th>
                            <td>
                                <label><input type="checkbox" name="bulk_days[]" value="1"> Monday</label><br>
                                <label><input type="checkbox" name="bulk_days[]" value="2"> Tuesday</label><br>
                                <label><input type="checkbox" name="bulk_days[]" value="3"> Wednesday</label><br>
                                <label><input type="checkbox" name="bulk_days[]" value="4"> Thursday</label><br>
                                <label><input type="checkbox" name="bulk_days[]" value="5"> Friday</label><br>
                                <label><input type="checkbox" name="bulk_days[]" value="6"> Saturday</label><br>
                                <label><input type="checkbox" name="bulk_days[]" value="0"> Sunday</label>
                                <p class="description">Select which days to create opportunities</p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary">Create Opportunities</button>
                    </p>
                </form>

                <script>
                document.getElementById('bulk_frequency').addEventListener('change', function() {
                    const daysRow = document.getElementById('bulk_days_row');
                    if (this.value === 'weekly' || this.value === 'biweekly') {
                        daysRow.style.display = 'table-row';
                    } else {
                        daysRow.style.display = 'none';
                    }
                });
                </script>
            </div>

            <!-- Mass Volunteer Import -->
            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h2>Import Volunteers (CSV)</h2>
                <p>Upload a CSV file to import multiple volunteers at once.</p>

                <div style="background: #f0f6fc; padding: 15px; border-left: 4px solid #0073aa; margin-bottom: 20px;">
                    <strong>CSV Format:</strong> Your CSV should have these columns (in any order):<br>
                    <code>name, email, phone, birthdate, volunteer_status, types, notes</code><br><br>
                    <strong>Example:</strong><br>
                    <code>name,email,phone,volunteer_status<br>John Doe,john@example.com,555-1234,Active</code>
                </div>

                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" enctype="multipart/form-data">
                    <?php wp_nonce_field('fs_import_volunteers', '_wpnonce_import'); ?>
                    <input type="hidden" name="action" value="fs_import_volunteers">

                    <p>
                        <input type="file" name="volunteer_csv" accept=".csv" required>
                    </p>

                    <p>
                        <label>
                            <input type="checkbox" name="skip_duplicates" value="1" checked>
                            Skip volunteers with duplicate email addresses
                        </label>
                    </p>

                    <p>
                        <label>
                            <input type="checkbox" name="send_welcome_emails" value="1">
                            Send welcome emails to imported volunteers
                        </label>
                    </p>

                    <p class="submit">
                        <button type="submit" class="button button-primary">Import Volunteers</button>
                    </p>
                </form>
            </div>

            <!-- Batch Email Sending -->
            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h2>Send Batch Email</h2>
                <p>Send an email to multiple volunteers based on criteria.</p>

                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" onsubmit="return confirm('Send email to selected volunteers?');">
                    <?php wp_nonce_field('fs_batch_email', '_wpnonce_batch'); ?>
                    <input type="hidden" name="action" value="fs_batch_email">

                    <table class="form-table">
                        <tr>
                            <th><label>Recipients</label></th>
                            <td>
                                <label><input type="radio" name="recipient_type" value="all" checked> All Active Volunteers</label><br>
                                <label><input type="radio" name="recipient_type" value="program"> By Program:</label>
                                <select name="recipient_program">
                                    <option value="">Select Program</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?php echo esc_attr($program->name); ?>"><?php echo esc_html($program->name); ?></option>
                                    <?php endforeach; ?>
                                </select><br>
                                <label><input type="radio" name="recipient_type" value="role"> By Role:</label>
                                <select name="recipient_role">
                                    <option value="">Select Role</option>
                                    <?php
                                    $roles = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}fs_roles ORDER BY name ASC");
                                    foreach ($roles as $role):
                                    ?>
                                        <option value="<?php echo $role->id; ?>"><?php echo esc_html($role->name); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="batch_subject">Subject</label></th>
                            <td>
                                <input type="text" name="batch_subject" id="batch_subject" class="large-text" required>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="batch_message">Message</label></th>
                            <td>
                                <textarea name="batch_message" id="batch_message" rows="10" class="large-text" required></textarea>
                                <p class="description">Available placeholders: {volunteer_name}, {portal_link}</p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary">Send Batch Email</button>
                    </p>
                </form>
            </div>

            <!-- Bulk Role Assignment -->
            <div style="background: white; padding: 20px; margin: 20px 0; border: 1px solid #ddd; border-radius: 4px;">
                <h2>Bulk Assign Roles</h2>
                <p>Assign one or more roles to multiple volunteers at once.</p>

                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" onsubmit="return confirm('Assign roles to selected volunteers?');">
                    <?php wp_nonce_field('fs_bulk_assign_roles', '_wpnonce_bulk_roles'); ?>
                    <input type="hidden" name="action" value="fs_bulk_assign_roles">

                    <table class="form-table">
                        <tr>
                            <th><label>Select Volunteers</label></th>
                            <td>
                                <label><input type="radio" name="selection_type" value="all" checked> All Active Volunteers</label><br>
                                <label><input type="radio" name="selection_type" value="program"> Volunteers in Program:</label>
                                <select name="selection_program">
                                    <option value="">Select Program</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?php echo esc_attr($program->name); ?>"><?php echo esc_html($program->name); ?></option>
                                    <?php endforeach; ?>
                                </select><br>
                                <label><input type="radio" name="selection_type" value="status"> By Status:</label>
                                <select name="selection_status">
                                    <option value="">Select Status</option>
                                    <option value="Active">Active</option>
                                    <option value="Inactive">Inactive</option>
                                    <option value="Pending">Pending</option>
                                </select><br>
                                <label><input type="radio" name="selection_type" value="specific"> Specific Volunteers:</label>
                                <div style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px; margin-top: 5px;">
                                    <?php
                                    $all_volunteers = $wpdb->get_results("SELECT id, name, email FROM {$wpdb->prefix}fs_volunteers ORDER BY name ASC");
                                    foreach ($all_volunteers as $vol):
                                    ?>
                                        <label style="display: block;">
                                            <input type="checkbox" name="specific_volunteers[]" value="<?php echo $vol->id; ?>">
                                            <?php echo esc_html($vol->name); ?> (<?php echo esc_html($vol->email); ?>)
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Roles to Assign *</label></th>
                            <td>
                                <?php foreach ($roles as $role): ?>
                                    <label style="display: block;">
                                        <input type="checkbox" name="assign_roles[]" value="<?php echo $role->id; ?>">
                                        <?php echo esc_html($role->name); ?>
                                    </label>
                                <?php endforeach; ?>
                                <p class="description">Select one or more roles to assign to the selected volunteers.</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Action Type</label></th>
                            <td>
                                <label><input type="radio" name="action_type" value="add" checked> Add roles (keep existing roles)</label><br>
                                <label><input type="radio" name="action_type" value="replace"> Replace all roles (remove existing, add new)</label>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary">Assign Roles</button>
                    </p>
                </form>
            </div>
        </div>
        <?php
    }

    /**
     * Handle bulk opportunity creation
     */
    public static function handle_bulk_create_opportunities() {
        check_admin_referer('fs_bulk_create_opportunities', '_wpnonce_bulk_create');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $title_template = sanitize_text_field($_POST['bulk_title']);
        $description = sanitize_textarea_field($_POST['bulk_description']);
        $location = sanitize_text_field($_POST['bulk_location']);
        $program = sanitize_text_field($_POST['bulk_program']);
        $spots = intval($_POST['bulk_spots']);
        $start_date = sanitize_text_field($_POST['bulk_start_date']);
        $end_date = sanitize_text_field($_POST['bulk_end_date']);
        $frequency = sanitize_text_field($_POST['bulk_frequency']);
        $days = isset($_POST['bulk_days']) ? array_map('intval', $_POST['bulk_days']) : array();

        global $wpdb;
        $created = 0;

        $current_date = new DateTime($start_date);
        $end = new DateTime($end_date);

        while ($current_date <= $end) {
            $should_create = false;

            switch ($frequency) {
                case 'daily':
                    $should_create = true;
                    $interval = new DateInterval('P1D');
                    break;

                case 'weekly':
                    $should_create = empty($days) || in_array($current_date->format('w'), $days);
                    $interval = new DateInterval('P1D');
                    break;

                case 'biweekly':
                    $weeks_diff = floor($current_date->diff(new DateTime($start_date))->days / 7);
                    $should_create = ($weeks_diff % 2 == 0) && (empty($days) || in_array($current_date->format('w'), $days));
                    $interval = new DateInterval('P1D');
                    break;

                case 'monthly':
                    $should_create = $current_date->format('j') == (new DateTime($start_date))->format('j');
                    $interval = new DateInterval('P1D');
                    break;
            }

            if ($should_create) {
                $title = str_replace('{date}', $current_date->format('M j, Y'), $title_template);

                $wpdb->insert(
                    "{$wpdb->prefix}fs_opportunities",
                    array(
                        'title' => $title,
                        'description' => $description,
                        'location' => $location,
                        'conference' => $program,
                        'event_date' => $current_date->format('Y-m-d'),
                        'spots_available' => $spots,
                        'spots_filled' => 0,
                        'status' => 'active'
                    ),
                    array('%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s')
                );

                if ($wpdb->insert_id) {
                    $created++;

                    // Log the action
                    FS_Audit_Log::log('bulk_create_opportunity', 'opportunity', $wpdb->insert_id, array(
                        'title' => $title,
                        'date' => $current_date->format('Y-m-d')
                    ));
                }
            }

            $current_date->add($interval);
        }

        wp_redirect(add_query_arg('created', $created, admin_url('admin.php?page=fs-bulk-operations')));
        exit;
    }

    /**
     * Handle volunteer import from CSV
     */
    public static function handle_import_volunteers() {
        check_admin_referer('fs_import_volunteers', '_wpnonce_import');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        if (!isset($_FILES['volunteer_csv']) || $_FILES['volunteer_csv']['error'] !== UPLOAD_ERR_OK) {
            wp_redirect(add_query_arg('error', urlencode('File upload failed'), admin_url('admin.php?page=fs-bulk-operations')));
            exit;
        }

        $skip_duplicates = isset($_POST['skip_duplicates']);
        $send_welcome = isset($_POST['send_welcome_emails']);

        $file = fopen($_FILES['volunteer_csv']['tmp_name'], 'r');
        $header = fgetcsv($file);

        if (!$header) {
            wp_redirect(add_query_arg('error', urlencode('Invalid CSV format'), admin_url('admin.php?page=fs-bulk-operations')));
            exit;
        }

        // Normalize header (trim and lowercase)
        $header = array_map(function($col) {
            return strtolower(trim($col));
        }, $header);

        global $wpdb;
        $imported = 0;

        while (($row = fgetcsv($file)) !== false) {
            if (count($row) !== count($header)) {
                continue; // Skip malformed rows
            }

            $data = array_combine($header, $row);

            // Required: name and email
            if (empty($data['name']) || empty($data['email'])) {
                continue;
            }

            $email = sanitize_email($data['email']);

            // Check for duplicates
            if ($skip_duplicates) {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}fs_volunteers WHERE email = %s",
                    $email
                ));
                if ($exists) {
                    continue;
                }
            }

            // Generate access token
            $access_token = bin2hex(random_bytes(32));

            // Insert volunteer
            $insert_data = array(
                'name' => sanitize_text_field($data['name']),
                'email' => $email,
                'access_token' => $access_token,
                'phone' => isset($data['phone']) ? sanitize_text_field($data['phone']) : '',
                'birthdate' => isset($data['birthdate']) ? sanitize_text_field($data['birthdate']) : null,
                'volunteer_status' => isset($data['volunteer_status']) ? sanitize_text_field($data['volunteer_status']) : 'Active',
                'types' => isset($data['types']) ? sanitize_text_field($data['types']) : '',
                'notes' => isset($data['notes']) ? sanitize_textarea_field($data['notes']) : '',
                'created_date' => date('Y-m-d')
            );

            $wpdb->insert("{$wpdb->prefix}fs_volunteers", $insert_data);

            if ($wpdb->insert_id) {
                $imported++;

                // Log the import
                FS_Audit_Log::log('import_volunteer', 'volunteer', $wpdb->insert_id, array(
                    'name' => $insert_data['name'],
                    'email' => $insert_data['email']
                ));

                // Send welcome email if requested
                if ($send_welcome) {
                    $portal_url = add_query_arg('token', $access_token, home_url('/volunteer-portal'));
                    FS_Notifications::send_welcome_email($wpdb->insert_id, $portal_url);
                }
            }
        }

        fclose($file);

        wp_redirect(add_query_arg('imported', $imported, admin_url('admin.php?page=fs-bulk-operations')));
        exit;
    }

    /**
     * Handle batch email sending
     */
    public static function handle_batch_email() {
        check_admin_referer('fs_batch_email', '_wpnonce_batch');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        $recipient_type = sanitize_text_field($_POST['recipient_type']);
        $subject = sanitize_text_field($_POST['batch_subject']);
        $message = wp_kses_post($_POST['batch_message']);

        global $wpdb;

        // Build query based on recipient type
        $query = "SELECT id, name, email, access_token FROM {$wpdb->prefix}fs_volunteers WHERE 1=1";

        if ($recipient_type === 'program') {
            $program = sanitize_text_field($_POST['recipient_program']);
            if (!empty($program)) {
                $query .= $wpdb->prepare(" AND id IN (
                    SELECT DISTINCT v.id FROM {$wpdb->prefix}fs_volunteers v
                    JOIN {$wpdb->prefix}fs_signups s ON v.id = s.volunteer_id
                    JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
                    WHERE o.conference = %s
                )", $program);
            }
        } elseif ($recipient_type === 'role') {
            $role_id = intval($_POST['recipient_role']);
            if ($role_id > 0) {
                $query .= $wpdb->prepare(" AND id IN (
                    SELECT volunteer_id FROM {$wpdb->prefix}fs_volunteer_roles
                    WHERE role_id = %d
                )", $role_id);
            }
        }

        $query .= " AND volunteer_status = 'Active'";

        $volunteers = $wpdb->get_results($query);

        $sent = 0;
        foreach ($volunteers as $volunteer) {
            $portal_link = add_query_arg('token', $volunteer->access_token, home_url('/volunteer-portal'));

            $personalized_message = str_replace(
                array('{volunteer_name}', '{portal_link}'),
                array($volunteer->name, $portal_link),
                $message
            );

            $email_body = "
            <html>
            <body style='font-family: Arial, sans-serif; line-height: 1.6;'>
                " . wpautop($personalized_message) . "
                <p style='margin-top: 30px;'>
                    <a href='{$portal_link}' style='display: inline-block; background: #0073aa; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px;'>Access Portal</a>
                </p>
            </body>
            </html>
            ";

            $headers = array('Content-Type: text/html; charset=UTF-8');

            if (wp_mail($volunteer->email, $subject, $email_body, $headers)) {
                $sent++;
            }
        }

        // Log batch email
        FS_Audit_Log::log('batch_email', 'system', 0, array(
            'recipient_type' => $recipient_type,
            'count' => $sent,
            'subject' => $subject
        ));

        wp_redirect(add_query_arg('emails_sent', $sent, admin_url('admin.php?page=fs-bulk-operations')));
        exit;
    }

    /**
     * Handle bulk role assignment
     */
    public static function handle_bulk_assign_roles() {
        check_admin_referer('fs_bulk_assign_roles', '_wpnonce_bulk_roles');

        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        global $wpdb;

        $selection_type = isset($_POST['selection_type']) ? sanitize_text_field($_POST['selection_type']) : 'all';
        $assign_roles = isset($_POST['assign_roles']) ? array_map('intval', $_POST['assign_roles']) : array();
        $action_type = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : 'add';

        if (empty($assign_roles)) {
            wp_redirect(add_query_arg('error', urlencode('Please select at least one role to assign'), admin_url('admin.php?page=fs-bulk-operations')));
            exit;
        }

        // Get volunteers based on selection type
        $volunteers = array();

        switch ($selection_type) {
            case 'all':
                $volunteers = $wpdb->get_results(
                    "SELECT id FROM {$wpdb->prefix}fs_volunteers WHERE volunteer_status = 'Active'"
                );
                break;

            case 'program':
                $program = isset($_POST['selection_program']) ? sanitize_text_field($_POST['selection_program']) : '';
                if (empty($program)) {
                    wp_redirect(add_query_arg('error', urlencode('Please select a program'), admin_url('admin.php?page=fs-bulk-operations')));
                    exit;
                }

                $volunteers = $wpdb->get_results($wpdb->prepare(
                    "SELECT v.id
                     FROM {$wpdb->prefix}fs_volunteers v
                     JOIN {$wpdb->prefix}fs_signups s ON v.id = s.volunteer_id
                     JOIN {$wpdb->prefix}fs_opportunities o ON s.opportunity_id = o.id
                     WHERE o.program = %s
                     GROUP BY v.id",
                    $program
                ));
                break;

            case 'status':
                $status = isset($_POST['selection_status']) ? sanitize_text_field($_POST['selection_status']) : '';
                if (empty($status)) {
                    wp_redirect(add_query_arg('error', urlencode('Please select a status'), admin_url('admin.php?page=fs-bulk-operations')));
                    exit;
                }

                $volunteers = $wpdb->get_results($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}fs_volunteers WHERE volunteer_status = %s",
                    $status
                ));
                break;

            case 'specific':
                $specific_ids = isset($_POST['specific_volunteers']) ? array_map('intval', $_POST['specific_volunteers']) : array();
                if (empty($specific_ids)) {
                    wp_redirect(add_query_arg('error', urlencode('Please select at least one volunteer'), admin_url('admin.php?page=fs-bulk-operations')));
                    exit;
                }

                $placeholders = implode(',', array_fill(0, count($specific_ids), '%d'));
                $volunteers = $wpdb->get_results($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}fs_volunteers WHERE id IN ($placeholders)",
                    ...$specific_ids
                ));
                break;
        }

        if (empty($volunteers)) {
            wp_redirect(add_query_arg('error', urlencode('No volunteers found matching the selection criteria'), admin_url('admin.php?page=fs-bulk-operations')));
            exit;
        }

        $assigned_count = 0;

        foreach ($volunteers as $volunteer) {
            // If replace mode, remove existing roles first
            if ($action_type === 'replace') {
                $wpdb->delete(
                    "{$wpdb->prefix}fs_volunteer_roles",
                    array('volunteer_id' => $volunteer->id)
                );
            }

            // Add new roles
            foreach ($assign_roles as $role_id) {
                // Check if role already assigned (to avoid duplicates)
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$wpdb->prefix}fs_volunteer_roles
                     WHERE volunteer_id = %d AND role_id = %d",
                    $volunteer->id,
                    $role_id
                ));

                if (!$exists) {
                    $wpdb->insert(
                        "{$wpdb->prefix}fs_volunteer_roles",
                        array(
                            'volunteer_id' => $volunteer->id,
                            'role_id' => $role_id
                        )
                    );
                }
            }

            $assigned_count++;
        }

        // Log bulk role assignment
        FS_Audit_Log::log('bulk_role_assignment', 'system', 0, array(
            'selection_type' => $selection_type,
            'roles' => $assign_roles,
            'action_type' => $action_type,
            'count' => $assigned_count
        ));

        wp_redirect(add_query_arg('roles_assigned', $assigned_count, admin_url('admin.php?page=fs-bulk-operations')));
        exit;
    }
}
