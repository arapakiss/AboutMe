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

        add_submenu_page(
            'event-checkin',
            __( 'Email Template', 'event-checkin' ),
            __( 'Email Template', 'event-checkin' ),
            'ec_manage_settings',
            'ec-email-template',
            array( __CLASS__, 'render_email_template_page' )
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
                'confirmDelete' => __( 'Are you sure you want to delete this event? This cannot be undone.', 'event-checkin' ),
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
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=ec-add-event&event_id=' . $event->id ) ); ?>">
                                            <?php echo esc_html( $event->title ); ?>
                                        </a>
                                    </strong>
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
                                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=ec-registrations&event_id=' . $event->id ) ); ?>" class="button button-small">
                                        <?php esc_html_e( 'Registrations', 'event-checkin' ); ?>
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
}
