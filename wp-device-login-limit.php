<?php
/**
 * Plugin Name: WP Device Login Limit
 * Plugin URI: https://wordpress.org/plugins/wp-device-login-limit/
 * Description: Restrict users to a fixed number of registered devices. Includes hard device whitelist, OTP verification for new devices, admin reset controls, frontend device list, and full WordPress.org compliance.
 * Version: 1.0.0
 * Author: WP Device Login Limit
 * Author URI: https://wordpress.org
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-device-login-limit
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

final class WP_Device_Login_Limit {

    const OPTION_LIMIT = 'wpdll_device_limit';
    const META_DEVICES = 'wpdll_allowed_devices';
    const META_OTP     = 'wpdll_device_otp';

    public function __construct() {
        add_action( 'plugins_loaded', [ $this, 'wpdll_load_textdomain' ] );
        add_action( 'admin_init', [ $this, 'wpdll_register_settings' ] );
        add_action( 'admin_menu', [ $this, 'wpdll_admin_menu' ] );
        add_filter( 'authenticate', [ $this, 'wpdll_enforce_device_limit' ], 30, 3 );
        add_action( 'login_form_wpdll_otp', [ $this, 'wpdll_render_otp_page' ] );
        add_action( 'show_user_profile', [ $this, 'wpdll_user_profile_ui' ] );
        add_action( 'edit_user_profile', [ $this, 'wpdll_user_profile_ui' ] );
        add_action( 'personal_options_update', [ $this, 'wpdll_save_user_profile' ] );
        add_action( 'edit_user_profile_update', [ $this, 'wpdll_save_user_profile' ] );
        add_shortcode( 'wpdll_my_devices', [ $this, 'wpdll_frontend_device_list' ] );
    }

    /** i18n */
    public function wpdll_load_textdomain() {
        load_plugin_textdomain( 'wp-device-login-limit', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    /** SETTINGS */
    public function wpdll_register_settings() {
        register_setting( 'wpdll_settings', self::OPTION_LIMIT, [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 3,
        ] );

        if ( get_option( self::OPTION_LIMIT ) === false ) {
            add_option( self::OPTION_LIMIT, 3 );
        }
    }

    public function wpdll_admin_menu() {
        add_options_page(
            __( 'Device Login Limit', 'wp-device-login-limit' ),
            __( 'Device Login Limit', 'wp-device-login-limit' ),
            'manage_options',
            'wpdll-settings',
            [ $this, 'wpdll_settings_page' ]
        );
    }

    public function wpdll_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'WP Device Login Limit', 'wp-device-login-limit' ); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'wpdll_settings' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Maximum Devices Per User', 'wp-device-login-limit' ); ?></th>
                        <td>
                            <input type="number" min="1" name="<?php echo esc_attr( self::OPTION_LIMIT ); ?>" value="<?php echo esc_attr( get_option( self::OPTION_LIMIT ) ); ?>" />
                            <p class="description"><?php esc_html_e( 'This limit applies to ALL users, including administrators.', 'wp-device-login-limit' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    /** DEVICE IDENTIFIER */
    private function wpdll_get_device_id() {
        if ( isset( $_COOKIE['wpdll_device_id'] ) ) {
            return sanitize_text_field( $_COOKIE['wpdll_device_id'] );
        }

        $raw = $_SERVER['HTTP_USER_AGENT'] . wp_generate_uuid4();
        $device_id = hash( 'sha256', $raw );

        setcookie(
            'wpdll_device_id',
            $device_id,
            time() + YEAR_IN_SECONDS,
            COOKIEPATH,
            COOKIE_DOMAIN,
            is_ssl(),
            true
        );

        return $device_id;
    }

    /** LOGIN ENFORCEMENT */
    public function wpdll_enforce_device_limit( $user, $username, $password ) {
        if ( ! $user instanceof WP_User ) {
            return $user;
        }

        $limit     = (int) get_option( self::OPTION_LIMIT, 3 );
        $device_id = $this->wpdll_get_device_id();
        $devices   = get_user_meta( $user->ID, self::META_DEVICES, true );

        if ( ! is_array( $devices ) ) {
            $devices = [];
        }

        // Known device
        if ( in_array( $device_id, wp_list_pluck( $devices, 'id' ), true ) ) {
            return $user;
        }

        // New device allowed
        if ( count( $devices ) < $limit ) {
            $otp = wp_rand( 100000, 999999 );

            update_user_meta( $user->ID, self::META_OTP, [
                'otp'    => $otp,
                'device' => $device_id,
                'agent'  => sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ),
                'time'   => time(),
            ] );

            wp_mail(
                $user->user_email,
                __( 'New Device Verification', 'wp-device-login-limit' ),
                sprintf( __( 'Your verification code is: %s', 'wp-device-login-limit' ), $otp )
            );

            wp_safe_redirect( wp_login_url() . '?action=wpdll_otp&log=' . rawurlencode( $username ) );
            exit;
        }

        return new WP_Error(
            'wpdll_limit_reached',
            __( 'Device limit reached. Please contact the site administrator.', 'wp-device-login-limit' )
        );
    }

    /** OTP PAGE */
    public function wpdll_render_otp_page() {
        if ( isset( $_POST['wpdll_verify_otp'] ) ) {
            $username = sanitize_user( $_POST['log'] );
            $otp      = absint( $_POST['wpdll_otp'] );
            $user     = get_user_by( 'login', $username );

            if ( $user ) {
                $data = get_user_meta( $user->ID, self::META_OTP, true );
                if ( $data && $otp === (int) $data['otp'] ) {
                    $devices   = get_user_meta( $user->ID, self::META_DEVICES, true );
                    if ( ! is_array( $devices ) ) $devices = [];

                    $devices[] = [
                        'id'    => $data['device'],
                        'agent' => $data['agent'],
                        'time'  => time(),
                    ];

                    update_user_meta( $user->ID, self::META_DEVICES, $devices );
                    delete_user_meta( $user->ID, self::META_OTP );

                    wp_set_current_user( $user->ID );
                    wp_set_auth_cookie( $user->ID );
                    wp_safe_redirect( admin_url() );
                    exit;
                }
            }

            echo '<p>' . esc_html__( 'Invalid verification code.', 'wp-device-login-limit' ) . '</p>';
        }
        ?>
        <form method="post" class="wpdll-otp-form">
            <h2><?php esc_html_e( 'Verify New Device', 'wp-device-login-limit' ); ?></h2>
            <input type="hidden" name="log" value="<?php echo esc_attr( $_GET['log'] ?? '' ); ?>">
            <p><input type="number" name="wpdll_otp" placeholder="123456" required></p>
            <p><button type="submit" name="wpdll_verify_otp" class="button button-primary"><?php esc_html_e( 'Verify', 'wp-device-login-limit' ); ?></button></p>
        </form>
        <?php
    }

    /** USER PROFILE (ADMIN) */
    public function wpdll_user_profile_ui( $user ) {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $devices = get_user_meta( $user->ID, self::META_DEVICES, true );
        if ( ! is_array( $devices ) ) $devices = [];
        ?>
        <h3><?php esc_html_e( 'Registered Devices', 'wp-device-login-limit' ); ?></h3>
        <ul>
            <?php foreach ( $devices as $device ) : ?>
                <li><?php echo esc_html( $device['agent'] ); ?> — <?php echo esc_html( date_i18n( 'Y-m-d H:i', $device['time'] ) ); ?></li>
            <?php endforeach; ?>
        </ul>
        <label><input type="checkbox" name="wpdll_reset_devices"> <?php esc_html_e( 'Reset all devices for this user', 'wp-device-login-limit' ); ?></label>
        <?php
    }

    public function wpdll_save_user_profile( $user_id ) {
        if ( current_user_can( 'manage_options' ) && isset( $_POST['wpdll_reset_devices'] ) ) {
            delete_user_meta( $user_id, self::META_DEVICES );
            delete_user_meta( $user_id, self::META_OTP );
        }
    }

    /** FRONTEND SHORTCODE */
    public function wpdll_frontend_device_list() {
        if ( ! is_user_logged_in() ) return '';

        $devices = get_user_meta( get_current_user_id(), self::META_DEVICES, true );
        if ( ! is_array( $devices ) || empty( $devices ) ) {
            return esc_html__( 'No registered devices.', 'wp-device-login-limit' );
        }

        $out = '<ul class="wpdll-device-list">';
        foreach ( $devices as $device ) {
            $out .= '<li>' . esc_html( $device['agent'] ) . '</li>';
        }
        $out .= '</ul>';

        return $out;
    }
}

new WP_Device_Login_Limit();
