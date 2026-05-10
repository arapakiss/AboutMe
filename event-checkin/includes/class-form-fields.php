<?php
namespace EventCheckin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Form field type registry. Defines all available field types,
 * their default settings, validation rules, and render methods.
 */
class Form_Fields {

    /**
     * Get all registered field types with their metadata.
     *
     * @return array Field type definitions.
     */
    public static function get_types() {
        return array(
            'short_text' => array(
                'label'    => __( 'Short Text', 'event-checkin' ),
                'icon'     => 'T',
                'category' => 'basic',
                'defaults' => array(
                    'label'       => __( 'Text Field', 'event-checkin' ),
                    'placeholder' => '',
                    'required'    => false,
                    'maxlength'   => 255,
                    'pattern'     => '',
                    'width'       => 'full',
                ),
            ),
            'long_text' => array(
                'label'    => __( 'Long Text', 'event-checkin' ),
                'icon'     => '&#9776;',
                'category' => 'basic',
                'defaults' => array(
                    'label'       => __( 'Text Area', 'event-checkin' ),
                    'placeholder' => '',
                    'required'    => false,
                    'maxlength'   => 5000,
                    'rows'        => 4,
                    'width'       => 'full',
                ),
            ),
            'email' => array(
                'label'    => __( 'Email', 'event-checkin' ),
                'icon'     => '@',
                'category' => 'contact',
                'defaults' => array(
                    'label'       => __( 'Email Address', 'event-checkin' ),
                    'placeholder' => 'name@example.com',
                    'required'    => true,
                    'verify'      => false,
                    'width'       => 'half',
                ),
            ),
            'phone' => array(
                'label'    => __( 'Phone', 'event-checkin' ),
                'icon'     => '&#9742;',
                'category' => 'contact',
                'defaults' => array(
                    'label'         => __( 'Phone Number', 'event-checkin' ),
                    'placeholder'   => '',
                    'required'      => false,
                    'verify'        => false,
                    'country_codes' => array( '+30', '+33', '+44', '+49', '+1', '+48', '+90', '+34' ),
                    'default_code'  => '+30',
                    'width'         => 'half',
                ),
            ),
            'website' => array(
                'label'    => __( 'Website', 'event-checkin' ),
                'icon'     => '&#127760;',
                'category' => 'contact',
                'defaults' => array(
                    'label'        => __( 'Website URL', 'event-checkin' ),
                    'placeholder'  => 'https://www.example.com',
                    'required'     => false,
                    'show_preview' => true,
                    'width'        => 'full',
                ),
            ),
            'radio' => array(
                'label'    => __( 'Radio Buttons', 'event-checkin' ),
                'icon'     => '&#9673;',
                'category' => 'choice',
                'defaults' => array(
                    'label'    => __( 'Select One', 'event-checkin' ),
                    'options'  => array(
                        array( 'label' => __( 'Option 1', 'event-checkin' ), 'value' => 'option_1', 'description' => '' ),
                        array( 'label' => __( 'Option 2', 'event-checkin' ), 'value' => 'option_2', 'description' => '' ),
                        array( 'label' => __( 'Option 3', 'event-checkin' ), 'value' => 'option_3', 'description' => '' ),
                    ),
                    'required' => false,
                    'layout'   => 'cards', // 'cards' or 'list'.
                    'width'    => 'full',
                ),
            ),
            'checkbox' => array(
                'label'    => __( 'Checkboxes', 'event-checkin' ),
                'icon'     => '&#9745;',
                'category' => 'choice',
                'defaults' => array(
                    'label'      => __( 'Select Multiple', 'event-checkin' ),
                    'options'    => array(
                        array( 'label' => __( 'Option A', 'event-checkin' ), 'value' => 'option_a' ),
                        array( 'label' => __( 'Option B', 'event-checkin' ), 'value' => 'option_b' ),
                        array( 'label' => __( 'Option C', 'event-checkin' ), 'value' => 'option_c' ),
                    ),
                    'required'   => false,
                    'min_select' => 0,
                    'max_select' => 0, // 0 = unlimited.
                    'layout'     => 'chips', // 'chips' or 'cards'.
                    'width'      => 'full',
                ),
            ),
            'dropdown' => array(
                'label'    => __( 'Dropdown', 'event-checkin' ),
                'icon'     => '&#9660;',
                'category' => 'choice',
                'defaults' => array(
                    'label'      => __( 'Select', 'event-checkin' ),
                    'options'    => array(
                        array( 'label' => __( 'Option 1', 'event-checkin' ), 'value' => 'option_1' ),
                        array( 'label' => __( 'Option 2', 'event-checkin' ), 'value' => 'option_2' ),
                    ),
                    'required'   => false,
                    'searchable' => false,
                    'width'      => 'half',
                ),
            ),
            'datetime' => array(
                'label'    => __( 'Date & Time', 'event-checkin' ),
                'icon'     => '&#128197;',
                'category' => 'advanced',
                'defaults' => array(
                    'label'      => __( 'Date & Time', 'event-checkin' ),
                    'required'   => false,
                    'mode'       => 'both', // 'date', 'time', 'both'.
                    'min_date'   => '',
                    'max_date'   => '',
                    'time_slots' => array( '09:00', '10:00', '11:00', '14:00', '16:00', '18:00' ),
                    'width'      => 'full',
                ),
            ),
            'file_upload' => array(
                'label'    => __( 'File Upload', 'event-checkin' ),
                'icon'     => '&#128206;',
                'category' => 'advanced',
                'defaults' => array(
                    'label'       => __( 'Upload File', 'event-checkin' ),
                    'required'    => false,
                    'accept'      => '.pdf,.doc,.docx,.ppt,.pptx,.jpg,.png',
                    'max_size_mb' => 10,
                    'multiple'    => false,
                    'width'       => 'full',
                ),
            ),
            'range' => array(
                'label'    => __( 'Range Slider', 'event-checkin' ),
                'icon'     => '&#8596;',
                'category' => 'advanced',
                'defaults' => array(
                    'label'       => __( 'Range', 'event-checkin' ),
                    'required'    => false,
                    'min'         => 1,
                    'max'         => 10,
                    'step'        => 1,
                    'default_val' => 5,
                    'unit'        => '',
                    'description' => '',
                    'width'       => 'half',
                ),
            ),
            'signature' => array(
                'label'    => __( 'Signature', 'event-checkin' ),
                'icon'     => '&#9999;',
                'category' => 'advanced',
                'defaults' => array(
                    'label'    => __( 'Signature', 'event-checkin' ),
                    'required' => false,
                    'width'    => 'full',
                ),
            ),
            'social' => array(
                'label'    => __( 'Social Links', 'event-checkin' ),
                'icon'     => '&#128279;',
                'category' => 'advanced',
                'defaults' => array(
                    'label'     => __( 'Social Profiles', 'event-checkin' ),
                    'platforms' => array( 'linkedin', 'twitter', 'instagram' ),
                    'required'  => false,
                    'width'     => 'full',
                ),
            ),
            'hidden' => array(
                'label'    => __( 'Hidden Field', 'event-checkin' ),
                'icon'     => '&#128065;',
                'category' => 'utility',
                'defaults' => array(
                    'name'  => 'tracking',
                    'value' => '',
                    'width' => 'full',
                ),
            ),
            'section_break' => array(
                'label'    => __( 'Section Break', 'event-checkin' ),
                'icon'     => '&#8212;',
                'category' => 'utility',
                'defaults' => array(
                    'title'       => '',
                    'description' => '',
                    'width'       => 'full',
                ),
            ),
        );
    }

    /**
     * Get field type categories.
     *
     * @return array Categories.
     */
    public static function get_categories() {
        return array(
            'basic'    => __( 'Basic Fields', 'event-checkin' ),
            'contact'  => __( 'Contact & Verification', 'event-checkin' ),
            'choice'   => __( 'Choice Fields', 'event-checkin' ),
            'advanced' => __( 'Advanced Fields', 'event-checkin' ),
            'utility'  => __( 'Utility', 'event-checkin' ),
        );
    }

    /**
     * Get width options for the column layout system.
     *
     * @return array Width options.
     */
    public static function get_width_options() {
        return array(
            'full'       => array( 'label' => __( 'Full Width', 'event-checkin' ), 'cols' => 6 ),
            'half'       => array( 'label' => __( 'Half', 'event-checkin' ), 'cols' => 3 ),
            'third'      => array( 'label' => __( 'One Third', 'event-checkin' ), 'cols' => 2 ),
            'two_thirds' => array( 'label' => __( 'Two Thirds', 'event-checkin' ), 'cols' => 4 ),
        );
    }

    /**
     * Generate a unique field ID.
     *
     * @return string Field ID.
     */
    public static function generate_field_id() {
        return 'field_' . bin2hex( random_bytes( 6 ) );
    }

    /**
     * Generate a unique step ID.
     *
     * @return string Step ID.
     */
    public static function generate_step_id() {
        return 'step_' . bin2hex( random_bytes( 4 ) );
    }

    /**
     * Create a new field instance with defaults.
     *
     * @param string $type Field type key.
     * @return array|null Field config or null if type unknown.
     */
    public static function create_field( $type ) {
        $types = self::get_types();
        if ( ! isset( $types[ $type ] ) ) {
            return null;
        }

        return array_merge(
            array(
                'id'   => self::generate_field_id(),
                'type' => $type,
            ),
            $types[ $type ]['defaults']
        );
    }

    /**
     * Create a default form schema for a new event.
     *
     * @return array Form schema with default steps.
     */
    public static function get_default_schema() {
        return array(
            'steps' => array(
                array(
                    'id'       => 'step_default_1',
                    'title'    => __( 'Your Details', 'event-checkin' ),
                    'subtitle' => __( 'Basic information', 'event-checkin' ),
                    'kicker'   => __( 'Registration', 'event-checkin' ),
                    'fields'   => array(
                        array(
                            'id'          => 'field_first_name',
                            'type'        => 'short_text',
                            'label'       => __( 'First Name', 'event-checkin' ),
                            'placeholder' => __( 'John', 'event-checkin' ),
                            'required'    => true,
                            'maxlength'   => 100,
                            'width'       => 'half',
                        ),
                        array(
                            'id'          => 'field_last_name',
                            'type'        => 'short_text',
                            'label'       => __( 'Last Name', 'event-checkin' ),
                            'placeholder' => __( 'Doe', 'event-checkin' ),
                            'required'    => true,
                            'maxlength'   => 100,
                            'width'       => 'half',
                        ),
                        array(
                            'id'          => 'field_email',
                            'type'        => 'email',
                            'label'       => __( 'Email Address', 'event-checkin' ),
                            'placeholder' => 'name@example.com',
                            'required'    => true,
                            'verify'      => false,
                            'width'       => 'half',
                        ),
                        array(
                            'id'          => 'field_phone',
                            'type'        => 'phone',
                            'label'       => __( 'Phone', 'event-checkin' ),
                            'placeholder' => '',
                            'required'    => false,
                            'verify'      => false,
                            'width'       => 'half',
                        ),
                    ),
                ),
            ),
            'settings' => array(
                'submit_label'       => __( 'Submit Registration', 'event-checkin' ),
                'success_message'    => __( 'Registration complete! Check your email for the QR code.', 'event-checkin' ),
                'enable_review_step' => true,
                'enable_progress_bar' => true,
            ),
        );
    }

    /**
     * Validate a single field value server-side.
     *
     * @param array  $field Field configuration.
     * @param mixed  $value Submitted value.
     * @return true|\WP_Error True if valid, WP_Error if not.
     */
    public static function validate_field( $field, $value ) {
        $type     = $field['type'] ?? '';
        $required = ! empty( $field['required'] );
        $label    = $field['label'] ?? __( 'Field', 'event-checkin' );

        // Required check.
        if ( $required && self::is_empty( $value ) ) {
            return new \WP_Error( 'required', sprintf( __( '%s is required.', 'event-checkin' ), $label ) );
        }

        // Skip further validation if empty and not required.
        if ( self::is_empty( $value ) ) {
            return true;
        }

        switch ( $type ) {
            case 'short_text':
            case 'long_text':
                $maxlength = (int) ( $field['maxlength'] ?? 5000 );
                if ( mb_strlen( $value ) > $maxlength ) {
                    return new \WP_Error( 'maxlength', sprintf( __( '%s exceeds maximum length of %d characters.', 'event-checkin' ), $label, $maxlength ) );
                }
                if ( ! empty( $field['pattern'] ) && ! preg_match( '/' . $field['pattern'] . '/', $value ) ) {
                    return new \WP_Error( 'pattern', sprintf( __( '%s format is invalid.', 'event-checkin' ), $label ) );
                }
                break;

            case 'email':
                if ( ! is_email( $value ) ) {
                    return new \WP_Error( 'email', sprintf( __( '%s must be a valid email address.', 'event-checkin' ), $label ) );
                }
                break;

            case 'phone':
                $phone = preg_replace( '/[^0-9+]/', '', $value );
                if ( strlen( $phone ) < 7 || strlen( $phone ) > 20 ) {
                    return new \WP_Error( 'phone', sprintf( __( '%s must be a valid phone number.', 'event-checkin' ), $label ) );
                }
                break;

            case 'website':
                if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
                    return new \WP_Error( 'url', sprintf( __( '%s must be a valid URL.', 'event-checkin' ), $label ) );
                }
                break;

            case 'radio':
            case 'dropdown':
                $valid_values = wp_list_pluck( $field['options'] ?? array(), 'value' );
                if ( ! in_array( $value, $valid_values, true ) ) {
                    return new \WP_Error( 'invalid_option', sprintf( __( 'Invalid selection for %s.', 'event-checkin' ), $label ) );
                }
                break;

            case 'checkbox':
                if ( is_array( $value ) ) {
                    $valid_values = wp_list_pluck( $field['options'] ?? array(), 'value' );
                    foreach ( $value as $v ) {
                        if ( ! in_array( $v, $valid_values, true ) ) {
                            return new \WP_Error( 'invalid_option', sprintf( __( 'Invalid selection for %s.', 'event-checkin' ), $label ) );
                        }
                    }
                    $min = (int) ( $field['min_select'] ?? 0 );
                    $max = (int) ( $field['max_select'] ?? 0 );
                    if ( $min > 0 && count( $value ) < $min ) {
                        return new \WP_Error( 'min_select', sprintf( __( 'Please select at least %d options for %s.', 'event-checkin' ), $min, $label ) );
                    }
                    if ( $max > 0 && count( $value ) > $max ) {
                        return new \WP_Error( 'max_select', sprintf( __( 'Please select at most %d options for %s.', 'event-checkin' ), $max, $label ) );
                    }
                }
                break;

            case 'range':
                $val = (float) $value;
                $min = (float) ( $field['min'] ?? 0 );
                $max = (float) ( $field['max'] ?? 100 );
                if ( $val < $min || $val > $max ) {
                    return new \WP_Error( 'range', sprintf( __( '%s must be between %s and %s.', 'event-checkin' ), $label, $min, $max ) );
                }
                break;

            case 'file_upload':
                // File validation is handled separately during upload.
                break;

            case 'signature':
                if ( ! Security::validate_signature_data( $value ) ) {
                    return new \WP_Error( 'signature', sprintf( __( 'Invalid signature for %s.', 'event-checkin' ), $label ) );
                }
                break;
        }

        return true;
    }

    /**
     * Sanitize a field value based on its type.
     *
     * @param array $field Field config.
     * @param mixed $value Raw value.
     * @return mixed Sanitized value.
     */
    public static function sanitize_field( $field, $value ) {
        $type = $field['type'] ?? '';

        switch ( $type ) {
            case 'short_text':
            case 'hidden':
                return sanitize_text_field( $value );

            case 'long_text':
                return sanitize_textarea_field( $value );

            case 'email':
                return sanitize_email( $value );

            case 'phone':
                return preg_replace( '/[^0-9+\-\s()]/', '', sanitize_text_field( $value ) );

            case 'website':
                return esc_url_raw( $value );

            case 'radio':
            case 'dropdown':
                return sanitize_key( $value );

            case 'checkbox':
                if ( is_array( $value ) ) {
                    return array_map( 'sanitize_key', $value );
                }
                return array();

            case 'datetime':
                return sanitize_text_field( $value );

            case 'range':
                return (float) $value;

            case 'signature':
                // Keep the data URI as-is (validated separately).
                return $value;

            case 'social':
                if ( is_array( $value ) ) {
                    return array_map( 'sanitize_text_field', $value );
                }
                return array();

            case 'file_upload':
                // File handling is separate.
                return sanitize_text_field( $value );

            default:
                return sanitize_text_field( $value );
        }
    }

    /**
     * Check if a value is empty.
     *
     * @param mixed $value Value to check.
     * @return bool
     */
    private static function is_empty( $value ) {
        if ( is_array( $value ) ) {
            return empty( $value );
        }
        return trim( (string) $value ) === '';
    }
}
