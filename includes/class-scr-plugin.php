<?php
/**
 * Core plugin runtime class.
 *
 * @package SyncifyerCartRecovery
 */

defined( 'ABSPATH' ) || exit;

/**
 * Main runtime plugin class.
 */
class Syncifyer_Cart_Recovery_Plugin {

	/**
	 * API client.
	 *
	 * @var Syncifyer_Cart_Recovery_API_Client
	 */
	protected $api_client;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->api_client = new Syncifyer_Cart_Recovery_API_Client();
		$this->init_hooks();
	}

	/**
	 * Register runtime hooks.
	 *
	 * @return void
	 */
	public function init_hooks() {
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Load plugin textdomain.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'syncifyer-cart-recovery', false, dirname( SCR_PLUGIN_BASENAME ) . '/languages' );
	}

	/**
	 * Get API client.
	 *
	 * @return Syncifyer_Cart_Recovery_API_Client
	 */
	public function api_client() {
		return $this->api_client;
	}
}
