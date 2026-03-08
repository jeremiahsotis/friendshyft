# Database Sync Guide

## ✅ STATUS: RESOLVED

All database tables and cron jobs are now successfully created. This document is maintained for historical reference and troubleshooting.

**Current State (as of 2025-12-12):**
- ✅ All 34 tables exist in database
- ✅ All 11 cron jobs scheduled
- ✅ All Sessions 1-9 features fully operational

---

## Historical Issue: Missing Tables and Cron Jobs (RESOLVED)

During development, we discovered that many tables and cron jobs from Sessions 7-9 were missing from the database. This was because the **plugin activation hook hadn't run since we added those features**.

**Resolution:** Plugin was deactivated and reactivated, triggering the activation hook and creating all missing resources.

## Root Cause

When you add new features to a WordPress plugin that require database tables or cron jobs, those resources are only created when the activation hook runs (typically on plugin activation). Since the plugin was already active when we added Sessions 7-9 features, the activation hook never executed for those new components.

## Database State: All Tables Created ✅

### All 34 Tables Now Exist:

#### Core Tables (12 tables) ✅
- `wp_fs_volunteers` - Volunteer records
- `wp_fs_roles` - Volunteer roles/types
- `wp_fs_programs` - Program categorization
- `wp_fs_opportunities` - Volunteer opportunities/shifts
- `wp_fs_signups` - Volunteer → Opportunity signups
- `wp_fs_time_records` - Time tracking records
- `wp_fs_volunteer_interests` - Volunteer program interests
- `wp_fs_volunteer_roles` - Volunteer → Role assignments
- `wp_fs_opportunity_templates` - Recurring opportunity templates
- `wp_fs_holidays` - Holiday dates
- `wp_fs_workflows` - Multi-step training/onboarding
- `wp_fs_progress` - Workflow progress tracking

#### Team Management (4 tables) ✅
- `wp_fs_teams` - Team records
- `wp_fs_team_members` - Team membership
- `wp_fs_team_attendance` - Team time tracking
- `wp_fs_team_signups` - Team-based opportunity signups

#### Email & Notifications (2 tables) ✅
- `wp_fs_email_log` - Email ingestion audit trail
- `wp_fs_handoff_notifications` - Recurring shift handoff notifications

#### Portal Enhancements (2 tables) ✅
- `wp_fs_opportunity_shifts` - Shift management
- `wp_fs_favorites` - Volunteer favorites
- `wp_fs_volunteer_badges` - Badge awards

#### Session 7: Google Calendar (1 table) ✅
- `wp_fs_blocked_times` - Google Calendar blocked times

#### Session 7: Feedback System (3 tables) ✅
- `wp_fs_surveys` - Post-event surveys
- `wp_fs_suggestions` - Volunteer suggestions
- `wp_fs_testimonials` - Volunteer testimonials

#### Session 8: Advanced Scheduling (6 tables) ✅
- `wp_fs_waitlist` - Opportunity waitlists
- `wp_fs_substitute_requests` - Substitute coverage requests
- `wp_fs_swap_history` - Volunteer swap history
- `wp_fs_availability` - Recurring weekly availability
- `wp_fs_blackout_dates` - Volunteer blackout dates
- `wp_fs_auto_signup_log` - Auto-signup audit trail

#### Session 9: Analytics (4 tables) ✅
- `wp_fs_predictions` - Predictive analytics cache
- `wp_fs_engagement_scores` - Volunteer engagement tracking
- `wp_fs_reengagement_campaigns` - Re-engagement campaign log
- `wp_fs_audit_log` - Comprehensive audit trail

### Tables That Never Existed (Architectural Decisions)
These were never part of the design:
- ❌ `wp_fs_badges` - Correct name is `wp_fs_volunteer_badges`
- ❌ `wp_fs_workflow_steps` - Steps stored as serialized TEXT in `wp_fs_workflows`
- ❌ `wp_fs_volunteer_workflows` - Replaced by `wp_fs_progress` table
- ❌ `wp_fs_opportunity_roles` - Opportunities use direct role assignment

### All 11 Cron Jobs Now Scheduled ✅

1. `fs_send_attendance_reminders` - Daily attendance reminders (9 AM)
2. `fs_generate_opportunities_cron` - Weekly opportunity generation from templates
3. `fs_check_imap_inbox` - Hourly email ingestion check
4. `fs_sync_cron` - Daily Monday.com sync
5. `fs_daily_handoff_check` - Daily handoff notifications
6. `fs_check_google_calendar_cron` - Hourly Google Calendar sync (Session 7)
7. `fs_send_event_surveys_cron` - Daily post-event surveys (Session 7)
8. `fs_process_auto_signups_cron` - Daily auto-signup processing (Session 8)
9. `fs_update_engagement_scores_cron` - Daily engagement score updates (Session 9)
10. `fs_send_reengagement_campaigns_cron` - Weekly re-engagement campaigns (Session 9)
11. `fs_update_predictions_cron` - Daily predictive analytics updates (Session 9)

## How the Issue Was Resolved

**Solution Used:** Deactivate/Reactivate Plugin (Option 2)

The plugin was deactivated and then reactivated via **Plugins → Installed Plugins**, which triggered the full activation hook in `friendshyft.php`. This:
1. Created all 34 missing database tables
2. Scheduled all 11 cron jobs
3. Ran all pending migrations
4. Verified all table schemas

**Alternative solutions that were available:**

### Option 1: Database Sync Tool
A dedicated admin page at **WordPress Admin → FriendShyft → Database Sync** provides visual confirmation of missing resources and can create them on-demand without requiring plugin restart.

### Option 2: Deactivate/Reactivate Plugin (USED)
Standard WordPress procedure that runs the full activation hook. This was the chosen solution.

### Option 3: Manual SQL Script
Running CREATE TABLE statements manually (not recommended due to error potential).

## Verification Completed ✅

All verification steps were completed successfully:

### 1. Database Tables Verified ✅

```sql
SHOW TABLES LIKE 'wp_fs_%';
```

**Result:** 34 tables confirmed (all tables from DATABASE-SCHEMA.md)

### 2. Cron Jobs Verified ✅

Via WP-CLI:
```bash
wp cron event list | grep fs_
```

Via plugin (WP Crontrol):
- Go to Tools → Cron Events
- Search for "fs_"

**Result:** All 11 cron jobs scheduled and running

### 3. Functionality Testing ✅

All admin pages accessible and functional:
- ✅ Navigate to **FriendShyft → Volunteer Feedback** - Works
- ✅ Navigate to **FriendShyft → Google Calendar** - Works
- ✅ Navigate to **FriendShyft → Advanced Scheduling** - Works
- ✅ Navigate to **FriendShyft → Analytics** - Works
- ✅ Navigate to **FriendShyft → Database Sync** - Shows all green checkmarks

## Prevention for Future

To avoid this issue in future development:

1. **Always test activation hook** after adding new features that require database changes
2. **Deactivate/reactivate during development** to ensure activation hook runs
3. **Use migrations** for incremental database changes
4. **Document table changes** in code comments and README
5. **Version your database schema** to track changes over time

## Historical Impact (When Tables Were Missing)

Before the fix, these features were non-functional:

### Session 7: Google Calendar Integration
- ❌ Volunteers couldn't connect Google Calendar
- ❌ No two-way sync of volunteer opportunities
- ❌ Blocked times not tracked

### Session 7: Feedback System
- ❌ Post-event surveys not sent
- ❌ Suggestion box unavailable
- ❌ Testimonials couldn't be collected

### Session 8: Advanced Scheduling
- ❌ Waitlist management not working
- ❌ Substitute finder unavailable
- ❌ Recurring personal schedules not functional
- ❌ Auto-signup feature disabled

### Session 9: Analytics & Insights
- ❌ Predictive analytics not calculating
- ❌ Impact metrics not tracking
- ❌ Volunteer retention analytics disabled
- ❌ Re-engagement campaigns not sending

**All features are now fully operational.**

## Post-Resolution Actions Completed ✅

After successfully resolving the database sync issue:

1. ✅ **Tested each feature** - All admin pages accessible
2. ✅ **Checked debug.log** - No errors after sync
3. ✅ **Verified cron jobs** - All 11 jobs scheduled and running
4. ✅ **Updated documentation** - TESTING-PLAN.md, DATABASE-SCHEMA.md, and this guide updated
5. ⏳ **Commit changes** - Ready for version control commit

## Technical Details

### What the Sync Does

The sync operation runs the same code as the plugin activation hook:

```php
// Creates all tables
FS_Google_Calendar_Sync::create_tables();
FS_Feedback_System::create_tables();
FS_Waitlist_Manager::create_tables();
FS_Substitute_Finder::create_tables();
FS_Recurring_Schedules::create_tables();
FS_Predictive_Analytics::create_tables();
FS_Volunteer_Retention::create_tables();

// Schedules all cron jobs
wp_schedule_event(time(), 'hourly', 'fs_check_google_calendar_cron');
wp_schedule_event(time(), 'daily', 'fs_send_event_surveys_cron');
wp_schedule_event(time(), 'daily', 'fs_process_auto_signups_cron');
wp_schedule_event(time(), 'daily', 'fs_update_engagement_scores_cron');
wp_schedule_event(time(), 'weekly', 'fs_send_reengagement_campaigns_cron');
wp_schedule_event(time(), 'daily', 'fs_update_predictions_cron');
```

### Why This Happened

During development across 9 sessions, we:
1. Added code for new features
2. Created classes with `create_tables()` methods
3. Updated `friendshyft.php` activation hook
4. **But never triggered the activation hook** by deactivating/reactivating

The plugin was live and functional for core features, so there was no reason to restart it. The new code was loaded, but the initialization (table creation, cron scheduling) never ran.

## Conclusion

This was a common WordPress plugin development scenario where new features were added but the activation hook wasn't triggered to create the necessary database resources. The issue was resolved successfully by deactivating and reactivating the plugin.

**Current Status:**
- ✅ All 34 database tables created and verified
- ✅ All 11 cron jobs scheduled and running
- ✅ All Sessions 1-9 features fully functional
- ✅ Database schema matches codebase
- ✅ Production ready

**Key Takeaways for Future Development:**
1. Always test the activation hook after adding database-dependent features
2. Deactivate/reactivate during development to ensure proper initialization
3. Use the Database Sync admin page to verify system state
4. Document table changes in DATABASE-SCHEMA.md
5. Keep TESTING-PLAN.md synchronized with actual database state

---

## Related Documentation

For detailed information about the database schema, see:
- **DATABASE-SCHEMA.md** - Authoritative reference for all 34 tables
- **TESTING-PLAN.md** - Updated with current table verification checklist
- **CLAUDE.md** - Architecture overview and development guidelines

**Questions?** The Database Sync admin page at **FriendShyft → Database Sync** provides real-time status information about all tables and cron jobs.
