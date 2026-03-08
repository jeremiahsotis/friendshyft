<?php
/**
 * FriendShyft Email Ingestion - Database Migration
 * 
 * Adds:
 * - Phone fields to fs_volunteers
 * - Email ingestion log table
 * - Volunteer interests tracking
 */

class FS_Email_Ingestion_Migration {
    
    public static function run() {
        global $wpdb;

        // Get all existing columns
        $columns = $wpdb->get_col("DESCRIBE {$wpdb->prefix}fs_volunteers", 0);

        // Add phone field if it doesn't exist
        if (!in_array('phone', $columns)) {
            $wpdb->query("
                ALTER TABLE {$wpdb->prefix}fs_volunteers
                ADD COLUMN phone VARCHAR(20) DEFAULT NULL AFTER email
            ");
        }

        // Add phone_cell field if it doesn't exist
        if (!in_array('phone_cell', $columns)) {
            $wpdb->query("
                ALTER TABLE {$wpdb->prefix}fs_volunteers
                ADD COLUMN phone_cell VARCHAR(20) DEFAULT NULL AFTER phone
            ");
        }

        // Add source field if it doesn't exist
        if (!in_array('source', $columns)) {
            $wpdb->query("
                ALTER TABLE {$wpdb->prefix}fs_volunteers
                ADD COLUMN source VARCHAR(50) DEFAULT 'manual' AFTER phone_cell
            ");
        }
        
        // Create email ingestion log table
        $wpdb->query("
            CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fs_email_log (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                received_date datetime NOT NULL,
                from_address varchar(255) NOT NULL,
                subject varchar(500) DEFAULT NULL,
                raw_body text NOT NULL,
                parsed_data text DEFAULT NULL,
                status varchar(20) NOT NULL DEFAULT 'pending',
                volunteer_id bigint(20) DEFAULT NULL,
                error_message text DEFAULT NULL,
                processed_date datetime DEFAULT NULL,
                PRIMARY KEY (id),
                KEY status (status),
                KEY received_date (received_date),
                KEY volunteer_id (volunteer_id)
            )
        ");
        
        // Create volunteer interests table
        $wpdb->query("
            CREATE TABLE IF NOT EXISTS {$wpdb->prefix}fs_volunteer_interests (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                volunteer_id bigint(20) NOT NULL,
                interest varchar(255) NOT NULL,
                notes text DEFAULT NULL,
                source varchar(50) DEFAULT 'manual',
                created_date datetime NOT NULL,
                PRIMARY KEY (id),
                KEY volunteer_id (volunteer_id),
                KEY interest (interest)
            )
        ");
        
        return true;
    }
    
    public static function rollback() {
        global $wpdb;
        
        // Check if phone field existed before our migration
        // We'll remove phone_cell and source regardless
        // Only remove phone if we added it (which we can't know for sure, so we'll keep it to be safe)
        $wpdb->query("
            ALTER TABLE {$wpdb->prefix}fs_volunteers 
            DROP COLUMN phone_cell,
            DROP COLUMN source
        ");
        
        // Note: We don't drop 'phone' in rollback since it may have existed before
        // If you need to remove it, do so manually: ALTER TABLE wp_fs_volunteers DROP COLUMN phone;
        
        // Drop tables
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fs_email_log");
        $wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}fs_volunteer_interests");
        
        return true;
    }
}
