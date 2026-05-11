<?php
namespace EventCheckin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Digital signature handling utilities.
 */
class Signature {

    /**
     * Save signature data to a registration.
     *
     * @param int    $registration_id Registration ID.
     * @param string $signature_data  Base64 PNG data URI.
     * @return bool True on success.
     */
    public static function save( $registration_id, $signature_data ) {
        if ( ! Security::validate_signature_data( $signature_data ) ) {
            return false;
        }

        global $wpdb;
        return (bool) $wpdb->update(
            $wpdb->prefix . 'ec_registrations',
            array( 'signature_data' => $signature_data ),
            array( 'id' => $registration_id ),
            array( '%s' ),
            array( '%d' )
        );
    }

    /**
     * Get signature data for a registration.
     *
     * @param int $registration_id Registration ID.
     * @return string|null Signature data URI or null.
     */
    public static function get( $registration_id ) {
        global $wpdb;
        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT signature_data FROM {$wpdb->prefix}ec_registrations WHERE id = %d",
                $registration_id
            )
        );
    }

    /**
     * Convert a signature data URI to a binary PNG for embedding in exports.
     *
     * @param string $data_uri The data URI.
     * @return string|false Binary PNG data or false.
     */
    public static function to_binary( $data_uri ) {
        if ( empty( $data_uri ) ) {
            return false;
        }

        $prefix = 'data:image/png;base64,';
        if ( strpos( $data_uri, $prefix ) !== 0 ) {
            return false;
        }

        $base64 = substr( $data_uri, strlen( $prefix ) );
        return base64_decode( $base64, true );
    }

    /**
     * Save a signature to a temporary file for Excel embedding.
     *
     * @param string $data_uri Signature data URI.
     * @param int    $reg_id   Registration ID (for unique filename).
     * @return string|false File path or false.
     */
    public static function to_temp_file( $data_uri, $reg_id ) {
        $binary = self::to_binary( $data_uri );
        if ( ! $binary ) {
            return false;
        }

        $temp_dir = get_temp_dir() . 'ec-signatures/';
        if ( ! file_exists( $temp_dir ) ) {
            wp_mkdir_p( $temp_dir );
        }

        $path = $temp_dir . 'sig-' . intval( $reg_id ) . '.png';
        if ( file_put_contents( $path, $binary ) !== false ) {
            return $path;
        }

        return false;
    }
}
