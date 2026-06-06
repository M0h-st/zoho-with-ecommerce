<?php
/**
 * Admin settings and connection management.
 *
 * @package WooCommerce_Zoho_CRM
 */

defined( 'ABSPATH' ) || exit;

class WCZC_Settings {

	/**
	 * Register admin hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect_after_activation' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_setup_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WCZC_PLUGIN_FILE ), array( __CLASS__, 'plugin_action_links' ) );
		add_action( 'admin_post_wczc_save_settings', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_wczc_test_connection', array( __CLASS__, 'handle_test_connection' ) );
		add_action( 'admin_post_wczc_sync_latest', array( __CLASS__, 'handle_sync_latest' ) );
	}

	/**
	 * Whether initial setup is still required.
	 *
	 * @param array|null $settings Plugin settings.
	 * @return bool
	 */
	public static function needs_setup( $settings = null ) {
		$settings = $settings ?: self::get_settings();
		return ! WCZC_OAuth::is_connected( $settings );
	}

	/**
	 * Send admin to setup screen right after activation.
	 */
	public static function maybe_redirect_after_activation() {
		if ( ! get_transient( 'wczc_activation_redirect' ) ) {
			return;
		}

		delete_transient( 'wczc_activation_redirect' );

		if ( wp_doing_ajax() || is_network_admin() || isset( $_GET['activate-multi'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		wp_safe_redirect( self::admin_url( 'success:' . __( 'Welcome! Complete the steps below to connect Zoho CRM.', 'woocommerce-zoho-crm' ) ) );
		exit;
	}

	/**
	 * Prompt admin to finish setup until Zoho is connected.
	 */
	public static function render_setup_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'wczc-zoho-crm' === $page ) {
			return;
		}

		$settings = self::get_settings();
		if ( ! self::needs_setup( $settings ) ) {
			return;
		}

		$setup_url = self::admin_url();
		$message   = empty( $settings['client_id'] ) || empty( $settings['client_secret'] )
			? __( 'Add your Zoho Client ID and Client Secret, then click "Save & Connect to Zoho CRM".', 'woocommerce-zoho-crm' )
			: __( 'Credentials saved but Zoho is not authorized yet. Click "Save & Connect to Zoho CRM" on the settings page.', 'woocommerce-zoho-crm' );

		printf(
			'<div class="notice notice-warning is-dismissible"><p><strong>%1$s</strong> %2$s <a href="%3$s" class="button button-primary" style="margin-left:8px;">%4$s</a></p></div>',
			esc_html__( 'Zoho CRM setup required', 'woocommerce-zoho-crm' ),
			esc_html( $message ),
			esc_url( $setup_url ),
			esc_html__( 'Complete setup', 'woocommerce-zoho-crm' )
		);
	}

	/**
	 * @param array $links Plugin action links.
	 * @return array
	 */
	public static function plugin_action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%1$s">%2$s</a>',
			esc_url( self::admin_url() ),
			esc_html__( 'Settings', 'woocommerce-zoho-crm' )
		);

		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'        => 'no',
			'client_id'      => '',
			'client_secret'  => '',
			'refresh_token'  => '',
			'connected'      => 'no',
			'connected_at'   => '',
			'data_center'    => 'com',
			'api_domain'     => '',
			'record_type'    => 'deal',
			'sync_on_status' => 'processing',
			'deal_stage'     => 'Qualification',
			'lead_status'    => 'Not Contacted',
		);
	}

	/**
	 * Get merged plugin settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$settings = wp_parse_args( get_option( 'wczc_settings', array() ), self::defaults() );

		$config_file = WCZC_PLUGIN_DIR . 'local-config.php';
		if ( file_exists( $config_file ) ) {
			$local = include $config_file;
			if ( is_array( $local ) ) {
				foreach ( array( 'client_id', 'client_secret', 'refresh_token', 'data_center' ) as $key ) {
					if ( empty( $settings[ $key ] ) && ! empty( $local[ $key ] ) ) {
						$settings[ $key ] = $local[ $key ];
					}
				}
			}
		}

		return $settings;
	}

	/**
	 * Persist plugin settings.
	 *
	 * @param array $settings Settings array.
	 */
	public static function update_settings( array $settings ) {
		update_option( 'wczc_settings', wp_parse_args( $settings, self::defaults() ) );
	}

	/**
	 * Admin page URL with optional notice.
	 *
	 * @param string $notice Notice payload.
	 * @return string
	 */
	public static function admin_url( $notice = '' ) {
		$url = admin_url( 'admin.php?page=wczc-zoho-crm' );
		if ( $notice ) {
			$url = add_query_arg( 'wczc_notice', rawurlencode( $notice ), $url );
		}
		return $url;
	}

	/**
	 * Add submenu under WooCommerce.
	 */
	public static function register_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Zoho CRM', 'woocommerce-zoho-crm' ),
			__( 'Zoho CRM', 'woocommerce-zoho-crm' ),
			'manage_woocommerce',
			'wczc-zoho-crm',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Settings screen.
	 */
	public static function render_page() {
		$settings       = self::get_settings();
		$connected      = WCZC_OAuth::is_connected( $settings );
		$has_credentials = WCZC_OAuth::has_app_credentials( $settings );
		$redirect       = WCZC_OAuth::redirect_uri();
		$notice         = isset( $_GET['wczc_notice'] ) ? sanitize_text_field( wp_unslash( $_GET['wczc_notice'] ) ) : '';
		$connect_url    = wp_nonce_url( admin_url( 'admin-post.php?action=' . WCZC_OAuth::ACTION_CONNECT ), WCZC_OAuth::ACTION_CONNECT );
		$disconnect_url = wp_nonce_url( admin_url( 'admin-post.php?action=' . WCZC_OAuth::ACTION_DISCONNECT ), WCZC_OAuth::ACTION_DISCONNECT );

		if ( $notice ) {
			$type = 0 === strpos( $notice, 'error:' ) ? 'error' : 'success';
			$text = substr( $notice, strlen( $type ) + 1 );
			printf(
				'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
				esc_attr( $type ),
				esc_html( $text )
			);
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WooCommerce → Zoho CRM', 'woocommerce-zoho-crm' ); ?></h1>
			<p><?php esc_html_e( 'Automatically sync customer orders to Zoho CRM as Contacts and Deals.', 'woocommerce-zoho-crm' ); ?></p>

			<div class="wczc-connection-card" style="background:#fff;border:1px solid #c3c4c7;padding:16px 20px;margin:20px 0;max-width:900px;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Zoho connection', 'woocommerce-zoho-crm' ); ?></h2>
				<p>
					<strong><?php esc_html_e( 'Status:', 'woocommerce-zoho-crm' ); ?></strong>
					<?php if ( $connected ) : ?>
						<span style="color:#008a20;">&#9679; <?php esc_html_e( 'Connected', 'woocommerce-zoho-crm' ); ?></span>
						<?php if ( ! empty( $settings['connected_at'] ) ) : ?>
							<span class="description">(<?php echo esc_html( $settings['connected_at'] ); ?>)</span>
						<?php endif; ?>
					<?php else : ?>
						<span style="color:#b32d2e;">&#9679; <?php esc_html_e( 'Not connected', 'woocommerce-zoho-crm' ); ?></span>
					<?php endif; ?>
				</p>
				<ul style="list-style:none;margin:12px 0;padding:0;line-height:1.9;">
					<li><?php echo $has_credentials ? '&#10003;' : '&#10007;'; ?> <?php esc_html_e( 'Client ID & Secret saved', 'woocommerce-zoho-crm' ); ?></li>
					<li><?php echo $connected ? '&#10003;' : '&#10007;'; ?> <?php esc_html_e( 'Refresh token obtained (via Connect or manual entry)', 'woocommerce-zoho-crm' ); ?></li>
				</ul>
				<?php if ( ! $connected ) : ?>
					<p style="background:#fcf9e8;border-left:4px solid #dba617;padding:10px 12px;">
						<strong><?php esc_html_e( 'Important:', 'woocommerce-zoho-crm' ); ?></strong>
						<?php esc_html_e( 'Saving Client ID and Secret alone does not connect Zoho. You must click "Save & Connect to Zoho CRM" (or paste a Refresh Token below).', 'woocommerce-zoho-crm' ); ?>
					</p>
				<?php endif; ?>
				<p>
					<strong><?php esc_html_e( 'Redirect URI (register in Zoho API Console):', 'woocommerce-zoho-crm' ); ?></strong><br />
					<code style="display:inline-block;margin-top:6px;padding:6px 10px;background:#f6f7f7;"><?php echo esc_html( $redirect ); ?></code>
				</p>
				<p class="description"><?php esc_html_e( 'Must match exactly. In Zoho API Console, enable "Multi DC" if your CRM account is EU, India, Australia, etc.', 'woocommerce-zoho-crm' ); ?></p>
				<?php if ( $connected ) : ?>
					<p>
						<a class="button button-secondary" href="<?php echo esc_url( $disconnect_url ); ?>"><?php esc_html_e( 'Disconnect Zoho CRM', 'woocommerce-zoho-crm' ); ?></a>
						<a class="button button-primary" href="<?php echo esc_url( $connect_url ); ?>"><?php esc_html_e( 'Re-authorize', 'woocommerce-zoho-crm' ); ?></a>
					</p>
				<?php endif; ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'wczc_save_settings' ); ?>
				<input type="hidden" name="action" value="wczc_save_settings" />

				<h2><?php esc_html_e( 'API credentials', 'woocommerce-zoho-crm' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wczc_client_id"><?php esc_html_e( 'Client ID', 'woocommerce-zoho-crm' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="wczc_client_id" name="wczc_settings[client_id]" value="<?php echo esc_attr( $settings['client_id'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wczc_client_secret"><?php esc_html_e( 'Client Secret', 'woocommerce-zoho-crm' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="wczc_client_secret" name="wczc_settings[client_secret]" value="" placeholder="<?php echo esc_attr( $settings['client_secret'] ? '••••••••••••' : '' ); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Leave blank to keep the current secret.', 'woocommerce-zoho-crm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Data center', 'woocommerce-zoho-crm' ); ?></th>
						<td>
							<select name="wczc_settings[data_center]">
								<?php
								$dcs = array(
									'com'    => 'US (.com)',
									'eu'     => 'EU (.eu)',
									'in'     => 'India (.in)',
									'com.au' => 'Australia (.com.au)',
								);
								foreach ( $dcs as $value => $label ) {
									printf(
										'<option value="%1$s" %2$s>%3$s</option>',
										esc_attr( $value ),
										selected( $settings['data_center'], $value, false ),
										esc_html( $label )
									);
								}
								?>
							</select>
							<p class="description"><?php esc_html_e( 'Must match your Zoho CRM account region. Re-connect after changing.', 'woocommerce-zoho-crm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wczc_refresh_token"><?php esc_html_e( 'Refresh Token', 'woocommerce-zoho-crm' ); ?></label></th>
						<td>
							<input type="password" class="regular-text" id="wczc_refresh_token" name="wczc_settings[refresh_token]" value="" placeholder="<?php echo esc_attr( $settings['refresh_token'] ? '••••••••••••' : '' ); ?>" autocomplete="off" />
							<p class="description">
								<?php esc_html_e( 'Optional: paste a refresh token manually, or use "Save & Connect" below for OAuth.', 'woocommerce-zoho-crm' ); ?>
								<?php if ( $connected ) : ?>
									<strong><?php esc_html_e( 'A refresh token is stored.', 'woocommerce-zoho-crm' ); ?></strong>
								<?php endif; ?>
							</p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Sync settings', 'woocommerce-zoho-crm' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable integration', 'woocommerce-zoho-crm' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="wczc_settings[enabled]" value="yes" <?php checked( $settings['enabled'], 'yes' ); ?> />
								<?php esc_html_e( 'Sync orders to Zoho CRM automatically', 'woocommerce-zoho-crm' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'CRM record type', 'woocommerce-zoho-crm' ); ?></th>
						<td>
							<select name="wczc_settings[record_type]">
								<option value="deal" <?php selected( $settings['record_type'], 'deal' ); ?>><?php esc_html_e( 'Deal (linked to Contact)', 'woocommerce-zoho-crm' ); ?></option>
								<option value="lead" <?php selected( $settings['record_type'], 'lead' ); ?>><?php esc_html_e( 'Lead', 'woocommerce-zoho-crm' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sync when order is', 'woocommerce-zoho-crm' ); ?></th>
						<td>
							<select name="wczc_settings[sync_on_status]">
								<option value="processing" <?php selected( $settings['sync_on_status'], 'processing' ); ?>><?php esc_html_e( 'Processing', 'woocommerce-zoho-crm' ); ?></option>
								<option value="completed" <?php selected( $settings['sync_on_status'], 'completed' ); ?>><?php esc_html_e( 'Completed', 'woocommerce-zoho-crm' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wczc_deal_stage"><?php esc_html_e( 'Deal stage', 'woocommerce-zoho-crm' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="wczc_deal_stage" name="wczc_settings[deal_stage]" value="<?php echo esc_attr( $settings['deal_stage'] ); ?>" />
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wczc_lead_status"><?php esc_html_e( 'Lead status', 'woocommerce-zoho-crm' ); ?></label></th>
						<td>
							<input type="text" class="regular-text" id="wczc_lead_status" name="wczc_settings[lead_status]" value="<?php echo esc_attr( $settings['lead_status'] ); ?>" />
						</td>
					</tr>
				</table>

				<?php
				submit_button( __( 'Save settings', 'woocommerce-zoho-crm' ), 'secondary', 'submit', false );
				if ( ! $connected ) {
					submit_button(
						__( 'Save & Connect to Zoho CRM', 'woocommerce-zoho-crm' ),
						'primary',
						'wczc_save_and_connect',
						false
					);
				}
				?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'Diagnostics', 'woocommerce-zoho-crm' ); ?></h2>
			<p>
				<a class="button button-secondary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wczc_test_connection' ), 'wczc_test_connection' ) ); ?>">
					<?php esc_html_e( 'Test API connection', 'woocommerce-zoho-crm' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=wczc_sync_latest' ), 'wczc_sync_latest' ) ); ?>">
					<?php esc_html_e( 'Sync latest order', 'woocommerce-zoho-crm' ); ?>
				</a>
			</p>
			<p class="description"><?php esc_html_e( 'Logs: WooCommerce → Status → Logs → source "woocommerce-zoho-crm".', 'woocommerce-zoho-crm' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Parse and merge settings from POST input.
	 *
	 * @param array $input Raw POST data.
	 * @return array
	 */
	private static function parse_settings_input( array $input ) {
		$saved  = self::get_settings();
		$fields = array( 'client_id', 'data_center', 'record_type', 'sync_on_status', 'deal_stage', 'lead_status' );

		foreach ( $fields as $field ) {
			if ( isset( $input[ $field ] ) ) {
				$saved[ $field ] = sanitize_text_field( $input[ $field ] );
			}
		}

		$saved['enabled'] = isset( $input['enabled'] ) && 'yes' === $input['enabled'] ? 'yes' : 'no';

		if ( ! empty( $input['client_secret'] ) ) {
			$saved['client_secret'] = sanitize_text_field( $input['client_secret'] );
		}

		if ( ! empty( $input['refresh_token'] ) ) {
			$saved['refresh_token'] = sanitize_text_field( $input['refresh_token'] );
			$saved['connected']     = 'yes';
			$saved['connected_at']  = current_time( 'mysql' );
		}

		return $saved;
	}

	/**
	 * Save settings form (also handles Save & Connect).
	 */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'woocommerce-zoho-crm' ) );
		}
		check_admin_referer( 'wczc_save_settings' );

		$input         = isset( $_POST['wczc_settings'] ) ? wp_unslash( $_POST['wczc_settings'] ) : array();
		$saved         = self::parse_settings_input( $input );
		$should_connect = ! empty( $_POST['wczc_save_and_connect'] );

		if ( $should_connect && ( empty( $saved['client_id'] ) || empty( $saved['client_secret'] ) ) ) {
			wp_safe_redirect( self::admin_url( 'error:' . __( 'Enter Client ID and Client Secret before connecting.', 'woocommerce-zoho-crm' ) ) );
			exit;
		}

		self::update_settings( $saved );
		delete_transient( WCZC_Zoho_API::TOKEN_TRANSIENT );

		if ( $should_connect ) {
			$url = WCZC_OAuth::authorization_url( $saved );
			if ( is_wp_error( $url ) ) {
				wp_safe_redirect( self::admin_url( 'error:' . $url->get_error_message() ) );
				exit;
			}
			WCZC_OAuth::redirect_to_zoho( $url );
		}

		if ( WCZC_OAuth::is_connected( $saved ) ) {
			wp_safe_redirect( self::admin_url( 'success:' . __( 'Settings saved. Zoho CRM is connected.', 'woocommerce-zoho-crm' ) ) );
		} else {
			wp_safe_redirect( self::admin_url( 'success:' . __( 'Settings saved.', 'woocommerce-zoho-crm' ) ) );
		}
		exit;
	}

	/**
	 * Test Zoho connection.
	 */
	public static function handle_test_connection() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'woocommerce-zoho-crm' ) );
		}
		check_admin_referer( 'wczc_test_connection' );

		$api    = new WCZC_Zoho_API();
		$result = $api->test_connection();

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( self::admin_url( 'error:' . $result->get_error_message() ) );
		} else {
			wp_safe_redirect( self::admin_url( 'success:' . __( 'API connection successful.', 'woocommerce-zoho-crm' ) ) );
		}
		exit;
	}

	/**
	 * Sync the most recent order.
	 */
	public static function handle_sync_latest() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'woocommerce-zoho-crm' ) );
		}
		check_admin_referer( 'wczc_sync_latest' );

		$result = WCZC_Order_Handler::sync_latest_order();

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( self::admin_url( 'error:' . $result->get_error_message() ) );
		} else {
			$msg = ! empty( $result['already_synced'] )
				? __( 'Latest order was already synced.', 'woocommerce-zoho-crm' )
				: sprintf(
					/* translators: 1: contact ID, 2: record type, 3: record ID */
					__( 'Synced successfully. Contact %1$s, %2$s %3$s.', 'woocommerce-zoho-crm' ),
					$result['contact_id'],
					ucfirst( $result['record_type'] ),
					$result['record_id']
				);
			wp_safe_redirect( self::admin_url( 'success:' . $msg ) );
		}
		exit;
	}

	/**
	 * Import credentials from legacy local-config.php once.
	 */
	public static function maybe_migrate_local_config() {
		if ( get_option( 'wczc_local_config_migrated' ) ) {
			return;
		}

		$config_file = WCZC_PLUGIN_DIR . 'local-config.php';
		if ( ! file_exists( $config_file ) ) {
			return;
		}

		$local = include $config_file;
		if ( ! is_array( $local ) ) {
			return;
		}

		$settings = self::get_settings();
		foreach ( array( 'client_id', 'client_secret', 'refresh_token', 'data_center' ) as $key ) {
			if ( ! empty( $local[ $key ] ) && empty( $settings[ $key ] ) ) {
				$settings[ $key ] = $local[ $key ];
			}
		}
		if ( ! empty( $settings['refresh_token'] ) ) {
			$settings['connected'] = 'yes';
		}

		self::update_settings( $settings );
		update_option( 'wczc_local_config_migrated', 1 );
	}
}
