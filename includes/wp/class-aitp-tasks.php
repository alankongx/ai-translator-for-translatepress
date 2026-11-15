<?php
/**
 * Work lists of background jobs as table rows (page-mapping plan, cache-purge list).
 *
 * Kept in rows rather than one option: an option holding thousands of addresses is rewritten
 * whole on every step (megabytes per write) and silently fails above the database's packet
 * limit, so the job repeats the same step forever. Each entry is unique per job.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Tasks {

	/**
	 * Add entries (an entry already in the list is left as it is).
	 *
	 * @param string        $job   Job name (map, purge).
	 * @param array         $items Strings, or arrays (stored as JSON).
	 * @param callable|null $key   Item => unique key (default: the item itself).
	 * @return int Entries added.
	 */
	public static function add( $job, array $items, $key = null ) {
		global $wpdb;
		$t     = AITP_Schema::table( 'tasks' );
		$added = 0;
		foreach ( array_chunk( array_values( $items ), 200 ) as $chunk ) {
			$vals = array();
			foreach ( $chunk as $it ) {
				$data   = is_array( $it ) ? (string) wp_json_encode( $it ) : (string) $it;
				$k      = $key ? (string) call_user_func( $key, $it ) : $data;
				$vals[] = $wpdb->prepare( '(%s,%s,%s)', $job, sha1( $k ), $data );
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "INSERT IGNORE INTO $t (job, ukey, data) VALUES " . implode( ',', $vals ) );
			$added += max( 0, (int) $wpdb->rows_affected );
		}
		return $added;
	}

	/**
	 * First entries of a job, oldest first.
	 *
	 * @param string $job Job.
	 * @param int    $n   How many.
	 * @param bool   $json Decode JSON entries.
	 * @return array<int,mixed> id => entry.
	 */
	public static function first( $job, $n, $json = false ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, data FROM ' . AITP_Schema::table( 'tasks' ) . ' WHERE job = %s ORDER BY id LIMIT %d', $job, max( 1, (int) $n ) ), ARRAY_A );
		$out  = array();
		foreach ( $rows as $r ) {
			$out[ (int) $r['id'] ] = $json ? json_decode( (string) $r['data'], true ) : (string) $r['data'];
		}
		return $out;
	}

	/**
	 * @param int[] $ids Entries done.
	 * @return int Entries removed.
	 */
	public static function remove( array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->query( 'DELETE FROM ' . AITP_Schema::table( 'tasks' ) . ' WHERE id IN (' . implode( ',', $ids ) . ')' );
	}

	/**
	 * @param string $job Job.
	 * @return int Entries waiting.
	 */
	public static function count( $job ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . AITP_Schema::table( 'tasks' ) . ' WHERE job = %s', $job ) );
	}

	/** @param string $job Job whose entries are dropped. */
	public static function clear( $job ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . AITP_Schema::table( 'tasks' ) . ' WHERE job = %s', $job ) );
	}
}
