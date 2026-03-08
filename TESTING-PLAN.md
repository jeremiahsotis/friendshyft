# FriendShyft End-to-End Testing Plan

**Version:** 1.1.0
**Date:** 2025-12-12
**Environment:** Local by Flywheel (friendshyft.local)

---

## Pre-Testing Setup

### 1. Environment Verification
- [ ] Local by Flywheel is running
- [ ] WordPress site accessible at http://friendshyft.local/wp-admin
- [ ] PHP version 7.4+ confirmed
- [ ] MySQL database accessible
- [ ] WP_DEBUG enabled in wp-config.php
- [ ] Debug log file writable: `wp-content/debug.log`

### 2. Plugin Activation
- [ ] Navigate to Plugins → Installed Plugins
- [ ] Activate FriendShyft plugin
- [ ] Confirm no PHP errors in debug.log
- [ ] Verify "FriendShyft" menu appears in admin sidebar

### 3. Database Verification
**Check all tables created:**
```sql
SHOW TABLES LIKE 'wp_fs_%';
```

Expected tables (34 total):

**Core Tables (12):**
- [X] wp_fs_volunteers
- [X] wp_fs_roles
- [X] wp_fs_programs
- [X] wp_fs_opportunities
- [X] wp_fs_signups
- [X] wp_fs_time_records
- [X] wp_fs_volunteer_interests
- [X] wp_fs_volunteer_roles
- [X] wp_fs_opportunity_templates
- [X] wp_fs_holidays
- [X] wp_fs_workflows
- [X] wp_fs_progress (workflow progress tracking)

**Team Management (4):**
- [X] wp_fs_teams
- [X] wp_fs_team_members
- [X] wp_fs_team_attendance
- [X] wp_fs_team_signups

**Badges & Achievements (1):**
- [X] wp_fs_volunteer_badges

**Email & Notifications (2):**
- [X] wp_fs_email_log
- [X] wp_fs_handoff_notifications

**Portal Enhancements (2):**
- [X] wp_fs_opportunity_shifts
- [X] wp_fs_favorites

**Google Calendar Integration (1):**
- [X] wp_fs_blocked_times

**Feedback System (3):**
- [X] wp_fs_surveys
- [X] wp_fs_suggestions
- [X] wp_fs_testimonials

**Advanced Scheduling (6):**
- [X] wp_fs_waitlist
- [X] wp_fs_substitute_requests
- [X] wp_fs_swap_history
- [X] wp_fs_availability
- [X] wp_fs_blackout_dates
- [X] wp_fs_auto_signup_log

**Analytics & Insights (4):**
- [X] wp_fs_predictions
- [X] wp_fs_engagement_scores
- [X] wp_fs_reengagement_campaigns
- [X] wp_fs_audit_log

### 4. Cron Jobs Verification
**Check scheduled cron jobs:**
```php
// Via WP-CLI or plugin like WP Crontrol
wp cron event list
```

Expected cron jobs (11 total):
- [X] fs_send_attendance_reminders (daily, 9 AM)
- [X] fs_generate_opportunities_cron (weekly)
- [X] fs_check_imap_inbox (hourly)
- [X] fs_sync_cron (daily)
- [X] fs_daily_handoff_check (daily)
- [X] fs_check_google_calendar_cron (hourly)
- [X] fs_send_event_surveys_cron (daily)
- [X] fs_process_auto_signups_cron (daily)
- [X] fs_update_engagement_scores_cron (daily)
- [X] fs_send_reengagement_campaigns_cron (weekly)
- [X] fs_update_predictions_cron (daily)

---

## Phase 1: Core Data Setup

### Programs Management
1. [X] Navigate to FriendShyft → Programs
2. [X] Create test programs:
   - [X] "Food Distribution" (description, active)
   - [X] "Youth Mentoring" (description, active)
   - [X] "Community Cleanup" (description, active)
3. [X] Edit a program and update description
4. [X] Verify programs list displays correctly
5. [X] Check audit log for program creation events

### Roles Management
1. [X] Navigate to FriendShyft → Roles
2. [X] Create test roles:
   - [X] "Food Handler" (training required: yes, min age: 18)
   - [X] "Mentor" (training required: yes, min age: 21)
   - [X] "General Volunteer" (training required: no, min age: 16)
3. [X] Link roles to programs (Food Handler → Food Distribution)
4. [X] Edit a role and update requirements
5. [X] Verify roles list displays correctly
6. [X] Check audit log for role creation events

### Holidays Management
1. [X] Navigate to FriendShyft → Holidays
2. [X] Add holidays:
   - [X] "Christmas 2025" (12/25/2025)
   - [X] "New Year's Day 2026" (01/01/2026)
3. [X] Edit a holiday
4. [X] Delete a holiday
5. [X] Verify holiday list displays correctly

---

## Phase 2: Volunteer Management

### Manual Volunteer Creation
1. [X] Navigate to FriendShyft → Add Volunteer
2. [X] Create test volunteers:
   - **Volunteer 1:** "John Doe" (john@example.com, 555-1234, DOB: 1990-01-01)
   - **Volunteer 2:** "Jane Smith" (jane@example.com, 555-5678, DOB: 1995-05-15)
   - **Volunteer 3:** "Bob Wilson" (bob@example.com, 555-9999, DOB: 1985-10-20)
3. [X] Assign roles to volunteers (John → Food Handler, Jane → Mentor)
4. [X] Verify access tokens auto-generated (64 characters)
5. [X] Verify PINs auto-generated (4 digits)
6. [X] Verify QR codes generated
7. [ ] Check audit log for volunteer creation events

### Volunteer List & Management
1. [X] Navigate to FriendShyft → Volunteers
2. [X] Verify all test volunteers appear
3. [X] Test search functionality (by name, email)
4. [ ] Test filter by status (active/inactive)
5. [X] Edit a volunteer and update details
6. [ ] Resend welcome email to a volunteer
7. [X] Deactivate a volunteer
8. [X] Reactivate a volunteer
9. [ ] Export volunteers to CSV
10. [ ] Verify CSV contains all expected columns

### Bulk Operations
1. [X] Navigate to FriendShyft → Bulk Operations
2. [ ] Test bulk role assignment:
   - [X] Select multiple volunteers
   - [X] Assign "General Volunteer" role to all
   - [X] Verify role assignments in database
3. [X] Test bulk email:
   - [X] Select multiple volunteers
   - [X] Send custom email message
   - [X] Verify emails sent successfully
4. [X] Check audit log for bulk operations

---

## Phase 3: Opportunity Management

### Opportunity Templates
1. [X] Navigate to FriendShyft → Templates
2. [X] Create recurring template:
   - [X] Title: "Weekly Food Distribution"
   - [X] Program: Food Distribution
   - [X] Recurrence: Weekly (Every Monday)
   - [X] Time: 9:00 AM - 12:00 PM
   - [X] Spots: 5
   - [X] Roles: Food Handler
3. [ ] Create one-time template:
   - [X] Title: "Community Cleanup Day"
   - [X] Recurrence: None
4. [X] Edit template and update details
5. [X] Manually trigger opportunity generation
6. [X] Verify opportunities created for next 4 weeks
7. [ ] Check audit log for template events

### Manual Opportunity Creation
1. [X] Navigate to FriendShyft → Opportunities
2. [X] Create test opportunities:
   - [X] "Youth Mentoring Session" (tomorrow, 2:00-4:00 PM, 3 spots)
   - [X] "Park Cleanup" (next week, 10:00 AM-1:00 PM, 10 spots)
3. [X] Assign required roles to opportunities
4. [X] Set location and description
5. [X] Publish opportunities
6. [X] Verify opportunities list displays correctly
7. [ ] Check audit log for opportunity creation events

### Opportunity Editing
1. [X] Edit an opportunity:
   - [X] Change date/time
   - [X] Update spots available
   - [X] Modify description
2. [ ] Draft an opportunity (unpublish)
3. [X] Cancel an opportunity
4. [X] Verify status changes reflected in list
5. [ ] Check audit log for all edits

---

## Phase 4: Signup Management

### Manual Signups (Admin)
1. [X] Navigate to FriendShyft → Signups
2. [X] Create manual signup:
   - [X] Select volunteer (John Doe)
   - [X] Select opportunity (Youth Mentoring Session)
   - [X] Confirm signup
3. [X] Verify signup appears in list
4. [X] Verify volunteer receives confirmation email
5. [X] Check spots_filled incremented on opportunity
6. [ ] Check audit log for signup event

### Signup Conflicts
1. [X] Attempt to sign up same volunteer for overlapping opportunity
2. [X] Verify conflict detected and error shown
3. [X] Create signup for different time - should succeed
4. [X] Check audit log for conflict detection

### Signup Status Changes
1. [X] Navigate to FriendShyft → Signups
2. [ ] Change signup status:
   - [ ] Confirmed → No Show
   - [ ] No Show → Confirmed
   - [ ] Confirmed → Cancelled
3. [ ] Verify spots_filled updates correctly
4. [ ] Verify volunteer notified of status changes
5. [ ] Check audit log for status changes

### Waitlist Testing (Advanced Scheduling)
1. [X] Fill an opportunity to capacity (all spots taken)
2. [X] Attempt to sign up another volunteer
3. [X] Verify volunteer added to waitlist
4. [X] Check waitlist rank calculated correctly
5. [X] Cancel one existing signup
6. [X] Verify waitlist volunteer automatically promoted
7. [X] Verify promotion email sent
8. [X] Check audit log for waitlist events

---

## Phase 5: Time Tracking

### Kiosk Check-In/Check-Out
1. [X] Navigate to volunteer portal kiosk URL
2. [ ] Test PIN-based check-in:
   - [ ] Enter volunteer PIN (John Doe's PIN)
   - [ ] Select opportunity
   - [ ] Confirm check-in
   - [ ] Verify check-in time recorded
3. [ ] Test QR code check-in:
   - [ ] Scan volunteer QR code (or enter QR manually)
   - [ ] Verify check-in successful
4. [ ] Test check-out:
   - [ ] Enter same PIN
   - [ ] Select opportunity
   - [ ] Confirm check-out
   - [ ] Verify hours calculated correctly
5. [ ] Verify time record created in database
6. [ ] Check audit log for time tracking events

### Admin Time Records
1. [ ] Navigate to FriendShyft → Dashboard
2. [ ] Verify recent time records displayed
3. [ ] Check total hours calculated correctly
4. [ ] Export time records to CSV
5. [ ] Verify CSV formatting correct

### Badge Awarding
1. [ ] Navigate to FriendShyft → Achievements
2. [ ] Verify badges auto-awarded based on hours:
   - [ ] 10 hours badge
   - [ ] 50 hours badge (if applicable)
3. [ ] Manually award a badge to a volunteer
4. [ ] Verify badge notification email sent
5. [ ] Check audit log for badge events

---

## Phase 6: Team Management

### Team Creation
1. [X] Navigate to FriendShyft → Teams
2. [X] Create test team:
   - [X] Name: "Community Church Group"
   - [X] Leader: John Doe
   - [X] Description: "Weekly volunteers from First Community"
3. [X] Add team members:
   - [X] Jane Smith
   - [X] Bob Wilson
4. [X] Verify team appears in list
5. [ ] Check audit log for team creation

### Team Signups
1. [ ] Create team signup for opportunity:
   - [ ] Select team
   - [ ] Select opportunity
   - [ ] Estimated count: 5
   - [ ] Confirm signup
2. [ ] Verify team signup created
3. [ ] Verify opportunity spots allocated for team
4. [ ] Verify team leader receives confirmation
5. [ ] Check audit log for team signup

### Team Conflict Resolution
1. [X] Sign up individual volunteer for opportunity
2. [X] Sign up their team for same opportunity
3. [X] Verify individual signup flagged as needs_merge
4. [ ] Verify volunteer receives merge notification
5. [ ] Check audit log for conflict resolution

### Team Time Tracking
1. [X] Team leader checks in team using kiosk
2. [X] Enter team leader PIN
3. [X] Set people_count (e.g., 5)
4. [X] Confirm check-in
5. [X] Check out team
6. [ ] Verify hours_per_person and total_hours calculated
7. [X] Verify team attendance record created
8. [X] Check audit log for team time tracking

---

## Phase 7: Workflows (Training/Onboarding)

### Workflow Creation
1. [X] Navigate to FriendShyft → Workflows
2. [X] Create training workflow:
   - [X] Name: "Food Handler Training"
   - [X] Description: "Required safety training"
3. [X] Add workflow steps:
   - [X] Step 1: "Watch Safety Video" (order: 1)
   - [X] Step 2: "Read Handbook" (order: 2)
   - [X] Step 3: "Pass Quiz" (order: 3)
4. [ ] Link workflow to role (Food Handler)
5. [X] Verify workflow appears in list

### Workflow Assignment
1. [X] Assign workflow to volunteer manually
2. [X] Verify volunteer sees workflow in portal
3. [ ] Mark steps complete via API/admin
4. [ ] Verify completion notification sent
5. [ ] Check audit log for workflow events

---

## Phase 8: Email Ingestion

### API Endpoint Testing
1. [X] Navigate to FriendShyft → Email Settings
2. [ ] Note the security token
3. [ ] Test API endpoint using curl or Postman:
```bash
curl -X POST http://friendshyft.local/wp-admin/admin-post.php?action=fs_receive_email \
  -H "X-FriendShyft-Token: YOUR_TOKEN_HERE" \
  -H "Content-Type: application/json" \
  -d '{
    "raw_email": "From: test@example.com\nSubject: Volunteer Interest\n\nName: Test Person\nEmail: test@example.com\nPhone: 555-0000\nInterests: Food Distribution, Mentoring"
  }'
```
4. [ ] Verify 200 OK response
5. [ ] Check email log for processed email
6. [ ] Verify volunteer created in database
7. [ ] Verify interests captured
8. [ ] Verify welcome email sent

### Email Log Review
1. [ ] Navigate to FriendShyft → Email Log
2. [ ] Verify processed email appears
3. [ ] Check email details (parsed data)
4. [ ] Filter by status (success/error)
5. [ ] Check audit log integration

---

## Phase 9: Google Calendar Integration (Session 7)

### OAuth Setup
1. [ ] Navigate to FriendShyft → Google Calendar
2. [ ] Enter Google OAuth credentials (if available)
3. [ ] Test OAuth flow:
   - [ ] Click "Connect Google Calendar"
   - [ ] Authorize app
   - [ ] Verify redirect back with success message
4. [ ] Verify access token and refresh token stored

### Two-Way Sync
1. [ ] Volunteer connects Google Calendar in portal
2. [ ] Create signup for volunteer
3. [ ] Verify event created in volunteer's Google Calendar
4. [ ] Update event in Google Calendar
5. [ ] Verify blocked time created in FriendShyft
6. [ ] Cancel signup
7. [ ] Verify event removed from Google Calendar
8. [ ] Check audit log for sync events

### Blocked Times
1. [ ] Create manual blocked time for volunteer
2. [ ] Attempt to sign up volunteer during blocked time
3. [ ] Verify conflict detected
4. [ ] Check blocked times list in admin

---

## Phase 10: Volunteer Feedback System (Session 7)

### Post-Event Surveys
1. [ ] Complete an opportunity (set date to past)
2. [ ] Trigger survey cron manually or wait for daily run
3. [ ] Verify survey email sent to volunteers
4. [ ] Volunteer clicks survey link in email
5. [ ] Complete survey:
   - [ ] Rating: 5 stars
   - [ ] Comments: "Great experience!"
   - [ ] Submit
6. [ ] Navigate to FriendShyft → Volunteer Feedback
7. [ ] Verify survey response appears
8. [ ] Check average rating calculated correctly

### Suggestion Box
1. [X] Volunteer submits suggestion via portal:
   - [X] Category: "Scheduling"
   - [X] Title: "Need weekend shifts"
   - [X] Description: "Would love more Saturday opportunities"
   - [X] Submit
2. [X] Navigate to FriendShyft → Volunteer Feedback → Suggestions
3. [X] Verify suggestion appears
4. [X] Update suggestion status to "Under Review"
5. [X] Add admin response
6. [X] Verify volunteer receives notification
7. [X] Mark as "Implemented"
8. [X] Check audit log for suggestion events

### Testimonials
1. [X] Volunteer submits testimonial via portal:
   - [X] Content: "Volunteering has changed my life!"
   - [X] Permission to publish: Yes
   - [X] Submit
2. [X] Navigate to FriendShyft → Volunteer Feedback → Testimonials
3. [X] Verify testimonial appears (pending)
4. [X] Approve testimonial
5. [X] Verify testimonial marked as published
6. [X] Check testimonial displays on public page (if implemented)
7. [X] Check audit log for testimonial events

---

## Phase 11: Advanced Scheduling (Session 8)

### Waitlist Management
1. [ ] Navigate to FriendShyft → Advanced Scheduling → Waitlists
2. [ ] Verify statistics displayed (waiting count, promoted count)
3. [ ] View waitlist for specific opportunity
4. [ ] Verify rank order displayed correctly
5. [ ] Check rank calculation factors (hours, signups, reliability)
6. [ ] Manually promote volunteer from waitlist
7. [ ] Export waitlist data to CSV

### Substitute Finder
1. [ ] Volunteer requests substitute via portal:
   - [ ] Select signed-up opportunity
   - [ ] Click "Request Substitute"
   - [ ] Enter reason (optional)
   - [ ] Submit request
2. [ ] Navigate to FriendShyft → Advanced Scheduling → Substitutes
3. [ ] Verify substitute request appears (pending)
4. [ ] Verify qualified volunteers notified via email
5. [ ] Qualified volunteer accepts substitute request:
   - [ ] Click link in email
   - [ ] Confirm acceptance
6. [ ] Verify swap recorded in swap_history
7. [ ] Verify original volunteer receives confirmation
8. [ ] Check audit log for substitute events
9. [ ] Export swap history to CSV

### Recurring Personal Schedules
1. [ ] Volunteer sets recurring availability in portal:
   - [ ] Monday 9:00 AM - 5:00 PM
   - [ ] Wednesday 9:00 AM - 5:00 PM
   - [ ] Enable auto-signup
2. [ ] Add blackout date:
   - [ ] Date range: Next week Monday-Friday
   - [ ] Reason: "Vacation"
3. [ ] Create matching opportunity (Monday 10:00 AM - 12:00 PM)
4. [ ] Trigger auto-signup cron manually
5. [ ] Verify volunteer auto-signed up
6. [ ] Verify auto-signup notification sent
7. [ ] Create opportunity during blackout period
8. [ ] Verify volunteer NOT auto-signed up
9. [ ] Navigate to FriendShyft → Advanced Scheduling → Auto-Signup Log
10. [ ] Verify success/failure logs with reasons
11. [ ] Export auto-signup log to CSV

---

## Phase 12: Analytics & Insights (Session 9)

### Predictive Analytics
1. [ ] Navigate to FriendShyft → Analytics → Predictive Analytics
2. [ ] Verify upcoming opportunities table (next 30 days)
3. [ ] Check predicted signups for each opportunity
4. [ ] Verify confidence scores displayed (0-100%)
5. [ ] Check no-show risk indicators
6. [ ] Review shift optimization insights:
   - [ ] Best day of week
   - [ ] Average fill rate
   - [ ] Optimal lead time
7. [ ] Select different program from dropdown
8. [ ] Verify optimization updates for selected program
9. [ ] Trigger prediction cron manually:
```bash
wp cron event run fs_update_predictions_cron
```
10. [ ] Verify predictions updated

### Impact Metrics
1. [ ] Navigate to FriendShyft → Analytics → Impact Metrics
2. [ ] Set date range (e.g., Year to date)
3. [ ] Click "Update Report"
4. [ ] Verify statistics display correctly:
   - [ ] Total volunteer hours
   - [ ] Unique volunteers
   - [ ] Economic value (@ $31.80/hour)
   - [ ] Retention rate
   - [ ] Total shifts
   - [ ] Active programs
5. [ ] Review hours by program table
6. [ ] Verify calculations correct (avg hours per shift, etc.)
7. [ ] Review donor-ready key messages
8. [ ] Click "Export CSV Report"
9. [ ] Verify CSV downloads with proper formatting
10. [ ] Open CSV in Excel - verify UTF-8 encoding correct

### Volunteer Retention Analytics
1. [ ] Navigate to FriendShyft → Analytics → Volunteer Retention
2. [ ] Review engagement statistics:
   - [ ] Average engagement score
   - [ ] High/medium/low risk counts
   - [ ] Engagement trends (improving/declining/stable)
3. [ ] Review high-risk volunteers table:
   - [ ] Check engagement scores (0-100)
   - [ ] Verify trend indicators
   - [ ] Check days inactive
4. [ ] Click "Send Re-engagement" button for high-risk volunteer
5. [ ] Verify re-engagement email sent successfully
6. [ ] Check volunteer's email for re-engagement campaign
7. [ ] Review medium-risk volunteers table
8. [ ] Trigger engagement score cron manually:
```bash
wp cron event run fs_update_engagement_scores_cron
```
9. [ ] Verify scores updated
10. [ ] Trigger re-engagement campaign cron:
```bash
wp cron event run fs_send_reengagement_campaigns_cron
```
11. [ ] Verify campaigns sent (check email log)
12. [ ] Check audit log for analytics events

---

## Phase 13: Volunteer Portal (Public-Facing)

### Portal Access
1. [X] Create test page with shortcode: `[volunteer_portal]`
2. [X] Navigate to portal URL with token:
   - `http://friendshyft.local/volunteer-portal/?token=JOHNS_ACCESS_TOKEN`
3. [X] Verify volunteer dashboard loads
4. [X] Verify volunteer name displayed correctly
5. [X] Check no PHP errors in debug.log

### Portal Features
1. [X] **Dashboard Tab:**
   - [X] Verify upcoming signups displayed
   - [X] Check hours summary (total, this month, this year)
   - [X] View badges earned
2. [X] **Opportunities Tab:**
   - [X] Browse available opportunities
   - [ ] Filter by program
   - [ ] Search by keyword
   - [X] Sign up for opportunity
   - [X] Verify confirmation message
3. [ ] **My Schedule Tab:**
   - [ ] View all signups (upcoming and past)
   - [ ] Cancel signup
   - [ ] Verify cancellation notification
   - [ ] Export schedule to iCal
   - [ ] Verify iCal file downloads
4. [ ] **Profile Tab:**
   - [X] Update personal information
   - [ ] Change preferences
   - [ ] View QR code
   - [ ] Download QR code image
5. [ ] **History Tab:**
   - [X] View completed shifts
   - [X] Check hours logged per shift
   - [ ] Filter by date range
6. [ ] **Google Calendar Tab (if connected):**
   - [ ] Connect Google Calendar
   - [ ] View sync status
   - [ ] Disconnect Google Calendar
7. [ ] **Feedback Tab:**
   - [ ] Submit suggestion
   - [ ] View suggestion status
   - [ ] Submit testimonial
8. [ ] **Availability Tab (Recurring Schedules):**
   - [ ] Set weekly availability
   - [ ] Toggle auto-signup
   - [ ] Add blackout dates
   - [ ] Remove blackout dates

---

## Phase 14: POC (Point of Contact) Features

### POC Dashboard
1. [X] Assign POC role to admin user
2. [X] Navigate to FriendShyft → POC Dashboard
3. [X] Verify opportunities for POC's programs displayed
4. [X] Check signup counts and fill status
5. [X] View volunteer details for signups
6. [ ] Send reminder to volunteers

### POC Calendar View
1. [X] Navigate to FriendShyft → POC Calendar
2. [X] Verify calendar displays opportunities
3. [X] Click on opportunity in calendar
4. [X] View opportunity details popup
5. [X] Manage signups from calendar view

### POC Reports
1. [X] Navigate to FriendShyft → POC Reports
2. [X] Generate volunteer hours report
3. [ ] Filter by date range
4. [ ] Export to CSV
5. [ ] Verify CSV formatting correct

---

## Phase 15: Activity Reports & Audit Log

### Activity Reports
1. [X] Navigate to FriendShyft → Activity Reports
2. [X] Select report type:
   - [ ] Volunteer hours by program
   - [ ] Signup trends
   - [ ] No-show rates
3. [ ] Set date range
4. [ ] Generate report
5. [ ] Export to CSV
6. [ ] Verify calculations correct

### Audit Log
1. [ ] Navigate to FriendShyft → Audit Log
2. [ ] Filter by action type (volunteer_created, signup_created, etc.)
3. [ ] Filter by date range
4. [ ] Search by user
5. [ ] View detailed log entry
6. [ ] Verify JSON details captured correctly
7. [ ] Export audit log to CSV

---

## Phase 16: Edge Cases & Error Handling

### Data Validation
1. [ ] Test invalid email format in volunteer creation
2. [ ] Test duplicate email address
3. [ ] Test invalid date formats
4. [ ] Test negative numbers for spots_available
5. [ ] Test SQL injection attempts (should be sanitized)
6. [ ] Test XSS attempts (should be escaped)

### Permission Checks
1. [ ] Log in as subscriber role
2. [ ] Attempt to access FriendShyft admin pages
3. [ ] Verify access denied
4. [ ] Attempt AJAX actions without nonce
5. [ ] Verify nonce verification failures

### Conflict Detection
1. [ ] Sign up volunteer for overlapping opportunities
2. [ ] Sign up volunteer during blackout period
3. [ ] Sign up volunteer without required role
4. [ ] Sign up volunteer under minimum age
5. [ ] Sign up for full opportunity (should add to waitlist)

### Email Failures
1. [ ] Temporarily break wp_mail (return false)
2. [ ] Attempt to send notification
3. [ ] Verify error logged
4. [ ] Verify graceful failure (no PHP fatal errors)

### Cron Job Failures
1. [ ] Check debug.log after each cron run
2. [ ] Verify no PHP errors
3. [ ] Verify WP_DEBUG logs show completion messages

---

## Phase 17: Performance Testing

### Database Query Performance
1. [ ] Create 100 test volunteers
2. [ ] Create 50 test opportunities
3. [ ] Create 200 test signups
4. [ ] Navigate to volunteer list
5. [ ] Check page load time (<2 seconds expected)
6. [ ] Navigate to opportunities list
7. [ ] Check page load time
8. [ ] Run analytics queries
9. [ ] Verify query times reasonable (<3 seconds)

### Portal Load Testing
1. [ ] Access portal with token
2. [ ] Check page load time
3. [ ] Navigate between tabs
4. [ ] Verify smooth transitions
5. [ ] Check browser console for errors

---

## Phase 18: Final Verification

### Plugin Deactivation
1. [ ] Deactivate FriendShyft plugin
2. [ ] Verify all cron jobs cleared
3. [ ] Verify no PHP errors
4. [ ] Reactivate plugin
5. [ ] Verify cron jobs re-scheduled
6. [ ] Verify all data intact

### WordPress Compatibility
1. [ ] Test with WordPress Multisite (if applicable)
2. [ ] Test with different themes
3. [ ] Test with common plugins (Yoast SEO, etc.)
4. [ ] Verify no JavaScript conflicts
5. [ ] Verify no CSS conflicts

### Browser Compatibility
1. [ ] Test in Chrome
2. [ ] Test in Firefox
3. [ ] Test in Safari
4. [ ] Test in Edge
5. [ ] Test on mobile devices (iOS/Android)

---

## Test Results Summary

### Pass/Fail Criteria
- **Critical:** All core features (volunteers, opportunities, signups, time tracking) must work
- **High Priority:** Advanced features (teams, analytics, scheduling) must work
- **Medium Priority:** Optional features (Google Calendar, feedback) should work
- **Low Priority:** Nice-to-have features can have minor issues

### Issues Log
Create a spreadsheet or document to track:
- Issue description
- Steps to reproduce
- Expected vs. actual behavior
- Severity (critical/high/medium/low)
- Status (open/fixed/wontfix)
- File location (for debugging)

---

## Post-Testing

### Debug Log Review
1. [ ] Review complete debug.log file
2. [ ] Check for PHP warnings
3. [ ] Check for PHP notices
4. [ ] Check for deprecated function calls
5. [ ] Resolve all critical errors

### Database Cleanup
1. [ ] Remove test volunteers
2. [ ] Remove test opportunities
3. [ ] Remove test signups
4. [ ] OR keep test data for demo purposes

### Documentation Updates
1. [ ] Update README.md with any new findings
2. [ ] Update CLAUDE.md with any architecture changes
3. [ ] Update TODO.md with remaining issues
4. [ ] Create user documentation (if needed)

---

## Quick Test Script (For Rapid Verification)

For quick smoke testing after code changes:

```bash
# 1. Activate plugin
wp plugin activate friendshyft

# 2. Check tables exist
wp db query "SHOW TABLES LIKE 'wp_fs_%'"

# 3. Check cron jobs
wp cron event list | grep fs_

# 4. Create test volunteer
# (Manual via admin UI)

# 5. Create test opportunity
# (Manual via admin UI)

# 6. Create test signup
# (Manual via admin UI)

# 7. Check debug log
tail -f wp-content/debug.log

# 8. Run cron jobs manually
wp cron event run fs_update_predictions_cron
wp cron event run fs_update_engagement_scores_cron

# 9. Deactivate and reactivate
wp plugin deactivate friendshyft
wp plugin activate friendshyft
```

---

## Automated Testing (Future Enhancement)

Consider adding PHPUnit tests for:
- [ ] Database schema creation
- [ ] Volunteer CRUD operations
- [ ] Opportunity CRUD operations
- [ ] Signup logic and conflict detection
- [ ] Time tracking calculations
- [ ] Badge awarding logic
- [ ] Analytics calculations
- [ ] Email sending (mock)

---

**End of Testing Plan**

This comprehensive plan ensures all features across all 9 development sessions are thoroughly tested. Execute tests in order for best results.
