<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

delete_option( 'wpdll_device_limit' );

$users = get_users();
foreach ( $users as $user ) {
    delete_user_meta( $user->ID, 'wpdll_allowed_devices' );
    delete_user_meta( $user->ID, 'wpdll_device_otp' );
}
