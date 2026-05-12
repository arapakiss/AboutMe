<?php
/**
 * Plugin Name: Event Check-in
 * Plugin URI: https://github.com/arapakiss/AboutMe
 * Description: Event registration system with QR codes, self-service kiosk check-in, digital signatures, and Excel export.
 * Version: 1.2.2
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

define( 'EC_VERSION', '1.2.2' );
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
require_once EC_PLUGIN_DIR . 'includes/class-staff-app.php';

// GitHub auto-updater: checks releases on arapakiss/AboutMe for new versions.
require_once EC_PLUGIN_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$ec_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/arapakiss/AboutMe/',
    __FILE__,
    'event-checkin'
);
// Use GitHub releases as the source. The release tag must match the version (e.g. v1.2.2).
$ec_update_checker->getVcsApi()->enableReleaseAssets();

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
    EventCheckin\Staff_App::init();
});
