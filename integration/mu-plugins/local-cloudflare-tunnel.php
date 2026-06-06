<?php
/**
 * Rewrite WordPress URLs when the site is accessed via a Cloudflare quick tunnel.
 *
 * Without this, CSS/JS load from ecommerce-with-zoho.local and the page looks like plain HTML.
 *
 * @package Local_Cloudflare_Tunnel
 */

defined( 'ABSPATH' ) || exit;

/**
 * @return string|null Tunnel host, or null when not on a tunnel.
 */
function lct_tunnel_host() {
	static $host = null;
	static $resolved = false;

	if ( $resolved ) {
		return $host;
	}

	$resolved = true;
	$candidates = array();

	if ( ! empty( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ) {
		$candidates[] = strtolower( wp_unslash( $_SERVER['HTTP_X_FORWARDED_HOST'] ) );
	}

	if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
		$candidates[] = strtolower( wp_unslash( $_SERVER['HTTP_HOST'] ) );
	}

	foreach ( $candidates as $candidate ) {
		$candidate = preg_replace( '/:\d+$/', '', $candidate );
		if ( str_ends_with( $candidate, '.trycloudflare.com' ) || str_ends_with( $candidate, '.cloudflare.com' ) ) {
			$host = $candidate;
			break;
		}
	}

	return $host;
}

/**
 * @param string $url Site URL from the database.
 * @return string
 */
function lct_tunnel_site_url( $url ) {
	$tunnel_host = lct_tunnel_host();
	if ( ! $tunnel_host ) {
		return $url;
	}

	return 'https://' . $tunnel_host;
}

add_filter( 'option_siteurl', 'lct_tunnel_site_url' );
add_filter( 'option_home', 'lct_tunnel_site_url' );
add_filter( 'pre_option_siteurl', 'lct_tunnel_pre_option_siteurl', 10, 1 );
add_filter( 'pre_option_home', 'lct_tunnel_pre_option_siteurl', 10, 1 );

/**
 * Short-circuit option lookup on tunnel requests.
 *
 * @param mixed $pre Existing pre-filter value.
 * @return mixed
 */
function lct_tunnel_pre_option_siteurl( $pre ) {
	$tunnel_host = lct_tunnel_host();
	if ( ! $tunnel_host ) {
		return $pre;
	}

	return 'https://' . $tunnel_host;
}
