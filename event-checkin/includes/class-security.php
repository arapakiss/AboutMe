<?php
namespace EventCheckin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Security utilities: rate limiting, input validation, nonce helpers.
 */
class Security {

    const RATE_LIMIT_REGISTRATION = 'registration';
    const RATE_LIMIT_CHECKIN      = 'checkin';
    const RATE_LIMIT_EXPORT       = 'export';

    /**
     * Rate limit windows (seconds) and max attempts.
     */
    private static $limits = array(
        'registration' => array( 'window' => 3600, 'max' => 5 ),
        'checkin'      => array( 'window' => 60, 'max' => 30 ),
        'export'       => array( 'window' => 60, 'max' => 5 ),
    );

    public static function init() {
        // Clean up expired rate limit entries daily.
        if ( ! wp_next_scheduled( 'ec_cleanup_rate_limits' ) ) {
            wp_schedule_event( time(), 'daily', 'ec_cleanup_rate_limits' );
        }
        add_action( 'ec_cleanup_rate_limits', array( __CLASS__, 'cleanup_rate_limits' ) );
    }

    /**
     * Check if an IP is rate-limited for a given action.
     *
     * @param string $action One of the RATE_LIMIT_* constants.
     * @return bool True if allowed, false if rate-limited.
     */
    public static function check_rate_limit( $action ) {
        if ( ! isset( self::$limits[ $action ] ) ) {
            return true;
        }

        global $wpdb;
        $table      = $wpdb->prefix . 'ec_rate_limits';
        $ip         = self::get_client_ip();
        $config     = self::$limits[ $action ];
        $window_start = gmdate( 'Y-m-d H:i:s', time() - $config['window'] );

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT attempts, window_start FROM {$table} WHERE ip_address = %s AND action_type = %s",
                $ip,
                $action
            )
        );

        if ( ! $row ) {
            // First attempt.
            $wpdb->insert( $table, array(
                'ip_address'   => $ip,
                'action_type'  => $action,
                'attempts'     => 1,
                'window_start' => gmdate( 'Y-m-d H:i:s' ),
            ), array( '%s', '%s', '%d', '%s' ) );
            return true;
        }

        // If window has expired, reset.
        if ( $row->window_start < $window_start ) {
            $wpdb->update(
                $table,
                array( 'attempts' => 1, 'window_start' => gmdate( 'Y-m-d H:i:s' ) ),
                array( 'ip_address' => $ip, 'action_type' => $action ),
                array( '%d', '%s' ),
                array( '%s', '%s' )
            );
            return true;
        }

        // Check if over limit.
        if ( (int) $row->attempts >= $config['max'] ) {
            return false;
        }

        // Increment.
        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET attempts = attempts + 1 WHERE ip_address = %s AND action_type = %s",
                $ip,
                $action
            )
        );

        return true;
    }

    /**
     * Get the client IP address, accounting for proxies.
     *
     * @return string
     */
    public static function get_client_ip() {
        $headers = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        );

        foreach ( $headers as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                $ip = explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
                $ip = trim( $ip[0] );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }

        return '0.0.0.0';
    }

    /**
     * Generate a cryptographically secure QR token.
     *
     * @return string 64-character hex string.
     */
    public static function generate_qr_token() {
        return bin2hex( random_bytes( 32 ) );
    }

    /**
     * Sanitize and validate an email address.
     *
     * @param string $email Raw email.
     * @return string|false Sanitized email or false.
     */
    public static function validate_email( $email ) {
        $email = sanitize_email( $email );
        return is_email( $email ) ? $email : false;
    }

    /**
     * Sanitize custom field data.
     *
     * @param array $data Raw data.
     * @return array Sanitized data.
     */
    public static function sanitize_custom_data( $data ) {
        if ( ! is_array( $data ) ) {
            return array();
        }
        $clean = array();
        foreach ( $data as $key => $value ) {
            $key = sanitize_key( $key );
            if ( is_array( $value ) ) {
                $clean[ $key ] = array_map( 'sanitize_text_field', $value );
            } else {
                $clean[ $key ] = sanitize_text_field( $value );
            }
        }
        return $clean;
    }

    /**
     * Validate signature data (must be a valid base64 data URI).
     *
     * @param string $data Signature data URI.
     * @return bool
     */
    public static function validate_signature_data( $data ) {
        if ( empty( $data ) ) {
            return false;
        }
        // Must be a PNG data URI.
        if ( strpos( $data, 'data:image/png;base64,' ) !== 0 ) {
            return false;
        }
        $base64 = substr( $data, strlen( 'data:image/png;base64,' ) );
        return base64_decode( $base64, true ) !== false;
    }

    /**
     * Clean up expired rate limit records.
     */
    public static function cleanup_rate_limits() {
        global $wpdb;
        $table = $wpdb->prefix . 'ec_rate_limits';
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$table} WHERE window_start < %s",
                gmdate( 'Y-m-d H:i:s', time() - 7200 )
            )
        );
    }
}
