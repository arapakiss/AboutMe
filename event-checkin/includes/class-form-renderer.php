<?php
namespace EventCheckin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders the multi-step conversational form on the frontend.
 */
class Form_Renderer {

    public static function init() {
        // Override the default registration shortcode to use the new form system.
        remove_shortcode( 'event_registration' );
        add_shortcode( 'event_registration', array( __CLASS__, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
    }

    /**
     * Enqueue frontend form assets.
     */
    public static function enqueue_assets() {
        if ( ! is_singular() ) {
            return;
        }

        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content, 'event_registration' ) ) {
            return;
        }

        wp_enqueue_style( 'ec-form-frontend', EC_PLUGIN_URL . 'assets/css/form-frontend.css', array(), EC_VERSION );

        // Signature pad for signature fields.
        wp_enqueue_script( 'signature-pad', EC_PLUGIN_URL . 'assets/js/vendor/signature_pad.umd.min.js', array(), '4.1.7', true );

        wp_enqueue_script( 'ec-form-renderer', EC_PLUGIN_URL . 'assets/js/form-renderer.js', array( 'jquery', 'signature-pad' ), EC_VERSION, true );

        // reCAPTCHA if enabled.
        if ( Settings::is_recaptcha_enabled() ) {
            wp_enqueue_script( 'google-recaptcha', 'https://www.google.com/recaptcha/api.js?render=' . esc_attr( Settings::get( 'recaptcha_site_key' ) ), array(), null, true );
        }

        wp_localize_script( 'ec-form-renderer', 'ecFormRenderer', array(
            'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
            'restUrl'          => esc_url_raw( rest_url( 'event-checkin/v1' ) ),
            'nonce'            => wp_create_nonce( 'ec_registration_nonce' ),
            'restNonce'        => wp_create_nonce( 'wp_rest' ),
            'recaptchaEnabled' => Settings::is_recaptcha_enabled(),
            'recaptchaSiteKey' => Settings::is_recaptcha_enabled() ? Settings::get( 'recaptcha_site_key' ) : '',
        ) );
    }

    /**
     * Render the registration form shortcode.
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML.
     */
    public static function render_shortcode( $atts ) {
        $atts     = shortcode_atts( array( 'id' => 0 ), $atts, 'event_registration' );
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

        // Get form schema.
        $schema = null;
        if ( ! empty( $event->form_schema ) ) {
            $schema = json_decode( $event->form_schema, true );
        }
        if ( ! $schema || empty( $schema['steps'] ) ) {
            $schema = Form_Fields::get_default_schema();
        }

        $steps    = $schema['steps'];
        $settings = $schema['settings'] ?? array();

        ob_start();
        ?>
        <div class="ec-form-app" id="ec-form-app"
             data-event-id="<?php echo intval( $event_id ); ?>"
             data-schema="<?php echo esc_attr( wp_json_encode( $schema ) ); ?>">

            <!-- Left Panel -->
            <aside class="ec-form-left">
                <div class="ec-form-left-top">
                    <div class="ec-form-pill"><?php echo esc_html( $event->title ); ?></div>
                    <?php
                    $form_languages = $settings['languages'] ?? array( 'en' );
                    if ( count( $form_languages ) > 1 ) :
                        $lang_labels = self::get_language_labels();
                    ?>
                    <div class="ec-form-lang-switcher" id="ec-form-lang-switcher">
                        <select id="ec-form-language" class="ec-form-lang-select">
                            <?php foreach ( $form_languages as $lang_code ) : ?>
                                <option value="<?php echo esc_attr( $lang_code ); ?>">
                                    <?php echo esc_html( $lang_labels[ $lang_code ] ?? strtoupper( $lang_code ) ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                </div>
                <div>
                    <h1 class="ec-form-headline">
                        <?php esc_html_e( 'Tell us about', 'event-checkin' ); ?><br>
                        <span><?php esc_html_e( 'your participation', 'event-checkin' ); ?></span>
                    </h1>
                    <?php if ( $event->description ) : ?>
                        <p class="ec-form-lead"><?php echo esc_html( $event->description ); ?></p>
                    <?php endif; ?>

                    <nav class="ec-form-steps" id="ec-form-step-nav">
                        <?php foreach ( $steps as $i => $step ) : ?>
                            <div class="ec-form-step-link <?php echo $i === 0 ? 'is-active' : ''; ?>" data-step="<?php echo intval( $i ); ?>">
                                <span class="ec-form-step-no"><?php echo intval( $i + 1 ); ?></span>
                                <span>
                                    <span class="ec-form-step-title"><?php echo esc_html( $step['title'] ); ?></span>
                                    <?php if ( ! empty( $step['subtitle'] ) ) : ?>
                                        <span class="ec-form-step-sub"><?php echo esc_html( $step['subtitle'] ); ?></span>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                        <?php if ( ! empty( $settings['enable_review_step'] ) ) : ?>
                            <div class="ec-form-step-link" data-step="review">
                                <span class="ec-form-step-no"><?php echo count( $steps ) + 1; ?></span>
                                <span>
                                    <span class="ec-form-step-title"><?php esc_html_e( 'Review', 'event-checkin' ); ?></span>
                                    <span class="ec-form-step-sub"><?php esc_html_e( 'Submit registration', 'event-checkin' ); ?></span>
                                </span>
                            </div>
                        <?php endif; ?>
                    </nav>
                </div>
                <div></div>
            </aside>

            <!-- Right Panel -->
            <main class="ec-form-right">
                <div class="ec-form-topbar">
                    <div>
                        <h2 id="ec-form-top-title"><?php echo esc_html( $steps[0]['title'] ?? '' ); ?></h2>
                        <div class="ec-form-kicker" id="ec-form-top-kicker">
                            <?php printf( esc_html__( 'Step 1 of %d', 'event-checkin' ), count( $steps ) + ( ! empty( $settings['enable_review_step'] ) ? 1 : 0 ) ); ?>
                        </div>
                    </div>
                    <?php if ( ! empty( $settings['enable_progress_bar'] ) ) : ?>
                        <div class="ec-form-progress" id="ec-form-progress">
                            <?php
                            $total = count( $steps ) + ( ! empty( $settings['enable_review_step'] ) ? 1 : 0 );
                            for ( $i = 0; $i < $total; $i++ ) :
                            ?>
                                <span class="ec-form-progress-dot <?php echo $i === 0 ? 'on' : ''; ?>"></span>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <section class="ec-form-stage">
                    <form id="ec-form-main" novalidate>
                        <input type="hidden" name="action" value="ec_register">
                        <input type="hidden" name="event_id" value="<?php echo intval( $event_id ); ?>">
                        <input type="hidden" name="ec_nonce" value="<?php echo esc_attr( wp_create_nonce( 'ec_registration_nonce' ) ); ?>">
                        <!-- Honeypot -->
                        <div style="display:none !important;" aria-hidden="true">
                            <input type="text" name="ec_website" value="" tabindex="-1" autocomplete="off">
                        </div>

                        <?php foreach ( $steps as $i => $step ) : ?>
                            <div class="ec-form-panel <?php echo $i === 0 ? 'active' : ''; ?>" data-step-index="<?php echo intval( $i ); ?>">
                                <header>
                                    <?php if ( ! empty( $step['kicker'] ) ) : ?>
                                        <div class="ec-form-kicker"><?php echo esc_html( $step['kicker'] ); ?></div>
                                    <?php endif; ?>
                                    <h3 class="ec-form-question-title">
                                        <?php echo esc_html( $step['title'] ); ?>
                                    </h3>
                                    <?php if ( ! empty( $step['subtitle'] ) ) : ?>
                                        <p class="ec-form-question-help"><?php echo esc_html( $step['subtitle'] ); ?></p>
                                    <?php endif; ?>
                                </header>

                                <div class="ec-form-content">
                                    <?php
                                    foreach ( $step['fields'] as $field ) {
                                        echo self::render_field( $field, $event_id );
                                    }
                                    ?>
                                </div>

                                <div class="ec-form-actions">
                                    <?php if ( $i > 0 ) : ?>
                                        <button type="button" class="ec-form-btn ghost ec-form-prev"><?php esc_html_e( 'Back', 'event-checkin' ); ?></button>
                                    <?php else : ?>
                                        <span></span>
                                    <?php endif; ?>
                                    <button type="button" class="ec-form-btn primary ec-form-next"><?php esc_html_e( 'Continue', 'event-checkin' ); ?> &rarr;</button>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <?php if ( ! empty( $settings['enable_review_step'] ) ) : ?>
                            <div class="ec-form-panel" data-step-index="review">
                                <header>
                                    <div class="ec-form-kicker"><?php esc_html_e( 'Review', 'event-checkin' ); ?></div>
                                    <h3 class="ec-form-question-title"><?php esc_html_e( 'Review &', 'event-checkin' ); ?> <span><?php esc_html_e( 'submit', 'event-checkin' ); ?></span></h3>
                                </header>
                                <div class="ec-form-content">
                                    <div class="ec-ff-review" id="ec-form-review-data" data-width="full" style="grid-column:1/-1"></div>
                                </div>
                                <div class="ec-form-actions">
                                    <button type="button" class="ec-form-btn ghost ec-form-prev"><?php esc_html_e( 'Back', 'event-checkin' ); ?></button>
                                    <button type="submit" class="ec-form-btn primary">
                                        <?php echo esc_html( $settings['submit_label'] ?? __( 'Submit Registration', 'event-checkin' ) ); ?> &rarr;
                                    </button>
                                </div>
                            </div>
                        <?php endif; ?>
                    </form>

                    <!-- Success -->
                    <div class="ec-form-success" id="ec-form-success">
                        <div class="ec-form-success-icon">&#10003;</div>
                        <h3 class="ec-form-question-title"><?php esc_html_e( 'Thank you!', 'event-checkin' ); ?></h3>
                        <p class="ec-form-question-help">
                            <?php echo esc_html( $settings['success_message'] ?? __( 'Registration complete! Check your email for the QR code.', 'event-checkin' ) ); ?>
                        </p>
                        <div id="ec-form-success-qr" style="margin-top:24px;"></div>
                    </div>
                </section>
            </main>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a single field based on its type.
     *
     * @param array $field    Field config.
     * @param int   $event_id Event ID.
     * @return string HTML.
     */
    public static function render_field( $field, $event_id ) {
        $type     = $field['type'] ?? '';
        $id       = esc_attr( $field['id'] ?? '' );
        $name     = 'ecf_' . $id;
        $label    = esc_html( $field['label'] ?? '' );
        $required = ! empty( $field['required'] );
        $req_mark = $required ? ' *' : '';
        $width    = esc_attr( $field['width'] ?? 'full' );

        ob_start();

        switch ( $type ) {
            case 'short_text':
                ?>
                <div class="ec-ff" data-width="<?php echo $width; ?>" data-field-id="<?php echo $id; ?>">
                    <label class="ec-ff-label" for="<?php echo $id; ?>"><?php echo $label . $req_mark; ?></label>
                    <input class="ec-ff-input" type="text" id="<?php echo $id; ?>" name="<?php echo $name; ?>"
                           placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>"
                           maxlength="<?php echo intval( $field['maxlength'] ?? 255 ); ?>"
                           <?php echo $required ? 'required' : ''; ?>>
                </div>
                <?php
                break;

            case 'long_text':
                ?>
                <div class="ec-ff" data-width="<?php echo $width; ?>" data-field-id="<?php echo $id; ?>">
                    <label class="ec-ff-label" for="<?php echo $id; ?>"><?php echo $label . $req_mark; ?></label>
                    <textarea class="ec-ff-textarea" id="<?php echo $id; ?>" name="<?php echo $name; ?>"
                              placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>"
                              rows="<?php echo intval( $field['rows'] ?? 4 ); ?>"
                              maxlength="<?php echo intval( $field['maxlength'] ?? 5000 ); ?>"
                              <?php echo $required ? 'required' : ''; ?>></textarea>
                </div>
                <?php
                break;

            case 'email':
                ?>
                <div class="ec-ff" data-width="<?php echo $width; ?>" data-field-id="<?php echo $id; ?>" data-verify="<?php echo ! empty( $field['verify'] ) ? '1' : '0'; ?>">
                    <label class="ec-ff-label" for="<?php echo $id; ?>"><?php echo $label . $req_mark; ?></label>
                    <div style="display:flex;gap:10px;align-items:end;">
                        <input class="ec-ff-input" type="email" id="<?php echo $id; ?>" name="<?php echo $name; ?>"
                               placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>"
                               <?php echo $required ? 'required' : ''; ?> style="flex:1">
                        <?php if ( ! empty( $field['verify'] ) ) : ?>
                            <button type="button" class="ec-ff-verify-btn ec-verify-send" data-type="email" data-field="<?php echo $id; ?>"
                                    data-event="<?php echo intval( $event_id ); ?>">
                                <?php esc_html_e( 'Verify', 'event-checkin' ); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php if ( ! empty( $field['verify'] ) ) : ?>
                        <div class="ec-ff-otp" id="otp-<?php echo $id; ?>" style="display:none;">
                            <div class="ec-ff-otp-row">
                                <input class="ec-ff-input ec-otp-digit" type="text" maxlength="1" data-pos="0">
                                <input class="ec-ff-input ec-otp-digit" type="text" maxlength="1" data-pos="1">
                                <input class="ec-ff-input ec-otp-digit" type="text" maxlength="1" data-pos="2">
                                <input class="ec-ff-input ec-otp-digit" type="text" maxlength="1" data-pos="3">
                                <input class="ec-ff-input ec-otp-digit" type="text" maxlength="1" data-pos="4">
                                <input class="ec-ff-input ec-otp-digit" type="text" maxlength="1" data-pos="5">
                                <button type="button" class="ec-ff-verify-btn ec-verify-check" data-type="email" data-field="<?php echo $id; ?>">&#10003;</button>
                            </div>
                        </div>
                        <input type="hidden" name="<?php echo $name; ?>_verified" class="ec-verify-token" value="">
                    <?php endif; ?>
                </div>
                <?php
                break;

            case 'phone':
                $codes = $field['country_codes'] ?? array( '+30', '+44', '+1' );
                $default_code = $field['default_code'] ?? '+30';
                ?>
                <div class="ec-ff" data-width="<?php echo $width; ?>" data-field-id="<?php echo $id; ?>" data-verify="<?php echo ! empty( $field['verify'] ) ? '1' : '0'; ?>">
                    <label class="ec-ff-label"><?php echo $label . $req_mark; ?></label>
                    <div class="ec-ff-phone-box">
                        <select class="ec-ff-select" name="<?php echo $name; ?>_code" id="<?php echo $id; ?>_code">
                            <?php foreach ( $codes as $code ) : ?>
                                <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $code, $default_code ); ?>><?php echo esc_html( $code ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input class="ec-ff-input" type="tel" id="<?php echo $id; ?>" name="<?php echo $name; ?>"
                               placeholder="<?php echo esc_attr( $field['placeholder'] ?? '' ); ?>"
                               <?php echo $required ? 'required' : ''; ?>>
                    </div>
                    <?php if ( ! empty( $field['verify'] ) ) : ?>
                        <button type="button" class="ec-ff-verify-btn ec-verify-send" data-type="sms" data-field="<?php echo $id; ?>"
                                data-event="<?php echo intval( $event_id ); ?>" style="margin-top:8px;width:100%">
                            <?php esc_html_e( 'Send SMS Code', 'event-checkin' ); ?>
                        </button>
                        <div class="ec-ff-otp" id="otp-<?php echo $id; ?>" style="display:none;">
                            <div class="ec-ff-otp-row">
                                <input class="ec-ff-input ec-otp-digit" type="text" maxlength="1" data-pos="0">
                                <input class="ec-ff-input ec-otp-digit" type="text" maxlength="1" data-pos="1">
                                <input class="ec-ff-input ec-otp-digit" type="text" maxlength="1" data-pos="2">
                                <input class="ec-ff-input ec-otp-digit" type="text" maxlength="1" data-pos="3">
                                <input class="ec-ff-input ec-otp-digit" type="text" maxlength="1" data-pos="4">
                                <input class="ec-ff-input ec-otp-digit" type="text" maxlength="1" data-pos="5">
                                <button type="button" class="ec-ff-verify-btn ec-verify-check" data-type="sms" data-field="<?php echo $id; ?>">&#10003;</button>
                            </div>
                        </div>
                        <input type="hidden" name="<?php echo $name; ?>_verified" class="ec-verify-token" value="">
                    <?php endif; ?>
                </div>
                <?php
                break;

            case 'website':
                ?>
                <div class="ec-ff" data-width="<?php echo $width; ?>" data-field-id="<?php echo $id; ?>">
                    <label class="ec-ff-label" for="<?php echo $id; ?>"><?php echo $label . $req_mark; ?></label>
                    <?php if ( ! empty( $field['show_preview'] ) ) : ?>
                        <div class="ec-ff-website-wrap">
                            <input class="ec-ff-input ec-website-input" type="url" id="<?php echo $id; ?>" name="<?php echo $name; ?>"
                                   placeholder="<?php echo esc_attr( $field['placeholder'] ?? 'https://' ); ?>"
                                   <?php echo $required ? 'required' : ''; ?>>
                            <aside class="ec-ff-preview-card">
                                <div class="ec-ff-preview-top"></div>
                                <div class="ec-ff-preview-body">
                                    <b class="ec-website-preview-title">Website</b>
                                    <p class="ec-website-preview-url">example.com</p>
                                </div>
                            </aside>
                        </div>
                    <?php else : ?>
                        <input class="ec-ff-input" type="url" id="<?php echo $id; ?>" name="<?php echo $name; ?>"
                               placeholder="<?php echo esc_attr( $field['placeholder'] ?? 'https://' ); ?>"
                               <?php echo $required ? 'required' : ''; ?>>
                    <?php endif; ?>
                </div>
                <?php
                break;

            case 'radio':
                $layout = $field['layout'] ?? 'cards';
                ?>
                <div class="ec-ff" data-width="<?php echo $width; ?>" data-field-id="<?php echo $id; ?>">
                    <label class="ec-ff-label"><?php echo $label . $req_mark; ?></label>
                    <div class="ec-ff-choice-grid">
                        <?php foreach ( ( $field['options'] ?? array() ) as $j => $opt ) : ?>
                            <label class="ec-ff-choice">
                                <input type="radio" name="<?php echo $name; ?>" value="<?php echo esc_attr( $opt['value'] ); ?>" <?php echo $j === 0 ? 'checked' : ''; ?>>
                                <span class="ec-ff-choice-mark">&#10003;</span>
                                <b><?php echo esc_html( $opt['label'] ); ?></b>
                                <?php if ( ! empty( $opt['description'] ) ) : ?>
                                    <p><?php echo esc_html( $opt['description'] ); ?></p>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php
                break;

            case 'checkbox':
                $layout = $field['layout'] ?? 'chips';
                ?>
                <div class="ec-ff" data-width="<?php echo $width; ?>" data-field-id="<?php echo $id; ?>">
                    <label class="ec-ff-label"><?php echo $label . $req_mark; ?></label>
                    <div class="ec-ff-chips">
                        <?php foreach ( ( $field['options'] ?? array() ) as $opt ) : ?>
                            <label class="<?php echo $layout === 'cards' ? 'ec-ff-choice' : 'ec-ff-chip'; ?>">
                                <input type="checkbox" name="<?php echo $name; ?>[]" value="<?php echo esc_attr( $opt['value'] ); ?>">
                                <?php if ( $layout === 'cards' ) : ?>
                                    <span class="ec-ff-choice-mark">&#10003;</span>
                                    <b><?php echo esc_html( $opt['label'] ); ?></b>
                                <?php else : ?>
                                    <?php echo esc_html( $opt['label'] ); ?>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php
                break;

            case 'dropdown':
                ?>
                <div class="ec-ff" data-width="<?php echo $width; ?>" data-field-id="<?php echo $id; ?>">
                    <label class="ec-ff-label" for="<?php echo $id; ?>"><?php echo $label . $req_mark; ?></label>
                    <select class="ec-ff-select" id="<?php echo $id; ?>" name="<?php echo $name; ?>" <?php echo $required ? 'required' : ''; ?>>
                        <option value=""><?php esc_html_e( '-- Select --', 'event-checkin' ); ?></option>
                        <?php foreach ( ( $field['options'] ?? array() ) as $opt ) : ?>
                            <option value="<?php echo esc_attr( $opt['value'] ); ?>"><?php echo esc_html( $opt['label'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php
                break;

            case 'datetime':
                ?>
                <div class="ec-ff" data-width="<?php echo $width; ?>" data-field-id="<?php echo $id; ?>">
                    <label class="ec-ff-label" for="<?php echo $id; ?>"><?php echo $label . $req_mark; ?></label>
                    <?php
                    $mode = $field['mode'] ?? 'both';
                    $input_type = $mode === 'date' ? 'date' : ( $mode === 'time' ? 'time' : 'datetime-local' );
                    ?>
                    <input class="ec-ff-input" type="<?php echo $input_type; ?>" id="<?php echo $id; ?>" name="<?php echo $name; ?>"
                           <?php echo $required ? 'required' : ''; ?>
                           <?php echo ! empty( $field['min_date'] ) ? 'min="' . esc_attr( $field['min_date'] ) . '"' : ''; ?>
                           <?php echo ! empty( $field['max_date'] ) ? 'max="' . esc_attr( $field['max_date'] ) . '"' : ''; ?>>
                </div>
                <?php
                break;

            case 'file_upload':
                ?>
                <div class="ec-ff" data-width="<?php echo $width; ?>" data-field-id="<?php echo $id; ?>">
                    <label class="ec-ff-label"><?php echo $label . $req_mark; ?></label>
                    <div class="ec-ff-upload" onclick="this.querySelector('input').click()">
                        <b><?php esc_html_e( 'Drag & drop or click to upload', 'event-checkin' ); ?></b>
                        <p style="color:var(--ecf-muted);font-size:13px;margin:4px 0 0;">
                            <?php echo esc_html( $field['accept'] ?? '' ); ?> &mdash; max <?php echo intval( $field['max_size_mb'] ?? 10 ); ?>MB
                        </p>
                        <input type="file" name="<?php echo $name; ?>"
                               accept="<?php echo esc_attr( $field['accept'] ?? '' ); ?>"
                               <?php echo ! empty( $field['multiple'] ) ? 'multiple' : ''; ?>
                               <?php echo $required ? 'required' : ''; ?>>
                    </div>
                </div>
                <?php
                break;

            case 'range':
                ?>
                <div class="ec-ff" data-width="<?php echo $width; ?>" data-field-id="<?php echo $id; ?>">
                    <label class="ec-ff-label"><?php echo $label; ?></label>
                    <div class="ec-ff-range-wrap">
                        <div class="ec-ff-range-row">
                            <input type="range" name="<?php echo $name; ?>"
                                   min="<?php echo esc_attr( $field['min'] ?? 1 ); ?>"
                                   max="<?php echo esc_attr( $field['max'] ?? 10 ); ?>"
                                   step="<?php echo esc_attr( $field['step'] ?? 1 ); ?>"
                                   value="<?php echo esc_attr( $field['default_val'] ?? 5 ); ?>"
                                   oninput="this.closest('.ec-ff-range-wrap').querySelector('.ec-ff-range-value').textContent=this.value">
                            <div class="ec-ff-range-value"><?php echo esc_html( $field['default_val'] ?? 5 ); ?></div>
                        </div>
                        <?php if ( ! empty( $field['description'] ) ) : ?>
                            <p style="color:var(--ecf-muted);font-size:13px;margin:8px 0 0;"><?php echo esc_html( $field['description'] ); ?></p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
                break;

            case 'signature':
                ?>
                <div class="ec-ff" data-width="<?php echo $width; ?>" data-field-id="<?php echo $id; ?>">
                    <label class="ec-ff-label"><?php echo $label . $req_mark; ?></label>
                    <div class="ec-ff-signature" id="sig-<?php echo $id; ?>">
                        <canvas id="sig-canvas-<?php echo $id; ?>"></canvas>
                    </div>
                    <input type="hidden" name="<?php echo $name; ?>" id="sig-data-<?php echo $id; ?>">
                    <button type="button" class="ec-form-btn ghost ec-sig-clear" data-field="<?php echo $id; ?>" style="margin-top:8px;">
                        <?php esc_html_e( 'Clear Signature', 'event-checkin' ); ?>
                    </button>
                </div>
                <?php
                break;

            case 'social':
                $platforms = $field['platforms'] ?? array( 'linkedin', 'twitter', 'instagram' );
                $icons = array( 'linkedin' => 'in', 'twitter' => 'X', 'instagram' => 'IG', 'facebook' => 'f', 'youtube' => 'YT', 'tiktok' => 'TT', 'github' => 'GH' );
                ?>
                <div class="ec-ff" data-width="<?php echo $width; ?>" data-field-id="<?php echo $id; ?>">
                    <label class="ec-ff-label"><?php echo $label; ?></label>
                    <div class="ec-ff-social-grid">
                        <?php foreach ( $platforms as $platform ) : ?>
                            <div class="ec-ff-social">
                                <div class="ec-ff-social-icon"><?php echo esc_html( $icons[ $platform ] ?? $platform[0] ); ?></div>
                                <b><?php echo esc_html( ucfirst( $platform ) ); ?></b>
                                <input type="text" name="<?php echo $name; ?>[<?php echo esc_attr( $platform ); ?>]" placeholder="@username">
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php
                break;

            case 'country':
                $countries = self::get_country_list();
                ?>
                <div class="ec-ff" data-width="<?php echo $width; ?>" data-field-id="<?php echo $id; ?>">
                    <label class="ec-ff-label" for="<?php echo $id; ?>"><?php echo $label . $req_mark; ?></label>
                    <select class="ec-ff-select ec-ff-country-select" id="<?php echo $id; ?>" name="<?php echo $name; ?>" <?php echo $required ? 'required' : ''; ?>>
                        <option value=""><?php esc_html_e( '-- Select Country --', 'event-checkin' ); ?></option>
                        <?php foreach ( $countries as $code => $country_name ) : ?>
                            <option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $country_name ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php
                break;

            case 'company_info':
                ?>
                <div class="ec-ff ec-ff-company-card" data-width="<?php echo $width; ?>" data-field-id="<?php echo $id; ?>">
                    <div class="ec-company-card">
                        <div class="ec-company-card-header">
                            <?php if ( ! empty( $field['show_logo'] ) ) : ?>
                            <div class="ec-company-card-logo" id="ec-company-logo-preview">
                                <span class="ec-company-card-logo-placeholder">&#127970;</span>
                            </div>
                            <?php endif; ?>
                            <div class="ec-company-card-title">
                                <h4 id="ec-company-card-name"><?php esc_html_e( 'Company Name', 'event-checkin' ); ?></h4>
                                <span class="ec-company-card-type" id="ec-company-card-type"><?php esc_html_e( 'Company Type', 'event-checkin' ); ?></span>
                            </div>
                        </div>
                        <div class="ec-company-card-body">
                            <div class="ec-company-card-row">
                                <span class="ec-company-card-icon">&#127760;</span>
                                <span id="ec-company-card-website">&mdash;</span>
                            </div>
                            <div class="ec-company-card-row">
                                <span class="ec-company-card-icon">&#128205;</span>
                                <span id="ec-company-card-location">&mdash;</span>
                            </div>
                            <div class="ec-company-card-row">
                                <span class="ec-company-card-icon">@</span>
                                <span id="ec-company-card-email">&mdash;</span>
                            </div>
                            <div class="ec-company-card-row">
                                <span class="ec-company-card-icon">&#9742;</span>
                                <span id="ec-company-card-phone">&mdash;</span>
                            </div>
                            <?php if ( ! empty( $field['show_desc'] ) ) : ?>
                            <div class="ec-company-card-desc" id="ec-company-card-desc"></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php
                break;

            case 'hidden':
                ?>
                <input type="hidden" name="<?php echo esc_attr( $field['name'] ?? $name ); ?>" value="<?php echo esc_attr( $field['value'] ?? '' ); ?>">
                <?php
                break;

            case 'section_break':
                ?>
                <div class="ec-ff-section-break" data-width="full">
                    <?php if ( ! empty( $field['title'] ) ) : ?>
                        <h4><?php echo esc_html( $field['title'] ); ?></h4>
                    <?php endif; ?>
                    <?php if ( ! empty( $field['description'] ) ) : ?>
                        <p><?php echo esc_html( $field['description'] ); ?></p>
                    <?php endif; ?>
                </div>
                <?php
                break;
        }

        return ob_get_clean();
    }

    /**
     * Get list of countries (ISO 3166-1 alpha-2).
     *
     * @return array Associative array of code => name.
     */
    public static function get_country_list() {
        $list = array(
            'AF' => 'Afghanistan', 'AL' => 'Albania', 'DZ' => 'Algeria', 'AD' => 'Andorra',
            'AO' => 'Angola', 'AR' => 'Argentina', 'AM' => 'Armenia', 'AU' => 'Australia',
            'AT' => 'Austria', 'AZ' => 'Azerbaijan', 'BH' => 'Bahrain', 'BD' => 'Bangladesh',
            'BY' => 'Belarus', 'BE' => 'Belgium', 'BA' => 'Bosnia and Herzegovina',
            'BR' => 'Brazil', 'BG' => 'Bulgaria', 'CA' => 'Canada', 'CL' => 'Chile',
            'CN' => 'China', 'CO' => 'Colombia', 'HR' => 'Croatia', 'CU' => 'Cuba',
            'CY' => 'Cyprus', 'CZ' => 'Czech Republic', 'DK' => 'Denmark', 'EC' => 'Ecuador',
            'EG' => 'Egypt', 'EE' => 'Estonia', 'ET' => 'Ethiopia', 'FI' => 'Finland',
            'FR' => 'France', 'GE' => 'Georgia', 'DE' => 'Germany', 'GH' => 'Ghana',
            'GR' => 'Greece', 'GT' => 'Guatemala', 'HU' => 'Hungary', 'IS' => 'Iceland',
            'IN' => 'India', 'ID' => 'Indonesia', 'IR' => 'Iran', 'IQ' => 'Iraq',
            'IE' => 'Ireland', 'IL' => 'Israel', 'IT' => 'Italy', 'JM' => 'Jamaica',
            'JP' => 'Japan', 'JO' => 'Jordan', 'KZ' => 'Kazakhstan', 'KE' => 'Kenya',
            'KW' => 'Kuwait', 'LV' => 'Latvia', 'LB' => 'Lebanon', 'LY' => 'Libya',
            'LT' => 'Lithuania', 'LU' => 'Luxembourg', 'MK' => 'North Macedonia',
            'MY' => 'Malaysia', 'MT' => 'Malta', 'MX' => 'Mexico', 'MD' => 'Moldova',
            'MC' => 'Monaco', 'ME' => 'Montenegro', 'MA' => 'Morocco', 'NL' => 'Netherlands',
            'NZ' => 'New Zealand', 'NG' => 'Nigeria', 'NO' => 'Norway', 'OM' => 'Oman',
            'PK' => 'Pakistan', 'PA' => 'Panama', 'PE' => 'Peru', 'PH' => 'Philippines',
            'PL' => 'Poland', 'PT' => 'Portugal', 'QA' => 'Qatar', 'RO' => 'Romania',
            'RU' => 'Russia', 'SA' => 'Saudi Arabia', 'RS' => 'Serbia', 'SG' => 'Singapore',
            'SK' => 'Slovakia', 'SI' => 'Slovenia', 'ZA' => 'South Africa',
            'KR' => 'South Korea', 'ES' => 'Spain', 'SE' => 'Sweden', 'CH' => 'Switzerland',
            'TW' => 'Taiwan', 'TH' => 'Thailand', 'TN' => 'Tunisia', 'TR' => 'Turkey',
            'UA' => 'Ukraine', 'AE' => 'United Arab Emirates', 'GB' => 'United Kingdom',
            'US' => 'United States', 'UY' => 'Uruguay', 'UZ' => 'Uzbekistan',
            'VE' => 'Venezuela', 'VN' => 'Vietnam',
        );
        asort( $list );
        return apply_filters( 'ec_country_list', $list );
    }

    /**
     * Get language labels for the language switcher.
     *
     * @return array Language code => label.
     */
    public static function get_language_labels() {
        return array(
            'en' => 'English',
            'el' => 'Greek',
            'fr' => 'French',
            'de' => 'German',
            'es' => 'Spanish',
            'tr' => 'Turkish',
            'pl' => 'Polish',
            'ar' => 'Arabic',
            'it' => 'Italian',
            'pt' => 'Portuguese',
            'nl' => 'Dutch',
            'ru' => 'Russian',
            'ja' => 'Japanese',
            'zh' => 'Chinese',
            'ko' => 'Korean',
        );
    }
}
