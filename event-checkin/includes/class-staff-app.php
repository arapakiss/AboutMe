<?php
namespace EventCheckin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Staff Mobile App - a mobile-first shortcode-based SPA for event staff.
 *
 * Provides a bottom-navigation mobile interface with:
 *  - Guest list with search and manual check-in/out
 *  - QR code scanner with profile display
 *  - Add registration form
 *  - Check-in history log
 *
 * Usage: [event_staff_app id="EVENT_ID"]
 */
class Staff_App {

    public static function init() {
        add_shortcode( 'event_staff_app', array( __CLASS__, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
    }

    /**
     * Enqueue mobile app assets only when the shortcode is present.
     */
    public static function enqueue_assets() {
        if ( ! is_singular() ) {
            return;
        }

        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'event_staff_app' ) ) {
            return;
        }

        // html5-qrcode library (already bundled for kiosk).
        wp_enqueue_script(
            'html5-qrcode',
            EC_PLUGIN_URL . 'assets/js/vendor/html5-qrcode.min.js',
            array(),
            '2.3.8',
            true
        );

        wp_enqueue_style(
            'ec-staff-app',
            EC_PLUGIN_URL . 'assets/css/staff-app.css',
            array(),
            EC_VERSION
        );

        wp_enqueue_script(
            'ec-staff-app',
            EC_PLUGIN_URL . 'assets/js/staff-app.js',
            array( 'jquery', 'html5-qrcode' ),
            EC_VERSION,
            true
        );

        wp_localize_script( 'ec-staff-app', 'ecStaffApp', array(
            'restUrl' => esc_url_raw( rest_url( 'event-checkin/v1/staff' ) ),
            'nonce'   => wp_create_nonce( 'wp_rest' ),
            'i18n'    => array(
                'guests'           => __( 'Guests', 'event-checkin' ),
                'scan'             => __( 'Scan', 'event-checkin' ),
                'add'              => __( 'Add', 'event-checkin' ),
                'history'          => __( 'History', 'event-checkin' ),
                'searchPlaceholder' => __( 'Search by name, email, phone...', 'event-checkin' ),
                'noGuests'         => __( 'No guests found.', 'event-checkin' ),
                'noHistory'        => __( 'No check-in history yet.', 'event-checkin' ),
                'checkedIn'        => __( 'Checked In', 'event-checkin' ),
                'registered'       => __( 'Registered', 'event-checkin' ),
                'cancelled'        => __( 'Cancelled', 'event-checkin' ),
                'checkIn'          => __( 'Check In', 'event-checkin' ),
                'undoCheckIn'      => __( 'Undo Check-In', 'event-checkin' ),
                'scanQR'           => __( 'Scan QR Code', 'event-checkin' ),
                'pointCamera'      => __( 'Point the camera at a QR code', 'event-checkin' ),
                'cameraError'      => __( 'Camera access denied or not available.', 'event-checkin' ),
                'notFound'         => __( 'Registration not found for this QR code.', 'event-checkin' ),
                'addGuest'         => __( 'Add Guest', 'event-checkin' ),
                'firstName'        => __( 'First Name', 'event-checkin' ),
                'lastName'         => __( 'Last Name', 'event-checkin' ),
                'email'            => __( 'Email', 'event-checkin' ),
                'phone'            => __( 'Phone', 'event-checkin' ),
                'submit'           => __( 'Register Guest', 'event-checkin' ),
                'adding'           => __( 'Adding...', 'event-checkin' ),
                'addSuccess'       => __( 'Guest registered successfully!', 'event-checkin' ),
                'addError'         => __( 'Failed to add guest.', 'event-checkin' ),
                'required'         => __( 'Please fill in all required fields.', 'event-checkin' ),
                'duplicateEmail'   => __( 'This email is already registered.', 'event-checkin' ),
                'error'            => __( 'An error occurred. Please try again.', 'event-checkin' ),
                'loading'          => __( 'Loading...', 'event-checkin' ),
                'profile'          => __( 'Guest Profile', 'event-checkin' ),
                'back'             => __( 'Back', 'event-checkin' ),
                'status'           => __( 'Status', 'event-checkin' ),
                'registeredAt'     => __( 'Registered', 'event-checkin' ),
                'checkedInAt'      => __( 'Checked In At', 'event-checkin' ),
                'allStatuses'      => __( 'All', 'event-checkin' ),
                'total'            => __( 'Total', 'event-checkin' ),
                'manualCheckin'    => __( 'Manual check-in', 'event-checkin' ),
                'manualUncheckin'  => __( 'Manual undo check-in', 'event-checkin' ),
                'qrCheckin'        => __( 'QR scan check-in', 'event-checkin' ),
                'scanAgain'        => __( 'Scan Another', 'event-checkin' ),
            ),
        ) );
    }

    /**
     * Render the staff app shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public static function render_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'event_staff_app' );
        $event_id = absint( $atts['id'] );

        if ( ! $event_id ) {
            return '<p class="ec-error">' . esc_html__( 'Invalid event ID.', 'event-checkin' ) . '</p>';
        }

        if ( ! current_user_can( 'ec_manage_checkin' ) ) {
            return '<p class="ec-error">' . esc_html__( 'You do not have permission to access the staff app.', 'event-checkin' ) . '</p>';
        }

        global $wpdb;
        $event = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ec_events WHERE id = %d", $event_id )
        );

        if ( ! $event ) {
            return '<p class="ec-error">' . esc_html__( 'Event not found.', 'event-checkin' ) . '</p>';
        }

        ob_start();
        ?>
        <div class="ec-staff-app" id="ec-staff-app" data-event-id="<?php echo intval( $event_id ); ?>">

            <!-- Top Bar -->
            <header class="ec-sa-header">
                <div class="ec-sa-header-title">
                    <span class="ec-sa-event-name"><?php echo esc_html( $event->title ); ?></span>
                    <span class="ec-sa-event-badge" id="ec-sa-stats-badge">0 / 0</span>
                </div>
            </header>

            <!-- Screens Container -->
            <main class="ec-sa-main">

                <!-- Guests Screen -->
                <section class="ec-sa-screen ec-sa-screen--guests active" id="ec-sa-screen-guests">
                    <div class="ec-sa-search-bar">
                        <input type="search" id="ec-sa-search" placeholder="" class="ec-sa-search-input" autocomplete="off">
                        <select id="ec-sa-status-filter" class="ec-sa-filter-select">
                            <option value=""><?php esc_html_e( 'All', 'event-checkin' ); ?></option>
                            <option value="registered"><?php esc_html_e( 'Registered', 'event-checkin' ); ?></option>
                            <option value="checked_in"><?php esc_html_e( 'Checked In', 'event-checkin' ); ?></option>
                            <option value="cancelled"><?php esc_html_e( 'Cancelled', 'event-checkin' ); ?></option>
                        </select>
                    </div>
                    <div class="ec-sa-guest-list" id="ec-sa-guest-list">
                        <div class="ec-sa-loading"><?php esc_html_e( 'Loading...', 'event-checkin' ); ?></div>
                    </div>
                    <div class="ec-sa-load-more" id="ec-sa-load-more" style="display:none;">
                        <button type="button" class="ec-sa-btn ec-sa-btn--secondary" id="ec-sa-btn-load-more">
                            <?php esc_html_e( 'Load More', 'event-checkin' ); ?>
                        </button>
                    </div>
                </section>

                <!-- Scan Screen -->
                <section class="ec-sa-screen ec-sa-screen--scan" id="ec-sa-screen-scan">
                    <div class="ec-sa-scan-container">
                        <div class="ec-sa-scanner-viewport" id="ec-sa-scanner-viewport"></div>
                        <p class="ec-sa-scan-hint" id="ec-sa-scan-hint"><?php esc_html_e( 'Point the camera at a QR code', 'event-checkin' ); ?></p>
                    </div>
                    <!-- Scan result overlay -->
                    <div class="ec-sa-scan-result" id="ec-sa-scan-result" style="display:none;">
                        <div class="ec-sa-profile-card" id="ec-sa-scan-profile"></div>
                    </div>
                </section>

                <!-- Add Guest Screen -->
                <section class="ec-sa-screen ec-sa-screen--add" id="ec-sa-screen-add">
                    <div class="ec-sa-form-container">
                        <h2 class="ec-sa-screen-title"><?php esc_html_e( 'Add Guest', 'event-checkin' ); ?></h2>
                        <form id="ec-sa-add-form" class="ec-sa-form" novalidate>
                            <div class="ec-sa-field">
                                <label for="ec-sa-add-fname"><?php esc_html_e( 'First Name', 'event-checkin' ); ?> *</label>
                                <input type="text" id="ec-sa-add-fname" name="first_name" required autocomplete="given-name">
                            </div>
                            <div class="ec-sa-field">
                                <label for="ec-sa-add-lname"><?php esc_html_e( 'Last Name', 'event-checkin' ); ?> *</label>
                                <input type="text" id="ec-sa-add-lname" name="last_name" required autocomplete="family-name">
                            </div>
                            <div class="ec-sa-field">
                                <label for="ec-sa-add-email"><?php esc_html_e( 'Email', 'event-checkin' ); ?> *</label>
                                <input type="email" id="ec-sa-add-email" name="email" required autocomplete="email">
                            </div>
                            <div class="ec-sa-field">
                                <label for="ec-sa-add-phone"><?php esc_html_e( 'Phone', 'event-checkin' ); ?></label>
                                <input type="tel" id="ec-sa-add-phone" name="phone" autocomplete="tel">
                            </div>
                            <div class="ec-sa-form-actions">
                                <button type="submit" class="ec-sa-btn ec-sa-btn--primary ec-sa-btn--full" id="ec-sa-btn-add">
                                    <?php esc_html_e( 'Register Guest', 'event-checkin' ); ?>
                                </button>
                            </div>
                            <div class="ec-sa-form-message" id="ec-sa-add-message" style="display:none;"></div>
                        </form>
                    </div>
                </section>

                <!-- History Screen -->
                <section class="ec-sa-screen ec-sa-screen--history" id="ec-sa-screen-history">
                    <h2 class="ec-sa-screen-title"><?php esc_html_e( 'Check-in History', 'event-checkin' ); ?></h2>
                    <div class="ec-sa-history-list" id="ec-sa-history-list">
                        <div class="ec-sa-loading"><?php esc_html_e( 'Loading...', 'event-checkin' ); ?></div>
                    </div>
                </section>

                <!-- Profile Overlay -->
                <section class="ec-sa-screen ec-sa-screen--profile" id="ec-sa-screen-profile" style="display:none;">
                    <div class="ec-sa-profile-header">
                        <button type="button" class="ec-sa-back-btn" id="ec-sa-btn-back-profile">&larr; <?php esc_html_e( 'Back', 'event-checkin' ); ?></button>
                    </div>
                    <div class="ec-sa-profile-content" id="ec-sa-profile-content"></div>
                </section>

            </main>

            <!-- Bottom Navigation -->
            <nav class="ec-sa-bottom-nav">
                <button type="button" class="ec-sa-nav-item active" data-screen="guests">
                    <svg class="ec-sa-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 00-3-3.87"/>
                        <path d="M16 3.13a4 4 0 010 7.75"/>
                    </svg>
                    <span><?php esc_html_e( 'Guests', 'event-checkin' ); ?></span>
                </button>
                <button type="button" class="ec-sa-nav-item" data-screen="scan">
                    <svg class="ec-sa-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <rect x="3" y="3" width="7" height="7"/>
                        <rect x="14" y="3" width="7" height="7"/>
                        <rect x="14" y="14" width="7" height="7"/>
                        <rect x="3" y="14" width="7" height="7"/>
                    </svg>
                    <span><?php esc_html_e( 'Scan', 'event-checkin' ); ?></span>
                </button>
                <button type="button" class="ec-sa-nav-item" data-screen="add">
                    <svg class="ec-sa-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="16"/>
                        <line x1="8" y1="12" x2="16" y2="12"/>
                    </svg>
                    <span><?php esc_html_e( 'Add', 'event-checkin' ); ?></span>
                </button>
                <button type="button" class="ec-sa-nav-item" data-screen="history">
                    <svg class="ec-sa-nav-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <polyline points="12 6 12 12 16 14"/>
                    </svg>
                    <span><?php esc_html_e( 'History', 'event-checkin' ); ?></span>
                </button>
            </nav>

            <!-- Toast container -->
            <div class="ec-sa-toast-container" id="ec-sa-toast-container"></div>
        </div>
        <?php
        return ob_get_clean();
    }

    // =========================================================================
    // REST API Routes
    // =========================================================================

    /**
     * Register REST API routes for the staff app.
     */
    public static function register_rest_routes() {
        $ns = 'event-checkin/v1/staff';

        // GET registrations for an event.
        register_rest_route( $ns, '/registrations/(?P<event_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_registrations' ),
            'permission_callback' => function () {
                return current_user_can( 'ec_manage_checkin' );
            },
            'args' => array(
                'event_id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
                's'        => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
                'status'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_key', 'default' => '' ),
                'page'     => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 1 ),
                'per_page' => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 30 ),
            ),
        ) );

        // GET single registration details.
        register_rest_route( $ns, '/registration/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_registration' ),
            'permission_callback' => function () {
                return current_user_can( 'ec_manage_checkin' );
            },
            'args' => array(
                'id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
            ),
        ) );

        // POST toggle check-in status.
        register_rest_route( $ns, '/toggle-checkin', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_toggle_checkin' ),
            'permission_callback' => function () {
                return current_user_can( 'ec_manage_checkin' );
            },
            'args' => array(
                'reg_id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
            ),
        ) );

        // POST add registration.
        register_rest_route( $ns, '/add-registration', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_add_registration' ),
            'permission_callback' => function () {
                return current_user_can( 'ec_manage_checkin' );
            },
            'args' => array(
                'event_id'   => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
                'first_name' => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'last_name'  => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'email'      => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_email' ),
                'phone'      => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '' ),
            ),
        ) );

        // GET check-in history.
        register_rest_route( $ns, '/history/(?P<event_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_history' ),
            'permission_callback' => function () {
                return current_user_can( 'ec_manage_checkin' );
            },
            'args' => array(
                'event_id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
                'page'     => array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 1 ),
            ),
        ) );

        // GET event stats.
        register_rest_route( $ns, '/stats/(?P<event_id>\d+)', array(
            'methods'             => 'GET',
            'callback'            => array( __CLASS__, 'rest_get_stats' ),
            'permission_callback' => function () {
                return current_user_can( 'ec_manage_checkin' );
            },
            'args' => array(
                'event_id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
            ),
        ) );

        // POST lookup by QR token.
        register_rest_route( $ns, '/lookup-token', array(
            'methods'             => 'POST',
            'callback'            => array( __CLASS__, 'rest_lookup_token' ),
            'permission_callback' => function () {
                return current_user_can( 'ec_manage_checkin' );
            },
            'args' => array(
                'token'    => array( 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
                'event_id' => array( 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ),
            ),
        ) );
    }

    /**
     * REST: Get paginated registrations with search/filter.
     */
    public static function rest_get_registrations( $request ) {
        global $wpdb;
        $event_id = $request->get_param( 'event_id' );
        $search   = $request->get_param( 's' );
        $status   = $request->get_param( 'status' );
        $page     = max( 1, $request->get_param( 'page' ) );
        $per_page = min( 100, max( 1, $request->get_param( 'per_page' ) ) );
        $offset   = ( $page - 1 ) * $per_page;

        $where = $wpdb->prepare( "WHERE event_id = %d", $event_id );

        if ( $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare(
                " AND (first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR phone LIKE %s)",
                $like, $like, $like, $like
            );
        }

        if ( $status && in_array( $status, array( 'registered', 'checked_in', 'cancelled' ), true ) ) {
            $where .= $wpdb->prepare( " AND status = %s", $status );
        }

        $table = $wpdb->prefix . 'ec_registrations';

        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" );

        $rows = $wpdb->get_results(
            "SELECT id, first_name, last_name, email, phone, status, checked_in_at, created_at
             FROM {$table} {$where}
             ORDER BY created_at DESC
             LIMIT {$per_page} OFFSET {$offset}"
        );

        $items = array();
        foreach ( $rows as $row ) {
            $items[] = array(
                'id'            => (int) $row->id,
                'first_name'    => $row->first_name,
                'last_name'     => $row->last_name,
                'email'         => $row->email,
                'phone'         => $row->phone,
                'status'        => $row->status,
                'checked_in_at' => $row->checked_in_at,
                'created_at'    => $row->created_at,
            );
        }

        return new \WP_REST_Response( array(
            'items'      => $items,
            'total'      => $total,
            'page'       => $page,
            'total_pages' => ceil( $total / $per_page ),
        ), 200 );
    }

    /**
     * REST: Get single registration details.
     */
    public static function rest_get_registration( $request ) {
        global $wpdb;
        $id = $request->get_param( 'id' );

        $reg = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT r.*, e.title as event_title, e.event_date, e.location, e.custom_fields as event_custom_fields
                 FROM {$wpdb->prefix}ec_registrations r
                 JOIN {$wpdb->prefix}ec_events e ON r.event_id = e.id
                 WHERE r.id = %d",
                $id
            )
        );

        if ( ! $reg ) {
            return new \WP_REST_Response( array( 'message' => 'Registration not found.' ), 404 );
        }

        $custom_data    = $reg->custom_data ? json_decode( $reg->custom_data, true ) : array();
        $custom_fields  = $reg->event_custom_fields ? json_decode( $reg->event_custom_fields, true ) : array();

        // Build custom fields with values.
        $custom = array();
        foreach ( $custom_fields as $field ) {
            $key = sanitize_key( $field['label'] );
            $custom[] = array(
                'label' => $field['label'],
                'value' => isset( $custom_data[ $key ] ) ? $custom_data[ $key ] : ( isset( $custom_data[ $field['label'] ] ) ? $custom_data[ $field['label'] ] : '' ),
            );
        }

        $qr_url = QRCode::get_url( $reg->qr_token );

        return new \WP_REST_Response( array(
            'id'            => (int) $reg->id,
            'first_name'    => $reg->first_name,
            'last_name'     => $reg->last_name,
            'email'         => $reg->email,
            'phone'         => $reg->phone,
            'status'        => $reg->status,
            'checked_in_at' => $reg->checked_in_at,
            'created_at'    => $reg->created_at,
            'signature'     => ! empty( $reg->signature_data ),
            'custom_fields' => $custom,
            'qr_url'        => $qr_url ? $qr_url : '',
            'event_title'   => $reg->event_title,
        ), 200 );
    }

    /**
     * REST: Toggle check-in / undo check-in.
     */
    public static function rest_toggle_checkin( $request ) {
        global $wpdb;
        $reg_id = $request->get_param( 'reg_id' );
        $table  = $wpdb->prefix . 'ec_registrations';

        $reg = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $reg_id ) );

        if ( ! $reg ) {
            return new \WP_REST_Response( array( 'message' => 'Registration not found.' ), 404 );
        }

        if ( $reg->status === 'cancelled' ) {
            return new \WP_REST_Response( array( 'message' => 'Cannot modify a cancelled registration.' ), 400 );
        }

        $user_id = get_current_user_id();

        if ( $reg->status === 'checked_in' ) {
            // Undo check-in.
            $wpdb->update(
                $table,
                array( 'status' => 'registered', 'checked_in_at' => null ),
                array( 'id' => $reg_id ),
                array( '%s', null ),
                array( '%d' )
            );

            // Log the undo.
            $wpdb->insert(
                $wpdb->prefix . 'ec_checkin_log',
                array(
                    'registration_id' => $reg_id,
                    'event_id'        => $reg->event_id,
                    'action'          => 'undo_checkin',
                    'performed_by'    => $user_id,
                    'ip_address'      => $_SERVER['REMOTE_ADDR'] ?? '',
                    'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ),
                array( '%d', '%d', '%s', '%d', '%s', '%s' )
            );

            return new \WP_REST_Response( array(
                'status'  => 'registered',
                'message' => $reg->first_name . ' ' . $reg->last_name . ' check-in undone.',
            ), 200 );
        } else {
            // Check in.
            $now = current_time( 'mysql' );
            $wpdb->update(
                $table,
                array( 'status' => 'checked_in', 'checked_in_at' => $now ),
                array( 'id' => $reg_id ),
                array( '%s', '%s' ),
                array( '%d' )
            );

            // Log the check-in.
            $wpdb->insert(
                $wpdb->prefix . 'ec_checkin_log',
                array(
                    'registration_id' => $reg_id,
                    'event_id'        => $reg->event_id,
                    'action'          => 'staff_checkin',
                    'performed_by'    => $user_id,
                    'ip_address'      => $_SERVER['REMOTE_ADDR'] ?? '',
                    'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
                ),
                array( '%d', '%d', '%s', '%d', '%s', '%s' )
            );

            return new \WP_REST_Response( array(
                'status'        => 'checked_in',
                'checked_in_at' => $now,
                'message'       => $reg->first_name . ' ' . $reg->last_name . ' checked in!',
            ), 200 );
        }
    }

    /**
     * REST: Add a new registration.
     */
    public static function rest_add_registration( $request ) {
        global $wpdb;

        $event_id   = $request->get_param( 'event_id' );
        $first_name = $request->get_param( 'first_name' );
        $last_name  = $request->get_param( 'last_name' );
        $email      = $request->get_param( 'email' );
        $phone      = $request->get_param( 'phone' );

        // Check event exists.
        $event = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ec_events WHERE id = %d", $event_id
        ) );
        if ( ! $event ) {
            return new \WP_REST_Response( array( 'message' => 'Event not found.' ), 404 );
        }

        // Check duplicate.
        $exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ec_registrations WHERE event_id = %d AND email = %s AND status != 'cancelled'",
            $event_id, $email
        ) );
        if ( $exists ) {
            return new \WP_REST_Response( array( 'message' => 'This email is already registered for this event.' ), 409 );
        }

        $qr_token = bin2hex( random_bytes( 32 ) );

        $wpdb->insert(
            $wpdb->prefix . 'ec_registrations',
            array(
                'event_id'   => $event_id,
                'qr_token'   => $qr_token,
                'first_name' => $first_name,
                'last_name'  => $last_name,
                'email'      => $email,
                'phone'      => $phone,
                'status'     => 'registered',
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        $reg_id = $wpdb->insert_id;

        // Generate QR code and send email.
        $qr_url = QRCode::generate( $qr_token, $event_id );

        $upload_dir = wp_upload_dir();
        $qr_file    = $upload_dir['basedir'] . '/event-checkin/qrcodes/qr-' . substr( $qr_token, 0, 16 ) . '.png';

        $vars = array(
            'first_name'     => $first_name,
            'last_name'      => $last_name,
            'event_title'    => $event->title,
            'event_date'     => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $event->event_date ) ),
            'event_location' => $event->location,
            'qr_code_url'    => $qr_url ? $qr_url : '',
            'site_name'      => get_bloginfo( 'name' ),
        );

        Email::send( $email, $vars, $qr_file );

        return new \WP_REST_Response( array(
            'message' => $first_name . ' ' . $last_name . ' registered successfully.',
            'reg_id'  => $reg_id,
        ), 201 );
    }

    /**
     * REST: Get check-in history.
     */
    public static function rest_get_history( $request ) {
        global $wpdb;
        $event_id = $request->get_param( 'event_id' );
        $page     = max( 1, $request->get_param( 'page' ) );
        $per_page = 50;
        $offset   = ( $page - 1 ) * $per_page;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT l.*, r.first_name, r.last_name, r.email,
                    u.display_name as performed_by_name
             FROM {$wpdb->prefix}ec_checkin_log l
             JOIN {$wpdb->prefix}ec_registrations r ON l.registration_id = r.id
             LEFT JOIN {$wpdb->base_prefix}users u ON l.performed_by = u.ID
             WHERE l.event_id = %d
             ORDER BY l.created_at DESC
             LIMIT {$per_page} OFFSET {$offset}",
            $event_id
        ) );

        $items = array();
        foreach ( $rows as $row ) {
            $items[] = array(
                'id'              => (int) $row->id,
                'registration_id' => (int) $row->registration_id,
                'first_name'      => $row->first_name,
                'last_name'       => $row->last_name,
                'email'           => $row->email,
                'action'          => $row->action,
                'performed_by'    => $row->performed_by_name ? $row->performed_by_name : __( 'System', 'event-checkin' ),
                'created_at'      => $row->created_at,
            );
        }

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ec_checkin_log WHERE event_id = %d",
            $event_id
        ) );

        return new \WP_REST_Response( array(
            'items'       => $items,
            'total'       => $total,
            'total_pages' => ceil( $total / $per_page ),
        ), 200 );
    }

    /**
     * REST: Get event stats.
     */
    public static function rest_get_stats( $request ) {
        global $wpdb;
        $event_id = $request->get_param( 'event_id' );
        $table    = $wpdb->prefix . 'ec_registrations';

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND status != 'cancelled'", $event_id
        ) );
        $checked_in = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND status = 'checked_in'", $event_id
        ) );

        return new \WP_REST_Response( array(
            'total'      => $total,
            'checked_in' => $checked_in,
        ), 200 );
    }

    /**
     * REST: Look up registration by QR token (extracted from scanned URL).
     */
    public static function rest_lookup_token( $request ) {
        global $wpdb;
        $token    = $request->get_param( 'token' );
        $event_id = $request->get_param( 'event_id' );

        $reg = $wpdb->get_row( $wpdb->prepare(
            "SELECT r.*, e.title as event_title
             FROM {$wpdb->prefix}ec_registrations r
             JOIN {$wpdb->prefix}ec_events e ON r.event_id = e.id
             WHERE r.qr_token = %s AND r.event_id = %d",
            $token, $event_id
        ) );

        if ( ! $reg ) {
            // Try without event_id filter (might be scanning from another event).
            $reg = $wpdb->get_row( $wpdb->prepare(
                "SELECT r.*, e.title as event_title
                 FROM {$wpdb->prefix}ec_registrations r
                 JOIN {$wpdb->prefix}ec_events e ON r.event_id = e.id
                 WHERE r.qr_token = %s",
                $token
            ) );
        }

        if ( ! $reg ) {
            return new \WP_REST_Response( array( 'message' => 'Registration not found.' ), 404 );
        }

        $custom_data   = $reg->custom_data ? json_decode( $reg->custom_data, true ) : array();
        $qr_url        = QRCode::get_url( $reg->qr_token );

        return new \WP_REST_Response( array(
            'id'            => (int) $reg->id,
            'first_name'    => $reg->first_name,
            'last_name'     => $reg->last_name,
            'email'         => $reg->email,
            'phone'         => $reg->phone,
            'status'        => $reg->status,
            'checked_in_at' => $reg->checked_in_at,
            'created_at'    => $reg->created_at,
            'event_title'   => $reg->event_title,
            'event_id'      => (int) $reg->event_id,
            'qr_url'        => $qr_url ? $qr_url : '',
            'same_event'    => (int) $reg->event_id === $event_id,
        ), 200 );
    }
}
