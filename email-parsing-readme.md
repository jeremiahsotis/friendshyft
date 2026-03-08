# FriendShyft Email Ingestion Feature

Automated volunteer onboarding from Community Volunteer Hub emails.

## 📋 What It Does

Automatically processes volunteer interest emails:
1. **Receives** → Email from Community Volunteer Hub
2. **Parses** → Extracts name, email, phone, interest
3. **Creates** → New volunteer record with portal access
4. **Records** → Interest in volunteer_interests table
5. **Sends** → Welcome email with magic link
6. **Logs** → Everything for review and audit

## 🎯 Key Benefits

- **No more manual data entry** from volunteer hub emails
- **Instant portal access** for new volunteers
- **Complete audit trail** of all processing
- **Duplicate prevention** with smart detection
- **Error handling** with admin notifications
- **Interest tracking** for program matching

## 📦 Package Contents

### Core Classes (6 files)
```
class-email-parser.php          - Parses volunteer hub emails
class-email-processor.php       - Orchestrates workflow
class-email-ingestion.php       - API endpoint + IMAP
class-admin-email-settings.php  - Settings page
class-admin-process-email.php   - Manual processing UI
class-admin-email-log.php       - Log viewer
```

### Setup Files (2 files)
```
fs-email-ingestion-migration.php    - Database migration
class-admin-email-migration.php     - Migration runner UI
```

### Documentation (3 files)
```
INTEGRATION-CHECKLIST.md         - Step-by-step setup
EMAIL-INGESTION-IMPLEMENTATION.md - Complete guide
EMAIL-INGESTION-SUMMARY.md        - Quick reference
```

## 🚀 Quick Start

### 1. Install Files
Copy all PHP files to your plugin directory:
- Core classes → `/includes/`
- Admin pages → `/admin/`
- Migration file → plugin root

### 2. Update Plugin
Add requires to main plugin file (see INTEGRATION-CHECKLIST.md)

### 3. Run Migration
Go to: **FriendShyft → Email Migration** → Click "Run Migration"

### 4. Test It
Go to: **FriendShyft → Process Email** → Paste sample email → Process

### 5. Start Using
Process emails manually or set up API webhook for automation

## 📊 Database Changes

### New Tables

**fs_email_log** - Tracks all email processing
- All incoming emails logged with status
- Parsed data stored as JSON
- Links to created volunteer records
- Error messages for failed processing

**fs_volunteer_interests** - Interest tracking
- Multiple interests per volunteer
- Source tracking (email_hub, manual, etc)
- Notes from submission
- Date stamped

### Modified Tables

**fs_volunteers** - Added fields
- `phone` - Home phone number
- `phone_cell` - Cell phone number
- `source` - Origin (email_hub, manual, etc)

## 🎨 Admin Interface

### Three New Menu Items

**Process Email**
- Manual email processing
- Paste and process interface
- Immediate feedback
- Perfect for testing

**Email Settings**
- Generate API token
- View endpoint URL
- Test email parser
- Setup instructions

**Email Log**
- View all processed emails
- Filter by status
- View raw email and parsed data
- Reprocess failed emails

## 🔄 Processing Flow

```
┌─────────────────────────────────────┐
│  Email Received                     │
│  (Manual paste or API webhook)      │
└────────────┬────────────────────────┘
             │
             ↓
┌─────────────────────────────────────┐
│  Log Raw Email                      │
│  Status: pending                    │
└────────────┬────────────────────────┘
             │
             ↓
┌─────────────────────────────────────┐
│  Parse Email                        │
│  Extract: name, email, phone, etc   │
└────────────┬────────────────────────┘
             │
        ┌────┴─────┐
        │          │
        ↓          ↓
   Parse OK    Parse Failed
        │          │
        │          └──→ Log error, notify admin
        │
        ↓
┌─────────────────────────────────────┐
│  Check for Duplicate Email          │
└────────────┬────────────────────────┘
             │
        ┌────┴──────┐
        │           │
        ↓           ↓
    Duplicate      New
        │           │
        │           ↓
        │    ┌──────────────────┐
        │    │ Create Volunteer │
        │    │ Generate Token   │
        │    │ Record Interest  │
        │    └────────┬─────────┘
        │             │
        │             ↓
        │    ┌──────────────────┐
        │    │ Send Welcome     │
        │    │ Email with Link  │
        │    └────────┬─────────┘
        │             │
        ├─────────────┤
        │             │
        ↓             ↓
┌─────────────────────────────────────┐
│  Log Result                         │
│  - Success: volunteer_id            │
│  - Duplicate: existing volunteer_id │
│  - Failed: error message            │
└─────────────────────────────────────┘
             │
             ↓
┌─────────────────────────────────────┐
│  Admin Notification                 │
│  (errors and duplicates only)       │
└─────────────────────────────────────┘
```

## 📧 Email Format

The system expects this exact format from Community Volunteer Hub:

```
Title: A New Response To Your Need
Body: This message is to notify you that a response has been submitted to Society of St Vincent de Paul – Fort Wayne's need.
Volunteer Opportunity: Food Pantry Volunteer
Submitter: Theresa Newman
Email: theresa.el.new@gmail.com
Phone: (419) 967-5723
Cell: (419) 967-5723
Additional Notes: [optional notes here]
Thank you!
Your Friends at Volunteer Center
```

**Fields Extracted:**
- `Volunteer Opportunity:` → Interest
- `Submitter:` → Name
- `Email:` → Email address
- `Phone:` → Home phone
- `Cell:` → Cell phone
- `Additional Notes:` → Notes

## 🎯 Usage Options

### Option 1: Manual Processing (Easiest)
1. Receive email from hub
2. Go to **Process Email** page
3. Copy/paste email body
4. Click "Process"
5. Done!

**Best for:** Starting out, low volume, testing

### Option 2: API Webhook (Automated)
1. Generate security token
2. Configure email service to POST
3. Emails process automatically

**Best for:** High volume, fully automated workflow

### Option 3: IMAP Polling (Future)
Not yet implemented in UI, but code is ready.
Would check inbox hourly for new emails.

## 🔐 Security

- API endpoint requires 64-character token
- Token regeneration invalidates old tokens
- All input sanitized and validated
- Email validation before storage
- Admin-only access to settings
- Audit trail of all activity

## 🎨 Customization

### Welcome Email
**File:** `class-email-parser.php` (line ~260)
- Customize subject line
- Modify email body
- Add organization branding
- Change portal URL

### Parse Logic
**File:** `class-email-parser.php` (line ~17-80)
- Adjust regex patterns
- Add/remove fields
- Change validation rules

### Status Values
**Files:** Various
- `success` - Volunteer created, email sent
- `success_no_email` - Created but email failed
- `duplicate` - Already exists, interest added
- `failed` - Parsing or creation error
- `pending` - Not yet processed

## 📊 Monitoring

### Email Log Dashboard
- Real-time processing status
- Success/failure metrics
- Detailed error messages
- Raw email review
- Reprocess capability

### Status Filters
- All emails
- Successful
- Duplicates
- Failed
- Pending

### Actions
- View details (modal)
- View volunteer record
- Reprocess failed emails

## 🐛 Troubleshooting

### Email Not Parsing
1. Check Email Log for specific error
2. Verify format matches expected structure
3. Use "Test Parser" on settings page
4. Review regex patterns in parser class

### Volunteer Not Created
1. Check for duplicate email
2. Review error in Email Log
3. Verify required fields present
4. Check database permissions

### Welcome Email Not Sent
- Volunteer still created (success_no_email)
- Check WordPress email config
- Consider SMTP plugin
- Review email logs

### API Not Working
1. Verify token is correct
2. Check header: `X-FriendShyft-Token`
3. Ensure JSON format
4. Review web server logs

## 🔮 Future Enhancements

Potential additions:
- [ ] IMAP configuration UI
- [ ] Bulk import historical emails
- [ ] Interest matching to opportunities
- [ ] Auto-assign to programs
- [ ] Reply-to-email handling
- [ ] SMS notifications
- [ ] Custom field mapping
- [ ] Multi-language support

## 📚 Documentation

- **INTEGRATION-CHECKLIST.md** - Step-by-step setup guide
- **EMAIL-INGESTION-IMPLEMENTATION.md** - Complete technical docs
- **EMAIL-INGESTION-SUMMARY.md** - Quick reference

## 🎓 Learning Resources

### Key Concepts
- **Magic Links** - Token-based authentication for portal
- **Duplicate Detection** - Email-based uniqueness
- **Audit Trail** - Complete logging for compliance
- **Interest Tracking** - Multi-source interest capture
- **Error Recovery** - Reprocess capability

### Code Architecture
- **Parser** - Single responsibility: extract data
- **Processor** - Orchestrate workflow
- **Ingestion** - Handle incoming emails
- **Admin** - User interface layers

## 🤝 Contributing

If you extend this feature:
1. Update regex patterns carefully
2. Test with real hub emails
3. Maintain error handling
4. Document changes
5. Update this README

## 📝 License

Part of FriendShyft WordPress plugin.

## ✨ Credits

Built with ❤️ for St. Vincent de Paul Fort Wayne
Designed for nonprofit volunteer management
Removing Monday.com dependency
Making volunteer onboarding seamless

---
