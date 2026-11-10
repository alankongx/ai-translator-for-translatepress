<?php
/**
 * Admin tab "Page map & test journal".
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Admin_Pages {

	/** Hooks. */
	public static function boot() {
		add_action( 'admin_post_aitp_pages', array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_aitp_pages_download', array( __CLASS__, 'download' ) );
		add_action( 'wp_ajax_aitp_map_step', array( __CLASS__, 'ajax_step' ) );
	}

	/**
	 * @param array $args Query args.
	 * @return string
	 */
	private static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => AITP_Admin::PAGE, 'tab' => 'pages' ), $args ), admin_url( 'options-general.php' ) );
	}

	/**
	 * @param string $s State.
	 * @return string
	 */
	private static function status_label( $s ) {
		$map = array(
			'idle'    => __( 'not run yet', 'ai-translator-for-translatepress' ),
			'running' => __( 'running', 'ai-translator-for-translatepress' ),
			'stopped' => __( 'stopped', 'ai-translator-for-translatepress' ),
			'done'    => __( 'finished', 'ai-translator-for-translatepress' ),
		);
		return isset( $map[ $s ] ) ? $map[ $s ] : $s;
	}

	/**
	 * @param string $why Reason.
	 * @return string
	 */
	private static function reason( $why ) {
		$map = array(
			'running'           => __( 'a run is already in progress', 'ai-translator-for-translatepress' ),
			'no_translatepress' => __( 'TranslatePress is not active', 'ai-translator-for-translatepress' ),
			'no_pages'          => __( 'no usable page address', 'ai-translator-for-translatepress' ),
			/* translators: %s: maximum */
			'too_many'          => sprintf( __( 'more than %s pages × languages; choose fewer', 'ai-translator-for-translatepress' ), number_format_i18n( AITP_Pagemap::MAX_ITEMS ) ),
		);
		return isset( $map[ $why ] ) ? $map[ $why ] : $why;
	}

	/**
	 * @param string $k Journal outcome.
	 * @return string
	 */
	private static function journal_label( $k ) {
		$map = array(
			'open'      => __( 'not undone yet', 'ai-translator-for-translatepress' ),
			'yes'       => __( 'undone', 'ai-translator-for-translatepress' ),
			'changed'   => __( 'changed since (kept)', 'ai-translator-for-translatepress' ),
			'manual'    => __( 'translated manually since (kept)', 'ai-translator-for-translatepress' ),
			'missing'   => __( 'row no longer exists', 'ai-translator-for-translatepress' ),
			'not_saved' => __( 'never saved by TranslatePress', 'ai-translator-for-translatepress' ),
			'new_row'   => __( 'new row (listed below, not deleted)', 'ai-translator-for-translatepress' ),
		);
		return isset( $map[ $k ] ) ? $map[ $k ] : $k;
	}

	/**
	 * @param int $st TranslatePress row status.
	 * @return string
	 */
	private static function row_status( $st ) {
		if ( (int) $st === 1 ) {
			return __( 'machine translated', 'ai-translator-for-translatepress' );
		}
		if ( (int) $st === 2 ) {
			return __( 'translated manually', 'ai-translator-for-translatepress' );
		}
		return __( 'not translated', 'ai-translator-for-translatepress' );
	}

	/** Render the tab. */
	public static function render() {
		self::render_map();
		self::render_journal();
	}

	/** Page mapping. */
	private static function render_map() {
		$s     = AITP_Pagemap::state();
		$langs = AITP_Queue::tp_languages();
		echo '<h2>' . esc_html__( 'Page map', 'ai-translator-for-translatepress' ) . '</h2>';
		echo '<p class="description" style="max-width:860px;">' . esc_html__( 'TranslatePress links a string to a page only when it is inside the post content, so headings, menus, theme and page-builder text belong to no page and a repair cannot know which pages to visit. Mapping opens each page once in read-only mode – machine translation is switched off for that request and TranslatePress saves no strings – and records which page-text rows of that language the page shows, matched by their complete original text. Repairs then reset only rows on a known page and visit only those pages. Selected pages can be downloaded as a candidate list for a page-by-page comparison. Mapping costs no AI characters; it loads pages like a visitor (one every 5 seconds).', 'ai-translator-for-translatepress' ) . '</p>';

		echo '<table class="widefat striped aitp-kv" style="max-width:860px;"><tbody>';
		$state = self::status_label( $s['status'] );
		if ( $s['items'] ) {
			/* translators: 1: pages done, 2: pages, 3: read, 4: failed */
			$state .= ' — ' . sprintf( __( '%1$s of %2$s pages (%3$s read, %4$s failed)', 'ai-translator-for-translatepress' ), number_format_i18n( (int) $s['i'] ), number_format_i18n( count( $s['items'] ) ), number_format_i18n( (int) $s['ok'] ), number_format_i18n( (int) $s['failed'] ) );
		}
		echo '<tr><th>' . esc_html__( 'State', 'ai-translator-for-translatepress' ) . '</th><td>' . esc_html( $state ) . '</td></tr>';
		echo '</tbody></table>';

		if ( $s['status'] === 'running' ) {
			echo '<p>';
			self::button( 'map_stop', __( 'Stop mapping', 'ai-translator-for-translatepress' ), '' );
			echo '</p><p><button type="button" class="button" id="aitp-map-live" data-label-stop="' . esc_attr__( 'Stop advancing from this page', 'ai-translator-for-translatepress' ) . '">' . esc_html__( 'Advance the mapping from this page now', 'ai-translator-for-translatepress' ) . '</button> <span id="aitp-map-live-status" class="description"></span></p>';
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aitp_pages"><input type="hidden" name="do" value="map_start">';
			wp_nonce_field( 'aitp_pages' );
			echo '<table class="form-table" role="presentation"><tbody>';
			echo '<tr><th>' . esc_html__( 'Page addresses', 'ai-translator-for-translatepress' ) . '</th><td><textarea name="urls" rows="5" class="large-text code" placeholder="' . esc_attr( home_url( '/' ) ) . '"></textarea><br><span class="description">' . esc_html__( 'Addresses in the default language, one per line. Empty = every page of the backfill queue\'s page list (generate it there first).', 'ai-translator-for-translatepress' ) . '</span></td></tr>';
			echo '<tr><th>' . esc_html__( 'Languages', 'ai-translator-for-translatepress' ) . '</th><td>';
			foreach ( $langs as $l ) {
				echo '<label style="margin-right:12px;"><input type="checkbox" name="langs[]" value="' . esc_attr( $l ) . '"> ' . esc_html( $l ) . '</label>';
			}
			echo '<br><span class="description">' . esc_html__( 'None ticked = all languages.', 'ai-translator-for-translatepress' ) . '</span></td></tr>';
			echo '</tbody></table><p><button class="button button-primary">' . esc_html__( 'Map these pages', 'ai-translator-for-translatepress' ) . '</button></p></form>';
		}

		// Mapped pages.
		$lang  = isset( $_GET['m_lang'] ) ? sanitize_text_field( wp_unslash( $_GET['m_lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$q     = isset( $_GET['m_q'] ) ? sanitize_text_field( wp_unslash( $_GET['m_q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$res   = AITP_Pagemap::pages( array( 'lang' => $lang, 'q' => $q, 'page' => $paged, 'per_page' => 50 ) );
		echo '<h3>' . esc_html__( 'Mapped pages', 'ai-translator-for-translatepress' ) . '</h3>';
		echo '<form method="get" action="' . esc_url( admin_url( 'options-general.php' ) ) . '" class="aitp-filters"><input type="hidden" name="page" value="' . esc_attr( AITP_Admin::PAGE ) . '"><input type="hidden" name="tab" value="pages">';
		echo '<select name="m_lang"><option value="">' . esc_html__( 'All languages', 'ai-translator-for-translatepress' ) . '</option>';
		foreach ( $langs as $l ) {
			echo '<option value="' . esc_attr( $l ) . '"' . selected( $lang, $l, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select> <input type="text" name="m_q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'address contains', 'ai-translator-for-translatepress' ) . '"> <button class="button">' . esc_html__( 'Filter', 'ai-translator-for-translatepress' ) . '</button></form>';
		/* translators: %s: number of pages */
		echo '<p>' . esc_html( sprintf( __( '%s mapped pages match.', 'ai-translator-for-translatepress' ), number_format_i18n( $res['total'] ) ) ) . '</p>';
		if ( ! $res['rows'] ) {
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aitp_pages_download"><input type="hidden" name="what" value="candidates">';
		wp_nonce_field( 'aitp_pages_download' );
		echo '<table class="widefat striped aitp-map"><thead><tr><td class="check-column"><input type="checkbox" onclick="document.querySelectorAll(\'.aitp-map input[name=\\\'pages[]\\\']\').forEach(function(c){c.checked=this.checked;}.bind(this))"></td><th>' . esc_html__( 'Page', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Language', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Linked by TranslatePress', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Found on the page', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Machine / manual / not translated', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Text with no row', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Mapped', 'ai-translator-for-translatepress' ) . '</th></tr></thead><tbody>';
		foreach ( $res['rows'] as $r ) {
			$cover = (int) $r['found'] === 0 ? ' <strong>' . esc_html__( 'not covered', 'ai-translator-for-translatepress' ) . '</strong>' : '';
			$fail  = $r['note'] !== '' ? ' · ' . sprintf( /* translators: %s: HTTP status */ __( 'last visit failed (HTTP %s)', 'ai-translator-for-translatepress' ), (int) $r['http'] ) : '';
			/* translators: %s: rows linked by TranslatePress that the page also shows */
			$meta = $r['object_type'] === 'post' ? number_format_i18n( (int) $r['meta'] ) . ' <span class="description">(' . esc_html( sprintf( __( '%s of them on the page', 'ai-translator-for-translatepress' ), number_format_i18n( (int) $r['meta_found'] ) ) ) . ')</span>' : '—';
			echo '<tr><th class="check-column"><input type="checkbox" name="pages[]" value="' . (int) $r['id'] . '"></th><td><a href="' . esc_url( $r['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $r['url'] ) . '</a>' . $cover . '<br><span class="description">#' . (int) $r['id'] . esc_html( $fail ) . '</span></td><td>' . esc_html( $r['lang'] ) . '</td><td>' . $meta . '</td><td>' . esc_html( number_format_i18n( (int) $r['found'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $r['s1'] ) . ' / ' . number_format_i18n( (int) $r['s2'] ) . ' / ' . number_format_i18n( (int) $r['s0'] ) ) . '</td><td>' . esc_html( number_format_i18n( (int) $r['unknown'] ) ) . '</td><td>' . esc_html( get_date_from_gmt( $r['mapped_at'], 'Y-m-d H:i' ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $meta and $cover are escaped above.
		}
		echo '</tbody></table>';
		echo '<p><button class="button button-primary" name="scope" value="selected">' . esc_html__( 'Download candidate list of the selected pages', 'ai-translator-for-translatepress' ) . '</button> <button class="button" name="scope" value="all">' . esc_html__( 'Download candidate list of all mapped pages', 'ai-translator-for-translatepress' ) . '</button></p>';
		echo '<p class="description" style="max-width:860px;">' . esc_html__( 'The list holds the machine-translated rows the pages show (manual translations and untranslated rows are left out), in the format of "Import a candidate list" under Audit & repair. Rows linked by TranslatePress but no longer shown on the page are not in it: visiting the page would not translate them again.', 'ai-translator-for-translatepress' ) . '</p></form>';
		$pages = (int) ceil( $res['total'] / 50 );
		if ( $pages > 1 ) {
			echo '<p>';
			for ( $i = 1; $i <= min( $pages, 200 ); $i++ ) {
				echo $i === $paged ? '<strong>' . (int) $i . '</strong> ' : '<a href="' . esc_url( self::url( array( 'm_lang' => $lang, 'm_q' => $q, 'paged' => $i ) ) ) . '">' . (int) $i . '</a> ';
			}
			echo '</p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(this.dataset.q)" data-q="' . esc_attr__( 'Forget every mapped page? TranslatePress data is not touched; repairs then only know the pages TranslatePress links.', 'ai-translator-for-translatepress' ) . '"><input type="hidden" name="action" value="aitp_pages"><input type="hidden" name="do" value="map_clear">';
		wp_nonce_field( 'aitp_pages' );
		echo '<button class="button button-link-delete">' . esc_html__( 'Clear the page map', 'ai-translator-for-translatepress' ) . '</button></form>';
	}

	/** Test journal. */
	private static function render_journal() {
		$s = AITP_Journal::state();
		echo '<h2>' . esc_html__( 'Test journal', 'ai-translator-for-translatepress' ) . '</h2>';
		echo '<p class="description" style="max-width:860px;">' . esc_html__( 'For tests on a staging site. While the journal is on, every translation this engine hands to TranslatePress is written down with the exact state the row had before. After the test, roll back the repair batches first, then "Undo translations of the test": rows that still hold exactly our translation get their earlier state back (usually "not translated"). Rows TranslatePress added during the test (strings it saw for the first time) are listed and can be downloaded; they are never deleted. Restoring a full database backup stays a decision for the site owner.', 'ai-translator-for-translatepress' ) . '</p>';
		echo '<table class="widefat striped aitp-kv" style="max-width:860px;"><tbody>';
		echo '<tr><th>' . esc_html__( 'State', 'ai-translator-for-translatepress' ) . '</th><td>' . esc_html( $s['on'] ? __( 'on — recording', 'ai-translator-for-translatepress' ) : __( 'off', 'ai-translator-for-translatepress' ) ) . '</td></tr>';
		if ( $s['session'] ) {
			echo '<tr><th>' . esc_html__( 'Test', 'ai-translator-for-translatepress' ) . '</th><td>' . esc_html( ( $s['label'] !== '' ? $s['label'] . ' · ' : '' ) . wp_date( 'Y-m-d H:i', (int) $s['started'] ) . ( $s['stopped'] ? ' – ' . wp_date( 'Y-m-d H:i', (int) $s['stopped'] ) : '' ) ) . '</td></tr>';
			$sum = AITP_Journal::summary();
			$e   = array();
			foreach ( $sum['entries'] as $k => $n ) {
				$e[] = self::journal_label( $k ) . ': ' . number_format_i18n( $n );
			}
			echo '<tr><th>' . esc_html__( 'Translations recorded', 'ai-translator-for-translatepress' ) . '</th><td>' . esc_html( $e ? implode( ', ', $e ) : '0' ) . '</td></tr>';
			$n = array();
			foreach ( $sum['new_rows'] as $r ) {
				$n[] = $r['lang'] . ( $r['kind'] === 'gettext' ? ' · ' . __( 'interface', 'ai-translator-for-translatepress' ) : '' ) . ' · ' . self::row_status( $r['status'] ) . ': ' . number_format_i18n( $r['rows'] );
			}
			echo '<tr><th>' . esc_html__( 'Rows TranslatePress added since the start', 'ai-translator-for-translatepress' ) . '</th><td>' . esc_html( $n ? implode( '; ', $n ) : '0' ) . '</td></tr>';
		}
		echo '</tbody></table><p>';
		if ( $s['on'] ) {
			self::button( 'journal_stop', __( 'Switch the journal off', 'ai-translator-for-translatepress' ), '' );
		} else {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:6px;"' . ( $s['session'] ? ' onsubmit="return confirm(this.dataset.q)" data-q="' . esc_attr__( 'Start a new test? What the previous test recorded can then no longer be undone here.', 'ai-translator-for-translatepress' ) . '"' : '' ) . '><input type="hidden" name="action" value="aitp_pages"><input type="hidden" name="do" value="journal_start">';
			wp_nonce_field( 'aitp_pages' );
			echo '<input type="text" name="label" maxlength="64" placeholder="' . esc_attr__( 'Name of the test', 'ai-translator-for-translatepress' ) . '"> <button class="button button-primary">' . esc_html__( 'Switch the journal on', 'ai-translator-for-translatepress' ) . '</button></form>';
		}
		if ( $s['session'] ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:6px;" onsubmit="return confirm(this.dataset.q)" data-q="' . esc_attr__( 'Put every row this engine translated during the test back to its state before? Roll back the repair batches of the test first. Rows changed since are kept.', 'ai-translator-for-translatepress' ) . '"><input type="hidden" name="action" value="aitp_pages"><input type="hidden" name="do" value="journal_undo">';
			wp_nonce_field( 'aitp_pages' );
			echo '<button class="button">' . esc_html__( 'Undo translations of the test', 'ai-translator-for-translatepress' ) . '</button></form>';
			echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aitp_pages_download&what=new_rows' ), 'aitp_pages_download' ) ) . '">' . esc_html__( 'Download the new rows', 'ai-translator-for-translatepress' ) . '</a>';
		}
		echo '</p>';
	}

	/**
	 * @param string $do    Action.
	 * @param string $label Text.
	 * @param string $class primary or ''.
	 */
	private static function button( $do, $label, $class ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:6px;"><input type="hidden" name="action" value="aitp_pages"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		wp_nonce_field( 'aitp_pages' );
		echo '<button type="submit" class="button' . ( $class === 'primary' ? ' button-primary' : '' ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/** Form actions. */
	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ai-translator-for-translatepress' ), 403 );
		}
		check_admin_referer( 'aitp_pages' );
		$do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$msg  = '';
		$type = 'success';
		switch ( $do ) {
			case 'map_start':
				$urls  = isset( $_POST['urls'] ) ? preg_split( '~\R~', sanitize_textarea_field( wp_unslash( $_POST['urls'] ) ) ) : array();
				$langs = isset( $_POST['langs'] ) && is_array( $_POST['langs'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['langs'] ) ) : array();
				$r     = AITP_Pagemap::start( array_map( 'esc_url_raw', array_map( 'trim', (array) $urls ) ), $langs );
				if ( $r['error'] !== '' ) {
					/* translators: %s: reason */
					$msg  = sprintf( __( 'Mapping cannot start: %s.', 'ai-translator-for-translatepress' ), self::reason( $r['error'] ) );
					$type = 'warning';
				} else {
					AITP_Pagemap::step( 15 );
					/* translators: %s: number of pages */
					$msg = sprintf( __( 'Mapping %s pages. It continues in the background; you can also advance it from this page.', 'ai-translator-for-translatepress' ), number_format_i18n( $r['pages'] ) );
				}
				if ( $r['bad'] ) {
					/* translators: %s: addresses */
					$msg .= ' ' . sprintf( __( 'Not usable (not on this site, or no translated address): %s', 'ai-translator-for-translatepress' ), implode( ', ', array_slice( $r['bad'], 0, 10 ) ) );
				}
				break;
			case 'map_stop':
				AITP_Pagemap::stop();
				$msg = __( 'Mapping stopped. Pages mapped so far are kept.', 'ai-translator-for-translatepress' );
				break;
			case 'map_clear':
				AITP_Pagemap::clear();
				$msg = __( 'Page map cleared.', 'ai-translator-for-translatepress' );
				break;
			case 'journal_start':
				$why = AITP_Journal::start( isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '' );
				$msg = $why === '' ? __( 'Test journal on.', 'ai-translator-for-translatepress' ) : self::reason( $why );
				$type = $why === '' ? 'success' : 'warning';
				break;
			case 'journal_stop':
				AITP_Journal::stop();
				$msg = __( 'Test journal off. What it recorded can still be undone.', 'ai-translator-for-translatepress' );
				break;
			case 'journal_undo':
				$r = AITP_Journal::undo();
				/* translators: 1: rows restored, 2: new rows */
				$msg = sprintf( __( '%1$s rows put back to their state before the test; %2$s translations were new rows (listed, not deleted).', 'ai-translator-for-translatepress' ), number_format_i18n( $r['restored'] ), number_format_i18n( $r['new_rows'] ) );
				if ( $r['skipped'] ) {
					$parts = array();
					foreach ( $r['skipped'] as $k => $n ) {
						$parts[] = self::journal_label( $k ) . ': ' . number_format_i18n( $n );
					}
					/* translators: %s: reasons and counts */
					$msg .= ' ' . sprintf( __( 'Kept as they are: %s.', 'ai-translator-for-translatepress' ), implode( ', ', $parts ) );
				}
				break;
		}
		if ( $msg !== '' ) {
			set_transient( 'aitp_flash_' . get_current_user_id(), array( 'type' => $type, 'message' => $msg ), 120 );
		}
		wp_safe_redirect( self::url() );
		exit;
	}

	/** Downloads: candidate list of mapped pages, new rows of the test. */
	public static function download() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ai-translator-for-translatepress' ), 403 );
		}
		check_admin_referer( 'aitp_pages_download' );
		$what = isset( $_REQUEST['what'] ) ? sanitize_key( wp_unslash( $_REQUEST['what'] ) ) : '';
		if ( $what === 'new_rows' ) {
			$data = array( 'rows' => AITP_Journal::new_rows() );
			$name = 'aitp-new-rows-' . gmdate( 'Ymd-His' ) . '.json';
		} else {
			$scope = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : 'selected';
			$ids   = isset( $_POST['pages'] ) && is_array( $_POST['pages'] ) ? array_map( 'intval', wp_unslash( $_POST['pages'] ) ) : array();
			if ( $scope !== 'all' && ! $ids ) {
				set_transient( 'aitp_flash_' . get_current_user_id(), array( 'type' => 'warning', 'message' => __( 'Nothing selected.', 'ai-translator-for-translatepress' ) ), 120 );
				wp_safe_redirect( self::url() );
				exit;
			}
			$data = AITP_Pagemap::export( $scope === 'all' ? array() : $ids );
			$name = 'aitp-page-candidates-' . gmdate( 'Ymd-His' ) . '.json';
		}
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		echo wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		exit;
	}

	/** One mapping step from the open admin page (AJAX). */
	public static function ajax_step() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( 'aitp_map_step' );
		$r = AITP_Pagemap::step( 25 );
		$s = $r['state'];
		/* translators: 1: pages done, 2: pages, 3: read, 4: failed */
		$text = sprintf( __( '%1$s of %2$s pages (%3$s read, %4$s failed)', 'ai-translator-for-translatepress' ), number_format_i18n( (int) $s['i'] ), number_format_i18n( count( $s['items'] ) ), number_format_i18n( (int) $s['ok'] ), number_format_i18n( (int) $s['failed'] ) );
		if ( $s['status'] === 'done' ) {
			$text .= ' — ' . __( 'finished', 'ai-translator-for-translatepress' );
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
}
