# SpeedPress Device Login Limit

SpeedPress Device Login Limit allows site owners to restrict user logins to a fixed number of trusted devices.  
Once the device limit is reached, users can only log in from their registered devices unless an admin resets their device access.

This plugin is ideal for:
- Membership sites
- Course platforms
- WooCommerce stores
- Private communities
- SaaS-style WordPress apps

---

## ✨ Features

- 🔐 **Hard device-based login restriction**
- 📱 Allow login from only **N registered devices**
- 🚫 Block login from new devices after limit is reached
- 🔑 **Email OTP verification** for new devices
- 👑 **Admin reset device access** per user
- 🌍 Global device limit for **all users (including admins)**
- 🧑 User-visible device list (shortcode)
- 🛒 WooCommerce compatible
- 🌐 Translation ready (i18n)
- ✅ WordPress.org compliant

---

## 🔧 How It Works

1. A user logs in from a new device
2. If device slots are available:
   - An OTP is sent to the user’s email
   - Device is verified and registered
3. If the device limit is reached:
   - Login is blocked
4. Admin can reset devices at any time

> Device detection is cookie-based (industry standard).

---

## ⚙️ Installation

1. Upload the plugin folder to `/wp-content/plugins/`
2. Activate **SpeedPress Device Login Limit**
3. Go to **Settings → Device Login Limit**
4. Set the maximum allowed devices

---

## ⚙️ Settings

- **Maximum Devices Per User**
  - Applies to ALL users
  - Includes administrators

---

## 👑 Admin Controls

- View registered devices in user profile
- Reset device access for any user
- Reset works for admins too

---

## 👤 User Features

- View registered devices using shortcode:

```text
[spdll_my_devices]
