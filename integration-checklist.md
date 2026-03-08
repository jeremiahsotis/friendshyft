# Email Ingestion - Integration Checklist

## Step 1: Copy Files to Plugin

Copy these files to your FriendShyft plugin directory:

```
friendshyft/
├── includes/
│   ├── class-email-parser.php          ← COPY HERE
│   ├── class-email-processor.php        ← COPY HERE
│   └── class-email-ingestion.php        ← COPY HERE
│
├── admin/
│   ├── class-admin-email-settings.php   ← COPY HERE
│   ├── class-admin-process-email.php    ← COPY HERE
│   ├── class-admin-email-log.php        ← COPY HERE
│   └── class-admin-email-migration.php  ← COPY HERE (temporary)
│
└── fs-email-ingestion-migration.php     ← COPY HERE
```

## Step 2: Update Main Plugin File

**File:** `friendshyft.php` (or your main plugin file)

Add this code after your existing includes:

```php
// ============================================
// EMAIL INGESTION FEATURE
// ============================================

// Core classes
require_once plugin_dir_path(__FILE__) . 'includes/class-email-parser.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-email-processor.php';
require_once plugin_dir_path(__FILE__) . 'includes/class-email-ingestion.php';

// Initialize ingestion (REST API endpoint, etc)
FS_Email_Ingestion::init();

// Admin pages
if (is_admin()) {
    require_once plugin_dir_path(__FILE__) . 'admin/class-admin-email-settings.php';
    require_once plugin_dir_path(__FILE__) . 'admin/class-admin-process-email.php';
    require_once plugin_dir_path(__FILE__) . 'admin/class-admin-email-log.php';
    
    // Temporary migration runner (remove after running migration)
    require_once plugin_dir_path(__FILE__) . 'admin/class-admin-email-migration.php';
    
    // Initialize admin pages
    FS_Admin_Email_Settings::init();
    FS_Admin_Process_Email::init();
    FS_Admin_Email_Log::init();
    
    // Temporary (remove after running migration)
    FS_Email_Migration_Runner::init();
    add_action('admin_post_rollback_email_migration', array('FS_Email_Migration_Runner', 'rollback_migration'));
}
```

## Step 3: Run Database Migration

1. **Go to:** WordPress Admin → FriendShyft → Email Migration
2. **Click:** "Run Migration" button
3. **Verify:** Green checkmarks appear for all items
4. **Result:** 
   - ✓ fs_email_log table created
   - ✓ fs_volunteer_interests table created
   - ✓ phone fields added to fs_volunteers

## Step 4: Remove Migration Runner (Optional)

After successful migration, you can remove:

**From main plugin file, delete these lines:**
```php
require_once plugin_dir_path(__FILE__) . 'admin/class-admin-email-migration.php';
FS_Email_Migration_Runner::init();
add_action('admin_post_rollback_email_migration', array('FS_Email_Migration_Runner', 'rollback_migration'));
```

**Delete this file:**
```
friendshyft/admin/class-admin-email-migration.php
```

## Step 5: Test Manual Processing

1. **Go to:** FriendShyft → Process Email
2. **Paste this test email:**
   ```
   Title: A New Response To Your Need
   Body: This message is to notify you that a response has been submitted to Society of St Vincent de Paul – Fort Wayne's need.
   Volunteer Opportunity: Food Pantry Volunteer
   Submitter: Test Volunteer
   Email: test@example.com
   Phone: (260) 555-1234
   Cell: (260) 555-1234
   Additional Notes: This is a test submission
   Thank you!
   Your Friends at Volunteer Center
   ```
3. **Click:** "Process Email & Create Volunteer"
4. **Verify:**
   - Success message appears
   - Volunteer created
   - Welcome email sent
   - Link to view volunteer works

## Step 6: Check Email Log

1. **Go to:** FriendShyft → Email Log
2. **Verify:**
   - Test email appears in log
   - Status shows as "Success"
   - Parsed data is correct
   - Volunteer ID is linked
3. **Click:** "View" to see details

## Step 7: Verify Volunteer Record

1. **Go to:** FriendShyft → Volunteers
2. **Find:** Test volunteer (test@example.com)
3. **Verify:**
   - Name: Test Volunteer
   - Email: test@example.com
   - Phone fields populated
   - Source: email_hub
   - Access token generated
4. **Check:** Interest recorded in volunteer interests table

## Step 8: Set Up Automation (Optional)

### Option A: API Webhook

1. **Go to:** FriendShyft → Email Settings
2. **Click:** "Generate Security Token"
3. **Copy:** API endpoint URL
4. **Copy:** Security token
5. **Configure:** Email service to POST to endpoint
   - Headers: `X-FriendShyft-Token: [your-token]`
   - Body: `{"raw_email": "[email-body]"}`

### Option B: Keep Manual (Recommended to Start)

- Just keep using the "Process Email" page
- Paste emails as they arrive
- Simple and reliable

## Verification Checklist

- [ ] All files copied to correct directories
- [ ] Main plugin file updated with requires
- [ ] Migration run successfully
- [ ] Test email processed successfully
- [ ] Email log shows test entry
- [ ] Volunteer record created correctly
- [ ] Interest recorded properly
- [ ] Welcome email sent
- [ ] Email log filtering works
- [ ] View details modal works
- [ ] Admin pages appear in menu

## Menu Structure (After Integration)

```
FriendShyft
├── Dashboard
├── Volunteers
├── Opportunities
├── ... (your existing pages)
├── Process Email         ← NEW
├── Email Settings        ← NEW
└── Email Log             ← NEW
```

## Testing Different Scenarios

### Test 1: Successful Creation
✓ Use test email above
✓ Should create new volunteer

### Test 2: Duplicate Email
✓ Process same email again
✓ Should show duplicate status
✓ Should add new interest to existing volunteer

### Test 3: Missing Required Field
```
Volunteer Opportunity: Test
Submitter: 
Email: 
```
✗ Should fail with parse error
✓ Should appear in email log as "Failed"

### Test 4: Invalid Email Format
```
Volunteer Opportunity: Test
Submitter: Bad Email
Email: not-an-email
```
✗ Should fail validation
✓ Should log with error message

## Troubleshooting

**Problem:** Migration page shows errors
- Check database permissions
- Verify table doesn't already exist
- Check WordPress debug log

**Problem:** Test email not parsing
- Verify exact format from Community Volunteer Hub
- Check for hidden characters
- Use "Test Parse" on Email Settings page

**Problem:** Volunteer not created
- Check Email Log for error message
- Verify email address is valid
- Check for duplicate

**Problem:** Welcome email not sent
- Check WordPress email configuration
- Consider installing SMTP plugin
- Volunteer still created (success_no_email status)

## Next Steps After Integration

1. **Process real emails** from Community Volunteer Hub
2. **Monitor Email Log** for any parsing issues
3. **Set up API webhook** if you want automation
4. **Customize welcome email** in class-email-parser.php (line ~260)
5. **Review volunteer interests** periodically
6. **Consider matching interests to opportunities**

## Support

If you encounter issues:
- Review Email Log for error details
- Check WordPress debug log
- Verify email format matches expected structure
- Test with known good email
- Review this checklist for missed steps

## Success Criteria

You'll know it's working when:
- ✓ Manual processing creates volunteers instantly
- ✓ Email log shows all processing activity
- ✓ Volunteers receive welcome emails
- ✓ Phone numbers captured properly
- ✓ Interests tracked for each volunteer
- ✓ Duplicates handled gracefully
- ✓ Errors logged with details

