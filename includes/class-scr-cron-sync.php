<?php
/**
 * Cron sync class.
 *
 * @package SyncifyerCartRecovery
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles cron-based batch sync for captured carts.
 */
class Syncifyer_Cart_Recovery_Cron_Sync {

	/**
	 * Cron hook name.
	 *
	 * @var string
	 */
	protected $cron_hook = 'scr_sync_pending_carts';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->init_hooks();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function init_hooks() {
		add_filter( 'cron_schedules', array( $this, 'register_schedule' ) );
		add_action( 'init', array( $this, 'maybe_schedule_event' ) );
		add_action( $this->cron_hook, array( $this, 'process_batch' ) );
	}

	/**
	 * Register five minute schedule.
	 *
	 * @param array $schedules Existing schedules.
	 * @return array
	 */
	public function register_schedule( $schedules ) {
		if ( empty( $schedules['scr_every_five_minutes'] ) ) {
			$schedules['scr_every_five_minutes'] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => __( 'Every Five Minutes', 'syncifyer-cart-recovery' ),
			);
		}

		return $schedules;
	}

	/**
	 * Schedule cron event if missing.
	 *
	 * @return void
	 */
	public function maybe_schedule_event() {
		if ( wp_next_scheduled( $this->cron_hook ) ) {
			return;
		}

		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'scr_every_five_minutes', $this->cron_hook );
	}

	/**
	 * Process a batch of pending carts.
	 *
	 * @return void
	 */
	public function process_batch() {
		$records = $this->get_pending_records();

		if ( empty( $records ) ) {
			return;
		}

		$response = $this->send_batch( $records );
		$handled  = $this->parse_response( $response, $records );

		$this->mark_synced_records( $handled['successful_ids'] );
		$this->mark_failed_records( $handled['failed_ids'], $handled['error_message'] );
	}

	/**
	 * Fetch pending records.
	 *
	 * @return array
	 */
	protected function get_pending_records() {
		global $wpdb;

		$batch_size = (int) apply_filters( 'scr_sync_batch_size', 10 );

		$query = $wpdb->prepare(
			"SELECT * FROM " . SCR_RECOVERED_CARTS_TABLE . " WHERE sync_status = %s AND status = %s ORDER BY updated_at ASC LIMIT %d",
			'pending',
			'pending',
			max( 1, $batch_size )
		);

		return (array) $wpdb->get_results( $query, ARRAY_A );
	}

	/**
	 * Send carts batch to remote API.
	 *
	 * @param array $records Pending cart records.
	 * @return array|WP_Error
	 */
	protected function send_batch( array $records ) {
		$settings = get_option( 'scr_settings', array() );
		$url      = $this->get_endpoint_url( $settings );

		if ( empty( $url ) ) {
			return new WP_Error( 'scr_missing_api_url', __( 'API base URL is not configured.', 'syncifyer-cart-recovery' ) );
		}

		$payload = array(
			'site_url'      => home_url( '/' ),
			'timestamp_utc' => gmdate( 'c' ),
			'carts'         => $this->prepare_payload_records( $records ),
		);

		$args = array(
			'timeout'     => ! empty( $settings['api_timeout'] ) ? max( 1, absint( $settings['api_timeout'] ) ) : 15,
			'redirection' => 3,
			'blocking'    => true,
			'headers'     => $this->get_request_headers( $settings ),
			'body'        => wp_json_encode( $payload ),
		);

		if ( false === $args['body'] ) {
			return new WP_Error( 'scr_invalid_payload', __( 'Cart sync payload could not be encoded.', 'syncifyer-cart-recovery' ) );
		}

		return wp_remote_post( $url, $args );
	}

	/**
	 * Prepare payload records for sync.
	 *
	 * @param array $records Pending records.
	 * @return array
	 */
	protected function prepare_payload_records( array $records ) {
		$payload = array();

		foreach ( $records as $record ) {
			$payload[] = array(
				'id'          => isset( $record['id'] ) ? absint( $record['id'] ) : 0,
				'cart_id'     => isset( $record['cart_id'] ) ? sanitize_text_field( $record['cart_id'] ) : '',
				'session_id'  => isset( $record['session_id'] ) ? sanitize_text_field( $record['session_id'] ) : '',
				'email'       => isset( $record['email'] ) ? sanitize_email( $record['email'] ) : '',
				'phone'       => isset( $record['phone'] ) ? sanitize_text_field( $record['phone'] ) : '',
				'consent'     => isset( $record['consent'] ) ? absint( $record['consent'] ) : 0,
				'cart_total'  => isset( $record['cart_total'] ) ? (float) $record['cart_total'] : 0,
				'currency'    => isset( $record['currency'] ) ? sanitize_text_field( $record['currency'] ) : '',
				'status'      => isset( $record['status'] ) ? sanitize_text_field( $record['status'] ) : 'pending',
				'created_at'  => isset( $record['created_at'] ) ? sanitize_text_field( $record['created_at'] ) : '',
				'updated_at'  => isset( $record['updated_at'] ) ? sanitize_text_field( $record['updated_at'] ) : '',
				'products'    => $this->decode_cart_data( isset( $record['cart_data'] ) ? $record['cart_data'] : '' ),
			);
		}

		return $payload;
	}

	/**
	 * Parse remote API response into success and failure groups.
	 *
	 * @param array|WP_Error $response Remote response.
	 * @param array          $records  Pending records.
	 * @return array
	 */
	protected function parse_response( $response, array $records ) {
		$record_ids = wp_list_pluck( $records, 'id' );

		if ( is_wp_error( $response ) ) {
			return array(
				'successful_ids' => array(),
				'failed_ids'     => array_map( 'absint', $record_ids ),
				'error_message'  => sanitize_text_field( $response->get_error_message() ),
			);
		}

		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( $status_code < 200 || $status_code >= 300 ) {
			return array(
				'successful_ids' => array(),
				'failed_ids'     => array_map( 'absint', $record_ids ),
				'error_message'  => sanitize_text_field( wp_remote_retrieve_response_message( $response ) ),
			);
		}

		if ( ! is_array( $data ) ) {
			return array(
				'successful_ids' => array_map( 'absint', $record_ids ),
				'failed_ids'     => array(),
				'error_message'  => '',
			);
		}

		$successful_ids = array();
		$failed_ids     = array();
		$error_message  = ! empty( $data['message'] ) ? sanitize_text_field( $data['message'] ) : '';

		if ( ! empty( $data['results'] ) && is_array( $data['results'] ) ) {
			foreach ( $data['results'] as $result ) {
				$record_id = isset( $result['id'] ) ? absint( $result['id'] ) : 0;

				if ( ! $record_id && ! empty( $result['cart_id'] ) ) {
					$record_id = $this->find_record_id_by_cart_id( $records, $result['cart_id'] );
				}

				if ( ! $record_id ) {
					continue;
				}

				if ( ! empty( $result['success'] ) ) {
					$successful_ids[] = $record_id;
				} else {
					$failed_ids[] = $record_id;
				}
			}
		} elseif ( ! empty( $data['synced_ids'] ) && is_array( $data['synced_ids'] ) ) {
			$successful_ids = array_map( 'absint', $data['synced_ids'] );
			$failed_ids     = array_diff( array_map( 'absint', $record_ids ), $successful_ids );
		} else {
			$successful_ids = array_map( 'absint', $record_ids );
		}

		if ( ! empty( $successful_ids ) || ! empty( $failed_ids ) ) {
			$processed_ids = array_unique( array_merge( $successful_ids, $failed_ids ) );
			$missing_ids   = array_diff( array_map( 'absint', $record_ids ), array_map( 'absint', $processed_ids ) );

			if ( ! empty( $missing_ids ) ) {
				$failed_ids = array_merge( $failed_ids, $missing_ids );
			}
		}

		return array(
			'successful_ids' => array_values( array_unique( array_filter( $successful_ids ) ) ),
			'failed_ids'     => array_values( array_unique( array_filter( $failed_ids ) ) ),
			'error_message'  => $error_message,
		);
	}

	/**
	 * Mark successful records as synced.
	 *
	 * @param array $record_ids Successful record IDs.
	 * @return void
	 */
	protected function mark_synced_records( array $record_ids ) {
		global $wpdb;

		if ( empty( $record_ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $record_ids ), '%d' ) );
		$params       = array_merge(
			array(
				'synced',
				'synced',
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				'',
			),
			array_map( 'absint', $record_ids )
		);

		$query = $wpdb->prepare(
			"UPDATE " . SCR_RECOVERED_CARTS_TABLE . " SET sync_status = %s, status = %s, synced_at = %s, updated_at = %s, error_message = %s WHERE id IN ({$placeholders})",
			$params
		);

		$wpdb->query( $query );
	}

	/**
	 * Mark failed records for retry.
	 *
	 * @param array  $record_ids    Failed record IDs.
	 * @param string $error_message Optional error message.
	 * @return void
	 */
	protected function mark_failed_records( array $record_ids, $error_message = '' ) {
		global $wpdb;

		if ( empty( $record_ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $record_ids ), '%d' ) );
		$params       = array_merge(
			array(
				current_time( 'mysql', true ),
				current_time( 'mysql', true ),
				sanitize_text_field( $error_message ),
			),
			array_map( 'absint', $record_ids )
		);

		$query = $wpdb->prepare(
			"UPDATE " . SCR_RECOVERED_CARTS_TABLE . " SET retry_count = retry_count + 1, last_attempt_at = %s, updated_at = %s, error_message = %s, sync_status = 'pending', status = 'pending' WHERE id IN ({$placeholders})",
			$params
		);

		$wpdb->query( $query );
	}

	/**
	 * Build request headers from settings.
	 *
	 * @param array $settings Plugin settings.
	 * @return array
	 */
	protected function get_request_headers( array $settings ) {
		$headers = array(
			'Content-Type' => 'application/json; charset=utf-8',
			'Accept'       => 'application/json',
		);

		$auth_type   = ! empty( $settings['auth_type'] ) ? sanitize_key( $settings['auth_type'] ) : '';
		$auth_token  = ! empty( $settings['auth_token'] ) ? sanitize_text_field( $settings['auth_token'] ) : '';
		$auth_header = ! empty( $settings['auth_header'] ) ? sanitize_text_field( $settings['auth_header'] ) : 'Authorization';
		$auth_prefix = isset( $settings['auth_prefix'] ) ? sanitize_text_field( $settings['auth_prefix'] ) : 'Bearer';

		if ( empty( $auth_token ) && defined( 'SCR_API_TOKEN' ) ) {
			$auth_token = sanitize_text_field( (string) SCR_API_TOKEN );
		}

		if ( empty( $auth_type ) && ! empty( $auth_token ) ) {
			$auth_type = 'bearer';
		}

		if ( 'bearer' === $auth_type && ! empty( $auth_token ) ) {
			$headers['Authorization'] = trim( $auth_prefix . ' ' . $auth_token );
		}

		if ( 'api_key' === $auth_type && ! empty( $auth_token ) ) {
			$headers[ $auth_header ] = $auth_token;
		}

		return $headers;
	}

	/**
	 * Get remote sync endpoint URL.
	 *
	 * @param array $settings Plugin settings.
	 * @return string
	 */
	protected function get_endpoint_url( array $settings ) {
		$base_url = ! empty( $settings['api_base_url'] ) ? esc_url_raw( $settings['api_base_url'] ) : '';

		if ( empty( $base_url ) && defined( 'SCR_API_BASE_URL' ) ) {
			$base_url = esc_url_raw( (string) SCR_API_BASE_URL );
		}

		if ( empty( $base_url ) ) {
			return '';
		}

		$endpoint = apply_filters( 'scr_cart_sync_endpoint', 'carts/batch-sync' );

		return esc_url_raw( trailingslashit( untrailingslashit( $base_url ) ) . ltrim( sanitize_text_field( $endpoint ), '/' ) );
	}

	/**
	 * Decode stored cart data.
	 *
	 * @param string $cart_data Stored cart JSON.
	 * @return array
	 */
	protected function decode_cart_data( $cart_data ) {
		$data = json_decode( (string) $cart_data, true );

		return is_array( $data ) ? $data : array();
	}

	/**
	 * Find record ID by cart ID.
	 *
	 * @param array  $records Pending records.
	 * @param string $cart_id Cart identifier.
	 * @return int
	 */
	protected function find_record_id_by_cart_id( array $records, $cart_id ) {
		$cart_id = sanitize_text_field( $cart_id );

		foreach ( $records as $record ) {
			if ( ! empty( $record['cart_id'] ) && $cart_id === $record['cart_id'] ) {
				return absint( $record['id'] );
			}
		}

		return 0;
	}
}
