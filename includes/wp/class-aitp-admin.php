<?php
/**
 * Admin screens: Settings → AI Translator (settings, status & log, review list),
 * connection test, status card on the TranslatePress "Automatic Translation" tab, notices.
 *
 * Every action requires manage_options and a nonce. Nothing here is reachable by visitors.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

final class AITP_Admin {

	const PAGE = 'aitp';

	/** @var AITP_Admin|null */
	private static $instance = null;

	/** @return AITP_Admin */
	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Hooks. */
	public function boot() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_post_aitp_test', array( $this, 'handle_test' ) );
		add_action( 'admin_post_aitp_reset_breaker', array( $this, 'handle_reset_breaker' ) );
		add_action( 'admin_post_aitp_clear_failures', array( $this, 'handle_clear_failures' ) );
		add_action( 'admin_post_aitp_review', array( $this, 'handle_review' ) );
		add_action( 'admin_post_aitp_export_review', array( $this, 'handle_export_review' ) );
		add_action( 'trp_machine_translation_extra_settings_middle', array( $this, 'tp_status_card' ), 30, 1 );
		AITP_Admin_Queue::boot();
		AITP_Admin_Audit::boot();
		add_filter( 'plugin_action_links_' . plugin_basename( AITP_FILE ), array( $this, 'action_links' ) );
	}

	/** @return string */
	public static function url( $tab = 'settings', array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::PAGE, 'tab' => $tab ), $args ), admin_url( 'options-general.php' ) );
	}

	/** Menu entry. */
	public function menu() {
		add_options_page( __( 'AI Translator', 'ai-translator-for-translatepress' ), __( 'AI Translator', 'ai-translator-for-translatepress' ), 'manage_options', self::PAGE, array( $this, 'render' ) );
	}

	/** Settings API registration. */
	public function register() {
		register_setting(
			'aitp',
			AITP_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'AITP_Settings', 'sanitize' ),
				'show_in_rest'      => false,
				'default'           => AITP_Settings::defaults(),
			)
		);
	}

	/**
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'ai-translator-for-translatepress' ) . '</a>' );
		return $links;
	}

	/**
	 * @param string $hook Admin page hook.
	 */
	public function assets( $hook ) {
		if ( $hook !== 'settings_page_' . self::PAGE ) {
			return;
		}
		wp_enqueue_style( 'aitp-admin', AITP_URL . 'admin/assets/admin.css', array(), AITP_VERSION );
		wp_enqueue_script( 'aitp-admin', AITP_URL . 'admin/assets/admin.js', array(), AITP_VERSION, true );
		$presets = array();
		foreach ( AITP_Provider_OpenAI::presets() as $k => $p ) {
			$presets[ $k ] = array( 'base_url' => $p['base_url'], 'model' => $p['model'] );
		}
		wp_add_inline_script( 'aitp-admin', 'window.aitpPresets = ' . wp_json_encode( $presets ) . ';', 'before' );
		wp_add_inline_script(
			'aitp-admin',
			'window.aitpQueue = ' . wp_json_encode(
				array(
					'ajax'  => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'aitp_queue_step' ),
					'error' => __( 'Request failed; trying again shortly.', 'ai-translator-for-translatepress' ),
				)
			) . ';window.aitpAudit = ' . wp_json_encode(
				array(
					'ajax'  => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'aitp_audit_step' ),
					'error' => __( 'Request failed; trying again shortly.', 'ai-translator-for-translatepress' ),
				)
			) . ';',
			'before'
		);
	}

	/* -------------------------------------------------------------------------------------
	 * Notices.
	 * ----------------------------------------------------------------------------------- */

	/** Admin notices for administrators. */
	public function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! class_exists( 'TRP_Translate_Press' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'AI Translator for TranslatePress needs the TranslatePress plugin to be installed and active.', 'ai-translator-for-translatepress' ) . '</p></div>';
			return;
		}
		if ( defined( 'HHGFOTR_PLUGIN_DIR' ) || class_exists( 'HHGFOTR_Plugin' ) || class_exists( 'TRP_HHGFOTR_Gemini_Machine_Translator' ) ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'The plugin "HHG for TranslatePress" is also active. Only one translation engine is used at a time (TranslatePress → Automatic Translation → engine). Deactivate HHG after switching to AI Translator to avoid confusion.', 'ai-translator-for-translatepress' ) . '</p></div>';
		}
		if ( AITP_Settings::key_unreadable() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'AI Translator: the saved API key can no longer be read (the site\'s secret keys in wp-config.php changed). Please enter the API key again.', 'ai-translator-for-translatepress' ) . ' <a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open settings', 'ai-translator-for-translatepress' ) . '</a></p></div>';
		}
		if ( AITP_Plugin::engine_selected() && AITP_Plugin::automatic_translation_on() ) {
			$b = AITP_Plugin::breaker()->state();
			if ( $b['status'] === 'paused' ) {
				echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'AI Translator has paused automatic translation.', 'ai-translator-for-translatepress' ) . '</strong> ' . esc_html( self::reason_text( $b['reason'] ) ) . ' <a href="' . esc_url( self::url( 'status' ) ) . '">' . esc_html__( 'Details', 'ai-translator-for-translatepress' ) . '</a></p></div>';
			}
		}
		$flash = get_transient( 'aitp_flash_' . get_current_user_id() );
		if ( is_array( $flash ) && isset( $_GET['page'] ) && $_GET['page'] === self::PAGE ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			delete_transient( 'aitp_flash_' . get_current_user_id() );
			echo '<div class="notice notice-' . esc_attr( $flash['type'] ) . ' is-dismissible"><p>' . esc_html( $flash['message'] ) . '</p></div>';
		}
	}

	/**
	 * @param string $type    success|error|warning|info.
	 * @param string $message Message.
	 */
	private static function flash( $type, $message ) {
		set_transient( 'aitp_flash_' . get_current_user_id(), array( 'type' => $type, 'message' => $message ), 120 );
	}

	/**
	 * Human explanation of a breaker / failure reason.
	 *
	 * @param string $reason Code.
	 * @return string
	 */
	public static function reason_text( $reason ) {
		$map = array(
			'auth'             => __( 'The API key was rejected. Check the key and the API address.', 'ai-translator-for-translatepress' ),
			'billing'          => __( 'The provider reports a billing or balance problem. Top up or check the account.', 'ai-translator-for-translatepress' ),
			'not_found'        => __( 'The model or API address was not found. Check the model name.', 'ai-translator-for-translatepress' ),
			'bad_request'      => __( 'The provider rejected the requests as invalid. Check the model and settings, then run the connection test.', 'ai-translator-for-translatepress' ),
			'rate_limit'       => __( 'Rate limit reached; requests resume automatically.', 'ai-translator-for-translatepress' ),
			'quota_daily'      => __( 'The provider\'s daily quota is used up; requests resume automatically later.', 'ai-translator-for-translatepress' ),
			'server'           => __( 'The provider returned server errors; requests resume automatically.', 'ai-translator-for-translatepress' ),
			'timeout'          => __( 'Requests timed out; requests resume automatically.', 'ai-translator-for-translatepress' ),
			'network'          => __( 'Network error while contacting the provider.', 'ai-translator-for-translatepress' ),
			'invalid_response' => __( 'The provider returned an unreadable response.', 'ai-translator-for-translatepress' ),
			'bad_output'       => __( 'The model\'s answers were repeatedly cut off, unreadable or misaligned, so they were discarded; requests resume automatically.', 'ai-translator-for-translatepress' ),
		);
		return isset( $map[ $reason ] ) ? $map[ $reason ] : (string) $reason;
	}

	/* -------------------------------------------------------------------------------------
	 * TranslatePress "Automatic Translation" tab.
	 * ----------------------------------------------------------------------------------- */

	/**
	 * @param array $mt_settings TranslatePress machine translation settings.
	 */
	public function tp_status_card( $mt_settings ) {
		$selected = is_array( $mt_settings ) && isset( $mt_settings['translation-engine'] ) && $mt_settings['translation-engine'] === AITP_TP_ENGINE;
		echo '<div class="aitp-tp-card" style="' . ( $selected ? '' : 'display:none;' ) . 'margin:12px 0 24px;max-width:640px;padding:12px 16px;background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;" data-aitp-engine="' . esc_attr( AITP_TP_ENGINE ) . '">';
		echo '<p style="margin:0 0 6px;"><strong>' . esc_html__( 'AI Translator', 'ai-translator-for-translatepress' ) . '</strong></p>';
		if ( ! AITP_Settings::is_configured() ) {
			echo '<p style="margin:0;">' . esc_html__( 'Not configured yet.', 'ai-translator-for-translatepress' ) . ' ';
		} else {
			$s = AITP_Settings::get();
			$b = AITP_Plugin::breaker()->state();
			echo '<p style="margin:0;">' . esc_html( sprintf( '%s · %s', $s['provider'] === 'openai' ? (string) wp_parse_url( AITP_Settings::effective_base_url(), PHP_URL_HOST ) : 'Google Gemini', $s['model'] ) ) . ' · ' . esc_html( self::breaker_label( $b['status'] ) ) . ' ';
		}
		echo '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Open AI Translator settings', 'ai-translator-for-translatepress' ) . '</a></p>';
		echo '<p class="description" style="margin:6px 0 0;">' . esc_html__( 'The daily character limit above also applies to AI Translator (sent characters, retries included).', 'ai-translator-for-translatepress' ) . '</p>';
		echo '</div>';
		echo '<script>(function(){var s=document.querySelector(\'select[name="trp_machine_translation_settings[translation-engine]"]\'),c=document.querySelector(".aitp-tp-card");if(!s||!c)return;function u(){c.style.display=s.value===c.getAttribute("data-aitp-engine")?"":"none";}s.addEventListener("change",u);u();var r=document.querySelectorAll(\'input[name="trp_machine_translation_settings[translation-engine]"]\');r.forEach(function(i){i.addEventListener("change",function(){c.style.display=(i.checked&&i.value===c.getAttribute("data-aitp-engine"))?"":"none";});});})();</script>';
	}

	/**
	 * @param string $status Breaker status.
	 * @return string
	 */
	private static function breaker_label( $status ) {
		if ( $status === 'paused' ) {
			return __( 'paused', 'ai-translator-for-translatepress' );
		}
		if ( $status === 'open' ) {
			return __( 'waiting (temporary)', 'ai-translator-for-translatepress' );
		}
		return __( 'running', 'ai-translator-for-translatepress' );
	}

	/* -------------------------------------------------------------------------------------
	 * Page.
	 * ----------------------------------------------------------------------------------- */

	/** Render the settings page. */
	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs = array(
			'settings' => __( 'Settings', 'ai-translator-for-translatepress' ),
			'status'   => __( 'Status & log', 'ai-translator-for-translatepress' ),
			'queue'    => __( 'Backfill queue', 'ai-translator-for-translatepress' ),
			'review'   => __( 'Review list', 'ai-translator-for-translatepress' ),
			'audit'    => __( 'Audit & repair', 'ai-translator-for-translatepress' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'settings';
		}
		echo '<div class="wrap aitp-wrap"><h1>' . esc_html__( 'AI Translator for TranslatePress', 'ai-translator-for-translatepress' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			echo '<a class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( $k ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( $tab === 'status' ) {
			$this->render_status();
		} elseif ( $tab === 'queue' ) {
			AITP_Admin_Queue::render();
		} elseif ( $tab === 'review' ) {
			$this->render_review();
		} elseif ( $tab === 'audit' ) {
			AITP_Admin_Audit::render();
		} else {
			$this->render_settings();
		}
		echo '</div>';
	}

	/** Settings tab. */
	private function render_settings() {
		$s      = AITP_Settings::get();
		$name   = AITP_Settings::OPTION;
		$hint   = AITP_Settings::key_hint();
		$const  = AITP_Settings::key_from_constant();
		$result = get_transient( 'aitp_test_' . get_current_user_id() );

		if ( ! AITP_Plugin::engine_selected() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'To use this engine, open TranslatePress → Settings → Automatic Translation, turn automatic translation on and choose "AI Translator" as the engine.', 'ai-translator-for-translatepress' ) . '</p></div>';
		}

		if ( is_array( $result ) ) {
			$this->render_test_result( $result );
		}

		echo '<form method="post" action="options.php" class="aitp-form">';
		settings_fields( 'aitp' );
		echo '<input type="hidden" name="' . esc_attr( $name ) . '[_form]" value="1"><input type="hidden" name="' . esc_attr( $name ) . '[_checkboxes]" value="1">';

		echo '<h2>' . esc_html__( 'Connection', 'ai-translator-for-translatepress' ) . '</h2><table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'API type', 'ai-translator-for-translatepress' ) . '</th><td>';
		foreach ( array( 'gemini' => __( 'Google Gemini (native API)', 'ai-translator-for-translatepress' ), 'openai' => __( 'OpenAI-compatible API (OpenAI, DeepSeek, Zhipu, OpenRouter, relays …)', 'ai-translator-for-translatepress' ) ) as $v => $l ) {
			echo '<label style="display:block;margin-bottom:4px;"><input type="radio" name="' . esc_attr( $name ) . '[provider]" value="' . esc_attr( $v ) . '"' . checked( $s['provider'], $v, false ) . ' class="aitp-provider"> ' . esc_html( $l ) . '</label>';
		}
		echo '</td></tr>';

		echo '<tr class="aitp-openai-only"><th scope="row"><label for="aitp-preset">' . esc_html__( 'Service', 'ai-translator-for-translatepress' ) . '</label></th><td><select id="aitp-preset" name="' . esc_attr( $name ) . '[preset]">';
		foreach ( AITP_Provider_OpenAI::presets() as $k => $p ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $s['preset'], $k, false ) . '>' . esc_html( $p['label'] ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="aitp-base">' . esc_html__( 'API address', 'ai-translator-for-translatepress' ) . '</label></th><td><input type="url" class="regular-text code" id="aitp-base" name="' . esc_attr( $name ) . '[base_url]" value="' . esc_attr( $s['base_url'] ) . '" placeholder="' . esc_attr( $s['provider'] === 'gemini' ? 'https://generativelanguage.googleapis.com/v1beta' : AITP_Settings::effective_base_url() ) . '">';
		echo '<p class="description">' . esc_html__( 'Leave empty to use the default address of the selected service. HTTPS only. For relays, enter the address that ends before /chat/completions (often ending in /v1).', 'ai-translator-for-translatepress' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="aitp-key">' . esc_html__( 'API key', 'ai-translator-for-translatepress' ) . '</label></th><td>';
		if ( $const ) {
			echo '<p>' . esc_html__( 'Set in wp-config.php (AITP_API_KEY).', 'ai-translator-for-translatepress' ) . '</p>';
		} else {
			echo '<input type="password" class="regular-text" id="aitp-key" name="' . esc_attr( $name ) . '[api_key_new]" value="" autocomplete="new-password" placeholder="' . esc_attr( $hint !== '' ? sprintf( /* translators: %s: last 4 characters */ __( 'Saved (…%s) — enter a new key to replace it', 'ai-translator-for-translatepress' ), $hint ) : __( 'Paste the API key', 'ai-translator-for-translatepress' ) ) . '">';
			if ( $hint !== '' ) {
				echo ' <label><input type="checkbox" name="' . esc_attr( $name ) . '[api_key_clear]" value="1"> ' . esc_html__( 'Remove saved key', 'ai-translator-for-translatepress' ) . '</label>';
			}
			echo '<p class="description">' . esc_html__( 'Stored encrypted and never shown again. It is only sent to the API address above, in a request header.', 'ai-translator-for-translatepress' ) . '</p>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row"><label for="aitp-model">' . esc_html__( 'Model', 'ai-translator-for-translatepress' ) . '</label></th><td><input type="text" class="regular-text code" id="aitp-model" name="' . esc_attr( $name ) . '[model]" value="' . esc_attr( $s['model'] ) . '">';
		echo '<p class="description">' . esc_html__( 'Model name exactly as the provider writes it, e.g. gemini-3.5-flash, gpt-4.1-mini, deepseek-chat.', 'ai-translator-for-translatepress' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Thinking', 'ai-translator-for-translatepress' ) . '</th><td><select name="' . esc_attr( $name ) . '[thinking]">';
		foreach ( array( 'off' => __( 'Minimal (fastest, recommended)', 'ai-translator-for-translatepress' ), 'low' => __( 'Low', 'ai-translator-for-translatepress' ), 'auto' => __( 'Model default', 'ai-translator-for-translatepress' ) ) as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $s['thinking'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Structured output', 'ai-translator-for-translatepress' ) . '</th><td><select name="' . esc_attr( $name ) . '[structured]">';
		foreach ( array( 'auto' => __( 'Automatic (schema → JSON → plain, chosen by what the API accepts)', 'ai-translator-for-translatepress' ), 'json' => __( 'JSON mode only', 'ai-translator-for-translatepress' ), 'off' => __( 'Off (prompt only)', 'ai-translator-for-translatepress' ) ) as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $s['structured'], $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th scope="row"><label for="aitp-temp">' . esc_html__( 'Temperature', 'ai-translator-for-translatepress' ) . '</label></th><td><input type="number" step="0.1" min="0" max="2" class="small-text" id="aitp-temp" name="' . esc_attr( $name ) . '[temperature]" value="' . esc_attr( $s['temperature'] ) . '"> <span class="description">' . esc_html__( 'Empty = provider default (recommended for Gemini 3).', 'ai-translator-for-translatepress' ) . '</span></td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html__( 'Translation rules', 'ai-translator-for-translatepress' ) . '</h2><table class="form-table" role="presentation">';
		$this->textarea( 'protected_terms', __( 'Protected terms', 'ai-translator-for-translatepress' ), __( 'One per line. Brand and product names that must appear unchanged (exact spelling and capitals), e.g. OKAY EYEWEAR.', 'ai-translator-for-translatepress' ), $s, 5 );
		$this->textarea( 'glossary', __( 'Glossary', 'ai-translator-for-translatepress' ), __( 'One rule per line: source term => language: preferred | alternative ; !forbidden. Language can be a code (fr, es_MX) or * for all. Example: tinted lenses => es_MX: micas tintadas | lentes tintados ; !polarizadas', 'ai-translator-for-translatepress' ), $s, 6 );
		$this->textarea( 'protected_patterns', __( 'Protected patterns', 'ai-translator-for-translatepress' ), __( 'Optional. One regular expression per line (without delimiters); every match must reappear unchanged. Technical standards (EN ISO 12312-1, ANSI Z80.3 …) and model codes (TR90, UV400) are protected automatically.', 'ai-translator-for-translatepress' ), $s, 3 );
		$this->textarea( 'site_context', __( 'About the site', 'ai-translator-for-translatepress' ), __( 'Short description sent with every request: industry, audience, tone. Example: B2B manufacturer of kids sunglasses and optical frames; formal business tone.', 'ai-translator-for-translatepress' ), $s, 3 );

		echo '<tr><th scope="row">' . esc_html__( 'Checks', 'ai-translator-for-translatepress' ) . '</th><td>';
		foreach ( array(
			'code_guard'   => __( 'Model numbers and material codes must stay unchanged (TR90, UV400, OK-A1001)', 'ai-translator-for-translatepress' ),
			'size_guard'   => __( 'Size labels must stay unchanged (S, M, L, XL)', 'ai-translator-for-translatepress' ),
			'claim_checks' => __( 'Flag added claims (guaranteed, certified, in stock, polarized, reinforced, best …)', 'ai-translator-for-translatepress' ),
		) as $k => $l ) {
			echo '<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="' . esc_attr( $name ) . '[' . esc_attr( $k ) . ']" value="1"' . checked( ! empty( $s[ $k ] ), true, false ) . '> ' . esc_html( $l ) . '</label>';
		}
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Flagged translations', 'ai-translator-for-translatepress' ) . '</th><td>';
		foreach ( array( 'save_review' => __( 'Save them and add them to the review list (recommended)', 'ai-translator-for-translatepress' ), 'strict' => __( 'Do not save them; keep the original until reviewed', 'ai-translator-for-translatepress' ) ) as $v => $l ) {
			echo '<label style="display:block;margin-bottom:4px;"><input type="radio" name="' . esc_attr( $name ) . '[soft_policy]" value="' . esc_attr( $v ) . '"' . checked( $s['soft_policy'], $v, false ) . '> ' . esc_html( $l ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'Applies to possible meaning changes (added claims, glossary). Broken structure, missing numbers, untranslated or truncated text is never saved.', 'ai-translator-for-translatepress' ) . '</p></td></tr>';
		echo '</table>';

		echo '<h2>' . esc_html__( 'Performance', 'ai-translator-for-translatepress' ) . '</h2><table class="form-table" role="presentation">';
		$this->number( 'time_budget', __( 'Seconds per page view', 'ai-translator-for-translatepress' ), __( 'Maximum time spent translating during one page view. Strings not finished are translated on a later view.', 'ai-translator-for-translatepress' ), $s, 10, 40 );
		$this->number( 'chunk_size', __( 'Strings per call', 'ai-translator-for-translatepress' ), __( 'How many strings TranslatePress hands over at once.', 'ai-translator-for-translatepress' ), $s, 20, 300 );
		$this->number( 'batch_items', __( 'Strings per request', 'ai-translator-for-translatepress' ), '', $s, 5, 100 );
		$this->number( 'batch_tokens', __( 'Source tokens per request', 'ai-translator-for-translatepress' ), '', $s, 500, 8000 );
		$this->number( 'concurrency', __( 'Parallel requests', 'ai-translator-for-translatepress' ), __( 'Lower this if the provider reports rate limits.', 'ai-translator-for-translatepress' ), $s, 1, 6 );
		$this->number( 'http_timeout', __( 'Request timeout (seconds)', 'ai-translator-for-translatepress' ), '', $s, 10, 60 );
		$this->number( 'log_days', __( 'Keep log (days)', 'ai-translator-for-translatepress' ), '', $s, 1, 90 );
		echo '</table>';

		submit_button();
		echo '</form>';

		echo '<h2>' . esc_html__( 'Connection test', 'ai-translator-for-translatepress' ) . '</h2>';
		echo '<p>' . esc_html__( 'Translates two short test sentences with the saved settings and shows exactly what came back. Nothing is saved to TranslatePress.', 'ai-translator-for-translatepress' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aitp_test">';
		wp_nonce_field( 'aitp_test' );
		submit_button( __( 'Run connection test', 'ai-translator-for-translatepress' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * @param array $r Stored test result.
	 */
	private function render_test_result( array $r ) {
		$ok = ! empty( $r['ok'] );
		echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . ' inline aitp-test"><p><strong>' . esc_html( $ok ? __( 'Connection test passed.', 'ai-translator-for-translatepress' ) : __( 'Connection test failed.', 'ai-translator-for-translatepress' ) ) . '</strong> ';
		echo esc_html( sprintf( '%s → %s · %s · %d ms', $r['source'], $r['target'], $r['model'], (int) $r['ms'] ) ) . '</p>';
		if ( ! empty( $r['translations'] ) ) {
			echo '<ul>';
			foreach ( $r['translations'] as $i => $t ) {
				echo '<li><code>' . esc_html( $r['sources'][ $i ] ) . '</code> → ' . esc_html( $t ) . '</li>';
			}
			echo '</ul>';
		}
		if ( ! empty( $r['failed'] ) ) {
			echo '<p>' . esc_html__( 'Not translated:', 'ai-translator-for-translatepress' ) . ' ' . esc_html( implode( ', ', array_unique( $r['failed'] ) ) ) . '</p>';
		}
		if ( ! empty( $r['message'] ) ) {
			echo '<p><code>' . esc_html( $r['message'] ) . '</code></p>';
		}
		if ( ! empty( $r['caps'] ) ) {
			echo '<p class="description">' . esc_html__( 'Detected features:', 'ai-translator-for-translatepress' ) . ' ' . esc_html( $r['caps'] ) . '</p>';
		}
		echo '</div>';
	}

	/**
	 * @param string $key   Setting.
	 * @param string $label Label.
	 * @param string $desc  Help.
	 * @param array  $s     Settings.
	 * @param int    $rows  Rows.
	 */
	private function textarea( $key, $label, $desc, array $s, $rows ) {
		$id = 'aitp-' . $key;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td><textarea class="large-text code" rows="' . (int) $rows . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( AITP_Settings::OPTION ) . '[' . esc_attr( $key ) . ']">' . esc_textarea( (string) $s[ $key ] ) . '</textarea>';
		if ( $desc !== '' ) {
			echo '<p class="description">' . esc_html( $desc ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * @param string $key   Setting.
	 * @param string $label Label.
	 * @param string $desc  Help.
	 * @param array  $s     Settings.
	 * @param int    $min   Min.
	 * @param int    $max   Max.
	 */
	private function number( $key, $label, $desc, array $s, $min, $max ) {
		$id = 'aitp-' . $key;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td><input type="number" class="small-text" min="' . (int) $min . '" max="' . (int) $max . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( AITP_Settings::OPTION ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) $s[ $key ] ) . '">';
		if ( $desc !== '' ) {
			echo ' <span class="description">' . esc_html( $desc ) . '</span>';
		}
		echo '</td></tr>';
	}

	/** Status tab. */
	private function render_status() {
		global $wpdb;
		$b       = AITP_Plugin::breaker()->state();
		$counter = (int) get_option( 'trp_machine_translation_counter', 0 );
		$limit   = AITP_TP_Quota::limit();

		echo '<h2>' . esc_html__( 'Overview', 'ai-translator-for-translatepress' ) . '</h2><table class="widefat striped aitp-kv"><tbody>';
		$this->kv( __( 'Engine selected in TranslatePress', 'ai-translator-for-translatepress' ), AITP_Plugin::engine_selected() ? __( 'Yes', 'ai-translator-for-translatepress' ) : __( 'No', 'ai-translator-for-translatepress' ) );
		$this->kv( __( 'Automatic translation', 'ai-translator-for-translatepress' ), AITP_Plugin::automatic_translation_on() ? __( 'On', 'ai-translator-for-translatepress' ) : __( 'Off', 'ai-translator-for-translatepress' ) );
		$this->kv( __( 'Configured', 'ai-translator-for-translatepress' ), AITP_Settings::is_configured() ? __( 'Yes', 'ai-translator-for-translatepress' ) : __( 'No', 'ai-translator-for-translatepress' ) );
		$state = self::breaker_label( $b['status'] );
		if ( $b['status'] !== 'closed' ) {
			$state .= ' — ' . self::reason_text( $b['reason'] );
			if ( (int) $b['until'] > time() ) {
				/* translators: %s: time */
				$state .= ' ' . sprintf( __( '(next attempt %s)', 'ai-translator-for-translatepress' ), wp_date( 'Y-m-d H:i', (int) $b['until'] ) );
			}
		}
		$this->kv( __( 'State', 'ai-translator-for-translatepress' ), $state );
		if ( $b['message'] !== '' ) {
			$this->kv( __( 'Last error', 'ai-translator-for-translatepress' ), (string) $b['message'] );
		}
		/* translators: 1: used characters, 2: limit */
		$this->kv( __( 'Characters sent today (TranslatePress limit)', 'ai-translator-for-translatepress' ), sprintf( __( '%1$s of %2$s', 'ai-translator-for-translatepress' ), number_format_i18n( $counter ), number_format_i18n( $limit ) ) );
		echo '</tbody></table>';

		if ( $b['status'] !== 'closed' ) {
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;"><input type="hidden" name="action" value="aitp_reset_breaker">';
			wp_nonce_field( 'aitp_reset_breaker' );
			submit_button( __( 'Resume now', 'ai-translator-for-translatepress' ), 'secondary', 'submit', false );
			echo '</form>';
		}

		// Last 24 hours.
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$rows  = $wpdb->get_col( $wpdb->prepare( 'SELECT data FROM ' . AITP_Schema::table( 'log' ) . " WHERE event = 'call' AND created_at >= %s ORDER BY id DESC LIMIT 5000", $since ) );
		$sum   = array( 'calls' => 0, 'items' => 0, 'translated' => 0, 'failed' => 0, 'flagged' => 0, 'cached' => 0, 'requests' => 0, 'sent_bytes' => 0 );
		$why   = array();
		foreach ( $rows as $json ) {
			$d = json_decode( $json, true );
			if ( ! is_array( $d ) ) {
				continue;
			}
			$sum['calls']++;
			foreach ( array( 'items', 'translated', 'failed', 'flagged', 'cached', 'requests', 'sent_bytes' ) as $k ) {
				$sum[ $k ] += isset( $d[ $k ] ) ? (int) $d[ $k ] : 0;
			}
			if ( ! empty( $d['reasons'] ) && is_array( $d['reasons'] ) ) {
				foreach ( $d['reasons'] as $r => $n ) {
					$why[ $r ] = ( isset( $why[ $r ] ) ? $why[ $r ] : 0 ) + (int) $n;
				}
			}
		}
		arsort( $why );
		echo '<h2>' . esc_html__( 'Last 24 hours', 'ai-translator-for-translatepress' ) . '</h2><table class="widefat striped aitp-kv"><tbody>';
		$this->kv( __( 'Engine calls', 'ai-translator-for-translatepress' ), number_format_i18n( $sum['calls'] ) );
		$this->kv( __( 'Strings received', 'ai-translator-for-translatepress' ), number_format_i18n( $sum['items'] ) );
		$this->kv( __( 'Translated and handed to TranslatePress', 'ai-translator-for-translatepress' ), number_format_i18n( $sum['translated'] ) . ( $sum['cached'] ? ' (' . sprintf( /* translators: %s: number */ __( '%s from cache', 'ai-translator-for-translatepress' ), number_format_i18n( $sum['cached'] ) ) . ')' : '' ) );
		$this->kv( __( 'Saved with a review flag', 'ai-translator-for-translatepress' ), number_format_i18n( $sum['flagged'] ) );
		$this->kv( __( 'Not translated (left for a later attempt)', 'ai-translator-for-translatepress' ), number_format_i18n( $sum['failed'] ) );
		$this->kv( __( 'API requests', 'ai-translator-for-translatepress' ), number_format_i18n( $sum['requests'] ) );
		$this->kv( __( 'Characters sent', 'ai-translator-for-translatepress' ), number_format_i18n( $sum['sent_bytes'] ) );
		if ( $why ) {
			$parts = array();
			foreach ( $why as $r => $n ) {
				$parts[] = self::code_label( $r ) . ': ' . number_format_i18n( $n );
			}
			$this->kv( __( 'Reasons', 'ai-translator-for-translatepress' ), implode( ' · ', $parts ) );
		}
		echo '</tbody></table>';

		// Items in backoff.
		$ft     = AITP_Schema::table( 'failures' );
		$counts = $wpdb->get_results( "SELECT target_locale, reason, COUNT(*) n, SUM(next_retry_at > UNIX_TIMESTAMP()) held FROM $ft GROUP BY target_locale, reason ORDER BY n DESC LIMIT 50", ARRAY_A );
		echo '<h2>' . esc_html__( 'Strings that keep failing', 'ai-translator-for-translatepress' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'After repeated failures a string is retried less often (5 min, 30 min, 2 h, 12 h). It stays untranslated in TranslatePress meanwhile.', 'ai-translator-for-translatepress' ) . '</p>';
		if ( ! $counts ) {
			echo '<p>' . esc_html__( 'None.', 'ai-translator-for-translatepress' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Language', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Reason', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Strings', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Waiting', 'ai-translator-for-translatepress' ) . '</th></tr></thead><tbody>';
			foreach ( $counts as $c ) {
				echo '<tr><td>' . esc_html( $c['target_locale'] ) . '</td><td>' . esc_html( self::code_label( $c['reason'] ) ) . '</td><td>' . (int) $c['n'] . '</td><td>' . (int) $c['held'] . '</td></tr>';
			}
			echo '</tbody></table>';
			$samples = $wpdb->get_results( "SELECT target_locale, reason, excerpt, attempts FROM $ft ORDER BY updated_at DESC LIMIT 20", ARRAY_A );
			echo '<details style="margin-top:8px;"><summary>' . esc_html__( 'Latest examples', 'ai-translator-for-translatepress' ) . '</summary><table class="widefat striped"><tbody>';
			foreach ( $samples as $x ) {
				echo '<tr><td>' . esc_html( $x['target_locale'] ) . '</td><td>' . esc_html( self::code_label( $x['reason'] ) ) . ' ×' . (int) $x['attempts'] . '</td><td>' . esc_html( $x['excerpt'] ) . '</td></tr>';
			}
			echo '</tbody></table></details>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:10px;"><input type="hidden" name="action" value="aitp_clear_failures">';
			wp_nonce_field( 'aitp_clear_failures' );
			submit_button( __( 'Retry all now', 'ai-translator-for-translatepress' ), 'secondary', 'submit', false );
			echo '</form>';
		}

		// Recent log.
		$log = $wpdb->get_results( 'SELECT created_at, level, event, target_locale, data FROM ' . AITP_Schema::table( 'log' ) . ' ORDER BY id DESC LIMIT 60', ARRAY_A );
		echo '<h2>' . esc_html__( 'Recent log', 'ai-translator-for-translatepress' ) . '</h2>';
		if ( ! $log ) {
			echo '<p>' . esc_html__( 'Nothing logged yet.', 'ai-translator-for-translatepress' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped aitp-log"><thead><tr><th>' . esc_html__( 'Time (UTC)', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Event', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Language', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Details', 'ai-translator-for-translatepress' ) . '</th></tr></thead><tbody>';
		foreach ( $log as $l ) {
			echo '<tr class="aitp-level-' . esc_attr( $l['level'] ) . '"><td>' . esc_html( $l['created_at'] ) . '</td><td>' . esc_html( $l['event'] . ' / ' . $l['level'] ) . '</td><td>' . esc_html( $l['target_locale'] ) . '</td><td><code>' . esc_html( self::log_summary( $l['event'], (string) $l['data'] ) ) . '</code></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * @param string $event Event.
	 * @param string $json  Data.
	 * @return string
	 */
	private static function log_summary( $event, $json ) {
		$d = json_decode( $json, true );
		if ( ! is_array( $d ) ) {
			return '';
		}
		unset( $d['target'] );
		$parts = array();
		foreach ( $d as $k => $v ) {
			if ( is_array( $v ) ) {
				if ( ! $v ) {
					continue;
				}
				$v = wp_json_encode( $v );
			}
			if ( $v === '' || $v === null ) {
				continue;
			}
			$parts[] = $k . '=' . $v;
		}
		$s = implode( ' ', $parts );
		return strlen( $s ) > 400 ? substr( $s, 0, 400 ) . '…' : $s;
	}

	/**
	 * Label for a failure / flag code.
	 *
	 * @param string $code Code.
	 * @return string
	 */
	public static function code_label( $code ) {
		$map = array(
			'too_short'                 => __( 'translation too short', 'ai-translator-for-translatepress' ),
			'too_long'                  => __( 'translation too long', 'ai-translator-for-translatepress' ),
			'sentences_missing'         => __( 'sentences missing', 'ai-translator-for-translatepress' ),
			'untranslated'              => __( 'returned untranslated', 'ai-translator-for-translatepress' ),
			'english_residue'           => __( 'source language left in translation', 'ai-translator-for-translatepress' ),
			'number_mismatch'           => __( 'numbers changed', 'ai-translator-for-translatepress' ),
			'markup_mismatch'           => __( 'HTML changed', 'ai-translator-for-translatepress' ),
			'markup_unbalanced'         => __( 'HTML broken', 'ai-translator-for-translatepress' ),
			'placeholder_mismatch'      => __( 'placeholders changed', 'ai-translator-for-translatepress' ),
			'link_changed'              => __( 'link or e-mail changed', 'ai-translator-for-translatepress' ),
			'protected_term_missing'    => __( 'protected term missing', 'ai-translator-for-translatepress' ),
			'brand_case'                => __( 'ordinary word written like the brand', 'ai-translator-for-translatepress' ),
			'protected_pattern_missing' => __( 'code or standard changed', 'ai-translator-for-translatepress' ),
			'size_changed'              => __( 'size label changed', 'ai-translator-for-translatepress' ),
			'foreign_script'            => __( 'characters of another language', 'ai-translator-for-translatepress' ),
			'source_residue'            => __( 'source characters left in translation', 'ai-translator-for-translatepress' ),
			'truncated'                 => __( 'answer cut off', 'ai-translator-for-translatepress' ),
			'missing_id'                => __( 'missing in answer', 'ai-translator-for-translatepress' ),
			'id_conflict'               => __( 'answer IDs did not match', 'ai-translator-for-translatepress' ),
			'misaligned'                => __( 'answer looked misaligned, all of it discarded', 'ai-translator-for-translatepress' ),
			'invalid_json'              => __( 'unreadable answer', 'ai-translator-for-translatepress' ),
			'invalid_structure'         => __( 'unreadable answer', 'ai-translator-for-translatepress' ),
			'blocked'                   => __( 'blocked by provider safety filter', 'ai-translator-for-translatepress' ),
			'deferred'                  => __( 'time budget used up (next page view)', 'ai-translator-for-translatepress' ),
			'breaker'                   => __( 'engine paused / waiting', 'ai-translator-for-translatepress' ),
			'quota'                     => __( 'daily character limit reached', 'ai-translator-for-translatepress' ),
			'held'                      => __( 'waiting after repeated failures', 'ai-translator-for-translatepress' ),
			'structured_data'           => __( 'structured data (not translated)', 'ai-translator-for-translatepress' ),
			'empty'                     => __( 'empty answer', 'ai-translator-for-translatepress' ),
			'format_leak'               => __( 'answer format leaked into text', 'ai-translator-for-translatepress' ),
			'glossary_required'         => __( 'glossary term not used', 'ai-translator-for-translatepress' ),
			'glossary_forbidden'        => __( 'forbidden glossary term used', 'ai-translator-for-translatepress' ),
			'brand_added'               => __( 'brand added', 'ai-translator-for-translatepress' ),
			'number_format'             => __( 'number written in words', 'ai-translator-for-translatepress' ),
			'structured_broken'         => __( 'structured data damaged', 'ai-translator-for-translatepress' ),
			'shared_translation'        => __( 'same translation as an unrelated text', 'ai-translator-for-translatepress' ),
			'imported'                  => __( 'from imported list', 'ai-translator-for-translatepress' ),
			'near_misaligned'           => __( 'next to misplaced translations', 'ai-translator-for-translatepress' ),
			'identical'                 => __( 'left exactly as the original', 'ai-translator-for-translatepress' ),
			'ai_mismatch'               => __( 'AI: belongs to another text or incomplete', 'ai-translator-for-translatepress' ),
			'control_chars'             => __( 'control characters', 'ai-translator-for-translatepress' ),
			'no_translate_changed'      => __( 'text marked "do not translate" changed', 'ai-translator-for-translatepress' ),
			'no_letters'                => __( 'no letters', 'ai-translator-for-translatepress' ),
		);
		if ( isset( $map[ $code ] ) ) {
			return $map[ $code ];
		}
		if ( strpos( $code, 'added_claim:' ) === 0 ) {
			/* translators: %s: concept such as guarantee */
			return sprintf( __( 'added claim: %s', 'ai-translator-for-translatepress' ), substr( $code, 12 ) );
		}
		if ( strpos( $code, 'review_' ) === 0 ) {
			/* translators: %s: reason */
			return sprintf( __( 'not saved, needs review: %s', 'ai-translator-for-translatepress' ), self::code_label( substr( $code, 7 ) ) );
		}
		if ( strpos( $code, 'assembled_' ) === 0 ) {
			return self::code_label( substr( $code, 10 ) );
		}
		return self::reason_text( $code );
	}

	/**
	 * @param string $k Key.
	 * @param string $v Value.
	 */
	private function kv( $k, $v ) {
		echo '<tr><th style="width:320px;">' . esc_html( $k ) . '</th><td>' . esc_html( $v ) . '</td></tr>';
	}

	/** Review tab. */
	private function render_review() {
		global $wpdb;
		$t      = AITP_Schema::table( 'review' );
		$status = isset( $_GET['status'] ) && in_array( $_GET['status'], array( 'open', 'done', 'dismissed' ), true ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'open'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged  = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$per    = 50;
		$total  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE status = %s", $status ) );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE status = %s ORDER BY id DESC LIMIT %d OFFSET %d", $status, $per, ( $paged - 1 ) * $per ), ARRAY_A );

		echo '<p>' . esc_html__( 'Translations that passed all hard checks but may change the meaning (added claims, glossary). Correct them in the TranslatePress translation editor if needed, then mark them as reviewed.', 'ai-translator-for-translatepress' ) . '</p>';
		echo '<ul class="subsubsub">';
		$links = array();
		foreach ( array( 'open' => __( 'Open', 'ai-translator-for-translatepress' ), 'done' => __( 'Reviewed', 'ai-translator-for-translatepress' ), 'dismissed' => __( 'Dismissed', 'ai-translator-for-translatepress' ) ) as $k => $l ) {
			$n       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE status = %s", $k ) );
			$links[] = '<li><a href="' . esc_url( self::url( 'review', array( 'status' => $k ) ) ) . '"' . ( $k === $status ? ' class="current"' : '' ) . '>' . esc_html( $l ) . ' <span class="count">(' . (int) $n . ')</span></a>';
		}
		echo implode( ' | </li>', $links ) . '</li></ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		echo '<p style="clear:both;"><a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=aitp_export_review&status=' . $status ), 'aitp_export_review' ) ) . '">' . esc_html__( 'Export CSV', 'ai-translator-for-translatepress' ) . '</a></p>';

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Nothing here.', 'ai-translator-for-translatepress' ) . '</p>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="aitp_review"><input type="hidden" name="status" value="' . esc_attr( $status ) . '">';
		wp_nonce_field( 'aitp_review' );
		echo '<table class="widefat striped aitp-review"><thead><tr><td class="check-column"><input type="checkbox" onclick="document.querySelectorAll(\'.aitp-review input[name=\\\'ids[]\\\']\').forEach(function(c){c.checked=this.checked;}.bind(this))"></td><th>' . esc_html__( 'Language', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Original', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Translation', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Flags', 'ai-translator-for-translatepress' ) . '</th><th>' . esc_html__( 'Saved', 'ai-translator-for-translatepress' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$flags = array_map( array( __CLASS__, 'code_label' ), array_filter( explode( ',', $r['flags'] ) ) );
			echo '<tr><th class="check-column"><input type="checkbox" name="ids[]" value="' . (int) $r['id'] . '"></th><td>' . esc_html( $r['target_locale'] ) . '</td><td>' . esc_html( AITP_Translator::excerpt( $r['original'], 400 ) ) . '</td><td>' . esc_html( AITP_Translator::excerpt( $r['translation'], 400 ) ) . '</td><td>' . esc_html( implode( ', ', $flags ) ) . '</td><td>' . ( $r['saved'] ? esc_html__( 'Yes', 'ai-translator-for-translatepress' ) : esc_html__( 'No', 'ai-translator-for-translatepress' ) ) . '</td></tr>';
		}
		echo '</tbody></table><p>';
		if ( $status !== 'done' ) {
			echo '<button class="button" name="do" value="done">' . esc_html__( 'Mark as reviewed', 'ai-translator-for-translatepress' ) . '</button> ';
		}
		if ( $status !== 'dismissed' ) {
			echo '<button class="button" name="do" value="dismissed">' . esc_html__( 'Dismiss', 'ai-translator-for-translatepress' ) . '</button> ';
		}
		if ( $status !== 'open' ) {
			echo '<button class="button" name="do" value="open">' . esc_html__( 'Reopen', 'ai-translator-for-translatepress' ) . '</button>';
		}
		echo '</p></form>';
		$pages = (int) ceil( $total / $per );
		if ( $pages > 1 ) {
			echo '<p>';
			for ( $i = 1; $i <= $pages; $i++ ) {
				echo $i === $paged ? '<strong>' . (int) $i . '</strong> ' : '<a href="' . esc_url( self::url( 'review', array( 'status' => $status, 'paged' => $i ) ) ) . '">' . (int) $i . '</a> ';
			}
			echo '</p>';
		}
	}

	/* -------------------------------------------------------------------------------------
	 * Actions.
	 * ----------------------------------------------------------------------------------- */

	/** @param string $action Nonce action. */
	private static function guard( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'ai-translator-for-translatepress' ), 403 );
		}
		check_admin_referer( $action );
	}

	/** Connection test. */
	public function handle_test() {
		self::guard( 'aitp_test' );
		$provider = AITP_Settings::provider();
		$result   = array(
			'ok'           => false,
			'model'        => '',
			'source'       => '',
			'target'       => '',
			'ms'           => 0,
			'sources'      => array(),
			'translations' => array(),
			'failed'       => array(),
			'message'      => '',
			'caps'         => '',
		);
		if ( ! $provider ) {
			$result['message'] = __( 'Enter the API key and model first.', 'ai-translator-for-translatepress' );
		} else {
			$trp    = get_option( 'trp_settings', array() );
			$source = isset( $trp['default-language'] ) ? (string) $trp['default-language'] : 'en_US';
			$target = 'fr_FR';
			if ( ! empty( $trp['translation-languages'] ) && is_array( $trp['translation-languages'] ) ) {
				foreach ( $trp['translation-languages'] as $l ) {
					if ( $l !== $source ) {
						$target = (string) $l;
						break;
					}
				}
			}
			$sources = array(
				'Welcome to our online store.',
				'Our sunglasses for kids block 100% of UVA and UVB rays (UV400).',
			);
			$log     = new AITP_Memory_Log();
			$breaker = new AITP_Circuit_Breaker( new AITP_Memory_State() );
			$t       = new AITP_Translator(
				$provider,
				AITP_Settings::rules(),
				array(
					'transport' => new AITP_WP_Transport(),
					'breaker'   => $breaker,
					'log'       => $log,
					'caps'      => new AITP_WP_Caps(),
				),
				array_merge( AITP_Settings::translator_options(), array( 'soft_retry' => false ) )
			);
			$start = microtime( true );
			$r     = $t->translate( $sources, $source, $target, microtime( true ) + 25 );
			$result['ms']           = (int) round( ( microtime( true ) - $start ) * 1000 );
			$result['model']        = $provider->model();
			$result['source']       = $source;
			$result['target']       = $target;
			$result['sources']      = $sources;
			$result['translations'] = $r['translations'];
			$result['failed']       = array_map( array( __CLASS__, 'code_label' ), $r['failed'] );
			$result['ok']           = count( $r['translations'] ) === count( $sources );
			foreach ( array_reverse( $log->rows ) as $row ) {
				if ( ! empty( $row['data']['message'] ) ) {
					$result['message'] = (string) $row['data']['message'];
					break;
				}
			}
			$caps = array();
			foreach ( $t->caps() as $k => $v ) {
				if ( $v === true ) {
					$caps[] = $k;
				}
			}
			$result['caps'] = implode( ', ', $caps );
			if ( $result['ok'] ) {
				AITP_Plugin::breaker()->reset();
			}
		}
		set_transient( 'aitp_test_' . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() );
		exit;
	}

	/** Resume after a pause. */
	public function handle_reset_breaker() {
		self::guard( 'aitp_reset_breaker' );
		AITP_Plugin::breaker()->reset();
		self::flash( 'success', __( 'Automatic translation resumed.', 'ai-translator-for-translatepress' ) );
		wp_safe_redirect( self::url( 'status' ) );
		exit;
	}

	/** Clear failure backoff. */
	public function handle_clear_failures() {
		global $wpdb;
		self::guard( 'aitp_clear_failures' );
		$wpdb->query( 'DELETE FROM ' . AITP_Schema::table( 'failures' ) );
		self::flash( 'success', __( 'Failed strings will be retried on the next page view.', 'ai-translator-for-translatepress' ) );
		wp_safe_redirect( self::url( 'status' ) );
		exit;
	}

	/** Review list bulk action. */
	public function handle_review() {
		global $wpdb;
		self::guard( 'aitp_review' );
		$do     = isset( $_POST['do'] ) && in_array( $_POST['do'], array( 'done', 'dismissed', 'open' ), true ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$ids    = isset( $_POST['ids'] ) ? array_filter( array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) ) : array();
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'open';
		if ( $do !== '' && $ids ) {
			$in = implode( ',', array_map( 'intval', $ids ) );
			$wpdb->query( $wpdb->prepare( 'UPDATE ' . AITP_Schema::table( 'review' ) . " SET status = %s WHERE id IN ($in)", $do ) );
		}
		wp_safe_redirect( self::url( 'review', array( 'status' => $status ) ) );
		exit;
	}

	/** CSV export of the review list. */
	public function handle_export_review() {
		global $wpdb;
		self::guard( 'aitp_export_review' );
		$status = isset( $_GET['status'] ) && in_array( $_GET['status'], array( 'open', 'done', 'dismissed' ), true ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : 'open';
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT id, source_locale, target_locale, original, translation, flags, saved, status, created_at FROM ' . AITP_Schema::table( 'review' ) . ' WHERE status = %s ORDER BY id', $status ), ARRAY_A );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=aitp-review-' . $status . '-' . gmdate( 'Ymd' ) . '.csv' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $out, array( 'id', 'source_locale', 'target_locale', 'original', 'translation', 'flags', 'saved', 'status', 'created_at' ) );
		foreach ( $rows as $r ) {
			foreach ( $r as $k => $v ) {
				// Neutralise spreadsheet formulas.
				if ( is_string( $v ) && $v !== '' && strpos( '=+-@', $v[0] ) !== false ) {
					$r[ $k ] = "'" . $v;
				}
			}
			fputcsv( $out, $r );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
