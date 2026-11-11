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
			// Rehearsal: through the front proxy with the 8 KB header limit (option aitp_e2e_via_proxy).
			$req['url'] = ( get_option( 'aitp_e2e_via_proxy' ) ? 'http://hcdn' : 'http://wordpress' ) . ( isset( $p['path'] ) ? $p['path'] : '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
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

/*
 * 5. Spoiled signed visits (option aitp_e2e_tamper): 'signature' changes the last character,
 *    'format' sends garbage, 'expired' moves the time stamp back, 'replay' marks the nonce as
 *    used before the request is sent. Applied after the routing filter above.
 */
add_filter(
	'aitp_queue_request',
	function ( $req ) {
		$t = get_option( 'aitp_e2e_tamper', '' );
		$h = isset( $req['args']['headers']['X-AITP-Warm'] ) ? (string) $req['args']['headers']['X-AITP-Warm'] : '';
		if ( $t === '' || $h === '' ) {
			return $req;
		}
		$p = explode( '.', $h );
		if ( $t === 'signature' ) {
			$h = substr( $h, 0, -1 ) . ( substr( $h, -1 ) === 'a' ? 'b' : 'a' );
		} elseif ( $t === 'format' ) {
			$h = 'garbage';
		} elseif ( $t === 'expired' && count( $p ) === 3 ) {
			$h = ( (int) $p[0] - 1000 ) . '.' . $p[1] . '.' . $p[2];
		} elseif ( $t === 'replay' && count( $p ) === 3 ) {
			set_transient( 'aitp_used_' . $p[1], 1, 600 );
		}
		$req['args']['headers']['X-AITP-Warm'] = $h;
		return $req;
	},
	20
);

/*
 * 6. Faults around repairs (dev/e2e only):
 *    aitp_e2e_kill_after = N   the WP-CLI process dies right before the (N+1)th change of a
 *                              dictionary row by a repair or rollback (a cut-off request).
 *    aitp_e2e_purge_fault = 1  cache purges throw an exception.
 *    aitp_e2e_fake_litespeed   a stand-in \LiteSpeed\Purge that logs purge_url calls (with the
 *                              "no admin notice" flag) to wp-content/e2e-state/litespeed.log.
 */
add_filter(
	'query',
	function ( $q ) {
		static $n = 0, $max = null, $busy = false;
		if ( PHP_SAPI !== 'cli' || $busy || ! did_action( 'plugins_loaded' ) ) {
			return $q;
		}
		if ( $max === null ) {
			$busy = true; // get_option() itself runs a query.
			$max  = (int) get_option( 'aitp_e2e_kill_after', 0 );
			$busy = false;
		}
		if ( $max > 0 && preg_match( '~^UPDATE `[^`]*trp_dictionary_[^`]*` SET translated = ~', $q ) ) {
			if ( ++$n > $max ) {
				fwrite( STDERR, "e2e: killed before change $n\n" );
				exit( 3 );
			}
		}
		return $q;
	}
);
if ( get_option( 'aitp_e2e_purge_fault' ) ) {
	// Only while the fault test runs: otherwise this hook would count as a second cache plugin.
	add_action(
		'aitp_purge_urls',
		function () {
			throw new RuntimeException( 'e2e: cache plugin failed' );
		}
	);
}
// Header probe (0.6.4 F07): ?aitp_e2e_purge=N hands N page addresses to AITP_Cache_Purge::urls();
// ?aitp_e2e_raw=N calls LiteSpeed's purge_url N times in one request (how 0.6.3 cleared a list);
// ?aitp_e2e_crontick=1 runs the background step as WP-Cron would.
add_action(
	'init',
	function () {
		if ( ! get_option( 'aitp_e2e_purge_probe' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( isset( $_GET['aitp_e2e_crontick'] ) ) {
			// The background step as WP-Cron runs it (wp_doing_cron(): LiteSpeed also stores its header for the next request).
			add_filter( 'wp_doing_cron', '__return_true' );
			wp_send_json( array( 'left' => AITP_Cache_Purge::tick( 10 ) ) );
		}
		$n = isset( $_GET['aitp_e2e_purge'] ) ? (int) $_GET['aitp_e2e_purge'] : ( isset( $_GET['aitp_e2e_raw'] ) ? (int) $_GET['aitp_e2e_raw'] : 0 );
		if ( $n < 1 ) {
			return;
		}
		$urls = array();
		for ( $i = 1; $i <= $n; $i++ ) {
			$urls[] = home_url( '/probe-page-' . $i . '/' );
		}
		if ( isset( $_GET['aitp_e2e_raw'] ) ) {
			foreach ( $urls as $u ) {
				\LiteSpeed\Purge::cls()->purge_url( $u, false, true );
			}
			wp_send_json( array( 'raw' => $n ) );
		}
		wp_send_json( AITP_Cache_Purge::urls( $urls ) );
	},
	99
);
