=== Syncifyer Cart Recovery ===
Contributors: yourname
Tags: cart recovery, abandoned cart, api
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Production-ready WordPress plugin scaffold for Syncifyer Cart Recovery.

== Description ==

This plugin uses a WooCommerce-style bootstrap in the main plugin file:

- `define_constants()`
- `define_tables()`
- `includes()`
- `init_hooks()`

Current features:
- Install routine with table creation and version/options setup
- Activation and deactivation API notifications when configured
- Reusable API client for any endpoint
- Optional API authentication headers
- Normalized response array with status code, body and decoded JSON

== Installation ==

1. Upload plugin to `/wp-content/plugins/abandoned-cart`.
2. Activate the plugin through WordPress admin.
3. Set base API URL with:
   - Constant: `SCR_API_BASE_URL`
   - Filter: `scr_api_base_url`

== Hooks ==

- `scr_api_base_url` (filter)
- `scr_api_timeout` (filter)
- `scr_api_request_args` (filter)
- `scr_api_auth_headers` (filter)
- `scr_api_response` (action)
- `scr_install_event_payload` (filter)
- `scr_install_event_sent` (action)
- `scr_installed` (action)
- `scr_deactivated` (action)
- `syncifyer_cart_recovery_loaded` (action)

== Changelog ==

= 1.0.0 =
- Initial Syncifyer Cart Recovery implementation.
