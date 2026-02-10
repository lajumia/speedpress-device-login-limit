<?php
/**
 * Plugin Name: WP Device Login Limit
 * Plugin URI: https://wordpress.org/plugins/wp-device-login-limit/
 * Description: Restrict users to a fixed number of devices. New devices require OTP verification. Admins can reset devices. WordPress.org compliant.
 * Version: 1.0.0
 * Author: WP Device Login Limit
 * License: GPLv2 or later
 * Text Domain: wp-device-login-limit
 */

defined( 'ABSPATH' ) || exit;

/**
 * GLOBAL CONSTANTS
 */
define( 'WPDLL_DEVICE_LIMIT', 'wpdll_device_limit' );
define( 'WPDLL_ALLOWED_DEVICES', 'wpdll_allowed_devices' );
define( 'WPDLL_DEVICE_OTP', 'wpdll_device_otp' );
define( 'WPDLL_OTP_PAGE_ID', 'wpdll_otp_page_id' );

final class WP_Device_Login_Limit {

    public function __construct() {

        register_activation_hook( __FILE__, [ $this, 'wpddl_create_otp_page' ] );

        add_action( 'plugins_loaded', [ $this, 'wpddl_load_textdomain' ] );
        add_action( 'admin_init', [ $this, 'wpddl_register_settings' ] );
        add_action( 'admin_menu', [ $this, 'wpddl_admin_menu' ] );

        add_filter( 'authenticate', [ $this, 'enforce_device_limit' ], 30, 3 );

        add_shortcode( 'wpdll_otp_form', [ $this, 'otp_shortcode' ] );
        add_shortcode( 'wpdll_my_devices', [ $this, 'frontend_devices' ] );

        add_action( 'show_user_profile', [ $this, 'user_profile_devices' ] );
        add_action( 'edit_user_profile', [ $this, 'user_profile_devices' ] );
        add_action( 'personal_options_update', [ $this, 'save_user_profile' ] );
        add_action( 'edit_user_profile_update', [ $this, 'save_user_profile' ] );
    }

    /* -------------------------
     * ACTIVATION
     * ------------------------- */
    public function wpddl_create_otp_page() {

        if ( get_option( WPDLL_OTP_PAGE_ID ) ) {
            return;
        }

        $page_id = wp_insert_post([
            'post_title'   => 'WPDLL Verify Device',
            'post_name'    => 'wpdll-verify-device',
            'post_content' => '[wpdll_otp_form]',
            'post_status'  => 'publish',
            'post_type'    => 'page'
        ]);

        if ( ! is_wp_error( $page_id ) ) {
            update_option( WPDLL_OTP_PAGE_ID, $page_id );
        }
    }

    /**
     * Load Textdomain
     */
    public function wpddl_load_textdomain() {
        load_plugin_textdomain( 'wp-device-login-limit', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    /* -------------------------
     * ADMIN SETTINGS
     * ------------------------- */
    public function wpddl_register_settings() {

        register_setting( 'wpdll', WPDLL_DEVICE_LIMIT, [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 3,
        ] );
    }

    public function wpddl_admin_menu() {

        add_options_page(
            'Device Login Limit',
            'Device Login Limit',
            'manage_options',
            'wpdll',
            [ $this, 'wpddl_settings_page' ]
        );
    }

    public function wpddl_settings_page() { ?>
        <div class="wrap">
            <h1>WP Device Login Limit</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'wpdll' ); ?>
                <input type="number" name="<?php echo esc_attr( WPDLL_DEVICE_LIMIT ); ?>" value="<?php echo esc_attr( get_option( WPDLL_DEVICE_LIMIT ) ); ?>">
                <?php submit_button(); ?>
            </form>
        </div>
    <?php }

    /* -------------------------
     * LOGIN ENFORCEMENT
     * ------------------------- */
    public function enforce_device_limit( $user, $username, $password ) {

        if ( ! $user instanceof WP_User ) {
            return $user;
        }

        $limit   = (int) get_option( WPDLL_DEVICE_LIMIT, 3 );
        $device  = $this->get_device_id();
        $devices = get_user_meta( $user->ID, WPDLL_ALLOWED_DEVICES, true );

        if ( ! is_array( $devices ) ) {
            $devices = [];
        }

        // Known device
        if ( in_array( $device, wp_list_pluck( $devices, 'id' ), true ) ) {
            return $user;
        }

        // New device allowed → OTP
        if ( count( $devices ) < $limit ) {

            $otp = wp_rand( 100000, 999999 );

            update_user_meta( $user->ID, WPDLL_DEVICE_OTP, [
                'otp'    => $otp,
                'device' => $device,
                'agent'  => sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ),
                'time'   => time(),
            ] );

            // wp_mail(
            //     $user->user_email,
            //     __( 'Verify New Device', 'wp-device-login-limit' ),
            //     sprintf( __( 'Your verification code is: %s', 'wp-device-login-limit' ), $otp )
            // );
            if ( wp_mail( 
                $user->user_email,
                __('Verify New Device', 'wp-device-login-limit'),
                sprintf(__('Your verification code is: %s', 'wp-device-login-limit'), $otp)
             ) ) {
                error_log('OTP email sent successfully');
            } else {
                error_log('OTP email failed to send');
            }


            $page_id = get_option( WPDLL_OTP_PAGE_ID );
            if ( $page_id ) {
                wp_safe_redirect(
                    add_query_arg(
                        'log',
                        urlencode( $username ),
                        get_permalink( $page_id )
                    )
                );
                exit;
            }
        }

        return new WP_Error(
            'wpdll_limit',
            __( 'Device limit reached. Contact administrator.', 'wp-device-login-limit' )
        );
    }

    /* -------------------------
     * OTP PAGE SHORTCODE
     * ------------------------- */
    public function otp_shortcode() {

        if ( ! isset( $_GET['log'] ) ) {
            return '';
        }

        $error = '';

        if ( isset( $_POST['wpdll_verify_otp'] ) ) {

            check_admin_referer( 'wpdll_verify_otp' );

            $username = sanitize_user( $_POST['log'] );
            $otp      = absint( $_POST['wpdll_otp'] );
            $user     = get_user_by( 'login', $username );

            if ( $user ) {

                $data = get_user_meta( $user->ID, WPDLL_DEVICE_OTP, true );

                if (
                    $data &&
                    $otp === (int) $data['otp'] &&
                    time() - (int) $data['time'] <= 300
                ) {

                    $devices = get_user_meta( $user->ID, WPDLL_ALLOWED_DEVICES, true );
                    if ( ! is_array( $devices ) ) {
                        $devices = [];
                    }

                    $devices[] = [
                        'id'    => $data['device'],
                        'agent' => $data['agent'],
                        'time'  => time(),
                    ];

                    update_user_meta( $user->ID, WPDLL_ALLOWED_DEVICES, $devices );
                    delete_user_meta( $user->ID, WPDLL_DEVICE_OTP );

                    wp_set_current_user( $user->ID );
                    wp_set_auth_cookie( $user->ID, true );

                    wp_safe_redirect( admin_url() );
                    exit;
                }

                $error = __( 'Invalid or expired code.', 'wp-device-login-limit' );
            }
        }

        ob_start(); ?>
        <form method="post" style="max-width:360px;margin:10vh auto;padding:30px;background:#fff;border-radius:10px;text-align:center">
            <h2><?php esc_html_e( 'Verify Device', 'wp-device-login-limit' ); ?></h2>
            <?php if ( $error ) : ?><p style="color:red"><?php echo esc_html( $error ); ?></p><?php endif; ?>
            <?php wp_nonce_field( 'wpdll_verify_otp' ); ?>
            <input type="hidden" name="log" value="<?php echo esc_attr( $_GET['log'] ); ?>">
            <input type="number" name="wpdll_otp" required placeholder="123456" style="width:100%;padding:12px">
            <button name="wpdll_verify_otp" style="margin-top:15px;width:100%">Verify</button>
        </form>
        <?php
        return ob_get_clean();
    }

    /* -------------------------
     * DEVICE IDENTIFIER
     * ------------------------- */
    private function get_device_id() {

        if ( isset( $_COOKIE['wpdll_device_id'] ) ) {
            return sanitize_text_field( $_COOKIE['wpdll_device_id'] );
        }

        $id = hash( 'sha256', wp_generate_uuid4() . $_SERVER['HTTP_USER_AGENT'] );

        setcookie(
            'wpdll_device_id',
            $id,
            time() + YEAR_IN_SECONDS,
            COOKIEPATH,
            COOKIE_DOMAIN,
            is_ssl(),
            true
        );

        return $id;
    }

    /* -------------------------
     * USER PROFILE
     * ------------------------- */
    public function user_profile_devices( $user ) {

        if ( ! current_user_can( 'manage_options' ) ) return;

        $devices = get_user_meta( $user->ID, WPDLL_ALLOWED_DEVICES, true );
        if ( ! is_array( $devices ) ) return;

        echo '<h3>Registered Devices</h3><ul>';
        foreach ( $devices as $d ) {
            echo '<li>' . esc_html( $d['agent'] ) . '</li>';
        }
        echo '</ul><label><input type="checkbox" name="wpdll_reset"> Reset devices</label>';
    }

    public function save_user_profile( $user_id ) {

        if ( current_user_can( 'manage_options' ) && isset( $_POST['wpdll_reset'] ) ) {
            delete_user_meta( $user_id, WPDLL_ALLOWED_DEVICES );
            delete_user_meta( $user_id, WPDLL_DEVICE_OTP );
        }
    }

    /* -------------------------
     * FRONTEND DEVICES
     * ------------------------- */
    public function frontend_devices() {

        if ( ! is_user_logged_in() ) return '';

        $devices = get_user_meta( get_current_user_id(), WPDLL_ALLOWED_DEVICES, true );
        if ( ! is_array( $devices ) ) return '';

        $out = '<ul>';
        foreach ( $devices as $d ) {
            $out .= '<li>' . esc_html( $d['agent'] ) . '</li>';
        }
        $out .= '</ul>';

        return $out;
    }
}

new WP_Device_Login_Limit();
