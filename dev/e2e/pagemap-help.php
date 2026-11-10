<?php
/**
 * Test site only. wp eval-file /e2e/pagemap-help.php <mode> …
 *
 * dict <lang> [max_id]       Rows, highest id and checksum of a dictionary table (rows up to max_id).
 * find <lang> <original>     The row of an original, with its post_parent_id links.
 * set <lang> <id> <status> <translated|__NULL__>
 * cand <lang> <id,id,…>      Writes an import file for these rows; prints its path.
 * queue                      Queue rows (url, lang, status, note).
 * visits [clear]             Signed internal visits logged by the e2e mu-plugin.
 * originals                  Rows of the TranslatePress original strings table.
 * meta <slug> [clear]       post_parent_id links of a page (clear: remove them and TP's daily markers).
 * permalink <slug>           Address of a page.
 */
global $wpdb;
$mode = isset( $args[0] ) ? $args[0] : '';
$dict = function ( $l ) use ( $wpdb ) {
	return $wpdb->prefix . 'trp_dictionary_en_us_' . strtolower( $l );
};
$out  = array();
switch ( $mode ) {
	case 'dict':
		$t    = $dict( $args[1] );
		$max  = isset( $args[2] ) ? (int) $args[2] : PHP_INT_MAX;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, original, translated, status FROM $t WHERE id <= %d ORDER BY id", $max ), ARRAY_A );
		$s    = '';
		foreach ( $rows as $r ) {
			$s .= $r['id'] . '|' . $r['original'] . '|' . ( $r['translated'] === null ? "\0NULL" : $r['translated'] ) . '|' . $r['status'] . "\n";
		}
		$out = array( 'rows' => count( $rows ), 'max' => (int) $wpdb->get_var( "SELECT MAX(id) FROM $t" ), 'sum' => md5( $s ) );
		break;
	case 'find':
		$t   = $dict( $args[1] );
		$r   = $wpdb->get_row( $wpdb->prepare( "SELECT id, original, translated, status, original_id FROM $t WHERE original = %s", $args[2] ), ARRAY_A );
		$out = $r ? $r + array( 'is_null' => $r['translated'] === null, 'meta' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}trp_original_meta WHERE meta_key = 'post_parent_id' AND original_id = %d", (int) $r['original_id'] ) ) ) : array( 'id' => 0 );
		break;
	case 'set':
		$t = $dict( $args[1] );
		if ( $args[4] === '__NULL__' ) {
			$wpdb->query( $wpdb->prepare( "UPDATE $t SET translated = NULL, status = %d WHERE id = %d", (int) $args[3], (int) $args[2] ) );
		} else {
			$wpdb->update( $t, array( 'translated' => $args[4], 'status' => (int) $args[3] ), array( 'id' => (int) $args[2] ) );
		}
		$out = array( 'ok' => 1 );
		break;
	case 'cand':
		$t    = $dict( $args[1] );
		$ids  = array_map( 'intval', explode( ',', $args[2] ) );
		$rows = array();
		foreach ( $wpdb->get_results( "SELECT id, original, translated FROM $t WHERE id IN (" . implode( ',', $ids ) . ')', ARRAY_A ) as $r ) {
			$rows[] = array( 'language' => $args[1], 'id' => $r['id'], 'original' => $r['original'], 'old' => $r['translated'] );
		}
		@mkdir( WP_CONTENT_DIR . '/e2e-state', 0777, true );
		$file = WP_CONTENT_DIR . '/e2e-state/pagemap-cand.json';
		file_put_contents( $file, wp_json_encode( array( 'rows' => $rows ) ) );
		$out = array( 'file' => $file, 'rows' => count( $rows ) );
		break;
	case 'queue':
		$out = array( 'rows' => $wpdb->get_results( "SELECT url, lang, status, note FROM {$wpdb->prefix}aitp_queue ORDER BY id", ARRAY_A ) );
		break;
	case 'visits':
		$f = WP_CONTENT_DIR . '/e2e-state/visits.log';
		if ( isset( $args[1] ) && $args[1] === 'clear' ) {
			@unlink( $f );
		}
		$out = array( 'visits' => is_readable( $f ) ? array_values( array_filter( explode( "\n", (string) file_get_contents( $f ) ) ) ) : array() );
		break;
	case 'originals':
		$out = array( 'rows' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}trp_original_strings" ) );
		break;
	case 'meta':
		$p  = get_page_by_path( $args[1] );
		$id = $p ? (int) $p->ID : 0;
		if ( isset( $args[2] ) && $args[2] === 'clear' ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}trp_original_meta WHERE meta_key = 'post_parent_id' AND meta_value = %s", (string) $id ) );
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%processed\\_original\\_string\\_meta%'" );
			wp_cache_flush();
		}
		$out = array( 'post' => $id, 'links' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}trp_original_meta WHERE meta_key = 'post_parent_id' AND meta_value = %s", (string) $id ) ), 'markers' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%processed_original_string_meta%'" ) );
		break;
	case 'permalink':
		$p   = get_page_by_path( $args[1] );
		$out = array( 'url' => $p ? get_permalink( $p ) : '' );
		break;
}
echo wp_json_encode( $out );
