<?php
namespace EventCheckin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles frontend registration form rendering and submission.
 */
class Registration {

    public static function init() {
        add_shortcode( 'event_registration', array( __CLASS__, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_ec_register', array( __CLASS__, 'handle_registration' ) );
        add_action( 'wp_ajax_nopriv_ec_register', array( __CLASS__, 'handle_registration' ) );
    }

    /**
     * Enqueue public-facing assets.
     */
    public static function enqueue_assets() {
        if ( ! is_singular() ) {
            return;
        }

        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'event_registration' ) ) {
            return;
        }

        wp_enqueue_style(
            'ec-public',
            EC_PLUGIN_URL . 'assets/css/public.css',
            array(),
            EC_VERSION
        );

        wp_enqueue_script(
            'ec-registration',
            EC_PLUGIN_URL . 'assets/js/registration.js',
            array( 'jquery' ),
            EC_VERSION,
            true
        );

        wp_localize_script( 'ec-registration', 'ecRegistration', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ec_registration_nonce' ),
            'i18n'    => array(
                'submitting'    => __( 'Submitting...', 'event-checkin' ),
                'success'       => __( 'Registration successful! Check your email for the QR code.', 'event-checkin' ),
                'error'         => __( 'An error occurred. Please try again.', 'event-checkin' ),
                'required'      => __( 'This field is required.', 'event-checkin' ),
                'invalidEmail'  => __( 'Please enter a valid email address.', 'event-checkin' ),
            ),
        ) );
    }

    /**
     * Render the registration form shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public static function render_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'event_registration' );
        $event_id = absint( $atts['id'] );

        if ( ! $event_id ) {
            return '<p class="ec-error">' . esc_html__( 'Invalid event ID.', 'event-checkin' ) . '</p>';
        }

        global $wpdb;
        $event = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ec_events WHERE id = %d AND status = 'published'",
                $event_id
            )
        );

        if ( ! $event ) {
            return '<p class="ec-error">' . esc_html__( 'Event not found or not open for registration.', 'event-checkin' ) . '</p>';
        }

        // Check registration deadline.
        if ( $event->registration_deadline && strtotime( $event->registration_deadline ) < time() ) {
            return '<p class="ec-error">' . esc_html__( 'Registration deadline has passed.', 'event-checkin' ) . '</p>';
        }

        // Check capacity.
        if ( $event->max_capacity ) {
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}ec_registrations WHERE event_id = %d AND status != 'cancelled'",
                    $event_id
                )
            );
            if ( (int) $count >= (int) $event->max_capacity ) {
                return '<p class="ec-error">' . esc_html__( 'This event is full. Registration is closed.', 'event-checkin' ) . '</p>';
            }
        }

        $custom_fields = $event->custom_fields ? json_decode( $event->custom_fields, true ) : array();

        ob_start();
        ?>
        <div class="ec-registration-wrapper" id="ec-registration-<?php echo intval( $event_id ); ?>">
            <div class="ec-event-header">
                <h2><?php echo esc_html( $event->title ); ?></h2>
                <?php if ( $event->description ) : ?>
                    <p class="ec-event-description"><?php echo esc_html( $event->description ); ?></p>
                <?php endif; ?>
                <p class="ec-event-meta">
                    <span class="ec-date"><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $event->event_date ) ) ); ?></span>
                    <?php if ( $event->location ) : ?>
                        <span class="ec-location"><?php echo esc_html( $event->location ); ?></span>
                    <?php endif; ?>
                </p>
            </div>

            <form class="ec-registration-form" id="ec-form-<?php echo intval( $event_id ); ?>" novalidate>
                <input type="hidden" name="event_id" value="<?php echo intval( $event_id ); ?>">
                <input type="hidden" name="action" value="ec_register">
                <input type="hidden" name="ec_nonce" value="<?php echo esc_attr( wp_create_nonce( 'ec_registration_nonce' ) ); ?>">

                <!-- Honeypot field for bot detection -->
                <div style="display:none !important;" aria-hidden="true">
                    <input type="text" name="ec_website" value="" tabindex="-1" autocomplete="off">
                </div>

                <div class="ec-field">
                    <label for="ec-first-name-<?php echo intval( $event_id ); ?>"><?php esc_html_e( 'First Name', 'event-checkin' ); ?> *</label>
                    <input type="text" id="ec-first-name-<?php echo intval( $event_id ); ?>" name="first_name" required maxlength="100">
                </div>

                <div class="ec-field">
                    <label for="ec-last-name-<?php echo intval( $event_id ); ?>"><?php esc_html_e( 'Last Name', 'event-checkin' ); ?> *</label>
                    <input type="text" id="ec-last-name-<?php echo intval( $event_id ); ?>" name="last_name" required maxlength="100">
                </div>

                <div class="ec-field">
                    <label for="ec-email-<?php echo intval( $event_id ); ?>"><?php esc_html_e( 'Email', 'event-checkin' ); ?> *</label>
                    <input type="email" id="ec-email-<?php echo intval( $event_id ); ?>" name="email" required maxlength="255">
                </div>

                <div class="ec-field">
                    <label for="ec-phone-<?php echo intval( $event_id ); ?>"><?php esc_html_e( 'Phone', 'event-checkin' ); ?></label>
                    <input type="tel" id="ec-phone-<?php echo intval( $event_id ); ?>" name="phone" maxlength="50">
                </div>

                <?php foreach ( $custom_fields as $i => $field ) : ?>
                    <div class="ec-field">
                        <label for="ec-cf-<?php echo intval( $event_id ); ?>-<?php echo intval( $i ); ?>">
                            <?php echo esc_html( $field['label'] ); ?>
                            <?php if ( ! empty( $field['required'] ) ) : ?> *<?php endif; ?>
                        </label>
                        <?php
                        $field_name = 'custom_' . sanitize_key( $field['label'] );
                        $field_id   = 'ec-cf-' . intval( $event_id ) . '-' . intval( $i );
                        $required   = ! empty( $field['required'] ) ? 'required' : '';

                        switch ( $field['type'] ) :
                            case 'textarea':
                                ?>
                                <textarea id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_name ); ?>" <?php echo $required; ?> rows="3"></textarea>
                                <?php
                                break;
                            case 'select':
                                $options = array_map( 'trim', explode( ',', $field['options'] ?? '' ) );
                                ?>
                                <select id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_name ); ?>" <?php echo $required; ?>>
                                    <option value=""><?php esc_html_e( '-- Select --', 'event-checkin' ); ?></option>
                                    <?php foreach ( $options as $opt ) : ?>
                                        <option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php
                                break;
                            case 'checkbox':
                                ?>
                                <label class="ec-checkbox-label">
                                    <input type="checkbox" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_name ); ?>" value="1" <?php echo $required; ?>>
                                    <?php echo esc_html( $field['label'] ); ?>
                                </label>
                                <?php
                                break;
                            default: // text
                                ?>
                                <input type="text" id="<?php echo esc_attr( $field_id ); ?>" name="<?php echo esc_attr( $field_name ); ?>" <?php echo $required; ?> maxlength="255">
                                <?php
                                break;
                        endswitch;
                        ?>
                    </div>
                <?php endforeach; ?>

                <div class="ec-field ec-submit-field">
                    <button type="submit" class="ec-submit-btn"><?php esc_html_e( 'Register', 'event-checkin' ); ?></button>
                </div>

                <div class="ec-message" style="display:none;"></div>
            </form>

            <div class="ec-success-panel" style="display:none;">
                <div class="ec-success-icon">&#10003;</div>
                <h3><?php esc_html_e( 'Registration Successful!', 'event-checkin' ); ?></h3>
                <p><?php esc_html_e( 'Your QR code has been sent to your email. Please present it at the event entrance.', 'event-checkin' ); ?></p>
                <div class="ec-qr-preview"></div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Handle AJAX registration submission.
     */
    public static function handle_registration() {
        // Verify nonce.
        if ( ! check_ajax_referer( 'ec_registration_nonce', 'ec_nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Security check failed. Please refresh and try again.', 'event-checkin' ) ), 403 );
        }

        // Honeypot check.
        if ( ! empty( $_POST['ec_website'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Registration failed.', 'event-checkin' ) ), 400 );
        }

        // Rate limiting.
        if ( ! Security::check_rate_limit( Security::RATE_LIMIT_REGISTRATION ) ) {
            wp_send_json_error( array( 'message' => __( 'Too many registration attempts. Please try again later.', 'event-checkin' ) ), 429 );
        }

        $event_id   = absint( $_POST['event_id'] ?? 0 );
        $first_name = sanitize_text_field( $_POST['first_name'] ?? '' );
        $last_name  = sanitize_text_field( $_POST['last_name'] ?? '' );
        $email      = Security::validate_email( $_POST['email'] ?? '' );
        $phone      = sanitize_text_field( $_POST['phone'] ?? '' );

        // Validate required fields.
        if ( ! $event_id || ! $first_name || ! $last_name || ! $email ) {
            wp_send_json_error( array( 'message' => __( 'Please fill in all required fields.', 'event-checkin' ) ), 400 );
        }

        global $wpdb;

        // Verify event exists and is open.
        $event = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ec_events WHERE id = %d AND status = 'published'",
                $event_id
            )
        );

        if ( ! $event ) {
            wp_send_json_error( array( 'message' => __( 'Event not found or not open for registration.', 'event-checkin' ) ), 404 );
        }

        // Check deadline.
        if ( $event->registration_deadline && strtotime( $event->registration_deadline ) < time() ) {
            wp_send_json_error( array( 'message' => __( 'Registration deadline has passed.', 'event-checkin' ) ), 400 );
        }

        // Atomic capacity check using a transaction.
        $wpdb->query( 'START TRANSACTION' );

        if ( $event->max_capacity ) {
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->prefix}ec_registrations WHERE event_id = %d AND status != 'cancelled' FOR UPDATE",
                    $event_id
                )
            );

            if ( (int) $count >= (int) $event->max_capacity ) {
                $wpdb->query( 'ROLLBACK' );
                wp_send_json_error( array( 'message' => __( 'This event is full.', 'event-checkin' ) ), 400 );
            }
        }

        // Check duplicate registration.
        $existing = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$wpdb->prefix}ec_registrations WHERE email = %s AND event_id = %d AND status != 'cancelled'",
                $email,
                $event_id
            )
        );

        if ( $existing ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( array( 'message' => __( 'You are already registered for this event.', 'event-checkin' ) ), 400 );
        }

        // Collect custom field data.
        $custom_data = array();
        $custom_fields = $event->custom_fields ? json_decode( $event->custom_fields, true ) : array();
        foreach ( $custom_fields as $field ) {
            $key   = 'custom_' . sanitize_key( $field['label'] );
            $value = sanitize_text_field( $_POST[ $key ] ?? '' );

            if ( ! empty( $field['required'] ) && empty( $value ) ) {
                $wpdb->query( 'ROLLBACK' );
                wp_send_json_error( array(
                    'message' => sprintf( __( '%s is required.', 'event-checkin' ), $field['label'] ),
                ), 400 );
            }

            $custom_data[ $field['label'] ] = $value;
        }

        // Generate unique QR token.
        $qr_token = Security::generate_qr_token();

        // Insert registration.
        $inserted = $wpdb->insert(
            $wpdb->prefix . 'ec_registrations',
            array(
                'event_id'    => $event_id,
                'qr_token'    => $qr_token,
                'first_name'  => $first_name,
                'last_name'   => $last_name,
                'email'       => $email,
                'phone'       => $phone,
                'custom_data' => wp_json_encode( $custom_data ),
                'status'      => 'registered',
                'ip_address'  => Security::get_client_ip(),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( ! $inserted ) {
            $wpdb->query( 'ROLLBACK' );
            wp_send_json_error( array( 'message' => __( 'Registration failed. Please try again.', 'event-checkin' ) ), 500 );
        }

        $wpdb->query( 'COMMIT' );

        // Generate QR code.
        $qr_url = QRCode::generate( $qr_token, $event_id );

        // Queue confirmation email (non-blocking).
        $upload_dir = wp_upload_dir();
        $qr_file    = $upload_dir['basedir'] . '/event-checkin/qrcodes/qr-' . substr( $qr_token, 0, 16 ) . '.png';
        $event_date = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $event->event_date ) );

        Email::queue( $email, array(
            'first_name'     => $first_name,
            'last_name'      => $last_name,
            'event_title'    => $event->title,
            'event_date'     => $event_date,
            'event_location' => $event->location ?: '',
            'qr_code_url'    => $qr_url ?: '',
            'site_name'      => get_bloginfo( 'name' ),
        ), $qr_file );

        wp_send_json_success( array(
            'message' => __( 'Registration successful! Check your email for the QR code.', 'event-checkin' ),
            'qr_url'  => $qr_url,
        ) );
    }

    /**
     * Get event data with transient caching for high-traffic scenarios.
     *
     * @param int $event_id Event ID.
     * @return object|null Event object or null.
     */
    public static function get_cached_event( $event_id ) {
        $cache_key = 'ec_event_' . $event_id;
        $event     = get_transient( $cache_key );

        if ( false === $event ) {
            global $wpdb;
            $event = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ec_events WHERE id = %d",
                    $event_id
                )
            );

            if ( $event ) {
                set_transient( $cache_key, $event, 300 ); // Cache for 5 minutes.
            }
        }

        return $event;
    }
}
