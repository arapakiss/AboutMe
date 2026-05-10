<?php
namespace EventCheckin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Email and SMS verification for form fields.
 */
class Verification {

    const TABLE_NAME = 'ec_verifications';

    /**
     * Initialize verification hooks.
     */
    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    /**
     * Register REST routes for verification.
     */
    public static function register_routes() {
        register_rest_route( 'event-checkin/v1', '/verify/send', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_send_code' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'identifier' => array( 'required' => true, 'type' => 'string' ),
                'type'       => array( 'required' => true, 'type' => 'string', 'enum' => array( 'email', 'sms' ) ),
                'event_id'   => array( 'required' => true, 'type' => 'integer' ),
            ),
        ) );

        register_rest_route( 'event-checkin/v1', '/verify/check', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_check_code' ),
            'permission_callback' => '__return_true',
            'args'                => array(
                'identifier' => array( 'required' => true, 'type' => 'string' ),
                'code'       => array( 'required' => true, 'type' => 'string' ),
                'type'       => array( 'required' => true, 'type' => 'string', 'enum' => array( 'email', 'sms' ) ),
            ),
        ) );
    }

    /**
     * Handle sending a verification code.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public static function handle_send_code( $request ) {
        $identifier = sanitize_text_field( $request->get_param( 'identifier' ) );
        $type       = sanitize_key( $request->get_param( 'type' ) );
        $event_id   = absint( $request->get_param( 'event_id' ) );

        // Rate limit: max 3 codes per identifier per 10 minutes.
        if ( ! self::check_send_rate( $identifier ) ) {
            return new \WP_REST_Response( array(
                'status'  => 'error',
                'message' => __( 'Too many verification attempts. Please wait a few minutes.', 'event-checkin' ),
            ), 429 );
        }

        // Validate identifier.
        if ( $type === 'email' && ! is_email( $identifier ) ) {
            return new \WP_REST_Response( array(
                'status'  => 'error',
                'message' => __( 'Invalid email address.', 'event-checkin' ),
            ), 400 );
        }

        if ( $type === 'sms' ) {
            $phone = preg_replace( '/[^0-9+]/', '', $identifier );
            if ( strlen( $phone ) < 7 ) {
                return new \WP_REST_Response( array(
                    'status'  => 'error',
                    'message' => __( 'Invalid phone number.', 'event-checkin' ),
                ), 400 );
            }
        }

        // Generate 6-digit code.
        $code = self::generate_code();

        // Store code.
        self::store_code( $identifier, $code, $type );

        // Send the code.
        $sent = false;
        if ( $type === 'email' ) {
            $sent = self::send_email_code( $identifier, $code, $event_id );
        } elseif ( $type === 'sms' ) {
            $sent = self::send_sms_code( $identifier, $code, $event_id );
        }

        if ( ! $sent ) {
            return new \WP_REST_Response( array(
                'status'  => 'error',
                'message' => __( 'Failed to send verification code. Please try again.', 'event-checkin' ),
            ), 500 );
        }

        // Mask the identifier for the response.
        $masked = self::mask_identifier( $identifier, $type );

        return new \WP_REST_Response( array(
            'status'  => 'sent',
            'message' => sprintf( __( 'Verification code sent to %s', 'event-checkin' ), $masked ),
            'expires' => 300, // 5 minutes.
        ), 200 );
    }

    /**
     * Handle checking a verification code.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response
     */
    public static function handle_check_code( $request ) {
        $identifier = sanitize_text_field( $request->get_param( 'identifier' ) );
        $code       = sanitize_text_field( $request->get_param( 'code' ) );
        $type       = sanitize_key( $request->get_param( 'type' ) );

        $result = self::verify_code( $identifier, $code, $type );

        if ( is_wp_error( $result ) ) {
            return new \WP_REST_Response( array(
                'status'  => 'error',
                'code'    => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ), 400 );
        }

        return new \WP_REST_Response( array(
            'status'  => 'verified',
            'message' => __( 'Verification successful!', 'event-checkin' ),
            'token'   => $result, // Verification token for form submission.
        ), 200 );
    }

    /**
     * Generate a 6-digit numeric verification code.
     *
     * @return string 6-digit code.
     */
    public static function generate_code() {
        return str_pad( (string) random_int( 0, 999999 ), 6, '0', STR_PAD_LEFT );
    }

    /**
     * Store a verification code.
     *
     * @param string $identifier Email or phone.
     * @param string $code       The code.
     * @param string $type       'email' or 'sms'.
     */
    public static function store_code( $identifier, $code, $type ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Delete any existing codes for this identifier.
        $wpdb->delete( $table, array(
            'identifier' => $identifier,
            'type'       => $type,
        ), array( '%s', '%s' ) );

        $wpdb->insert( $table, array(
            'identifier' => $identifier,
            'code'       => wp_hash( $code ), // Store hashed.
            'type'       => $type,
            'verified'   => 0,
            'attempts'   => 0,
            'expires_at' => gmdate( 'Y-m-d H:i:s', time() + 300 ),
            'created_at' => gmdate( 'Y-m-d H:i:s' ),
        ), array( '%s', '%s', '%s', '%d', '%d', '%s', '%s' ) );
    }

    /**
     * Verify a submitted code.
     *
     * @param string $identifier Email or phone.
     * @param string $code       Submitted code.
     * @param string $type       'email' or 'sms'.
     * @return string|\WP_Error Verification token on success, WP_Error on failure.
     */
    public static function verify_code( $identifier, $code, $type ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $record = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE identifier = %s AND type = %s AND verified = 0 ORDER BY created_at DESC LIMIT 1",
                $identifier,
                $type
            )
        );

        if ( ! $record ) {
            return new \WP_Error( 'no_code', __( 'No verification code found. Please request a new one.', 'event-checkin' ) );
        }

        // Check expiry.
        if ( strtotime( $record->expires_at ) < time() ) {
            return new \WP_Error( 'expired', __( 'Verification code has expired. Please request a new one.', 'event-checkin' ) );
        }

        // Check max attempts (5).
        if ( (int) $record->attempts >= 5 ) {
            return new \WP_Error( 'max_attempts', __( 'Too many failed attempts. Please request a new code.', 'event-checkin' ) );
        }

        // Increment attempts.
        $wpdb->update(
            $table,
            array( 'attempts' => (int) $record->attempts + 1 ),
            array( 'id' => $record->id ),
            array( '%d' ),
            array( '%d' )
        );

        // Verify code (compare hash).
        if ( ! hash_equals( wp_hash( $code ), $record->code ) ) {
            return new \WP_Error( 'invalid_code', __( 'Invalid code. Please try again.', 'event-checkin' ) );
        }

        // Mark as verified.
        $wpdb->update(
            $table,
            array( 'verified' => 1 ),
            array( 'id' => $record->id ),
            array( '%d' ),
            array( '%d' )
        );

        // Generate a verification token (used during form submission to prove verification).
        $token = wp_hash( $identifier . '|' . $type . '|' . time() );

        // Store token in transient (valid for 30 min).
        set_transient( 'ec_verified_' . md5( $identifier . $type ), $token, 1800 );

        return $token;
    }

    /**
     * Check if an identifier has been verified (during form submission).
     *
     * @param string $identifier Email or phone.
     * @param string $type       'email' or 'sms'.
     * @param string $token      Verification token from frontend.
     * @return bool
     */
    public static function is_verified( $identifier, $type, $token ) {
        $stored = get_transient( 'ec_verified_' . md5( $identifier . $type ) );
        return $stored && hash_equals( $stored, $token );
    }

    /**
     * Send email verification code.
     *
     * @param string $email    Email address.
     * @param string $code     Verification code.
     * @param int    $event_id Event ID.
     * @return bool
     */
    private static function send_email_code( $email, $code, $event_id ) {
        $subject = sprintf(
            __( 'Your verification code: %s', 'event-checkin' ),
            $code
        );

        $message = sprintf(
            __( 'Your verification code is: %s', 'event-checkin' ),
            $code
        ) . "\n\n";
        $message .= __( 'This code expires in 5 minutes.', 'event-checkin' ) . "\n";
        $message .= __( 'If you did not request this code, please ignore this email.', 'event-checkin' );

        return wp_mail( $email, $subject, $message );
    }

    /**
     * Send SMS verification code via configurable provider.
     *
     * @param string $phone    Phone number.
     * @param string $code     Verification code.
     * @param int    $event_id Event ID.
     * @return bool
     */
    private static function send_sms_code( $phone, $code, $event_id ) {
        $sms_webhook = Settings::get( 'sms_webhook_url' );
        $sms_provider = Settings::get( 'sms_provider' );

        $message = sprintf(
            __( 'Your verification code is: %s. Expires in 5 minutes.', 'event-checkin' ),
            $code
        );

        // If Twilio is configured.
        if ( $sms_provider === 'twilio' ) {
            return self::send_twilio_sms( $phone, $message );
        }

        // If a webhook URL is configured (generic provider).
        if ( ! empty( $sms_webhook ) ) {
            return self::send_webhook_sms( $sms_webhook, $phone, $message, $code );
        }

        // No SMS provider configured -- log and fail gracefully.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "Event Check-in: SMS verification requested but no provider configured. Phone: {$phone}, Code: {$code}" );
        }

        return false;
    }

    /**
     * Send SMS via Twilio API.
     *
     * @param string $phone   Phone number.
     * @param string $message SMS message.
     * @return bool
     */
    private static function send_twilio_sms( $phone, $message ) {
        $account_sid = Settings::get( 'twilio_account_sid' );
        $auth_token  = Settings::get( 'twilio_auth_token' );
        $from_number = Settings::get( 'twilio_from_number' );

        if ( empty( $account_sid ) || empty( $auth_token ) || empty( $from_number ) ) {
            return false;
        }

        $url = "https://api.twilio.com/2010-04-01/Accounts/{$account_sid}/Messages.json";

        $response = wp_remote_post( $url, array(
            'timeout' => 15,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $account_sid . ':' . $auth_token ),
            ),
            'body'    => array(
                'From' => $from_number,
                'To'   => $phone,
                'Body' => $message,
            ),
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        return $code >= 200 && $code < 300;
    }

    /**
     * Send SMS via generic webhook.
     *
     * @param string $url     Webhook URL.
     * @param string $phone   Phone number.
     * @param string $message SMS message.
     * @param string $code    Verification code.
     * @return bool
     */
    private static function send_webhook_sms( $url, $phone, $message, $code ) {
        $response = wp_remote_post( $url, array(
            'timeout' => 15,
            'headers' => array( 'Content-Type' => 'application/json' ),
            'body'    => wp_json_encode( array(
                'phone'   => $phone,
                'message' => $message,
                'code'    => $code,
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $status = wp_remote_retrieve_response_code( $response );
        return $status >= 200 && $status < 300;
    }

    /**
     * Check send rate limit (max 3 codes per 10 minutes per identifier).
     *
     * @param string $identifier Email or phone.
     * @return bool True if allowed.
     */
    private static function check_send_rate( $identifier ) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE identifier = %s AND created_at > %s",
                $identifier,
                gmdate( 'Y-m-d H:i:s', time() - 600 )
            )
        );

        return (int) $count < 3;
    }

    /**
     * Mask an identifier for display.
     *
     * @param string $identifier Email or phone.
     * @param string $type       'email' or 'sms'.
     * @return string Masked string.
     */
    private static function mask_identifier( $identifier, $type ) {
        if ( $type === 'email' ) {
            $parts = explode( '@', $identifier );
            $name  = $parts[0];
            $domain = $parts[1] ?? '';
            $masked = substr( $name, 0, 2 ) . str_repeat( '*', max( 3, strlen( $name ) - 2 ) );
            return $masked . '@' . $domain;
        }

        // Phone: show last 4 digits.
        return str_repeat( '*', max( 0, strlen( $identifier ) - 4 ) ) . substr( $identifier, -4 );
    }

    /**
     * Create the verifications table.
     */
    public static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . self::TABLE_NAME;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            identifier VARCHAR(255) NOT NULL,
            code VARCHAR(255) NOT NULL,
            type VARCHAR(10) NOT NULL DEFAULT 'email',
            verified TINYINT(1) NOT NULL DEFAULT 0,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY identifier_type_idx (identifier, type),
            KEY expires_idx (expires_at)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}
