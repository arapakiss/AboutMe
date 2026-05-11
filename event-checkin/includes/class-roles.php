<?php
namespace EventCheckin;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages custom roles and capabilities for the plugin.
 */
class Roles {

    const STAFF_ROLE = 'ec_staff';

    public static function init() {
        // Nothing to hook at runtime; roles are created on activation.
    }

    /**
     * Create the staff role with appropriate capabilities.
     */
    public static function create_roles() {
        // Remove first in case caps changed.
        remove_role( self::STAFF_ROLE );

        add_role( self::STAFF_ROLE, __( 'Event Staff', 'event-checkin' ), array(
            'read'              => true,
            'ec_view_events'    => true,
            'ec_manage_checkin' => true,
            'ec_view_registrations' => true,
        ) );

        // Grant admin all EC capabilities.
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $admin->add_cap( 'ec_view_events' );
            $admin->add_cap( 'ec_manage_events' );
            $admin->add_cap( 'ec_manage_checkin' );
            $admin->add_cap( 'ec_view_registrations' );
            $admin->add_cap( 'ec_export_data' );
            $admin->add_cap( 'ec_manage_settings' );
        }
    }

    /**
     * Remove custom roles and capabilities on uninstall.
     */
    public static function remove_roles() {
        remove_role( self::STAFF_ROLE );

        $admin = get_role( 'administrator' );
        if ( $admin ) {
            $caps = array(
                'ec_view_events',
                'ec_manage_events',
                'ec_manage_checkin',
                'ec_view_registrations',
                'ec_export_data',
                'ec_manage_settings',
            );
            foreach ( $caps as $cap ) {
                $admin->remove_cap( $cap );
            }
        }
    }

    /**
     * Check if the current user has a specific EC capability.
     *
     * @param string $cap Capability name.
     * @return bool
     */
    public static function current_user_can( $cap ) {
        return current_user_can( $cap );
    }
}
