<?php
/**
 * Main plugin class.
 *
 * @package SyncifyerCartRecovery
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Syncifyer_Cart_Recovery', false ) ) {
	/**
	 * Main plugin bootstrap.
	 */
	final class Syncifyer_Cart_Recovery {

		/**
		 * Singleton instance.
		 *
		 * @var Syncifyer_Cart_Recovery|null
		 */
		protected static $instance = null;

		/**
		 * Runtime plugin object.
		 *
		 * @var Syncifyer_Cart_Recovery_Plugin|null
		 */
		protected $plugin = null;

		/**
		 * Get singleton instance.
		 *
		 * @return Syncifyer_Cart_Recovery
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}

			return self::$instance;
		}

		/**
		 * Bootstrap plugin.
		 */
		private function __construct() {
			$this->define_constants();
			$this->define_tables();
			$this->includes();
			$this->init_hooks();
		}

		/**
		 * Define plugin constants.
		 *
		 * @return void
		 */
		private function define_constants() {
			$this->define( 'SCR_PLUGIN_VERSION', '1.0.0' );
			$this->define( 'SCR_DB_VERSION', '1.0.0' );
			$this->define( 'SCR_PLUGIN_BASENAME', plugin_basename( SCR_PLUGIN_FILE ) );
			$this->define( 'SCR_PLUGIN_PATH', plugin_dir_path( SCR_PLUGIN_FILE ) );
			$this->define( 'SCR_PLUGIN_URL', plugin_dir_url( SCR_PLUGIN_FILE ) );
			$this->define( 'SCR_PLUGIN_SLUG', 'syncifyer-cart-recovery' );
			$this->define( 'SCR_API_BASE_URL', '' );
			$this->define( 'SCR_API_TOKEN', '' );
		}

		/**
		 * Define custom table constants.
		 *
		 * @return void
		 */
		private function define_tables() {
			global $wpdb;

			$this->define( 'SCR_RECOVERED_CARTS_TABLE', $wpdb->prefix . 'syncifyer_recovered_carts' );
		}

		/**
		 * Include required class files.
		 *
		 * @return void
		 */
		private function includes() {
			require_once SCR_PLUGIN_PATH . 'includes/class-scr-api-client.php';
			require_once SCR_PLUGIN_PATH . 'includes/class-scr-install.php';
			require_once SCR_PLUGIN_PATH . 'includes/class-scr-consent.php';
			require_once SCR_PLUGIN_PATH . 'includes/class-scr-cart-capture.php';
			require_once SCR_PLUGIN_PATH . 'includes/class-scr-cron-sync.php';
			require_once SCR_PLUGIN_PATH . 'includes/class-scr-plugin.php';
		}

		/**
		 * Register hooks.
		 *
		 * @return void
		 */
		private function init_hooks() {
			register_activation_hook( SCR_PLUGIN_FILE, array( 'Syncifyer_Cart_Recovery_Install', 'install' ) );
			register_deactivation_hook( SCR_PLUGIN_FILE, array( 'Syncifyer_Cart_Recovery_Install', 'deactivate' ) );

			add_action( 'plugins_loaded', array( $this, 'init_plugin' ), 5 );
		}

		/**
		 * Initialize runtime plugin class.
		 *
		 * @return void
		 */
		public function init_plugin() {
			if ( null !== $this->plugin ) {
				return;
			}

			$this->plugin = new Syncifyer_Cart_Recovery_Plugin();

			/**
			 * Fires after Syncifyer Cart Recovery has loaded.
			 *
			 * @param Syncifyer_Cart_Recovery $plugin Bootstrap instance.
			 */
			do_action( 'syncifyer_cart_recovery_loaded', $this );
		}

		/**
		 * Get runtime plugin object.
		 *
		 * @return Syncifyer_Cart_Recovery_Plugin|null
		 */
		public function plugin() {
			return $this->plugin;
		}

		/**
		 * Define constant if missing.
		 *
		 * @param string $name  Constant name.
		 * @param mixed  $value Constant value.
		 * @return void
		 */
		private function define( $name, $value ) {
			if ( ! defined( $name ) ) {
				define( $name, $value );
			}
		}
	}
}
