<?php
namespace EventCheckin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * QR code generation using the bundled phpqrcode library.
 */
class QRCode {

    /**
     * Generate a QR code PNG image for a given token.
     *
     * @param string $token   The QR token.
     * @param int    $event_id The event ID.
     * @return string|false URL to the QR code image, or false on failure.
     */
    public static function generate( $token, $event_id ) {
        $upload_dir = wp_upload_dir();
        $dir        = $upload_dir['basedir'] . '/event-checkin/qrcodes';

        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }

        $filename = 'qr-' . substr( $token, 0, 16 ) . '.png';
        $filepath = $dir . '/' . $filename;

        // Build a short, clean check-in URL for the QR code.
        // Uses /ec/TOKEN path format instead of query params to keep
        // the encoded content shorter and the QR code less dense.
        $checkin_url = home_url( '/ec/' . $token );

        // Generate QR code using bundled library.
        // Use medium error correction (M = 15% recovery) for the best balance
        // between scan reliability and code density. H-level (30%) creates
        // overly dense codes that are harder to read on phone cameras.
        // Module size 12 with margin 4 produces larger pixel blocks for
        // better camera recognition at distance while keeping file size reasonable.
        require_once EC_PLUGIN_DIR . 'lib/phpqrcode.php';

        \QRcode::png( $checkin_url, $filepath, QR_ECLEVEL_M, 12, 4 );

        if ( file_exists( $filepath ) ) {
            return $upload_dir['baseurl'] . '/event-checkin/qrcodes/' . $filename;
        }

        return false;
    }

    /**
     * Get the QR code URL for an existing registration.
     *
     * @param string $token The QR token.
     * @return string|false URL or false if not found.
     */
    public static function get_url( $token ) {
        $upload_dir = wp_upload_dir();
        $filename   = 'qr-' . substr( $token, 0, 16 ) . '.png';
        $filepath   = $upload_dir['basedir'] . '/event-checkin/qrcodes/' . $filename;

        if ( file_exists( $filepath ) ) {
            return $upload_dir['baseurl'] . '/event-checkin/qrcodes/' . $filename;
        }

        return false;
    }

    /**
     * Delete a QR code image file.
     *
     * @param string $token The QR token.
     */
    public static function delete( $token ) {
        $upload_dir = wp_upload_dir();
        $filename   = 'qr-' . substr( $token, 0, 16 ) . '.png';
        $filepath   = $upload_dir['basedir'] . '/event-checkin/qrcodes/' . $filename;

        if ( file_exists( $filepath ) ) {
            wp_delete_file( $filepath );
        }
    }

    /**
     * Get QR code as base64 data URI for embedding in emails.
     *
     * @param string $token The QR token.
     * @return string|false Data URI or false.
     */
    public static function get_base64( $token ) {
        $upload_dir = wp_upload_dir();
        $filename   = 'qr-' . substr( $token, 0, 16 ) . '.png';
        $filepath   = $upload_dir['basedir'] . '/event-checkin/qrcodes/' . $filename;

        if ( file_exists( $filepath ) ) {
            $data = file_get_contents( $filepath );
            return 'data:image/png;base64,' . base64_encode( $data );
        }

        return false;
    }
}
