# Email Ingestion Feature - Implementation Guide

## Overview
This feature automatically processes volunteer interest emails from the Community Volunteer Hub, creating volunteer records and sending welcome emails.

## Files to Add to Plugin

### Core Classes (in `/includes/` directory)
1. `class-email-parser.php` - Parses volunteer hub emails
2. `class-email-processor.php` - Orchestrates the processing workflow
3. `class-email-ingestion.php` - Handles API endpoint and IMAP polling

### Admin Pages (in `/admin/` directory)
4. `class-admin-email-settings.php` - Settings page for API configuration
5. `class-admin-process-email.php` - Manual email processing interface
6. `class-admin-email-log.php` - Email processing log viewer

### Database
7. `fs-email-ingestion-migration.php` - Run once to create tables

## Integration Steps

### 1. Add Files to Main Plugin

Copy all files to your plugin directory:
```
friendshyft/
  includes/
    class-email-parser.php
    class-email-processor.php
    class-email-ingestion.php
  admin/
    class-admin-email-settings.php
    class-admin-process-email.php
    class-admin-email-log.php
```

### 2. Update Main Plugin File

Add to your main `friendshyft.php` file in the includes section:

```php
// Email ingestion feature
require_once plugin_dir_path(__FILE__) . 'includes/class-email-parser.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-email-processor.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-email-ingestion.php';

// Admin pages
if (is_admin()) {
    require_once plugin_dir_path(__FILE__) . 'admin/class-admin-email-settings.php';
    require_once plugin_dir_path(__FILE__) . 'admin/class-admin-process-email.php';
    require_once plugin_dir_path(__FILE__) . 'admin/class-admin-email-log.php';
    
    FS_Admin_Email_Settings::init();
    FS_Admin_Process_Email::init();
    FS_Admin_Email_Log::init();
}

// Initialize ingestion system
FS_Email_Ingestion::init();
```

### 3. Run Database Migration

Add this to your plugin activation hook or run manually via admin page:

```php
// In your plugin activation function
register_activation_hook(__FILE__, 'friendshyft_activate');

function friendshyft_activate() {
    require_once plugin_dir_path(__FILE__) . 'fs-email-ingestion-migration.php';
    FS_Email_Ingestion_Migration::run();
}
```

Or create a one-time admin page to run it:

```php
// Add to admin menu temporarily
add_action('admin_menu', function() {
    add_menu_page('Run Migration', 'Run Migration', 'manage_options', 'run-email-migration', function() {
        if (isset($_POST['run_migration'])) {
            require_once plugin_dir_path(__FILE__) . 'fs-email-ingestion-migration.php';
            FS_Email_Ingestion_Migration::run();
            echo 'Migration completed!';
        }
        echo 'Run Email Migration';
    });
});
```

### 4. Database Schema Created

The migration creates:

**fs_volunteers table updates:**
- `phone` VARCHAR(20) - Home phone
- `phone_cell` VARCHAR(20) - Cell phone
- `source` VARCHAR(50) - Origin (manual, email_hub, etc)

**fs_email_log table:**
- `id` - Primary key
- `received_date` - When email was received
- `from_address` - Sender email
- `subject` - Email subject
- `raw_body` - Complete email body
- `parsed_data` - JSON of extracted fields
- `status` - pending/success/duplicate/failed/success_no_email
- `volunteer_id` - Created volunteer (if applicable)
- `error_message` - Error details (if failed)
- `processed_date` - When processed

**fs_volunteer_interests table:**
- `id` - Primary key
- `volunteer_id` - Foreign key to volunteer
- `interest` - Type of volunteer opportunity
- `notes` - Additional notes from submission
- `source` - Origin (email_hub, manual, etc)
- `created_date` - When recorded

## Usage

### Option 1: Manual Processing (Easiest to Start)

1. Go to **FriendShyft → Process Email**
2. Paste the complete email from Community Volunteer Hub
3. Click "Process Email & Create Volunteer"
4. System will:
   - Parse name, email, phone, interest
   - Check for duplicates
   - Create volunteer record with source="email_hub"
   - Record their interest
   - Send welcome email with portal access link
   - Log the transaction

### Option 2: API Webhook (Automated)

1. Go to **FriendShyft → Email Settings**
2. Click "Generate Security Token"
3. Copy the API endpoint URL
4. Set up email forwarding:

**Using Zapier/Make:**
- Trigger: New email from volunteer hub
- Action: POST to endpoint URL
- Headers: `X-FriendShyft-Token: [your-token]`
- Body: `{"raw_email": "[email-body]"}`

**Using Mailgun Routes:**
- Create route to catch incoming emails
- Configure POST to endpoint URL with token header

### Option 3: IMAP Polling (Coming Soon)

IMAP support is built in but needs settings page for configuration. Would add:
- IMAP server settings
- Email/password
- Enable/disable toggle
- Hourly cron check for new emails

## Testing

### Test Email Format

Use this exact format to test:

```
Title: A New Response To Your Need
Body: This message is to notify you that a response has been submitted to Society of St Vincent de Paul – Fort Wayne's need.
Volunteer Opportunity: Food Pantry Volunteer
Submitter: Theresa Newman
Email: theresa.el.new@gmail.com
Phone: (419) 967-5723
Cell: (419) 967-5723
Additional Notes:
Thank you!
Your Friends at Volunteer Center
```

### Testing Steps

1. **Manual Processing Test:**
   - Go to Process Email page
   - Paste test email above
   - Verify volunteer created
   - Check email sent
   - View in Email Log

2. **API Endpoint Test:**
   - Use curl or Postman:
   ```bash
   curl -X POST [your-endpoint-url] \
     -H "Content-Type: application/json" \
     -H "X-FriendShyft-Token: [your-token]" \
     -d '{"raw_email": "Title: A New Response...[full email body]"}'
   ```

3. **Check Results:**
   - View in Email Log
   - Verify volunteer record exists
   - Check volunteer received welcome email
   - Confirm interest was recorded

## Admin Pages

### Email Settings (`/wp-admin/admin.php?page=fs-email-settings`)
- Generate/regenerate security token
- View API endpoint URL
- Test email parsing
- Setup instructions

### Process Email (`/wp-admin/admin.php?page=fs-process-email`)
- Manual email processing interface
- Paste and process emails one at a time
- Immediate feedback on success/errors

### Email Log (`/wp-admin/admin.php?page=fs-email-log`)
- View all processed emails
- Filter by status (success/duplicate/failed/pending)
- View details of each email
- Reprocess failed emails
- Click volunteer ID to view their record

## Features

### Duplicate Handling
- Checks for existing email before creating volunteer
- If duplicate found:
  - Logs as "duplicate" status
  - Still records the new interest expressed
  - Notifies admin
  - Does NOT send another welcome email

### Error Handling
- Parse failures: Logs error, notifies admin, keeps raw email for review
- Creation failures: Logs error with details
- Email send failures: Creates volunteer but logs as "success_no_email"

### Volunteer Details Captured
- **Name**: From "Submitter" field
- **Email**: From "Email" field
- **Phone**: From "Phone" field
- **Cell**: From "Cell" field
- **Interest**: From "Volunteer Opportunity" field
- **Notes**: From "Additional Notes" field (optional)
- **Source**: Automatically set to "email_hub"
- **Access Token**: Auto-generated for portal access

### Welcome Email
Automatically sends:
- Personalized greeting
- Portal access link (magic link with token)
- Overview of portal features
- Organization branding

## Troubleshooting

### Email Not Parsing
1. Check Email Log for error details
2. View raw email in log detail modal
3. Verify email format matches expected structure
4. Use Test Parser on settings page

### Volunteer Not Created
1. Check for duplicate email
2. Review error message in Email Log
3. Try reprocessing from log page

### Welcome Email Not Sent
- Volunteer still created successfully
- Status shows as "success_no_email"
- Check WordPress email configuration
- Consider SMTP plugin if needed

### API Endpoint Not Working
1. Verify token matches
2. Check X-FriendShyft-Token header
3. Ensure JSON body has "raw_email" field
4. Review web server logs for errors

## Future Enhancements

Potential additions:
- IMAP configuration UI (already built, needs settings page)
- Bulk import from existing hub emails
- Interest matching to opportunities
- Auto-assign to programs based on interest
- Reply-to-email for questions
- SMS notifications (if cell phone provided)

## Security

- API endpoint requires security token
- Token is 64-character random string
- Regenerating token invalidates old one
- All volunteer data sanitized on input
- Email validation before storage
- Admin capabilities required for settings

## Performance

- Email processing is fast (< 1 second)
- Large email backlogs process in batches
- Cron job runs hourly for IMAP
- Webhook is real-time
- Email log table has indexes for fast filtering

## Support

If issues arise:
- Check Email Log first
- Review error messages
- Test with known good email format
- Reprocess failed emails from log
- Contact for assistance with parsing changes
