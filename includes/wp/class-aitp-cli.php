<?php
/**
 * WP-CLI: wp aitp queue <sync|start|pause|status|run|retry|clear>
 *         wp aitp audit <scan|status|list|repair|rollback|import|ai-check|dismiss>
 *         wp aitp autofix <status|settings|start|stop|run>
 *         wp aitp map <start|run|stop|status|list|export|clear>
 *         wp aitp journal <start|stop|status|undo|new-rows>
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AI Translator for TranslatePress commands.
 */
final class AITP_CLI {

	/**
	 * Manage the backfill queue.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : sync | start | pause | status | run | retry | clear
	 *
	 * [--minutes=<minutes>]
	 * : For "run": how long to work (default 5).
	 *
	 * [--pages=<pages>]
	 * : For "run": stop after this many page visits.
	 *
	 * [--ignore-rate]
	 * : For "run": do not wait between pages (testing).
	 *
	 * ## EXAMPLES
	 *
	 *     wp aitp queue sync
	 *     wp aitp queue start
	 *     wp aitp queue run --minutes=30
	 *     wp aitp queue status
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Named.
	 */
	public function queue( $args, $assoc ) {
		$action = isset( $args[0] ) ? $args[0] : 'status';
		switch ( $action ) {
			case 'sync':
				$r = AITP_Queue::sync();
				WP_CLI::success( sprintf( '%d source pages, %d queue rows, %d removed.', $r['pages'], $r['rows'], $r['removed'] ) );
				break;
			case 'start':
				AITP_Queue::start();
				WP_CLI::success( 'Queue running.' );
				break;
			case 'pause':
				AITP_Queue::pause();
				WP_CLI::success( 'Queue paused.' );
				break;
			case 'retry':
				WP_CLI::success( AITP_Queue::requeue( 'failed' ) . ' pages queued again.' );
				break;
			case 'clear':
				AITP_Queue::clear();
				WP_CLI::success( 'Queue emptied.' );
				break;
			case 'run':
				$minutes = isset( $assoc['minutes'] ) ? max( 1, (int) $assoc['minutes'] ) : 5;
				$pages   = isset( $assoc['pages'] ) ? max( 1, (int) $assoc['pages'] ) : 0;
				if ( ! empty( $assoc['ignore-rate'] ) ) {
					add_filter( 'option_' . AITP_Queue::SETTINGS, array( __CLASS__, 'no_rate' ) );
					add_filter( 'default_option_' . AITP_Queue::SETTINGS, array( __CLASS__, 'no_rate' ) );
				}
				$end     = time() + $minutes * 60;
				$visited = 0;
				do {
					$r        = AITP_Queue::run( max( 30, $end - time() ), $pages ? $pages - $visited : 0, true );
					$visited += $r['visited'];
					if ( in_array( $r['result'], array( 'stopped', 'waiting', 'idle', 'busy', 'pages' ), true ) ) {
						break;
					}
				} while ( time() < $end );
				WP_CLI::log( wp_json_encode( array( 'result' => $r['result'], 'visited' => $visited, 'wait' => $r['wait'] ) ) );
				break;
			case 'status':
			default:
				WP_CLI::log( wp_json_encode( array( 'state' => AITP_Queue::state(), 'progress' => AITP_Queue::progress() ), JSON_PRETTY_PRINT ) );
		}
	}

	/**
	 * Audit and repair stored translations.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : scan | status | list | repair | rollback | import | ai-check | dismiss | protect | unprotect | protected
	 *
	 * [<arg>]
	 * : For "rollback": batch id. For "import" and "protect": file path.
 *
 * [--label=<label>]
 * : For "protect" / "unprotect": name of the accepted list.
	 *
	 * [--ids=<ids>]
	 * : Finding ids, comma separated (repair, ai-check, dismiss).
	 *
	 * [--lang=<lang>]
	 * : Filter (list, or repair/ai-check without --ids).
	 *
	 * [--severity=<severity>]
	 * : Filter: error | suspect | review.
	 *
	 * [--code=<code>]
	 * : Filter by problem code.
	 *
	 * [--source=<source>]
	 * : Filter: scan | import.
	 *
	 * [--state=<state>]
	 * : Filter: open (default) | fixed | dismissed | changed | accepted | all.
	 *
	 * [--limit=<n>]
	 * : For "list": rows to show (default 50).
	 *
	 * [--minutes=<minutes>]
	 * : For "scan": how long to work (default 10).
	 *
	 * [--yes]
	 * : Confirm "repair" without --ids (applies to every match of the filters, at most 1000).
	 *
	 * [--include-unlinked]
	 * : For "repair": also reset rows on no known page (not linked by TranslatePress, not
	 * found by page mapping); every page of their language is then queued again.
	 *
	 * [--pages=<list>]
	 * : For "repair": only queue these pages (comma-separated addresses, or a file with one
	 * per line; default-language or translated). Rows shown on none of them are skipped.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aitp audit scan
	 *     wp aitp audit list --lang=fr_FR --severity=error
	 *     wp aitp audit repair --ids=12,13
	 *     wp aitp audit rollback 1762700000000
 *     wp aitp audit protect reviewed.json --label="Reviewed 2026-11"
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Named.
	 */
	public function audit( $args, $assoc ) {
		$action = isset( $args[0] ) ? $args[0] : 'status';
		$f      = array();
		foreach ( array( 'lang', 'severity', 'code', 'source', 'state' ) as $k ) {
			$f[ $k ] = isset( $assoc[ $k ] ) ? (string) $assoc[ $k ] : '';
		}
		$ids = isset( $assoc['ids'] ) ? array_map( 'intval', explode( ',', (string) $assoc['ids'] ) ) : array();
		switch ( $action ) {
			case 'scan':
				AITP_Audit::start_scan();
				$end = time() + 60 * ( isset( $assoc['minutes'] ) ? max( 1, (int) $assoc['minutes'] ) : 10 );
				do {
					$r = AITP_Audit::step( max( 5, min( 60, $end - time() ) ) );
				} while ( $r['result'] === 'running' && time() < $end );
				WP_CLI::log( wp_json_encode( array( 'result' => $r['result'], 'scanned' => $r['state']['scanned'], 'counts' => AITP_Audit::counts() ) ) );
				break;
			case 'list':
				$res = AITP_Audit::find( $f, 1, isset( $assoc['limit'] ) ? max( 1, (int) $assoc['limit'] ) : 50 );
				WP_CLI::log( wp_json_encode( $res, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
				break;
			case 'repair':
				if ( ! $ids ) {
					if ( empty( $assoc['yes'] ) ) {
						WP_CLI::error( 'Give --ids, or --yes to reset every match of the filters (at most 1000).' );
					}
					$ids = AITP_Audit::ids_for( $f );
				}
				$only = array();
				if ( isset( $assoc['pages'] ) ) {
					$u    = (string) $assoc['pages'];
					$only = array_values( array_filter( array_map( 'trim', is_readable( $u ) ? preg_split( '~\R~', (string) file_get_contents( $u ) ) : explode( ',', $u ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				}
				WP_CLI::log( wp_json_encode( AITP_Audit::repair( $ids, array( 'unlinked' => ! empty( $assoc['include-unlinked'] ), 'pages' => $only ) ) ) );
				break;
			case 'rollback':
				WP_CLI::log( wp_json_encode( AITP_Audit::rollback( isset( $args[1] ) ? (int) $args[1] : 0 ) ) );
				break;
			case 'import':
				if ( empty( $args[1] ) || ! is_readable( $args[1] ) ) {
					WP_CLI::error( 'File not readable.' );
				}
				WP_CLI::log( wp_json_encode( AITP_Audit::import( (string) file_get_contents( $args[1] ) ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				break;
			case 'protect':
				if ( empty( $args[1] ) || ! is_readable( $args[1] ) ) {
					WP_CLI::error( 'File not readable.' );
				}
				WP_CLI::log( wp_json_encode( AITP_Audit::protect( (string) file_get_contents( $args[1] ), isset( $assoc['label'] ) ? (string) $assoc['label'] : '' ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				break;
			case 'unprotect':
				if ( empty( $assoc['label'] ) ) {
					WP_CLI::error( '--label is required.' );
				}
				WP_CLI::success( AITP_Audit::unprotect( (string) $assoc['label'] ) . ' rows removed from the accepted list.' );
				break;
			case 'protected':
				WP_CLI::log( wp_json_encode( AITP_Audit::protected_lists(), JSON_PRETTY_PRINT ) );
				break;
			case 'ai-check':
				WP_CLI::log( wp_json_encode( AITP_Audit::ai_check( $ids ? $ids : AITP_Audit::ids_for( $f ), 300 ) ) );
				break;
			case 'dismiss':
				WP_CLI::success( AITP_Audit::set_finding_state( $ids, 'dismissed' ) . ' findings dismissed.' );
				break;
			case 'status':
			default:
				WP_CLI::log( wp_json_encode( array( 'state' => AITP_Audit::state(), 'counts' => AITP_Audit::counts(), 'batches' => AITP_Audit::batches() ), JSON_PRETTY_PRINT ) );
		}
	}

	/**
	 * Batch auto repair.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : status | settings | start | stop | run
	 *
	 * [--enabled=<0|1>]
	 * : For "settings": allow the auto repair.
	 *
	 * [--languages=<langs>]
	 * : For "settings": comma separated (empty = all).
	 *
	 * [--batch=<n>]
	 * : For "settings": rows per batch (20-500).
	 *
	 * [--cap=<n>]
	 * : For "settings": characters per day.
	 *
	 * [--minutes=<minutes>]
	 * : For "run": how long to work (default 10).
	 *
	 * [--ignore-rate]
	 * : For "run": ignore the pages-per-minute limit (testing).
	 *
	 * ## EXAMPLES
	 *
	 *     wp aitp autofix settings --enabled=1 --languages=de_DE --batch=100
	 *     wp aitp autofix start
	 *     wp aitp autofix run --minutes=30
	 *     wp aitp autofix stop
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Named.
	 */
	public function autofix( $args, $assoc ) {
		$action = isset( $args[0] ) ? $args[0] : 'status';
		switch ( $action ) {
			case 'settings':
				$in = AITP_Autofix::settings();
				if ( isset( $assoc['enabled'] ) ) {
					$in['enabled'] = (bool) (int) $assoc['enabled'];
				}
				if ( isset( $assoc['languages'] ) ) {
					$in['languages'] = array_filter( explode( ',', (string) $assoc['languages'] ) );
				}
				foreach ( array( 'batch', 'cap' ) as $k ) {
					if ( isset( $assoc[ $k ] ) ) {
						$in[ $k ] = (int) $assoc[ $k ];
					}
				}
				WP_CLI::log( wp_json_encode( AITP_Autofix::save_settings( $in ) ) );
				break;
			case 'start':
				$why = AITP_Autofix::start();
				if ( $why !== '' ) {
					WP_CLI::error( 'Cannot start: ' . $why );
				}
				WP_CLI::success( 'Auto repair started.' );
				break;
			case 'stop':
				AITP_Autofix::stop( 'manual' );
				WP_CLI::success( 'Auto repair stopped.' );
				break;
			case 'run':
				if ( ! empty( $assoc['ignore-rate'] ) ) {
					add_filter( 'option_' . AITP_Queue::SETTINGS, array( __CLASS__, 'no_rate' ) );
					add_filter( 'default_option_' . AITP_Queue::SETTINGS, array( __CLASS__, 'no_rate' ) );
				}
				$end = time() + 60 * ( isset( $assoc['minutes'] ) ? max( 1, (int) $assoc['minutes'] ) : 10 );
				do {
					$r = AITP_Autofix::step( max( 20, min( 120, $end - time() ) ) );
					if ( in_array( $r['result'], array( 'rate', 'busy' ), true ) && time() < $end ) {
						sleep( max( 1, min( (int) $r['wait'], $end - time() ) ) );
					}
				} while ( in_array( $r['result'], array( 'running', 'time', 'rate', 'busy' ), true ) && time() < $end );
				$s = $r['state'];
				unset( $s['batch']['urls'] );
				WP_CLI::log( wp_json_encode( array( 'result' => $r['result'], 'state' => $s ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
				break;
			case 'status':
			default:
				WP_CLI::log( wp_json_encode( array( 'settings' => AITP_Autofix::settings(), 'state' => AITP_Autofix::state() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		}
	}

	/**
	 * Page mapping: which page-text rows each translated page shows (read-only visits:
	 * nothing is translated or saved).
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : start | run | stop | status | list | export | clear
	 *
	 * [--urls=<urls>]
	 * : For "start": default-language addresses, comma separated, or a file with one per line.
	 * Without it every page of the backfill queue's page list is mapped.
	 *
	 * [--languages=<languages>]
	 * : For "start" and "list": languages, comma separated (default all).
	 *
	 * [--minutes=<minutes>]
	 * : For "run": how long to work (default 10).
	 *
	 * [--ignore-rate]
	 * : For "run": no pause between pages (testing).
	 *
	 * [--pages=<ids>]
	 * : For "export": mapped page ids, comma separated (default all).
	 *
	 * [--file=<file>]
	 * : For "export": write the candidate list to this file instead of the screen.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aitp map start --urls=https://example.com/,https://example.com/shop/
	 *     wp aitp map run --minutes=15
	 *     wp aitp map list
	 *     wp aitp map export --pages=1,2,3 --file=candidates.json
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Named.
	 */
	public function map( $args, $assoc ) {
		$action = isset( $args[0] ) ? $args[0] : 'status';
		$langs  = isset( $assoc['languages'] ) ? array_filter( explode( ',', (string) $assoc['languages'] ) ) : array();
		switch ( $action ) {
			case 'start':
				$urls = array();
				if ( isset( $assoc['urls'] ) ) {
					$u    = (string) $assoc['urls'];
					$urls = is_readable( $u ) ? preg_split( '~\R~', (string) file_get_contents( $u ) ) : explode( ',', $u ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				}
				$r = AITP_Pagemap::start( $urls, $langs );
				if ( $r['error'] !== '' ) {
					WP_CLI::error( 'Cannot start: ' . $r['error'] . ( $r['bad'] ? ' (not usable: ' . implode( ', ', $r['bad'] ) . ')' : '' ) );
				}
				WP_CLI::success( sprintf( '%d pages to map.', $r['pages'] ) . ( $r['bad'] ? ' Not usable: ' . implode( ', ', $r['bad'] ) : '' ) );
				break;
			case 'run':
				if ( ! empty( $assoc['ignore-rate'] ) ) {
					AITP_Pagemap::$gap = 0;
				}
				$end = time() + 60 * ( isset( $assoc['minutes'] ) ? max( 1, (int) $assoc['minutes'] ) : 10 );
				do {
					$r = AITP_Pagemap::step( max( 20, min( 120, $end - time() ) ) );
					if ( in_array( $r['result'], array( 'running', 'busy' ), true ) && $r['wait'] && time() < $end ) {
						sleep( max( 1, min( (int) $r['wait'], $end - time() ) ) );
					}
				} while ( in_array( $r['result'], array( 'running', 'busy' ), true ) && time() < $end );
				$s = $r['state'];
				WP_CLI::log( wp_json_encode( array( 'result' => $r['result'], 'status' => $s['status'], 'done' => $s['i'], 'pages' => count( $s['items'] ), 'ok' => $s['ok'], 'failed' => $s['failed'] ) ) );
				break;
			case 'stop':
				AITP_Pagemap::stop();
				WP_CLI::success( 'Page mapping stopped.' );
				break;
			case 'list':
				$res = AITP_Pagemap::pages( array( 'lang' => $langs ? reset( $langs ) : '', 'per_page' => 1000 ) );
				foreach ( $res['rows'] as &$r ) {
					unset( $r['ukey'] );
				}
				unset( $r );
				WP_CLI::log( wp_json_encode( $res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
				break;
			case 'export':
				$ids  = isset( $assoc['pages'] ) ? array_map( 'intval', explode( ',', (string) $assoc['pages'] ) ) : array();
				$json = wp_json_encode( AITP_Pagemap::export( $ids ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
				if ( ! empty( $assoc['file'] ) ) {
					file_put_contents( (string) $assoc['file'], $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					WP_CLI::success( 'Written to ' . $assoc['file'] );
				} else {
					WP_CLI::log( $json );
				}
				break;
			case 'clear':
				AITP_Pagemap::clear();
				WP_CLI::success( 'Page map cleared (TranslatePress data untouched).' );
				break;
			case 'status':
			default:
				$s = AITP_Pagemap::state();
				WP_CLI::log( wp_json_encode( array( 'status' => $s['status'], 'reason' => $s['reason'], 'done' => $s['i'], 'pages' => count( $s['items'] ), 'ok' => $s['ok'], 'failed' => $s['failed'] ) ) );
		}
	}

	/**
	 * Test journal: record what this engine translates, undo it after a test.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : start | stop | status | undo | new-rows
	 *
	 * [--label=<label>]
	 * : For "start": name of the test.
	 *
	 * [--file=<file>]
	 * : For "new-rows": write the rows to this file instead of the screen.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aitp journal start --label="staging test"
	 *     wp aitp journal stop
	 *     wp aitp journal undo
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Named.
	 */
	public function journal( $args, $assoc ) {
		$action = isset( $args[0] ) ? $args[0] : 'status';
		switch ( $action ) {
			case 'start':
				$why = AITP_Journal::start( isset( $assoc['label'] ) ? (string) $assoc['label'] : '' );
				if ( $why !== '' ) {
					WP_CLI::error( 'Cannot start: ' . $why );
				}
				WP_CLI::success( 'Test journal on.' );
				break;
			case 'stop':
				AITP_Journal::stop();
				WP_CLI::success( 'Test journal off.' );
				break;
			case 'undo':
				WP_CLI::log( wp_json_encode( AITP_Journal::undo() ) );
				break;
			case 'new-rows':
				$json = wp_json_encode( array( 'rows' => AITP_Journal::new_rows() ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
				if ( ! empty( $assoc['file'] ) ) {
					file_put_contents( (string) $assoc['file'], $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					WP_CLI::success( 'Written to ' . $assoc['file'] );
				} else {
					WP_CLI::log( $json );
				}
				break;
			case 'status':
			default:
				$s = AITP_Journal::state();
				unset( $s['max'] );
				WP_CLI::log( wp_json_encode( array( 'state' => $s ) + AITP_Journal::summary() ) );
		}
	}

	/**
	 * @param mixed $v Settings.
	 * @return mixed
	 */
	public static function no_rate( $v ) {
		if ( is_array( $v ) ) {
			$v['per_minute'] = 6000;
		}
		return $v;
	}
}
