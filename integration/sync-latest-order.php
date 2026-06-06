#!/usr/bin/env php
<?php
/**
 * CLI script: fetch latest WooCommerce order and sync to Zoho CRM.
 *
 * Usage (from project root):
 *   php integration/sync-latest-order.php
 *
 * Requires the WooCommerce Zoho CRM plugin to be active and configured.
 */

$root    = dirname( __DIR__ );
$candidates = array(
	$root . '/app/public/wp-load.php',
	dirname( $root ) . '/app/public/wp-load.php',
	$root . '/wp-load.php',
);

$wp_load = null;
foreach ( $candidates as $candidate ) {
	if ( file_exists( $candidate ) ) {
		$wp_load = $candidate;
		break;
	}
}

if ( ! $wp_load ) {
	fwrite( STDERR, "WordPress not found. Run from repo root or set wp-load.php path.\n" );
	exit( 1 );
}

require_once $wp_load;

if ( ! class_exists( 'WCZC_Order_Handler' ) ) {
	fwrite( STDERR, "Activate the WooCommerce Zoho CRM plugin first.\n" );
	exit( 1 );
}

$settings = WCZC_Settings::get_settings();
if ( ( $settings['enabled'] ?? 'no' ) !== 'yes' ) {
	fwrite( STDERR, "Integration is disabled. Enable it under WooCommerce → Zoho CRM.\n" );
	exit( 1 );
}

if ( ! WCZC_OAuth::is_connected( $settings ) ) {
	fwrite( STDERR, "Zoho CRM is not connected. Connect via WooCommerce → Zoho CRM.\n" );
	exit( 1 );
}

echo "Fetching latest order...\n";

$result = WCZC_Order_Handler::sync_latest_order();

if ( is_wp_error( $result ) ) {
	fwrite( STDERR, 'Sync failed: ' . $result->get_error_message() . "\n" );
	exit( 1 );
}

if ( ! empty( $result['already_synced'] ) ) {
	echo "Latest order already synced.\n";
	echo "Contact ID: {$result['contact_id']}\n";
	echo "Record ID:  {$result['record_id']}\n";
	exit( 0 );
}

echo "Sync successful.\n";
echo "Contact ID: {$result['contact_id']}\n";
echo ucfirst( $result['record_type'] ) . " ID: {$result['record_id']}\n";
echo "\nVerify in Zoho CRM: Contacts and Deals (or Leads) modules.\n";
