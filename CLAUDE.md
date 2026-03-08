# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

FriendShyft is a comprehensive WordPress plugin for volunteer management at nonprofits. It handles volunteer registration, opportunity signup, time tracking, badges/achievements, team management, and email-based volunteer ingestion.

**Environment:** WordPress plugin running in Local by Flywheel environment
**Working Directory:** `/Users/jeremiahotis/Local Sites/friendshyft/app/public/wp-content/plugins/friendshyft`
**Version:** 1.1.0

## Key Commands

### WordPress Development
```bash
# This is a WordPress plugin - no build process required
# PHP files are executed directly by WordPress

# Access the WordPress admin
# http://friendshyft.local/wp-admin

# Access the plugin dashboard
# WordPress Admin → FriendShyft → Dashboard
```

### Database Operations
```bash
# Database migrations run automatically on activation
# Manual migration trigger: WordPress Admin → FriendShyft → Run Migration

# Check database tables exist
# WordPress Admin → Tools → Site Health → Info → Database
# Look for tables prefixed with wp_fs_*
```

### Git Operations
```bash
# Current branch: main
git status
git add .
git commit -m "Description"
git push origin main
```

## Core Architecture

### Entry Point
- **friendshyft.php** - Main plugin file, handles activation, deactivation, and class loading

### Database Layer
- **includes/class-database.php** - Core database schema (volunteers, roles, programs, opportunities, signups)
- **includes/class-database-migrations.php** - Schema version management
- **fs-team-management-migration.php** - Team feature migration
- **fs-email-ingestion-migration.php** - Email processing migration

Core tables:
- `wp_fs_volunteers` - Volunteer records with access tokens, PINs
- `wp_fs_opportunities` - Volunteer opportunities/shifts
- `wp_fs_signups` - Volunteer → Opportunity signups
- `wp_fs_roles` - Volunteer roles/types
- `wp_fs_programs` - Program categorization
- `wp_fs_workflows` - Multi-step training/onboarding
- `wp_fs_teams` - Team-based volunteering
- `wp_fs_email_log` - Email ingestion audit trail

### Core Classes (includes/)

**Volunteer Management:**
- `class-admin-volunteers.php` - Admin CRUD for volunteers
- `class-eligibility-checker.php` - Role/program eligibility logic
- `class-poc-role.php` - Point of Contact role management

**Opportunity & Signup:**
- `class-signup.php` - Core signup logic and conflict detection
- `class-opportunity-templates.php` - Recurring opportunity generation
- `class-calendar-export.php` - iCal export for volunteers

**Time Tracking:**
- `class-time-tracking.php` - Individual volunteer time tracking
- `class-team-time-tracking.php` - Team-based time tracking

**Notifications & Communication:**
- `class-notifications.php` - Email notification system
- `class-attendance-confirmation.php` - Attendance reminders
- `class-reminder-schedule.php` - Cron-based reminder scheduling
- `class-fs-handoff-notifications.php` - Recurring shift handoff notifications

**Achievements:**
- `class-badges.php` - Badge/achievement system

**Team Management:**
- `class-team-manager.php` - Team CRUD operations
- `class-team-signup.php` - Team-based opportunity signup with conflict resolution
- `class-team-portal.php` - Team leader portal interface
- `class-team-kiosk.php` - Team check-in kiosk

**Email Ingestion:**
- `class-email-parser.php` - Parse volunteer hub emails
- `class-email-processor.php` - Process parsed emails into volunteers
- `class-email-ingestion.php` - IMAP polling & API endpoint

**External Integrations:**
- `class-monday-api.php` - Monday.com API integration
- `class-sync-engine.php` - Bidirectional sync with Monday.com

### Admin UI (admin/)

- `class-admin-menu.php` - WordPress admin menu structure
- `class-admin-dashboard.php` - Main dashboard with stats
- `class-admin-poc-dashboard.php` - Point of Contact opportunity view
- `class-admin-programs.php` - Program management
- `class-admin-roles.php` - Role management
- `class-admin-opportunities.php` - Opportunity CRUD
- `class-admin-signups.php` - Signup management with status changes
- `class-admin-teams.php` - Team management UI
- `class-admin-templates.php` - Opportunity template management
- `class-admin-workflows.php` - Workflow/training management
- `class-admin-holidays.php` - Holiday date management
- `class-admin-email-settings.php` - Email ingestion API settings
- `class-admin-process-email.php` - Manual email processing UI
- `class-admin-email-log.php` - Email processing audit log
- `admin-achievements-dashboard.php` - Badge/achievement admin

### Public UI (public/)

- `class-volunteer-portal.php` - Main volunteer portal (shortcode: `[volunteer_portal]`)
- `class-volunteer-profile.php` - Volunteer profile management
- `class-volunteer-registration.php` - Public volunteer registration forms
- `class-kiosk.php` - Check-in/check-out kiosk for time tracking
- `class-public-opportunities.php` - Public opportunity display
- `class-public-programs.php` - Public program showcase

### Authentication Architecture

FriendShyft uses a **dual authentication system**:

1. **WordPress Users (Admin/POC)** - Standard WordPress authentication via `is_user_logged_in()`
2. **Token-based (Volunteers)** - Magic link authentication via `access_token` field
   - Volunteers don't need WordPress accounts
   - Access via: `/volunteer-portal/?token={access_token}`
   - Tokens are 64-character random strings stored in `wp_fs_volunteers.access_token`
   - All AJAX handlers support both `wp_ajax_*` (logged in) and `wp_ajax_nopriv_*` (token)

**Security Note:** Always verify token authentication in AJAX handlers:
```php
// Check for token-based auth first, then fall back to logged-in user
$volunteer = self::check_token_auth();
if (!$volunteer && is_user_logged_in()) {
    $volunteer = self::get_current_volunteer();
}
```

### Cron Jobs

All cron jobs are scheduled in `friendshyft_activate()` and cleared in `friendshyft_deactivate()`:

- `fs_send_attendance_reminders` - Daily at 9 AM, sends pre-shift reminders
- `fs_generate_opportunities_cron` - Weekly, generates opportunities from templates
- `fs_check_imap_inbox` - Hourly, polls IMAP for new volunteer interest emails
- `fs_sync_cron` - Daily, syncs with Monday.com (if configured)
- `fs_daily_handoff_check` - Daily, notifies about recurring shift handoffs

**Important:** Cron-dependent classes must be initialized in `friendshyft_init()`:
```php
FS_Sync_Engine::init();
FS_Opportunity_Templates::init();
FS_Handoff_Notifications::init();
FS_Email_Ingestion::init();
```

### Key Design Patterns

**1. Static Class Pattern:**
All classes use static methods with `::init()` patterns:
```php
class FS_Example {
    public static function init() {
        add_action('hook_name', array(__CLASS__, 'method_name'));
    }
}
// Called in friendshyft.php:
FS_Example::init();
```

**2. AJAX Handler Registration:**
Support both logged-in and non-logged-in (token) access:
```php
add_action('wp_ajax_fs_action_name', array(__CLASS__, 'handler'));
add_action('wp_ajax_nopriv_fs_action_name', array(__CLASS__, 'handler'));
```

**3. Database Access:**
Direct `$wpdb` queries (no ORM). Always sanitize with `$wpdb->prepare()`:
```php
global $wpdb;
$result = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE id = %d",
    $volunteer_id
));
```

**4. Nonce Verification:**
Admin actions use nonces, token-based actions skip nonces:
```php
// Admin context
check_ajax_referer('friendshyft_admin_nonce', 'nonce');

// Token context - verify token instead
$volunteer = $wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->prefix}fs_volunteers WHERE access_token = %s",
    $token
));
```

### ⚠️ CRITICAL: Nonce Patterns for ALL CRUD Operations

**EVERY TIME you touch ANY CRUD functionality (Create, Read, Update, Delete), nonce action strings MUST match between generation and verification.**

**Pattern to use (EVERY TIME):**

#### Delete Operations
```php
// 1. In the list/render method (generating the delete link):
<a href="<?php echo wp_nonce_url(
    admin_url('admin.php?page=fs-{entity_plural}&action=delete&id=' . ${entity}->id),
    'fs_delete_{entity}_' . ${entity}->id
); ?>" onclick="return confirm('Delete this {entity}?');">Delete</a>

// 2. In the handle_delete() method (verifying the nonce):
if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'fs_delete_{entity}_' . $_GET['id'])) {
    wp_die('Security check failed');
}
```

#### Create/Update Forms (Standard Pattern)
```php
// 1. In the form (generating nonce field):
<?php wp_nonce_field('fs_{entity}_form'); ?>

// 2. In the handle_form_submission() method (verifying):
if (!check_admin_referer('fs_{entity}_form')) {
    wp_die('Security check failed');
}
```

#### POST Forms with Custom Nonce Field Names
**CRITICAL: When using custom nonce field names (e.g., `_wpnonce_waitlist`, `_wpnonce_team`), you MUST:**

```php
// 1. In the form (generating nonce with custom field name):
<form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
    <input type="hidden" name="_wpnonce_custom" value="<?php echo wp_create_nonce('fs_action_name'); ?>">
    <input type="hidden" name="action" value="fs_action_name">
    <!-- form fields -->
</form>

// 2. In the handler method (verifying with BOTH parameters):
public static function handle_action() {
    // ✅ CORRECT: Specify both action AND nonce field name
    check_admin_referer('fs_action_name', '_wpnonce_custom');

    // ❌ WRONG: Omitting second parameter
    check_admin_referer('fs_action_name');  // This looks for '_wpnonce' by default!

    // Rest of handler...
}
```

**Why this matters:**
- `check_admin_referer($action)` looks for a field named `_wpnonce` by default
- `check_admin_referer($action, $query_arg)` looks for the specified field name
- If your field is named `_wpnonce_waitlist`, you MUST pass that as the second parameter
- Otherwise WordPress looks for the wrong field name and the nonce check always fails

**Examples from codebase:**
- **Delete:** `'fs_delete_program_' . $program->id`, `'fs_delete_role_' . $role->id`, `'fs_delete_holiday_' . $holiday->id`
- **Standard forms:** `'fs_program_form'`, `'fs_role_form'`, `'fs_opportunity_form'`
- **Custom field names:** `check_admin_referer('fs_add_to_waitlist', '_wpnonce_waitlist')`, `check_admin_referer('fs_manual_signup', '_wpnonce_team')`

**Common mistakes to AVOID:**
- ❌ Using different action strings in generation vs verification (`'fs_delete_program_'` vs `'delete_program_'`)
- ❌ Missing the `fs_` prefix entirely
- ❌ Using generic nonce without entity name or ID (`'delete'` instead of `'fs_delete_program_' . $id`)
- ❌ Copy-pasting nonce verification from another file without updating the action string
- ❌ **Using custom nonce field names but not passing the field name to `check_admin_referer()`** (e.g., field is `_wpnonce_waitlist` but calling `check_admin_referer('action')` instead of `check_admin_referer('action', '_wpnonce_waitlist')`)

**Why this matters:**
WordPress verifies nonces by comparing the action string character-by-character. Even a tiny mismatch causes "Security check failed". This applies to:
- **DELETE operations** (via URL links with `wp_nonce_url()`)
- **CREATE operations** (via form submission with `wp_nonce_field()`)
- **UPDATE operations** (via form submission with `wp_nonce_field()`)
- **AJAX operations** (via `check_ajax_referer()`)

**Before committing ANY CRUD code:**
1. Find where the nonce is generated (link, form field, or AJAX call)
2. Find where the nonce is verified (handler method)
3. Confirm the action strings **match exactly**
4. Test the operation to ensure "Security check failed" doesn't appear

**5. Eligibility & Conflict Detection:**
Before signup, check:
- Role eligibility: `FS_Eligibility_Checker::check_eligibility()`
- Time conflicts: `FS_Signup::check_conflict()`
- Team conflicts: `FS_Team_Signup::handle_individual_to_team_conflict()`

## Critical Implementation Notes

### Signup Conflict Resolution (class-team-signup.php)

When an individual volunteer has an existing signup and their team also signs up for the same shift:
1. Individual signup is flagged as `needs_merge`
2. Notification is sent to volunteer explaining the merge
3. Volunteer's hours are tracked as part of team attendance
4. Individual signup remains in database for audit purposes

Implementation in `handle_individual_to_team_conflict()`:
```php
$wpdb->update(
    "{$wpdb->prefix}fs_signups",
    ['status' => 'needs_merge'],
    ['id' => $existing_signup->id]
);
FS_Notifications::send_team_merge_notification($volunteer_id, $opportunity_id, $team_name);
```

### Time Tracking Modes

**Individual Tracking:**
- Volunteer checks in via kiosk using PIN or QR code
- Records individual check-in/check-out times
- Calculates hours per person

**Team Tracking:**
- Team leader checks in entire team using their PIN
- Adjustable `people_count` at check-in time
- Stores `hours_per_person` and `total_hours = people_count * hours_per_person`

Both modes write to separate tables:
- Individual: `wp_fs_time_records`
- Team: `wp_fs_team_attendance`

### Email Ingestion Flow

1. Email arrives at monitored inbox OR posted to API endpoint
2. `FS_Email_Parser::parse_volunteer_email()` extracts structured data
3. `FS_Email_Processor::process_email()` creates volunteer + interests
4. Duplicate check by email address
5. If new: Send welcome email with magic link token
6. Log everything to `wp_fs_email_log` for audit

### Badge/Achievement System

Badges are awarded automatically based on:
- Hours volunteered (milestone badges: 10hr, 50hr, 100hr, etc.)
- Number of signups (frequency badges)
- Completing workflows (training completion)

Check badge eligibility after:
- Time tracking check-out
- Signup completion
- Workflow step completion

Awards via: `FS_Badges::check_and_award_badges($volunteer_id)`

### Monday.com Sync

**Bidirectional sync** between FriendShyft and Monday.com:
- Local changes push to Monday via `FS_Sync_Engine::push_to_monday()`
- Monday changes pull locally via `FS_Monday_API::fetch_volunteers()`
- Conflict resolution: Monday.com is source of truth (last write wins)

Fields synced:
- Volunteers (contacts)
- Roles (board items)
- Volunteer-Role assignments (connections)

**Important:** Only sync if `FS_Monday_API::is_configured()` returns true.

## Debugging & Development

### Debug Logging

All `error_log()` statements are wrapped in `WP_DEBUG` checks:
```php
if (defined('WP_DEBUG') && WP_DEBUG) {
    error_log('FriendShyft: Debug message');
}
```

To enable debug logs:
```php
// wp-config.php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Logs appear in: `/Users/jeremiahotis/Local Sites/friendshyft/app/public/wp-content/debug.log`

### Common Development Workflow

1. **Add a new feature:**
   - Create class in appropriate directory (includes/, admin/, public/)
   - Use static class pattern with `::init()` method
   - Require file in `friendshyft.php` → `friendshyft_init()`
   - Call `ClassName::init()` in appropriate context

2. **Add database table:**
   - Add `CREATE TABLE` SQL in `FS_Database::create_tables()`
   - OR create migration in `FS_Database_Migrations::run_migrations()`
   - Tables auto-create on plugin activation

3. **Add admin page:**
   - Create `class-admin-{feature}.php` in `admin/`
   - Register in `FS_Admin_Menu::add_menu_pages()` or as submenu
   - Initialize in `friendshyft_init()` if using `admin_post_*` hooks

4. **Add volunteer portal feature:**
   - Add method to `FS_Volunteer_Portal` or create new class in `public/`
   - Register AJAX handlers (both `wp_ajax_*` and `wp_ajax_nopriv_*`)
   - Support token-based authentication

5. **Add notification:**
   - Create method in `FS_Notifications` class
   - Use WordPress `wp_mail()` function
   - Include volunteer's access token in links: `"?token={$volunteer->access_token}"`

### Testing Checklist

When making changes:
- Test with WP_DEBUG enabled
- Verify both logged-in (admin) and token-based (volunteer) access
- Check for SQL injection via `$wpdb->prepare()`
- Verify nonce checks on admin forms
- Test team vs individual signup conflicts
- Verify cron jobs are scheduled correctly
- Check email notifications are sent

## Known Issues & TODOs

See TODO.md for comprehensive punch list. Key outstanding items:

**Critical:**
- Team migration needs to be integrated into activation hook (currently manual)
- Input validation for email ingestion (max size limits)

**Security:**
- All `$_POST` accesses now use `isset()` checks (completed)
- All `error_log()` wrapped in `WP_DEBUG` (completed)

## File Organization

```
friendshyft/
├── friendshyft.php           # Main plugin file
├── includes/                 # Core business logic
├── admin/                    # WordPress admin UI
├── public/                   # Public-facing UI (portal, kiosk, registration)
├── css/                      # Stylesheets
├── js/                       # JavaScript (if added)
├── assets/                   # Images, fonts, etc.
├── fs-*-migration.php        # Database migrations
└── *.md                      # Documentation
```

## Integration Points

### Shortcodes
- `[volunteer_portal]` - Main volunteer dashboard
- `[volunteer_interest_form]` - Public interest capture form

### REST API Endpoints
- `POST /wp-json/friendshyft/v1/complete-step` - Complete workflow step
- `POST /wp-json/friendshyft/v1/signup-opportunity` - Signup for opportunity
- `POST /wp-json/friendshyft/v1/cancel-signup` - Cancel signup

### Email Ingestion API
- `POST /wp-admin/admin-post.php?action=fs_receive_email` - Webhook for email ingestion
- Requires header: `X-FriendShyft-Token: {security_token}`
- Body: `{"raw_email": "..."}`

## WordPress Standards

- **Prefix all functions/classes:** `fs_` or `FS_`
- **Nonce verification:** Required for all form submissions
- **Capability checks:** Use `current_user_can('manage_friendshyft')` or `current_user_can('manage_volunteers')`
- **Sanitization:** Always sanitize input via `sanitize_text_field()`, `intval()`, etc.
- **Escape output:** Use `esc_html()`, `esc_attr()`, `esc_url()` in templates
- **Database queries:** Always use `$wpdb->prepare()` with placeholders

## Performance Considerations

- Database queries on volunteer portal are optimized with indexes on:
  - `access_token`, `email`, `volunteer_status`, `pin`, `qr_code`
- Large datasets (>10,000 volunteers) should paginate results
- Opportunity template generation runs weekly to avoid performance hits
- Email ingestion processes one email at a time (not batched)
