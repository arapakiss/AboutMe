<?php
namespace EventCheckin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * HTML email template system with admin editor and queue-based sending.
 */
class Email {

    const OPTION_TEMPLATE = 'ec_email_template';
    const OPTION_SUBJECT  = 'ec_email_subject';
    const QUEUE_HOOK      = 'ec_process_email_queue';

    /**
     * Default HTML email template with Istanbul Edition styling.
     */
    public static function get_default_template() {
        return '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{event_title} - Registration Confirmation</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f5f7;font-family:\'Plus Jakarta Sans\',\'Segoe UI\',Roboto,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f5f7;padding:40px 20px;">
<tr><td align="center">
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:0;overflow:hidden;max-width:600px;width:100%;">

<!-- Header -->
<tr>
<td style="background-color:#002d72;padding:40px 40px 30px;text-align:center;">
<h1 style="margin:0;font-size:28px;font-weight:800;color:#ffffff;text-transform:uppercase;letter-spacing:-0.02em;font-style:italic;">
Registration Confirmed
</h1>
<p style="margin:8px 0 0;font-size:14px;color:rgba(255,255,255,0.7);text-transform:uppercase;letter-spacing:0.15em;">
{event_title}
</p>
</td>
</tr>

<!-- Body -->
<tr>
<td style="padding:40px;">
<p style="margin:0 0 20px;font-size:16px;color:#002d72;line-height:1.6;">
Hello <strong>{first_name} {last_name}</strong>,
</p>
<p style="margin:0 0 24px;font-size:16px;color:#00122e;line-height:1.6;">
Thank you for registering. Your spot is confirmed. Please present the QR code below at the event entrance for check-in.
</p>

<!-- Event Details Card -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f8f9fb;border-left:4px solid #0babe4;padding:0;margin-bottom:30px;">
<tr><td style="padding:20px 24px;">
<p style="margin:0 0 4px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:0.15em;color:rgba(0,45,114,0.5);">Event Details</p>
<p style="margin:0 0 8px;font-size:18px;font-weight:700;color:#002d72;">{event_title}</p>
<p style="margin:0 0 4px;font-size:14px;color:#00122e;">Date: <strong>{event_date}</strong></p>
<p style="margin:0;font-size:14px;color:#00122e;">Location: <strong>{event_location}</strong></p>
</td></tr>
</table>

<!-- QR Code -->
<table role="presentation" width="100%" cellpadding="0" cellspacing="0">
<tr><td align="center" style="padding:10px 0 30px;">
<p style="margin:0 0 12px;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:0.15em;color:rgba(0,45,114,0.5);">Your QR Code</p>
<img src="{qr_code_url}" alt="QR Code" width="200" height="200" style="display:block;border:3px solid #002d72;width:200px;height:200px;">
<p style="margin:12px 0 0;font-size:12px;color:#666;">Save this code or show it on your phone at the entrance.</p>
</td></tr>
</table>

<p style="margin:0;font-size:14px;color:#00122e;line-height:1.6;">
If you have any questions, please contact us.
</p>
</td>
</tr>

<!-- Footer -->
<tr>
<td style="background-color:#00122e;padding:24px 40px;text-align:center;">
<p style="margin:0;font-size:12px;color:rgba(255,255,255,0.5);">
{site_name} &mdash; Powered by Event Check-in
</p>
</td>
</tr>

</table>
</td></tr>
</table>
</body>
</html>';
    }

    /**
     * Get the default email subject template.
     */
    public static function get_default_subject() {
        return __( 'Registration Confirmed: {event_title}', 'event-checkin' );
    }

    /**
     * Get the saved email template or default.
     */
    public static function get_template() {
        return get_option( self::OPTION_TEMPLATE, self::get_default_template() );
    }

    /**
     * Get the saved email subject or default.
     */
    public static function get_subject_template() {
        return get_option( self::OPTION_SUBJECT, self::get_default_subject() );
    }

    /**
     * Replace template variables with actual values.
     *
     * @param string $template The template string.
     * @param array  $vars     Associative array of variable => value.
     * @return string Rendered template.
     */
    public static function render( $template, $vars ) {
        foreach ( $vars as $key => $value ) {
            $template = str_replace( '{' . $key . '}', esc_html( $value ), $template );
        }
        return $template;
    }

    /**
     * Queue an email for sending via WP Cron.
     *
     * @param string $to         Recipient email.
     * @param array  $vars       Template variables.
     * @param string $qr_file    Path to QR code file for attachment.
     * @return bool True if queued.
     */
    public static function queue( $to, $vars, $qr_file = '' ) {
        $queue = get_option( 'ec_email_queue', array() );

        $queue[] = array(
            'to'       => $to,
            'vars'     => $vars,
            'qr_file'  => $qr_file,
            'attempts' => 0,
            'queued_at' => time(),
        );

        update_option( 'ec_email_queue', $queue, false );

        // Schedule immediate processing if not already scheduled.
        if ( ! wp_next_scheduled( self::QUEUE_HOOK ) ) {
            wp_schedule_single_event( time(), self::QUEUE_HOOK );
        }

        return true;
    }

    /**
     * Process the email queue. Called by WP Cron.
     * Processes up to 50 emails per tick.
     */
    public static function process_queue() {
        $queue = get_option( 'ec_email_queue', array() );
        if ( empty( $queue ) ) {
            return;
        }

        $batch_size = 50;
        $processed  = 0;
        $remaining  = array();

        foreach ( $queue as $item ) {
            if ( $processed >= $batch_size ) {
                $remaining[] = $item;
                continue;
            }

            $success = self::send( $item['to'], $item['vars'], $item['qr_file'] );

            if ( ! $success ) {
                $item['attempts']++;
                // Retry up to 3 times.
                if ( $item['attempts'] < 3 ) {
                    $remaining[] = $item;
                }
                // After 3 failures, drop the email (logged by wp_mail).
            }

            $processed++;
        }

        if ( ! empty( $remaining ) ) {
            update_option( 'ec_email_queue', $remaining, false );
            // Schedule next batch in 30 seconds.
            wp_schedule_single_event( time() + 30, self::QUEUE_HOOK );
        } else {
            delete_option( 'ec_email_queue' );
        }
    }

    /**
     * Send a single email immediately.
     *
     * @param string $to       Recipient email.
     * @param array  $vars     Template variables.
     * @param string $qr_file  Path to QR code file.
     * @return bool
     */
    public static function send( $to, $vars, $qr_file = '' ) {
        $subject_template = self::get_subject_template();
        $body_template    = self::get_template();

        $subject = self::render( $subject_template, $vars );
        $body    = self::render( $body_template, $vars );

        $headers = array(
            'Content-Type: text/html; charset=UTF-8',
        );

        $attachments = array();
        if ( $qr_file && file_exists( $qr_file ) ) {
            $attachments[] = $qr_file;
        }

        return wp_mail( $to, $subject, $body, $headers, $attachments );
    }

    /**
     * Initialize email hooks.
     */
    public static function init() {
        add_action( self::QUEUE_HOOK, array( __CLASS__, 'process_queue' ) );

        // Override from name/address if configured.
        add_filter( 'wp_mail_from', array( __CLASS__, 'filter_from_address' ) );
        add_filter( 'wp_mail_from_name', array( __CLASS__, 'filter_from_name' ) );
    }

    /**
     * Filter the from email address.
     *
     * @param string $from Default from address.
     * @return string
     */
    public static function filter_from_address( $from ) {
        $custom = Settings::get( 'email_from_address' );
        return ! empty( $custom ) ? sanitize_email( $custom ) : $from;
    }

    /**
     * Filter the from name.
     *
     * @param string $name Default from name.
     * @return string
     */
    public static function filter_from_name( $name ) {
        $custom = Settings::get( 'email_from_name' );
        return ! empty( $custom ) ? sanitize_text_field( $custom ) : $name;
    }
}
