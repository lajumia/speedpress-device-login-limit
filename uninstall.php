<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

delete_option( 'wpdll_device_limit' );

$wpdll_users = get_users();
foreach ( $wpdll_users as $wpdll_user ) {
    delete_user_meta( $wpdll_user->ID, 'wpdll_allowed_devices' );
    delete_user_meta( $wpdll_user->ID, 'wpdll_device_otp' );
}