<?php
/**
 * WordPress implementations of the core storage interfaces.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

final class AITP_WP_Cache implements AITP_Cache_Store {
	public function get( $key ) {
		global $wpdb;
		$v = $wpdb->get_var( $wpdb->prepare( 'SELECT translation FROM ' . AITP_Schema::table( 'cache' ) . ' WHERE ckey = %s', $key ) );
		return is_string( $v ) ? $v : null;
	}
	public function set( $key, $target_locale, $text ) {
		global $wpdb;
		$wpdb->replace(
			AITP_Schema::table( 'cache' ),
			array(
				'ckey'          => $key,
				'target_locale' => (string) $target_locale,
				'translation'   => (string) $text,
				'created_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}
	public function delete( $key ) {
		global $wpdb;
		$wpdb->delete( AITP_Schema::table( 'cache' ), array( 'ckey' => $key ), array( '%s' ) );
	}
}

final class AITP_WP_Failures implements AITP_Failure_Store {
	public function held( $fkey ) {
		global $wpdb;
		$next = $wpdb->get_var( $wpdb->prepare( 'SELECT next_retry_at FROM ' . AITP_Schema::table( 'failures' ) . ' WHERE fkey = %s', $fkey ) );
		return $next !== null && (int) $next > time();
	}
	public function failed( $fkey, array $info ) {
		global $wpdb;
		$t        = AITP_Schema::table( 'failures' );
		$attempts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT attempts FROM $t WHERE fkey = %s", $fkey ) ) + 1;
		$wpdb->replace(
			$t,
			array(
				'fkey'          => $fkey,
				'source_locale' => isset( $info['source_locale'] ) ? (string) $info['source_locale'] : '',
				'target_locale' => isset( $info['target_locale'] ) ? (string) $info['target_locale'] : '',
				'reason'        => isset( $info['reason'] ) ? substr( (string) $info['reason'], 0, 64 ) : '',
				'excerpt'       => isset( $info['excerpt'] ) ? (string) $info['excerpt'] : '',
				'attempts'      => $attempts,
				'next_retry_at' => time() + AITP_Translator::backoff_seconds( $attempts ),
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s' )
		);
	}
	public function clear( $fkey ) {
		global $wpdb;
		$wpdb->delete( AITP_Schema::table( 'failures' ), array( 'fkey' => $fkey ), array( '%s' ) );
	}
}

final class AITP_WP_Review implements AITP_Review_Store {
	public function add( array $row ) {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . AITP_Schema::table( 'review' ) . ' (source_locale, target_locale, original, original_hash, translation, translation_hash, flags, saved, status, created_at) VALUES (%s, %s, %s, %s, %s, %s, %s, %d, %s, %s)',
				(string) $row['source_locale'],
				(string) $row['target_locale'],
				(string) $row['original'],
				sha1( (string) $row['original'] ),
				(string) $row['translation'],
				sha1( (string) $row['translation'] ),
				substr( implode( ',', (array) $row['flags'] ), 0, 255 ),
				! empty( $row['saved'] ) ? 1 : 0,
				'open',
				current_time( 'mysql', true )
			)
		);
	}
}

final class AITP_WP_Log implements AITP_Log {
	public function log( $level, $event, array $data ) {
		global $wpdb;
		$wpdb->insert(
			AITP_Schema::table( 'log' ),
			array(
				'created_at'    => current_time( 'mysql', true ),
				'level'         => (string) $level,
				'event'         => (string) $event,
				'target_locale' => isset( $data['target'] ) ? (string) $data['target'] : '',
				'data'          => (string) wp_json_encode( $data ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}
}

final class AITP_WP_Option_State implements AITP_State_Store {
	/** @var string */
	private $name;
	public function __construct( $name ) {
		$this->name = $name;
	}
	public function get() {
		wp_cache_delete( $this->name, 'options' );
		$v = get_option( $this->name, array() );
		return is_array( $v ) ? $v : array();
	}
	public function set( array $state ) {
		update_option( $this->name, $state, false );
	}
}

final class AITP_WP_Caps implements AITP_Caps_Store {
	public function get( $fingerprint ) {
		$all = get_option( 'aitp_caps', array() );
		return is_array( $all ) && isset( $all[ $fingerprint ] ) && is_array( $all[ $fingerprint ] ) ? $all[ $fingerprint ] : null;
	}
	public function set( $fingerprint, array $caps ) {
		$all = get_option( 'aitp_caps', array() );
		$all = is_array( $all ) ? $all : array();
		$all[ $fingerprint ] = $caps;
		update_option( 'aitp_caps', $all, false );
	}
}

/**
 * Daily character quota shared with TranslatePress ("Limit machine translation / characters
 * per day" in TranslatePress settings).
 *
 * Bytes are reserved atomically (TranslatePress' own counter, updated with SELECT … FOR
 * UPDATE) before a request is sent, so parallel page views cannot overshoot the limit.
 * Bytes of requests that were never processed are given back.
 */
final class AITP_TP_Quota implements AITP_Quota {

	/** @var object TRP_Machine_Translator_Logger */
	private $logger;

	/** @param object $logger TranslatePress machine translator logger. */
	public function __construct( $logger ) {
		$this->logger = $logger;
	}

	/** @return int */
	public static function limit() {
		$mt = get_option( 'trp_machine_translation_settings', array() );
		return isset( $mt['machine_translation_limit'] ) && $mt['machine_translation_limit'] !== '' ? (int) $mt['machine_translation_limit'] : 1000000;
	}

	public function reserve( $bytes ) {
		$bytes = (int) $bytes;
		if ( $bytes <= 0 ) {
			return true;
		}
		$new = (int) $this->logger->increase_counter_with_value( $bytes );
		if ( $new > self::limit() ) {
			$this->logger->increase_counter_with_value( -$bytes );
			return false;
		}
		return true;
	}

	public function commit( $bytes ) {
		if ( method_exists( $this->logger, 'count_machine_translated_characters' ) ) {
			$this->logger->count_machine_translated_characters( (int) $bytes );
		}
	}

	public function release( $bytes ) {
		$bytes   = (int) $bytes;
		$current = (int) $this->logger->get_current_counter();
		$give    = min( $bytes, max( 0, $current ) ); // The counter may have been reset at midnight.
		if ( $give > 0 ) {
			$this->logger->increase_counter_with_value( -$give );
		}
	}
}
