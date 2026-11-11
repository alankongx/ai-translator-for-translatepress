<?php
/**
 * "Backfill queue" tab: status, controls, settings, progress, pages needing attention.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Admin_Queue {

	/** Hooks. */
	public static function boot() {
		add_action( 'admin_post_aitp_queue', array( __CLASS__, 'handle' ) );
		add_action( 'wp_ajax_aitp_queue_step', array( __CLASS__, 'ajax_step' ) );
	}

	/** @return string */
	private static function url() {
		return AITP_Admin::url( 'queue' );
	}

	/**
	 * Label for a page note or wait reason.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function note_label( $code ) {
		$map = array(
			''                => '',
			'redirect'        => __( 'page redirects elsewhere (not queued again)', 'ai-translator-for-translatepress' ),
			'no_report'       => __( 'page answered without being processed (a page cache?)', 'ai-translator-for-translatepress' ),
			'signature'       => __( 'internal visit signature not accepted (nothing was translated or saved)', 'ai-translator-for-translatepress' ),
			'unreachable'     => __( 'the site could not reach its own page', 'ai-translator-for-translatepress' ),
			'max_visits'      => __( 'still new strings after several visits; continues later', 'ai-translator-for-translatepress' ),
			'strings_failing' => __( 'some strings keep failing; retried later', 'ai-translator-for-translatepress' ),
			'queue_cap'       => __( 'daily character limit of the queue reached', 'ai-translator-for-translatepress' ),
			'engine_not_selected' => __( 'TranslatePress is not using the AI Translator engine (TranslatePress → Automatic Translation); the queue does not visit pages for another engine', 'ai-translator-for-translatepress' ),
			'automatic_off'   => __( 'automatic translation is switched off in TranslatePress', 'ai-translator-for-translatepress' ),
			'tp_limit'        => __( 'TranslatePress daily limit almost used up (reserve kept for visitors)', 'ai-translator-for-translatepress' ),
		);
		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ];
		}
		if ( strpos( $code, 'http_' ) === 0 ) {
			/* translators: %s: HTTP status code */
			return sprintf( __( 'page returned HTTP %s', 'ai-translator-for-translatepress' ), substr( $code, 5 ) );
		}
		return AITP_Admin::code_label( $code );
	}

	/** Render the tab. */
	public static function render() {
		global $wpdb;
		$s     = AITP_Queue::state();
		$set   = AITP_Queue::settings();
		$prog  = AITP_Queue::progress();
		$total = array_fill_keys( AITP_Queue::STATUSES, 0 );
		foreach ( $prog as $by ) {
			foreach ( $by as $st => $n ) {
				$total[ $st ] = ( isset( $total[ $st ] ) ? $total[ $st ] : 0 ) + $n;
			}
		}
		$all = array_sum( $total );

		echo '<p class="description" style="max-width:820px;">' . esc_html__( 'Visits the translated version of your pages in the background, one page at a time, so TranslatePress translates them before a visitor does. Every translation goes through the same checks as during a normal page view; nothing is written to TranslatePress directly.', 'ai-translator-for-translatepress' ) . '</p>';

		// Overview.
		if ( $s['status'] === 'running' ) {
			$state = __( 'running', 'ai-translator-for-translatepress' );
			if ( (int) $s['wait_until'] > time() ) {
				/* translators: 1: reason, 2: time */
				$state = sprintf( __( 'waiting: %1$s (until %2$s)', 'ai-translator-for-translatepress' ), self::note_label( $s['wait_reason'] ), wp_date( 'Y-m-d H:i', (int) $s['wait_until'] ) );
			}
		} else {
			$state = __( 'paused', 'ai-translator-for-translatepress' );
		}
		echo '<h2>' . esc_html__( 'Overview', 'ai-translator-for-translatepress' ) . '</h2><table class="widefat striped aitp-kv"><tbody>';
		self::kv( __( 'State', 'ai-translator-for-translatepress' ), $state );
		/* translators: 1: done pages, 2: all pages */
		self::kv( __( 'Pages done', 'ai-translator-for-translatepress' ), sprintf( __( '%1$s of %2$s', 'ai-translator-for-translatepress' ), number_format_i18n( $total['done'] ), number_format_i18n( $all ) ) );
		$cap = (int) $set['queue_cap'];
		self::kv( __( 'Characters used by the queue today', 'ai-translator-for-translatepress' ), number_format_i18n( (int) $s['day_bytes'] ) . ( $cap ? ' / ' . number_format_i18n( $cap ) : '' ) );
		self::kv( __( 'Page list built', 'ai-translator-for-translatepress' ), $s['last_sync'] ? wp_date( 'Y-m-d H:i', (int) $s['last_sync'] ) : __( 'not yet', 'ai-translator-for-translatepress' ) );
		$next = wp_next_scheduled( AITP_Queue::CRON );
		self::kv( __( 'Background run (WP-Cron)', 'ai-translator-for-translatepress' ), $next ? __( 'scheduled every minute', 'ai-translator-for-translatepress' ) . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? ' — ' . __( 'WP-Cron is disabled on this site; a server cron job must call wp-cron.php, or keep this page open, or use "wp aitp queue run".', 'ai-translator-for-translatepress' ) : '' ) . AITP_Admin::cron_late( $next ) : __( 'not scheduled', 'ai-translator-for-translatepress' ) );
		echo '</tbody></table>';

		// Controls.
		echo '<p class="aitp-queue-actions">';
		if ( $s['status'] === 'running' ) {
			self::button( 'pause', __( 'Pause', 'ai-translator-for-translatepress' ), 'secondary' );
		} else {
			self::button( 'start', $all ? __( 'Start', 'ai-translator-for-translatepress' ) : __( 'Build page list and start', 'ai-translator-for-translatepress' ), 'primary' );
		}
		self::button( 'sync', __( 'Rebuild page list', 'ai-translator-for-translatepress' ), 'secondary' );
		if ( $total['failed'] || $total['partial'] ) {
			self::button( 'retry', __( 'Queue unfinished pages again', 'ai-translator-for-translatepress' ), 'secondary' );
		}
		if ( $all ) {
			self::button( 'recheck', __( 'Check all pages again', 'ai-translator-for-translatepress' ), 'secondary' );
			self::button( 'clear', __( 'Empty the queue', 'ai-translator-for-translatepress' ), 'link-delete', __( 'Remove all pages from the queue? Translations are not affected.', 'ai-translator-for-translatepress' ) );
		}
		echo '</p>';
		if ( $s['status'] === 'running' ) {
			echo '<p><button type="button" class="button" id="aitp-queue-live" data-label-stop="' . esc_attr__( 'Stop advancing from this page', 'ai-translator-for-translatepress' ) . '">' . esc_html__( 'Advance from this page now', 'ai-translator-for-translatepress' ) . '</button> <span id="aitp-queue-live-status" class="description"></span></p>';
		}

		// Progress.
		echo '<h2>' . esc_html__( 'Progress', 'ai-translator-for-translatepress' ) . '</h2>';
		if ( ! $prog ) {
			echo '<p>' . esc_html__( 'The queue is empty. Choose what to translate below, then start.', 'ai-translator-for-translatepress' ) . '</p>';
		} else {
			$heads = array(
				'done'    => __( 'Done', 'ai-translator-for-translatepress' ),
				'pending' => __( 'Waiting', 'ai-translator-for-translatepress' ),
				'partial' => __( 'Partly done', 'ai-translator-for-translatepress' ),
				'failed'  => __( 'Failed', 'ai-translator-for-translatepress' ),
			);
			echo '<table class="widefat striped" style="max-width:820px;"><thead><tr><th>' . esc_html__( 'Language', 'ai-translator-for-translatepress' ) . '</th>';
			foreach ( $heads as $h ) {
				echo '<th>' . esc_html( $h ) . '</th>';
			}
			echo '<th>' . esc_html__( 'Total', 'ai-translator-for-translatepress' ) . '</th></tr></thead><tbody>';
			ksort( $prog );
			foreach ( $prog as $lang => $by ) {
				echo '<tr><td>' . esc_html( $lang ) . '</td>';
				foreach ( array_keys( $heads ) as $k ) {
					echo '<td>' . esc_html( number_format_i18n( isset( $by[ $k ] ) ? $by[ $k ] : 0 ) ) . '</td>';
				}
				echo '<td>' . esc_html( number_format_i18n( array_sum( $by ) ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		// Pages needing attention.
		$t    = AITP_Schema::table( 'queue' );
		$rows = $wpdb->get_results( "SELECT url, lang, status, note, next_at, last_visit_at FROM $t WHERE status IN ('failed','partial') ORDER BY status = 'failed' DESC, last_visit_at DESC LIMIT 50", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $rows ) {
			echo '<h2>' . esc_html__( 'Pages needing attention', 'ai-translator-for-translatepress' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Page', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Language', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'State', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Reason', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Next attempt', 'ai-translator-for-translatepress' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr><td><a href="' . esc_url( $r['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $r['url'] ) . '</a></td><td>' . esc_html( $r['lang'] ) . '</td><td>' . esc_html( $r['status'] === 'failed' ? __( 'Failed', 'ai-translator-for-translatepress' ) : __( 'Partly done', 'ai-translator-for-translatepress' ) ) . '</td><td>' . esc_html( self::note_label( $r['note'] ) ) . '</td><td>' . esc_html( (int) $r['next_at'] ? wp_date( 'Y-m-d H:i', (int) $r['next_at'] ) : '' ) . '</td></tr>';
			}
			echo '</tbody></table><p class="description">' . esc_html__( 'Strings that fail are listed under Status & log with their reasons.', 'ai-translator-for-translatepress' ) . '</p>';
		}

		self::render_settings( $set );
	}

	/** @param array $set Settings. */
	private static function render_settings( array $set ) {
		$langs = AITP_Queue::tp_languages();
		echo '<h2>' . esc_html__( 'What to translate', 'ai-translator-for-translatepress' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aitp_queue"><input type="hidden" name="do" value="save">';
		wp_nonce_field( 'aitp_queue' );
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Languages', 'ai-translator-for-translatepress' ) . '</th><td>';
		foreach ( $langs as $l ) {
			echo '<label style="margin-right:14px;"><input type="checkbox" name="q[languages][]" value="' . esc_attr( $l ) . '"' . checked( ! $set['languages'] || in_array( $l, $set['languages'], true ), true, false ) . '> ' . esc_html( $l ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Languages configured in TranslatePress.', 'ai-translator-for-translatepress' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Content', 'ai-translator-for-translatepress' ) . '</th><td>';
		foreach ( AITP_Queue::content_types() as $k => $label ) {
			echo '<label style="display:inline-block;min-width:180px;"><input type="checkbox" name="q[types][]" value="' . esc_attr( $k ) . '"' . checked( in_array( $k, $set['types'], true ), true, false ) . '> ' . esc_html( $label ) . '</label> ';
		}
		echo '<p class="description">' . esc_html__( 'Published content only. Edited pages are queued again automatically.', 'ai-translator-for-translatepress' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="aitp-q-urls">' . esc_html__( 'Extra pages', 'ai-translator-for-translatepress' ) . '</label></th><td><textarea id="aitp-q-urls" class="large-text code" rows="3" name="q[extra_urls]">' . esc_textarea( $set['extra_urls'] ) . '</textarea><p class="description">' . esc_html__( 'One address per line, in the default language, on this site (e.g. archive or landing pages).', 'ai-translator-for-translatepress' ) . '</p></td></tr>';

		$nums = array(
			'per_minute'   => array( __( 'Pages per minute', 'ai-translator-for-translatepress' ), __( 'Upper limit; a page with many new strings takes longer.', 'ai-translator-for-translatepress' ), 1, 30 ),
			'queue_cap'    => array( __( 'Daily character limit of the queue', 'ai-translator-for-translatepress' ), __( 'Characters the queue may send per day (0 = only the TranslatePress limit applies).', 'ai-translator-for-translatepress' ), 0, 100000000 ),
			'reserve'      => array( __( 'Reserve for visitors (%)', 'ai-translator-for-translatepress' ), __( 'The queue stops when the TranslatePress daily limit is used up to this reserve, so live page views can still be translated.', 'ai-translator-for-translatepress' ), 0, 90 ),
			'page_seconds' => array( __( 'Seconds per page', 'ai-translator-for-translatepress' ), __( 'Time budget of one background page visit (10–40).', 'ai-translator-for-translatepress' ), 10, 40 ),
		);
		foreach ( $nums as $k => $n ) {
			echo '<tr><th scope="row"><label for="aitp-q-' . esc_attr( $k ) . '">' . esc_html( $n[0] ) . '</label></th><td><input id="aitp-q-' . esc_attr( $k ) . '" type="number" class="small-text" style="width:120px;" min="' . (int) $n[2] . '" max="' . (int) $n[3] . '" name="q[' . esc_attr( $k ) . ']" value="' . esc_attr( (string) $set[ $k ] ) . '"><p class="description">' . esc_html( $n[1] ) . '</p></td></tr>';
		}
		echo '</tbody></table>';
		submit_button( __( 'Save and rebuild page list', 'ai-translator-for-translatepress' ) );
		echo '</form>';
	}

	/**
	 * @param string $do      Action.
	 * @param string $label   Button text.
	 * @param string $class   primary|secondary|link-delete.
	 * @param string $confirm Confirmation question.
	 */
	private static function button( $do, $label, $class, $confirm = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:6px;"' . ( $confirm !== '' ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '' ) . '><input type="hidden" name="action" value="aitp_queue"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		wp_nonce_field( 'aitp_queue' );
		echo '<button type="submit" class="button' . ( $class === 'primary' ? ' button-primary' : '' ) . ( $class === 'link-delete' ? ' aitp-button-delete' : '' ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/**
	 * @param string $k Label.
	 * @param string $v Value.
	 */
	private static function kv( $k, $v ) {
		echo '<tr><th>' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
	}

	/** Form actions. */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ai-translator-for-translatepress' ), 403 );
		}
		check_admin_referer( 'aitp_queue' );
		$do  = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$msg = '';
		switch ( $do ) {
			case 'save':
				$in = isset( $_POST['q'] ) && is_array( $_POST['q'] ) ? wp_unslash( $_POST['q'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field by field in save_settings().
				AITP_Queue::save_settings( $in );
				$r   = AITP_Queue::sync();
				/* translators: 1: pages, 2: queue entries */
				$msg = sprintf( __( 'Saved. %1$s pages, %2$s page/language combinations in the queue.', 'ai-translator-for-translatepress' ), number_format_i18n( $r['pages'] ), number_format_i18n( $r['rows'] ) );
				break;
			case 'sync':
				$r   = AITP_Queue::sync();
				/* translators: 1: pages, 2: queue entries */
				$msg = sprintf( __( 'Page list rebuilt: %1$s pages, %2$s page/language combinations.', 'ai-translator-for-translatepress' ), number_format_i18n( $r['pages'] ), number_format_i18n( $r['rows'] ) );
				break;
			case 'start':
				if ( ! AITP_Settings::is_configured() ) {
					$msg = __( 'Enter the API key and model first.', 'ai-translator-for-translatepress' );
					break;
				}
				if ( ! AITP_Queue::progress() ) {
					AITP_Queue::sync();
				}
				AITP_Queue::start();
				$msg = __( 'The queue is running.', 'ai-translator-for-translatepress' );
				break;
			case 'pause':
				AITP_Queue::pause();
				$msg = __( 'The queue is paused.', 'ai-translator-for-translatepress' );
				break;
			case 'retry':
				/* translators: %s: number of pages */
				$msg = sprintf( __( '%s pages queued again.', 'ai-translator-for-translatepress' ), number_format_i18n( AITP_Queue::requeue( 'failed' ) ) );
				break;
			case 'recheck':
				/* translators: %s: number of pages */
				$msg = sprintf( __( '%s pages queued again.', 'ai-translator-for-translatepress' ), number_format_i18n( AITP_Queue::requeue( 'all' ) ) );
				break;
			case 'clear':
				AITP_Queue::pause();
				AITP_Queue::clear();
				$msg = __( 'The queue is empty.', 'ai-translator-for-translatepress' );
				break;
		}
		if ( $msg !== '' ) {
			set_transient( 'aitp_flash_' . get_current_user_id(), array( 'type' => 'success', 'message' => $msg ), 120 );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/** One step from the open admin page (AJAX). */
	public static function ajax_step() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'aitp_queue_step' );
		$r    = AITP_Queue::run( 25, 1, false );
		$prog = AITP_Queue::progress();
		$done = 0;
		$all  = 0;
		foreach ( $prog as $by ) {
			$done += isset( $by['done'] ) ? $by['done'] : 0;
			$all  += array_sum( $by );
		}
		$labels = array(
			'time'    => __( 'working', 'ai-translator-for-translatepress' ),
			'pages'   => __( 'page done', 'ai-translator-for-translatepress' ),
			'rate'    => __( 'waiting for the speed limit', 'ai-translator-for-translatepress' ),
			'waiting' => __( 'queue is waiting', 'ai-translator-for-translatepress' ),
			'idle'    => __( 'nothing due right now', 'ai-translator-for-translatepress' ),
			'busy'    => __( 'another run is in progress', 'ai-translator-for-translatepress' ),
			'stopped' => __( 'queue is paused', 'ai-translator-for-translatepress' ),
		);
		/* translators: 1: state, 2: done pages, 3: all pages */
		$text = sprintf( __( '%1$s — %2$s of %3$s done', 'ai-translator-for-translatepress' ), isset( $labels[ $r['result'] ] ) ? $labels[ $r['result'] ] : $r['result'], number_format_i18n( $done ), number_format_i18n( $all ) );
		wp_send_json_success(
			array(
				'result' => $r['result'],
				'wait'   => (int) $r['wait'],
				'text'   => $text,
				'stop'   => in_array( $r['result'], array( 'stopped' ), true ) || ( $r['result'] === 'idle' && ! $r['wait'] ),
			)
		);
	}
}
