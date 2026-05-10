<?php
namespace EventCheckin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin form builder: drag-and-drop UI for building event registration forms.
 */
class Form_Builder {

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_ajax_ec_save_form_schema', array( __CLASS__, 'handle_save_schema' ) );
        add_action( 'wp_ajax_ec_load_form_schema', array( __CLASS__, 'handle_load_schema' ) );
        add_action( 'wp_ajax_ec_create_form_page', array( __CLASS__, 'handle_create_form_page' ) );
        add_action( 'wp_ajax_ec_create_kiosk_page', array( __CLASS__, 'handle_create_kiosk_page' ) );
    }

    /**
     * Register the form builder admin page.
     */
    public static function register_menu() {
        add_submenu_page(
            'event-checkin',
            __( 'Form Builder', 'event-checkin' ),
            __( 'Form Builder', 'event-checkin' ),
            'ec_manage_events',
            'ec-form-builder',
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * Enqueue form builder assets.
     *
     * @param string $hook Page hook.
     */
    public static function enqueue_assets( $hook ) {
        if ( $hook !== 'event-check-in_page_ec-form-builder' ) {
            return;
        }

        wp_enqueue_style(
            'ec-form-builder',
            EC_PLUGIN_URL . 'assets/css/form-builder.css',
            array(),
            EC_VERSION
        );

        wp_enqueue_script(
            'sortablejs',
            EC_PLUGIN_URL . 'assets/js/vendor/sortable.min.js',
            array(),
            '1.15.6',
            true
        );

        wp_enqueue_script(
            'ec-form-builder',
            EC_PLUGIN_URL . 'assets/js/form-builder.js',
            array( 'jquery', 'sortablejs' ),
            EC_VERSION,
            true
        );

        wp_localize_script( 'ec-form-builder', 'ecFormBuilder', array(
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'nonce'        => wp_create_nonce( 'ec_form_builder' ),
            'fieldTypes'   => Form_Fields::get_types(),
            'categories'   => Form_Fields::get_categories(),
            'widthOptions' => Form_Fields::get_width_options(),
            'defaultSchema' => Form_Fields::get_default_schema(),
            'i18n'         => array(
                'addStep'         => __( 'Add Step', 'event-checkin' ),
                'deleteStep'      => __( 'Delete Step', 'event-checkin' ),
                'deleteField'     => __( 'Delete Field', 'event-checkin' ),
                'confirmDelete'   => __( 'Are you sure?', 'event-checkin' ),
                'saved'           => __( 'Form saved!', 'event-checkin' ),
                'saving'          => __( 'Saving...', 'event-checkin' ),
                'dragHere'        => __( 'Drag fields here', 'event-checkin' ),
                'noFields'        => __( 'Drop fields from the palette to start building your form.', 'event-checkin' ),
                'stepTitle'       => __( 'Step', 'event-checkin' ),
                'fieldSettings'   => __( 'Field Settings', 'event-checkin' ),
                'required'        => __( 'Required', 'event-checkin' ),
                'label'           => __( 'Label', 'event-checkin' ),
                'placeholder'     => __( 'Placeholder', 'event-checkin' ),
                'width'           => __( 'Width', 'event-checkin' ),
                'options'         => __( 'Options', 'event-checkin' ),
                'addOption'       => __( 'Add Option', 'event-checkin' ),
                'verification'    => __( 'Enable Verification', 'event-checkin' ),
                'pageCreated'     => __( 'Registration page created!', 'event-checkin' ),
            ),
        ) );
    }

    /**
     * Render the form builder page.
     */
    public static function render_page() {
        $event_id = isset( $_GET['event_id'] ) ? absint( $_GET['event_id'] ) : 0;

        // Get list of events for the selector.
        global $wpdb;
        $events = $wpdb->get_results(
            "SELECT id, title, status FROM {$wpdb->prefix}ec_events ORDER BY created_at DESC"
        );

        $current_event = null;
        if ( $event_id ) {
            $current_event = $wpdb->get_row(
                $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ec_events WHERE id = %d", $event_id )
            );
        }

        $form_page_id  = get_option( 'ec_default_form_page_id', 0 );
        $form_page_url = $form_page_id ? get_permalink( $form_page_id ) : '';

        $kiosk_page_id  = $event_id ? get_option( 'ec_kiosk_page_' . $event_id, 0 ) : 0;
        $kiosk_page_url = $kiosk_page_id ? get_permalink( $kiosk_page_id ) : '';
        ?>
        <div class="wrap ec-builder-wrap">
            <!-- Top Bar -->
            <div class="ec-builder-topbar">
                <div class="ec-builder-topbar-left">
                    <h1><?php esc_html_e( 'Form Builder', 'event-checkin' ); ?></h1>
                    <select id="ec-event-selector" class="ec-builder-select">
                        <option value=""><?php esc_html_e( '-- Select Event --', 'event-checkin' ); ?></option>
                        <?php foreach ( $events as $ev ) : ?>
                            <option value="<?php echo intval( $ev->id ); ?>" <?php selected( $event_id, $ev->id ); ?>>
                                <?php echo esc_html( $ev->title ); ?> (<?php echo esc_html( $ev->status ); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ec-builder-topbar-right">
                    <button type="button" class="ec-builder-btn ec-builder-btn--ghost" id="ec-import-form" title="<?php esc_attr_e( 'Import form schema from JSON', 'event-checkin' ); ?>">
                        &#8593; <?php esc_html_e( 'Import', 'event-checkin' ); ?>
                    </button>
                    <input type="file" id="ec-import-file" accept=".json" style="display:none">
                    <button type="button" class="ec-builder-btn ec-builder-btn--ghost" id="ec-export-form" title="<?php esc_attr_e( 'Export form schema as JSON', 'event-checkin' ); ?>">
                        &#8595; <?php esc_html_e( 'Export', 'event-checkin' ); ?>
                    </button>
                    <button type="button" class="ec-builder-btn ec-builder-btn--ghost" id="ec-create-page">
                        <?php esc_html_e( 'Create Registration Page', 'event-checkin' ); ?>
                    </button>
                    <?php if ( $form_page_url ) : ?>
                        <a href="<?php echo esc_url( $form_page_url ); ?>" target="_blank" class="ec-builder-btn ec-builder-btn--ghost">
                            <?php esc_html_e( 'View Page', 'event-checkin' ); ?>
                        </a>
                    <?php endif; ?>
                    <button type="button" class="ec-builder-btn ec-builder-btn--ghost" id="ec-create-kiosk">
                        <?php esc_html_e( 'Create Kiosk Page', 'event-checkin' ); ?>
                    </button>
                    <?php if ( $kiosk_page_url ) : ?>
                        <a href="<?php echo esc_url( $kiosk_page_url ); ?>" target="_blank" class="ec-builder-btn ec-builder-btn--ghost">
                            <?php esc_html_e( 'View Kiosk', 'event-checkin' ); ?>
                        </a>
                    <?php endif; ?>
                    <button type="button" class="ec-builder-btn ec-builder-btn--primary" id="ec-save-form">
                        <?php esc_html_e( 'Save Form', 'event-checkin' ); ?>
                    </button>
                </div>
            </div>

            <!-- Page Creation Options -->
            <div class="ec-builder-page-options" id="ec-page-options">
                <label class="ec-setting-checkbox">
                    <input type="checkbox" id="ec-opt-hide-header" checked>
                    <?php esc_html_e( 'Hide theme header', 'event-checkin' ); ?>
                </label>
                <label class="ec-setting-checkbox">
                    <input type="checkbox" id="ec-opt-hide-footer" checked>
                    <?php esc_html_e( 'Hide theme footer', 'event-checkin' ); ?>
                </label>
            </div>

            <div class="ec-builder-layout" id="ec-builder-layout">
                <!-- Left: Field Palette -->
                <aside class="ec-builder-palette" id="ec-palette">
                    <div class="ec-palette-header">
                        <h3><?php esc_html_e( 'Fields', 'event-checkin' ); ?></h3>
                    </div>
                    <div class="ec-palette-fields" id="ec-palette-fields">
                        <!-- Populated by JS from fieldTypes -->
                    </div>

                    <!-- Step List -->
                    <div class="ec-palette-steps">
                        <h3><?php esc_html_e( 'Steps', 'event-checkin' ); ?></h3>
                        <div id="ec-step-list"></div>
                        <button type="button" class="ec-builder-btn ec-builder-btn--ghost ec-btn-full" id="ec-add-step">
                            + <?php esc_html_e( 'Add Step', 'event-checkin' ); ?>
                        </button>
                    </div>
                </aside>

                <!-- Center: Form Canvas -->
                <div class="ec-builder-canvas" id="ec-canvas">
                    <div class="ec-canvas-empty" id="ec-canvas-empty">
                        <p><?php esc_html_e( 'Select an event and start building your form.', 'event-checkin' ); ?></p>
                        <p><?php esc_html_e( 'Drag fields from the left palette onto the canvas.', 'event-checkin' ); ?></p>
                    </div>
                    <div class="ec-canvas-steps" id="ec-canvas-steps">
                        <!-- Rendered by JS -->
                    </div>
                </div>

                <!-- Right: Field Settings -->
                <aside class="ec-builder-settings" id="ec-settings-panel">
                    <div class="ec-settings-header">
                        <h3 id="ec-settings-title"><?php esc_html_e( 'Field Settings', 'event-checkin' ); ?></h3>
                        <button type="button" class="ec-settings-close" id="ec-settings-close">&times;</button>
                    </div>
                    <div class="ec-settings-body" id="ec-settings-body">
                        <p class="ec-settings-empty"><?php esc_html_e( 'Click a field to edit its settings.', 'event-checkin' ); ?></p>
                    </div>

                    <!-- Translation Settings -->
                    <div class="ec-settings-section ec-translation-settings">
                        <div class="ec-settings-header">
                            <h3><?php esc_html_e( 'Translation (DeepL)', 'event-checkin' ); ?></h3>
                        </div>
                        <div class="ec-settings-body">
                            <div class="ec-setting-group">
                                <label class="ec-setting-label"><?php esc_html_e( 'DeepL API Key (Free)', 'event-checkin' ); ?></label>
                                <input class="ec-setting-input" type="password" id="ec-deepl-api-key" placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx:fx">
                                <button type="button" class="ec-builder-btn ec-builder-btn--ghost ec-btn-full" id="ec-deepl-test" style="margin-top:6px">
                                    <?php esc_html_e( 'Test Key', 'event-checkin' ); ?>
                                </button>
                            </div>
                            <div class="ec-setting-group">
                                <label class="ec-setting-label"><?php esc_html_e( 'Form Languages', 'event-checkin' ); ?></label>
                                <?php
                                $all_langs = Form_Renderer::get_language_labels();
                                foreach ( $all_langs as $code => $name ) :
                                ?>
                                <label class="ec-setting-checkbox" style="margin-bottom:4px">
                                    <input type="checkbox" class="ec-lang-checkbox" value="<?php echo esc_attr( $code ); ?>" <?php echo $code === 'en' ? 'checked disabled' : ''; ?>>
                                    <?php echo esc_html( $name ); ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                            <button type="button" class="ec-builder-btn ec-builder-btn--primary ec-btn-full" id="ec-deepl-translate" style="margin-top:8px">
                                <?php esc_html_e( 'Generate Translations', 'event-checkin' ); ?>
                            </button>
                            <div id="ec-deepl-status" style="margin-top:8px;font-size:12px;color:var(--ecb-muted)"></div>
                        </div>
                    </div>
                </aside>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Save form schema for an event.
     */
    public static function handle_save_schema() {
        check_ajax_referer( 'ec_form_builder', 'nonce' );

        if ( ! current_user_can( 'ec_manage_events' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'event-checkin' ) ), 403 );
        }

        $event_id = absint( $_POST['event_id'] ?? 0 );
        $schema   = $_POST['schema'] ?? '';

        if ( ! $event_id ) {
            wp_send_json_error( array( 'message' => __( 'No event selected.', 'event-checkin' ) ), 400 );
        }

        // Decode and validate the schema.
        if ( is_string( $schema ) ) {
            $schema = json_decode( wp_unslash( $schema ), true );
        }

        if ( ! is_array( $schema ) || empty( $schema['steps'] ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid form schema.', 'event-checkin' ) ), 400 );
        }

        // Sanitize the schema.
        $clean_schema = self::sanitize_schema( $schema );

        global $wpdb;
        $updated = $wpdb->update(
            $wpdb->prefix . 'ec_events',
            array(
                'form_schema'  => wp_json_encode( $clean_schema ),
                'custom_fields' => wp_json_encode( $clean_schema ), // Keep backward compat.
            ),
            array( 'id' => $event_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        // Clear cache.
        Settings::cache_delete( 'event_' . $event_id );
        delete_transient( 'ec_event_' . $event_id );

        wp_send_json_success( array( 'message' => __( 'Form saved!', 'event-checkin' ) ) );
    }

    /**
     * AJAX: Load form schema for an event.
     */
    public static function handle_load_schema() {
        check_ajax_referer( 'ec_form_builder', 'nonce' );

        if ( ! current_user_can( 'ec_manage_events' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'event-checkin' ) ), 403 );
        }

        $event_id = absint( $_POST['event_id'] ?? 0 );
        if ( ! $event_id ) {
            wp_send_json_error( array( 'message' => __( 'No event selected.', 'event-checkin' ) ), 400 );
        }

        global $wpdb;
        $event = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ec_events WHERE id = %d", $event_id )
        );

        if ( ! $event ) {
            wp_send_json_error( array( 'message' => __( 'Event not found.', 'event-checkin' ) ), 404 );
        }

        $schema = null;

        // Try new form_schema first, fall back to custom_fields.
        if ( ! empty( $event->form_schema ) ) {
            $schema = json_decode( $event->form_schema, true );
        }

        // If no schema exists, provide default.
        if ( ! $schema || ! isset( $schema['steps'] ) ) {
            $schema = Form_Fields::get_default_schema();
        }

        wp_send_json_success( array(
            'schema' => $schema,
            'event'  => array(
                'id'     => $event->id,
                'title'  => $event->title,
                'status' => $event->status,
            ),
        ) );
    }

    /**
     * AJAX: Create a default registration page.
     */
    public static function handle_create_form_page() {
        check_ajax_referer( 'ec_form_builder', 'nonce' );

        if ( ! current_user_can( 'ec_manage_events' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'event-checkin' ) ), 403 );
        }

        $event_id = absint( $_POST['event_id'] ?? 0 );
        if ( ! $event_id ) {
            wp_send_json_error( array( 'message' => __( 'No event selected.', 'event-checkin' ) ), 400 );
        }

        // Check if page already exists.
        $existing = get_option( 'ec_form_page_' . $event_id, 0 );
        if ( $existing && get_post_status( $existing ) !== false ) {
            wp_send_json_success( array(
                'page_id'  => $existing,
                'page_url' => get_permalink( $existing ),
                'message'  => __( 'Page already exists.', 'event-checkin' ),
            ) );
        }

        global $wpdb;
        $event = $wpdb->get_row(
            $wpdb->prepare( "SELECT title FROM {$wpdb->prefix}ec_events WHERE id = %d", $event_id )
        );

        $hide_header = ! empty( $_POST['hide_header'] ) ? '1' : '0';
        $hide_footer = ! empty( $_POST['hide_footer'] ) ? '1' : '0';

        $page_id = wp_insert_post( array(
            'post_title'   => sprintf( __( 'Register: %s', 'event-checkin' ), $event ? $event->title : 'Event' ),
            'post_content' => '[event_registration id="' . $event_id . '" hide_header="' . $hide_header . '" hide_footer="' . $hide_footer . '"]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ) );

        if ( is_wp_error( $page_id ) ) {
            wp_send_json_error( array( 'message' => $page_id->get_error_message() ), 500 );
        }

        update_option( 'ec_form_page_' . $event_id, $page_id );
        update_option( 'ec_default_form_page_id', $page_id );

        wp_send_json_success( array(
            'page_id'  => $page_id,
            'page_url' => get_permalink( $page_id ),
            'message'  => __( 'Registration page created!', 'event-checkin' ),
        ) );
    }

    /**
     * AJAX: Create a kiosk check-in page for an event.
     */
    public static function handle_create_kiosk_page() {
        check_ajax_referer( 'ec_form_builder', 'nonce' );

        if ( ! current_user_can( 'ec_manage_events' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'event-checkin' ) ), 403 );
        }

        $event_id = absint( $_POST['event_id'] ?? 0 );
        if ( ! $event_id ) {
            wp_send_json_error( array( 'message' => __( 'No event selected.', 'event-checkin' ) ), 400 );
        }

        // Check if kiosk page already exists for this event.
        $existing = get_option( 'ec_kiosk_page_' . $event_id, 0 );
        if ( $existing && get_post_status( $existing ) !== false ) {
            wp_send_json_success( array(
                'page_id'  => $existing,
                'page_url' => get_permalink( $existing ),
                'message'  => __( 'Kiosk page already exists.', 'event-checkin' ),
            ) );
        }

        global $wpdb;
        $event = $wpdb->get_row(
            $wpdb->prepare( "SELECT title FROM {$wpdb->prefix}ec_events WHERE id = %d", $event_id )
        );

        // Kiosk defaults to hiding header/footer. Only show if explicitly set to 0.
        $hide_header = isset( $_POST['hide_header'] ) && $_POST['hide_header'] === '0' ? '0' : '1';
        $hide_footer = isset( $_POST['hide_footer'] ) && $_POST['hide_footer'] === '0' ? '0' : '1';

        $page_id = wp_insert_post( array(
            'post_title'   => sprintf( __( 'Kiosk: %s', 'event-checkin' ), $event ? $event->title : 'Event' ),
            'post_content' => '[event_kiosk id="' . $event_id . '" hide_header="' . $hide_header . '" hide_footer="' . $hide_footer . '"]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ) );

        if ( is_wp_error( $page_id ) ) {
            wp_send_json_error( array( 'message' => $page_id->get_error_message() ), 500 );
        }

        update_option( 'ec_kiosk_page_' . $event_id, $page_id );

        wp_send_json_success( array(
            'page_id'  => $page_id,
            'page_url' => get_permalink( $page_id ),
            'message'  => __( 'Kiosk page created!', 'event-checkin' ),
        ) );
    }

    /**
     * Sanitize a form schema.
     *
     * @param array $schema Raw schema.
     * @return array Sanitized schema.
     */
    private static function sanitize_schema( $schema ) {
        $languages = array( 'en' );
        if ( ! empty( $schema['settings']['languages'] ) && is_array( $schema['settings']['languages'] ) ) {
            $languages = array_map( 'sanitize_text_field', $schema['settings']['languages'] );
            if ( ! in_array( 'en', $languages, true ) ) {
                array_unshift( $languages, 'en' );
            }
        }

        $clean = array(
            'steps'    => array(),
            'settings' => array(
                'submit_label'        => sanitize_text_field( $schema['settings']['submit_label'] ?? __( 'Submit', 'event-checkin' ) ),
                'success_message'     => sanitize_textarea_field( $schema['settings']['success_message'] ?? '' ),
                'enable_review_step'  => ! empty( $schema['settings']['enable_review_step'] ),
                'enable_progress_bar' => ! empty( $schema['settings']['enable_progress_bar'] ),
                'languages'           => $languages,
                'deepl_api_key'       => sanitize_text_field( $schema['settings']['deepl_api_key'] ?? '' ),
            ),
        );

        if ( ! is_array( $schema['steps'] ?? null ) ) {
            return $clean;
        }

        foreach ( $schema['steps'] as $step ) {
            $clean_step = array(
                'id'       => sanitize_key( $step['id'] ?? Form_Fields::generate_step_id() ),
                'title'    => sanitize_text_field( $step['title'] ?? '' ),
                'subtitle' => sanitize_text_field( $step['subtitle'] ?? '' ),
                'kicker'   => sanitize_text_field( $step['kicker'] ?? '' ),
                'fields'   => array(),
            );

            if ( is_array( $step['fields'] ?? null ) ) {
                foreach ( $step['fields'] as $field ) {
                    $clean_field = self::sanitize_field_config( $field );
                    if ( $clean_field ) {
                        $clean_step['fields'][] = $clean_field;
                    }
                }
            }

            $clean['steps'][] = $clean_step;
        }

        return $clean;
    }

    /**
     * Sanitize a single field configuration.
     *
     * @param array $field Raw field config.
     * @return array|null Sanitized config or null.
     */
    private static function sanitize_field_config( $field ) {
        $types = Form_Fields::get_types();
        $type  = sanitize_key( $field['type'] ?? '' );

        if ( ! isset( $types[ $type ] ) ) {
            return null;
        }

        $clean = array(
            'id'   => sanitize_key( $field['id'] ?? Form_Fields::generate_field_id() ),
            'type' => $type,
        );

        // Merge with defaults and sanitize each property.
        $defaults = $types[ $type ]['defaults'];
        foreach ( $defaults as $key => $default ) {
            $value = $field[ $key ] ?? $default;

            if ( $key === 'options' && is_array( $value ) ) {
                $clean[ $key ] = array();
                foreach ( $value as $opt ) {
                    $clean[ $key ][] = array(
                        'label'       => sanitize_text_field( $opt['label'] ?? '' ),
                        'value'       => sanitize_key( $opt['value'] ?? '' ),
                        'description' => sanitize_text_field( $opt['description'] ?? '' ),
                    );
                }
            } elseif ( $key === 'platforms' && is_array( $value ) ) {
                $clean[ $key ] = array_map( 'sanitize_key', $value );
            } elseif ( $key === 'country_codes' && is_array( $value ) ) {
                $clean[ $key ] = array_map( 'sanitize_text_field', $value );
            } elseif ( $key === 'time_slots' && is_array( $value ) ) {
                $clean[ $key ] = array_map( 'sanitize_text_field', $value );
            } elseif ( is_bool( $default ) ) {
                $clean[ $key ] = (bool) $value;
            } elseif ( is_int( $default ) ) {
                $clean[ $key ] = (int) $value;
            } elseif ( is_float( $default ) ) {
                $clean[ $key ] = (float) $value;
            } else {
                $clean[ $key ] = sanitize_text_field( (string) $value );
            }
        }

        return $clean;
    }
}
