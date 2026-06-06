<?php
/**
 * Simple logger for sync events.
 *
 * @package WooCommerce_Zoho_CRM
 */

defined( 'ABSPATH' ) || exit;

class WCZC_Logger {

	const SOURCE = 'woocommerce-zoho-crm';

	/**
	 * Write a log entry.
	 *
	 * @param string $level   Log level.
	 * @param string $message Message.
	 * @param array  $context Extra context.
	 */
	public static function log( $level, $message, $context = array() ) {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array_merge( array( 'source' => self::SOURCE ), $context ) );
			return;
		}

		error_log( sprintf( '[WCZC %s] %s %s', strtoupper( $level ), $message, wp_json_encode( $context ) ) );
	}

	/**
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function info( $message, $context = array() ) {
		self::log( 'info', $message, $context );
	}

	/**
	 * @param string $message Message.
	 * @param array  $context Context.
	 */
	public static function error( $message, $context = array() ) {
		self::log( 'error', $message, $context );
	}
}
