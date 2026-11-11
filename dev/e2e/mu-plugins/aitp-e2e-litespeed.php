<?php
/**
 * dev/e2e only: stand-in for LiteSpeed Cache's purge class when aitp_e2e_fake_litespeed is on.
 */
namespace LiteSpeed;

if ( ! class_exists( '\LiteSpeed\Purge' ) && get_option( 'aitp_e2e_fake_litespeed' ) ) {
	class Purge {
		public static function cls() {
			return new self();
		}
		public function purge_url( $url, $purge2 = false, $quite = false ) {
			@mkdir( WP_CONTENT_DIR . '/e2e-state', 0777, true );
			file_put_contents( WP_CONTENT_DIR . '/e2e-state/litespeed.log', ( $quite ? 'quiet ' : 'NOTICE ' ) . $url . "\n", FILE_APPEND );
			return true;
		}
		public static function purge_all_lscache( $reason = false ) {
			@mkdir( WP_CONTENT_DIR . '/e2e-state', 0777, true );
			file_put_contents( WP_CONTENT_DIR . '/e2e-state/litespeed.log', "ALL\n", FILE_APPEND );
		}
	}
}
