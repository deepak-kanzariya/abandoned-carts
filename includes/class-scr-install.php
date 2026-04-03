<?php
/**
 * Install class.
 *
 * @package SyncifyerCartRecovery
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles installation, updates, and activation side effects.
 */
class Syncifyer_Cart_Recovery_Install {

	/**
	 * Run plugin install routine.
	 *
	 * @return void
	 */
	public static function install() {
		if ( ! is_blog_installed() ) {
			return;
		}

		self::create_tables();
		self::create_options();
		self::update_version();
		self::maybe_notify_api( 'install' );

		/**
		 * Fires after plugin install routine completes.
		 */
		do_action( 'scr_installed' );
	}

	/**
	 * Run plugin deactivation routine.
	 *
	 * @return void
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'scr_sync_pending_carts' );
		self::maybe_notify_api( 'deactivate' );

		/**
		 * Fires on plugin deactivation.
		 */
		do_action( 'scr_deactivated' );
	}

	/**
	 * Create plugin tables.
	 *
	 * @return void
	 */
	protected static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table_name      = SCR_RECOVERED_CARTS_TABLE;

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			
			cart_id VARCHAR(64) NOT NULL,
			session_id VARCHAR(64) DEFAULT NULL,

			email VARCHAR(190) DEFAULT NULL,
			phone VARCHAR(20) DEFAULT NULL,
			consent TINYINT(1) DEFAULT 0,

			cart_data LONGTEXT NOT NULL,
			cart_total DECIMAL(10,2) DEFAULT 0.00,
			currency VARCHAR(10) DEFAULT 'INR',

			status VARCHAR(20) DEFAULT 'active',

			sync_status VARCHAR(20) DEFAULT 'pending',
			retry_count INT DEFAULT 0,
			last_attempt_at DATETIME DEFAULT NULL,
			synced_at DATETIME DEFAULT NULL,

			error_message TEXT DEFAULT NULL,

			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,

			PRIMARY KEY  (id),
			UNIQUE KEY cart_id (cart_id),
			KEY sync_status (sync_status),
			KEY created_at (created_at),
			KEY email (email)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * Create default plugin options.
	 *
	 * @return void
	 */
	protected static function create_options() {
		add_option(
			'scr_settings',
			array(
				'api_base_url'   => '',
				'auth_type'      => '',
				'auth_token'     => '',
				'auth_header'    => 'Authorization',
				'auth_prefix'    => 'Bearer',
				'api_timeout'    => 15,
				'installed_at'   => gmdate( 'c' ),
			)
		);
	}

	/**
	 * Persist plugin version info.
	 *
	 * @return void
	 */
	protected static function update_version() {
		update_option( 'scr_version', SCR_PLUGIN_VERSION );
		update_option( 'scr_db_version', SCR_DB_VERSION );
	}

	/**
	 * Send optional install/deactivation event to external API.
	 *
	 * @param string $event Event name.
	 * @return void
	 */
	protected static function maybe_notify_api( $event ) {
		global $wp_version;

		$client = new Syncifyer_Cart_Recovery_API_Client();

		if ( ! $client->has_api_base_url() ) {
			return;
		}

		$payload = array(
			'event'          => sanitize_key( $event ),
			'plugin_name'    => 'Syncifyer Cart Recovery',
			'plugin_version' => SCR_PLUGIN_VERSION,
			'db_version'     => SCR_DB_VERSION,
			'site_url'       => home_url( '/' ),
			'admin_email'    => get_option( 'admin_email' ),
			'wp_version'     => (string) $wp_version,
			'timestamp_utc'  => gmdate( 'c' ),
		);

		/**
		 * Filter install event payload before request.
		 *
		 * @param array  $payload Event payload.
		 * @param string $event   Event name.
		 */
		$payload = (array) apply_filters( 'scr_install_event_payload', $payload, $event );

		$response = $client->post( 'plugin-events/' . sanitize_key( $event ), $payload );

		/**
		 * Fires after an install-related API event is sent.
		 *
		 * @param string $event    Event name.
		 * @param array  $payload  Request payload.
		 * @param array  $response Normalized response.
		 */
		do_action( 'scr_install_event_sent', $event, $payload, $response );
	}
}
