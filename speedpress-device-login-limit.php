<?php
/**
 * Plugin Name: SpeedPress Device Login Limit
 * Plugin URI: https://wpspeedpress.com/speedpress-device-login-limit
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

final class SPDLL_Device_Login_Limit {


    public function __construct() {
        // Load function on plugin registration
        register_activation_hook( __FILE__, [ $this, 'plugin_activation' ] );

        // Enqueue script for admin page
        add_action('admin_enqueue_scripts', [ $this, 'spdll_load_admin_assets' ]);

        // Create OTP page on activation & init
        add_action( 'init', [ $this, 'spdll_create_otp_page' ] );
        
        // Shortcodes
        add_shortcode( 'spdll_otp_form', [ $this, 'spdll_otp_shortcode' ] );
        add_shortcode( 'spdll_my_devices', [ $this, 'spdll_frontend_device_list' ] );

        // Admin settings
        add_action('admin_init', [ $this, 'speedpress_dll_check_smtp_active' ]);
        add_action( 'admin_init', [ $this, 'spdll_register_settings' ] );
        add_action( 'admin_menu', [ $this, 'spdll_admin_menu' ] );

        // Login enforcement
        add_filter( 'authenticate', [ $this, 'spdll_enforce_device_limit' ], 30, 3 );

        // User profile devices
        add_action( 'show_user_profile', [ $this, 'spdll_user_profile_devices' ] );
        add_action( 'edit_user_profile', [ $this, 'spdll_user_profile_devices' ] );
        add_action( 'personal_options_update', [ $this, 'spdll_save_user_profile' ] );
        add_action( 'edit_user_profile_update', [ $this, 'spdll_save_user_profile' ] );

        // Delete the device form admin
        add_action('wp_ajax_spdll_delete_device', [ $this, 'spdll_delete_device_callback' ]);
    }

    public function plugin_activation() {
        // 1. Check SMTP and block activation if missing
        $this->spdll_check_smtp_on_activation();

        // 2. Send test email to confirm SMTP works
        $this->spdll_test_smtp_on_activation();

        // 3. Approve first device for current admin
        $this->spdll_approve_first_device_on_activation();
    }

    /**
     * Deactivate plugin is smtp is not activated
     */
    public function spdll_check_smtp_on_activation() {

        // Check WP Mail SMTP
        if ( ! function_exists( 'wp_mail_smtp' ) ) {

            deactivate_plugins( plugin_basename( __FILE__ ) );
            $title = __( 'Missing SMTP Dependency', 'speedpress-device-login-limit' );
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
                 esc_html( $title ),
                [
                    'back_link' => true,
                ]
            );


        }
    }

     /**
     * Enqueue assets
     */
    public function spdll_load_admin_assets() {
        wp_enqueue_style(
            'spdll-admin-css',
            plugin_dir_url(__FILE__) . 'assets/css/spdll.css',
            [],
            '1.0.0'
        );
        wp_enqueue_script(
            'spdll-admin-js',
            plugin_dir_url(__FILE__) . 'assets/js/spdll.js',
            ['jquery'],
            '1.0.0',
            true
        );
    }
    
    /**
     * Check if SMTP is active in admin notices
     */
    public function speedpress_dll_check_smtp_active() {

        include_once(ABSPATH . 'wp-admin/includes/plugin.php');

        if ( ! is_plugin_active('wp-mail-smtp/wp_mail_smtp.php') ) {

            add_action('admin_notices', [$this, 'speedpress_dll_smtp_notice']);
        }
    }

    public function speedpress_dll_smtp_notice() {
        echo '<div class="notice notice-error"><p>';
        echo esc_html__('SpeedPress Device Login Limit requires WP Mail SMTP to send OTP emails. Please install and activate an SMTP plugin.', 'speedpress-device-login-limit');
        echo '</p></div>';
    }

    /**
     * Send test email on plugin activation to ensure SMTP is working.
     */
    public function spdll_test_smtp_on_activation() {

        // Get admin email
        $admin_email = get_option( 'admin_email' );

        // Compose test email
        $subject = __( 'WP Device Login Limit: Test Email', 'speedpress-device-login-limit' );
        $message = __( 'This is a test email to verify that your SMTP settings are working correctly.', 'speedpress-device-login-limit' );
        $headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

        // Try sending the email
        $sent = wp_mail( $admin_email, $subject, $message, $headers );

        // If failed → deactivate plugin & show error
        if ( ! $sent ) {

            deactivate_plugins( plugin_basename( __FILE__ ) );
            $title = __( 'SMTP Configuration Required', 'speedpress-device-login-limit' );
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

                    <h1 style="color:#d63638;margin-bottom:15px; font-size:28px;">
                        ⚠ SMTP Test Failed
                    </h1>

                    <p>
                        <span class="spdll-highlight">WP Device Login Limit</span> could not send a test email to the admin email address.
                    </p>

                    <h3>How to fix it:</h3>
                    <ol>
                        <li>Install and activate an SMTP plugin (like <span class="spdll-highlight">WP Mail SMTP</span>).</li>
                        <li>Configure your email service (Gmail, Outlook, Zoho, etc.).</li>
                        <li>Test email sending from the SMTP plugin settings.</li>
                        <li>Reactivate <span class="spdll-highlight">WP Device Login Limit</span>.</li>
                    </ol>

                    <a href="https://wordpress.org/plugins/wp-mail-smtp/" target="_blank" rel="noopener noreferrer" class="spdll-btn">
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
    private function spdll_approve_first_device_on_activation() {

        $user = wp_get_current_user();
        if ( ! $user || ! $user->ID ) {
            return;
        }

        // Get existing approved devices
        $devices = get_user_meta( $user->ID, SPDLL_ALLOWED_DEVICES, true );
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
        $device_id = $this->spdll_get_device_id(); // This function checks cookie and generates ID if missing

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

        update_user_meta( $user->ID, SPDLL_ALLOWED_DEVICES, $devices );

        // Set cookie if not already set
        if ( empty( $_COOKIE['spdll_device_id'] ) ) {
            setcookie(
                'spdll_device_id',
                $device_id,
                time() + YEAR_IN_SECONDS,
                COOKIEPATH,
                COOKIE_DOMAIN,
                is_ssl(),
                true
            );
            $_COOKIE['spdll_device_id'] = $device_id; // Also set it in PHP superglobal
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
            'SpeedPress Device Limit',
            'SpeedPress Device Limit',
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

            // Get user IP and Device
            $ip_address = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
            $device_type = wp_is_mobile() ? 'Mobile' : 'Desktop';


            // Save OTP with pending status and timestamp
            update_user_meta( $user->ID, SPDLL_DEVICE_OTP, [
                'otp'     => $otp,
                'device'  => $device,
                'agent' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : 'unknown',
                'time'    => $now,
                'status'  => 'pending',
                'ip_address'      => $ip_address,
                'device_type' => $device_type,
            ] );

            $subject = __( 'Verify New Device Login', 'speedpress-device-login-limit' );

            $message = sprintf(
                __(
                    /* Translators: 
                    %1$s is the user's display name, 
                    %2$s is the OTP verification code. 
                    */
                    "Hello %1\$s,\n\nYour verification code is: %2\$s\n\nThis code will expire shortly.\n\nIf you did not request this login, please ignore this email.",
                    'speedpress-device-login-limit'
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

                // Security: remove OTP
                delete_user_meta( $user->ID, SPDLL_DEVICE_OTP );

                // Stop login + show message to user
                return new WP_Error(
                    'spdll_email_failed',
                    __( 
                        'We could not send the verification email at this time. Please configure smtp plugin or contact the site administrator.',
                        'speedpress-device-login-limit'
                    )
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
     * DEVICE IDENTIFIER
     * ------------------------- */
    private function spdll_get_device_id() {

        if ( isset( $_COOKIE['spdll_device_id'] ) ) {
            return sanitize_text_field( wp_unslash( $_COOKIE['spdll_device_id'] ) );
        }

        // Get the user agent safely
        $user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field(wp_unslash( $_SERVER['HTTP_USER_AGENT'] )) : 'unknown';
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
        ?>
        <h2 style="margin-top:30px;"><?php esc_html_e( 'Device Login Limit', 'speedpress-device-login-limit' ); ?></h2>
        <p style="margin-bottom:15px; color:#555;">
            <?php esc_html_e( 'List of all devices that have accessed this user account. You can delete devices or monitor status.', 'speedpress-device-login-limit' ); ?>
        </p>

        <?php if ( ! is_array( $devices ) || empty( $devices ) ) : ?>
            <p style="color:#555;font-style:italic;"><?php esc_html_e( 'No devices registered yet.', 'speedpress-device-login-limit' ); ?></p>
        <?php else : ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>

                        <th><?php esc_html_e( 'Device', 'speedpress-device-login-limit' ); ?></th>
                        <th><?php esc_html_e( 'User Agent', 'speedpress-device-login-limit' ); ?></th>                
                        <th><?php esc_html_e( 'Last Login', 'speedpress-device-login-limit' ); ?></th>
                        <th><?php esc_html_e( 'IP Address', 'speedpress-device-login-limit' ); ?></th>
                        <th><?php esc_html_e( 'Status', 'speedpress-device-login-limit' ); ?></th>
                        <th><?php esc_html_e( 'Actions', 'speedpress-device-login-limit' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $devices as $index => $device ) : 
                        $status = isset( $device['status'] ) ? ucfirst( $device['status'] ) : 'Unknown';
                        $status_color = ($status === 'Approved') ? '#27ae60' : '#d63638';
                        $time = ! empty( $device['time'] ) ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $device['time'] ) : esc_html__( 'Unknown', 'speedpress-device-login-limit' );
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
                                <a href="#" class="spdll-delete-device" data-user-id="<?php echo esc_attr( $user->ID ); ?>" data-device-id="<?php echo esc_attr( $device['id'] ); ?>" style="color:#d63638;font-weight:bold;text-decoration:none;font-size:25px;padding:5px 15px;">
                                    &#x1F5D1; <!-- trash icon -->
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php
    }

    public function spdll_save_user_profile( $user_id ) {

        // Only allow admins
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Verify nonce before processing form
        if ( isset( $_POST['spdll_reset_devices'] ) && isset( $_POST['spdll_reset_devices_nonce'] ) ) {

            if (
                ! isset( $_POST['spdll_reset_devices_nonce'] ) ||
                ! wp_verify_nonce(
                    sanitize_text_field( wp_unslash( $_POST['spdll_reset_devices_nonce'] ) ),
                    'spdll_reset_devices_action'
                )
            ) {
                return;
            }

            // Safe to delete user meta
            delete_user_meta( $user_id, SPDLL_ALLOWED_DEVICES );
            delete_user_meta( $user_id, SPDLL_DEVICE_OTP );
        }
    }

    /* -------------------------
    * AJAX DEVICE DELETE
    * ------------------------- */
    public function spdll_delete_device_callback() {

        // Verify nonce
        if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'spdll_delete_device_nonce' ) ) {
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
        $devices = get_user_meta( $user_id, SPDLL_ALLOWED_DEVICES, true );

        if ( ! is_array( $devices ) || empty( $devices ) ) {
            wp_send_json_error( [ 'message' => 'No devices found' ] );
        }

        // Remove the matching device
        $updated_devices = array_filter( $devices, function( $d ) use ( $device_id ) {
            return isset( $d['id'] ) && $d['id'] !== $device_id;
        });

        // Update user meta
        update_user_meta( $user_id, SPDLL_ALLOWED_DEVICES, $updated_devices );

        wp_send_json_success( [ 'message' => 'Device deleted successfully' ] );
    }


}

new SPDLL_Device_Login_Limit();