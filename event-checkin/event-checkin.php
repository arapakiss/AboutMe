<?php
/**
 * Plugin Name: Event Check-in
 * Plugin URI: https://github.com/arapakiss/AboutMe
 * Description: Event registration system with QR codes, self-service kiosk check-in, digital signatures, and Excel export.
 * Version: 1.2.0
 * Author: Alexander Arapakis
 * Author URI: https://github.com/arapakiss
 * License: GPL-2.0+
 * Text Domain: event-checkin
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'EC_VERSION', '1.2.0' );
define( 'EC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'EC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'EC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Autoload classes.
spl_autoload_register( function ( $class ) {
    $prefix = 'EventCheckin\\';
    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }
    $relative = substr( $class, strlen( $prefix ) );
    $file     = EC_PLUGIN_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
});

/**
 * Plugin activation.
 */
function ec_activate() {
    require_once EC_PLUGIN_DIR . 'includes/class-activator.php';
    require_once EC_PLUGIN_DIR . 'includes/class-roles.php';
    require_once EC_PLUGIN_DIR . 'includes/class-verification.php';
    require_once EC_PLUGIN_DIR . 'includes/class-settings.php';
    EventCheckin\Activator::activate();
}
register_activation_hook( __FILE__, 'ec_activate' );

/**
 * Plugin deactivation.
 */
function ec_deactivate() {
    require_once EC_PLUGIN_DIR . 'includes/class-activator.php';
    EventCheckin\Activator::deactivate();
}
register_deactivation_hook( __FILE__, 'ec_deactivate' );

// Bootstrap the plugin.
require_once EC_PLUGIN_DIR . 'includes/class-activator.php';
require_once EC_PLUGIN_DIR . 'includes/class-security.php';
require_once EC_PLUGIN_DIR . 'includes/class-roles.php';
require_once EC_PLUGIN_DIR . 'includes/class-email.php';
require_once EC_PLUGIN_DIR . 'includes/class-admin.php';
require_once EC_PLUGIN_DIR . 'includes/class-rest-api.php';
require_once EC_PLUGIN_DIR . 'includes/class-registration.php';
require_once EC_PLUGIN_DIR . 'includes/class-qrcode.php';
require_once EC_PLUGIN_DIR . 'includes/class-checkin.php';
require_once EC_PLUGIN_DIR . 'includes/class-signature.php';
require_once EC_PLUGIN_DIR . 'includes/class-export.php';
require_once EC_PLUGIN_DIR . 'includes/class-settings.php';
require_once EC_PLUGIN_DIR . 'includes/class-form-fields.php';
require_once EC_PLUGIN_DIR . 'includes/class-form-builder.php';
require_once EC_PLUGIN_DIR . 'includes/class-form-renderer.php';
require_once EC_PLUGIN_DIR . 'includes/class-deepl-translate.php';
require_once EC_PLUGIN_DIR . 'includes/class-verification.php';

add_action( 'plugins_loaded', function () {
    EventCheckin\Security::init();
    EventCheckin\Roles::init();
    EventCheckin\Email::init();
    EventCheckin\Settings::init();
    EventCheckin\Admin::init();
    EventCheckin\Rest_API::init();
    EventCheckin\Registration::init();
    EventCheckin\Export::init();
    EventCheckin\Checkin::init();
    EventCheckin\Form_Builder::init();
    EventCheckin\Form_Renderer::init();
    EventCheckin\DeepL_Translate::init();
    EventCheckin\Verification::init();
});

// Rewrite rule for short QR code URLs: /ec/TOKEN
// This makes QR codes scannable by phone cameras and resolves to a
// helpful page instead of a 404. Also keeps the encoded URL shorter
// for easier QR scanning.
add_action( 'init', function () {
    add_rewrite_rule(
        '^ec/([a-f0-9]{64})/?$',
        'index.php?ec_token=$matches[1]',
        'top'
    );
});

add_filter( 'query_vars', function ( $vars ) {
    $vars[] = 'ec_token';
    return $vars;
});

add_action( 'template_redirect', function () {
    $token = get_query_var( 'ec_token' );
    if ( ! $token || ! preg_match( '/^[a-f0-9]{64}$/', $token ) ) {
        return;
    }

    global $wpdb;
    $reg = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT r.id, r.first_name, r.last_name, r.status, r.checked_in_at,
                    e.title as event_title, e.event_date, e.location
             FROM {$wpdb->prefix}ec_registrations r
             JOIN {$wpdb->prefix}ec_events e ON r.event_id = e.id
             WHERE r.qr_token = %s",
            $token
        )
    );

    // Show a simple, mobile-friendly status page.
    header( 'Content-Type: text/html; charset=utf-8' );
    header( 'Cache-Control: no-cache, no-store, must-revalidate' );
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . esc_html__( 'Event Check-in', 'event-checkin' ) . '</title>';
    echo '<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f4f5f7;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}.card{background:#fff;max-width:400px;width:100%;box-shadow:0 4px 24px rgba(0,0,0,.1);text-align:center;overflow:hidden}.header{background:#002d72;padding:24px;color:#fff}.header h1{font-size:20px;font-weight:800;text-transform:uppercase;letter-spacing:-.02em}.body{padding:32px 24px}.status{display:inline-block;padding:8px 20px;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.1em;border:2px solid;margin:16px 0}.status-checked_in{border-color:#0babe4;color:#002d72;background:rgba(11,171,228,.08)}.status-registered{border-color:#002d72;color:#002d72;background:rgba(0,45,114,.05)}.status-cancelled{border-color:#dc2626;color:#991b1b;background:rgba(220,38,38,.05)}.name{font-size:24px;font-weight:800;color:#002d72;margin-bottom:8px}.meta{font-size:14px;color:#4b5563;margin:4px 0}.info{font-size:13px;color:#9ca3af;margin-top:20px}</style>';
    echo '</head><body><div class="card"><div class="header"><h1>' . esc_html__( 'Event Check-in', 'event-checkin' ) . '</h1></div><div class="body">';

    if ( ! $reg ) {
        echo '<p style="color:#dc2626;font-weight:700;">' . esc_html__( 'Registration not found.', 'event-checkin' ) . '</p>';
    } else {
        echo '<p class="name">' . esc_html( $reg->first_name . ' ' . $reg->last_name ) . '</p>';
        echo '<p class="meta">' . esc_html( $reg->event_title ) . '</p>';
        if ( $reg->event_date ) {
            echo '<p class="meta">' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $reg->event_date ) ) ) . '</p>';
        }
        if ( $reg->location ) {
            echo '<p class="meta">' . esc_html( $reg->location ) . '</p>';
        }
        echo '<div class="status status-' . esc_attr( $reg->status ) . '">' . esc_html( ucfirst( str_replace( '_', ' ', $reg->status ) ) ) . '</div>';
        if ( $reg->checked_in_at ) {
            echo '<p class="meta">' . sprintf( esc_html__( 'Checked in: %s', 'event-checkin' ), esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $reg->checked_in_at ) ) ) ) . '</p>';
        }
        echo '<p class="info">' . esc_html__( 'Present this QR code at the event entrance for check-in.', 'event-checkin' ) . '</p>';
    }

    echo '</div></div></body></html>';
    exit;
});
