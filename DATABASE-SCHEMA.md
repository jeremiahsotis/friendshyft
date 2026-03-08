# FriendShyft Database Schema Documentation

**Version:** 1.1.0
**Last Updated:** 2025-12-12
**Total Tables:** 34

This document provides the authoritative reference for all database tables in the FriendShyft plugin.

---

## Table Overview

All tables use the WordPress prefix convention: `{$wpdb->prefix}fs_*` (typically `wp_fs_*`)

### Tables by Category:
- **Core Tables:** 12
- **Team Management:** 4
- **Badges & Achievements:** 1
- **Email & Notifications:** 2
- **Portal Enhancements:** 2
- **Google Calendar Integration:** 1
- **Feedback System:** 3
- **Advanced Scheduling:** 6
- **Analytics & Insights:** 4

---

## Core Tables (12)

### 1. `wp_fs_volunteers`
**Purpose:** Central volunteer records
**Created By:** `FS_Database::create_tables()` (includes/class-database.php:24)
**Key Columns:**
- `id` - Primary key
- `monday_id` - Integration with Monday.com
- `name`, `email`, `phone` - Contact information
- `access_token` - 64-character magic link token for portal access
- `pin` - 4-6 digit PIN for kiosk check-in
- `qr_code` - QR code for kiosk check-in
- `volunteer_status` - Active/Inactive/Pending
- `birthdate` - For age eligibility checks
- `background_check_status`, `background_check_date`, `background_check_expiration` - Compliance tracking
- `emergency_contact_name`, `emergency_contact_phone`, `emergency_contact_relationship` - Emergency info

**Indexes:** `monday_id`, `email`, `access_token` (UNIQUE), `volunteer_status`, `pin`, `qr_code`, `wp_user_id`

---

### 2. `wp_fs_roles`
**Purpose:** Volunteer role definitions (e.g., "Food Handler", "Mentor")
**Created By:** `FS_Database::create_tables()` (includes/class-database.php:57)
**Key Columns:**
- `id` - Primary key
- `monday_id` - Integration with Monday.com
- `name`, `description` - Role details
- `program_id` - Links to programs table
- `status` - Active/Inactive

**Indexes:** `monday_id`, `program_id`, `status`

---

### 3. `wp_fs_programs`
**Purpose:** Program categorization (e.g., "Food Distribution", "Youth Mentoring")
**Created By:** `FS_Database::create_tables()` (includes/class-database.php:165)
**Key Columns:**
- `id` - Primary key
- `monday_id` - Integration with Monday.com
- `name`, `short_description`, `long_description` - Program details
- `active_status` - Active/Inactive
- `display_order` - Sort order for public display

**Indexes:** `monday_id`, `active_status`, `display_order`

---

### 4. `wp_fs_opportunities`
**Purpose:** Volunteer opportunities/shifts
**Created By:** `FS_Database::create_tables()` (includes/class-database.php:120)
**Key Columns:**
- `id` - Primary key
- `monday_id` - Integration with Monday.com
- `template_id` - Links to opportunity templates (for recurring opportunities)
- `title`, `description`, `location` - Opportunity details
- `event_date` - Date of opportunity
- `datetime_start`, `datetime_end` - Start and end times
- `spots_available`, `spots_filled` - Capacity tracking
- `requirements` - Text description of requirements
- `status` - Open/Closed/Cancelled

**Indexes:** `monday_id`, `template_id`, `event_date`, `status`

---

### 5. `wp_fs_signups`
**Purpose:** Volunteer → Opportunity signups
**Created By:** `FS_Database::create_tables()` (includes/class-database.php:145)
**Key Columns:**
- `id` - Primary key
- `opportunity_id`, `volunteer_id` - Foreign keys
- `status` - confirmed/cancelled/no_show/needs_merge
- `signup_date`, `cancelled_date` - Timestamps
- `attendance_confirmed` - Boolean for attendance tracking
- `confirmation_date` - When attendance was confirmed
- `reminder_sent` - Boolean for reminder email tracking
- `notes` - Admin notes

**Indexes:** `opportunity_id`, `volunteer_id`, `status`, `attendance_confirmed`

---

### 6. `wp_fs_time_records`
**Purpose:** Individual volunteer time tracking (check-in/check-out)
**Created By:** `FS_Time_Tracking::create_tables()` (includes/class-time-tracking.php)
**Key Columns:**
- `id` - Primary key
- `volunteer_id`, `opportunity_id` - Foreign keys
- `check_in_time`, `check_out_time` - Timestamps
- `hours` - Calculated hours worked
- `method` - pin/qr_code/manual
- `notes` - Admin notes

**Indexes:** `volunteer_id`, `opportunity_id`

---

### 7. `wp_fs_volunteer_interests`
**Purpose:** Volunteer program interests (captured during registration)
**Created By:** `FS_Email_Ingestion_Migration::run()` (fs-email-ingestion-migration.php:65)
**Key Columns:**
- `id` - Primary key
- `volunteer_id`, `program_id` - Foreign keys
- `interest_level` - high/medium/low
- `created_date` - When interest was captured

**Indexes:** `volunteer_id`, `program_id`

---

### 8. `wp_fs_volunteer_roles`
**Purpose:** Volunteer → Role junction table (many-to-many)
**Created By:** `FS_Database::create_tables()` (includes/class-database.php:73)
**Key Columns:**
- `id` - Primary key
- `volunteer_id`, `role_id` - Foreign keys
- `monday_connection_id` - Integration with Monday.com
- `assigned_date` - When role was assigned

**Indexes:** `volunteer_id`, `role_id`, UNIQUE(`volunteer_id`, `role_id`)

---

### 9. `wp_fs_opportunity_templates`
**Purpose:** Recurring opportunity templates (e.g., "Weekly Food Distribution")
**Created By:** `FS_Opportunity_Templates::create_tables()` (includes/class-opportunity-templates.php)
**Key Columns:**
- `id` - Primary key
- `title`, `description`, `location` - Template details
- `program_id` - Links to programs
- `recurrence_type` - weekly/biweekly/monthly/custom
- `recurrence_days` - Serialized array of days (e.g., [1,3,5] for Mon/Wed/Fri)
- `time_start`, `time_end` - Default times
- `spots_available` - Default capacity
- `active` - Boolean for enabled/disabled

**Indexes:** `program_id`, `active`

---

### 10. `wp_fs_holidays`
**Purpose:** Holiday dates (no opportunities generated on these dates)
**Created By:** Template migration or admin UI
**Key Columns:**
- `id` - Primary key
- `holiday_date` - Date of holiday
- `holiday_name` - Name of holiday

**Indexes:** `holiday_date`

---

### 11. `wp_fs_workflows`
**Purpose:** Multi-step training/onboarding workflows
**Created By:** `FS_Database::create_tables()` (includes/class-database.php:87)
**Key Columns:**
- `id` - Primary key
- `monday_id` - Integration with Monday.com
- `name`, `description` - Workflow details
- `steps` - Serialized TEXT field containing step definitions
- `status` - Active/Inactive

**Indexes:** `monday_id`, `status`

**Note:** Steps are stored as serialized data in the `steps` column, not in a separate table.

---

### 12. `wp_fs_progress`
**Purpose:** Volunteer workflow progress tracking
**Created By:** `FS_Database::create_tables()` (includes/class-database.php:102)
**Key Columns:**
- `id` - Primary key
- `monday_id` - Integration with Monday.com
- `volunteer_id`, `workflow_id` - Foreign keys
- `overall_status` - in_progress/completed
- `progress_percentage` - 0-100
- `step_completions` - Serialized TEXT field containing completion data
- `completed` - Boolean

**Indexes:** `monday_id`, `volunteer_id`, `workflow_id`

**Note:** This table replaces the concept of separate `wp_fs_workflow_steps` and `wp_fs_volunteer_workflows` tables.

---

## Team Management Tables (4)

### 13. `wp_fs_teams`
**Purpose:** Team definitions (e.g., "Community Church Group")
**Created By:** `FS_Team_Management_Migration::run()` (fs-team-management-migration.php)
**Key Columns:**
- `id` - Primary key
- `name`, `description` - Team details
- `leader_id` - Foreign key to volunteers (team leader)
- `status` - active/inactive
- `created_date` - When team was created

**Indexes:** `leader_id`, `status`

---

### 14. `wp_fs_team_members`
**Purpose:** Team membership (which volunteers are on which teams)
**Created By:** `FS_Team_Management_Migration::run()` (fs-team-management-migration.php)
**Key Columns:**
- `id` - Primary key
- `team_id`, `volunteer_id` - Foreign keys
- `joined_date` - When volunteer joined team
- `status` - active/inactive

**Indexes:** `team_id`, `volunteer_id`

---

### 15. `wp_fs_team_attendance`
**Purpose:** Team-based time tracking (entire team checks in together)
**Created By:** `FS_Team_Management_Migration::run()` (fs-team-management-migration.php)
**Key Columns:**
- `id` - Primary key
- `team_id`, `opportunity_id` - Foreign keys
- `check_in_time`, `check_out_time` - Timestamps
- `people_count` - Number of team members present
- `hours_per_person` - Calculated hours per person
- `total_hours` - people_count × hours_per_person
- `checked_in_by` - Foreign key to volunteer (team leader)

**Indexes:** `team_id`, `opportunity_id`

---

### 16. `wp_fs_team_signups`
**Purpose:** Team-based opportunity signups
**Created By:** `FS_Team_Management_Migration::run()` (fs-team-management-migration.php)
**Key Columns:**
- `id` - Primary key
- `team_id`, `opportunity_id` - Foreign keys
- `estimated_count` - Estimated number of team members attending
- `status` - confirmed/cancelled
- `signup_date`, `cancelled_date` - Timestamps

**Indexes:** `team_id`, `opportunity_id`, `status`

---

## Badges & Achievements (1)

### 17. `wp_fs_volunteer_badges`
**Purpose:** Badge/achievement awards for volunteers
**Created By:** `FS_Badges::create_tables()` (includes/class-badges.php:37)
**Key Columns:**
- `id` - Primary key
- `volunteer_id` - Foreign key
- `badge_type` - hours/signups/workflow/special
- `badge_level` - bronze/silver/gold/platinum or specific milestone (10hr, 50hr, 100hr)
- `earned_date` - When badge was earned
- `notification_sent` - Boolean for email notification tracking

**Indexes:** `volunteer_id`, `badge_type`, UNIQUE(`volunteer_id`, `badge_type`, `badge_level`)

**Note:** Table name is `wp_fs_volunteer_badges`, NOT `wp_fs_badges`.

---

## Email & Notifications (2)

### 18. `wp_fs_email_log`
**Purpose:** Audit log for email ingestion (volunteer interest emails)
**Created By:** `FS_Email_Ingestion_Migration::run()` (fs-email-ingestion-migration.php)
**Key Columns:**
- `id` - Primary key
- `raw_email` - Full raw email content
- `parsed_data` - Serialized parsed volunteer data
- `volunteer_id` - Foreign key (if volunteer was created)
- `status` - success/error
- `error_message` - Error details if status=error
- `source` - api/imap
- `processed_date` - Timestamp

**Indexes:** `volunteer_id`, `status`, `processed_date`

---

### 19. `wp_fs_handoff_notifications`
**Purpose:** Tracks handoff notifications for recurring shifts
**Created By:** `FS_Database::create_tables()` (includes/class-database.php:182)
**Key Columns:**
- `id` - Primary key
- `template_id` - Foreign key to opportunity templates
- `period_start`, `period_end` - Date range for recurring period
- `volunteer_id`, `next_volunteer_id` - Foreign keys (handoff from → to)
- `notification_type` - period_start/period_end
- `sent_date` - When notification was sent

**Indexes:** Composite index on (`template_id`, `period_start`, `volunteer_id`, `notification_type`)

---

## Portal Enhancements (2)

### 20. `wp_fs_opportunity_shifts`
**Purpose:** Shift management within opportunities (break opportunities into shifts)
**Created By:** `FS_Portal_Enhancements::create_favorites_table()` (public/class-portal-enhancements.php)
**Key Columns:**
- `id` - Primary key
- `opportunity_id` - Foreign key
- `shift_name` - Name of shift (e.g., "Morning Shift", "Afternoon Shift")
- `start_time`, `end_time` - Shift times
- `spots_available`, `spots_filled` - Capacity per shift

**Indexes:** `opportunity_id`

---

### 21. `wp_fs_favorites`
**Purpose:** Volunteer favorite opportunities (for quick access in portal)
**Created By:** `FS_Portal_Enhancements::create_favorites_table()` (public/class-portal-enhancements.php)
**Key Columns:**
- `id` - Primary key
- `volunteer_id`, `opportunity_id` - Foreign keys
- `created_date` - When favorited

**Indexes:** `volunteer_id`, `opportunity_id`, UNIQUE(`volunteer_id`, `opportunity_id`)

---

## Google Calendar Integration (1)

### 22. `wp_fs_blocked_times`
**Purpose:** Synced blocked times from volunteer's Google Calendar
**Created By:** `FS_Google_Calendar_Sync::create_tables()` (includes/class-google-calendar-sync.php)
**Key Columns:**
- `id` - Primary key
- `volunteer_id` - Foreign key
- `google_event_id` - External event ID from Google Calendar
- `event_title` - Title of blocking event
- `start_time`, `end_time` - Blocked time range
- `all_day` - Boolean for all-day events
- `synced_at` - Last sync timestamp

**Indexes:** `volunteer_id`, `google_event_id`, `start_time`, `end_time`

---

## Feedback System (3)

### 23. `wp_fs_surveys`
**Purpose:** Post-event surveys from volunteers
**Created By:** `FS_Feedback_System::create_tables()` (includes/class-feedback-system.php)
**Key Columns:**
- `id` - Primary key
- `volunteer_id`, `opportunity_id` - Foreign keys
- `rating` - 1-5 stars
- `comments` - Free-text feedback
- `submitted_date` - Timestamp
- `status` - pending/reviewed

**Indexes:** `volunteer_id`, `opportunity_id`, `rating`, `submitted_date`

---

### 24. `wp_fs_suggestions`
**Purpose:** Volunteer suggestions for improvements
**Created By:** `FS_Feedback_System::create_tables()` (includes/class-feedback-system.php)
**Key Columns:**
- `id` - Primary key
- `volunteer_id` - Foreign key
- `category` - scheduling/communication/training/other
- `title` - Suggestion title
- `description` - Detailed suggestion
- `status` - pending/under_review/implemented/declined
- `admin_response` - Admin's response to suggestion
- `submitted_date`, `reviewed_date` - Timestamps

**Indexes:** `volunteer_id`, `category`, `status`, `submitted_date`

---

### 25. `wp_fs_testimonials`
**Purpose:** Volunteer testimonials for public display
**Created By:** `FS_Feedback_System::create_tables()` (includes/class-feedback-system.php)
**Key Columns:**
- `id` - Primary key
- `volunteer_id` - Foreign key
- `content` - Testimonial text
- `permission_to_publish` - Boolean
- `status` - pending/approved/declined
- `submitted_date`, `published_date` - Timestamps

**Indexes:** `volunteer_id`, `status`, `permission_to_publish`

---

## Advanced Scheduling (6)

### 26. `wp_fs_waitlist`
**Purpose:** Waitlist for full opportunities
**Created By:** `FS_Waitlist_Manager::create_tables()` (includes/class-waitlist-manager.php)
**Key Columns:**
- `id` - Primary key
- `opportunity_id`, `volunteer_id` - Foreign keys
- `added_date` - When added to waitlist
- `rank` - Calculated rank based on volunteer history
- `rank_score` - Numeric score for ranking
- `status` - waiting/promoted/expired
- `promoted_date` - When promoted from waitlist
- `notified` - Boolean for notification tracking

**Indexes:** `opportunity_id`, `volunteer_id`, `rank`, `status`

---

### 27. `wp_fs_substitute_requests`
**Purpose:** Substitute/coverage requests for shifts
**Created By:** `FS_Substitute_Finder::create_tables()` (includes/class-substitute-finder.php)
**Key Columns:**
- `id` - Primary key
- `signup_id` - Foreign key (original signup needing coverage)
- `volunteer_id` - Foreign key (volunteer requesting substitute)
- `opportunity_id` - Foreign key
- `requested_date` - When request was made
- `reason` - Optional reason for needing substitute
- `status` - pending/filled/cancelled/expired
- `filled_by_volunteer_id` - Foreign key (substitute volunteer)
- `filled_date` - When substitute was found

**Indexes:** `signup_id`, `volunteer_id`, `opportunity_id`, `status`, `filled_by_volunteer_id`

---

### 28. `wp_fs_swap_history`
**Purpose:** Audit trail of shift swaps/substitutions
**Created By:** `FS_Substitute_Finder::create_tables()` (includes/class-substitute-finder.php)
**Key Columns:**
- `id` - Primary key
- `original_signup_id`, `new_signup_id` - Foreign keys to signups
- `original_volunteer_id`, `substitute_volunteer_id` - Foreign keys to volunteers
- `opportunity_id` - Foreign key
- `swap_date` - When swap occurred
- `swap_type` - substitute/swap/emergency
- `reason` - Why swap occurred
- `admin_approved` - Boolean for admin approval

**Indexes:** `original_volunteer_id`, `substitute_volunteer_id`, `opportunity_id`, `swap_date`

---

### 29. `wp_fs_availability`
**Purpose:** Volunteer recurring availability schedules
**Created By:** `FS_Recurring_Schedules::create_tables()` (includes/class-recurring-schedules.php)
**Key Columns:**
- `id` - Primary key
- `volunteer_id` - Foreign key
- `day_of_week` - 0-6 (Sunday-Saturday)
- `start_time`, `end_time` - Available time range
- `active` - Boolean for enabled/disabled
- `auto_signup_enabled` - Boolean for automatic signup

**Indexes:** `volunteer_id`, `day_of_week`, `active`

---

### 30. `wp_fs_blackout_dates`
**Purpose:** Volunteer-specific blackout dates (unavailable periods)
**Created By:** `FS_Recurring_Schedules::create_tables()` (includes/class-recurring-schedules.php)
**Key Columns:**
- `id` - Primary key
- `volunteer_id` - Foreign key
- `start_date`, `end_date` - Blackout period
- `reason` - Optional reason (vacation, etc.)
- `created_date` - When blackout was added

**Indexes:** `volunteer_id`, `start_date`, `end_date`

---

### 31. `wp_fs_auto_signup_log`
**Purpose:** Audit log for automatic signups from recurring schedules
**Created By:** `FS_Recurring_Schedules::create_tables()` (includes/class-recurring-schedules.php)
**Key Columns:**
- `id` - Primary key
- `volunteer_id`, `opportunity_id` - Foreign keys
- `availability_id` - Foreign key to availability rule that triggered signup
- `signup_id` - Foreign key to created signup (if successful)
- `attempted_date` - When auto-signup was attempted
- `status` - success/failed/skipped
- `reason` - Why success/failed (e.g., "full", "conflict", "blackout")

**Indexes:** `volunteer_id`, `opportunity_id`, `attempted_date`, `status`

---

## Analytics & Insights (4)

### 32. `wp_fs_predictions`
**Purpose:** Predictive analytics data (ML-based predictions)
**Created By:** `FS_Predictive_Analytics::create_tables()` (includes/class-predictive-analytics.php)
**Key Columns:**
- `id` - Primary key
- `opportunity_id` - Foreign key
- `predicted_signups` - Expected number of signups
- `predicted_no_shows` - Expected no-show count
- `confidence_score` - 0-100 confidence percentage
- `factors` - Serialized array of contributing factors
- `created_date` - When prediction was made
- `actual_signups`, `actual_no_shows` - Actual outcomes (for model training)

**Indexes:** `opportunity_id`, `created_date`

---

### 33. `wp_fs_engagement_scores`
**Purpose:** Volunteer engagement scoring for retention
**Created By:** `FS_Volunteer_Retention::create_tables()` (includes/class-volunteer-retention.php)
**Key Columns:**
- `id` - Primary key
- `volunteer_id` - Foreign key
- `score` - 0-100 engagement score
- `trend` - improving/declining/stable
- `risk_level` - low/medium/high (churn risk)
- `last_signup_date` - Most recent signup
- `days_inactive` - Days since last activity
- `calculated_date` - When score was calculated
- `factors` - Serialized array of factors contributing to score

**Indexes:** `volunteer_id`, `score`, `risk_level`, `calculated_date`

---

### 34. `wp_fs_reengagement_campaigns`
**Purpose:** Tracking re-engagement campaigns sent to at-risk volunteers
**Created By:** `FS_Volunteer_Retention::create_tables()` (includes/class-volunteer-retention.php)
**Key Columns:**
- `id` - Primary key
- `volunteer_id` - Foreign key
- `campaign_type` - inactive_30/inactive_60/inactive_90/custom
- `sent_date` - When campaign was sent
- `email_opened` - Boolean (if tracking enabled)
- `email_clicked` - Boolean (if tracking enabled)
- `resulted_in_signup` - Boolean (volunteer signed up after campaign)
- `signup_date` - Date of first signup after campaign

**Indexes:** `volunteer_id`, `campaign_type`, `sent_date`, `resulted_in_signup`

---

## Deprecated / Non-Existent Tables

These tables are sometimes referenced in old documentation but **do NOT exist** and **should NOT be created**:

❌ **wp_fs_workflow_steps** - Workflows store steps as serialized TEXT in `wp_fs_workflows.steps`
❌ **wp_fs_volunteer_workflows** - Replaced by `wp_fs_progress` table
❌ **wp_fs_opportunity_roles** - Opportunities use direct role assignment via programs
❌ **wp_fs_badges** - Correct table name is `wp_fs_volunteer_badges`

---

## Database Maintenance

### Indexes
All tables have appropriate indexes for performance. Key indexes include:
- Foreign keys (volunteer_id, opportunity_id, etc.)
- Status fields (for filtering)
- Date fields (for range queries)
- Unique constraints where needed (access_token, volunteer_badge combinations, etc.)

### Charset/Collation
All tables use WordPress default charset collation via `$wpdb->get_charset_collate()`

### Table Creation
Tables are created via:
1. `dbDelta()` for core tables (allows safe schema updates)
2. Direct `CREATE TABLE IF NOT EXISTS` for feature tables
3. Migrations handle schema changes (see `includes/class-database-migrations.php`)

### Verification Query
To verify all tables exist:
```sql
SHOW TABLES LIKE 'wp_fs_%';
```

Expected result: 34 tables

---

## Related Documentation

- **TESTING-PLAN.md** - End-to-end testing procedures
- **CLAUDE.md** - Architecture overview and development guide
- **DATABASE-SYNC-GUIDE.md** - Troubleshooting missing tables
- **TODO.md** - Feature implementation status

---

**End of Database Schema Documentation**
