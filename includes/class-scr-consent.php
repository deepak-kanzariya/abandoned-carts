<?php
/**
 * GDPR consent helper class.
 *
 * @package SyncifyerCartRecovery
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles visitor country and consent rules.
 */
class Syncifyer_Cart_Recovery_Consent {

	/**
	 * EU country codes.
	 *
	 * @var string[]
	 */
	protected $eu_countries = array(
		'AT',
		'BE',
		'BG',
		'HR',
		'CY',
		'CZ',
		'DK',
		'EE',
		'FI',
		'FR',
		'DE',
		'GR',
		'HU',
		'IE',
		'IT',
		'LV',
		'LT',
		'LU',
		'MT',
		'NL',
		'PL',
		'PT',
		'RO',
		'SK',
		'SI',
		'ES',
		'SE',
	);

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
		add_filter( 'woocommerce_checkout_fields', array( $this, 'maybe_add_checkout_consent_field' ) );
	}

	/**
	 * Get visitor country.
	 *
	 * @return string
	 */
	public function get_visitor_country() {
		$cached_country = $this->get_cached_country();

		if ( ! empty( $cached_country ) ) {
			return $cached_country;
		}

		$country = '';
		$ip      = $this->get_visitor_ip();

		if ( ! empty( $ip ) && class_exists( 'WC_Geolocation' ) ) {
			$location = WC_Geolocation::geolocate_ip( $ip, true, false );

			if ( ! empty( $location['country'] ) ) {
				$country = strtoupper( sanitize_text_field( $location['country'] ) );
			}
		}

		/**
		 * Filter detected visitor country.
		 *
		 * @param string $country Country code.
		 * @param string $ip      Visitor IP address.
		 */
		$country = strtoupper( sanitize_text_field( (string) apply_filters( 'scr_detect_visitor_country', $country, $ip ) ) );

		$this->cache_country( $country );

		return $country;
	}

	/**
	 * Determine whether current visitor is from the EU.
	 *
	 * @return bool
	 */
	public function is_eu_visitor() {
		return in_array( $this->get_visitor_country(), $this->eu_countries, true );
	}

	/**
	 * Add consent field to checkout for EU visitors.
	 *
	 * @param array $fields Checkout fields.
	 * @return array
	 */
	public function maybe_add_checkout_consent_field( $fields ) {
		if ( ! $this->is_eu_visitor() ) {
			return $fields;
		}

		if ( ! isset( $fields['billing'] ) || ! is_array( $fields['billing'] ) ) {
			$fields['billing'] = array();
		}

		$fields['billing']['scr_consent'] = array(
			'type'     => 'checkbox',
			'label'    => __( 'Send me updates and cart reminders via WhatsApp & Email', 'syncifyer-cart-recovery' ),
			'required' => false,
			'class'    => array( 'form-row-wide' ),
			'priority' => 999,
			'default'  => $this->get_request_consent_flag(),
		);

		return $fields;
	}

	/**
	 * Get consent flag from request.
	 *
	 * @return int
	 */
	public function get_request_consent_flag() {
		$consent = null;

		if ( isset( $_POST['scr_consent'] ) ) {
			$consent = wp_unslash( $_POST['scr_consent'] );
		} elseif ( isset( $_POST['post_data'] ) ) {
			parse_str( wp_unslash( $_POST['post_data'] ), $posted_data );

			if ( isset( $posted_data['scr_consent'] ) ) {
				$consent = $posted_data['scr_consent'];
			}
		}

		if ( null === $consent && function_exists( 'WC' ) && WC()->session ) {
			$consent = WC()->session->get( 'scr_consent' );
		}

		if ( null === $consent ) {
			$consent = apply_filters( 'scr_default_consent_value', 0 );
		}

		return $this->normalize_boolean_flag( $consent );
	}

	/**
	 * Cache consent in session for reuse.
	 *
	 * @param mixed $consent Consent value.
	 * @return int
	 */
	public function persist_request_consent( $consent ) {
		$normalized = $this->normalize_boolean_flag( $consent );

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'scr_consent', $normalized );
		}

		return $normalized;
	}

	/**
	 * Remove personal data for EU users without consent.
	 *
	 * @param string $email   Email address.
	 * @param string $phone   Phone number.
	 * @param int    $consent Consent flag.
	 * @return array
	 */
	public function sanitize_personal_data( $email, $phone, $consent ) {
		$email   = sanitize_email( $email );
		$phone   = sanitize_text_field( $phone );
		$consent = absint( $consent ) ? 1 : 0;

		if ( $this->is_eu_visitor() && ! $consent ) {
			$email = '';
			$phone = '';
		}

		return array(
			'email'   => $email,
			'phone'   => $phone,
			'consent' => $consent,
		);
	}

	/**
	 * Get visitor IP address.
	 *
	 * @return string
	 */
	protected function get_visitor_ip() {
		if ( class_exists( 'WC_Geolocation' ) ) {
			return sanitize_text_field( WC_Geolocation::get_ip_address() );
		}

		return '';
	}

	/**
	 * Get session/transient cached country.
	 *
	 * @return string
	 */
	protected function get_cached_country() {
		if ( function_exists( 'WC' ) && WC()->session ) {
			$session_country = WC()->session->get( 'scr_visitor_country' );

			if ( ! empty( $session_country ) ) {
				return strtoupper( sanitize_text_field( $session_country ) );
			}
		}

		$transient_key = $this->get_country_cache_key();

		if ( empty( $transient_key ) ) {
			return '';
		}

		$country = get_transient( $transient_key );

		if ( empty( $country ) ) {
			return '';
		}

		return strtoupper( sanitize_text_field( $country ) );
	}

	/**
	 * Persist detected country.
	 *
	 * @param string $country Country code.
	 * @return void
	 */
	protected function cache_country( $country ) {
		$country = strtoupper( sanitize_text_field( $country ) );

		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( 'scr_visitor_country', $country );
		}

		$transient_key = $this->get_country_cache_key();

		if ( ! empty( $transient_key ) ) {
			set_transient( $transient_key, $country, 6 * HOUR_IN_SECONDS );
		}
	}

	/**
	 * Build transient cache key for visitor country.
	 *
	 * @return string
	 */
	protected function get_country_cache_key() {
		$ip = $this->get_visitor_ip();

		if ( empty( $ip ) ) {
			return '';
		}

		return 'scr_country_' . md5( $ip );
	}

	/**
	 * Normalize checkbox-like values to integer flags.
	 *
	 * @param mixed $value Flag value.
	 * @return int
	 */
	protected function normalize_boolean_flag( $value ) {
		if ( is_bool( $value ) ) {
			return $value ? 1 : 0;
		}

		$value = strtolower( sanitize_text_field( (string) $value ) );

		return in_array( $value, array( '1', 'yes', 'true', 'on' ), true ) ? 1 : 0;
	}
}
