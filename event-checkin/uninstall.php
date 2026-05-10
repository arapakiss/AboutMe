<?php
/**
 * Fired when the plugin is uninstalled.
 * Cleans up database tables and options.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop custom tables.
$tables = array(
    $wpdb->prefix . 'ec_checkin_log',
    $wpdb->prefix . 'ec_registrations',
    $wpdb->prefix . 'ec_events',
    $wpdb->prefix . 'ec_rate_limits',
);

foreach ( $tables as $table ) {
    $wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
}

// Remove options.
delete_option( 'ec_db_version' );

// Remove QR code files.
$upload_dir = wp_upload_dir();
$ec_dir     = $upload_dir['basedir'] . '/event-checkin';
if ( is_dir( $ec_dir ) ) {
    $files = glob( $ec_dir . '/qrcodes/*.png' );
    if ( $files ) {
        foreach ( $files as $file ) {
            wp_delete_file( $file );
        }
    }
    @rmdir( $ec_dir . '/qrcodes' );
    @unlink( $ec_dir . '/.htaccess' );
    @rmdir( $ec_dir );
}

// Remove custom roles and capabilities.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-roles.php';
\EventCheckin\Roles::remove_roles();

// Clear scheduled events.
wp_clear_scheduled_hook( 'ec_cleanup_rate_limits' );
