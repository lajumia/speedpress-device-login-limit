<?php
/**
 * Plugin Name: WP Device Login Limit
 * Plugin URI: https://wordpress.org/plugins/wp-device-login-limit/
 * Description: Restrict users to a set number of devices and secure new device logins with OTP verification.
 * Version: 1.0.0
 * Author: Md Laju Miah
 * Author URI: https://profiles.wordpress.org/devlaju/
 * Text Domain: wp-device-login-limit
 * Domain Path:       /languages
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) exit;

    /* -------------------------
    * GLOBAL CONSTANTS
    * ------------------------- */
    define( 'WPDLL_DEVICE_LIMIT', 'wpdll_device_limit' );
    define( 'WPDLL_ALLOWED_DEVICES', 'wpdll_allowed_devices' );
    define( 'WPDLL_DEVICE_OTP', 'wpdll_device_otp' );
    define( 'WPDLL_OTP_PAGE_SLUG', 'wpdll-verify-device' );

final class WP_Device_Login_Limit {


    public function __construct() {
        // Load function on plugin registration
        register_activation_hook( __FILE__, [ $this, 'plugin_activation' ] );

        // Create OTP page on activation & init
        add_action( 'init', [ $this, 'wpdll_create_otp_page' ] );
        
        // Shortcodes
        add_shortcode( 'wpdll_otp_form', [ $this, 'wpdll_otp_shortcode' ] );
        add_shortcode( 'wpdll_my_devices', [ $this, 'wpdll_frontend_device_list' ] );

        // Admin settings
        add_action( 'admin_init', [ $this, 'wpdll_register_settings' ] );
        add_action( 'admin_menu', [ $this, 'wpdll_admin_menu' ] );

        // Login enforcement
        add_filter( 'authenticate', [ $this, 'wpdll_enforce_device_limit' ], 30, 3 );

        // User profile devices
        add_action( 'show_user_profile', [ $this, 'wpdll_user_profile_devices' ] );
        add_action( 'edit_user_profile', [ $this, 'wpdll_user_profile_devices' ] );
        add_action( 'personal_options_update', [ $this, 'wpdll_save_user_profile' ] );
        add_action( 'edit_user_profile_update', [ $this, 'wpdll_save_user_profile' ] );

        // Delete the device form admin
        add_action('wp_ajax_wpdll_delete_device', [ $this, 'wpdll_delete_device_callback' ]);
    }

    public function plugin_activation() {
        // 1. Check SMTP and block activation if missing
        $this->wpdll_check_smtp_on_activation();

        // 2. Send test email to confirm SMTP works
        //$this->wpdll_test_smtp_on_activation();

        // 3. Approve first device for current admin
        $this->wpdll_approve_first_device_on_activation();
    }

    /**
     * Deactivate plugin is smtp is not activated
     */
    public function wpdll_check_smtp_on_activation() {

        // Check WP Mail SMTP
        if ( ! function_exists( 'wp_mail_smtp' ) ) {

            deactivate_plugins( plugin_basename( __FILE__ ) );
            $title = __( 'Missing SMTP Dependency', 'wp-device-login-limit' );
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
                        .wpdll-highlight { color:#d63638; font-weight:bold; }
                        .wpdll-btn {
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
                        .wpdll-btn:hover { background:#1a5d91; transform:translateY(-2px);color:white!important;}
                        ul, ol { margin-left:20px; }
                    </style>

                    <h1 style="color:#d63638;margin-bottom:15px; font-size:28px;">
                        ⚠ Plugin Activation Blocked
                    </h1>

                    <p>
                        <span class="wpdll-highlight">WP Device Login Limit</span> requires a working email system to send
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
                        <li>Return to activate <span class="wpdll-highlight">WP Device Login Limit</span>.</li>
                    </ol>

                    <a href="https://wordpress.org/plugins/wp-mail-smtp/" target="_blank" rel="noopener noreferrer" class="wpdll-btn">
                        Install WP Mail SMTP
                    </a>

                    <p style="margin-top:25px;color:#666;font-size:13px;">
                        This requirement protects your site and ensures OTP emails are delivered reliably.
                    </p>
                </div>
                ',
                 esc_html( $title ),
                [
                    'back_link' => true,
                ]
            );


        }
    }

    /**
     * Send test email on plugin activation to ensure SMTP is working.
     */
    public function wpdll_test_smtp_on_activation() {

        // Get admin email
        $admin_email = get_option( 'admin_email' );

        // Compose test email
        $subject = __( 'WP Device Login Limit: Test Email', 'wp-device-login-limit' );
        $message = __( 'This is a test email to verify that your SMTP settings are working correctly.', 'wp-device-login-limit' );
        $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

        // Try sending the email
        $sent = wp_mail( $admin_email, $subject, $message, $headers );

        // If failed → deactivate plugin & show error
        if ( ! $sent ) {

            deactivate_plugins( plugin_basename( __FILE__ ) );
            $title = __( 'SMTP Configuration Required', 'wp-device-login-limit' );
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
                        .wpdll-highlight { color:#d63638; font-weight:bold; }
                        .wpdll-btn {
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
                        .wpdll-btn:hover { background:#1a5d91; transform:translateY(-2px);color:white!important;}
                    </style>

                    <h1 style="color:#d63638;margin-bottom:15px; font-size:28px;">
                        ⚠ SMTP Test Failed
                    </h1>

                    <p>
                        <span class="wpdll-highlight">WP Device Login Limit</span> could not send a test email to the admin email address.
                    </p>

                    <h3>How to fix it:</h3>
                    <ol>
                        <li>Install and activate an SMTP plugin (like <span class="wpdll-highlight">WP Mail SMTP</span>).</li>
                        <li>Configure your email service (Gmail, Outlook, Zoho, etc.).</li>
                        <li>Test email sending from the SMTP plugin settings.</li>
                        <li>Reactivate <span class="wpdll-highlight">WP Device Login Limit</span>.</li>
                    </ol>

                    <a href="https://wordpress.org/plugins/wp-mail-smtp/" target="_blank" rel="noopener noreferrer" class="wpdll-btn">
                        Install WP Mail SMTP
                    </a>

                    <p style="margin-top:25px;color:#666;font-size:13px;">
                        This ensures OTP emails are delivered reliably and prevents user lockouts.
                    </p>
                </div>
                ',
                esc_html( $title ),
                ['back_link' => true]
            );
        }
    }

    /**
     * Approve first device on activation
     */
    private function wpdll_approve_first_device_on_activation() {

        $user = wp_get_current_user();
        if ( ! $user || ! $user->ID ) {
            return;
        }

        // Get existing approved devices
        $devices = get_user_meta( $user->ID, WPDLL_ALLOWED_DEVICES, true );
        if ( ! is_array( $devices ) ) {
            $devices = [];
        }

        // Check if there’s already at least one approved device
        $approved_devices = array_filter( $devices, function( $d ) {
            return isset( $d['status'] ) && $d['status'] === 'approved';
        });

        if ( ! empty( $approved_devices ) ) {
            return; // User already has an approved device
        }

        // Generate device ID only once
        $device_id = $this->wpdll_get_device_id(); // This function checks cookie and generates ID if missing

        // Get user IP and Device
        $ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
        $device_type = wp_is_mobile() ? 'Mobile' : 'Desktop';

        // Save device as approved
        $devices[] = [
            'id'     => $device_id,
            'agent'  => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown',
            'time'   => time(),
            'ip_address'     => $ip_address,
            'device_type' => $device_type,
            'status' => 'approved',
        ];

        update_user_meta( $user->ID, WPDLL_ALLOWED_DEVICES, $devices );

        // Set cookie if not already set
        if ( empty( $_COOKIE['wpdll_device_id'] ) ) {
            setcookie(
                'wpdll_device_id',
                $device_id,
                time() + YEAR_IN_SECONDS,
                COOKIEPATH,
                COOKIE_DOMAIN,
                is_ssl(),
                true
            );
            $_COOKIE['wpdll_device_id'] = $device_id; // Also set it in PHP superglobal
        }
    }

    /* -------------------------
     * OTP PAGE CREATION
     * ------------------------- */
    public function wpdll_create_otp_page() {
        $slug = WPDLL_OTP_PAGE_SLUG;
        $page = get_page_by_path( $slug );

        if ( $page && $page->post_status === 'publish' ) return;

        wp_insert_post([
            'post_title'   => __( 'Verify Device', 'wp-device-login-limit' ),
            'post_name'    => $slug,
            'post_content' => '[wpdll_otp_form]',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ]);
    }

    /* -------------------------
    * OTP PAGE SHORTCODE
    * ------------------------- */
    public function wpdll_otp_shortcode() {

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
       $device_id = isset( $_COOKIE['wpdll_device_id'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['wpdll_device_id'] ) ) : false;

        // Get allowed devices for this user
        $devices = get_user_meta( $user->ID, WPDLL_ALLOWED_DEVICES, true );
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
        if ( isset( $_POST['wpdll_verify_otp'] ) ) {

            check_admin_referer( 'wpdll_verify_otp' );

            $otp = isset( $_POST['wpdll_otp'] ) ? absint( wp_unslash( $_POST['wpdll_otp'] ) ) : 0;

            $data = get_user_meta( $user->ID, WPDLL_DEVICE_OTP, true );

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

                update_user_meta( $user->ID, WPDLL_ALLOWED_DEVICES, $devices );
                delete_user_meta( $user->ID, WPDLL_DEVICE_OTP );

                // Log the user in
                wp_set_current_user( $user->ID );
                wp_set_auth_cookie( $user->ID, true );

                wp_safe_redirect( admin_url() );
                exit;
            }

            $error = __( 'Invalid or expired code.', 'wp-device-login-limit' );
        }

        // Render OTP form
        ob_start(); ?>
        <div class="wpdll-otp-wrapper" style="display:flex;justify-content:center;align-items:center;height:80vh;">
            <form method="post" class="wpdll-otp-card" style="max-width:400px;width:100%;padding:30px;background:#fff;border-radius:10px;box-shadow:0 5px 20px rgba(0,0,0,0.1);text-align:center;">
                <h2 style="margin-bottom:10px;"><?php esc_html_e( 'Verify Device', 'wp-device-login-limit' ); ?></h2>
                <p style="margin-bottom:20px;"><?php esc_html_e('A verification code has been sent to your email address.', 'wp-device-login-limit');?></p>
                
                <?php if ( $error ) : ?>
                    <p class="wpdll-error" style="color:#d63638;margin-bottom:15px;"><?php echo esc_html( $error ); ?></p>
                <?php endif; ?>

                <?php wp_nonce_field( 'wpdll_verify_otp' ); ?>
                <input type="hidden" name="log" value="<?php echo esc_attr( $username ); ?>">

                <input type="number" name="wpdll_otp" required placeholder="123456" style="width:100%;padding:12px;margin-bottom:15px;border-radius:6px;border:1px solid #ccc;text-align:center;">

                <button name="wpdll_verify_otp" style="width:100%;padding:12px;border:none;border-radius:6px;background:#2271b1;color:#fff;font-weight:bold;cursor:pointer;">
                    <?php esc_html_e( 'Verify & Continue', 'wp-device-login-limit' ); ?>
                </button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /* -------------------------
     * FRONTEND DEVICES
     * ------------------------- */
    public function wpdll_frontend_device_list() {

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

    /* -------------------------
     * ADMIN SETTINGS
     * ------------------------- */
    public function wpdll_register_settings() {

        register_setting( 'wpdll', WPDLL_DEVICE_LIMIT, [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 3,
        ] );
    }

    public function wpdll_admin_menu() {

        add_options_page(
            'Device Login Limit',
            'Device Login Limit',
            'manage_options',
            'wpdll',
            [ $this, 'wpdll_settings_page' ]
        );
    }

    public function wpdll_settings_page() { ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">WP Device Login Limit</h1>
            <p class="description">
                Control how many devices a user can log in from at the same time.
            </p>

            <hr class="wp-header-end">

            <form method="post" action="options.php">
                <?php settings_fields( 'wpdll' ); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="wpdll_device_limit">
                                    Maximum Devices Per User
                                </label>
                            </th>
                            <td>
                                <input
                                    type="number"
                                    id="wpdll_device_limit"
                                    name="<?php echo esc_attr( WPDLL_DEVICE_LIMIT ); ?>"
                                    value="<?php echo esc_attr( get_option( WPDLL_DEVICE_LIMIT, 3 ) ); ?>"
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
    public function wpdll_enforce_device_limit( $user, $username, $password ) {

        if ( ! $user instanceof WP_User ) {
            return $user;
        }

        $limit   = (int) get_option( WPDLL_DEVICE_LIMIT, 3 );
        $device  = $this->wpdll_get_device_id();
        $devices = get_user_meta( $user->ID, WPDLL_ALLOWED_DEVICES, true );

        if ( ! is_array( $devices ) ) {
            $devices = [];
        }

        // Known device → allow login immediately
        if ( in_array( $device, wp_list_pluck( $devices, 'id' ), true ) ) {
            return $user;
        }

        // Check for pending OTP
        $otp_data = get_user_meta( $user->ID, WPDLL_DEVICE_OTP, true );

        $otp_pending = false;
        $now = time();
        $otp_expiry = 10 * MINUTE_IN_SECONDS; // 10 minutes expiry

        if ( $otp_data && isset( $otp_data['status'] ) && $otp_data['status'] === 'pending' ) {
            if ( $now - $otp_data['time'] <= $otp_expiry ) {
                // Still valid → redirect to OTP page
                $otp_pending = true;
            } else {
                // Expired → remove OTP
                delete_user_meta( $user->ID, WPDLL_DEVICE_OTP );
            }
        }

        if ( $otp_pending ) {
            $page = get_page_by_path( WPDLL_OTP_PAGE_SLUG );
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

            // Get user IP and Device
            $ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
            $device_type = wp_is_mobile() ? 'Mobile' : 'Desktop';


            // Save OTP with pending status and timestamp
            update_user_meta( $user->ID, WPDLL_DEVICE_OTP, [
                'otp'     => $otp,
                'device'  => $device,
                'agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown',
                'time'    => $now,
                'status'  => 'pending',
                'ip_address'      => $ip_address,
                'device_type' => $device_type,
            ] );

            $subject = __( 'Verify New Device Login', 'wp-device-login-limit' );

            $message = sprintf(
                __(
                    /* Translators: 
                    %1$s is the user's display name, 
                    %2$s is the OTP verification code. 
                    */
                    "Hello %1\$s,\n\nYour verification code is: %2\$s\n\nThis code will expire shortly.\n\nIf you did not request this login, please ignore this email.",
                    'wp-device-login-limit'
                ),
                $user->display_name,
                $otp
            );


            $headers = array(
                'Content-Type: text/plain; charset=UTF-8',
            );

            $sent_email = wp_mail(
                $user->user_email,
                $subject,
                $message,
                $headers
            );

            if ( ! $sent_email ) {

                // 🔐 Security: remove OTP
                delete_user_meta( $user->ID, WPDLL_DEVICE_OTP );

                // 🚫 Stop login + show message to user
                return new WP_Error(
                    'wpdll_email_failed',
                    __( 
                        'We could not send the verification email at this time. Please configure smtp plugin or contact the site administrator.',
                        'wp-device-login-limit'
                    )
                );
            }



            // Email sent → redirect
            $page = get_page_by_path( WPDLL_OTP_PAGE_SLUG );
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
            'wpdll_limit',
            __( 'Device limit reached. Contact administrator.', 'wp-device-login-limit' )
        );
    }

    /* -------------------------
     * DEVICE IDENTIFIER
     * ------------------------- */
    private function wpdll_get_device_id() {

        if ( isset( $_COOKIE['wpdll_device_id'] ) ) {
            return sanitize_text_field( wp_unslash( $_COOKIE['wpdll_device_id'] ) );
        }

        // Get the user agent safely
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : 'unknown';
        $user_agent = sanitize_text_field( $user_agent );

        // Generate device ID using UUID + safe user agent
        $id = hash( 'sha256', wp_generate_uuid4() . $user_agent );


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
    * USER PROFILE – REGISTERED DEVICES
    * ------------------------- */
    public function wpdll_user_profile_devices( $user ) {

        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $devices = get_user_meta( $user->ID, WPDLL_ALLOWED_DEVICES, true );
        ?>
        <h2 style="margin-top:30px;"><?php esc_html_e( 'Device Login Limit', 'wp-device-login-limit' ); ?></h2>
        <p style="margin-bottom:15px; color:#555;">
            <?php esc_html_e( 'List of all devices that have accessed this user account. You can delete devices or monitor status.', 'wp-device-login-limit' ); ?>
        </p>

        <?php if ( ! is_array( $devices ) || empty( $devices ) ) : ?>
            <p style="color:#555;font-style:italic;"><?php esc_html_e( 'No devices registered yet.', 'wp-device-login-limit' ); ?></p>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>

                        <th><?php esc_html_e( 'Device', 'wp-device-login-limit' ); ?></th>
                        <th><?php esc_html_e( 'User Agent', 'wp-device-login-limit' ); ?></th>                
                        <th><?php esc_html_e( 'Last Login', 'wp-device-login-limit' ); ?></th>
                        <th><?php esc_html_e( 'IP Address', 'wp-device-login-limit' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'wp-device-login-limit' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'wp-device-login-limit' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $devices as $index => $device ) : 
                        $status = isset( $device['status'] ) ? ucfirst( $device['status'] ) : 'Unknown';
                        $status_color = ($status === 'Approved') ? '#27ae60' : '#d63638';
                        $time = ! empty( $device['time'] ) ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $device['time'] ) : esc_html__( 'Unknown', 'wp-device-login-limit' );
                        $ip = isset( $device['ip_address'] ) ? esc_html( $device['ip_address'] ) : '-';
                        $device_type = isset( $device['device_type'] ) ? esc_html( $device['device_type'] ) : 'Unknown';
                    ?>
                        <tr>
                            <td style="font-family:monospace;"><?php echo esc_html( $device_type ); ?></td>
                            <td><?php echo esc_html( $device['agent'] ); ?></td>
                            <td><?php echo esc_html( $time ); ?></td>
                            <td><?php echo esc_html( $ip ); ?></td>
                            <td>
                                <span style="display:inline-block;padding:3px 8px;font-size:12px;font-weight:bold;border-radius:4px;color:#fff;background:<?php echo esc_attr( $status_color ); ?>;">
                                    <?php echo esc_html( $status ); ?>
                                </span>
                            </td>
                            <td>
                                <a href="#" class="wpdll-delete-device" data-user-id="<?php echo esc_attr( $user->ID ); ?>" data-device-id="<?php echo esc_attr( $device['id'] ); ?>" style="color:#d63638;font-weight:bold;text-decoration:none;font-size:25px;padding:5px 15px;">
                                    &#x1F5D1; <!-- trash icon -->
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <script>
            jQuery(document).ready(function($){
                $('.wpdll-delete-device').on('click', function(e){
                    e.preventDefault();
                    if(confirm('Are you sure you want to delete this device?')){
                        var user_id = $(this).data('user-id');
                        var device_id = $(this).data('device-id');

                        $.post(ajaxurl, {
                            action: 'wpdll_delete_device',
                            user_id: user_id,
                            device_id: device_id,
                            _wpnonce: '<?php echo esc_js( wp_create_nonce("wpdll_delete_device_nonce") ); ?>'
                        }, function(response){
                            if(response.success){
                                location.reload();
                            } else {
                                alert('Failed to delete device.');
                            }
                        });
                    }
                });
            });
        </script>
        <?php
    }

    public function wpdll_save_user_profile( $user_id ) {

        // Only allow admins
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Verify nonce before processing form
        if ( isset( $_POST['wpdll_reset_devices'] ) && isset( $_POST['wpdll_reset_devices_nonce'] ) ) {

            if ( ! wp_verify_nonce( wp_unslash($_POST['wpdll_reset_devices_nonce']), 'wpdll_reset_devices_action' ) ) {
                return; // Nonce invalid, do nothing
            }

            // Safe to delete user meta
            delete_user_meta( $user_id, WPDLL_ALLOWED_DEVICES );
            delete_user_meta( $user_id, WPDLL_DEVICE_OTP );
        }
    }

    /* -------------------------
    * AJAX DEVICE DELETE
    * ------------------------- */
    private function wpdll_delete_device_callback() {

        // Verify nonce
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'wpdll_delete_device_nonce' ) ) {
            wp_send_json_error( [ 'message' => 'Invalid nonce' ] );
        }

        // Check permissions
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ] );
        }

        // Get data
        $user_id   = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
        $device_id = isset( $_POST['device_id'] ) ? sanitize_text_field( wp_unslash( $_POST['device_id'] ) ) : '';

        if ( ! $user_id || empty( $device_id ) ) {
            wp_send_json_error( [ 'message' => 'Missing data' ] );
        }

        // Get devices
        $devices = get_user_meta( $user_id, WPDLL_ALLOWED_DEVICES, true );

        if ( ! is_array( $devices ) || empty( $devices ) ) {
            wp_send_json_error( [ 'message' => 'No devices found' ] );
        }

        // Remove the matching device
        $updated_devices = array_filter( $devices, function( $d ) use ( $device_id ) {
            return isset( $d['id'] ) && $d['id'] !== $device_id;
        });

        // Update user meta
        update_user_meta( $user_id, WPDLL_ALLOWED_DEVICES, $updated_devices );

        wp_send_json_success( [ 'message' => 'Device deleted successfully' ] );
    }


}

new WP_Device_Login_Limit();
