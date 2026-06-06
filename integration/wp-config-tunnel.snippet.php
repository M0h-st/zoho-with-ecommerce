<?php
/**
 * Paste into wp-config.php (above "stop editing") for Cloudflare tunnel support.
 * wp-config.php is gitignored — keep this snippet in the repo as reference.
 */

if ( ! defined( 'WP_HOME' ) && ! defined( 'WP_SITEURL' ) ) {
	$tunnel_hosts = array();
	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ) {
		$tunnel_hosts[] = strtolower( (string) $_SERVER['HTTP_X_FORWARDED_HOST'] );
	}
	if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
		$tunnel_hosts[] = strtolower( (string) $_SERVER['HTTP_HOST'] );
	}
	foreach ( $tunnel_hosts as $tunnel_host ) {
		$tunnel_host = preg_replace( '/:\d+$/', '', $tunnel_host );
		if ( str_ends_with( $tunnel_host, '.trycloudflare.com' ) ) {
			$_SERVER['HTTPS'] = 'on';
			define( 'WP_HOME', 'https://' . $tunnel_host );
			define( 'WP_SITEURL', 'https://' . $tunnel_host );
			break;
		}
	}
}
