=== WP Device Login Limit ===
Contributors: devlaju
Tags: security, login, device limit, user access
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

A plugin to limit users to a fixed number of devices and verify new devices via OTP.

== Description ==
Limit users to logging in from a fixed number of devices. Devices are whitelisted. New devices require email OTP verification, and admins can reset devices per user.


== Features ==
* Hard device whitelist (not session based)
* Global device limit for all users
* OTP verification for new devices
* OTP expiry configurable (default 10 minutes)
* Admin reset device access per user
* Frontend shortcode to display user devices: [wpdll_my_devices]
* Automatic redirect for verified devices
* WooCommerce login compatible

== Installation ==
1. Upload the plugin to `/wp-content/plugins/`.
2. Activate the plugin via the 'Plugins' menu in WordPress.
3. Go to **Settings → Device Login Limit** to set the maximum number of devices per user.

== Email Delivery (Important) ==
This plugin relies on email delivery for OTP verification.
We strongly recommend installing one of the following SMTP plugins:

- WP Mail SMTP

Without SMTP, OTP emails may fail on many hosting environments. We strongly recommend configuring SMTP before using the plugin.


== Frequently Asked Questions ==
= What happens if a user exceeds the device limit? =
Users will be required to verify new devices via OTP. If the limit is reached, login will be blocked until an existing device is freed by admin.

= Can admins reset a user's devices? =
Yes, admins can reset devices per user from their profile page. Users will need to verify devices again on their next login.

= Does it work with WooCommerce login? =
Yes, all login methods including WooCommerce are supported.

= How long is the OTP valid? =
By default, OTP codes expire after 10 minutes. Users must enter the OTP within this period.

== Screenshots ==
1. OTP verification form
2. Admin user profile with device list and reset option
3. Plugin settings page

== Shortcodes ==
* `[wpdll_my_devices]` – Display a list of registered devices for the current user on the frontend.

== Changelog ==
= 1.0.0 =
* Initial release
* Device login limit
* OTP verification for new devices
* Admin device reset and frontend device list
