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

	/** Register hooks. */
	public function boot() {
		add_action( 'plugins_loaded', array( 'AITP_Schema', 'maybe_upgrade' ), 5 );
		add_filter( 'trp_machine_translation_engines', array( $this, 'add_engine' ), 30 );
		add_filter( 'trp_automatic_translation_engines_classes', array( $this, 'add_engine_class' ), 30 );
		add_action( 'aitp_daily', array( $this, 'daily_cleanup' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );

		if ( is_admin() ) {
			AITP_Admin::instance()->boot();
		}
		AITP_Updater::instance()->boot();
		AITP_Queue::instance()->boot();
		AITP_Audit::boot();
		AITP_Autofix::boot();
		AITP_Pagemap::boot();
		AITP_Cache_Purge::boot();
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
		if ( class_exists( 'TRP_Machine_Translator' ) && ! class_exists( 'AITP_TP_Engine' ) ) {
			require_once AITP_DIR . 'includes/wp/class-aitp-tp-engine.php';
		}
		if ( class_exists( 'AITP_TP_Engine' ) ) {
			$classes[ AITP_TP_ENGINE ] = 'AITP_TP_Engine';
		}
		return $classes;
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
