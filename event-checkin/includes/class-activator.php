<?php
namespace EventCheckin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles plugin activation: creates database tables and sets default options.
 */
class Activator {

    /**
     * Run on plugin activation.
     */
    public static function activate() {
        self::create_tables();
        self::create_upload_dir();
        Roles::create_roles();
        flush_rewrite_rules();
        update_option( 'ec_db_version', EC_VERSION );
    }

    /**
     * Run on plugin deactivation.
     */
    public static function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Create custom database tables.
     */
    private static function create_tables() {
        global $wpdb;
        $charset = $wpdb->get_charset_collate();

        $events_table       = $wpdb->prefix . 'ec_events';
        $registrations_table = $wpdb->prefix . 'ec_registrations';
        $checkin_log_table   = $wpdb->prefix . 'ec_checkin_log';
        $rate_limits_table   = $wpdb->prefix . 'ec_rate_limits';

        $sql = "CREATE TABLE {$events_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            title VARCHAR(255) NOT NULL,
            description TEXT,
            event_date DATETIME NOT NULL,
            location VARCHAR(255) DEFAULT '',
            max_capacity INT UNSIGNED DEFAULT NULL,
            registration_deadline DATETIME DEFAULT NULL,
            require_signature TINYINT(1) NOT NULL DEFAULT 0,
            custom_fields LONGTEXT DEFAULT NULL,
            form_schema LONGTEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status_idx (status),
            KEY event_date_idx (event_date)
        ) {$charset};

        CREATE TABLE {$registrations_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event_id BIGINT UNSIGNED NOT NULL,
            qr_token VARCHAR(64) NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(50) DEFAULT '',
            custom_data LONGTEXT DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'registered',
            checked_in_at DATETIME DEFAULT NULL,
            signature_data LONGTEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY qr_token_idx (qr_token),
            UNIQUE KEY email_event_idx (email, event_id),
            KEY event_status_idx (event_id, status)
        ) {$charset};

        CREATE TABLE {$checkin_log_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            registration_id BIGINT UNSIGNED NOT NULL,
            event_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(20) NOT NULL DEFAULT 'checkin',
            performed_by BIGINT UNSIGNED DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT '',
            user_agent VARCHAR(500) DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY reg_idx (registration_id),
            KEY event_idx (event_id)
        ) {$charset};

        CREATE TABLE {$rate_limits_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            ip_address VARCHAR(45) NOT NULL,
            action_type VARCHAR(50) NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 1,
            window_start DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY ip_action_idx (ip_address, action_type)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        // Verifications table for email/SMS OTP.
        Verification::create_table();
    }

    /**
     * Create upload directory for QR codes.
     */
    private static function create_upload_dir() {
        $upload_dir = wp_upload_dir();
        $ec_dir     = $upload_dir['basedir'] . '/event-checkin/qrcodes';
        if ( ! file_exists( $ec_dir ) ) {
            wp_mkdir_p( $ec_dir );
        }
        // Protect directory with .htaccess.
        $htaccess = $upload_dir['basedir'] . '/event-checkin/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess, "Options -Indexes\n" );
        }
    }
}
