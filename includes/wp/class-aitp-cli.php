<?php
/**
 * WP-CLI: wp aitp queue <sync|start|pause|status|run|retry|clear>
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
