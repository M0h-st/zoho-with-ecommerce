<?php
/**
 * Sync WooCommerce orders to Zoho CRM.
 *
 * @package WooCommerce_Zoho_CRM
 */

defined( 'ABSPATH' ) || exit;

class WCZC_Order_Handler {

	const META_SYNCED       = '_wczc_synced';
	const META_CONTACT_ID   = '_wczc_zoho_contact_id';
	const META_RECORD_ID    = '_wczc_zoho_record_id';
	const META_RECORD_TYPE  = '_wczc_zoho_record_type';
	const META_LAST_ERROR   = '_wczc_last_error';

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'maybe_sync_order' ), 20, 1 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'maybe_sync_order' ), 20, 1 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'maybe_sync_on_checkout' ), 30, 1 );

		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'render_order_meta_box' ) );
		add_action( 'wp_ajax_wczc_sync_order', array( __CLASS__, 'ajax_sync_order' ) );

		add_action( 'wczc_sync_order', array( __CLASS__, 'sync_order' ), 10, 1 );
	}

	/**
	 * Sync when order reaches configured status.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function maybe_sync_order( $order_id ) {
		$settings = WCZC_Settings::get_settings();
		if ( ( $settings['enabled'] ?? 'no' ) !== 'yes' ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$target = $settings['sync_on_status'] ?? 'processing';
		if ( $order->get_status() !== $target ) {
			return;
		}

		self::sync_order( $order_id );
	}

	/**
	 * Sync immediately after checkout when target status is processing.
	 *
	 * @param int $order_id Order ID.
	 */
	public static function maybe_sync_on_checkout( $order_id ) {
		$settings = WCZC_Settings::get_settings();
		if ( ( $settings['enabled'] ?? 'no' ) !== 'yes' ) {
			return;
		}
		if ( ( $settings['sync_on_status'] ?? 'processing' ) !== 'processing' ) {
			return;
		}

		self::sync_order( $order_id );
	}

	/**
	 * Sync a single order to Zoho CRM.
	 *
	 * @param int $order_id Order ID.
	 * @return array|WP_Error Result payload.
	 */
	public static function sync_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return new WP_Error( 'wczc_invalid_order', 'Order not found.' );
		}

		if ( 'yes' === $order->get_meta( self::META_SYNCED ) ) {
			return array(
				'already_synced' => true,
				'contact_id'     => $order->get_meta( self::META_CONTACT_ID ),
				'record_id'      => $order->get_meta( self::META_RECORD_ID ),
			);
		}

		$settings = WCZC_Settings::get_settings();
		$api      = new WCZC_Zoho_API( $settings );

		if ( ! $api->is_configured() ) {
			return new WP_Error( 'wczc_not_configured', 'Zoho CRM credentials are not configured.' );
		}

		$email = $order->get_billing_email();
		if ( empty( $email ) ) {
			return new WP_Error( 'wczc_no_email', 'Order has no billing email.' );
		}

		$contact_data = self::build_contact_data( $order );
		$contact_id   = $api->upsert_contact( $contact_data );

		if ( is_wp_error( $contact_id ) ) {
			self::mark_error( $order, $contact_id->get_error_message() );
			return $contact_id;
		}

		$record_type = $settings['record_type'] ?? 'deal';
		$record_data = self::build_record_data( $order, $contact_id, $settings, $record_type );

		if ( 'lead' === $record_type ) {
			$record_id = $api->create_lead( $record_data );
		} else {
			$record_id = $api->create_deal( $record_data );
		}

		if ( is_wp_error( $record_id ) ) {
			self::mark_error( $order, $record_id->get_error_message() );
			return $record_id;
		}

		$order->update_meta_data( self::META_SYNCED, 'yes' );
		$order->update_meta_data( self::META_CONTACT_ID, $contact_id );
		$order->update_meta_data( self::META_RECORD_ID, $record_id );
		$order->update_meta_data( self::META_RECORD_TYPE, $record_type );
		$order->delete_meta_data( self::META_LAST_ERROR );
		$order->save();

		WCZC_Logger::info(
			'Order synced to Zoho CRM',
			array(
				'order_id'    => $order_id,
				'contact_id'  => $contact_id,
				'record_id'   => $record_id,
				'record_type' => $record_type,
			)
		);

		return array(
			'contact_id'  => $contact_id,
			'record_id'   => $record_id,
			'record_type' => $record_type,
		);
	}

	/**
	 * @param WC_Order $order Order.
	 * @return array
	 */
	private static function build_contact_data( WC_Order $order ) {
		$first = $order->get_billing_first_name();
		$last  = $order->get_billing_last_name();

		if ( empty( $first ) && empty( $last ) ) {
			$first = $order->get_formatted_billing_full_name();
		}

		return array_filter(
			array(
				'First_Name'    => $first,
				'Last_Name'     => $last ?: 'Customer',
				'Email'         => $order->get_billing_email(),
				'Phone'         => $order->get_billing_phone(),
				'Mailing_Street' => trim( $order->get_billing_address_1() . ' ' . $order->get_billing_address_2() ),
				'Mailing_City'    => $order->get_billing_city(),
				'Mailing_State'   => $order->get_billing_state(),
				'Mailing_Zip'     => $order->get_billing_postcode(),
				'Mailing_Country' => $order->get_billing_country(),
				'Description'     => sprintf( 'WooCommerce customer. Last order #%d.', $order->get_id() ),
			)
		);
	}

	/**
	 * @param WC_Order $order       Order.
	 * @param string   $contact_id  Zoho contact ID.
	 * @param array    $settings    Plugin settings.
	 * @param string   $record_type deal|lead.
	 * @return array
	 */
	private static function build_record_data( WC_Order $order, $contact_id, array $settings, $record_type ) {
		$products = array();
		foreach ( $order->get_items() as $item ) {
			$products[] = sprintf(
				'%s x %d',
				$item->get_name(),
				$item->get_quantity()
			);
		}

		$title = sprintf(
			'WooCommerce Order #%d - %s',
			$order->get_id(),
			$order->get_formatted_billing_full_name()
		);

		$description = implode( ', ', $products );
		$amount      = (float) $order->get_total();
		$currency    = $order->get_currency();

		if ( 'lead' === $record_type ) {
			return array_filter(
				array(
					'Last_Name'   => $order->get_billing_last_name() ?: $order->get_formatted_billing_full_name(),
					'First_Name'  => $order->get_billing_first_name(),
					'Email'       => $order->get_billing_email(),
					'Phone'       => $order->get_billing_phone(),
					'Company'     => $order->get_billing_company(),
					'Lead_Status' => $settings['lead_status'] ?? 'Not Contacted',
					'Lead_Source' => 'WooCommerce',
					'Description' => $description,
					'Annual_Revenue' => $amount,
				)
			);
		}

		return array(
			'Deal_Name'   => $title,
			'Amount'      => $amount,
			'Stage'       => $settings['deal_stage'] ?? 'Qualification',
			'Closing_Date'=> gmdate( 'Y-m-d', strtotime( '+30 days' ) ),
			'Description' => $description,
			'Contact_Name'=> array( 'id' => $contact_id ),
		);
	}

	/**
	 * @param WC_Order $order   Order.
	 * @param string   $message Error message.
	 */
	private static function mark_error( WC_Order $order, $message ) {
		$order->update_meta_data( self::META_LAST_ERROR, $message );
		$order->save();
		WCZC_Logger::error( 'Order sync failed', array( 'order_id' => $order->get_id(), 'error' => $message ) );
	}

	/**
	 * Show sync status in admin order screen.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function render_order_meta_box( $order ) {
		$synced     = $order->get_meta( self::META_SYNCED );
		$contact_id = $order->get_meta( self::META_CONTACT_ID );
		$record_id  = $order->get_meta( self::META_RECORD_ID );
		$record_type = $order->get_meta( self::META_RECORD_TYPE );
		$error      = $order->get_meta( self::META_LAST_ERROR );
		?>
		<div class="order_data_column" style="clear:both;padding-top:12px;">
			<h3><?php esc_html_e( 'Zoho CRM', 'woocommerce-zoho-crm' ); ?></h3>
			<?php if ( 'yes' === $synced ) : ?>
				<p><strong><?php esc_html_e( 'Status:', 'woocommerce-zoho-crm' ); ?></strong> <?php esc_html_e( 'Synced', 'woocommerce-zoho-crm' ); ?></p>
				<p><strong><?php esc_html_e( 'Contact ID:', 'woocommerce-zoho-crm' ); ?></strong> <?php echo esc_html( $contact_id ); ?></p>
				<p><strong><?php echo esc_html( ucfirst( $record_type ?: 'deal' ) ); ?> ID:</strong> <?php echo esc_html( $record_id ); ?></p>
			<?php elseif ( $error ) : ?>
				<p><strong><?php esc_html_e( 'Status:', 'woocommerce-zoho-crm' ); ?></strong> <?php esc_html_e( 'Failed', 'woocommerce-zoho-crm' ); ?></p>
				<p><?php echo esc_html( $error ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Not synced yet.', 'woocommerce-zoho-crm' ); ?></p>
			<?php endif; ?>
			<p>
				<button type="button" class="button" id="wczc-sync-order" data-order-id="<?php echo esc_attr( $order->get_id() ); ?>">
					<?php esc_html_e( 'Sync to Zoho CRM', 'woocommerce-zoho-crm' ); ?>
				</button>
			</p>
		</div>
		<script>
		(function () {
			const btn = document.getElementById('wczc-sync-order');
			if (!btn) return;
			btn.addEventListener('click', function () {
				btn.disabled = true;
				const data = new FormData();
				data.append('action', 'wczc_sync_order');
				data.append('order_id', btn.dataset.orderId);
				data.append('_wpnonce', '<?php echo esc_js( wp_create_nonce( 'wczc_sync_order' ) ); ?>');
				fetch(ajaxurl, { method: 'POST', credentials: 'same-origin', body: data })
					.then(r => r.json())
					.then(res => { alert(res.data?.message || res.data || 'Done'); location.reload(); })
					.catch(() => { alert('Sync request failed'); btn.disabled = false; });
			});
		})();
		</script>
		<?php
	}

	/**
	 * Manual sync from admin.
	 */
	public static function ajax_sync_order() {
		check_ajax_referer( 'wczc_sync_order' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
		}

		$order_id = absint( $_POST['order_id'] ?? 0 );
		$order    = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => 'Invalid order.' ) );
		}

		$order->delete_meta_data( self::META_SYNCED );
		$order->save();

		$result = self::sync_order( $order_id );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					'Synced. Contact: %s, %s: %s',
					$result['contact_id'],
					ucfirst( $result['record_type'] ),
					$result['record_id']
				),
			)
		);
	}

	/**
	 * Fetch latest order and sync (for scripts/CLI).
	 *
	 * @return array|WP_Error
	 */
	public static function sync_latest_order() {
		$orders = wc_get_orders(
			array(
				'limit'   => 1,
				'orderby' => 'date',
				'order'   => 'DESC',
				'status'  => array( 'processing', 'completed', 'on-hold' ),
			)
		);

		if ( empty( $orders ) ) {
			return new WP_Error( 'wczc_no_orders', 'No orders found.' );
		}

		return self::sync_order( $orders[0]->get_id() );
	}
}
