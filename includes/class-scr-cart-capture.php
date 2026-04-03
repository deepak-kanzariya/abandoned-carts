<?php
/**
 * Cart capture class.
 *
 * @package SyncifyerCartRecovery
 */

defined( 'ABSPATH' ) || exit;

/**
 * Captures WooCommerce carts into local storage.
 */
class Syncifyer_Cart_Recovery_Cart_Capture {

	/**
	 * Consent helper.
	 *
	 * @var Syncifyer_Cart_Recovery_Consent
	 */
	protected $consent_helper;

	/**
	 * Constructor.
	 *
	 * @param Syncifyer_Cart_Recovery_Consent $consent_helper Consent helper instance.
	 */
	public function __construct( Syncifyer_Cart_Recovery_Consent $consent_helper ) {
		$this->consent_helper = $consent_helper;
		$this->init_hooks();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	protected function init_hooks() {
		add_action( 'woocommerce_add_to_cart', array( $this, 'capture_cart' ) );
		add_action( 'woocommerce_cart_updated', array( $this, 'capture_cart' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( $this, 'capture_checkout_posted_data' ) );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'capture_checkout_fields' ), 10, 2 );
	}

	/**
	 * Capture checkout data from order review refresh requests.
	 *
	 * @param string $posted_data Serialized checkout data.
	 * @return void
	 */
	public function capture_checkout_posted_data( $posted_data ) {
		$data = array();

		parse_str( (string) $posted_data, $data );

		$this->persist_checkout_session_data( $data );
		$this->capture_cart();
	}

	/**
	 * Capture checkout fields after validation.
	 *
	 * @param array    $data   Posted checkout data.
	 * @param WP_Error $errors Validation errors.
	 * @return void
	 */
	public function capture_checkout_fields( $data, $errors ) {
		$this->persist_checkout_session_data( is_array( $data ) ? $data : array() );
		$this->capture_cart();
	}

	/**
	 * Capture current cart state.
	 *
	 * @return void
	 */
	public function capture_cart() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart || ! WC()->session ) {
			return;
		}

		$cart_items = WC()->cart->get_cart();

		if ( empty( $cart_items ) ) {
			return;
		}

		$cart_id    = $this->get_cart_identifier();
		$session_id = $this->get_session_identifier();

		if ( empty( $cart_id ) ) {
			return;
		}

		$consent = $this->consent_helper->get_request_consent_flag();
		$email   = $this->get_customer_email();
		$phone   = $this->get_customer_phone();
		$privacy = $this->consent_helper->sanitize_personal_data( $email, $phone, $consent );
		$now     = current_time( 'mysql', true );

		$data = array(
			'cart_id'       => $cart_id,
			'session_id'    => $session_id,
			'email'         => $privacy['email'],
			'phone'         => $privacy['phone'],
			'consent'       => $privacy['consent'],
			'cart_data'     => wp_json_encode( $this->get_cart_products( $cart_items ) ),
			'cart_total'    => wc_format_decimal( WC()->cart->get_total( 'edit' ), 2 ),
			'currency'      => sanitize_text_field( get_woocommerce_currency() ),
			'status'        => 'pending',
			'sync_status'   => 'pending',
			'retry_count'   => 0,
			'last_attempt_at' => null,
			'synced_at'     => null,
			'error_message' => '',
			'updated_at'    => $now,
		);

		$this->upsert_cart_record( $data, $now );
	}

	/**
	 * Persist checkout data in Woo session.
	 *
	 * @param array $data Checkout data.
	 * @return void
	 */
	protected function persist_checkout_session_data( array $data ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}

		if ( isset( $data['billing_email'] ) ) {
			WC()->session->set( 'scr_checkout_email', sanitize_email( wp_unslash( $data['billing_email'] ) ) );
		}

		if ( isset( $data['billing_phone'] ) ) {
			WC()->session->set( 'scr_checkout_phone', sanitize_text_field( wp_unslash( $data['billing_phone'] ) ) );
		}

		if ( isset( $data['scr_consent'] ) ) {
			$this->consent_helper->persist_request_consent( wp_unslash( $data['scr_consent'] ) );
		}
	}

	/**
	 * Get unique cart identifier.
	 *
	 * @return string
	 */
	protected function get_cart_identifier() {
		$cart_id = WC()->session->get( 'scr_cart_id' );

		if ( empty( $cart_id ) ) {
			$customer_id = WC()->session->get_customer_id();

			if ( ! empty( $customer_id ) ) {
				$cart_id = 'scr_' . sanitize_key( $customer_id );
			} else {
				$cart_id = 'scr_' . sanitize_key( wp_generate_uuid4() );
			}

			WC()->session->set( 'scr_cart_id', $cart_id );
		}

		return sanitize_text_field( $cart_id );
	}

	/**
	 * Get session identifier.
	 *
	 * @return string
	 */
	protected function get_session_identifier() {
		return sanitize_text_field( (string) WC()->session->get_customer_id() );
	}

	/**
	 * Build cart product payload.
	 *
	 * @param array $cart_items WooCommerce cart items.
	 * @return array
	 */
	protected function get_cart_products( array $cart_items ) {
		$products = array();

		foreach ( $cart_items as $cart_item ) {
			$product = isset( $cart_item['data'] ) ? $cart_item['data'] : null;

			if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
				continue;
			}

			$products[] = array(
				'product_id' => $product->get_id(),
				'quantity'   => isset( $cart_item['quantity'] ) ? absint( $cart_item['quantity'] ) : 0,
				'price'      => wc_format_decimal( $product->get_price(), 2 ),
			);
		}

		return $products;
	}

	/**
	 * Get customer email from request/session/customer object.
	 *
	 * @return string
	 */
	protected function get_customer_email() {
		if ( isset( $_POST['billing_email'] ) ) {
			return sanitize_email( wp_unslash( $_POST['billing_email'] ) );
		}

		if ( function_exists( 'WC' ) && WC()->session ) {
			$email = WC()->session->get( 'scr_checkout_email' );

			if ( ! empty( $email ) ) {
				return sanitize_email( $email );
			}
		}

		if ( function_exists( 'WC' ) && WC()->customer ) {
			return sanitize_email( WC()->customer->get_billing_email() );
		}

		return '';
	}

	/**
	 * Get customer phone from request/session/customer object.
	 *
	 * @return string
	 */
	protected function get_customer_phone() {
		if ( isset( $_POST['billing_phone'] ) ) {
			return sanitize_text_field( wp_unslash( $_POST['billing_phone'] ) );
		}

		if ( function_exists( 'WC' ) && WC()->session ) {
			$phone = WC()->session->get( 'scr_checkout_phone' );

			if ( ! empty( $phone ) ) {
				return sanitize_text_field( $phone );
			}
		}

		if ( function_exists( 'WC' ) && WC()->customer ) {
			return sanitize_text_field( WC()->customer->get_billing_phone() );
		}

		return '';
	}

	/**
	 * Insert or update cart record.
	 *
	 * @param array  $data Cart record data.
	 * @param string $now  Current GMT datetime.
	 * @return void
	 */
	protected function upsert_cart_record( array $data, $now ) {
		global $wpdb;

		$existing_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM " . SCR_RECOVERED_CARTS_TABLE . ' WHERE cart_id = %s LIMIT 1',
				$data['cart_id']
			)
		);

		if ( $existing_id ) {
			$wpdb->update(
				SCR_RECOVERED_CARTS_TABLE,
				$data,
				array(
					'id' => absint( $existing_id ),
				),
				array(
					'%s',
					'%s',
					'%s',
					'%s',
					'%d',
					'%s',
					'%f',
					'%s',
					'%s',
					'%s',
					'%d',
					null,
					null,
					'%s',
					'%s',
				),
				array( '%d' )
			);

			return;
		}

		$data['created_at'] = $now;

		$wpdb->insert(
			SCR_RECOVERED_CARTS_TABLE,
			$data,
			array(
				'%s',
				'%s',
				'%s',
				'%s',
				'%d',
				'%s',
				'%f',
				'%s',
				'%s',
				'%s',
				'%d',
				null,
				null,
				'%s',
				'%s',
				'%s',
			)
		);
	}
}
