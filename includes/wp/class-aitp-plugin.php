<?php
/**
 * Wiring: TranslatePress engine registration, translator factory, cron.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Plugin {

	/** @var AITP_Plugin|null */
	private static $instance = null;

	/** @var AITP_Translator|null */
	private $translator = null;

	/** @return AITP_Plugin */
	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Oldest TranslatePress release with the engine API this plugin relies on (lock timeout). */
	const MIN_TP = '3.3.5';

	/**
	 * Whether the active TranslatePress is new enough. True while TranslatePress is not loaded
	 * yet (the check is repeated where it matters).
	 *
	 * @return bool
	 */
	public static function tp_supported() {
		if ( defined( 'TRP_PLUGIN_VERSION' ) ) {
			return version_compare( (string) TRP_PLUGIN_VERSION, self::MIN_TP, '>=' );
		}
		if ( class_exists( 'TRP_Machine_Translator' ) ) {
			return method_exists( 'TRP_Machine_Translator', 'get_machine_translation_lock_timeout' );
		}
		return true;
	}

	/** Register hooks. */
	public function boot() {
		add_action( 'plugins_loaded', array( 'AITP_Schema', 'maybe_upgrade' ), 5 );
		add_filter( 'trp_machine_translation_engines', array( $this, 'add_engine' ), 30 );
		add_filter( 'trp_automatic_translation_engines_classes', array( $this, 'add_engine_class' ), 30 );
		add_action( 'aitp_daily', array( $this, 'daily_cleanup' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
		add_action( 'init', array( __CLASS__, 'remember_shortcodes' ), 999 );
		// TranslatePress rewrites quotes and punctuation in machine translations after the
		// engine returns them; that would undo what the validator checked (and HHG's damage
		// came partly from it). Switch it off while this engine is selected.
		add_filter( 'trp_disable_restore_translation_quotes', array( __CLASS__, 'disable_tp_rewrite' ), 30 );
		add_filter( 'trp_disable_restore_punctuation_patterns', array( __CLASS__, 'disable_tp_rewrite' ), 30 );

		if ( is_admin() ) {
			AITP_Admin::instance()->boot();
		}
		AITP_Updater::instance()->boot();
		AITP_Queue::instance()->boot();
		AITP_Audit::boot();
		AITP_Autofix::boot();
		AITP_Pagemap::boot();
		AITP_Cache_Purge::boot();
		AITP_Journal::boot();
		// TranslatePress keeps only the settings of engines that are active when its form is saved:
		// keep what another engine stored (HHG's key and model) while it is switched off.
		add_filter( 'trp_machine_translation_sanitize_settings', array( __CLASS__, 'keep_other_engine_settings' ), PHP_INT_MAX, 2 );
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'WP_CLI' ) ) {
			require_once AITP_DIR . 'includes/wp/class-aitp-cli.php';
			WP_CLI::add_command( 'aitp', 'AITP_CLI' );
		}
	}

	/** Load translations of the plugin's own interface. */
	public function load_textdomain() {
		load_plugin_textdomain( 'ai-translator-for-translatepress', false, dirname( plugin_basename( AITP_FILE ) ) . '/languages' );
	}

	/**
	 * @param array $engines TranslatePress engine list.
	 * @return array
	 */
	public function add_engine( $engines ) {
		if ( ! self::tp_supported() ) {
			return $engines; // Notice on the admin screens explains why.
		}
		$engines[] = array(
			'value' => AITP_TP_ENGINE,
			'label' => __( 'AI Translator (Gemini / OpenAI-compatible)', 'ai-translator-for-translatepress' ),
		);
		return $engines;
	}

	/**
	 * @param array $classes engine => class.
	 * @return array
	 */
	public function add_engine_class( $classes ) {
		if ( ! self::tp_supported() ) {
			return $classes;
		}
		if ( class_exists( 'TRP_Machine_Translator' ) && ! class_exists( 'AITP_TP_Engine' ) ) {
			require_once AITP_DIR . 'includes/wp/class-aitp-tp-engine.php';
		}
		if ( class_exists( 'AITP_TP_Engine' ) ) {
			$classes[ AITP_TP_ENGINE ] = 'AITP_TP_Engine';
		}
		return $classes;
	}

	/**
	 * @param mixed $disabled Current value.
	 * @return mixed
	 */
	public static function disable_tp_rewrite( $disabled ) {
		return self::engine_selected() ? true : $disabled;
	}

	/** Registered shortcodes: only these "[name]" tokens are protected as placeholders. */
	public static function remember_shortcodes() {
		if ( isset( $GLOBALS['shortcode_tags'] ) && is_array( $GLOBALS['shortcode_tags'] ) ) {
			AITP_Text::$shortcodes = array_map( 'strval', array_keys( $GLOBALS['shortcode_tags'] ) );
		}
	}

	/** @return bool Whether TranslatePress currently uses this engine. */
	public static function engine_selected() {
		$mt = get_option( 'trp_machine_translation_settings', array() );
		return is_array( $mt ) && isset( $mt['translation-engine'] ) && $mt['translation-engine'] === AITP_TP_ENGINE;
	}

	/** @return bool */
	public static function automatic_translation_on() {
		$mt = get_option( 'trp_machine_translation_settings', array() );
		return is_array( $mt ) && isset( $mt['machine-translation'] ) && $mt['machine-translation'] === 'yes';
	}

	/**
	 * Seconds one engine call may take. Stays well below TranslatePress' lock timeout so a
	 * concurrent page view never takes over strings that are still being translated (and
	 * pays for them twice).
	 *
	 * @param int $lock_timeout TranslatePress lock timeout.
	 * @return int
	 */
	public function time_budget( $lock_timeout ) {
		$s      = AITP_Settings::get();
		$budget = (int) $s['time_budget'];
		if ( AITP_Queue::is_warm() ) {
			$budget = max( $budget, AITP_Queue::page_seconds() ); // Background visit: nobody is waiting.
		}
		return (int) max( 10, min( $budget, (int) $lock_timeout - 15 ) );
	}

	/**
	 * Translator with WordPress storage. Null when not configured.
	 *
	 * @param object|null $tp_logger TranslatePress machine translator logger (quota).
	 * @return AITP_Translator|null
	 */
	public function translator( $tp_logger = null ) {
		if ( $this->translator !== null ) {
			return $this->translator;
		}
		$provider = AITP_Settings::provider();
		if ( ! $provider ) {
			return null;
		}
		$deps = array(
			'transport' => new AITP_WP_Transport(),
			'cache'     => new AITP_WP_Cache(),
			'quota'     => $tp_logger && method_exists( $tp_logger, 'increase_counter_with_value' ) ? new AITP_TP_Quota( $tp_logger ) : new AITP_Memory_Quota(),
			'breaker'   => self::breaker(),
			'failures'  => new AITP_WP_Failures(),
			'review'    => new AITP_WP_Review(),
			'log'       => new AITP_WP_Log(),
			'caps'      => new AITP_WP_Caps(),
			'usage'     => new AITP_WP_Usage(),
		);
		$this->translator = new AITP_Translator( $provider, AITP_Settings::rules(), $deps, AITP_Settings::translator_options() );
		return $this->translator;
	}

	/** @return AITP_Circuit_Breaker */
	public static function breaker() {
		return new AITP_Circuit_Breaker( new AITP_WP_Option_State( 'aitp_breaker' ) );
	}

	/** Daily: prune logs, old cache entries and stale failure records. */
	public function daily_cleanup() {
		global $wpdb;
		$s    = AITP_Settings::get();
		$days = (int) $s['log_days'];
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . AITP_Schema::table( 'log' ) . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . AITP_Schema::table( 'cache' ) . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - 60 * DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . AITP_Schema::table( 'failures' ) . ' WHERE updated_at < %s', gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ) );
		// phpcs:enable
	}

	/**
	 * TranslatePress drops every key of its automatic-translation option that no active engine
	 * claims, each time the option is saved (its form, its daily counter, a restore from code).
	 * Keys of an engine that is switched off (HHG's key and model) are kept: the value given
	 * in this save if there is one, else the stored one. Keys some engine sanitized are left
	 * as they are.
	 *
	 * @param mixed $out Sanitized settings.
	 * @param mixed $in  Settings given to the save.
	 * @return mixed
	 */
	public static function keep_other_engine_settings( $out, $in = array() ) {
		if ( ! is_array( $out ) ) {
			return $out;
		}
		$old = get_option( 'trp_machine_translation_settings', array() );
		$old = is_array( $old ) ? $old : array();
		$in  = is_array( $in ) ? $in : array();
		foreach ( array_keys( $old + $in ) as $k ) {
			if ( array_key_exists( $k, $out ) || ! preg_match( '~^[A-Za-z0-9_-]{1,64}$~', (string) $k ) || strpos( (string) $k, 'aitp' ) === 0 ) {
				continue;
			}
			$out[ $k ] = array_key_exists( $k, $in ) ? $in[ $k ] : $old[ $k ];
		}
		return $out;
	}

	/** Activation. */
	public static function activate() {
		AITP_Schema::install();
		if ( ! wp_next_scheduled( 'aitp_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'aitp_daily' );
		}
	}

	/** Deactivation. */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'aitp_daily' );
		wp_clear_scheduled_hook( AITP_Queue::CRON );
		wp_clear_scheduled_hook( AITP_Audit::CRON );
		wp_clear_scheduled_hook( AITP_Autofix::CRON );
		wp_clear_scheduled_hook( AITP_Pagemap::CRON );
		wp_clear_scheduled_hook( AITP_Cache_Purge::CRON );
	}
}
