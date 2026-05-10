<?php
namespace EventCheckin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * DeepL Free API integration for form translation.
 *
 * Generates JSON translation files for the form labels and UI strings
 * in the languages configured via the form builder settings.
 */
class DeepL_Translate {

    const API_URL = 'https://api-free.deepl.com/v2/translate';
    const LANGS_URL = 'https://api-free.deepl.com/v2/languages';
    const CACHE_PREFIX = 'ec_deepl_';

    /**
     * Initialize hooks.
     */
    public static function init() {
        add_action( 'wp_ajax_ec_deepl_translate_form', array( __CLASS__, 'handle_translate' ) );
        add_action( 'wp_ajax_ec_deepl_test_key', array( __CLASS__, 'handle_test_key' ) );
    }

    /**
     * AJAX: Test the DeepL API key.
     */
    public static function handle_test_key() {
        check_ajax_referer( 'ec_form_builder', 'nonce' );

        if ( ! current_user_can( 'ec_manage_events' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }

        $api_key = sanitize_text_field( $_POST['api_key'] ?? '' );
        if ( empty( $api_key ) ) {
            wp_send_json_error( array( 'message' => 'API key is required.' ) );
        }

        $result = self::test_api_key( $api_key );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array( 'message' => 'API key is valid.' ) );
    }

    /**
     * AJAX: Translate form schema labels into target languages.
     */
    public static function handle_translate() {
        check_ajax_referer( 'ec_form_builder', 'nonce' );

        if ( ! current_user_can( 'ec_manage_events' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }

        $event_id  = absint( $_POST['event_id'] ?? 0 );
        $api_key   = sanitize_text_field( $_POST['api_key'] ?? '' );
        $languages = isset( $_POST['languages'] ) ? array_map( 'sanitize_text_field', (array) $_POST['languages'] ) : array();

        if ( ! $event_id || empty( $api_key ) || empty( $languages ) ) {
            wp_send_json_error( array( 'message' => 'Missing required parameters.' ) );
        }

        // Load form schema.
        global $wpdb;
        $event = $wpdb->get_row(
            $wpdb->prepare( "SELECT form_schema FROM {$wpdb->prefix}ec_events WHERE id = %d", $event_id )
        );

        if ( ! $event || empty( $event->form_schema ) ) {
            wp_send_json_error( array( 'message' => 'No form schema found for this event.' ) );
        }

        $schema = json_decode( $event->form_schema, true );
        if ( ! $schema || empty( $schema['steps'] ) ) {
            wp_send_json_error( array( 'message' => 'Invalid form schema.' ) );
        }

        // Extract translatable strings.
        $strings = self::extract_strings( $schema );
        if ( empty( $strings ) ) {
            wp_send_json_error( array( 'message' => 'No translatable strings found.' ) );
        }

        // Translate into each target language.
        $translations = array();
        $errors       = array();

        foreach ( $languages as $lang ) {
            if ( strtolower( $lang ) === 'en' ) {
                // English is the source, skip.
                $translations['en'] = array_combine( $strings, $strings );
                continue;
            }

            $cache_key  = self::CACHE_PREFIX . $event_id . '_' . $lang;
            $cached     = Settings::cache_get( $cache_key );

            if ( $cached && is_array( $cached ) ) {
                $translations[ $lang ] = $cached;
                continue;
            }

            $result = self::translate_batch( $strings, $lang, $api_key );
            if ( is_wp_error( $result ) ) {
                $errors[] = $lang . ': ' . $result->get_error_message();
                continue;
            }

            $translations[ $lang ] = $result;

            // Cache for 24 hours.
            Settings::cache_set( $cache_key, $result, 86400 );
        }

        // Store translations as event meta.
        $upload_dir = wp_upload_dir();
        $trans_dir  = $upload_dir['basedir'] . '/ec-translations';
        if ( ! file_exists( $trans_dir ) ) {
            wp_mkdir_p( $trans_dir );
            // Add index.php for security.
            file_put_contents( $trans_dir . '/index.php', '<?php // Silence is golden.' );
        }

        foreach ( $translations as $lang => $trans_data ) {
            $file_path = $trans_dir . '/form-' . $event_id . '-' . $lang . '.json';
            file_put_contents( $file_path, wp_json_encode( $trans_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
        }

        // Also store in options for quick access (events are in a custom table, not posts).
        update_option( 'ec_form_translations_' . $event_id, $translations, false );

        $response = array(
            'message'      => sprintf( 'Translated into %d language(s).', count( $translations ) ),
            'translations' => $translations,
            'files_dir'    => $upload_dir['baseurl'] . '/ec-translations/',
        );

        if ( ! empty( $errors ) ) {
            $response['warnings'] = $errors;
        }

        wp_send_json_success( $response );
    }

    /**
     * Extract translatable strings from a form schema.
     *
     * @param array $schema Form schema.
     * @return array Unique strings.
     */
    public static function extract_strings( $schema ) {
        $strings = array();

        // Settings strings.
        if ( ! empty( $schema['settings']['submit_label'] ) ) {
            $strings[] = $schema['settings']['submit_label'];
        }
        if ( ! empty( $schema['settings']['success_message'] ) ) {
            $strings[] = $schema['settings']['success_message'];
        }

        // Step and field strings.
        foreach ( $schema['steps'] as $step ) {
            if ( ! empty( $step['title'] ) )    $strings[] = $step['title'];
            if ( ! empty( $step['subtitle'] ) ) $strings[] = $step['subtitle'];
            if ( ! empty( $step['kicker'] ) )   $strings[] = $step['kicker'];

            foreach ( $step['fields'] as $field ) {
                if ( ! empty( $field['label'] ) )       $strings[] = $field['label'];
                if ( ! empty( $field['placeholder'] ) )  $strings[] = $field['placeholder'];
                if ( ! empty( $field['description'] ) )  $strings[] = $field['description'];

                // Option labels.
                if ( ! empty( $field['options'] ) && is_array( $field['options'] ) ) {
                    foreach ( $field['options'] as $opt ) {
                        if ( ! empty( $opt['label'] ) ) $strings[] = $opt['label'];
                        if ( ! empty( $opt['description'] ) ) $strings[] = $opt['description'];
                    }
                }
            }
        }

        // UI strings.
        $ui_strings = array(
            'Back', 'Continue', 'Review', 'Submit registration',
            'Step', 'of', 'This field is required',
            'Select Country', 'Select',
        );
        $strings = array_merge( $strings, $ui_strings );

        return array_values( array_unique( array_filter( $strings ) ) );
    }

    /**
     * Translate a batch of strings via DeepL Free API.
     *
     * @param array  $strings Source strings (English).
     * @param string $target  Target language code.
     * @param string $api_key DeepL API key.
     * @return array|\WP_Error Associative array source => translated.
     */
    public static function translate_batch( $strings, $target, $api_key ) {
        // DeepL uses uppercase language codes and some special mappings.
        $deepl_lang = strtoupper( $target );
        $lang_map   = array(
            'EL' => 'EL',
            'EN' => 'EN',
            'PT' => 'PT-BR',
            'ZH' => 'ZH-HANS',
        );
        if ( isset( $lang_map[ $deepl_lang ] ) ) {
            $deepl_lang = $lang_map[ $deepl_lang ];
        }

        // DeepL allows up to 50 texts per request.
        $chunks  = array_chunk( $strings, 50 );
        $results = array();

        foreach ( $chunks as $chunk ) {
            // DeepL expects multiple 'text' params with the same key.
            // WordPress wp_remote_post serializes arrays as text[0], text[1], etc.
            // We need to build the body string manually for repeated keys.
            $body_parts = array(
                'auth_key=' . urlencode( $api_key ),
                'target_lang=' . urlencode( $deepl_lang ),
            );
            foreach ( $chunk as $text ) {
                $body_parts[] = 'text=' . urlencode( $text );
            }
            $body_string = implode( '&', $body_parts );

            $response = wp_remote_post( self::API_URL, array(
                'timeout' => 30,
                'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
                'body'    => $body_string,
            ) );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $code = wp_remote_retrieve_response_code( $response );
            if ( $code !== 200 ) {
                $resp_body = json_decode( wp_remote_retrieve_body( $response ), true );
                $msg       = $resp_body['message'] ?? 'DeepL API error (HTTP ' . $code . ')';
                return new \WP_Error( 'deepl_error', $msg );
            }

            $resp_body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( empty( $resp_body['translations'] ) ) {
                return new \WP_Error( 'deepl_empty', 'Empty response from DeepL.' );
            }

            foreach ( $resp_body['translations'] as $i => $trans ) {
                $results[ $chunk[ $i ] ] = $trans['text'];
            }
        }

        return $results;
    }

    /**
     * Test if a DeepL API key is valid.
     *
     * @param string $api_key API key.
     * @return true|\WP_Error
     */
    public static function test_api_key( $api_key ) {
        $response = wp_remote_get( self::LANGS_URL . '?auth_key=' . urlencode( $api_key ), array(
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 403 ) {
            return new \WP_Error( 'invalid_key', 'Invalid DeepL API key.' );
        }
        if ( $code !== 200 ) {
            return new \WP_Error( 'api_error', 'DeepL API error (HTTP ' . $code . ').' );
        }

        return true;
    }

    /**
     * Load translations for a given event and language.
     *
     * @param int    $event_id Event ID.
     * @param string $lang     Language code.
     * @return array|null Translation map or null.
     */
    public static function load_translations( $event_id, $lang ) {
        $upload_dir = wp_upload_dir();
        $file       = $upload_dir['basedir'] . '/ec-translations/form-' . $event_id . '-' . $lang . '.json';

        if ( ! file_exists( $file ) ) {
            return null;
        }

        $contents = file_get_contents( $file );
        $data     = json_decode( $contents, true );

        return is_array( $data ) ? $data : null;
    }
}
