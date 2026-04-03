<?php
/**
 * Plugin Name: Syncifyer Cart Recovery
 * Plugin URI:  https://example.com/plugins/syncifyer-cart-recovery
 * Description: Cart recovery plugin bootstrap with install routines and API integration.
 * Version:     1.0.0
 * Author:      Your Name
 * Author URI:  https://example.com
 * Text Domain: syncifyer-cart-recovery
 * Domain Path: /languages
 * Requires at least: 6.4
 * Requires PHP: 7.4
 *
 * @package SyncifyerCartRecovery
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'SCR_PLUGIN_FILE' ) ) {
	define( 'SCR_PLUGIN_FILE', __FILE__ );
}

require_once plugin_dir_path( SCR_PLUGIN_FILE ) . 'includes/class-syncifyer-cart-recovery.php';

/**
 * Get main plugin instance.
 *
 * @return Syncifyer_Cart_Recovery
 */
function syncifyer_cart_recovery() {
	return Syncifyer_Cart_Recovery::instance();
}

syncifyer_cart_recovery();
