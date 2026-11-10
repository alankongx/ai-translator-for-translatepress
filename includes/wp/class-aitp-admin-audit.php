<?php
/**
 * "Audit & repair" tab: scan stored translations, review findings, reset selected rows
 * (with backup), roll back a repair, import a candidate list, optional AI check.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Admin_Audit {

	/** Hooks. */
	public static function boot() {
		add_action( 'admin_post_aitp_audit', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_aitp_audit_backup', array( __CLASS__, 'download_backup' ) );
		add_action( 'wp_ajax_aitp_audit_step', array( __CLASS__, 'ajax_step' ) );
		add_action( 'wp_ajax_aitp_autofix_step', array( __CLASS__, 'ajax_autofix_step' ) );
	}

	/**
	 * @param array $args Query args.
	 * @return string
	 */
	private static function url( array $args = array() ) {
		return AITP_Admin::url( 'audit', $args );
	}

	/** @return array Filters from the request. */
	private static function filters() {
		$f = array();
		foreach ( array( 'lang', 'severity', 'code', 'state', 'ai', 'source', 'kind' ) as $k ) {
			// phpcs:ignore WordPress.Security.NonceVerification
			$v       = isset( $_REQUEST[ 'f_' . $k ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ 'f_' . $k ] ) ) : '';
			$f[ $k ] = preg_match( '~^[A-Za-z0-9_:\-]{0,40}$~', $v ) ? $v : '';
		}
		return $f;
	}

	/**
	 * @param array $f Filters.
	 * @return array Query args.
	 */
	private static function filter_args( array $f ) {
		$a = array();
		foreach ( $f as $k => $v ) {
			if ( $v !== '' ) {
				$a[ 'f_' . $k ] = $v;
			}
		}
		return $a;
	}

	/**
	 * @param string $s Severity.
	 * @return string
	 */
	public static function severity_label( $s ) {
		$map = array(
			'error'   => __( 'Wrong', 'ai-translator-for-translatepress' ),
			'suspect' => __( 'Probably misplaced', 'ai-translator-for-translatepress' ),
			'review'  => __( 'Check meaning', 'ai-translator-for-translatepress' ),
		);
		return isset( $map[ $s ] ) ? $map[ $s ] : $s;
	}

	/**
	 * @param string $s Finding state.
	 * @return string
	 */
	public static function state_label( $s ) {
		$map = array(
			'open'      => __( 'Open', 'ai-translator-for-translatepress' ),
			'fixed'     => __( 'Reset, waiting for new translation', 'ai-translator-for-translatepress' ),
			'dismissed' => __( 'Dismissed', 'ai-translator-for-translatepress' ),
			'changed'   => __( 'Changed since the scan', 'ai-translator-for-translatepress' ),
			'accepted'  => __( 'Accepted (reviewed, never repaired)', 'ai-translator-for-translatepress' ),
			'all'       => __( 'All', 'ai-translator-for-translatepress' ),
		);
		return isset( $map[ $s ] ) ? $map[ $s ] : $s;
	}

	/**
	 * @param string $s AI verdict.
	 * @return string
	 */
	public static function ai_label( $s ) {
		$map = array(
			''         => '',
			'match'    => __( 'AI: matches', 'ai-translator-for-translatepress' ),
			'mismatch' => __( 'AI: does not match', 'ai-translator-for-translatepress' ),
			'unsure'   => __( 'AI: unsure', 'ai-translator-for-translatepress' ),
		);
		return isset( $map[ $s ] ) ? $map[ $s ] : $s;
	}

	/**
	 * @param string $why Skip reason.
	 * @return string
	 */
	private static function skip_label( $why ) {
		$map = array(
			'changed'       => __( 'changed since the scan', 'ai-translator-for-translatepress' ),
			'manual'        => __( 'translated manually', 'ai-translator-for-translatepress' ),
			'missing'       => __( 'row no longer exists', 'ai-translator-for-translatepress' ),
			'untranslated'  => __( 'already untranslated', 'ai-translator-for-translatepress' ),
			'not_open'      => __( 'not open', 'ai-translator-for-translatepress' ),
			'accepted'      => __( 'on the accepted list', 'ai-translator-for-translatepress' ),
			'engine_not_selected' => __( 'nothing reset: TranslatePress is not using the AI Translator engine, so another engine would translate these rows again', 'ai-translator-for-translatepress' ),
			'backup_failed' => __( 'backup failed', 'ai-translator-for-translatepress' ),
			'no_page'       => __( 'on no known page (not reset)', 'ai-translator-for-translatepress' ),
			'outside_pages' => __( 'not on any page of the given list (not reset)', 'ai-translator-for-translatepress' ),
		);
		return isset( $map[ $why ] ) ? $map[ $why ] : $why;
	}

	/**
	 * @param array $skipped reason => count.
	 * @return string
	 */
	private static function skipped_text( array $skipped ) {
		$parts = array();
		foreach ( $skipped as $why => $n ) {
			$parts[] = self::skip_label( $why ) . ': ' . number_format_i18n( $n );
		}
		return implode( ', ', $parts );
	}

	/** Render the tab. */
	public static function render() {
		$s      = AITP_Audit::state();
		$set    = AITP_Audit::settings();
		$counts = AITP_Audit::counts();

		echo '<p class="description" style="max-width:860px;">' . esc_html__( 'Checks translations TranslatePress already stored (machine translations only) with the same rules used before saving new ones. Nothing is changed by a check. Rows you choose can be set back to "not translated" – they are backed up first and translated again through the backfill queue. Manual translations are never touched.', 'ai-translator-for-translatepress' ) . '</p>';

		// Overview.
		$labels = array(
			'idle'    => __( 'not run yet', 'ai-translator-for-translatepress' ),
			'running' => __( 'running', 'ai-translator-for-translatepress' ),
			'stopped' => __( 'stopped', 'ai-translator-for-translatepress' ),
			'done'    => __( 'finished', 'ai-translator-for-translatepress' ),
		);
		echo '<h2>' . esc_html__( 'Check', 'ai-translator-for-translatepress' ) . '</h2><table class="widefat striped aitp-kv"><tbody>';
		$state = isset( $labels[ $s['status'] ] ) ? $labels[ $s['status'] ] : $s['status'];
		if ( $s['status'] === 'running' && $s['tables'] ) {
			/* translators: 1: table number, 2: number of tables */
			$state .= ' — ' . sprintf( __( 'table %1$s of %2$s', 'ai-translator-for-translatepress' ), number_format_i18n( min( count( $s['tables'] ), $s['ti'] + 1 ) ), number_format_i18n( count( $s['tables'] ) ) );
		}
		self::kv( __( 'State', 'ai-translator-for-translatepress' ), $state );
		self::kv( __( 'Machine translations checked', 'ai-translator-for-translatepress' ), number_format_i18n( (int) $s['scanned'] ) );
		self::kv( __( 'Started', 'ai-translator-for-translatepress' ), $s['started'] ? wp_date( 'Y-m-d H:i', (int) $s['started'] ) : '—' );
		self::kv( __( 'Finished', 'ai-translator-for-translatepress' ), $s['finished'] ? wp_date( 'Y-m-d H:i', (int) $s['finished'] ) : '—' );
		self::kv( __( 'Open findings', 'ai-translator-for-translatepress' ), number_format_i18n( $counts['open'] ) );
		if ( $counts['accepted'] ) {
			echo '<tr><td>' . esc_html__( 'Findings on the accepted list (never repaired)', 'ai-translator-for-translatepress' ) . '</td><td><a href="' . esc_url( self::url( array( 'f_state' => 'accepted' ) ) ) . '">' . esc_html( number_format_i18n( $counts['accepted'] ) ) . '</a></td></tr>';
		}
		echo '</tbody></table>';

		echo '<p>';
		if ( $s['status'] === 'running' ) {
			self::button( 'stop', __( 'Stop check', 'ai-translator-for-translatepress' ), 'secondary' );
			echo '</p><p><button type="button" class="button" id="aitp-audit-live" data-label-stop="' . esc_attr__( 'Stop advancing from this page', 'ai-translator-for-translatepress' ) . '">' . esc_html__( 'Advance the check from this page now', 'ai-translator-for-translatepress' ) . '</button> <span id="aitp-audit-live-status" class="description"></span>';
		} else {
			self::button( 'scan', $s['status'] === 'idle' ? __( 'Start check', 'ai-translator-for-translatepress' ) : __( 'Check again', 'ai-translator-for-translatepress' ), 'primary' );
		}
		echo '</p>';

		if ( $counts['by'] ) {
			$sev = array( 'error', 'suspect', 'review' );
			echo '<table class="widefat striped" style="max-width:820px;"><thead><tr><th>' . esc_html__( 'Language', 'ai-translator-for-translatepress' ) . '</th>';
			foreach ( $sev as $k ) {
				echo '<th>' . esc_html( self::severity_label( $k ) ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			ksort( $counts['by'] );
			foreach ( $counts['by'] as $lang => $by ) {
				echo '<tr><td>' . esc_html( $lang ) . '</td>';
				foreach ( $sev as $k ) {
					$n = isset( $by[ $k ] ) ? $by[ $k ] : 0;
					echo '<td>' . ( $n ? '<a href="' . esc_url( self::url( array( 'f_lang' => $lang, 'f_severity' => $k ) ) ) . '">' . esc_html( number_format_i18n( $n ) ) . '</a>' : '0' ) . '</td>';
				}
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		self::render_autofix();
		self::render_list( $set );
		self::render_purge();
		self::render_batches();
		self::render_import();
		self::render_settings( $set );
	}

	/**
	 * @param string $r Stop or wait reason of the auto repair.
	 * @return string
	 */
	public static function autofix_reason( $r ) {
		$map = array(
			''                    => '',
			'manual'              => __( 'stopped by a person', 'ai-translator-for-translatepress' ),
			'disabled'            => __( 'the switch is off', 'ai-translator-for-translatepress' ),
			'engine_not_selected' => __( 'TranslatePress is not using the AI Translator engine', 'ai-translator-for-translatepress' ),
			'automatic_off'       => __( 'automatic translation is off in TranslatePress', 'ai-translator-for-translatepress' ),
			'not_configured'      => __( 'API key or model missing', 'ai-translator-for-translatepress' ),
			'no_scan'             => __( 'run a full check first', 'ai-translator-for-translatepress' ),
			'scan_running'        => __( 'a check is running; wait until it has finished', 'ai-translator-for-translatepress' ),
			'too_many_failures'   => __( 'more than 5% of the last batch is still wrong or untranslated', 'ai-translator-for-translatepress' ),
			'english_saved'       => __( 'English was stored as a translation in the last batch', 'ai-translator-for-translatepress' ),
			'visit_failed'        => __( 'pages of the batch could not be loaded (loopback, cache or server error)', 'ai-translator-for-translatepress' ),
			'visit_signature'     => __( 'internal visit signature not accepted (nothing was translated or saved)', 'ai-translator-for-translatepress' ),
			'rolled_back'         => __( 'the current batch was rolled back', 'ai-translator-for-translatepress' ),
			'no_pages'            => __( 'no page address found for the batch', 'ai-translator-for-translatepress' ),
			'backup_failed'       => __( 'backup failed', 'ai-translator-for-translatepress' ),
			'provider_breaker'    => __( 'the AI provider is failing (engine paused)', 'ai-translator-for-translatepress' ),
			'cap'                 => __( 'daily character cap of the auto repair reached; continues tomorrow', 'ai-translator-for-translatepress' ),
			'tp_limit'            => __( 'TranslatePress daily limit (minus the share kept for visitors) reached; continues tomorrow', 'ai-translator-for-translatepress' ),
			'quota'               => __( 'TranslatePress daily limit reached; continues tomorrow', 'ai-translator-for-translatepress' ),
		);
		if ( isset( $map[ $r ] ) ) {
			return $map[ $r ];
		}
		if ( strpos( $r, 'provider_' ) === 0 ) {
			/* translators: %s: provider error */
			return sprintf( __( 'AI provider error: %s', 'ai-translator-for-translatepress' ), AITP_Admin::code_label( substr( $r, 9 ) ) );
		}
		return $r;
	}

	/** Batch auto repair. */
	private static function render_autofix() {
		$set   = AITP_Autofix::settings();
		$s     = AITP_Autofix::state();
		$langs = AITP_Queue::tp_languages();
		echo '<h2>' . esc_html__( 'Batch auto repair', 'ai-translator-for-translatepress' ) . '</h2>';
		echo '<p class="description" style="max-width:860px;">' . esc_html__( 'Off by default. Works through "wrong" findings of page text one batch at a time: backs up and resets one batch, visits only the pages that show those strings until they are translated again, checks the new translations, and starts the next batch only when the batch came out clean. It stops by itself when more than 5% of a batch is still wrong or untranslated, when English is stored as a translation, on provider errors or failed page loads, when TranslatePress no longer uses this engine, or when a batch is rolled back. "Probably misplaced" and "check meaning" findings, the accepted list, manual translations, structured data and interface text are never touched. Every batch can be rolled back under "Repairs". Try a few batches by hand first.', 'ai-translator-for-translatepress' ) . '</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aitp_audit"><input type="hidden" name="do" value="autofix_settings">';
		wp_nonce_field( 'aitp_audit' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th>' . esc_html__( 'Switch', 'ai-translator-for-translatepress' ) . '</th><td><label><input type="checkbox" name="x[enabled]" value="1"' . checked( $set['enabled'], true, false ) . '> ' . esc_html__( 'Allow the batch auto repair', 'ai-translator-for-translatepress' ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Languages', 'ai-translator-for-translatepress' ) . '</th><td>';
		foreach ( $langs as $l ) {
			echo '<label style="margin-right:12px;"><input type="checkbox" name="x[languages][]" value="' . esc_attr( $l ) . '"' . checked( in_array( $l, $set['languages'], true ), true, false ) . '> ' . esc_html( $l ) . '</label>';
		}
		echo '<br><span class="description">' . esc_html__( 'None ticked = all languages, one language after another.', 'ai-translator-for-translatepress' ) . '</span></td></tr>';
		/* translators: 1: smallest, 2: largest batch */
		echo '<tr><th>' . esc_html__( 'Rows per batch', 'ai-translator-for-translatepress' ) . '</th><td><input type="number" name="x[batch]" min="' . (int) AITP_Autofix_Logic::MIN_BATCH . '" max="' . (int) AITP_Autofix_Logic::MAX_BATCH . '" value="' . (int) $set['batch'] . '" class="small-text"> <span class="description">' . esc_html( sprintf( __( '%1$s–%2$s; smaller batches stop sooner when something goes wrong.', 'ai-translator-for-translatepress' ), number_format_i18n( AITP_Autofix_Logic::MIN_BATCH ), number_format_i18n( AITP_Autofix_Logic::MAX_BATCH ) ) ) . '</span></td></tr>';
		echo '<tr><th>' . esc_html__( 'Characters per day', 'ai-translator-for-translatepress' ) . '</th><td><input type="number" name="x[cap]" min="1" value="' . (int) $set['cap'] . '" class="regular-text" style="width:140px;"> <span class="description">' . esc_html__( 'Own cap of the auto repair (default: half the TranslatePress daily limit). The TranslatePress limit and the share kept for visitors apply as well.', 'ai-translator-for-translatepress' ) . '</span></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Save', 'ai-translator-for-translatepress' ), 'secondary' );
		echo '</form>';

		if ( ! $set['enabled'] && $s['status'] === 'idle' ) {
			return;
		}
		$labels = array(
			'idle'    => __( 'not started', 'ai-translator-for-translatepress' ),
			'running' => __( 'running', 'ai-translator-for-translatepress' ),
			'stopped' => __( 'stopped', 'ai-translator-for-translatepress' ),
			'done'    => __( 'finished — nothing left it may repair', 'ai-translator-for-translatepress' ),
		);
		echo '<table class="widefat striped aitp-kv"><tbody>';
		$state = isset( $labels[ $s['status'] ] ) ? $labels[ $s['status'] ] : $s['status'];
		if ( $s['status'] === 'stopped' && $s['reason'] !== '' ) {
			$state .= ' — ' . self::autofix_reason( $s['reason'] );
		}
		if ( $s['status'] === 'running' && time() < (int) $s['wait_until'] ) {
			/* translators: 1: reason, 2: time */
			$state .= ' — ' . sprintf( __( 'waiting: %1$s (until %2$s)', 'ai-translator-for-translatepress' ), self::autofix_reason( $s['wait_reason'] ), wp_date( 'Y-m-d H:i', (int) $s['wait_until'] ) );
		}
		self::kv( __( 'State', 'ai-translator-for-translatepress' ), $state );
		if ( $s['batch'] ) {
			$b = $s['batch'];
			/* translators: 1: language, 2: rows, 3: pages visited, 4: pages, 5: round, 6: max rounds */
			self::kv( __( 'Current batch', 'ai-translator-for-translatepress' ), sprintf( __( '%1$s, %2$s rows — page %3$s of %4$s, round %5$s of %6$s', 'ai-translator-for-translatepress' ), $b['lang'], number_format_i18n( (int) $b['reset'] ), number_format_i18n( min( (int) $b['ui'], count( $b['urls'] ) ) ), number_format_i18n( count( $b['urls'] ) ), number_format_i18n( (int) $b['pass'] ), number_format_i18n( AITP_Autofix_Logic::MAX_PASSES ) ) );
		}
		/* translators: 1: used, 2: cap */
		self::kv( __( 'Characters today', 'ai-translator-for-translatepress' ), sprintf( __( '%1$s of %2$s', 'ai-translator-for-translatepress' ), number_format_i18n( (int) $s['day_chars'] ), number_format_i18n( (int) $set['cap'] ) ) );
		/* translators: 1: batches, 2: rows */
		self::kv( __( 'Done so far', 'ai-translator-for-translatepress' ), sprintf( __( '%1$s batches, %2$s rows translated again and passing the checks', 'ai-translator-for-translatepress' ), number_format_i18n( (int) $s['batches'] ), number_format_i18n( (int) $s['rows_ok'] ) ) );
		if ( $s['left']['structured'] || $s['left']['no_page'] ) {
			/* translators: 1: structured data rows, 2: rows without a known page */
			self::kv( __( 'Left for a person', 'ai-translator-for-translatepress' ), sprintf( __( '%1$s structured data, %2$s on no known page (map the pages that show them first)', 'ai-translator-for-translatepress' ), number_format_i18n( (int) $s['left']['structured'] ), number_format_i18n( (int) $s['left']['no_page'] ) ) );
		}
		echo '</tbody></table><p>';
		if ( $s['status'] === 'running' ) {
			self::button( 'autofix_stop', __( 'Stop auto repair', 'ai-translator-for-translatepress' ), 'secondary' );
			echo '</p><p><button type="button" class="button" id="aitp-autofix-live" data-label-stop="' . esc_attr__( 'Stop advancing from this page', 'ai-translator-for-translatepress' ) . '">' . esc_html__( 'Advance the auto repair from this page now', 'ai-translator-for-translatepress' ) . '</button> <span id="aitp-autofix-live-status" class="description"></span>';
		} elseif ( $set['enabled'] ) {
			$q = $s['batch'] ? __( 'Resume the auto repair with the current batch?', 'ai-translator-for-translatepress' ) : __( 'Start the batch auto repair? Each batch is backed up first and can be rolled back.', 'ai-translator-for-translatepress' );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;" onsubmit="return confirm(this.dataset.q)" data-q="' . esc_attr( $q ) . '"><input type="hidden" name="action" value="aitp_audit"><input type="hidden" name="do" value="autofix_start">';
			wp_nonce_field( 'aitp_audit' );
			echo '<button class="button button-primary">' . esc_html( $s['batch'] ? __( 'Resume auto repair', 'ai-translator-for-translatepress' ) : __( 'Start auto repair', 'ai-translator-for-translatepress' ) ) . '</button></form>';
		}
		echo '</p>';

		if ( $s['history'] ) {
			echo '<table class="widefat striped" style="max-width:1100px;"><thead><tr><th>' . esc_html__( 'Time', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Language', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Reset', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Pass the checks', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Still wrong', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Still untranslated', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Changed by a person', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Pages', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Characters', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Result', 'ai-translator-for-translatepress' ) . '</th></tr></thead><tbody>';
			foreach ( array_slice( $s['history'], 0, 20 ) as $h ) {
				$res = $h['result'] === 'ok' ? __( 'OK', 'ai-translator-for-translatepress' ) : self::autofix_reason( $h['result'] );
				echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i', (int) $h['time'] ) ) . '</td><td>' . esc_html( $h['lang'] ) . '</td><td>' . esc_html( number_format_i18n( (int) $h['reset'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $h['ok'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $h['wrong'] ) . ( $h['english'] ? ' (' . __( 'English', 'ai-translator-for-translatepress' ) . ': ' . number_format_i18n( (int) $h['english'] ) . ')' : '' ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $h['untranslated'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $h['manual'] + (int) $h['gone'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $h['pages'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $h['chars'] ) ) . '</td><td>' . ( $h['result'] === 'ok' ? '' : '<strong>' ) . esc_html( $res ) . ( $h['result'] === 'ok' ? '' : '</strong>' ) . '</td></tr>';
			}
			echo '</tbody></table>';
			echo '<p class="description">' . esc_html__( 'Rows still wrong after a batch are listed under Findings with the note "still wrong after the auto repair" and are not picked again. To undo a batch, use "Roll back" under Repairs (batches of the auto repair are marked there).', 'ai-translator-for-translatepress' ) . '</p>';
		}
	}

	/** @param array $set Settings. */
	private static function render_list( array $set ) {
		$f     = self::filters();
		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res   = AITP_Audit::find( $f, $paged, 50 );

		echo '<h2>' . esc_html__( 'Findings', 'ai-translator-for-translatepress' ) . '</h2>';
		echo '<form method="get" action="' . esc_url( admin_url( 'options-general.php' ) ) . '" class="aitp-filters"><input type="hidden" name="page" value="' . esc_attr( AITP_Admin::PAGE ) . '"><input type="hidden" name="tab" value="audit">';
		$opts = array(
			'state'    => array( 'open' => self::state_label( 'open' ), 'fixed' => self::state_label( 'fixed' ), 'dismissed' => self::state_label( 'dismissed' ), 'changed' => self::state_label( 'changed' ), 'accepted' => self::state_label( 'accepted' ), 'all' => self::state_label( 'all' ) ),
			'lang'     => array( '' => __( 'All languages', 'ai-translator-for-translatepress' ) ) + array_combine( AITP_Queue::tp_languages(), AITP_Queue::tp_languages() ),
			'severity' => array( '' => __( 'All problems', 'ai-translator-for-translatepress' ), 'error' => self::severity_label( 'error' ), 'suspect' => self::severity_label( 'suspect' ), 'review' => self::severity_label( 'review' ) ),
			'kind'     => array( '' => __( 'Page text and interface text', 'ai-translator-for-translatepress' ), 'dictionary' => __( 'Page text', 'ai-translator-for-translatepress' ), 'gettext' => __( 'Interface text (gettext)', 'ai-translator-for-translatepress' ) ),
			'ai'       => array( '' => __( 'Any AI result', 'ai-translator-for-translatepress' ), 'mismatch' => self::ai_label( 'mismatch' ), 'match' => self::ai_label( 'match' ), 'unsure' => self::ai_label( 'unsure' ) ),
			'source'   => array( '' => __( 'Found by check or import', 'ai-translator-for-translatepress' ), 'scan' => __( 'Found by check', 'ai-translator-for-translatepress' ), 'import' => __( 'Imported', 'ai-translator-for-translatepress' ) ),
		);
		foreach ( $opts as $k => $choices ) {
			echo '<select name="f_' . esc_attr( $k ) . '">';
			foreach ( $choices as $v => $l ) {
				echo '<option value="' . esc_attr( $v ) . '"' . selected( $f[ $k ] === '' && $k === 'state' ? 'open' : $f[ $k ], $v, false ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</select> ';
		}
		echo '<input type="text" name="f_code" value="' . esc_attr( $f['code'] ) . '" placeholder="' . esc_attr__( 'problem code', 'ai-translator-for-translatepress' ) . '" style="width:150px;"> ';
		echo '<button class="button">' . esc_html__( 'Filter', 'ai-translator-for-translatepress' ) . '</button></form>';

		/* translators: %s: number of findings */
		echo '<p>' . esc_html( sprintf( __( '%s findings match.', 'ai-translator-for-translatepress' ), number_format_i18n( $res['total'] ) ) ) . '</p>';
		if ( ! $res['rows'] ) {
			return;
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aitp_audit">';
		foreach ( self::filter_args( $f ) as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		wp_nonce_field( 'aitp_audit' );
		echo '<table class="widefat striped aitp-review aitp-audit"><thead><tr><td class="check-column"><input type="checkbox" onclick="document.querySelectorAll(\'.aitp-audit input[name=\\\'ids[]\\\']\').forEach(function(c){c.checked=this.checked;}.bind(this))"></td><th>' . esc_html__( 'Language', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Original', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Stored translation', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Problem', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'State', 'ai-translator-for-translatepress' ) . '</th></tr></thead><tbody>';
		foreach ( $res['rows'] as $r ) {
			$codes = array_map( array( 'AITP_Admin', 'code_label' ), array_filter( explode( ',', $r['codes'] ) ) );
			$where = $r['kind'] === 'gettext' ? ' · ' . __( 'interface', 'ai-translator-for-translatepress' ) : '';
			$state = self::state_label( $r['state'] );
			if ( $r['state'] === 'open' && $r['changed'] ) {
				$state .= ' · ' . __( 'changed since the scan', 'ai-translator-for-translatepress' );
			}
			echo '<tr><th class="check-column"><input type="checkbox" name="ids[]" value="' . (int) $r['id'] . '"></th><td>' . esc_html( $r['lang'] . $where ) . '<br><span class="description">#' . (int) $r['row_id'] . '</span></td><td>' . esc_html( AITP_Translator::excerpt( $r['original'], 400 ) ) . '</td><td>' . esc_html( AITP_Translator::excerpt( $r['translated'], 400 ) ) . '</td><td><strong>' . esc_html( self::severity_label( $r['severity'] ) ) . '</strong><br>' . esc_html( implode( ', ', $codes ) ) . ( $r['ai'] !== '' ? '<br>' . esc_html( self::ai_label( $r['ai'] ) ) : '' ) . '</td><td>' . esc_html( $state ) . '</td></tr>';
		}
		echo '</tbody></table>';

		$max = number_format_i18n( AITP_Audit_Logic::MAX_ACTION_ROWS );
		echo '<p class="aitp-audit-actions">';
		echo '<button class="button button-primary" name="do" value="repair" onclick="return confirm(' . esc_attr( wp_json_encode( __( 'Set the selected translations back to "not translated"? They are backed up first and can be rolled back.', 'ai-translator-for-translatepress' ) ) ) . ');">' . esc_html__( 'Reset selected (backup first)', 'ai-translator-for-translatepress' ) . '</button> ';
		/* translators: %s: maximum number of rows */
		$all_q = sprintf( __( 'Reset ALL findings matching the filter (at most %s)? They are backed up first and can be rolled back.', 'ai-translator-for-translatepress' ), $max );
		/* translators: %s: maximum number of rows */
		echo '<button class="button" name="do" value="repair_filter" onclick="return confirm(' . esc_attr( wp_json_encode( $all_q ) ) . ');">' . esc_html( sprintf( __( 'Reset all matching (at most %s)', 'ai-translator-for-translatepress' ), $max ) ) . '</button> ';
		echo '<button class="button" name="do" value="dismiss">' . esc_html__( 'Not a problem', 'ai-translator-for-translatepress' ) . '</button> ';
		echo '<br><label><input type="checkbox" name="unlinked" value="1" onchange="if(this.checked&&!confirm(this.dataset.q)){this.checked=false;}" data-q="' . esc_attr__( 'Also reset rows that are on no known page? Their pages are unknown, so every page of their language is queued again (and visited when the backfill queue runs). Usually it is better to map the pages that show them first.', 'ai-translator-for-translatepress' ) . '"> ' . esc_html__( 'Also reset rows on no known page (queues every page of their language again)', 'ai-translator-for-translatepress' ) . '</label><br>';
		echo '<details style="margin:4px 0;"><summary>' . esc_html__( 'Only use these pages (optional)', 'ai-translator-for-translatepress' ) . '</summary><textarea name="only_pages" rows="4" cols="90" placeholder="https://example.com/page/">' . '</textarea><br><span class="description">' . esc_html__( 'One address per line (default language or translated). Rows are queued only on pages of this list; rows not shown on any of them are skipped and stay open. Rows on no known page are never reset with a list.', 'ai-translator-for-translatepress' ) . '</span></details>';
		echo '<button class="button" name="do" value="reopen">' . esc_html__( 'Reopen', 'ai-translator-for-translatepress' ) . '</button> ';
		if ( $set['ai_check'] ) {
			echo '<button class="button" name="do" value="ai">' . esc_html__( 'AI check selected', 'ai-translator-for-translatepress' ) . '</button> ';
			/* translators: %s: maximum number of rows */
			echo '<button class="button" name="do" value="ai_filter">' . esc_html( sprintf( __( 'AI check all matching (at most %s)', 'ai-translator-for-translatepress' ), $max ) ) . '</button>';
		}
		echo '</p></form>';
		echo '<p class="description">' . esc_html__( 'Only rows on a known page are reset: a page TranslatePress links the string to, or a mapped page that shows it. One such page per row is queued again; start the backfill queue (or open those pages, or wait for visitors) to get new translations. Clear CDN or server caches outside WordPress yourself.', 'ai-translator-for-translatepress' ) . '</p>';

		$pages = (int) ceil( $res['total'] / 50 );
		if ( $pages > 1 ) {
			echo '<p>';
			for ( $i = 1; $i <= min( $pages, 200 ); $i++ ) {
				echo $i === $paged ? '<strong>' . (int) $i . '</strong> ' : '<a href="' . esc_url( self::url( self::filter_args( $f ) + array( 'paged' => $i ) ) ) . '">' . (int) $i . '</a> ';
			}
			echo '</p>';
		}
	}

	/** Page cache addresses still to clear after a repair or rollback. */
	private static function render_purge() {
		if ( AITP_Cache_Purge::status()['waiting'] ) {
			AITP_Cache_Purge::tick( 3 ); // Viewing this page also moves the list on.
		}
		$st = AITP_Cache_Purge::status();
		if ( ! $st['waiting'] ) {
			return;
		}
		/* translators: 1: addresses waiting, 2: addresses per step */
		echo '<div class="notice notice-info inline"><p>' . esc_html( sprintf( __( 'Page caches: %1$s addresses whose translations changed are still to be cleared (in the background, %2$s at a time).', 'ai-translator-for-translatepress' ), number_format_i18n( $st['waiting'] ), number_format_i18n( AITP_Cache_Purge::STEP ) ) );
		if ( $st['waiting'] > 1000 || $st['dropped'] ) {
			echo ' ' . esc_html__( 'With this many pages, clearing the whole cache in your cache plugin is quicker; then stop the list here.', 'ai-translator-for-translatepress' );
		}
		echo '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aitp_audit"><input type="hidden" name="do" value="purge_clear">';
		wp_nonce_field( 'aitp_audit' );
		echo '<p><button class="button">' . esc_html__( 'I cleared the whole cache: stop the list', 'ai-translator-for-translatepress' ) . '</button></p></form></div>';
	}

	/** Repair batches. */
	private static function render_batches() {
		$b = AITP_Audit::batches();
		if ( ! $b ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Repairs', 'ai-translator-for-translatepress' ) . '</h2><table class="widefat striped" style="max-width:1100px;"><thead><tr><th>' . esc_html__( 'Time', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Reset', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Skipped', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Pages queued', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Rolled back', 'ai-translator-for-translatepress' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( array_slice( $b, 0, 30, true ) as $id => $info ) {
			$rolled = '';
			if ( ! empty( $info['rolled_back'] ) ) {
				$rolled = wp_date( 'Y-m-d H:i', (int) $info['rolled_back'] ) . ' — ' . number_format_i18n( (int) $info['restored'] ) . ( ! empty( $info['restore_skipped'] ) ? ' (' . self::skipped_text( $info['restore_skipped'] ) . ')' : '' );
			}
			$inc  = ! empty( $info['incomplete'] ) ? __( 'batch record incomplete (the repair was cut off); shown from its backup rows, can be downloaded and rolled back', 'ai-translator-for-translatepress' ) . ( ! empty( $info['langs'] ) ? ' — ' . $info['langs'] : '' ) : '';
			$auto = ! empty( $info['auto'] ) ? ' · ' . __( 'auto repair', 'ai-translator-for-translatepress' ) . ( isset( $info['auto_result'] ) && $info['auto_result'] !== 'ok' ? ' (' . self::autofix_reason( $info['auto_result'] ) . ')' : '' ) : '';
			echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i:s', (int) $info['time'] ) . $auto ) . ( $inc !== '' ? '<br><strong>' . esc_html( $inc ) . '</strong>' : '' ) . '<br><span class="description">#' . esc_html( (string) $id ) . '</span></td><td>' . esc_html( number_format_i18n( (int) $info['reset'] ) ) . '</td><td>' . esc_html( self::skipped_text( (array) $info['skipped'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $info['pages'] ) ) . '</td><td>' . esc_html( $rolled ) . '</td><td>';
			echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aitp_audit_backup&batch=' . rawurlencode( (string) $id ) ), 'aitp_audit_backup' ) ) . '">' . esc_html__( 'Download backup', 'ai-translator-for-translatepress' ) . '</a> ';
			if ( empty( $info['rolled_back'] ) ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;" onsubmit="return confirm(' . esc_attr( wp_json_encode( __( 'Put the old translations of this repair back? Rows translated manually since then are kept.', 'ai-translator-for-translatepress' ) ) ) . ');"><input type="hidden" name="action" value="aitp_audit"><input type="hidden" name="do" value="rollback"><input type="hidden" name="batch" value="' . esc_attr( (string) $id ) . '">';
				wp_nonce_field( 'aitp_audit' );
				echo '<button class="button">' . esc_html__( 'Roll back', 'ai-translator-for-translatepress' ) . '</button></form>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Import form. */
	private static function render_import() {
		echo '<h2>' . esc_html__( 'Import a candidate list', 'ai-translator-for-translatepress' ) . '</h2>';
		echo '<p class="description" style="max-width:860px;">' . esc_html__( 'JSON or CSV with the columns language, id, original and old (the stored translation); optional table = gettext. A row is added only when id, original and old translation all match the current TranslatePress row and it is still machine translated. Nothing is changed by an import.', 'ai-translator-for-translatepress' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aitp_audit"><input type="hidden" name="do" value="import">';
		wp_nonce_field( 'aitp_audit' );
		echo '<input type="file" name="list" accept=".json,.csv,application/json,text/csv"> <button class="button">' . esc_html__( 'Import', 'ai-translator-for-translatepress' ) . '</button></form>';

		echo '<h2>' . esc_html__( 'Accepted list', 'ai-translator-for-translatepress' ) . '</h2>';
		echo '<p class="description" style="max-width:860px;">' . esc_html__( 'Translations people have already reviewed (for example rows corrected by hand or by an earlier repair). The check still lists them, but they never count as open and can never be repaired. JSON or CSV with the columns language, id, original and translated (or new); optional table = gettext. An entry is kept only when it matches the current row exactly; when the row changes later, the protection no longer applies.', 'ai-translator-for-translatepress' ) . '</p>';
		$lists = AITP_Audit::protected_lists();
		if ( $lists ) {
			echo '<table class="widefat striped" style="max-width:820px;"><thead><tr><th>' . esc_html__( 'List', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Rows', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Added', 'ai-translator-for-translatepress' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $lists as $l ) {
				echo '<tr><td>' . esc_html( $l['label'] ) . '</td><td>' . esc_html( number_format_i18n( (int) $l['n'] ) ) . '</td><td>' . esc_html( get_date_from_gmt( $l['created'], 'Y-m-d H:i' ) ) . '</td><td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(this.dataset.q)" data-q="' . esc_attr__( 'Remove this accepted list? Its rows can then be repaired again.', 'ai-translator-for-translatepress' ) . '"><input type="hidden" name="action" value="aitp_audit"><input type="hidden" name="do" value="unprotect"><input type="hidden" name="label" value="' . esc_attr( $l['label'] ) . '">';
				wp_nonce_field( 'aitp_audit' );
				echo '<button class="button button-link-delete">' . esc_html__( 'Remove', 'ai-translator-for-translatepress' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:8px;"><input type="hidden" name="action" value="aitp_audit"><input type="hidden" name="do" value="protect">';
		wp_nonce_field( 'aitp_audit' );
		echo '<input type="text" name="label" maxlength="64" placeholder="' . esc_attr__( 'Name of the list', 'ai-translator-for-translatepress' ) . '"> <input type="file" name="list" accept=".json,.csv,application/json,text/csv"> <button class="button">' . esc_html__( 'Add to the accepted list', 'ai-translator-for-translatepress' ) . '</button></form>';
	}

	/**
	 * Read an uploaded list (max. 10 MB).
	 *
	 * @return string|null
	 */
	private static function uploaded() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.NonceVerification.Missing
		$file = isset( $_FILES['list'] ) && is_array( $_FILES['list'] ) ? $_FILES['list'] : null;
		if ( ! $file || ! empty( $file['error'] ) || ! is_uploaded_file( $file['tmp_name'] ) || (int) $file['size'] > 10 * MB_IN_BYTES ) {
			return null;
		}
		return (string) file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
	}

	/** @param array $set Settings. */
	private static function render_settings( array $set ) {
		echo '<h2>' . esc_html__( 'Check settings', 'ai-translator-for-translatepress' ) . '</h2><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aitp_audit"><input type="hidden" name="do" value="settings">';
		wp_nonce_field( 'aitp_audit' );
		echo '<p><label><input type="checkbox" name="a[gettext]" value="1"' . checked( $set['gettext'], true, false ) . '> ' . esc_html__( 'Also check interface text (gettext)', 'ai-translator-for-translatepress' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="a[ai_check]" value="1"' . checked( $set['ai_check'], true, false ) . '> ' . esc_html__( 'Allow the AI check', 'ai-translator-for-translatepress' ) . '</label><br><span class="description">' . esc_html__( 'Asks the configured model whether each selected translation belongs to its original. Rules cannot catch every short misplaced label; the AI can, but each check costs characters (counted against the TranslatePress daily limit) and the AI can be wrong too. Only runs on rows you select.', 'ai-translator-for-translatepress' ) . '</span></p>';
		submit_button( __( 'Save', 'ai-translator-for-translatepress' ) );
		echo '</form>';
	}

	/**
	 * @param string $do    Action.
	 * @param string $label Text.
	 * @param string $class primary|secondary.
	 */
	private static function button( $do, $label, $class ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:6px;"><input type="hidden" name="action" value="aitp_audit"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		wp_nonce_field( 'aitp_audit' );
		echo '<button type="submit" class="button' . ( $class === 'primary' ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
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
		check_admin_referer( 'aitp_audit' );
		$do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$ids  = isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ? array_map( 'intval', wp_unslash( $_POST['ids'] ) ) : array();
		$f    = self::filters();
		$msg  = '';
		$type = 'success';
		switch ( $do ) {
			case 'scan':
				AITP_Audit::start_scan();
				AITP_Audit::step( 15 );
				$msg = __( 'Check started. It continues in the background; you can also advance it from this page.', 'ai-translator-for-translatepress' );
				break;
			case 'stop':
				AITP_Audit::stop_scan();
				$msg = __( 'Check stopped. Findings so far are kept.', 'ai-translator-for-translatepress' );
				break;
			case 'repair':
			case 'repair_filter':
				$sel = $do === 'repair' ? $ids : AITP_Audit::ids_for( $f );
				if ( ! $sel ) {
					$msg  = __( 'Nothing selected.', 'ai-translator-for-translatepress' );
					$type = 'warning';
					break;
				}
				$only = isset( $_POST['only_pages'] ) ? array_values( array_filter( array_map( 'trim', preg_split( '~\R~', sanitize_textarea_field( wp_unslash( $_POST['only_pages'] ) ) ) ) ) ) : array();
				$r    = AITP_Audit::repair( $sel, array( 'unlinked' => ! empty( $_POST['unlinked'] ), 'pages' => array_map( 'esc_url_raw', $only ) ) );
				/* translators: 1: rows reset, 2: pages queued */
				$msg = sprintf( __( '%1$s translations backed up and reset; %2$s pages queued again.', 'ai-translator-for-translatepress' ), number_format_i18n( $r['reset'] ), number_format_i18n( $r['pages'] ) );
				if ( $r['skipped'] ) {
					/* translators: %s: reasons and counts */
					$msg .= ' ' . sprintf( __( 'Skipped: %s.', 'ai-translator-for-translatepress' ), self::skipped_text( $r['skipped'] ) );
				}
				if ( $r['no_page'] ) {
					$msg .= ' ' . __( 'Rows on no known page stay as they are: map the pages that show them (tab "Page map & test journal"), or tick "also reset rows on no known page".', 'ai-translator-for-translatepress' );
				}
				if ( $r['all_pages'] ) {
					/* translators: %s: languages */
					$msg .= ' ' . sprintf( __( 'Rows on no known page were reset as requested: every page of %s was queued again.', 'ai-translator-for-translatepress' ), implode( ', ', $r['all_pages'] ) );
				}
				if ( AITP_Queue::state()['status'] !== 'running' ) {
					$msg .= ' ' . __( 'The backfill queue is paused: start it, or the strings are translated again when visitors open the pages.', 'ai-translator-for-translatepress' );
				}
				break;
			case 'purge_clear':
				AITP_Cache_Purge::clear();
				$msg = __( 'The list of page caches to clear was emptied.', 'ai-translator-for-translatepress' );
				break;
			case 'rollback':
				$batch = isset( $_POST['batch'] ) ? (int) $_POST['batch'] : 0;
				$r     = AITP_Audit::rollback( $batch );
				/* translators: %s: rows restored */
				$msg = sprintf( __( '%s translations restored.', 'ai-translator-for-translatepress' ), number_format_i18n( $r['restored'] ) );
				if ( $r['skipped'] ) {
					/* translators: %s: reasons and counts */
					$msg .= ' ' . sprintf( __( 'Kept as they are: %s.', 'ai-translator-for-translatepress' ), self::skipped_text( $r['skipped'] ) );
				}
				break;
			case 'dismiss':
			case 'reopen':
				$n = AITP_Audit::set_finding_state( $ids, $do === 'dismiss' ? 'dismissed' : 'open' );
				/* translators: %s: number of rows */
				$msg = sprintf( __( '%s findings updated.', 'ai-translator-for-translatepress' ), number_format_i18n( $n ) );
				break;
			case 'ai':
			case 'ai_filter':
				$sel = $do === 'ai' ? $ids : AITP_Audit::ids_for( $f );
				$r   = AITP_Audit::ai_check( $sel, 40 );
				if ( $r['stop'] === 'disabled' || $r['stop'] === 'not_configured' ) {
					$msg  = $r['stop'] === 'disabled' ? __( 'The AI check is turned off in the check settings below.', 'ai-translator-for-translatepress' ) : __( 'Enter the API key and model first.', 'ai-translator-for-translatepress' );
					$type = 'warning';
					break;
				}
				/* translators: 1: checked, 2: mismatches, 3: matches, 4: unsure */
				$msg = sprintf( __( 'AI check: %1$s checked — %2$s do not match, %3$s match, %4$s unsure.', 'ai-translator-for-translatepress' ), number_format_i18n( $r['checked'] ), number_format_i18n( $r['mismatch'] ), number_format_i18n( $r['match'] ), number_format_i18n( $r['unsure'] ) );
				if ( $r['left'] ) {
					/* translators: %s: number of rows */
					$msg .= ' ' . sprintf( __( '%s not checked yet (time or provider limit); run it again.', 'ai-translator-for-translatepress' ), number_format_i18n( count( $r['left'] ) ) );
				}
				if ( $r['stop'] !== '' ) {
					$msg .= ' ' . AITP_Admin::code_label( $r['stop'] );
				}
				break;
			case 'import':
				$content = self::uploaded();
				if ( $content === null ) {
					$msg  = __( 'No file, or the file is larger than 10 MB.', 'ai-translator-for-translatepress' );
					$type = 'warning';
					break;
				}
				$r = AITP_Audit::import( $content );
				/* translators: 1: rows read, 2: rows added, 3: changed, 4: manual, 5: missing or unreadable, 6: on the accepted list */
				$msg = sprintf( __( 'Import: %1$s rows read, %2$s added. Not added — changed since: %3$s, translated manually: %4$s, missing or unreadable: %5$s, on the accepted list: %6$s.', 'ai-translator-for-translatepress' ), number_format_i18n( $r['read'] ), number_format_i18n( $r['added'] ), number_format_i18n( $r['changed'] + $r['untranslated'] ), number_format_i18n( $r['manual'] ), number_format_i18n( $r['missing'] + $r['bad'] ), number_format_i18n( $r['accepted'] ) );
				$f   = array( 'source' => 'import' ) + $f;
				break;
			case 'protect':
				$content = self::uploaded();
				if ( $content === null ) {
					$msg  = __( 'No file, or the file is larger than 10 MB.', 'ai-translator-for-translatepress' );
					$type = 'warning';
					break;
				}
				$r = AITP_Audit::protect( $content, isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '' );
				/* translators: 1: rows read, 2: added, 3: of them found by original text, 4: already on the list, 5: changed, 6: manual, 7: missing or unreadable, 8: original found more than once */
				$msg = sprintf( __( 'Accepted list: %1$s rows read, %2$s added (%3$s of them found by their original text because the ID differs), %4$s already on the list. Not added — text differs from the site: %5$s, translated manually (protected anyway): %6$s, missing or unreadable: %7$s, original found more than once: %8$s.', 'ai-translator-for-translatepress' ), number_format_i18n( $r['read'] ), number_format_i18n( $r['added'] ), number_format_i18n( $r['by_original'] ), number_format_i18n( $r['already'] ), number_format_i18n( $r['changed'] + $r['untranslated'] ), number_format_i18n( $r['manual'] ), number_format_i18n( $r['missing'] + $r['bad'] ), number_format_i18n( $r['duplicate'] ) );
				break;
			case 'unprotect':
				$n = AITP_Audit::unprotect( isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '' );
				/* translators: %s: number of rows */
				$msg = sprintf( __( '%s rows removed from the accepted list.', 'ai-translator-for-translatepress' ), number_format_i18n( $n ) );
				break;
			case 'autofix_settings':
				$in  = isset( $_POST['x'] ) && is_array( $_POST['x'] ) ? map_deep( wp_unslash( $_POST['x'] ), 'sanitize_text_field' ) : array();
				$out = AITP_Autofix::save_settings( $in );
				$msg = $out['enabled'] ? __( 'Saved. The auto repair is allowed; start it below.', 'ai-translator-for-translatepress' ) : __( 'Saved. The auto repair is off.', 'ai-translator-for-translatepress' );
				break;
			case 'autofix_start':
				$why = AITP_Autofix::start();
				if ( $why !== '' ) {
					/* translators: %s: reason */
					$msg  = sprintf( __( 'The auto repair cannot start: %s.', 'ai-translator-for-translatepress' ), self::autofix_reason( $why ) );
					$type = 'warning';
					break;
				}
				AITP_Autofix::step( 15 );
				$msg = __( 'Auto repair started. It continues in the background (WP-Cron); you can also advance it from this page.', 'ai-translator-for-translatepress' );
				break;
			case 'autofix_stop':
				AITP_Autofix::stop( 'manual' );
				$msg = __( 'Auto repair stopped. The current batch stays as it is: roll it back under Repairs, or resume later.', 'ai-translator-for-translatepress' );
				break;
			case 'settings':
				$in = isset( $_POST['a'] ) && is_array( $_POST['a'] ) ? wp_unslash( $_POST['a'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only checked for presence.
				AITP_Audit::save_settings( $in );
				$msg = __( 'Saved.', 'ai-translator-for-translatepress' );
				break;
		}
		if ( $msg !== '' ) {
			set_transient( 'aitp_flash_' . get_current_user_id(), array( 'type' => $type, 'message' => $msg ), 120 );
		}
		wp_safe_redirect( self::url( self::filter_args( $f ) ) );
		exit;
	}

	/** Download the backup of one repair (JSON). */
	public static function download_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ai-translator-for-translatepress' ), 403 );
		}
		check_admin_referer( 'aitp_audit_backup' );
		$batch = isset( $_GET['batch'] ) ? (int) $_GET['batch'] : 0;
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="aitp-repair-' . $batch . '.json"' );
		echo wp_json_encode( array( 'batch' => $batch, 'rows' => AITP_Audit::backup_rows( $batch ) ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		exit;
	}

	/** One auto repair step from the open admin page (AJAX). */
	public static function ajax_autofix_step() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'aitp_autofix_step' );
		$r = AITP_Autofix::step( 25 );
		$s = $r['state'];
		/* translators: 1: batches, 2: rows */
		$text = sprintf( __( '%1$s batches done, %2$s rows passing', 'ai-translator-for-translatepress' ), number_format_i18n( (int) $s['batches'] ), number_format_i18n( (int) $s['rows_ok'] ) );
		if ( $s['batch'] ) {
			/* translators: 1: pages visited, 2: pages, 3: round */
			$text .= ' — ' . sprintf( __( 'current batch: page %1$s of %2$s, round %3$s', 'ai-translator-for-translatepress' ), number_format_i18n( min( (int) $s['batch']['ui'], count( $s['batch']['urls'] ) ) ), number_format_i18n( count( $s['batch']['urls'] ) ), number_format_i18n( (int) $s['batch']['pass'] ) );
		}
		if ( $s['status'] === 'stopped' ) {
			$text .= ' — ' . __( 'stopped', 'ai-translator-for-translatepress' ) . ': ' . self::autofix_reason( $s['reason'] );
		} elseif ( $s['status'] === 'done' ) {
			$text .= ' — ' . __( 'finished', 'ai-translator-for-translatepress' );
		} elseif ( $r['result'] === 'waiting' ) {
			$text .= ' — ' . self::autofix_reason( $s['wait_reason'] );
		} elseif ( $r['result'] === 'busy' ) {
			$text .= ' — ' . __( 'another run is in progress', 'ai-translator-for-translatepress' );
		}
		wp_send_json_success(
			array(
				'result' => $r['result'],
				'wait'   => min( 60, max( 2, (int) $r['wait'] ) ),
				'text'   => $text,
				'stop'   => in_array( $s['status'], array( 'stopped', 'done', 'idle' ), true ),
			)
		);
	}

	/** One scan step from the open admin page (AJAX). */
	public static function ajax_step() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'aitp_audit_step' );
		$r = AITP_Audit::step( 20 );
		$s = $r['state'];
		/* translators: 1: rows checked, 2: open findings */
		$text = sprintf( __( '%1$s checked, %2$s findings', 'ai-translator-for-translatepress' ), number_format_i18n( (int) $s['scanned'] ), number_format_i18n( AITP_Audit::counts()['open'] ) );
		if ( $r['result'] === 'busy' ) {
			$text .= ' — ' . __( 'another run is in progress', 'ai-translator-for-translatepress' );
		}
		wp_send_json_success(
			array(
				'result' => $r['result'],
				'wait'   => $r['result'] === 'busy' ? 10 : 0,
				'text'   => $text,
				'stop'   => in_array( $r['result'], array( 'done', 'idle' ), true ),
			)
		);
	}
}
