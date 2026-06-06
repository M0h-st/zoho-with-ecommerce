<?php
/**
 * OAuth 2.0 authorization for Zoho CRM (production flow).
 *
 * @package WooCommerce_Zoho_CRM
 */

defined( 'ABSPATH' ) || exit;

class WCZC_OAuth {

	const SCOPE             = 'ZohoCRM.modules.ALL';
	const STATE_TRANSIENT   = 'wczc_oauth_state_';
	const ACTION_CONNECT    = 'wczc_oauth_connect';
	const ACTION_CALLBACK   = 'wczc_oauth_callback';
	const ACTION_DISCONNECT = 'wczc_oauth_disconnect';

	/**
	 * Map Zoho location codes to plugin data center keys.
	 *
	 * @return array<string, string>
	 */
	public static function location_map() {
		return array(
			'us' => 'com',
			'eu' => 'eu',
			'in' => 'in',
			'au' => 'com.au',
			'jp' => 'jp',
			'ca' => 'ca',
			'cn' => 'com.cn',
			'sa' => 'sa',
		);
	}

	/**
	 * Register OAuth handlers.
	 */
	public static function init() {
		add_action( 'admin_post_' . self::ACTION_CONNECT, array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_' . self::ACTION_CALLBACK, array( __CLASS__, 'handle_callback' ) );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( __CLASS__, 'handle_disconnect' ) );
		add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allowed_redirect_hosts' ) );
	}

	/**
	 * Allow OAuth redirects to Zoho account servers.
	 *
	 * @param array $hosts Allowed redirect hosts.
	 * @return array
	 */
	public static function allowed_redirect_hosts( $hosts ) {
		foreach ( array( 'com', 'eu', 'in', 'com.au', 'jp', 'ca', 'com.cn', 'sa' ) as $dc ) {
			$hosts[] = 'accounts.zoho.' . $dc;
		}
		return $hosts;
	}

	/**
	 * Redirect browser to an external Zoho OAuth URL.
	 *
	 * @param string $url Authorization URL.
	 */
	public static function redirect_to_zoho( $url ) {
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- External Zoho OAuth endpoint.
		exit;
	}

	/**
	 * Redirect URI registered in Zoho API Console.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=' . self::ACTION_CALLBACK );
	}

	/**
	 * Zoho accounts host for a data center.
	 *
	 * @param string $data_center Data center code.
	 * @return string
	 */
	public static function accounts_host( $data_center = 'com' ) {
		return 'https://accounts.zoho.' . $data_center;
	}

	/**
	 * Whether a refresh token is stored.
	 *
	 * @param array|null $settings Plugin settings.
	 * @return bool
	 */
	public static function is_connected( $settings = null ) {
		$settings = $settings ?: WCZC_Settings::get_settings();
		return ! empty( $settings['refresh_token'] );
	}

	/**
	 * Whether API app credentials are saved.
	 *
	 * @param array|null $settings Plugin settings.
	 * @return bool
	 */
	public static function has_app_credentials( $settings = null ) {
		$settings = $settings ?: WCZC_Settings::get_settings();
		return ! empty( $settings['client_id'] ) && ! empty( $settings['client_secret'] );
	}

	/**
	 * Resolve data center from Zoho callback params.
	 *
	 * @param string $location        Zoho location query param.
	 * @param string $accounts_server Zoho accounts-server query param.
	 * @return string
	 */
	public static function resolve_data_center( $location, $accounts_server ) {
		$map = self::location_map();

		if ( $location && isset( $map[ strtolower( $location ) ] ) ) {
			return $map[ strtolower( $location ) ];
		}

		if ( $accounts_server && preg_match( '#https://accounts\.zoho\.([a-z0-9.]+)#i', $accounts_server, $matches ) ) {
			return $matches[1];
		}

		return WCZC_Settings::get_settings()['data_center'] ?? 'com';
	}

	/**
	 * Validate Zoho accounts server URL.
	 *
	 * @param string $accounts_server Accounts server URL.
	 * @return string|false
	 */
	public static function sanitize_accounts_server( $accounts_server ) {
		$accounts_server = esc_url_raw( wp_unslash( $accounts_server ) );
		if ( ! $accounts_server || ! preg_match( '#^https://accounts\.zoho\.[a-z0-9.]+/?$#i', $accounts_server ) ) {
			return false;
		}
		return untrailingslashit( $accounts_server );
	}

	/**
	 * Build Zoho authorization URL.
	 *
	 * @param array $settings Plugin settings.
	 * @return string|WP_Error
	 */
	public static function authorization_url( array $settings ) {
		if ( empty( $settings['client_id'] ) || empty( $settings['client_secret'] ) ) {
			return new WP_Error( 'wczc_missing_credentials', __( 'Enter Client ID and Client Secret before connecting.', 'woocommerce-zoho-crm' ) );
		}

		$state        = bin2hex( random_bytes( 16 ) );
		$redirect_uri = self::redirect_uri();

		set_transient(
			self::STATE_TRANSIENT . $state,
			array(
				'user_id'      => get_current_user_id(),
				'redirect_uri' => $redirect_uri,
			),
			15 * MINUTE_IN_SECONDS
		);

		$params = array(
			'scope'         => self::SCOPE,
			'client_id'     => $settings['client_id'],
			'response_type' => 'code',
			'access_type'   => 'offline',
			'redirect_uri'  => $redirect_uri,
			'state'         => $state,
			'prompt'        => 'consent',
		);

		// Zoho multi-DC: start auth on .com; callback includes accounts-server for token exchange.
		return 'https://accounts.zoho.com/oauth/v2/auth?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Exchange authorization code for tokens.
	 *
	 * @param string $code            Authorization code.
	 * @param array  $settings        Plugin settings.
	 * @param string $accounts_server Token endpoint base URL from Zoho callback.
	 * @param string $redirect_uri    Redirect URI used during authorization.
	 * @return array|WP_Error Token payload.
	 */
	public static function exchange_code( $code, array $settings, $accounts_server, $redirect_uri ) {
		$token_url = untrailingslashit( $accounts_server ) . '/oauth/v2/token';

		$response = wp_remote_post(
			$token_url,
			array(
				'timeout' => 30,
				'body'    => array(
					'code'          => $code,
					'client_id'     => $settings['client_id'],
					'client_secret' => $settings['client_secret'],
					'redirect_uri'  => $redirect_uri,
					'grant_type'    => 'authorization_code',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body         = json_decode( wp_remote_retrieve_body( $response ), true );
		$status_code  = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code || empty( $body['refresh_token'] ) ) {
			$error_code = $body['error'] ?? 'oauth_exchange_failed';
			$message    = $body['error'] ?? ( $body['message'] ?? __( 'OAuth token exchange failed.', 'woocommerce-zoho-crm' ) );

			if ( 'invalid_code' === $error_code ) {
				$message = __( 'Authorization code expired or already used. Click "Save & Connect to Zoho CRM" again and approve immediately. Also confirm the Redirect URI in Zoho API Console matches exactly.', 'woocommerce-zoho-crm' );
			}

			WCZC_Logger::error(
				'OAuth token exchange failed',
				array(
					'error'           => $error_code,
					'message'         => $message,
					'token_url'       => $token_url,
					'redirect_uri'    => $redirect_uri,
					'response'        => $body,
				)
			);

			return new WP_Error( 'wczc_oauth_exchange', $message, $body );
		}

		return $body;
	}

	/**
	 * Start OAuth — redirect admin to Zoho.
	 */
	public static function handle_connect() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'woocommerce-zoho-crm' ) );
		}
		check_admin_referer( self::ACTION_CONNECT );

		$settings = WCZC_Settings::get_settings();
		$url      = self::authorization_url( $settings );

		if ( is_wp_error( $url ) ) {
			wp_safe_redirect( WCZC_Settings::admin_url( 'error:' . $url->get_error_message() ) );
			exit;
		}

		self::redirect_to_zoho( $url );
	}

	/**
	 * OAuth callback — store refresh token.
	 */
	public static function handle_callback() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'woocommerce-zoho-crm' ) );
		}

		if ( ! empty( $_GET['error'] ) ) {
			$error = sanitize_text_field( wp_unslash( $_GET['error'] ) );
			wp_safe_redirect( WCZC_Settings::admin_url( 'error:' . $error ) );
			exit;
		}

		$state  = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$stored = $state ? get_transient( self::STATE_TRANSIENT . $state ) : false;
		if ( $state ) {
			delete_transient( self::STATE_TRANSIENT . $state );
		}

		$stored_user = is_array( $stored ) ? (int) ( $stored['user_id'] ?? 0 ) : (int) $stored;
		$redirect_uri = is_array( $stored ) ? ( $stored['redirect_uri'] ?? self::redirect_uri() ) : self::redirect_uri();

		if ( ! $state || ! $stored || $stored_user !== get_current_user_id() ) {
			wp_safe_redirect( WCZC_Settings::admin_url( 'error:' . __( 'Invalid OAuth state. Click "Save & Connect to Zoho CRM" again.', 'woocommerce-zoho-crm' ) ) );
			exit;
		}

		$code = isset( $_GET['code'] ) ? wp_unslash( $_GET['code'] ) : '';
		$code = is_string( $code ) ? trim( $code ) : '';
		if ( ! $code ) {
			wp_safe_redirect( WCZC_Settings::admin_url( 'error:' . __( 'Authorization code missing.', 'woocommerce-zoho-crm' ) ) );
			exit;
		}

		$location        = isset( $_GET['location'] ) ? sanitize_text_field( wp_unslash( $_GET['location'] ) ) : '';
		$accounts_server = isset( $_GET['accounts-server'] ) ? wp_unslash( $_GET['accounts-server'] ) : '';
		$accounts_server = self::sanitize_accounts_server( $accounts_server );

		if ( ! $accounts_server ) {
			$settings        = WCZC_Settings::get_settings();
			$accounts_server = self::accounts_host( $settings['data_center'] ?? 'com' );
		}

		$settings = WCZC_Settings::get_settings();
		$tokens   = self::exchange_code( $code, $settings, $accounts_server, $redirect_uri );

		if ( is_wp_error( $tokens ) ) {
			wp_safe_redirect( WCZC_Settings::admin_url( 'error:' . $tokens->get_error_message() ) );
			exit;
		}

		$data_center = self::resolve_data_center( $location, $accounts_server );

		$settings['refresh_token'] = $tokens['refresh_token'];
		$settings['connected']     = 'yes';
		$settings['connected_at']  = current_time( 'mysql' );
		$settings['data_center']   = $data_center;

		if ( ! empty( $tokens['api_domain'] ) ) {
			$settings['api_domain'] = esc_url_raw( $tokens['api_domain'] );
		}

		WCZC_Settings::update_settings( $settings );
		delete_transient( WCZC_Zoho_API::TOKEN_TRANSIENT );

		if ( ! empty( $tokens['access_token'] ) ) {
			set_transient(
				WCZC_Zoho_API::TOKEN_TRANSIENT,
				$tokens['access_token'],
				(int) ( $tokens['expires_in'] ?? 3600 ) - 60
			);
		}

		wp_safe_redirect( WCZC_Settings::admin_url( 'success:' . __( 'Zoho CRM connected successfully.', 'woocommerce-zoho-crm' ) ) );
		exit;
	}

	/**
	 * Remove stored OAuth tokens.
	 */
	public static function handle_disconnect() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'woocommerce-zoho-crm' ) );
		}
		check_admin_referer( self::ACTION_DISCONNECT );

		$settings = WCZC_Settings::get_settings();
		$settings['refresh_token'] = '';
		$settings['connected']     = 'no';
		$settings['connected_at']  = '';
		$settings['api_domain']    = '';

		WCZC_Settings::update_settings( $settings );
		delete_transient( WCZC_Zoho_API::TOKEN_TRANSIENT );

		wp_safe_redirect( WCZC_Settings::admin_url( 'success:' . __( 'Zoho CRM disconnected.', 'woocommerce-zoho-crm' ) ) );
		exit;
	}
}
