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
        register_activation_hook( __FILE__, [ $this, 'spdll_plugin_activation' ] );

        add_action( 'wp_enqueue_scripts', [ $this, 'spdll_load_public_assets' ] );

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

        // Delete the device form admin
        add_action('wp_ajax_spdll_delete_device', [ $this, 'spdll_delete_device_callback' ]);
    }

    public function spdll_plugin_activation() {
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
        wp_enqueue_script(
            'spdll-public-js',
            plugin_dir_url(__FILE__) . 'assets/js/spdll-public.js',
            ['jquery'],
            '1.0.0',
            true
        );
        wp_localize_script(
            'spdll-admin-js',
            'spdll_ajax',
            [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce('spdll_delete_device_nonce')
            ]
        );
    }
    
    public function spdll_load_public_assets() {
        wp_enqueue_script(
            'spdll-public-js',
            plugin_dir_url(__FILE__) . 'assets/js/spdll-public.js',
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

        // Compose subject
        $subject = __( 'Test Email from SpeedPress Device Login Limit ', 'speedpress-device-login-limit' );

        // Get current year safely
        $current_year = esc_html( wp_date( 'Y' ) );

        // Compose HTML message based on your professional email template
        $message = sprintf(
            /* Translators: %1$s is the site name, %2$s is the current year */
            __(
                '<div style="max-width:600px;margin:0 auto;font-family:Arial,sans-serif;background:#eef2f7;padding:20px;">
                    <!-- Header -->
                    <table width="100%%" cellpadding="0" cellspacing="0" border="0" style="background:linear-gradient(135deg,#4e73df,#1cc88a);color:#ffffff;border-radius:12px;padding:30px;">
                        <tr>
                            <td>
                                <h1 style="margin:0;font-size:28px;font-weight:bold;">SpeedPress</h1>
                                <p style="margin:5px 0 15px;font-size:16px;">Secure, Accelerate & Optimize Your WordPress Site</p>
                                <a href="https://wpspeedpress.com" style="display:inline-block;margin-top:20px;background:#ffffff;color:#1cc88a;text-decoration:none;font-weight:bold;padding:12px 25px;border-radius:6px;">Visit SpeedPress</a>
                            </td>
                        </tr>
                    </table>

                    <!-- Content Card -->
                    <table width="100%%" cellpadding="0" cellspacing="0" border="0" style="padding:30px 0;">
                        <tr>
                            <td align="center">
                                <table width="480" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.08);padding:40px 30px;">
                                    <tr>
                                        <td style="text-align:center;">
                                            <h2 style="margin:0;color:#2c3e50;font-size:24px;">SMTP Test Email</h2>
                                            <p style="margin:15px 0 0;font-size:15px;color:#555;">Hello Admin,</p>
                                            <p style="margin:10px 0 0;font-size:15px;color:#555;">
                                                This is a test email to verify that your email delivery settings are working correctly. 
                                                If you see this styled email, your SMTP or mail setup is functioning properly.
                                            </p>
                                            <p style="margin:20px 0 0;font-size:15px;color:#555;">
                                                This email is generated by <strong>SpeedPress Device Login Limit</strong> plugin.
                                            </p>
                                            <a href="https://wpspeedpress.com" style="margin-top:30px;display:inline-block;background:#1cc88a;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 30px;border-radius:8px;">Go to SpeedPress</a>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>
                    </table>

                    <!-- Footer -->
                    <table width="100%%" cellpadding="0" cellspacing="0" border="0" style="background:#2c3e50;padding:30px;border-radius:12px;color:#ffffff;">
                        <tr>
                            <td>
                                <p style="margin:0;font-size:14px;line-height:20px;">
                                    SpeedPress – All-in-one WordPress optimization & security tool.
                                </p>
                                <p style="margin-top:15px;font-size:12px;color:#aaa;">
                                    &copy; %2$s %1$s. All rights reserved.
                                </p>
                            </td>
                        </tr>
                    </table>
                </div>',
                'speedpress-device-login-limit'
            ),
            esc_html( get_bloginfo( 'name' ) ), // %1$s => Site Name
            esc_html( $current_year )           // %2$s => Current Year
        );

        // Set headers for HTML email
        $headers = [
            'Content-Type: text/html; charset=UTF-8',
        ];

        // Send the email
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
        <div class="spdll-otp-wrapper" style="display:flex;justify-content:center;align-items:center;min-height:80vh;background:#eef2f7;padding:20px;font-family:Arial, sans-serif;">

            <form method="post" class="spdll-otp-card" style="max-width:420px;width:100%;background:#ffffff;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.08);padding:40px 30px;text-align:center;">

                <!-- Header: Brand + Info -->
                <div style="margin-bottom:25px;">
                    <h1 style="margin:0;font-size:26px;color:#1cc88a;font-weight:bold;">SpeedPress</h1>
                    <p style="margin:5px 0 0;font-size:15px;color:#555;">Secure, Accelerate & Optimize Your WordPress Site</p>
                </div>

                <!-- OTP Info -->
                <h2 style="margin:20px 0 10px;font-size:22px;color:#2c3e50;">Verify Your Device</h2>
                <p style="margin:0 0 20px;font-size:14px;color:#666;">
                    We sent a verification code to your email. Enter it below to continue.
                </p>

                <!-- Error Message -->
                <?php if ( $error ) : ?>
                    <p class="spdll-error" style="color:#d63638;margin-bottom:15px;font-weight:bold;"><?php echo esc_html( $error ); ?></p>
                <?php endif; ?>

                <?php wp_nonce_field( 'spdll_verify_otp' ); ?>
                <input type="hidden" name="log" value="<?php echo esc_attr( $username ); ?>">

                <!-- OTP Input -->
                <input type="number" name="spdll_otp" required placeholder="Enter 6-digit code" 
                    style="width:100%;padding:14px;margin-bottom:20px;border-radius:8px;border:1px solid #ccc;font-size:16px;text-align:center;box-sizing:border-box;">

                <!-- Countdown Timer -->
                <p id="spdll-countdown" style="margin:0 0 20px;font-size:14px;color:#888;">
                    <strong>Time remaining: 10:00</strong>
                </p>

                <!-- Submit Button -->
                <button name="spdll_verify_otp" style="width:100%;padding:14px;border:none;border-radius:8px;background:#1cc88a;color:#ffffff;font-weight:bold;font-size:16px;cursor:pointer;transition:all 0.3s ease;">
                    Verify & Continue
                </button>

                <!-- Footer: Links & Info -->
                <div style="margin-top:25px;font-size:12px;color:#aaa;">
                    Need help? Contact <a href="mailto:developerlaju@gmail.com" style="color:#1cc88a;text-decoration:none;">Plugin Support</a><br>
                    &copy; <?php echo esc_html( wp_date( 'Y' ) ); ?> SpeedPress. All rights reserved.
                </div>
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

            $subject = __('Your Login Verification Code', 'speedpress-device-login-limit');


            // Prepare dynamic values safely.
            $user_name  = esc_html( $user->display_name );
            $otp_code   = esc_html( $otp );
            $site_name  = esc_html( get_bloginfo( 'name' ) );
            $year       = esc_html( wp_date( 'Y' ) );

            // Translatable strings.
            
            $hello_text = sprintf(
                /* translators: 1: User display name */
                __( 'Hello %1$s,', 'speedpress-device-login-limit' ),
                $user_name
            );

            $verification_text = __(
                'We detected a login attempt from a new device. Use the verification code below to continue:',
                'speedpress-device-login-limit'
            );

            $expiry_text = __(
                'This code expires in 10 minutes.',
                'speedpress-device-login-limit'
            );

            $login_heading = __(
                'Login Verification',
                'speedpress-device-login-limit'
            );

            $explore_button = __(
                'Explore Features',
                'speedpress-device-login-limit'
            );

            $get_started_button = __(
                'Get Started with SpeedPress',
                'speedpress-device-login-limit'
            );

            $brand_tagline = __(
                'Secure, Accelerate & Optimize Your WordPress Site',
                'speedpress-device-login-limit'
            );

            $footer_desc = __(
                'SpeedPress – All-in-one WordPress optimization & security tool.',
                'speedpress-device-login-limit'
            );

            $support_label = __(
                'Support:',
                'speedpress-device-login-limit'
            );

            $all_rights = sprintf(
                /* translators: 1: Current year */
                __( '© %1$s SpeedPress. All rights reserved.', 'speedpress-device-login-limit' ),
                $year
            );

            // Build email message.
            $message = '
            <div style="max-width:600px;margin:0 auto;font-family:Arial,sans-serif;background:#eef2f7;padding:20px;">

                <!-- Header -->
                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:linear-gradient(135deg,#4e73df,#1cc88a);color:#ffffff;border-radius:12px;padding:30px;">
                    <tr>
                        <td>
                            <h1 style="margin:0;font-size:28px;font-weight:bold;">SpeedPress</h1>
                            <p style="margin:5px 0 15px;font-size:16px;">' . esc_html( $brand_tagline ) . '</p>

                            <p style="font-size:14px;color:#f1f3f5;line-height:20px;">
                                🚀 Boost your website speed with SpeedPress Premium Features.<br>
                                🔒 Secure your login and devices with advanced protections.<br>
                                🌐 Visit our website:
                                <a href="https://wpspeedpress.com" style="color:#ffffff;text-decoration:underline;">www.wpspeedpress.com</a>
                            </p>

                            <a href="https://wpspeedpress.com"
                            style="display:inline-block;margin-top:20px;background:#ffffff;color:#1cc88a;text-decoration:none;font-weight:bold;padding:12px 25px;border-radius:6px;">
                            ' . esc_html( $get_started_button ) . '
                            </a>
                        </td>
                    </tr>
                </table>

                <!-- OTP Card -->
                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="padding:30px 0;">
                    <tr>
                        <td align="center">
                            <table width="480" cellpadding="0" cellspacing="0" border="0" style="background:#ffffff;border-radius:12px;box-shadow:0 10px 25px rgba(0,0,0,0.08);padding:40px 30px;">
                                <tr>
                                    <td style="text-align:center;">
                                        <h2 style="margin:0;color:#2c3e50;font-size:24px;">' . esc_html( $login_heading ) . '</h2>

                                        <p style="margin:15px 0 0;font-size:15px;color:#555;">
                                            ' . $hello_text . '
                                        </p>

                                        <p style="margin:10px 0 0;font-size:15px;color:#555;">
                                            ' . esc_html( $verification_text ) . '
                                        </p>

                                        <div style="margin:30px 0;text-align:center;">
                                            <span style="display:inline-block;font-size:32px;font-weight:bold;letter-spacing:5px;background:#f1f3f8;padding:20px 35px;border-radius:10px;color:#2c3e50;box-shadow:0 5px 15px rgba(0,0,0,0.1);">
                                                ' . $otp_code . '
                                            </span>
                                        </div>

                                        <p style="margin:0;font-size:14px;color:#888;">
                                            ' . esc_html( $expiry_text ) . '
                                        </p>

                                        <a href="https://wpspeedpress.com/"
                                        style="margin-top:30px;display:inline-block;background:#1cc88a;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 30px;border-radius:8px;">
                                        ' . esc_html( $explore_button ) . '
                                        </a>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>

                <!-- Footer -->
                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#2c3e50;padding:30px;border-radius:12px;color:#ffffff;">
                    <tr>
                        <td>
                            <p style="margin:0;font-size:14px;line-height:20px;">
                                ' . esc_html( $footer_desc ) . '
                            </p>

                            <p style="margin:15px 0 0;font-size:12px;">
                                ' . esc_html( $support_label ) . '
                                <a href="mailto:developerlaju@gmail.com" style="color:#1cc88a;">
                                    developerlaju@gmail.com
                                </a>
                            </p>

                            <p style="margin-top:20px;font-size:12px;color:#aaa;">
                                ' . esc_html( $all_rights ) . '
                            </p>
                        </td>
                    </tr>
                </table>

            </div>';


            $headers = array(
                'Content-Type: text/html; charset=UTF-8',
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
        <h2 style="margin-top:40px;">
            <?php esc_html_e( 'Device Login Limit', 'speedpress-device-login-limit' ); ?>
        </h2>
        <div style="
            background:#f6f7f9;
            padding:25px;
            border-radius:8px;
            border:1px solid #e1e4e8;
            margin-top:15px;
        ">

            <p style="margin-top:0;margin-bottom:20px;color:#555;font-size:14px;">
                <?php esc_html_e( 'List of all devices that have accessed this user account. You can delete devices or monitor status.', 'speedpress-device-login-limit' ); ?>
            </p>

        <?php if ( ! is_array( $devices ) || empty( $devices ) ) : ?>

            <p style="color:#777;font-style:italic;margin:0;">
                <?php esc_html_e( 'No devices registered yet.', 'speedpress-device-login-limit' ); ?>
            </p>

        <?php else : ?>

            <table style="
                width:100%;
                border-collapse:collapse;
                background:#ffffff;
                font-size:14px;
            ">
                <thead>
                    <tr style="background:#f1f3f5;border-bottom:1px solid #e1e4e8;">
                        <th style="text-align:left;padding:12px 14px;font-weight:600;color:#333;">
                            <?php esc_html_e( 'Device', 'speedpress-device-login-limit' ); ?>
                        </th>
                        <th style="text-align:left;padding:12px 14px;font-weight:600;color:#333;">
                            <?php esc_html_e( 'User Agent', 'speedpress-device-login-limit' ); ?>
                        </th>
                        <th style="text-align:left;padding:12px 14px;font-weight:600;color:#333;">
                            <?php esc_html_e( 'Last Login', 'speedpress-device-login-limit' ); ?>
                        </th>
                        <th style="text-align:left;padding:12px 14px;font-weight:600;color:#333;">
                            <?php esc_html_e( 'IP Address', 'speedpress-device-login-limit' ); ?>
                        </th>
                        <th style="text-align:center;padding:12px 14px;font-weight:600;color:#333;">
                            <?php esc_html_e( 'Status', 'speedpress-device-login-limit' ); ?>
                        </th>
                        <th style="text-align:center;padding:12px 14px;font-weight:600;color:#333;">
                            <?php esc_html_e( 'Actions', 'speedpress-device-login-limit' ); ?>
                        </th>
                    </tr>
                </thead>

                <tbody>
                <?php foreach ( $devices as $index => $device ) :

                    $status = isset( $device['status'] ) ? ucfirst( $device['status'] ) : 'Unknown';
                    $status_color = ($status === 'Approved') ? '#16a34a' : '#dc2626';
                    $time = ! empty( $device['time'] )
                        ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $device['time'] )
                        : esc_html__( 'Unknown', 'speedpress-device-login-limit' );
                    $ip = isset( $device['ip_address'] ) ? esc_html( $device['ip_address'] ) : '-';
                    $device_type = isset( $device['device_type'] ) ? esc_html( $device['device_type'] ) : 'Unknown';
                ?>

                    <tr style="border-bottom:1px solid #edf0f2;">
                        <td style="padding:12px 14px;font-family:monospace;color:#333;">
                            <?php echo esc_html( $device_type ); ?>
                        </td>

                        <td style="padding:12px 14px;color:#555;max-width:280px;word-break:break-word;">
                            <?php echo esc_html( $device['agent'] ); ?>
                        </td>

                        <td style="padding:12px 14px;color:#555;">
                            <?php echo esc_html( $time ); ?>
                        </td>

                        <td style="padding:12px 14px;color:#555;">
                            <?php echo esc_html( $ip ); ?>
                        </td>

                        <td style="padding:12px 14px;text-align:center;">
                            <span style="
                                display:inline-block;
                                padding:4px 10px;
                                font-size:12px;
                                font-weight:600;
                                border-radius:20px;
                                color:#fff;
                                background:<?php echo esc_attr( $status_color ); ?>;
                            ">
                                <?php echo esc_html( $status ); ?>
                            </span>
                        </td>

                        <td style="padding:12px 14px;text-align:center;">
                            <a href="#"
                            class="spdll-delete-device"
                            data-user-id="<?php echo esc_attr( $user->ID ); ?>"
                            data-device-id="<?php echo esc_attr( $device['id'] ); ?>"
                            style="
                                    display:inline-block;
                                    color:#dc2626;
                                    font-size:18px;
                                    text-decoration:none;
                                    transition:opacity .2s ease;
                            "
                            title="Delete Device">
                                <span class="dashicons dashicons-trash"></span>
                            </a>
                        </td>
                    </tr>

                <?php endforeach; ?>
                </tbody>
            </table>

        <?php endif; ?>

        </div>

        <?php
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