=== WP Device Login Limit ===
Contributors: wp-device-login-limit
Tags: security, login, device limit, user access
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

== Description ==
Limit users to logging in from a fixed number of devices. Devices are whitelisted, new devices require email OTP verification, and admins can reset devices per user.

== Features ==
* Hard device whitelist (not session based)
* Global device limit for all users
* OTP verification for new devices
* Admin reset device access
* Frontend device list shortcode
* WooCommerce compatible

== Installation ==
1. Upload plugin to /wp-content/plugins/
2. Activate
3. Set device limit in Settings → Device Login Limit

== Shortcodes ==
[wpdll_my_devices]

== Changelog ==
= 1.0.0 =
Initial release
