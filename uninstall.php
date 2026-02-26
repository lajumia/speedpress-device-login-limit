<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

delete_option( 'spdll_device_limit' );

$spdll_users = get_users();
foreach ( $spdll_users as $spdll_user ) {
    delete_user_meta( $spdll_user->ID, 'spdll_allowed_devices' );
    delete_user_meta( $spdll_user->ID, 'spdll_device_otp' );
}