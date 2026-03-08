-- FriendShyft Database Migrations v1.7 and v1.8
-- Run this file to manually add the required columns

-- Migration 1.7: Add email fields to programs table
ALTER TABLE wp_fs_programs
ADD COLUMN email_description TEXT DEFAULT NULL AFTER long_description;

ALTER TABLE wp_fs_programs
ADD COLUMN schedule_days VARCHAR(255) DEFAULT NULL AFTER email_description;

ALTER TABLE wp_fs_programs
ADD COLUMN schedule_times VARCHAR(255) DEFAULT NULL AFTER schedule_days;

-- Migration 1.8: Add availability and preferred_contact to volunteers table
ALTER TABLE wp_fs_volunteers
ADD COLUMN availability TEXT DEFAULT NULL AFTER notes;

ALTER TABLE wp_fs_volunteers
ADD COLUMN preferred_contact VARCHAR(20) DEFAULT 'email' AFTER availability;

-- Update version number
UPDATE wp_options SET option_value = '1.8' WHERE option_name = 'friendshyft_db_version';
