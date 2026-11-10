<?php
/**
 * Clears cached copies of pages whose translations changed, for the page cache plugins
 * that offer a per-address purge. CDN caches outside WordPress are not reached; the
 * admin pages remind to clear them.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Cache_Purge {

	/**
	 * @param string[] $urls Page addresses.
	 */
	public static function urls( array $urls ) {
		$urls = array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );
		if ( ! $urls ) {
			return;
		}
		foreach ( $urls as $url ) {
			do_action( 'litespeed_purge_url', $url ); // LiteSpeed Cache.
			if ( function_exists( 'w3tc_flush_url' ) ) {
				w3tc_flush_url( $url ); // W3 Total Cache.
			}
			if ( function_exists( 'wpsc_delete_url_cache' ) ) {
				wpsc_delete_url_cache( $url ); // WP Super Cache.
			}
		}
		if ( function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( $urls ); // WP Rocket.
		}
		/**
		 * Pages whose translations changed. Hook here to purge other caches (CDN APIs).
		 *
		 * @param string[] $urls Page addresses.
		 */
		do_action( 'aitp_purge_urls', $urls );
	}
}
