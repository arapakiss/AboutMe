<?php
namespace EventCheckin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles check-in logic and the kiosk mode shortcode.
 */
class Checkin {

    public static function init() {
        add_shortcode( 'event_kiosk', array( __CLASS__, 'render_kiosk_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    /**
     * Enqueue kiosk assets.
     */
    public static function enqueue_assets() {
        if ( ! is_singular() ) {
            return;
        }

        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'event_kiosk' ) ) {
            return;
        }

        wp_enqueue_style(
            'ec-kiosk',
            EC_PLUGIN_URL . 'assets/css/kiosk.css',
            array(),
            EC_VERSION
        );

        // html5-qrcode library from CDN.
        wp_enqueue_script(
            'html5-qrcode',
            'https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js',
            array(),
            '2.3.8',
            true
        );

        // Signature pad library from CDN.
        wp_enqueue_script(
            'signature-pad',
            'https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js',
            array(),
            '4.1.7',
            true
        );

        wp_enqueue_script(
            'ec-kiosk',
            EC_PLUGIN_URL . 'assets/js/kiosk.js',
            array( 'jquery', 'html5-qrcode', 'signature-pad' ),
            EC_VERSION,
            true
        );

        wp_localize_script( 'ec-kiosk', 'ecKiosk', array(
            'restUrl'      => esc_url_raw( rest_url( 'event-checkin/v1' ) ),
            'nonce'        => wp_create_nonce( 'wp_rest' ),
            'idleTimeout'  => 15000, // 15 seconds before auto-reset.
            'i18n'         => array(
                'scanPrompt'       => __( 'Scan your QR code to check in', 'event-checkin' ),
                'processing'       => __( 'Processing...', 'event-checkin' ),
                'checkinSuccess'   => __( 'Check-in successful!', 'event-checkin' ),
                'welcome'          => __( 'Welcome, %s!', 'event-checkin' ),
                'alreadyCheckedIn' => __( 'You are already checked in.', 'event-checkin' ),
                'notFound'         => __( 'Registration not found. Please check your QR code.', 'event-checkin' ),
                'signaturePrompt'  => __( 'Please sign below to complete check-in', 'event-checkin' ),
                'signHere'         => __( 'Sign here', 'event-checkin' ),
                'clear'            => __( 'Clear', 'event-checkin' ),
                'confirm'          => __( 'Confirm', 'event-checkin' ),
                'cameraError'      => __( 'Camera access denied. Please allow camera access.', 'event-checkin' ),
                'error'            => __( 'An error occurred. Please try again.', 'event-checkin' ),
                'autoReset'        => __( 'Returning to scanner...', 'event-checkin' ),
            ),
        ) );
    }

    /**
     * Render the kiosk mode shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public static function render_kiosk_shortcode( $atts ) {
        $atts     = shortcode_atts( array( 'id' => 0 ), $atts, 'event_kiosk' );
        $event_id = absint( $atts['id'] );

        if ( ! $event_id ) {
            return '<p class="ec-error">' . esc_html__( 'Invalid event ID.', 'event-checkin' ) . '</p>';
        }

        // Staff or admin check.
        if ( ! current_user_can( 'ec_manage_checkin' ) ) {
            return '<p class="ec-error">' . esc_html__( 'You do not have permission to access the kiosk.', 'event-checkin' ) . '</p>';
        }

        global $wpdb;
        $event = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ec_events WHERE id = %d",
                $event_id
            )
        );

        if ( ! $event ) {
            return '<p class="ec-error">' . esc_html__( 'Event not found.', 'event-checkin' ) . '</p>';
        }

        ob_start();
        ?>
        <div class="ec-kiosk" id="ec-kiosk" data-event-id="<?php echo intval( $event_id ); ?>" data-require-signature="<?php echo intval( $event->require_signature ); ?>">
            <!-- Left Hero Panel (desktop) -->
            <div class="ec-kiosk-hero">
                <div class="ec-kiosk-hero-ghost">
                    <span><?php esc_html_e( 'Welcome', 'event-checkin' ); ?></span>
                </div>
                <div class="ec-kiosk-hero-content">
                    <h1><?php esc_html_e( 'Self Check-In', 'event-checkin' ); ?></h1>
                    <p><?php esc_html_e( 'Experience seamless arrival with our quick and easy check-in process.', 'event-checkin' ); ?></p>
                    <p class="ec-powered"><?php echo esc_html( $event->title ); ?></p>
                </div>
            </div>

            <!-- Right Workflow Panel -->
            <div class="ec-kiosk-workflow">
                <!-- Progress Steps -->
                <div class="ec-kiosk-progress">
                    <div class="ec-step">
                        <div class="ec-step-number active" id="ec-step-1">1</div>
                        <span class="ec-step-label"><?php esc_html_e( 'Scan', 'event-checkin' ); ?></span>
                    </div>
                    <div class="ec-step-divider"></div>
                    <div class="ec-step">
                        <div class="ec-step-number" id="ec-step-2">2</div>
                        <span class="ec-step-label"><?php echo $event->require_signature ? esc_html__( 'Sign', 'event-checkin' ) : esc_html__( 'Verify', 'event-checkin' ); ?></span>
                    </div>
                    <div class="ec-step-divider"></div>
                    <div class="ec-step">
                        <div class="ec-step-number" id="ec-step-3">3</div>
                        <span class="ec-step-label"><?php esc_html_e( 'Done', 'event-checkin' ); ?></span>
                    </div>
                    <div class="ec-kiosk-event-title">
                        <h2><span class="accent"><?php esc_html_e( 'Welcome', 'event-checkin' ); ?></span> <?php esc_html_e( 'Guest', 'event-checkin' ); ?></h2>
                        <p><?php esc_html_e( 'Your journey starts here', 'event-checkin' ); ?></p>
                    </div>
                </div>

                <!-- Scanner Screen -->
                <div class="ec-kiosk-screen ec-kiosk-screen--scanner active" id="ec-screen-scanner">
                    <h3 class="ec-section-title"><?php esc_html_e( 'Scan your QR code', 'event-checkin' ); ?></h3>
                    <p class="ec-label"><?php esc_html_e( 'Hold your QR code up to the camera', 'event-checkin' ); ?></p>
                    <div class="ec-scanner-viewport" id="ec-scanner-viewport"></div>
                    <div class="ec-kiosk-stats">
                        <span id="ec-stats-total">0</span> <?php esc_html_e( 'registered', 'event-checkin' ); ?> |
                        <span id="ec-stats-checkedin">0</span> <?php esc_html_e( 'checked in', 'event-checkin' ); ?>
                    </div>
                </div>

                <!-- Processing Screen -->
                <div class="ec-kiosk-screen ec-kiosk-screen--processing" id="ec-screen-processing">
                    <div class="ec-spinner"></div>
                    <p><?php esc_html_e( 'Processing...', 'event-checkin' ); ?></p>
                </div>

                <!-- Signature Screen -->
                <div class="ec-kiosk-screen ec-kiosk-screen--signature" id="ec-screen-signature">
                    <h3 class="ec-section-title"><?php esc_html_e( 'Sign to confirm', 'event-checkin' ); ?></h3>
                    <p class="ec-welcome-name" id="ec-signature-name"></p>
                    <div class="ec-signature-wrapper">
                        <canvas id="ec-signature-pad" width="600" height="200"></canvas>
                    </div>
                    <div class="ec-signature-actions">
                        <button type="button" class="ec-btn ec-btn--secondary" id="ec-sig-clear"><?php esc_html_e( 'Clear', 'event-checkin' ); ?></button>
                        <button type="button" class="ec-btn ec-btn--primary" id="ec-sig-confirm"><?php esc_html_e( 'Confirm & Check-In', 'event-checkin' ); ?></button>
                    </div>
                </div>

                <!-- Success Screen -->
                <div class="ec-kiosk-screen ec-kiosk-screen--success" id="ec-screen-success">
                    <div class="ec-success-checkmark">&#10003;</div>
                    <h2 class="ec-section-title" id="ec-success-message"><?php esc_html_e( 'Check-in complete', 'event-checkin' ); ?></h2>
                    <p class="ec-welcome-name" id="ec-success-name"></p>
                    <div class="ec-auto-reset">
                        <p><?php esc_html_e( 'Returning to scanner...', 'event-checkin' ); ?></p>
                        <div class="ec-countdown-bar"><div class="ec-countdown-fill" id="ec-countdown-fill"></div></div>
                    </div>
                </div>

                <!-- Already Checked In Screen -->
                <div class="ec-kiosk-screen ec-kiosk-screen--already" id="ec-screen-already">
                    <div class="ec-already-icon">&#8505;</div>
                    <h2 class="ec-section-title"><?php esc_html_e( 'Already checked in', 'event-checkin' ); ?></h2>
                    <p class="ec-welcome-name" id="ec-already-name"></p>
                    <div class="ec-auto-reset">
                        <p><?php esc_html_e( 'Returning to scanner...', 'event-checkin' ); ?></p>
                        <div class="ec-countdown-bar"><div class="ec-countdown-fill" id="ec-countdown-fill-already"></div></div>
                    </div>
                </div>

                <!-- Error Screen -->
                <div class="ec-kiosk-screen ec-kiosk-screen--error" id="ec-screen-error">
                    <div class="ec-error-icon">&#10007;</div>
                    <h2 class="ec-section-title" id="ec-error-message"><?php esc_html_e( 'An error occurred', 'event-checkin' ); ?></h2>
                    <div class="ec-auto-reset">
                        <p><?php esc_html_e( 'Returning to scanner...', 'event-checkin' ); ?></p>
                        <div class="ec-countdown-bar"><div class="ec-countdown-fill" id="ec-countdown-fill-error"></div></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Process a check-in by QR token.
     *
     * @param string   $qr_token   The QR token.
     * @param int|null $user_id    The staff user ID performing check-in (null for self).
     * @return array Result array with 'status' and 'data' keys.
     */
    public static function process_checkin( $qr_token, $user_id = null ) {
        global $wpdb;

        $qr_token = sanitize_text_field( $qr_token );

        if ( empty( $qr_token ) || strlen( $qr_token ) !== 64 ) {
            return array(
                'status' => 'error',
                'code'   => 'invalid_token',
                'message' => __( 'Invalid QR code.', 'event-checkin' ),
            );
        }

        // Start transaction for atomic check-in.
        $wpdb->query( 'START TRANSACTION' );

        $registration = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT r.*, e.title as event_title, e.require_signature
                 FROM {$wpdb->prefix}ec_registrations r
                 JOIN {$wpdb->prefix}ec_events e ON r.event_id = e.id
                 WHERE r.qr_token = %s
                 FOR UPDATE",
                $qr_token
            )
        );

        if ( ! $registration ) {
            $wpdb->query( 'ROLLBACK' );
            return array(
                'status'  => 'error',
                'code'    => 'not_found',
                'message' => __( 'Registration not found.', 'event-checkin' ),
            );
        }

        if ( $registration->status === 'cancelled' ) {
            $wpdb->query( 'ROLLBACK' );
            return array(
                'status'  => 'error',
                'code'    => 'cancelled',
                'message' => __( 'This registration has been cancelled.', 'event-checkin' ),
            );
        }

        if ( $registration->status === 'checked_in' ) {
            $wpdb->query( 'COMMIT' );
            return array(
                'status'  => 'already_checked_in',
                'code'    => 'already_checked_in',
                'message' => __( 'Already checked in.', 'event-checkin' ),
                'data'    => array(
                    'name'          => $registration->first_name . ' ' . $registration->last_name,
                    'checked_in_at' => $registration->checked_in_at,
                ),
            );
        }

        // If signature is required, don't mark as checked in yet -- just validate and return.
        if ( $registration->require_signature && ! $registration->signature_data ) {
            $wpdb->query( 'COMMIT' );
            return array(
                'status'  => 'needs_signature',
                'code'    => 'needs_signature',
                'message' => __( 'Signature required.', 'event-checkin' ),
                'data'    => array(
                    'registration_id' => $registration->id,
                    'name'            => $registration->first_name . ' ' . $registration->last_name,
                    'qr_token'        => $qr_token,
                ),
            );
        }

        // Perform check-in.
        $now = current_time( 'mysql', true );
        $wpdb->update(
            $wpdb->prefix . 'ec_registrations',
            array(
                'status'        => 'checked_in',
                'checked_in_at' => $now,
            ),
            array( 'id' => $registration->id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        // Log the check-in.
        $wpdb->insert(
            $wpdb->prefix . 'ec_checkin_log',
            array(
                'registration_id' => $registration->id,
                'event_id'        => $registration->event_id,
                'action'          => 'checkin',
                'performed_by'    => $user_id,
                'ip_address'      => Security::get_client_ip(),
                'user_agent'      => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
            ),
            array( '%d', '%d', '%s', '%d', '%s', '%s' )
        );

        $wpdb->query( 'COMMIT' );

        return array(
            'status'  => 'success',
            'code'    => 'checked_in',
            'message' => __( 'Check-in successful!', 'event-checkin' ),
            'data'    => array(
                'name'          => $registration->first_name . ' ' . $registration->last_name,
                'checked_in_at' => $now,
            ),
        );
    }

    /**
     * Complete check-in after signature is submitted.
     *
     * @param string $qr_token       The QR token.
     * @param string $signature_data Base64 signature data.
     * @param int|null $user_id      Staff user ID.
     * @return array Result.
     */
    public static function complete_checkin_with_signature( $qr_token, $signature_data, $user_id = null ) {
        global $wpdb;

        $qr_token = sanitize_text_field( $qr_token );

        if ( ! Security::validate_signature_data( $signature_data ) ) {
            return array(
                'status'  => 'error',
                'code'    => 'invalid_signature',
                'message' => __( 'Invalid signature data.', 'event-checkin' ),
            );
        }

        $wpdb->query( 'START TRANSACTION' );

        $registration = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ec_registrations WHERE qr_token = %s FOR UPDATE",
                $qr_token
            )
        );

        if ( ! $registration ) {
            $wpdb->query( 'ROLLBACK' );
            return array(
                'status'  => 'error',
                'code'    => 'not_found',
                'message' => __( 'Registration not found.', 'event-checkin' ),
            );
        }

        if ( $registration->status === 'checked_in' ) {
            $wpdb->query( 'COMMIT' );
            return array(
                'status' => 'already_checked_in',
                'code'   => 'already_checked_in',
                'data'   => array( 'name' => $registration->first_name . ' ' . $registration->last_name ),
            );
        }

        $now = current_time( 'mysql', true );
        $wpdb->update(
            $wpdb->prefix . 'ec_registrations',
            array(
                'status'         => 'checked_in',
                'checked_in_at'  => $now,
                'signature_data' => $signature_data,
            ),
            array( 'id' => $registration->id ),
            array( '%s', '%s', '%s' ),
            array( '%d' )
        );

        $wpdb->insert(
            $wpdb->prefix . 'ec_checkin_log',
            array(
                'registration_id' => $registration->id,
                'event_id'        => $registration->event_id,
                'action'          => 'checkin',
                'performed_by'    => $user_id,
                'ip_address'      => Security::get_client_ip(),
                'user_agent'      => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
            ),
            array( '%d', '%d', '%s', '%d', '%s', '%s' )
        );

        $wpdb->query( 'COMMIT' );

        return array(
            'status'  => 'success',
            'code'    => 'checked_in',
            'message' => __( 'Check-in successful!', 'event-checkin' ),
            'data'    => array(
                'name'          => $registration->first_name . ' ' . $registration->last_name,
                'checked_in_at' => $now,
            ),
        );
    }
}
