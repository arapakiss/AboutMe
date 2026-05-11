<?php
namespace EventCheckin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin panel: event CRUD, registration list, settings.
 */
class Admin {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menus' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_post_ec_save_event', array( __CLASS__, 'handle_save_event' ) );
        add_action( 'admin_post_ec_delete_event', array( __CLASS__, 'handle_delete_event' ) );
        add_action( 'admin_post_ec_save_email_template', array( __CLASS__, 'handle_save_email_template' ) );
        add_action( 'admin_post_ec_download_qr', array( __CLASS__, 'handle_download_qr' ) );

        // AJAX handlers for dashboard operations.
        add_action( 'wp_ajax_ec_get_registration', array( __CLASS__, 'ajax_get_registration' ) );
        add_action( 'wp_ajax_ec_update_registration', array( __CLASS__, 'ajax_update_registration' ) );
        add_action( 'wp_ajax_ec_resend_email', array( __CLASS__, 'ajax_resend_email' ) );
        add_action( 'wp_ajax_ec_manual_checkin', array( __CLASS__, 'ajax_manual_checkin' ) );
        add_action( 'wp_ajax_ec_cancel_registration', array( __CLASS__, 'ajax_cancel_registration' ) );
        add_action( 'wp_ajax_ec_add_registration', array( __CLASS__, 'ajax_add_registration' ) );
    }

    /**
     * Register admin menu pages.
     */
    public static function register_menus() {
        add_menu_page(
            __( 'Event Check-in', 'event-checkin' ),
            __( 'Event Check-in', 'event-checkin' ),
            'ec_view_events',
            'event-checkin',
            array( __CLASS__, 'render_events_page' ),
            'dashicons-tickets-alt',
            30
        );

        add_submenu_page(
            'event-checkin',
            __( 'Events', 'event-checkin' ),
            __( 'Events', 'event-checkin' ),
            'ec_view_events',
            'event-checkin',
            array( __CLASS__, 'render_events_page' )
        );

        add_submenu_page(
            'event-checkin',
            __( 'Add Event', 'event-checkin' ),
            __( 'Add Event', 'event-checkin' ),
            'ec_manage_events',
            'ec-add-event',
            array( __CLASS__, 'render_event_form' )
        );

        add_submenu_page(
            'event-checkin',
            __( 'Registrations', 'event-checkin' ),
            __( 'Registrations', 'event-checkin' ),
            'ec_view_registrations',
            'ec-registrations',
            array( __CLASS__, 'render_registrations_page' )
        );

        // Hidden submenu page for event dashboard (accessed via event links).
        add_submenu_page(
            null,
            __( 'Event Dashboard', 'event-checkin' ),
            __( 'Event Dashboard', 'event-checkin' ),
            'ec_view_registrations',
            'ec-event-dashboard',
            array( __CLASS__, 'render_event_dashboard' )
        );

        add_submenu_page(
            'event-checkin',
            __( 'Email Template', 'event-checkin' ),
            __( 'Email Template', 'event-checkin' ),
            'ec_manage_settings',
            'ec-email-template',
            array( __CLASS__, 'render_email_template_page' )
        );

        add_submenu_page(
            'event-checkin',
            __( 'Settings', 'event-checkin' ),
            __( 'Settings', 'event-checkin' ),
            'ec_manage_settings',
            'ec-settings',
            array( __CLASS__, 'render_settings_page' )
        );
    }

    /**
     * Enqueue admin CSS and JS.
     *
     * @param string $hook Current admin page hook.
     */
    public static function enqueue_assets( $hook ) {
        $ec_pages = array(
            'toplevel_page_event-checkin',
            'event-check-in_page_ec-add-event',
            'event-check-in_page_ec-registrations',
            'event-check-in_page_ec-email-template',
            'event-check-in_page_ec-settings',
            'admin_page_ec-event-dashboard',
        );

        if ( ! in_array( $hook, $ec_pages, true ) ) {
            return;
        }

        wp_enqueue_style(
            'ec-admin',
            EC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            EC_VERSION
        );

        wp_enqueue_script(
            'ec-admin',
            EC_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            EC_VERSION,
            true
        );

        wp_localize_script( 'ec-admin', 'ecAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'ec_admin_nonce' ),
            'i18n'    => array(
                'confirmDelete'      => __( 'Are you sure you want to delete this event? This cannot be undone.', 'event-checkin' ),
                'confirmCancel'      => __( 'Are you sure you want to cancel this registration?', 'event-checkin' ),
                'confirmCheckin'     => __( 'Manually check in this person?', 'event-checkin' ),
                'saving'             => __( 'Saving...', 'event-checkin' ),
                'saved'              => __( 'Saved successfully.', 'event-checkin' ),
                'sending'            => __( 'Sending...', 'event-checkin' ),
                'emailSent'          => __( 'Email sent successfully.', 'event-checkin' ),
                'emailFailed'        => __( 'Failed to send email.', 'event-checkin' ),
                'error'              => __( 'An error occurred. Please try again.', 'event-checkin' ),
                'close'              => __( 'Close', 'event-checkin' ),
                'noResults'          => __( 'No registrations match your search.', 'event-checkin' ),
                'addSuccess'         => __( 'Registration added successfully.', 'event-checkin' ),
                'cancelSuccess'      => __( 'Registration cancelled.', 'event-checkin' ),
                'checkinSuccess'     => __( 'Check-in completed.', 'event-checkin' ),
                'resendTitle'        => __( 'Resend Confirmation Email', 'event-checkin' ),
                'editTitle'          => __( 'Edit Registration', 'event-checkin' ),
                'addTitle'           => __( 'Add Registration', 'event-checkin' ),
            ),
        ) );
    }

    /**
     * Render the events list page.
     */
    public static function render_events_page() {
        global $wpdb;
        $table = $wpdb->prefix . 'ec_events';
        $reg_table = $wpdb->prefix . 'ec_registrations';

        $events = $wpdb->get_results(
            "SELECT e.*, 
                    (SELECT COUNT(*) FROM {$reg_table} r WHERE r.event_id = e.id) as registration_count,
                    (SELECT COUNT(*) FROM {$reg_table} r WHERE r.event_id = e.id AND r.status = 'checked_in') as checkin_count
             FROM {$table} e 
             ORDER BY e.event_date DESC"
        );
        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline"><?php esc_html_e( 'Events', 'event-checkin' ); ?></h1>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ec-add-event' ) ); ?>" class="page-title-action">
                <?php esc_html_e( 'Add New', 'event-checkin' ); ?>
            </a>
            <hr class="wp-header-end">

            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Title', 'event-checkin' ); ?></th>
                        <th><?php esc_html_e( 'Date', 'event-checkin' ); ?></th>
                        <th><?php esc_html_e( 'Location', 'event-checkin' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'event-checkin' ); ?></th>
                        <th><?php esc_html_e( 'Registrations', 'event-checkin' ); ?></th>
                        <th><?php esc_html_e( 'Checked In', 'event-checkin' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'event-checkin' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $events ) ) : ?>
                        <tr>
                            <td colspan="7"><?php esc_html_e( 'No events found.', 'event-checkin' ); ?></td>
                        </tr>
                    <?php else : ?>
                        <?php foreach ( $events as $event ) : ?>
                            <tr>
                                <td>
                                    <strong>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ec-event-dashboard&event_id=' . $event->id ) ); ?>">
                                            <?php echo esc_html( $event->title ); ?>
                                        </a>
                                    </strong>
                                    <div class="row-actions">
                                        <span class="dashboard">
                                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ec-event-dashboard&event_id=' . $event->id ) ); ?>">
                                                <?php esc_html_e( 'Dashboard', 'event-checkin' ); ?>
                                            </a> |
                                        </span>
                                        <span class="edit">
                                            <a href="<?php echo esc_url( admin_url( 'admin.php?page=ec-add-event&event_id=' . $event->id ) ); ?>">
                                                <?php esc_html_e( 'Edit', 'event-checkin' ); ?>
                                            </a>
                                        </span>
                                    </div>
                                </td>
                                <td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $event->event_date ) ) ); ?></td>
                                <td><?php echo esc_html( $event->location ); ?></td>
                                <td>
                                    <span class="ec-status ec-status--<?php echo esc_attr( $event->status ); ?>">
                                        <?php echo esc_html( ucfirst( $event->status ) ); ?>
                                    </span>
                                </td>
                                <td><?php echo intval( $event->registration_count ); ?></td>
                                <td><?php echo intval( $event->checkin_count ); ?></td>
                                <td>
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ec-event-dashboard&event_id=' . $event->id ) ); ?>" class="button button-small button-primary">
                                        <?php esc_html_e( 'Dashboard', 'event-checkin' ); ?>
                                    </a>
                                    <?php if ( current_user_can( 'ec_export_data' ) ) : ?>
                                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ec_export&event_id=' . $event->id ), 'ec_export_' . $event->id ) ); ?>" class="button button-small">
                                            <?php esc_html_e( 'Export', 'event-checkin' ); ?>
                                        </a>
                                    <?php endif; ?>
                                    <code title="<?php esc_attr_e( 'Shortcode for registration form', 'event-checkin' ); ?>">[event_registration id="<?php echo intval( $event->id ); ?>"]</code>
                                    <code title="<?php esc_attr_e( 'Shortcode for kiosk mode', 'event-checkin' ); ?>">[event_kiosk id="<?php echo intval( $event->id ); ?>"]</code>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render the add/edit event form.
     */
    public static function render_event_form() {
        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        $event    = null;

        if ( $event_id ) {
            global $wpdb;
            $event = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}ec_events WHERE id = %d",
                    $event_id
                )
            );
        }

        $title            = $event ? $event->title : '';
        $description      = $event ? $event->description : '';
        $event_date       = $event ? $event->event_date : '';
        $location         = $event ? $event->location : '';
        $max_capacity     = $event ? $event->max_capacity : '';
        $reg_deadline     = $event ? $event->registration_deadline : '';
        $require_sig      = $event ? (int) $event->require_signature : 0;
        $custom_fields    = $event && $event->custom_fields ? json_decode( $event->custom_fields, true ) : array();
        $status           = $event ? $event->status : 'draft';
        ?>
        <div class="wrap">
            <h1><?php echo $event_id ? esc_html__( 'Edit Event', 'event-checkin' ) : esc_html__( 'Add New Event', 'event-checkin' ); ?></h1>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ec-event-form">
                <input type="hidden" name="action" value="ec_save_event">
                <input type="hidden" name="event_id" value="<?php echo intval( $event_id ); ?>">
                <?php wp_nonce_field( 'ec_save_event', 'ec_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="ec_title"><?php esc_html_e( 'Title', 'event-checkin' ); ?> *</label></th>
                        <td><input type="text" id="ec_title" name="ec_title" value="<?php echo esc_attr( $title ); ?>" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="ec_description"><?php esc_html_e( 'Description', 'event-checkin' ); ?></label></th>
                        <td><textarea id="ec_description" name="ec_description" rows="5" class="large-text"><?php echo esc_textarea( $description ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th><label for="ec_event_date"><?php esc_html_e( 'Event Date & Time', 'event-checkin' ); ?> *</label></th>
                        <td><input type="datetime-local" id="ec_event_date" name="ec_event_date" value="<?php echo esc_attr( $event_date ? date( 'Y-m-d\TH:i', strtotime( $event_date ) ) : '' ); ?>" required></td>
                    </tr>
                    <tr>
                        <th><label for="ec_location"><?php esc_html_e( 'Location', 'event-checkin' ); ?></label></th>
                        <td><input type="text" id="ec_location" name="ec_location" value="<?php echo esc_attr( $location ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="ec_max_capacity"><?php esc_html_e( 'Max Capacity', 'event-checkin' ); ?></label></th>
                        <td>
                            <input type="number" id="ec_max_capacity" name="ec_max_capacity" value="<?php echo esc_attr( $max_capacity ); ?>" min="0" class="small-text">
                            <p class="description"><?php esc_html_e( 'Leave empty for unlimited.', 'event-checkin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ec_reg_deadline"><?php esc_html_e( 'Registration Deadline', 'event-checkin' ); ?></label></th>
                        <td><input type="datetime-local" id="ec_reg_deadline" name="ec_reg_deadline" value="<?php echo esc_attr( $reg_deadline ? date( 'Y-m-d\TH:i', strtotime( $reg_deadline ) ) : '' ); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="ec_require_signature"><?php esc_html_e( 'Require Signature', 'event-checkin' ); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="ec_require_signature" name="ec_require_signature" value="1" <?php checked( $require_sig, 1 ); ?>>
                                <?php esc_html_e( 'Require digital signature during check-in', 'event-checkin' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ec_status"><?php esc_html_e( 'Status', 'event-checkin' ); ?></label></th>
                        <td>
                            <select id="ec_status" name="ec_status">
                                <option value="draft" <?php selected( $status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'event-checkin' ); ?></option>
                                <option value="published" <?php selected( $status, 'published' ); ?>><?php esc_html_e( 'Published', 'event-checkin' ); ?></option>
                                <option value="closed" <?php selected( $status, 'closed' ); ?>><?php esc_html_e( 'Closed', 'event-checkin' ); ?></option>
                                <option value="archived" <?php selected( $status, 'archived' ); ?>><?php esc_html_e( 'Archived', 'event-checkin' ); ?></option>
                            </select>
                        </td>
                    </tr>
                </table>

                <h2><?php esc_html_e( 'Custom Registration Fields', 'event-checkin' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Add additional fields to the registration form. Name, Email, and Phone are always included.', 'event-checkin' ); ?></p>

                <div id="ec-custom-fields">
                    <?php if ( ! empty( $custom_fields ) ) : ?>
                        <?php foreach ( $custom_fields as $i => $field ) : ?>
                            <div class="ec-custom-field" data-index="<?php echo intval( $i ); ?>">
                                <input type="text" name="ec_cf_label[]" value="<?php echo esc_attr( $field['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Field Label', 'event-checkin' ); ?>">
                                <select name="ec_cf_type[]">
                                    <option value="text" <?php selected( $field['type'] ?? 'text', 'text' ); ?>>Text</option>
                                    <option value="textarea" <?php selected( $field['type'] ?? '', 'textarea' ); ?>>Textarea</option>
                                    <option value="select" <?php selected( $field['type'] ?? '', 'select' ); ?>>Select</option>
                                    <option value="checkbox" <?php selected( $field['type'] ?? '', 'checkbox' ); ?>>Checkbox</option>
                                </select>
                                <input type="text" name="ec_cf_options[]" value="<?php echo esc_attr( $field['options'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Options (comma-separated, for select)', 'event-checkin' ); ?>">
                                <label>
                                    <input type="checkbox" name="ec_cf_required[<?php echo intval( $i ); ?>]" value="1" <?php checked( ! empty( $field['required'] ) ); ?>>
                                    <?php esc_html_e( 'Required', 'event-checkin' ); ?>
                                </label>
                                <button type="button" class="button ec-remove-field">&times;</button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <button type="button" class="button" id="ec-add-field"><?php esc_html_e( '+ Add Field', 'event-checkin' ); ?></button>

                <?php submit_button( $event_id ? __( 'Update Event', 'event-checkin' ) : __( 'Create Event', 'event-checkin' ) ); ?>

                <?php if ( $event_id && current_user_can( 'ec_manage_events' ) ) : ?>
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ec_delete_event&event_id=' . $event_id ), 'ec_delete_event_' . $event_id ) ); ?>"
                       class="button button-link-delete"
                       onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this event?', 'event-checkin' ); ?>');">
                        <?php esc_html_e( 'Delete Event', 'event-checkin' ); ?>
                    </a>
                <?php endif; ?>
            </form>
        </div>
        <?php
    }

    /**
     * Handle saving an event (create or update).
     */
    public static function handle_save_event() {
        if ( ! current_user_can( 'ec_manage_events' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'event-checkin' ), 403 );
        }

        check_admin_referer( 'ec_save_event', 'ec_nonce' );

        global $wpdb;
        $table    = $wpdb->prefix . 'ec_events';
        $event_id = isset( $_POST['event_id'] ) ? absint( $_POST['event_id'] ) : 0;

        // Build custom fields array.
        $custom_fields = array();
        if ( ! empty( $_POST['ec_cf_label'] ) && is_array( $_POST['ec_cf_label'] ) ) {
            foreach ( $_POST['ec_cf_label'] as $i => $label ) {
                $label = sanitize_text_field( $label );
                if ( empty( $label ) ) {
                    continue;
                }
                $custom_fields[] = array(
                    'label'    => $label,
                    'type'     => sanitize_key( $_POST['ec_cf_type'][ $i ] ?? 'text' ),
                    'options'  => sanitize_text_field( $_POST['ec_cf_options'][ $i ] ?? '' ),
                    'required' => ! empty( $_POST['ec_cf_required'][ $i ] ),
                );
            }
        }

        $data = array(
            'title'                 => sanitize_text_field( $_POST['ec_title'] ?? '' ),
            'description'           => sanitize_textarea_field( $_POST['ec_description'] ?? '' ),
            'event_date'            => sanitize_text_field( $_POST['ec_event_date'] ?? '' ),
            'location'              => sanitize_text_field( $_POST['ec_location'] ?? '' ),
            'max_capacity'          => ! empty( $_POST['ec_max_capacity'] ) ? absint( $_POST['ec_max_capacity'] ) : null,
            'registration_deadline' => ! empty( $_POST['ec_reg_deadline'] ) ? sanitize_text_field( $_POST['ec_reg_deadline'] ) : null,
            'require_signature'     => ! empty( $_POST['ec_require_signature'] ) ? 1 : 0,
            'custom_fields'         => wp_json_encode( $custom_fields ),
            'status'                => sanitize_key( $_POST['ec_status'] ?? 'draft' ),
        );

        $formats = array( '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' );

        if ( $event_id ) {
            $wpdb->update( $table, $data, array( 'id' => $event_id ), $formats, array( '%d' ) );
        } else {
            $data['created_by'] = get_current_user_id();
            $formats[]          = '%d';
            $wpdb->insert( $table, $data, $formats );
            $event_id = $wpdb->insert_id;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=ec-add-event&event_id=' . $event_id . '&updated=1' ) );
        exit;
    }

    /**
     * Handle deleting an event.
     */
    public static function handle_delete_event() {
        if ( ! current_user_can( 'ec_manage_events' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'event-checkin' ), 403 );
        }

        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;
        check_admin_referer( 'ec_delete_event_' . $event_id );

        if ( $event_id ) {
            global $wpdb;
            // Delete registrations and check-in logs first.
            $wpdb->delete( $wpdb->prefix . 'ec_checkin_log', array( 'event_id' => $event_id ), array( '%d' ) );
            $wpdb->delete( $wpdb->prefix . 'ec_registrations', array( 'event_id' => $event_id ), array( '%d' ) );
            $wpdb->delete( $wpdb->prefix . 'ec_events', array( 'id' => $event_id ), array( '%d' ) );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=event-checkin&deleted=1' ) );
        exit;
    }

    /**
     * Render registrations list page.
     */
    public static function render_registrations_page() {
        global $wpdb;
        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;

        if ( ! $event_id ) {
            echo '<div class="wrap"><h1>' . esc_html__( 'Registrations', 'event-checkin' ) . '</h1>';
            echo '<p>' . esc_html__( 'Please select an event from the Events page.', 'event-checkin' ) . '</p></div>';
            return;
        }

        $event = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ec_events WHERE id = %d", $event_id )
        );

        if ( ! $event ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Event not found.', 'event-checkin' ) . '</p></div>';
            return;
        }

        $registrations = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ec_registrations WHERE event_id = %d ORDER BY created_at DESC",
                $event_id
            )
        );
        ?>
        <div class="wrap">
            <h1><?php printf( esc_html__( 'Registrations: %s', 'event-checkin' ), esc_html( $event->title ) ); ?></h1>

            <p>
                <?php printf(
                    esc_html__( 'Total: %1$d | Checked in: %2$d | Pending: %3$d', 'event-checkin' ),
                    count( $registrations ),
                    count( array_filter( $registrations, function( $r ) { return $r->status === 'checked_in'; } ) ),
                    count( array_filter( $registrations, function( $r ) { return $r->status === 'registered'; } ) )
                ); ?>
            </p>

            <?php if ( current_user_can( 'ec_export_data' ) ) : ?>
                <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ec_export&event_id=' . $event_id ), 'ec_export_' . $event_id ) ); ?>" class="button">
                    <?php esc_html_e( 'Export to Excel', 'event-checkin' ); ?>
                </a>
            <?php endif; ?>

            <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Name', 'event-checkin' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'event-checkin' ); ?></th>
                        <th><?php esc_html_e( 'Phone', 'event-checkin' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'event-checkin' ); ?></th>
                        <th><?php esc_html_e( 'Checked In At', 'event-checkin' ); ?></th>
                        <th><?php esc_html_e( 'Signature', 'event-checkin' ); ?></th>
                        <th><?php esc_html_e( 'Registered', 'event-checkin' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $registrations ) ) : ?>
                        <tr><td colspan="7"><?php esc_html_e( 'No registrations yet.', 'event-checkin' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $registrations as $reg ) : ?>
                            <tr>
                                <td><?php echo esc_html( $reg->first_name . ' ' . $reg->last_name ); ?></td>
                                <td><?php echo esc_html( $reg->email ); ?></td>
                                <td><?php echo esc_html( $reg->phone ); ?></td>
                                <td>
                                    <span class="ec-status ec-status--<?php echo esc_attr( $reg->status ); ?>">
                                        <?php echo esc_html( ucfirst( str_replace( '_', ' ', $reg->status ) ) ); ?>
                                    </span>
                                </td>
                                <td><?php echo $reg->checked_in_at ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $reg->checked_in_at ) ) ) : '-'; ?></td>
                                <td>
                                    <?php if ( $reg->signature_data ) : ?>
                                        <img src="<?php echo esc_attr( $reg->signature_data ); ?>" alt="Signature" style="max-width: 120px; max-height: 40px; border: 1px solid #ddd;">
                                    <?php else : ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $reg->created_at ) ) ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /**
     * Render the email template editor page.
     */
    public static function render_email_template_page() {
        $template = Email::get_template();
        $subject  = Email::get_subject_template();
        $saved    = isset( $_GET['updated'] ) && $_GET['updated'] === '1';
        $reset    = isset( $_GET['reset'] ) && $_GET['reset'] === '1';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Email Template', 'event-checkin' ); ?></h1>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Email template saved.', 'event-checkin' ); ?></p></div>
            <?php endif; ?>
            <?php if ( $reset ) : ?>
                <div class="notice notice-info is-dismissible"><p><?php esc_html_e( 'Email template reset to default.', 'event-checkin' ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ec_save_email_template">
                <?php wp_nonce_field( 'ec_save_email_template', 'ec_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th><label for="ec_email_subject"><?php esc_html_e( 'Email Subject', 'event-checkin' ); ?></label></th>
                        <td>
                            <input type="text" id="ec_email_subject" name="ec_email_subject" value="<?php echo esc_attr( $subject ); ?>" class="large-text">
                            <p class="description"><?php esc_html_e( 'Available variables: {first_name}, {last_name}, {event_title}, {event_date}, {site_name}', 'event-checkin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="ec_email_template"><?php esc_html_e( 'HTML Email Body', 'event-checkin' ); ?></label></th>
                        <td>
                            <p class="description" style="margin-bottom: 8px;">
                                <?php esc_html_e( 'Available variables: {first_name}, {last_name}, {event_title}, {event_date}, {event_location}, {qr_code_url}, {site_name}', 'event-checkin' ); ?>
                            </p>
                            <textarea id="ec_email_template" name="ec_email_template" rows="30" class="large-text code" style="font-family: monospace; font-size: 13px;"><?php echo esc_textarea( $template ); ?></textarea>
                        </td>
                    </tr>
                </table>

                <div style="display: flex; gap: 10px; align-items: center;">
                    <?php submit_button( __( 'Save Template', 'event-checkin' ), 'primary', 'submit', false ); ?>
                    <button type="submit" name="ec_reset_template" value="1" class="button button-secondary" onclick="return confirm('<?php esc_attr_e( 'Reset to default template?', 'event-checkin' ); ?>');">
                        <?php esc_html_e( 'Reset to Default', 'event-checkin' ); ?>
                    </button>
                </div>
            </form>

            <h2 style="margin-top: 30px;"><?php esc_html_e( 'Preview', 'event-checkin' ); ?></h2>
            <div style="border: 1px solid #dcdcde; border-radius: 4px; overflow: hidden; max-width: 640px;">
                <iframe id="ec-email-preview" style="width: 100%; height: 600px; border: none;"></iframe>
            </div>
            <script>
            (function() {
                function updatePreview() {
                    var html = document.getElementById('ec_email_template').value;
                    // Replace variables with sample data.
                    html = html.replace(/\{first_name\}/g, 'John');
                    html = html.replace(/\{last_name\}/g, 'Doe');
                    html = html.replace(/\{event_title\}/g, 'Sample Conference 2025');
                    html = html.replace(/\{event_date\}/g, '15 June 2025 10:00');
                    html = html.replace(/\{event_location\}/g, 'Athens Convention Center');
                    html = html.replace(/\{qr_code_url\}/g, 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=sample');
                    html = html.replace(/\{site_name\}/g, '<?php echo esc_js( get_bloginfo( "name" ) ); ?>');
                    var iframe = document.getElementById('ec-email-preview');
                    var doc = iframe.contentDocument || iframe.contentWindow.document;
                    doc.open();
                    doc.write(html);
                    doc.close();
                }
                document.getElementById('ec_email_template').addEventListener('input', updatePreview);
                setTimeout(updatePreview, 100);
            })();
            </script>
        </div>
        <?php
    }

    /**
     * Handle saving the email template.
     */
    public static function handle_save_email_template() {
        if ( ! current_user_can( 'ec_manage_settings' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'event-checkin' ), 403 );
        }

        check_admin_referer( 'ec_save_email_template', 'ec_nonce' );

        if ( ! empty( $_POST['ec_reset_template'] ) ) {
            delete_option( Email::OPTION_TEMPLATE );
            delete_option( Email::OPTION_SUBJECT );
            wp_safe_redirect( admin_url( 'admin.php?page=ec-email-template&reset=1' ) );
            exit;
        }

        $subject  = sanitize_text_field( $_POST['ec_email_subject'] ?? '' );
        // Allow HTML in template but sanitize with wp_kses_post for safety.
        $template = wp_kses_post( $_POST['ec_email_template'] ?? '' );

        // However, email templates need full HTML including style attributes,
        // so we use a more permissive sanitization.
        $allowed_html = wp_kses_allowed_html( 'post' );
        $allowed_html['style'] = array();
        $allowed_html['meta']  = array( 'charset' => true, 'name' => true, 'content' => true, 'http-equiv' => true );
        $allowed_html['link']  = array( 'href' => true, 'rel' => true, 'type' => true );
        // Allow style attributes on all elements.
        foreach ( $allowed_html as $tag => $attrs ) {
            if ( is_array( $attrs ) ) {
                $allowed_html[ $tag ]['style'] = true;
                $allowed_html[ $tag ]['align'] = true;
                $allowed_html[ $tag ]['width'] = true;
                $allowed_html[ $tag ]['height'] = true;
                $allowed_html[ $tag ]['bgcolor'] = true;
                $allowed_html[ $tag ]['cellpadding'] = true;
                $allowed_html[ $tag ]['cellspacing'] = true;
                $allowed_html[ $tag ]['border'] = true;
                $allowed_html[ $tag ]['role'] = true;
            }
        }

        $template = wp_kses( $_POST['ec_email_template'] ?? '', $allowed_html );

        if ( $subject ) {
            update_option( Email::OPTION_SUBJECT, $subject );
        }
        if ( $template ) {
            update_option( Email::OPTION_TEMPLATE, $template );
        }

        wp_safe_redirect( admin_url( 'admin.php?page=ec-email-template&updated=1' ) );
        exit;
    }

    /**
     * Render the settings page.
     */
    public static function render_settings_page() {
        $settings = Settings::get_all();
        $saved    = isset( $_GET['updated'] ) && $_GET['updated'] === '1';
        $has_object_cache = wp_using_ext_object_cache();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Settings', 'event-checkin' ); ?></h1>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'event-checkin' ); ?></p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="ec_save_settings">
                <?php wp_nonce_field( 'ec_save_settings', 'ec_nonce' ); ?>

                <!-- reCAPTCHA Section -->
                <h2><?php esc_html_e( 'reCAPTCHA v3 (Bot Protection)', 'event-checkin' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Optional. Adds invisible reCAPTCHA v3 to registration forms. Get keys at https://www.google.com/recaptcha/admin', 'event-checkin' ); ?></p>
                <table class="form-table">
                    <tr>
                        <th><label for="recaptcha_enabled"><?php esc_html_e( 'Enable reCAPTCHA', 'event-checkin' ); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="recaptcha_enabled" name="recaptcha_enabled" value="1" <?php checked( $settings['recaptcha_enabled'] ); ?>>
                                <?php esc_html_e( 'Enable reCAPTCHA v3 on registration forms', 'event-checkin' ); ?>
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="recaptcha_site_key"><?php esc_html_e( 'Site Key', 'event-checkin' ); ?></label></th>
                        <td><input type="text" id="recaptcha_site_key" name="recaptcha_site_key" value="<?php echo esc_attr( $settings['recaptcha_site_key'] ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="recaptcha_secret_key"><?php esc_html_e( 'Secret Key', 'event-checkin' ); ?></label></th>
                        <td><input type="password" id="recaptcha_secret_key" name="recaptcha_secret_key" value="<?php echo esc_attr( $settings['recaptcha_secret_key'] ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="recaptcha_threshold"><?php esc_html_e( 'Score Threshold', 'event-checkin' ); ?></label></th>
                        <td>
                            <input type="number" id="recaptcha_threshold" name="recaptcha_threshold" value="<?php echo esc_attr( $settings['recaptcha_threshold'] ); ?>" min="0" max="1" step="0.1" class="small-text">
                            <p class="description"><?php esc_html_e( '0.0 = allow all, 1.0 = strictest. Default: 0.5', 'event-checkin' ); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- Performance Section -->
                <h2><?php esc_html_e( 'Performance', 'event-checkin' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Object Cache', 'event-checkin' ); ?></th>
                        <td>
                            <?php if ( $has_object_cache ) : ?>
                                <span style="color: #065f46; font-weight: 700;">&#10003; <?php esc_html_e( 'Active (Redis/Memcached detected)', 'event-checkin' ); ?></span>
                            <?php else : ?>
                                <span style="color: #9ca3af;"><?php esc_html_e( 'Not detected. Using transient caching as fallback. For best performance with 1000+ users, install Redis or Memcached.', 'event-checkin' ); ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="object_cache_ttl"><?php esc_html_e( 'Cache TTL (seconds)', 'event-checkin' ); ?></label></th>
                        <td>
                            <input type="number" id="object_cache_ttl" name="object_cache_ttl" value="<?php echo esc_attr( $settings['object_cache_ttl'] ); ?>" min="30" max="3600" class="small-text">
                            <p class="description"><?php esc_html_e( 'How long to cache event data. Lower = more fresh, higher = less DB load. Default: 300', 'event-checkin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="export_chunk_size"><?php esc_html_e( 'Export Chunk Size', 'event-checkin' ); ?></label></th>
                        <td>
                            <input type="number" id="export_chunk_size" name="export_chunk_size" value="<?php echo esc_attr( $settings['export_chunk_size'] ); ?>" min="100" max="5000" class="small-text">
                            <p class="description"><?php esc_html_e( 'Rows per output buffer flush during Excel export. Default: 500', 'event-checkin' ); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- Kiosk Section -->
                <h2><?php esc_html_e( 'Kiosk', 'event-checkin' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="kiosk_idle_timeout"><?php esc_html_e( 'Idle Timeout (seconds)', 'event-checkin' ); ?></label></th>
                        <td>
                            <input type="number" id="kiosk_idle_timeout" name="kiosk_idle_timeout" value="<?php echo esc_attr( $settings['kiosk_idle_timeout'] ); ?>" min="5" max="60" class="small-text">
                            <p class="description"><?php esc_html_e( 'Seconds before auto-resetting to scanner after check-in. Default: 15', 'event-checkin' ); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- SMS Verification Section -->
                <h2><?php esc_html_e( 'SMS Verification (Phone Fields)', 'event-checkin' ); ?></h2>
                <p class="description"><?php esc_html_e( 'Configure an SMS provider for phone number verification in forms.', 'event-checkin' ); ?></p>
                <table class="form-table">
                    <tr>
                        <th><label for="sms_provider"><?php esc_html_e( 'SMS Provider', 'event-checkin' ); ?></label></th>
                        <td>
                            <select id="sms_provider" name="sms_provider" class="ec-builder-select">
                                <option value="" <?php selected( $settings['sms_provider'], '' ); ?>><?php esc_html_e( 'None (disabled)', 'event-checkin' ); ?></option>
                                <option value="twilio" <?php selected( $settings['sms_provider'], 'twilio' ); ?>>Twilio</option>
                                <option value="webhook" <?php selected( $settings['sms_provider'], 'webhook' ); ?>><?php esc_html_e( 'Custom Webhook', 'event-checkin' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="twilio_account_sid"><?php esc_html_e( 'Twilio Account SID', 'event-checkin' ); ?></label></th>
                        <td><input type="text" id="twilio_account_sid" name="twilio_account_sid" value="<?php echo esc_attr( $settings['twilio_account_sid'] ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="twilio_auth_token"><?php esc_html_e( 'Twilio Auth Token', 'event-checkin' ); ?></label></th>
                        <td><input type="password" id="twilio_auth_token" name="twilio_auth_token" value="<?php echo esc_attr( $settings['twilio_auth_token'] ); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="twilio_from_number"><?php esc_html_e( 'Twilio From Number', 'event-checkin' ); ?></label></th>
                        <td><input type="text" id="twilio_from_number" name="twilio_from_number" value="<?php echo esc_attr( $settings['twilio_from_number'] ); ?>" class="regular-text" placeholder="+1234567890"></td>
                    </tr>
                    <tr>
                        <th><label for="sms_webhook_url"><?php esc_html_e( 'Webhook URL', 'event-checkin' ); ?></label></th>
                        <td>
                            <input type="url" id="sms_webhook_url" name="sms_webhook_url" value="<?php echo esc_attr( $settings['sms_webhook_url'] ); ?>" class="regular-text">
                            <p class="description"><?php esc_html_e( 'For custom webhook: receives POST with JSON {phone, message, code}', 'event-checkin' ); ?></p>
                        </td>
                    </tr>
                </table>

                <!-- Email Section -->
                <h2><?php esc_html_e( 'Email Sending', 'event-checkin' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="email_from_name"><?php esc_html_e( 'From Name', 'event-checkin' ); ?></label></th>
                        <td>
                            <input type="text" id="email_from_name" name="email_from_name" value="<?php echo esc_attr( $settings['email_from_name'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>">
                            <p class="description"><?php esc_html_e( 'Leave empty to use site name. For reliable delivery, use an SMTP plugin like WP Mail SMTP.', 'event-checkin' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="email_from_address"><?php esc_html_e( 'From Address', 'event-checkin' ); ?></label></th>
                        <td>
                            <input type="email" id="email_from_address" name="email_from_address" value="<?php echo esc_attr( $settings['email_from_address'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
                        </td>
                    </tr>
                </table>

                <!-- Data Retention Section -->
                <h2><?php esc_html_e( 'Data Retention', 'event-checkin' ); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="delete_data_on_uninstall"><?php esc_html_e( 'Delete Data on Uninstall', 'event-checkin' ); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="delete_data_on_uninstall" name="delete_data_on_uninstall" value="1" <?php checked( $settings['delete_data_on_uninstall'] ); ?>>
                                <?php esc_html_e( 'Delete all plugin data (events, registrations, QR codes, translations) when the plugin is uninstalled', 'event-checkin' ); ?>
                            </label>
                            <p class="description" style="color: #b91c1c;">
                                <?php esc_html_e( 'Leave unchecked to preserve your data during updates and reinstalls. Only enable this if you want a complete cleanup when deleting the plugin.', 'event-checkin' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    // =========================================================================
    // Event Dashboard
    // =========================================================================

    /**
     * Render the event dashboard page with stats, registration table, and actions.
     */
    public static function render_event_dashboard() {
        global $wpdb;
        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;

        if ( ! $event_id ) {
            echo '<div class="wrap"><h1>' . esc_html__( 'Event Dashboard', 'event-checkin' ) . '</h1>';
            echo '<p>' . esc_html__( 'Please select an event from the Events page.', 'event-checkin' ) . '</p></div>';
            return;
        }

        $event = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ec_events WHERE id = %d", $event_id )
        );

        if ( ! $event ) {
            echo '<div class="wrap"><p>' . esc_html__( 'Event not found.', 'event-checkin' ) . '</p></div>';
            return;
        }

        // Gather stats.
        $stats = self::get_event_stats( $event_id );
        $custom_fields = $event->custom_fields ? json_decode( $event->custom_fields, true ) : array();

        // Search and filter params.
        $search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
        $status_filter = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
        $per_page = 25;
        $current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $offset = ( $current_page - 1 ) * $per_page;

        // Build query.
        $where = $wpdb->prepare( "WHERE r.event_id = %d", $event_id );
        if ( $search ) {
            $like = '%' . $wpdb->esc_like( $search ) . '%';
            $where .= $wpdb->prepare(
                " AND (r.first_name LIKE %s OR r.last_name LIKE %s OR r.email LIKE %s OR r.phone LIKE %s)",
                $like, $like, $like, $like
            );
        }
        if ( $status_filter ) {
            $where .= $wpdb->prepare( " AND r.status = %s", $status_filter );
        }

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ec_registrations r {$where}"
        );
        $total_pages = ceil( $total / $per_page );

        $registrations = $wpdb->get_results(
            "SELECT r.* FROM {$wpdb->prefix}ec_registrations r {$where} ORDER BY r.created_at DESC LIMIT {$per_page} OFFSET {$offset}"
        );

        $base_url = admin_url( 'admin.php?page=ec-event-dashboard&event_id=' . $event_id );
        ?>
        <div class="wrap ec-dashboard-wrap">
            <!-- Back link and title -->
            <p class="ec-dash-back">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=event-checkin' ) ); ?>">&larr; <?php esc_html_e( 'All Events', 'event-checkin' ); ?></a>
            </p>
            <h1 class="wp-heading-inline"><?php echo esc_html( $event->title ); ?></h1>
            <span class="ec-status ec-status--<?php echo esc_attr( $event->status ); ?>" style="margin-left: 12px; vertical-align: middle;">
                <?php echo esc_html( ucfirst( $event->status ) ); ?>
            </span>

            <!-- Event meta bar -->
            <div class="ec-dash-meta">
                <?php if ( $event->event_date ) : ?>
                    <span class="ec-dash-meta-item">
                        <span class="dashicons dashicons-calendar-alt"></span>
                        <?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $event->event_date ) ) ); ?>
                    </span>
                <?php endif; ?>
                <?php if ( $event->location ) : ?>
                    <span class="ec-dash-meta-item">
                        <span class="dashicons dashicons-location"></span>
                        <?php echo esc_html( $event->location ); ?>
                    </span>
                <?php endif; ?>
                <?php if ( $event->max_capacity ) : ?>
                    <span class="ec-dash-meta-item">
                        <span class="dashicons dashicons-groups"></span>
                        <?php printf( esc_html__( 'Capacity: %d', 'event-checkin' ), $event->max_capacity ); ?>
                    </span>
                <?php endif; ?>
            </div>

            <!-- Stats Cards -->
            <div class="ec-dash-stats">
                <div class="ec-dash-stat-card ec-dash-stat--total">
                    <div class="ec-dash-stat-number"><?php echo intval( $stats['total'] ); ?></div>
                    <div class="ec-dash-stat-label"><?php esc_html_e( 'Total Registrations', 'event-checkin' ); ?></div>
                </div>
                <div class="ec-dash-stat-card ec-dash-stat--checkedin">
                    <div class="ec-dash-stat-number"><?php echo intval( $stats['checked_in'] ); ?></div>
                    <div class="ec-dash-stat-label"><?php esc_html_e( 'Checked In', 'event-checkin' ); ?></div>
                </div>
                <div class="ec-dash-stat-card ec-dash-stat--pending">
                    <div class="ec-dash-stat-number"><?php echo intval( $stats['pending'] ); ?></div>
                    <div class="ec-dash-stat-label"><?php esc_html_e( 'Pending', 'event-checkin' ); ?></div>
                </div>
                <div class="ec-dash-stat-card ec-dash-stat--cancelled">
                    <div class="ec-dash-stat-number"><?php echo intval( $stats['cancelled'] ); ?></div>
                    <div class="ec-dash-stat-label"><?php esc_html_e( 'Cancelled', 'event-checkin' ); ?></div>
                </div>
                <?php if ( $event->max_capacity ) : ?>
                <div class="ec-dash-stat-card ec-dash-stat--capacity">
                    <div class="ec-dash-stat-number"><?php echo intval( $stats['total'] - $stats['cancelled'] ); ?> / <?php echo intval( $event->max_capacity ); ?></div>
                    <div class="ec-dash-stat-label"><?php esc_html_e( 'Capacity Used', 'event-checkin' ); ?></div>
                    <div class="ec-dash-progress-bar">
                        <div class="ec-dash-progress-fill" style="width: <?php echo min( 100, round( ( $stats['total'] - $stats['cancelled'] ) / max( 1, $event->max_capacity ) * 100 ) ); ?>%;"></div>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Check-in Progress Bar -->
            <div class="ec-dash-checkin-progress">
                <div class="ec-dash-checkin-bar">
                    <div class="ec-dash-checkin-fill" style="width: <?php echo $stats['total'] > 0 ? round( $stats['checked_in'] / $stats['total'] * 100 ) : 0; ?>%;"></div>
                </div>
                <span class="ec-dash-checkin-text">
                    <?php printf(
                        esc_html__( '%1$d of %2$d checked in (%3$d%%)', 'event-checkin' ),
                        $stats['checked_in'],
                        $stats['total'],
                        $stats['total'] > 0 ? round( $stats['checked_in'] / $stats['total'] * 100 ) : 0
                    ); ?>
                </span>
            </div>

            <!-- Action Buttons Bar -->
            <div class="ec-dash-actions">
                <?php if ( current_user_can( 'ec_edit_registrations' ) ) : ?>
                    <button type="button" class="button button-primary" id="ec-btn-add-registration" data-event-id="<?php echo intval( $event_id ); ?>">
                        <span class="dashicons dashicons-plus-alt2" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e( 'Add Registration', 'event-checkin' ); ?>
                    </button>
                <?php endif; ?>
                <?php if ( current_user_can( 'ec_export_data' ) ) : ?>
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ec_export&event_id=' . $event_id ), 'ec_export_' . $event_id ) ); ?>" class="button">
                        <span class="dashicons dashicons-download" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e( 'Export Excel', 'event-checkin' ); ?>
                    </a>
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ec_export&event_id=' . $event_id . '&format=csv' ), 'ec_export_' . $event_id ) ); ?>" class="button">
                        <span class="dashicons dashicons-media-spreadsheet" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e( 'Export CSV', 'event-checkin' ); ?>
                    </a>
                <?php endif; ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=ec-add-event&event_id=' . $event_id ) ); ?>" class="button">
                    <span class="dashicons dashicons-edit" style="vertical-align: text-bottom;"></span>
                    <?php esc_html_e( 'Edit Event', 'event-checkin' ); ?>
                </a>
            </div>

            <!-- Search and Filter Bar -->
            <div class="ec-dash-filters">
                <form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="ec-dash-search-form">
                    <input type="hidden" name="page" value="ec-event-dashboard">
                    <input type="hidden" name="event_id" value="<?php echo intval( $event_id ); ?>">
                    <div class="ec-dash-filter-row">
                        <input type="search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Search by name, email, or phone...', 'event-checkin' ); ?>" class="ec-dash-search-input">
                        <select name="status" class="ec-dash-status-filter">
                            <option value=""><?php esc_html_e( 'All statuses', 'event-checkin' ); ?></option>
                            <option value="registered" <?php selected( $status_filter, 'registered' ); ?>><?php esc_html_e( 'Registered', 'event-checkin' ); ?></option>
                            <option value="checked_in" <?php selected( $status_filter, 'checked_in' ); ?>><?php esc_html_e( 'Checked In', 'event-checkin' ); ?></option>
                            <option value="cancelled" <?php selected( $status_filter, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'event-checkin' ); ?></option>
                        </select>
                        <button type="submit" class="button"><?php esc_html_e( 'Filter', 'event-checkin' ); ?></button>
                        <?php if ( $search || $status_filter ) : ?>
                            <a href="<?php echo esc_url( $base_url ); ?>" class="button"><?php esc_html_e( 'Clear', 'event-checkin' ); ?></a>
                        <?php endif; ?>
                    </div>
                </form>
                <span class="ec-dash-result-count">
                    <?php printf( esc_html__( 'Showing %1$d of %2$d', 'event-checkin' ), count( $registrations ), $total ); ?>
                </span>
            </div>

            <!-- Registrations Table -->
            <table class="wp-list-table widefat fixed striped ec-dash-table" id="ec-registrations-table">
                <thead>
                    <tr>
                        <th class="ec-col-name"><?php esc_html_e( 'Name', 'event-checkin' ); ?></th>
                        <th class="ec-col-email"><?php esc_html_e( 'Email', 'event-checkin' ); ?></th>
                        <th class="ec-col-phone"><?php esc_html_e( 'Phone', 'event-checkin' ); ?></th>
                        <th class="ec-col-status"><?php esc_html_e( 'Status', 'event-checkin' ); ?></th>
                        <th class="ec-col-checkin"><?php esc_html_e( 'Checked In', 'event-checkin' ); ?></th>
                        <th class="ec-col-registered"><?php esc_html_e( 'Registered', 'event-checkin' ); ?></th>
                        <th class="ec-col-actions"><?php esc_html_e( 'Actions', 'event-checkin' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ( empty( $registrations ) ) : ?>
                        <tr><td colspan="7" class="ec-dash-empty"><?php esc_html_e( 'No registrations found.', 'event-checkin' ); ?></td></tr>
                    <?php else : ?>
                        <?php foreach ( $registrations as $reg ) : ?>
                            <tr id="ec-reg-row-<?php echo intval( $reg->id ); ?>" data-reg-id="<?php echo intval( $reg->id ); ?>">
                                <td class="ec-col-name">
                                    <strong><?php echo esc_html( $reg->first_name . ' ' . $reg->last_name ); ?></strong>
                                    <?php if ( $reg->signature_data ) : ?>
                                        <span class="dashicons dashicons-edit" title="<?php esc_attr_e( 'Has signature', 'event-checkin' ); ?>" style="font-size: 14px; color: #0babe4;"></span>
                                    <?php endif; ?>
                                </td>
                                <td class="ec-col-email"><?php echo esc_html( $reg->email ); ?></td>
                                <td class="ec-col-phone"><?php echo esc_html( $reg->phone ); ?></td>
                                <td class="ec-col-status">
                                    <span class="ec-status ec-status--<?php echo esc_attr( $reg->status ); ?>">
                                        <?php echo esc_html( ucfirst( str_replace( '_', ' ', $reg->status ) ) ); ?>
                                    </span>
                                </td>
                                <td class="ec-col-checkin">
                                    <?php echo $reg->checked_in_at ? esc_html( wp_date( 'M j, H:i', strtotime( $reg->checked_in_at ) ) ) : '<span class="ec-dash-muted">-</span>'; ?>
                                </td>
                                <td class="ec-col-registered">
                                    <?php echo esc_html( wp_date( 'M j, H:i', strtotime( $reg->created_at ) ) ); ?>
                                </td>
                                <td class="ec-col-actions">
                                    <div class="ec-action-buttons">
                                        <?php if ( current_user_can( 'ec_edit_registrations' ) ) : ?>
                                            <button type="button" class="button button-small ec-btn-edit" data-id="<?php echo intval( $reg->id ); ?>" title="<?php esc_attr_e( 'Edit', 'event-checkin' ); ?>">
                                                <span class="dashicons dashicons-edit"></span>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ( current_user_can( 'ec_resend_emails' ) ) : ?>
                                            <button type="button" class="button button-small ec-btn-resend" data-id="<?php echo intval( $reg->id ); ?>" data-email="<?php echo esc_attr( $reg->email ); ?>" title="<?php esc_attr_e( 'Resend Email', 'event-checkin' ); ?>">
                                                <span class="dashicons dashicons-email"></span>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ( current_user_can( 'ec_download_qr' ) ) : ?>
                                            <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ec_download_qr&reg_id=' . $reg->id ), 'ec_download_qr_' . $reg->id ) ); ?>" class="button button-small" title="<?php esc_attr_e( 'Download QR', 'event-checkin' ); ?>">
                                                <span class="dashicons dashicons-smartphone"></span>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ( current_user_can( 'ec_manage_checkin' ) && $reg->status === 'registered' ) : ?>
                                            <button type="button" class="button button-small ec-btn-checkin" data-id="<?php echo intval( $reg->id ); ?>" title="<?php esc_attr_e( 'Manual Check-in', 'event-checkin' ); ?>">
                                                <span class="dashicons dashicons-yes-alt"></span>
                                            </button>
                                        <?php endif; ?>
                                        <?php if ( current_user_can( 'ec_edit_registrations' ) && $reg->status !== 'cancelled' ) : ?>
                                            <button type="button" class="button button-small ec-btn-cancel" data-id="<?php echo intval( $reg->id ); ?>" title="<?php esc_attr_e( 'Cancel', 'event-checkin' ); ?>">
                                                <span class="dashicons dashicons-dismiss"></span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <!-- Pagination -->
            <?php if ( $total_pages > 1 ) : ?>
                <div class="ec-dash-pagination tablenav bottom">
                    <div class="tablenav-pages">
                        <span class="displaying-num">
                            <?php printf( esc_html__( '%d items', 'event-checkin' ), $total ); ?>
                        </span>
                        <span class="pagination-links">
                            <?php if ( $current_page > 1 ) : ?>
                                <a class="first-page button" href="<?php echo esc_url( add_query_arg( 'paged', 1, $base_url ) ); ?>">&laquo;</a>
                                <a class="prev-page button" href="<?php echo esc_url( add_query_arg( 'paged', $current_page - 1, $base_url ) ); ?>">&lsaquo;</a>
                            <?php else : ?>
                                <span class="tablenav-pages-navspan button disabled">&laquo;</span>
                                <span class="tablenav-pages-navspan button disabled">&lsaquo;</span>
                            <?php endif; ?>
                            <span class="paging-input">
                                <?php printf( esc_html__( '%1$d of %2$d', 'event-checkin' ), $current_page, $total_pages ); ?>
                            </span>
                            <?php if ( $current_page < $total_pages ) : ?>
                                <a class="next-page button" href="<?php echo esc_url( add_query_arg( 'paged', $current_page + 1, $base_url ) ); ?>">&rsaquo;</a>
                                <a class="last-page button" href="<?php echo esc_url( add_query_arg( 'paged', $total_pages, $base_url ) ); ?>">&raquo;</a>
                            <?php else : ?>
                                <span class="tablenav-pages-navspan button disabled">&rsaquo;</span>
                                <span class="tablenav-pages-navspan button disabled">&raquo;</span>
                            <?php endif; ?>
                        </span>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Edit Registration Modal -->
        <div id="ec-modal-edit" class="ec-modal" style="display:none;">
            <div class="ec-modal-overlay"></div>
            <div class="ec-modal-content">
                <div class="ec-modal-header">
                    <h2 id="ec-modal-edit-title"><?php esc_html_e( 'Edit Registration', 'event-checkin' ); ?></h2>
                    <button type="button" class="ec-modal-close">&times;</button>
                </div>
                <div class="ec-modal-body">
                    <form id="ec-edit-form">
                        <input type="hidden" name="reg_id" id="ec-edit-reg-id" value="">
                        <input type="hidden" name="event_id" id="ec-edit-event-id" value="<?php echo intval( $event_id ); ?>">
                        <table class="form-table ec-modal-form-table">
                            <tr>
                                <th><label for="ec-edit-first-name"><?php esc_html_e( 'First Name', 'event-checkin' ); ?></label></th>
                                <td><input type="text" id="ec-edit-first-name" name="first_name" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th><label for="ec-edit-last-name"><?php esc_html_e( 'Last Name', 'event-checkin' ); ?></label></th>
                                <td><input type="text" id="ec-edit-last-name" name="last_name" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th><label for="ec-edit-email"><?php esc_html_e( 'Email', 'event-checkin' ); ?></label></th>
                                <td><input type="email" id="ec-edit-email" name="email" class="regular-text" required></td>
                            </tr>
                            <tr>
                                <th><label for="ec-edit-phone"><?php esc_html_e( 'Phone', 'event-checkin' ); ?></label></th>
                                <td><input type="tel" id="ec-edit-phone" name="phone" class="regular-text"></td>
                            </tr>
                            <tr>
                                <th><label for="ec-edit-status"><?php esc_html_e( 'Status', 'event-checkin' ); ?></label></th>
                                <td>
                                    <select id="ec-edit-status" name="status">
                                        <option value="registered"><?php esc_html_e( 'Registered', 'event-checkin' ); ?></option>
                                        <option value="checked_in"><?php esc_html_e( 'Checked In', 'event-checkin' ); ?></option>
                                        <option value="cancelled"><?php esc_html_e( 'Cancelled', 'event-checkin' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <?php foreach ( $custom_fields as $i => $field ) : ?>
                                <tr>
                                    <th><label for="ec-edit-cf-<?php echo intval( $i ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
                                    <td>
                                        <?php $cf_name = 'custom_' . sanitize_key( $field['label'] ); ?>
                                        <?php if ( $field['type'] === 'textarea' ) : ?>
                                            <textarea id="ec-edit-cf-<?php echo intval( $i ); ?>" name="<?php echo esc_attr( $cf_name ); ?>" rows="3" class="large-text"></textarea>
                                        <?php elseif ( $field['type'] === 'select' ) : ?>
                                            <select id="ec-edit-cf-<?php echo intval( $i ); ?>" name="<?php echo esc_attr( $cf_name ); ?>">
                                                <option value=""><?php esc_html_e( '-- Select --', 'event-checkin' ); ?></option>
                                                <?php foreach ( array_map( 'trim', explode( ',', $field['options'] ?? '' ) ) as $opt ) : ?>
                                                    <option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php elseif ( $field['type'] === 'checkbox' ) : ?>
                                            <label><input type="checkbox" id="ec-edit-cf-<?php echo intval( $i ); ?>" name="<?php echo esc_attr( $cf_name ); ?>" value="1"> <?php echo esc_html( $field['label'] ); ?></label>
                                        <?php else : ?>
                                            <input type="text" id="ec-edit-cf-<?php echo intval( $i ); ?>" name="<?php echo esc_attr( $cf_name ); ?>" class="regular-text">
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </form>
                </div>
                <div class="ec-modal-footer">
                    <button type="button" class="button ec-modal-close"><?php esc_html_e( 'Cancel', 'event-checkin' ); ?></button>
                    <button type="button" class="button button-primary" id="ec-btn-save-edit"><?php esc_html_e( 'Save Changes', 'event-checkin' ); ?></button>
                </div>
            </div>
        </div>

        <!-- Resend Email Modal -->
        <div id="ec-modal-resend" class="ec-modal" style="display:none;">
            <div class="ec-modal-overlay"></div>
            <div class="ec-modal-content ec-modal-content--small">
                <div class="ec-modal-header">
                    <h2><?php esc_html_e( 'Resend Confirmation Email', 'event-checkin' ); ?></h2>
                    <button type="button" class="ec-modal-close">&times;</button>
                </div>
                <div class="ec-modal-body">
                    <p><?php esc_html_e( 'Send the confirmation email with QR code to:', 'event-checkin' ); ?></p>
                    <div class="ec-resend-options">
                        <label class="ec-radio-option">
                            <input type="radio" name="ec_resend_target" value="original" checked>
                            <span><?php esc_html_e( 'Original email', 'event-checkin' ); ?>: <strong id="ec-resend-original-email"></strong></span>
                        </label>
                        <label class="ec-radio-option">
                            <input type="radio" name="ec_resend_target" value="custom">
                            <span><?php esc_html_e( 'Different email address', 'event-checkin' ); ?></span>
                        </label>
                        <input type="email" id="ec-resend-custom-email" placeholder="<?php esc_attr_e( 'Enter email address', 'event-checkin' ); ?>" class="regular-text" style="display:none; margin-top: 8px;">
                    </div>
                    <input type="hidden" id="ec-resend-reg-id" value="">
                </div>
                <div class="ec-modal-footer">
                    <button type="button" class="button ec-modal-close"><?php esc_html_e( 'Cancel', 'event-checkin' ); ?></button>
                    <button type="button" class="button button-primary" id="ec-btn-send-email">
                        <span class="dashicons dashicons-email" style="vertical-align: text-bottom;"></span>
                        <?php esc_html_e( 'Send Email', 'event-checkin' ); ?>
                    </button>
                </div>
            </div>
        </div>

        <!-- Toast notification container -->
        <div id="ec-toast-container"></div>
        <?php
    }

    /**
     * Get aggregated stats for an event.
     *
     * @param int $event_id Event ID.
     * @return array Stats array.
     */
    private static function get_event_stats( $event_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'ec_registrations';

        $total = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d", $event_id )
        );
        $checked_in = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND status = 'checked_in'", $event_id )
        );
        $pending = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND status = 'registered'", $event_id )
        );
        $cancelled = (int) $wpdb->get_var(
            $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE event_id = %d AND status = 'cancelled'", $event_id )
        );

        return array(
            'total'      => $total,
            'checked_in' => $checked_in,
            'pending'    => $pending,
            'cancelled'  => $cancelled,
        );
    }

    // =========================================================================
    // AJAX Handlers
    // =========================================================================

    /**
     * AJAX: Get a single registration's data for the edit modal.
     */
    public static function ajax_get_registration() {
        check_ajax_referer( 'ec_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ec_edit_registrations' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'event-checkin' ) ), 403 );
        }

        $reg_id = absint( $_POST['reg_id'] ?? 0 );
        if ( ! $reg_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid registration ID.', 'event-checkin' ) ) );
        }

        global $wpdb;
        $reg = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ec_registrations WHERE id = %d",
                $reg_id
            )
        );

        if ( ! $reg ) {
            wp_send_json_error( array( 'message' => __( 'Registration not found.', 'event-checkin' ) ) );
        }

        $custom_data = $reg->custom_data ? json_decode( $reg->custom_data, true ) : array();

        wp_send_json_success( array(
            'id'          => $reg->id,
            'first_name'  => $reg->first_name,
            'last_name'   => $reg->last_name,
            'email'       => $reg->email,
            'phone'       => $reg->phone,
            'status'      => $reg->status,
            'custom_data' => $custom_data,
        ) );
    }

    /**
     * AJAX: Update a registration's data.
     */
    public static function ajax_update_registration() {
        check_ajax_referer( 'ec_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ec_edit_registrations' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'event-checkin' ) ), 403 );
        }

        $reg_id = absint( $_POST['reg_id'] ?? 0 );
        if ( ! $reg_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid registration ID.', 'event-checkin' ) ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ec_registrations';

        $reg = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $reg_id ) );
        if ( ! $reg ) {
            wp_send_json_error( array( 'message' => __( 'Registration not found.', 'event-checkin' ) ) );
        }

        $first_name = sanitize_text_field( $_POST['first_name'] ?? '' );
        $last_name  = sanitize_text_field( $_POST['last_name'] ?? '' );
        $email      = sanitize_email( $_POST['email'] ?? '' );
        $phone      = sanitize_text_field( $_POST['phone'] ?? '' );
        $status     = sanitize_key( $_POST['status'] ?? 'registered' );

        if ( ! $first_name || ! $last_name || ! $email ) {
            wp_send_json_error( array( 'message' => __( 'First name, last name, and email are required.', 'event-checkin' ) ) );
        }

        if ( ! in_array( $status, array( 'registered', 'checked_in', 'cancelled' ), true ) ) {
            $status = 'registered';
        }

        // Build custom data from POST.
        $custom_data = array();
        foreach ( $_POST as $key => $value ) {
            if ( strpos( $key, 'custom_' ) === 0 ) {
                $label = str_replace( 'custom_', '', $key );
                $custom_data[ $label ] = sanitize_text_field( $value );
            }
        }

        $data = array(
            'first_name'  => $first_name,
            'last_name'   => $last_name,
            'email'       => $email,
            'phone'       => $phone,
            'status'      => $status,
            'custom_data' => wp_json_encode( $custom_data ),
        );

        // If status changed to checked_in and wasn't before, set checked_in_at.
        if ( $status === 'checked_in' && $reg->status !== 'checked_in' ) {
            $data['checked_in_at'] = current_time( 'mysql' );
        }

        $wpdb->update( $table, $data, array( 'id' => $reg_id ) );

        wp_send_json_success( array(
            'message'    => __( 'Registration updated.', 'event-checkin' ),
            'first_name' => $first_name,
            'last_name'  => $last_name,
            'email'      => $email,
            'phone'      => $phone,
            'status'     => $status,
        ) );
    }

    /**
     * AJAX: Resend confirmation email for a registration.
     */
    public static function ajax_resend_email() {
        check_ajax_referer( 'ec_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ec_resend_emails' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'event-checkin' ) ), 403 );
        }

        $reg_id       = absint( $_POST['reg_id'] ?? 0 );
        $target_email = sanitize_email( $_POST['target_email'] ?? '' );

        if ( ! $reg_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid registration ID.', 'event-checkin' ) ) );
        }

        global $wpdb;
        $reg = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT r.*, e.title as event_title, e.event_date, e.location
                 FROM {$wpdb->prefix}ec_registrations r
                 JOIN {$wpdb->prefix}ec_events e ON r.event_id = e.id
                 WHERE r.id = %d",
                $reg_id
            )
        );

        if ( ! $reg ) {
            wp_send_json_error( array( 'message' => __( 'Registration not found.', 'event-checkin' ) ) );
        }

        $to = $target_email ? $target_email : $reg->email;

        // Get or regenerate QR code URL.
        $qr_url = QRCode::get_url( $reg->qr_token );
        if ( ! $qr_url ) {
            $qr_url = QRCode::generate( $reg->qr_token, $reg->event_id );
        }

        // Get QR code file path for attachment.
        $upload_dir = wp_upload_dir();
        $qr_file    = $upload_dir['basedir'] . '/event-checkin/qrcodes/qr-' . substr( $reg->qr_token, 0, 16 ) . '.png';

        $vars = array(
            'first_name'     => $reg->first_name,
            'last_name'      => $reg->last_name,
            'event_title'    => $reg->event_title,
            'event_date'     => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $reg->event_date ) ),
            'event_location' => $reg->location,
            'qr_code_url'    => $qr_url ? $qr_url : '',
            'site_name'      => get_bloginfo( 'name' ),
        );

        $sent = Email::send( $to, $vars, $qr_file );

        if ( $sent ) {
            wp_send_json_success( array( 'message' => sprintf( __( 'Email sent to %s.', 'event-checkin' ), $to ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to send email. Check your mail configuration.', 'event-checkin' ) ) );
        }
    }

    /**
     * AJAX: Manual check-in from the dashboard.
     */
    public static function ajax_manual_checkin() {
        check_ajax_referer( 'ec_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ec_manage_checkin' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'event-checkin' ) ), 403 );
        }

        $reg_id = absint( $_POST['reg_id'] ?? 0 );
        if ( ! $reg_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid registration ID.', 'event-checkin' ) ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ec_registrations';
        $reg   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $reg_id ) );

        if ( ! $reg ) {
            wp_send_json_error( array( 'message' => __( 'Registration not found.', 'event-checkin' ) ) );
        }

        if ( $reg->status === 'checked_in' ) {
            wp_send_json_error( array( 'message' => __( 'Already checked in.', 'event-checkin' ) ) );
        }

        if ( $reg->status === 'cancelled' ) {
            wp_send_json_error( array( 'message' => __( 'Cannot check in a cancelled registration.', 'event-checkin' ) ) );
        }

        $wpdb->update(
            $table,
            array(
                'status'        => 'checked_in',
                'checked_in_at' => current_time( 'mysql' ),
            ),
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
                'action'          => 'manual_checkin',
                'performed_by'    => get_current_user_id(),
                'ip_address'      => $_SERVER['REMOTE_ADDR'] ?? '',
                'user_agent'      => $_SERVER['HTTP_USER_AGENT'] ?? '',
            ),
            array( '%d', '%d', '%s', '%d', '%s', '%s' )
        );

        wp_send_json_success( array(
            'message'       => sprintf( __( '%s has been checked in.', 'event-checkin' ), $reg->first_name . ' ' . $reg->last_name ),
            'checked_in_at' => wp_date( 'M j, H:i', current_time( 'timestamp' ) ),
        ) );
    }

    /**
     * AJAX: Cancel a registration.
     */
    public static function ajax_cancel_registration() {
        check_ajax_referer( 'ec_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ec_edit_registrations' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'event-checkin' ) ), 403 );
        }

        $reg_id = absint( $_POST['reg_id'] ?? 0 );
        if ( ! $reg_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid registration ID.', 'event-checkin' ) ) );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'ec_registrations';

        $wpdb->update(
            $table,
            array( 'status' => 'cancelled' ),
            array( 'id' => $reg_id ),
            array( '%s' ),
            array( '%d' )
        );

        wp_send_json_success( array( 'message' => __( 'Registration cancelled.', 'event-checkin' ) ) );
    }

    /**
     * AJAX: Add a new registration from the dashboard.
     */
    public static function ajax_add_registration() {
        check_ajax_referer( 'ec_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'ec_edit_registrations' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'event-checkin' ) ), 403 );
        }

        $event_id   = absint( $_POST['event_id'] ?? 0 );
        $first_name = sanitize_text_field( $_POST['first_name'] ?? '' );
        $last_name  = sanitize_text_field( $_POST['last_name'] ?? '' );
        $email      = sanitize_email( $_POST['email'] ?? '' );
        $phone      = sanitize_text_field( $_POST['phone'] ?? '' );

        if ( ! $event_id || ! $first_name || ! $last_name || ! $email ) {
            wp_send_json_error( array( 'message' => __( 'Event ID, first name, last name, and email are required.', 'event-checkin' ) ) );
        }

        global $wpdb;

        // Check event exists.
        $event = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}ec_events WHERE id = %d",
            $event_id
        ) );
        if ( ! $event ) {
            wp_send_json_error( array( 'message' => __( 'Event not found.', 'event-checkin' ) ) );
        }

        // Check for duplicate email.
        $existing = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}ec_registrations WHERE event_id = %d AND email = %s AND status != 'cancelled'",
            $event_id, $email
        ) );
        if ( $existing ) {
            wp_send_json_error( array( 'message' => __( 'This email is already registered for this event.', 'event-checkin' ) ) );
        }

        // Generate QR token.
        $qr_token = bin2hex( random_bytes( 32 ) );

        // Build custom data.
        $custom_data = array();
        foreach ( $_POST as $key => $value ) {
            if ( strpos( $key, 'custom_' ) === 0 ) {
                $label = str_replace( 'custom_', '', $key );
                $custom_data[ $label ] = sanitize_text_field( $value );
            }
        }

        $wpdb->insert(
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
                'ip_address'  => $_SERVER['REMOTE_ADDR'] ?? '',
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        $reg_id = $wpdb->insert_id;

        // Generate QR code.
        $qr_url = QRCode::generate( $qr_token, $event_id );

        // Queue confirmation email.
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

        wp_send_json_success( array(
            'message' => sprintf( __( 'Registration added for %s %s. Confirmation email sent.', 'event-checkin' ), $first_name, $last_name ),
            'reg_id'  => $reg_id,
        ) );
    }

    /**
     * Handle QR code download.
     */
    public static function handle_download_qr() {
        if ( ! current_user_can( 'ec_download_qr' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'event-checkin' ), 403 );
        }

        $reg_id = absint( $_GET['reg_id'] ?? 0 );
        if ( ! $reg_id ) {
            wp_die( esc_html__( 'Invalid registration ID.', 'event-checkin' ), 400 );
        }

        check_admin_referer( 'ec_download_qr_' . $reg_id );

        global $wpdb;
        $reg = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT r.*, e.title as event_title FROM {$wpdb->prefix}ec_registrations r
                 JOIN {$wpdb->prefix}ec_events e ON r.event_id = e.id
                 WHERE r.id = %d",
                $reg_id
            )
        );

        if ( ! $reg ) {
            wp_die( esc_html__( 'Registration not found.', 'event-checkin' ), 404 );
        }

        $upload_dir = wp_upload_dir();
        $filepath   = $upload_dir['basedir'] . '/event-checkin/qrcodes/qr-' . substr( $reg->qr_token, 0, 16 ) . '.png';

        // Regenerate if missing.
        if ( ! file_exists( $filepath ) ) {
            QRCode::generate( $reg->qr_token, $reg->event_id );
        }

        if ( ! file_exists( $filepath ) ) {
            wp_die( esc_html__( 'QR code file not found.', 'event-checkin' ), 404 );
        }

        $filename = sanitize_file_name( $reg->first_name . '-' . $reg->last_name . '-qr.png' );

        header( 'Content-Type: image/png' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . filesize( $filepath ) );
        header( 'Cache-Control: no-cache, no-store, must-revalidate' );
        readfile( $filepath );
        exit;
    }
}
