<?php
/**
 * Test-site helpers (dev/e2e only, never shipped).
 *
 * 1. The site's public address (http://localhost:8088) is not reachable from inside the
 *    containers: internal queue visits go to the web container directly, with the public Host.
 * 2. A minimal page cache, off by default, to check that queue visits are not answered from a
 *    cache. Option aitp_e2e_page_cache: '' (off) | 'query' (query strings are separate pages,
 *    like most caches) | 'ignore_query' (query strings ignored: worst case).
 */

add_filter(
	'aitp_queue_request',
	function ( $req ) {
		$p = wp_parse_url( $req['url'] );
		if ( isset( $p['host'] ) && in_array( $p['host'], array( 'localhost', '127.0.0.1' ), true ) ) {
			$req['args']['headers']['Host'] = $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
			$req['url'] = 'http://wordpress' . ( isset( $p['path'] ) ? $p['path'] : '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
		}
		return $req;
	}
);

( function () {
	if ( PHP_SAPI === 'cli' || ( isset( $_SERVER['REQUEST_METHOD'] ) && $_SERVER['REQUEST_METHOD'] !== 'GET' ) ) {
		return;
	}
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '/';
	if ( strpos( $uri, '/wp-' ) !== false || ! empty( $_COOKIE ) ) {
		return;
	}
	$mode = get_option( 'aitp_e2e_page_cache', '' );
	if ( $mode !== 'query' && $mode !== 'ignore_query' ) {
		return;
	}
	$key  = $mode === 'ignore_query' ? strtok( $uri, '?' ) : $uri;
	$file = WP_CONTENT_DIR . '/e2e-cache/' . md5( $key ) . '.html';
	if ( is_readable( $file ) ) {
		header( 'X-E2E-Cache: HIT' );
		readfile( $file );
		exit;
	}
	ob_start(
		function ( $html ) use ( $file ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) && http_response_code() === 200 && strlen( $html ) > 500 ) {
				@mkdir( dirname( $file ), 0777, true );
				@file_put_contents( $file, $html );
			}
			return $html;
		}
	);
} )();

/*
 * 3. Text printed outside the post content (like theme or page-builder text): TranslatePress
 *    links it to no post. Option aitp_e2e_footer: array of [text, page slug or '' for every page].
 * 4. Log of signed internal visits (option aitp_e2e_visit_log on): one line per request URI in
 *    wp-content/e2e-state/visits.log.
 */
add_action(
	'wp_footer',
	function () {
		foreach ( (array) get_option( 'aitp_e2e_footer', array() ) as $f ) {
			if ( is_array( $f ) && ( $f[1] === '' || is_page( $f[1] ) ) ) {
				echo '<p class="aitp-e2e-footer">' . esc_html( $f[0] ) . '</p>';
			}
		}
	}
);
add_action(
	'plugins_loaded',
	function () {
		if ( ! empty( $_SERVER['HTTP_X_AITP_WARM'] ) && get_option( 'aitp_e2e_visit_log' ) ) {
			@mkdir( WP_CONTENT_DIR . '/e2e-state', 0777, true );
			@file_put_contents( WP_CONTENT_DIR . '/e2e-state/visits.log', $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND );
		}
	},
	0
);
