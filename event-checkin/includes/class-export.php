<?php
namespace EventCheckin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Excel/CSV export for event registrations.
 * Uses a lightweight XML-based Excel format (SpreadsheetML) to avoid external dependencies.
 */
class Export {

    public static function init() {
        add_action( 'admin_post_ec_export', array( __CLASS__, 'handle_export' ) );
    }

    /**
     * Handle the export request.
     */
    public static function handle_export() {
        if ( ! current_user_can( 'ec_export_data' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'event-checkin' ), 403 );
        }

        $event_id = absint( $_GET['event_id'] ?? 0 );
        if ( ! $event_id ) {
            wp_die( esc_html__( 'Invalid event ID.', 'event-checkin' ), 400 );
        }

        check_admin_referer( 'ec_export_' . $event_id );

        if ( ! Security::check_rate_limit( Security::RATE_LIMIT_EXPORT ) ) {
            wp_die( esc_html__( 'Too many export requests. Please wait.', 'event-checkin' ), 429 );
        }

        global $wpdb;

        $event = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ec_events WHERE id = %d", $event_id )
        );

        if ( ! $event ) {
            wp_die( esc_html__( 'Event not found.', 'event-checkin' ), 404 );
        }

        $registrations = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}ec_registrations WHERE event_id = %d ORDER BY created_at ASC",
                $event_id
            )
        );

        $custom_fields = $event->custom_fields ? json_decode( $event->custom_fields, true ) : array();
        $format        = isset( $_GET['format'] ) && $_GET['format'] === 'csv' ? 'csv' : 'xlsx';

        if ( $format === 'csv' ) {
            self::export_csv( $event, $registrations, $custom_fields );
        } else {
            self::export_xlsx( $event, $registrations, $custom_fields );
        }
    }

    /**
     * Export as CSV.
     *
     * @param object $event         Event object.
     * @param array  $registrations Array of registration objects.
     * @param array  $custom_fields Custom field definitions.
     */
    private static function export_csv( $event, $registrations, $custom_fields ) {
        $filename = sanitize_file_name( $event->title . '-registrations-' . gmdate( 'Y-m-d' ) . '.csv' );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        $output = fopen( 'php://output', 'w' );

        // BOM for Excel UTF-8 support.
        fprintf( $output, chr( 0xEF ) . chr( 0xBB ) . chr( 0xBF ) );

        // Header row.
        $headers = array(
            __( '#', 'event-checkin' ),
            __( 'First Name', 'event-checkin' ),
            __( 'Last Name', 'event-checkin' ),
            __( 'Email', 'event-checkin' ),
            __( 'Phone', 'event-checkin' ),
        );

        foreach ( $custom_fields as $field ) {
            $headers[] = $field['label'];
        }

        $headers[] = __( 'Status', 'event-checkin' );
        $headers[] = __( 'Checked In At', 'event-checkin' );
        $headers[] = __( 'Has Signature', 'event-checkin' );
        $headers[] = __( 'Registered At', 'event-checkin' );

        fputcsv( $output, $headers );

        // Data rows.
        foreach ( $registrations as $i => $reg ) {
            $custom_data = $reg->custom_data ? json_decode( $reg->custom_data, true ) : array();

            $row = array(
                $i + 1,
                $reg->first_name,
                $reg->last_name,
                $reg->email,
                $reg->phone,
            );

            foreach ( $custom_fields as $field ) {
                $row[] = $custom_data[ $field['label'] ] ?? '';
            }

            $row[] = ucfirst( str_replace( '_', ' ', $reg->status ) );
            $row[] = $reg->checked_in_at ? wp_date( 'Y-m-d H:i:s', strtotime( $reg->checked_in_at ) ) : '';
            $row[] = ! empty( $reg->signature_data ) ? __( 'Yes', 'event-checkin' ) : __( 'No', 'event-checkin' );
            $row[] = wp_date( 'Y-m-d H:i:s', strtotime( $reg->created_at ) );

            fputcsv( $output, $row );
        }

        fclose( $output );
        exit;
    }

    /**
     * Export as Excel (SpreadsheetML XML format).
     * This avoids needing PhpSpreadsheet or similar heavy dependencies.
     *
     * @param object $event         Event object.
     * @param array  $registrations Array of registration objects.
     * @param array  $custom_fields Custom field definitions.
     */
    private static function export_xlsx( $event, $registrations, $custom_fields ) {
        $filename = sanitize_file_name( $event->title . '-registrations-' . gmdate( 'Y-m-d' ) . '.xls' );

        header( 'Content-Type: application/vnd.ms-excel; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );

        // Build headers.
        $headers = array(
            __( '#', 'event-checkin' ),
            __( 'First Name', 'event-checkin' ),
            __( 'Last Name', 'event-checkin' ),
            __( 'Email', 'event-checkin' ),
            __( 'Phone', 'event-checkin' ),
        );

        foreach ( $custom_fields as $field ) {
            $headers[] = $field['label'];
        }

        $headers[] = __( 'Status', 'event-checkin' );
        $headers[] = __( 'Checked In At', 'event-checkin' );
        $headers[] = __( 'Has Signature', 'event-checkin' );
        $headers[] = __( 'Signature', 'event-checkin' );
        $headers[] = __( 'Registered At', 'event-checkin' );

        // Output SpreadsheetML.
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"';
        echo ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";

        // Styles.
        echo '<Styles>' . "\n";
        echo '<Style ss:ID="header"><Font ss:Bold="1" ss:Size="11"/><Interior ss:Color="#4472C4" ss:Pattern="Solid"/><Font ss:Color="#FFFFFF" ss:Bold="1"/></Style>' . "\n";
        echo '<Style ss:ID="default"><Font ss:Size="10"/></Style>' . "\n";
        echo '<Style ss:ID="checkedin"><Interior ss:Color="#C6EFCE" ss:Pattern="Solid"/></Style>' . "\n";
        echo '</Styles>' . "\n";

        // Event info sheet.
        echo '<Worksheet ss:Name="' . esc_attr( mb_substr( $event->title, 0, 31 ) ) . '">' . "\n";
        echo '<Table>' . "\n";

        // Header row.
        echo '<Row>' . "\n";
        foreach ( $headers as $header ) {
            echo '<Cell ss:StyleID="header"><Data ss:Type="String">' . esc_html( $header ) . '</Data></Cell>' . "\n";
        }
        echo '</Row>' . "\n";

        // Data rows -- streaming to avoid memory issues.
        foreach ( $registrations as $i => $reg ) {
            $custom_data = $reg->custom_data ? json_decode( $reg->custom_data, true ) : array();
            $style       = $reg->status === 'checked_in' ? 'checkedin' : 'default';

            echo '<Row>' . "\n";
            echo '<Cell ss:StyleID="' . $style . '"><Data ss:Type="Number">' . ( $i + 1 ) . '</Data></Cell>' . "\n";
            echo '<Cell ss:StyleID="' . $style . '"><Data ss:Type="String">' . esc_html( $reg->first_name ) . '</Data></Cell>' . "\n";
            echo '<Cell ss:StyleID="' . $style . '"><Data ss:Type="String">' . esc_html( $reg->last_name ) . '</Data></Cell>' . "\n";
            echo '<Cell ss:StyleID="' . $style . '"><Data ss:Type="String">' . esc_html( $reg->email ) . '</Data></Cell>' . "\n";
            echo '<Cell ss:StyleID="' . $style . '"><Data ss:Type="String">' . esc_html( $reg->phone ) . '</Data></Cell>' . "\n";

            foreach ( $custom_fields as $field ) {
                $val = $custom_data[ $field['label'] ] ?? '';
                echo '<Cell ss:StyleID="' . $style . '"><Data ss:Type="String">' . esc_html( $val ) . '</Data></Cell>' . "\n";
            }

            echo '<Cell ss:StyleID="' . $style . '"><Data ss:Type="String">' . esc_html( ucfirst( str_replace( '_', ' ', $reg->status ) ) ) . '</Data></Cell>' . "\n";
            echo '<Cell ss:StyleID="' . $style . '"><Data ss:Type="String">' . esc_html( $reg->checked_in_at ? wp_date( 'Y-m-d H:i:s', strtotime( $reg->checked_in_at ) ) : '' ) . '</Data></Cell>' . "\n";
            echo '<Cell ss:StyleID="' . $style . '"><Data ss:Type="String">' . ( ! empty( $reg->signature_data ) ? esc_html__( 'Yes', 'event-checkin' ) : esc_html__( 'No', 'event-checkin' ) ) . '</Data></Cell>' . "\n";

            // For signatures, we include a note that they can be viewed in the admin panel.
            if ( ! empty( $reg->signature_data ) ) {
                echo '<Cell ss:StyleID="' . $style . '"><Data ss:Type="String">' . esc_html__( 'View in admin panel', 'event-checkin' ) . '</Data></Cell>' . "\n";
            } else {
                echo '<Cell ss:StyleID="' . $style . '"><Data ss:Type="String">-</Data></Cell>' . "\n";
            }

            echo '<Cell ss:StyleID="' . $style . '"><Data ss:Type="String">' . esc_html( wp_date( 'Y-m-d H:i:s', strtotime( $reg->created_at ) ) ) . '</Data></Cell>' . "\n";
            echo '</Row>' . "\n";

            // Flush output buffer periodically to manage memory.
            if ( $i % 100 === 0 ) {
                ob_flush();
                flush();
            }
        }

        echo '</Table>' . "\n";
        echo '</Worksheet>' . "\n";

        // Summary sheet.
        echo '<Worksheet ss:Name="Summary">' . "\n";
        echo '<Table>' . "\n";
        echo '<Row><Cell ss:StyleID="header"><Data ss:Type="String">' . esc_html__( 'Event Summary', 'event-checkin' ) . '</Data></Cell></Row>' . "\n";
        echo '<Row><Cell><Data ss:Type="String">' . esc_html__( 'Event', 'event-checkin' ) . '</Data></Cell><Cell><Data ss:Type="String">' . esc_html( $event->title ) . '</Data></Cell></Row>' . "\n";
        echo '<Row><Cell><Data ss:Type="String">' . esc_html__( 'Date', 'event-checkin' ) . '</Data></Cell><Cell><Data ss:Type="String">' . esc_html( wp_date( 'Y-m-d H:i', strtotime( $event->event_date ) ) ) . '</Data></Cell></Row>' . "\n";
        echo '<Row><Cell><Data ss:Type="String">' . esc_html__( 'Location', 'event-checkin' ) . '</Data></Cell><Cell><Data ss:Type="String">' . esc_html( $event->location ) . '</Data></Cell></Row>' . "\n";

        $total      = count( $registrations );
        $checked_in = count( array_filter( $registrations, function( $r ) { return $r->status === 'checked_in'; } ) );
        $signed     = count( array_filter( $registrations, function( $r ) { return ! empty( $r->signature_data ); } ) );

        echo '<Row><Cell><Data ss:Type="String">' . esc_html__( 'Total Registrations', 'event-checkin' ) . '</Data></Cell><Cell><Data ss:Type="Number">' . $total . '</Data></Cell></Row>' . "\n";
        echo '<Row><Cell><Data ss:Type="String">' . esc_html__( 'Checked In', 'event-checkin' ) . '</Data></Cell><Cell><Data ss:Type="Number">' . $checked_in . '</Data></Cell></Row>' . "\n";
        echo '<Row><Cell><Data ss:Type="String">' . esc_html__( 'With Signature', 'event-checkin' ) . '</Data></Cell><Cell><Data ss:Type="Number">' . $signed . '</Data></Cell></Row>' . "\n";
        echo '<Row><Cell><Data ss:Type="String">' . esc_html__( 'Exported At', 'event-checkin' ) . '</Data></Cell><Cell><Data ss:Type="String">' . esc_html( wp_date( 'Y-m-d H:i:s' ) ) . '</Data></Cell></Row>' . "\n";

        echo '</Table>' . "\n";
        echo '</Worksheet>' . "\n";
        echo '</Workbook>';

        exit;
    }
}

// Hook the export handler outside the class init for admin-post.
add_action( 'admin_post_ec_export', array( '\EventCheckin\Export', 'handle_export' ) );
