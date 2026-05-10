<?php
namespace EventCheckin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin settings: reCAPTCHA, object cache, general configuration.
 */
class Settings {

    const OPTION_GROUP = 'ec_settings';

    /**
     * Default settings.
     */
    private static $defaults = array(
        'recaptcha_enabled'    => false,
        'recaptcha_site_key'   => '',
        'recaptcha_secret_key' => '',
        'recaptcha_threshold'  => 0.5,
        'object_cache_ttl'     => 300,  // 5 minutes.
        'kiosk_idle_timeout'   => 15,   // seconds.
        'email_from_name'      => '',
        'email_from_address'   => '',
        'export_chunk_size'    => 500,  // rows per batch for background export.
        'sms_provider'         => '',   // 'twilio' or 'webhook'.
        'sms_webhook_url'      => '',
        'twilio_account_sid'   => '',
        'twilio_auth_token'    => '',
        'twilio_from_number'   => '',
    );

    /**
     * Get a setting value.
     *
     * @param string $key Setting key.
     * @return mixed Setting value.
     */
    public static function get( $key ) {
        $settings = get_option( self::OPTION_GROUP, array() );
        return isset( $settings[ $key ] ) ? $settings[ $key ] : ( self::$defaults[ $key ] ?? null );
    }

    /**
     * Get all settings.
     *
     * @return array All settings merged with defaults.
     */
    public static function get_all() {
        $settings = get_option( self::OPTION_GROUP, array() );
        return wp_parse_args( $settings, self::$defaults );
    }

    /**
     * Save settings.
     *
     * @param array $data Settings to save.
     */
    public static function save( $data ) {
        $clean = array();
        foreach ( self::$defaults as $key => $default ) {
            if ( isset( $data[ $key ] ) ) {
                if ( is_bool( $default ) ) {
                    $clean[ $key ] = (bool) $data[ $key ];
                } elseif ( is_int( $default ) ) {
                    $clean[ $key ] = absint( $data[ $key ] );
                } elseif ( is_float( $default ) ) {
                    $clean[ $key ] = (float) $data[ $key ];
                } else {
                    $clean[ $key ] = sanitize_text_field( $data[ $key ] );
                }
            }
        }
        update_option( self::OPTION_GROUP, $clean );
    }

    /**
     * Check if reCAPTCHA is enabled and configured.
     *
     * @return bool
     */
    public static function is_recaptcha_enabled() {
        return self::get( 'recaptcha_enabled' )
            && ! empty( self::get( 'recaptcha_site_key' ) )
            && ! empty( self::get( 'recaptcha_secret_key' ) );
    }

    /**
     * Verify a reCAPTCHA v3 token.
     *
     * @param string $token The reCAPTCHA token from the frontend.
     * @param string $action Expected action name.
     * @return bool True if verified, false otherwise.
     */
    public static function verify_recaptcha( $token, $action = 'ec_register' ) {
        if ( ! self::is_recaptcha_enabled() ) {
            return true; // reCAPTCHA not enabled, skip.
        }

        if ( empty( $token ) ) {
            return false;
        }

        $response = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', array(
            'timeout' => 10,
            'body'    => array(
                'secret'   => self::get( 'recaptcha_secret_key' ),
                'response' => $token,
                'remoteip' => Security::get_client_ip(),
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            // On API failure, allow the request through to avoid blocking users.
            return true;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( empty( $body['success'] ) ) {
            return false;
        }

        // Check action matches.
        if ( isset( $body['action'] ) && $body['action'] !== $action ) {
            return false;
        }

        // Check score threshold (reCAPTCHA v3).
        $threshold = (float) self::get( 'recaptcha_threshold' );
        if ( isset( $body['score'] ) && $body['score'] < $threshold ) {
            return false;
        }

        return true;
    }

    /**
     * Get cached data with object cache support.
     * Falls back to transients if no object cache is available.
     *
     * @param string $key Cache key.
     * @return mixed|false Cached value or false.
     */
    public static function cache_get( $key ) {
        $cache_key = 'ec_' . $key;

        // Try object cache first (Redis, Memcached).
        if ( wp_using_ext_object_cache() ) {
            return wp_cache_get( $cache_key, 'event-checkin' );
        }

        // Fall back to transients.
        return get_transient( $cache_key );
    }

    /**
     * Set cached data with object cache support.
     *
     * @param string $key   Cache key.
     * @param mixed  $value Value to cache.
     * @param int    $ttl   Time-to-live in seconds (0 = use default).
     * @return bool
     */
    public static function cache_set( $key, $value, $ttl = 0 ) {
        $cache_key = 'ec_' . $key;
        $ttl       = $ttl ?: (int) self::get( 'object_cache_ttl' );

        if ( wp_using_ext_object_cache() ) {
            return wp_cache_set( $cache_key, $value, 'event-checkin', $ttl );
        }

        return set_transient( $cache_key, $value, $ttl );
    }

    /**
     * Delete cached data.
     *
     * @param string $key Cache key.
     * @return bool
     */
    public static function cache_delete( $key ) {
        $cache_key = 'ec_' . $key;

        if ( wp_using_ext_object_cache() ) {
            return wp_cache_delete( $cache_key, 'event-checkin' );
        }

        return delete_transient( $cache_key );
    }

    /**
     * Initialize settings hooks.
     */
    public static function init() {
        add_action( 'admin_post_ec_save_settings', array( __CLASS__, 'handle_save' ) );
    }

    /**
     * Handle settings save.
     */
    public static function handle_save() {
        if ( ! current_user_can( 'ec_manage_settings' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'event-checkin' ), 403 );
        }

        check_admin_referer( 'ec_save_settings', 'ec_nonce' );

        self::save( array(
            'recaptcha_enabled'    => ! empty( $_POST['recaptcha_enabled'] ),
            'recaptcha_site_key'   => $_POST['recaptcha_site_key'] ?? '',
            'recaptcha_secret_key' => $_POST['recaptcha_secret_key'] ?? '',
            'recaptcha_threshold'  => $_POST['recaptcha_threshold'] ?? 0.5,
            'object_cache_ttl'     => $_POST['object_cache_ttl'] ?? 300,
            'kiosk_idle_timeout'   => $_POST['kiosk_idle_timeout'] ?? 15,
            'email_from_name'      => $_POST['email_from_name'] ?? '',
            'email_from_address'   => $_POST['email_from_address'] ?? '',
            'export_chunk_size'    => $_POST['export_chunk_size'] ?? 500,
            'sms_provider'         => $_POST['sms_provider'] ?? '',
            'sms_webhook_url'      => $_POST['sms_webhook_url'] ?? '',
            'twilio_account_sid'   => $_POST['twilio_account_sid'] ?? '',
            'twilio_auth_token'    => $_POST['twilio_auth_token'] ?? '',
            'twilio_from_number'   => $_POST['twilio_from_number'] ?? '',
        ) );

        wp_safe_redirect( admin_url( 'admin.php?page=ec-settings&updated=1' ) );
        exit;
    }
}
