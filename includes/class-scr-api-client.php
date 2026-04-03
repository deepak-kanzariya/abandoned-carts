<?php
/**
 * API client class.
 *
 * @package SyncifyerCartRecovery
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles outbound API requests.
 */
class Syncifyer_Cart_Recovery_API_Client {

	/**
	 * Default request timeout.
	 *
	 * @var int
	 */
	protected $timeout = 15;

	/**
	 * Send a GET request.
	 *
	 * @param string $endpoint Relative endpoint or absolute URL.
	 * @param array  $query    Optional query args.
	 * @param array  $args     Optional wp_remote_request args.
	 * @return array
	 */
	public function get( $endpoint, array $query = array(), array $args = array() ) {
		if ( ! empty( $query ) ) {
			$endpoint = add_query_arg( $this->sanitize_payload( $query ), $endpoint );
		}

		return $this->request( 'GET', $endpoint, array(), $args );
	}

	/**
	 * Send a POST request.
	 *
	 * @param string $endpoint Relative endpoint or absolute URL.
	 * @param array  $payload  Request payload.
	 * @param array  $args     Optional wp_remote_request args.
	 * @return array
	 */
	public function post( $endpoint, array $payload = array(), array $args = array() ) {
		return $this->request( 'POST', $endpoint, $payload, $args );
	}

	/**
	 * Send a request to the configured API.
	 *
	 * @param string $method   HTTP method.
	 * @param string $endpoint Relative endpoint or absolute URL.
	 * @param array  $payload  Request payload.
	 * @param array  $args     Optional wp_remote_request args.
	 * @return array
	 */
	public function request( $method, $endpoint, array $payload = array(), array $args = array() ) {
		$method = strtoupper( sanitize_text_field( $method ) );
		$url    = $this->build_url( $endpoint );

		if ( empty( $url ) ) {
			return $this->error_response(
				'invalid_url',
				'API URL could not be resolved.',
				array(
					'endpoint' => $endpoint,
				)
			);
		}

		$request_args = wp_parse_args(
			$args,
			array(
				'method'      => $method,
				'timeout'     => (int) apply_filters( 'scr_api_timeout', $this->get_timeout(), $method, $endpoint ),
				'redirection' => 3,
				'blocking'    => true,
				'headers'     => array(
					'Content-Type' => 'application/json; charset=utf-8',
					'Accept'       => 'application/json',
				),
				'user-agent'  => 'syncifyer-cart-recovery/' . sanitize_text_field( SCR_PLUGIN_VERSION ) . '; ' . esc_url_raw( home_url( '/' ) ),
			)
		);

		$request_args['headers'] = $this->get_auth_headers( $request_args['headers'] );

		$payload = $this->sanitize_payload( $payload );

		if ( ! empty( $payload ) && ! in_array( $method, array( 'GET', 'HEAD' ), true ) ) {
			$request_args['body']        = wp_json_encode( $payload );
			$request_args['data_format'] = 'body';

			if ( false === $request_args['body'] ) {
				return $this->error_response(
					'invalid_payload',
					'API request payload could not be encoded.',
					array(
						'endpoint' => $endpoint,
					)
				);
			}
		}

		/**
		 * Filter outbound request args.
		 *
		 * @param array  $request_args Request args.
		 * @param string $method       HTTP method.
		 * @param string $url          Resolved URL.
		 * @param array  $payload      Sanitized payload.
		 */
		$request_args = (array) apply_filters( 'scr_api_request_args', $request_args, $method, $url, $payload );

		$response = wp_remote_request( $url, $request_args );

		if ( is_wp_error( $response ) ) {
			return $this->error_response(
				$response->get_error_code(),
				$response->get_error_message(),
				array(
					'url'    => $url,
					'method' => $method,
				)
			);
		}

		return $this->normalize_response( $response, $url, $method );
	}

	/**
	 * Build full request URL.
	 *
	 * @param string $endpoint Relative endpoint or absolute URL.
	 * @return string
	 */
	protected function build_url( $endpoint ) {
		$endpoint = trim( (string) $endpoint );

		if ( empty( $endpoint ) ) {
			return '';
		}

		if ( 0 === strpos( $endpoint, 'http://' ) || 0 === strpos( $endpoint, 'https://' ) ) {
			return esc_url_raw( $endpoint );
		}

		$base_url = $this->get_api_base_url();
		$base_url = trailingslashit( untrailingslashit( $base_url ) );
		$endpoint = ltrim( $endpoint, '/' );

		if ( empty( $base_url ) ) {
			return '';
		}

		return esc_url_raw( $base_url . $endpoint );
	}

	/**
	 * Check whether an API base URL is configured.
	 *
	 * @return bool
	 */
	public function has_api_base_url() {
		return '' !== $this->get_api_base_url();
	}

	/**
	 * Get configured API base URL.
	 *
	 * @return string
	 */
	protected function get_api_base_url() {
		$settings = get_option( 'scr_settings', array() );
		$base_url = '';

		if ( ! empty( $settings['api_base_url'] ) ) {
			$base_url = (string) $settings['api_base_url'];
		} elseif ( defined( 'SCR_API_BASE_URL' ) ) {
			$base_url = (string) SCR_API_BASE_URL;
		}

		return esc_url_raw( (string) apply_filters( 'scr_api_base_url', $base_url ) );
	}

	/**
	 * Get configured request timeout.
	 *
	 * @return int
	 */
	protected function get_timeout() {
		$settings = get_option( 'scr_settings', array() );

		if ( ! empty( $settings['api_timeout'] ) ) {
			return max( 1, absint( $settings['api_timeout'] ) );
		}

		return $this->timeout;
	}

	/**
	 * Append authentication headers if configured.
	 *
	 * @param array $headers Existing headers.
	 * @return array
	 */
	protected function get_auth_headers( array $headers ) {
		$settings    = get_option( 'scr_settings', array() );
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

		/**
		 * Filter authentication headers.
		 *
		 * @param array $headers Headers including auth values.
		 */
		return (array) apply_filters( 'scr_api_auth_headers', $headers );
	}

	/**
	 * Convert a raw WP HTTP response into a consistent array.
	 *
	 * @param array  $response Raw response.
	 * @param string $url      Resolved URL.
	 * @param string $method   HTTP method.
	 * @return array
	 */
	protected function normalize_response( array $response, $url, $method ) {
		$status_code = (int) wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );
		$data        = json_decode( $body, true );

		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$data = null;
		}

		$normalized = array(
			'success'     => $status_code >= 200 && $status_code < 300,
			'status_code' => $status_code,
			'message'     => wp_remote_retrieve_response_message( $response ),
			'body'        => $body,
			'data'        => $data,
			'headers'     => wp_remote_retrieve_headers( $response ),
			'url'         => $url,
			'method'      => $method,
		);

		/**
		 * Fires after every API response.
		 *
		 * @param array $normalized Normalized response.
		 * @param array $response   Raw WP HTTP response.
		 */
		do_action( 'scr_api_response', $normalized, $response );

		return $normalized;
	}

	/**
	 * Create a normalized error response.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param array  $context Error context.
	 * @return array
	 */
	protected function error_response( $code, $message, array $context = array() ) {
		return array(
			'success'     => false,
			'status_code' => 0,
			'message'     => sanitize_text_field( (string) $message ),
			'body'        => '',
			'data'        => null,
			'headers'     => array(),
			'error_code'  => sanitize_key( (string) $code ),
			'context'     => $this->sanitize_payload( $context ),
		);
	}

	/**
	 * Sanitize payload recursively.
	 *
	 * @param array $payload Payload data.
	 * @return array
	 */
	protected function sanitize_payload( array $payload ) {
		$sanitized = array();

		foreach ( $payload as $key => $value ) {
			$clean_key = is_string( $key ) ? sanitize_key( $key ) : $key;

			if ( is_array( $value ) ) {
				$sanitized[ $clean_key ] = $this->sanitize_payload( $value );
				continue;
			}

			if ( is_bool( $value ) || is_null( $value ) ) {
				$sanitized[ $clean_key ] = $value;
				continue;
			}

			if ( is_numeric( $value ) ) {
				$sanitized[ $clean_key ] = 0 + $value;
				continue;
			}

			if ( false !== strpos( (string) $clean_key, 'url' ) ) {
				$sanitized[ $clean_key ] = esc_url_raw( (string) $value );
				continue;
			}

			if ( false !== strpos( (string) $clean_key, 'email' ) ) {
				$sanitized[ $clean_key ] = sanitize_email( (string) $value );
				continue;
			}

			$sanitized[ $clean_key ] = sanitize_text_field( (string) $value );
		}

		return $sanitized;
	}
}
