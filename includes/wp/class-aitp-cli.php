<?php
/**
 * WP-CLI: wp aitp queue <sync|start|pause|status|run|retry|clear>
 *         wp aitp audit <scan|status|list|repair|rollback|import|ai-check|dismiss>
 *         wp aitp autofix <status|settings|start|stop|run>
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
	 * : Confirm "repair" without --ids (applies to every match of the filters, at most 500).
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
						WP_CLI::error( 'Give --ids, or --yes to reset every match of the filters (at most 500).' );
					}
					$ids = AITP_Audit::ids_for( $f );
				}
				WP_CLI::log( wp_json_encode( AITP_Audit::repair( $ids ) ) );
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
