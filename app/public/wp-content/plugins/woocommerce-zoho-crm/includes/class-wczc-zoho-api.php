<?php
/**
 * Zoho CRM API client (OAuth refresh token flow).
 *
 * @package WooCommerce_Zoho_CRM
 */

defined( 'ABSPATH' ) || exit;

class WCZC_Zoho_API {

	const TOKEN_TRANSIENT = 'wczc_zoho_access_token';

	/**
	 * @var array
	 */
	private $settings;

	/**
	 * @param array|null $settings Plugin settings.
	 */
	public function __construct( $settings = null ) {
		$this->settings = $settings ?: WCZC_Settings::get_settings();
	}

	/**
	 * Whether credentials are configured.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return WCZC_OAuth::has_app_credentials( $this->settings )
			&& ! empty( $this->settings['refresh_token'] );
	}

	/**
	 * Accounts host for OAuth.
	 *
	 * @return string
	 */
	private function accounts_host() {
		$dc = $this->settings['data_center'] ?? 'com';
		return 'https://accounts.zoho.' . $dc;
	}

	/**
	 * CRM API base URL.
	 *
	 * @return string
	 */
	private function api_base() {
		if ( ! empty( $this->settings['api_domain'] ) ) {
			return untrailingslashit( $this->settings['api_domain'] ) . '/crm/v2';
		}

		$dc = $this->settings['data_center'] ?? 'com';
		return 'https://www.zohoapis.' . $dc . '/crm/v2';
	}

	/**
	 * Get or refresh access token.
	 *
	 * @return string|WP_Error
	 */
	public function get_access_token() {
		$cached = get_transient( self::TOKEN_TRANSIENT );
		if ( $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			$this->accounts_host() . '/oauth/v2/token',
			array(
				'timeout' => 30,
				'body'    => array(
					'refresh_token' => $this->settings['refresh_token'],
					'client_id'     => $this->settings['client_id'],
					'client_secret' => $this->settings['client_secret'],
					'grant_type'    => 'refresh_token',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $code || empty( $body['access_token'] ) ) {
			$message = $body['error'] ?? ( $body['message'] ?? 'Token refresh failed.' );
			return new WP_Error( 'wczc_token_error', $message, $body );
		}

		set_transient( self::TOKEN_TRANSIENT, $body['access_token'], (int) $body['expires_in'] - 60 );

		return $body['access_token'];
	}

	/**
	 * Make authenticated CRM request.
	 *
	 * @param string $method   HTTP method.
	 * @param string $endpoint Path after /crm/v2.
	 * @param array  $body     Request body.
	 * @return array|WP_Error
	 */
	private function request( $method, $endpoint, $body = null ) {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url  = $this->api_base() . $endpoint;
		$args = array(
			'method'  => $method,
			'timeout' => 30,
			'headers' => array(
				'Authorization' => 'Zoho-oauthtoken ' . $token,
				'Content-Type'  => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$code    = wp_remote_retrieve_response_code( $response );

		if ( $code >= 400 ) {
			$message = $decoded['message'] ?? 'Zoho CRM request failed.';
			if ( ! empty( $decoded['data'][0]['message'] ) ) {
				$message = $decoded['data'][0]['message'];
			}
			return new WP_Error( 'wczc_api_error', $message, $decoded );
		}

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Find contact by email.
	 *
	 * @param string $email Customer email.
	 * @return string|null Contact ID.
	 */
	public function find_contact_by_email( $email ) {
		$encoded = rawurlencode( $email );
		$result  = $this->request( 'GET', '/Contacts/search?email=' . $encoded );

		if ( is_wp_error( $result ) ) {
			WCZC_Logger::error( 'Contact search failed', array( 'email' => $email, 'error' => $result->get_error_message() ) );
			return null;
		}

		if ( empty( $result['data'][0]['id'] ) ) {
			return null;
		}

		return $result['data'][0]['id'];
	}

	/**
	 * Create or update a contact.
	 *
	 * @param array $contact Contact fields.
	 * @return string|WP_Error Contact ID.
	 */
	public function upsert_contact( array $contact ) {
		$result = $this->request(
			'POST',
			'/Contacts/upsert',
			array(
				'data'                   => array( $contact ),
				'duplicate_check_fields' => array( 'Email' ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$row = $result['data'][0] ?? array();
		if ( ( $row['status'] ?? '' ) === 'error' ) {
			return new WP_Error( 'wczc_contact_error', $row['message'] ?? 'Contact upsert failed.', $row );
		}

		return $row['details']['id'] ?? null;
	}

	/**
	 * Create a Deal linked to a contact.
	 *
	 * @param array $deal Deal fields.
	 * @return string|WP_Error Deal ID.
	 */
	public function create_deal( array $deal ) {
		return $this->create_record( 'Deals', $deal );
	}

	/**
	 * Create a Lead.
	 *
	 * @param array $lead Lead fields.
	 * @return string|WP_Error Lead ID.
	 */
	public function create_lead( array $lead ) {
		return $this->create_record( 'Leads', $lead );
	}

	/**
	 * Create a CRM record.
	 *
	 * @param string $module Module name.
	 * @param array  $record Record data.
	 * @return string|WP_Error Record ID.
	 */
	private function create_record( $module, array $record ) {
		$result = $this->request(
			'POST',
			'/' . $module,
			array(
				'data' => array( $record ),
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$row = $result['data'][0] ?? array();
		if ( ( $row['status'] ?? '' ) === 'error' ) {
			return new WP_Error( 'wczc_record_error', $row['message'] ?? 'Record creation failed.', $row );
		}

		return $row['details']['id'] ?? null;
	}

	/**
	 * Test API connectivity using Contacts module (covered by ZohoCRM.modules.ALL).
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		$token = $this->get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$result = $this->request( 'GET', '/Contacts?per_page=1&fields=id' );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}
}
