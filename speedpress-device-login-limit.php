<?php
/**
 * Plugin Name: SpeedPress Device Login Limit
 * Plugin URI: https://wpspeedpress.com/speedpress-device-login-limit/
 * Description: Limit the number of devices a user can log in from and enhance account security by requiring OTP verification for any new device login.
 * Version: 1.0.0
 * Author: Md Laju Miah
 * Author URI: https://profiles.wordpress.org/devlaju/
 * Text Domain: speedpress-device-login-limit
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

    /* -------------------------
    * GLOBAL CONSTANTS
    * ------------------------- */
    define( 'SPDLL_DEVICE_LIMIT', 'spdll_device_limit' );
    define( 'SPDLL_ALLOWED_DEVICES', 'spdll_allowed_devices' );
    define( 'SPDLL_DEVICE_OTP', 'spdll_device_otp' );
    define( 'SPDLL_OTP_PAGE_SLUG', 'spdll-verify-device' );

final class SP_Device_Login_Limit {


    public function __construct() {

        register_activation_hook( __FILE__, [ $this, 'spdll_check_smtp_on_activation' ] );
        // Create OTP page on activation & init
        register_activation_hook( __FILE__, [ $this, 'spdll_create_otp_page' ] );
        add_action( 'init', [ $this, 'spdll_create_otp_page' ] );

        // Shortcodes
        add_shortcode( 'spdll_otp_form', [ $this, 'spdll_otp_shortcode' ] );
        add_shortcode( 'spdll_my_devices', [ $this, 'spdll_frontend_device_list' ] );

        // Admin settings
        add_action( 'admin_init', [ $this, 'spdll_register_settings' ] );
        add_action( 'admin_menu', [ $this, 'spdll_admin_menu' ] );

        // Login enforcement
        add_filter( 'authenticate', [ $this, 'spdll_enforce_device_limit' ], 30, 3 );

        // User profile devices
        add_action( 'show_user_profile', [ $this, 'spdll_user_profile_devices' ] );
        add_action( 'edit_user_profile', [ $this, 'spdll_user_profile_devices' ] );
        add_action( 'personal_options_update', [ $this, 'spdll_save_user_profile' ] );
        add_action( 'edit_user_profile_update', [ $this, 'spdll_save_user_profile' ] );
    }

    public function spdll_check_smtp_on_activation() {

        // Check WP Mail SMTP
        if ( ! function_exists( 'wp_mail_smtp' ) ) {

            deactivate_plugins( plugin_basename( __FILE__ ) );

           wp_die(
                '
                <div style="
                    max-width:700px;
                    margin:50px auto;
                    font-family:Arial, sans-serif;
                    border:1px solid #eee;
                    border-radius:12px;
                    padding:30px;
                    box-shadow:0 10px 25px rgba(0,0,0,0.1);
                    background:linear-gradient(135deg,#fff,#f9f9f9);
                    text-align:left;
                    animation: fadeIn 1s ease;
                ">
                    <style>
                        @keyframes fadeIn {
                            0% {opacity:0; transform:translateY(-20px);}
                            100% {opacity:1; transform:translateY(0);}
                        }
                        .spdll-highlight { color:#d63638; font-weight:bold; }
                        .spdll-btn {
                            display:inline-block;
                            margin-top:15px;
                            padding:10px 20px;
                            background:#2271b1;
                            color:#fff;
                            font-weight:bold;
                            text-decoration:none;
                            border-radius:6px;
                            transition:all 0.3s ease;
                        }
                        .spdll-btn:hover { background:#1a5d91; transform:translateY(-2px);color:white!important;}
                        ul, ol { margin-left:20px; }
                    </style>

                    <h1 style="color:#d63638;margin-bottom:15px; font-size:28px;">
                        ⚠ Plugin Activation Blocked
                    </h1>

                    <p>
                        <span class="spdll-highlight">WP Device Login Limit</span> requires a working email system to send
                        One-Time Passwords (OTP) for device verification. Without it, new devices cannot be verified,
                        and users may get locked out.
                    </p>

                    <h3 style="margin-top:25px;">Why is SMTP needed?</h3>
                    <ul>
                        <li>Email OTP verification ensures secure logins.</li>
                        <li>WordPress default emails often fail or go to spam.</li>
                        <li>Prevents accidental admin lockouts.</li>
                    </ul>

                    <h3 style="margin-top:25px;">How to fix it</h3>
                    <ol>
                        <li>Install and activate an WP Mail SMTP plugin.</li>
                        <li>Connect your email service (Gmail, Outlook, Zoho, etc.).</li>
                        <li>Test email sending from the SMTP plugin settings.</li>
                        <li>Return to activate <span class="spdll-highlight">WP Device Login Limit</span>.</li>
                    </ol>

                    <a href="https://wordpress.org/plugins/wp-mail-smtp/" target="_blank" rel="noopener noreferrer" class="spdll-btn">
                        Install WP Mail SMTP
                    </a>

                    <p style="margin-top:25px;color:#666;font-size:13px;">
                        This requirement protects your site and ensures OTP emails are delivered reliably.
                    </p>
                </div>
                ',
                __( 'Missing SMTP Dependency', 'speedpress-device-login-limit' ),
                [
                    'back_link' => true,
                ]
            );


        }
    }

    /* -------------------------
     * OTP PAGE CREATION
     * ------------------------- */
    public function spdll_create_otp_page() {
        $slug = SPDLL_OTP_PAGE_SLUG;
        $page = get_page_by_path( $slug );

        if ( $page && $page->post_status === 'publish' ) return;

        wp_insert_post([
            'post_title'   => __( 'Verify Device', 'speedpress-device-login-limit' ),
            'post_name'    => $slug,
            'post_content' => '[spdll_otp_form]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ]);
    }

    /* -------------------------
     * ADMIN SETTINGS
     * ------------------------- */
    public function spdll_register_settings() {

        register_setting( 'spdll', SPDLL_DEVICE_LIMIT, [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 3,
        ] );
    }

    public function spdll_admin_menu() {

        add_options_page(
            'Device Login Limit',
            'Device Login Limit',
            'manage_options',
            'spdll',
            [ $this, 'spdll_settings_page' ]
        );
    }

    public function spdll_settings_page() { ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">WP Device Login Limit</h1>
            <p class="description">
                Control how many devices a user can log in from at the same time.
            </p>

            <hr class="wp-header-end">

            <form method="post" action="options.php">
                <?php settings_fields( 'spdll' ); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="spdll_device_limit">
                                    Maximum Devices Per User
                                </label>
                            </th>
                            <td>
                                <input
                                    type="number"
                                    id="spdll_device_limit"
                                    name="<?php echo esc_attr( SPDLL_DEVICE_LIMIT ); ?>"
                                    value="<?php echo esc_attr( get_option( SPDLL_DEVICE_LIMIT, 3 ) ); ?>"
                                    class="small-text"
                                    min="1"
                                />

                                <p class="description">
                                    Set how many devices a user can register.
                                    This applies to <strong>all users</strong>, including administrators.
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <?php submit_button( 'Save Settings' ); ?>
            </form>
        </div>
    <?php }


    /* -------------------------
    * LOGIN ENFORCEMENT WITH OTP STATUS AND EXPIRY
    * ------------------------- */
    public function spdll_enforce_device_limit( $user, $username, $password ) {

        if ( ! $user instanceof WP_User ) {
            return $user;
        }

        $limit   = (int) get_option( SPDLL_DEVICE_LIMIT, 3 );
        $device  = $this->spdll_get_device_id();
        $devices = get_user_meta( $user->ID, SPDLL_ALLOWED_DEVICES, true );

        if ( ! is_array( $devices ) ) {
            $devices = [];
        }

        // Known device → allow login immediately
        if ( in_array( $device, wp_list_pluck( $devices, 'id' ), true ) ) {
            return $user;
        }

        // Check for pending OTP
        $otp_data = get_user_meta( $user->ID, SPDLL_DEVICE_OTP, true );

        $otp_pending = false;
        $now = time();
        $otp_expiry = 10 * MINUTE_IN_SECONDS; // 10 minutes expiry

        if ( $otp_data && isset( $otp_data['status'] ) && $otp_data['status'] === 'pending' ) {
            if ( $now - $otp_data['time'] <= $otp_expiry ) {
                // Still valid → redirect to OTP page
                $otp_pending = true;
            } else {
                // Expired → remove OTP
                delete_user_meta( $user->ID, SPDLL_DEVICE_OTP );
            }
        }

        if ( $otp_pending ) {
            $page = get_page_by_path( SPDLL_OTP_PAGE_SLUG );
            if ( $page ) {
                wp_safe_redirect(
                    add_query_arg(
                        'log',
                        urlencode( $username ),
                        get_permalink( $page->ID )
                    )
                );
                exit;
            }
        }

        // If not pending → new OTP if under limit
        if ( count( $devices ) < $limit ) {

            $otp = wp_rand( 100000, 999999 );

            // Save OTP with pending status and timestamp
            update_user_meta( $user->ID, SPDLL_DEVICE_OTP, [
                'otp'     => $otp,
                'device'  => $device,
                'agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown',
                'time'    => $now,
                'status'  => 'pending',
            ] );

            $sent_email = wp_mail(
    $user->user_email,
    __( 'Verify New Device', 'speedpress-device-login-limit' ),
    // Translators: %s is the OTP verification code sent to the user
    sprintf( __( 'Your verification code is: %s', 'speedpress-device-login-limit' ), $otp )
);

if ( ! $sent_email ) {
    error_log( 'WP Device Login Limit: OTP email failed to send.' );

    // Optional: Debug headers
    $to      = $user->user_email;
    $subject = 'Test';
    $message = 'Test email';
    $headers = [];
    $test    = wp_mail( $to, $subject, $message, $headers );
    error_log( 'Test wp_mail returned: ' . var_export( $test, true ) );

    delete_user_meta( $user->ID, SPDLL_DEVICE_OTP );
    return new WP_Error(
        'spdll_email_failed',
        __( 'Failed to send OTP email. Please configure email settings or contact support.', 'speedpress-device-login-limit' )
    );
}

// Email sent → redirect
$page = get_page_by_path( SPDLL_OTP_PAGE_SLUG );
if ( $page ) {
    wp_safe_redirect(
        add_query_arg(
            'log',
            urlencode( $username ),
            get_permalink( $page->ID )
        )
    );
    exit;
}

        }

        // Device limit reached
        return new WP_Error(
            'spdll_limit',
            __( 'Device limit reached. Contact administrator.', 'speedpress-device-login-limit' )
        );
    }


    /* -------------------------
    * OTP PAGE SHORTCODE
    * ------------------------- */
    public function spdll_otp_shortcode() {

        // Check if username is in the query string
        if ( ! isset( $_GET['log'] ) ) {
            return '';
        }

        $username = isset( $_GET['log'] ) ? sanitize_user( wp_unslash( $_GET['log'] ) ) : '';

        $user     = get_user_by( 'login', $username );

        if ( ! $user ) {
            return ''; // User does not exist
        }

        // Get existing device cookie (do not generate a new one)
       $device_id = isset( $_COOKIE['spdll_device_id'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['spdll_device_id'] ) ) : false;

        // Get allowed devices for this user
        $devices = get_user_meta( $user->ID, SPDLL_ALLOWED_DEVICES, true );
        if ( ! is_array( $devices ) ) {
            $devices = [];
        }

        // If user is logged in AND device is already approved, redirect to admin
        if ( is_user_logged_in() && $device_id ) {
            foreach ( $devices as $d ) {
                if ( isset($d['id'], $d['status']) && $d['id'] === $device_id && $d['status'] === 'approved' ) {
                    wp_safe_redirect( admin_url() );
                    exit;
                }
            }
        }

        $error = '';

        // Handle OTP submission
        if ( isset( $_POST['spdll_verify_otp'] ) ) {

            check_admin_referer( 'spdll_verify_otp' );

            $otp = isset( $_POST['spdll_otp'] ) ? absint( wp_unslash( $_POST['spdll_otp'] ) ) : 0;

            $data = get_user_meta( $user->ID, SPDLL_DEVICE_OTP, true );

            if (
                $data &&
                $otp === (int) $data['otp'] &&
                time() - (int) $data['time'] <= 10 * MINUTE_IN_SECONDS // 10 min expiry
            ) {
                // Add device to allowed list with status 'approved'
                $devices[] = [
                    'id'     => $data['device'],
                    'agent'  => $data['agent'],
                    'time'   => time(),
                    'status' => 'approved',
                ];

                update_user_meta( $user->ID, SPDLL_ALLOWED_DEVICES, $devices );
                delete_user_meta( $user->ID, SPDLL_DEVICE_OTP );

                // Log the user in
                wp_set_current_user( $user->ID );
                wp_set_auth_cookie( $user->ID, true );

                wp_safe_redirect( admin_url() );
                exit;
            }

            $error = __( 'Invalid or expired code.', 'speedpress-device-login-limit' );
        }

        // Render OTP form
        ob_start(); ?>
        <div class="spdll-otp-wrapper" style="display:flex;justify-content:center;align-items:center;height:80vh;">
            <form method="post" class="spdll-otp-card" style="max-width:400px;width:100%;padding:30px;background:#fff;border-radius:10px;box-shadow:0 5px 20px rgba(0,0,0,0.1);text-align:center;">
                <h2 style="margin-bottom:10px;"><?php esc_html_e( 'Verify Device', 'speedpress-device-login-limit' ); ?></h2>
                <p style="margin-bottom:20px;"><?php esc_html_e('A verification code has been sent to your email address.', 'speedpress-device-login-limit');?></p>
                
                <?php if ( $error ) : ?>
                    <p class="spdll-error" style="color:#d63638;margin-bottom:15px;"><?php echo esc_html( $error ); ?></p>
                <?php endif; ?>

                <?php wp_nonce_field( 'spdll_verify_otp' ); ?>
                <input type="hidden" name="log" value="<?php echo esc_attr( $username ); ?>">

                <input type="number" name="spdll_otp" required placeholder="123456" style="width:100%;padding:12px;margin-bottom:15px;border-radius:6px;border:1px solid #ccc;text-align:center;">

                <button name="spdll_verify_otp" style="width:100%;padding:12px;border:none;border-radius:6px;background:#2271b1;color:#fff;font-weight:bold;cursor:pointer;">
                    <?php esc_html_e( 'Verify & Continue', 'speedpress-device-login-limit' ); ?>
                </button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }


    /* -------------------------
     * DEVICE IDENTIFIER
     * ------------------------- */
    private function spdll_get_device_id() {

        if ( isset( $_COOKIE['spdll_device_id'] ) ) {
            return sanitize_text_field( wp_unslash( $_COOKIE['spdll_device_id'] ) );
        }

        // Get the user agent safely
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : 'unknown';
        $user_agent = sanitize_text_field( $user_agent );

        // Generate device ID using UUID + safe user agent
        $id = hash( 'sha256', wp_generate_uuid4() . $user_agent );


        setcookie(
            'spdll_device_id',
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
    * USER PROFILE – REGISTERED DEVICES
    * ------------------------- */
    public function spdll_user_profile_devices( $user ) {

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $devices = get_user_meta( $user->ID, SPDLL_ALLOWED_DEVICES, true );
        if ( ! is_array( $devices ) || empty( $devices ) ) {
            return;
        }
        ?>
        <h2><?php esc_html_e( 'Device Login Limit', 'speedpress-device-login-limit' ); ?></h2>

        <table class="form-table" role="presentation">
            <tr>
                <th>
                    <label><?php esc_html_e( 'Registered Devices', 'speedpress-device-login-limit' ); ?></label>
                </th>
                <td>
                    <ul class="spdll-device-list">
                        <?php foreach ( $devices as $device ) : ?>
                            <li>
                                <strong><?php echo esc_html( $device['agent'] ); ?></strong><br>
                                <span class="description">
                                    <?php
                                    if ( ! empty( $device['time'] ) ) {
                                        echo esc_html(
                                            date_i18n(
                                                get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
                                                (int) $device['time']
                                            )
                                        );
                                    }
                                    ?>
                                </span>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php wp_nonce_field( 'spdll_reset_devices_action', 'spdll_reset_devices_nonce' ); ?>
                    <label for="spdll_reset_devices">
                        <input type="checkbox" id="spdll_reset_devices" name="spdll_reset_devices" value="1">
                        <?php esc_html_e( 'Reset all registered devices for this user', 'speedpress-device-login-limit' ); ?>
                    </label>

                    <p class="description">
                        <?php esc_html_e(
                            'If checked, the user will be required to re-verify devices on next login.',
                            'speedpress-device-login-limit'
                        ); ?>
                    </p>
                </td>
            </tr>
        </table>
        <?php
    }


    public function spdll_save_user_profile( $user_id ) {

        // Only allow admins
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Verify nonce before processing form
        if ( isset( $_POST['spdll_reset_devices'] ) && isset( $_POST['spdll_reset_devices_nonce'] ) ) {

            if ( ! wp_verify_nonce( wp_unslash($_POST['spdll_reset_devices_nonce']), 'spdll_reset_devices_action' ) ) {
                return; // Nonce invalid, do nothing
            }

            // Safe to delete user meta
            delete_user_meta( $user_id, SPDLL_ALLOWED_DEVICES );
            delete_user_meta( $user_id, SPDLL_DEVICE_OTP );
        }
    }


    /* -------------------------
     * FRONTEND DEVICES
     * ------------------------- */
    public function spdll_frontend_device_list() {

        if ( ! is_user_logged_in() ) return '';

        $devices = get_user_meta( get_current_user_id(), SPDLL_ALLOWED_DEVICES, true );
        if ( ! is_array( $devices ) ) return '';

        $out = '<ul>';
        foreach ( $devices as $d ) {
            $out .= '<li>' . esc_html( $d['agent'] ) . '</li>';
        }
        $out .= '</ul>';

        return $out;
    }
}

new SP_Device_Login_Limit();
