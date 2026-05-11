<?php
/**
 * Fired when the plugin is uninstalled.
 * Only cleans up database tables and files if the admin has opted in
 * via Settings > "Delete all data on uninstall".
 * By default, data is preserved for safe updates and reinstalls.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Check the setting: only delete data if the admin explicitly opted in.
$settings = get_option( 'ec_settings', array() );
$delete_data = ! empty( $settings['delete_data_on_uninstall'] );

if ( ! $delete_data ) {
    // Data preservation mode: keep everything intact.
    // Only clear scheduled hooks (they won't fire without the plugin anyway).
    wp_clear_scheduled_hook( 'ec_cleanup_rate_limits' );
    return;
}

// --- Full cleanup (admin opted in) ---

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

// Remove all plugin options.
delete_option( 'ec_db_version' );
delete_option( 'ec_settings' );
delete_option( 'ec_default_form_page_id' );

// Remove per-event options (form pages, translations).
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'ec_form_page_%' OR option_name LIKE 'ec_form_translations_%'"
);

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

// Remove translation files.
$trans_dir = $upload_dir['basedir'] . '/ec-translations';
if ( is_dir( $trans_dir ) ) {
    $trans_files = glob( $trans_dir . '/*.json' );
    if ( $trans_files ) {
        foreach ( $trans_files as $file ) {
            wp_delete_file( $file );
        }
    }
    @unlink( $trans_dir . '/index.php' );
    @rmdir( $trans_dir );
}

// Remove custom roles and capabilities.
require_once plugin_dir_path( __FILE__ ) . 'includes/class-roles.php';
\EventCheckin\Roles::remove_roles();

// Clear all transients with our prefix.
$wpdb->query(
    "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_ec_%' OR option_name LIKE '_transient_timeout_ec_%'"
);

// Clear scheduled events.
wp_clear_scheduled_hook( 'ec_cleanup_rate_limits' );
