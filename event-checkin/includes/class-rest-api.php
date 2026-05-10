<?php
namespace EventCheckin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST API endpoints for check-in operations.
 */
class Rest_API {

    const NAMESPACE = 'event-checkin/v1';

    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
    }

    /**
     * Register REST API routes.
     */
    public static function register_routes() {
        // Check-in endpoint (used by kiosk).
        register_rest_route( self::NAMESPACE, '/checkin', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_checkin' ),
            'permission_callback' => function () {
                return current_user_can( 'ec_manage_checkin' );
            },
            'args'                => array(
                'qr_token' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function ( $value ) {
                        return is_string( $value ) && strlen( $value ) === 64 && ctype_xdigit( $value );
                    },
                ),
            ),
        ) );

        // Signature submission endpoint.
        register_rest_route( self::NAMESPACE, '/signature', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_signature' ),
            'permission_callback' => function () {
                return current_user_can( 'ec_manage_checkin' );
            },
            'args'                => array(
                'qr_token' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'signature' => array(
                    'required' => true,
                    'type'     => 'string',
                ),
            ),
        ) );

        // Event stats endpoint (used by kiosk for live counters).
        register_rest_route( self::NAMESPACE, '/stats/(?P<event_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'handle_stats' ),
            'permission_callback' => function () {
                return current_user_can( 'ec_manage_checkin' );
            },
            'args'                => array(
                'event_id' => array(
                    'required'          => true,
                    'type'              => 'integer',
                    'sanitize_callback' => 'absint',
                ),
            ),
        ) );

        // Lookup registration by token (for kiosk pre-check).
        register_rest_route( self::NAMESPACE, '/lookup', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'handle_lookup' ),
            'permission_callback' => function () {
                return current_user_can( 'ec_manage_checkin' );
            },
            'args'                => array(
                'qr_token' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
            ),
        ) );
    }

    /**
     * Handle check-in API request.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public static function handle_checkin( $request ) {
        if ( ! Security::check_rate_limit( Security::RATE_LIMIT_CHECKIN ) ) {
            return new \WP_REST_Response( array(
                'status'  => 'error',
                'code'    => 'rate_limited',
                'message' => __( 'Too many attempts. Please wait.', 'event-checkin' ),
            ), 429 );
        }

        $qr_token = $request->get_param( 'qr_token' );
        $user_id  = get_current_user_id();

        $result = Checkin::process_checkin( $qr_token, $user_id );

        $status_codes = array(
            'success'            => 200,
            'already_checked_in' => 200,
            'needs_signature'    => 200,
            'error'              => 400,
        );

        $http_code = $status_codes[ $result['status'] ] ?? 400;

        if ( $result['code'] === 'not_found' ) {
            $http_code = 404;
        }

        return new \WP_REST_Response( $result, $http_code );
    }

    /**
     * Handle signature submission.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public static function handle_signature( $request ) {
        $qr_token  = $request->get_param( 'qr_token' );
        $signature = $request->get_param( 'signature' );
        $user_id   = get_current_user_id();

        $result = Checkin::complete_checkin_with_signature( $qr_token, $signature, $user_id );

        $http_code = $result['status'] === 'success' || $result['status'] === 'already_checked_in' ? 200 : 400;

        return new \WP_REST_Response( $result, $http_code );
    }

    /**
     * Handle stats request.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public static function handle_stats( $request ) {
        global $wpdb;
        $event_id = $request->get_param( 'event_id' );

        $total = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ec_registrations WHERE event_id = %d AND status != 'cancelled'",
                $event_id
            )
        );

        $checked_in = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}ec_registrations WHERE event_id = %d AND status = 'checked_in'",
                $event_id
            )
        );

        return new \WP_REST_Response( array(
            'total'      => (int) $total,
            'checked_in' => (int) $checked_in,
        ), 200 );
    }

    /**
     * Handle token lookup.
     *
     * @param \WP_REST_Request $request Request object.
     * @return \WP_REST_Response
     */
    public static function handle_lookup( $request ) {
        global $wpdb;
        $qr_token = $request->get_param( 'qr_token' );

        $registration = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT r.id, r.first_name, r.last_name, r.email, r.status, r.checked_in_at,
                        e.title as event_title, e.require_signature
                 FROM {$wpdb->prefix}ec_registrations r
                 JOIN {$wpdb->prefix}ec_events e ON r.event_id = e.id
                 WHERE r.qr_token = %s",
                $qr_token
            )
        );

        if ( ! $registration ) {
            return new \WP_REST_Response( array(
                'status'  => 'error',
                'code'    => 'not_found',
                'message' => __( 'Registration not found.', 'event-checkin' ),
            ), 404 );
        }

        return new \WP_REST_Response( array(
            'status' => 'success',
            'data'   => array(
                'name'              => $registration->first_name . ' ' . $registration->last_name,
                'email'             => $registration->email,
                'event_title'       => $registration->event_title,
                'registration_status' => $registration->status,
                'checked_in_at'     => $registration->checked_in_at,
                'require_signature' => (bool) $registration->require_signature,
            ),
        ), 200 );
    }
}
