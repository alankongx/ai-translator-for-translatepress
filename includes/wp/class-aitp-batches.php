<?php
/**
 * Records of repair batches (what a batch reset, which pages it queued, how it ended), one
 * row per batch.
 *
 * Until 0.7.0 they lived in one option that kept the newest 200 only: older batches then
 * looked "cut off", and the test-journal boundary of each batch was lost. The backup rows
 * (table aitp_repair) stay the source for counts and rollbacks; these records add what the
 * rows cannot tell.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Batches {

	/**
	 * Every record.
	 *
	 * @return array<int,array> batch id => info
	 */
	public static function all() {
		global $wpdb;
		$out = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( 'SELECT id, info FROM ' . AITP_Schema::table( 'batches' ), ARRAY_A ) as $r ) {
			$info = json_decode( (string) $r['info'], true );
			if ( is_array( $info ) ) {
				$out[ (int) $r['id'] ] = $info;
			}
		}
		return $out;
	}

	/**
	 * @param int $id Batch id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$raw  = $wpdb->get_var( $wpdb->prepare( 'SELECT info FROM ' . AITP_Schema::table( 'batches' ) . ' WHERE id = %d', (int) $id ) );
		$info = is_string( $raw ) ? json_decode( $raw, true ) : null;
		return is_array( $info ) ? $info : null;
	}

	/**
	 * Write a record (whole).
	 *
	 * @param int   $id   Batch id.
	 * @param array $info Info.
	 */
	public static function save( $id, array $info ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO ' . AITP_Schema::table( 'batches' ) . ' (id, state, auto, info, created_at, updated_at) VALUES (%d, %s, %d, %s, %s, %s) ON DUPLICATE KEY UPDATE state = VALUES(state), auto = VALUES(auto), info = VALUES(info), updated_at = VALUES(updated_at)',
				(int) $id,
				isset( $info['state'] ) ? (string) $info['state'] : 'done',
				empty( $info['auto'] ) ? 0 : 1,
				(string) wp_json_encode( $info ),
				self::created( $id, $info ),
				$now
			)
		);
	}

	/**
	 * Add a record unless there is one (upgrade from the option).
	 *
	 * @param int   $id   Batch id.
	 * @param array $info Info.
	 */
	public static function insert_missing( $id, array $info ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'INSERT IGNORE INTO ' . AITP_Schema::table( 'batches' ) . ' (id, state, auto, info, created_at, updated_at) VALUES (%d, %s, %d, %s, %s, %s)',
				(int) $id,
				isset( $info['state'] ) ? (string) $info['state'] : 'done',
				empty( $info['auto'] ) ? 0 : 1,
				(string) wp_json_encode( $info ),
				self::created( $id, $info ),
				current_time( 'mysql', true )
			)
		);
	}

	/**
	 * Batches of the auto repair that were reset but never judged (the request that reset
	 * them ended before the auto repair took them on), oldest first.
	 *
	 * @param int $except Batch the auto repair is working on.
	 * @return array<int,array> batch id => info
	 */
	public static function auto_unjudged( $except = 0 ) {
		global $wpdb;
		$out = array();
		$old = gmdate( 'Y-m-d H:i:s', time() - 10 * MINUTE_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, state, info, updated_at FROM ' . AITP_Schema::table( 'batches' ) . " WHERE auto = 1 AND id <> %d AND (state = 'done' OR (state = 'running' AND updated_at < %s)) ORDER BY id DESC LIMIT 20", (int) $except, $old ), ARRAY_A );
		foreach ( array_reverse( $rows ) as $r ) {
			$info = json_decode( (string) $r['info'], true );
			if ( is_array( $info ) && empty( $info['auto_result'] ) && empty( $info['rolled_back'] ) ) {
				$out[ (int) $r['id'] ] = $info;
			}
		}
		return $out;
	}

	/** @param int $id Batch id. */
	public static function delete( $id ) {
		global $wpdb;
		$wpdb->delete( AITP_Schema::table( 'batches' ), array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @param int   $id   Batch id (milliseconds since 1970).
	 * @param array $info Info.
	 * @return string
	 */
	private static function created( $id, array $info ) {
		$t = ! empty( $info['time'] ) ? (int) $info['time'] : (int) floor( (int) $id / 1000 );
		return gmdate( 'Y-m-d H:i:s', $t > 0 ? $t : time() );
	}
}
