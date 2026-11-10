<?php
/**
 * Test site only. wp eval-file /e2e/audit-seed.php <seed|check|edit|manual|big|unbig|import-file>
 *
 * seed   Plants the HHG failure types in fully translated dictionaries (fr_FR, ja, ar):
 *        a shifted block (each row gets its neighbour's translation), English saved as
 *        translation, cut-down translations, bad manual rows (status 2, must never be touched),
 *        broken structured data and a gettext row with a lost placeholder.
 *        Writes wp-content/e2e-state/audit-seed.json (dump prints it).
 * check  Current value of every planted row + checksum of all manual rows.
 * edit   Simulates another tool fixing one planted row after the scan (args: lang id text).
 * manual Marks a row as manually translated (args: lang id).
 * big    Adds 50,000 good machine translations to es_MX (args: n); unbig removes them.
 * import-file Writes a candidate list for the import test to wp-content/e2e-state/import.json.
 */
require_once __DIR__ . '/pseudo.php';
global $wpdb;
$mode  = isset( $args[0] ) ? $args[0] : 'check';
$file  = WP_CONTENT_DIR . '/e2e-state/audit-seed.json';
$dict  = function ( $l ) use ( $wpdb ) {
	return $wpdb->prefix . 'trp_dictionary_en_us_' . strtolower( $l );
};
$terms = AITP_Settings::rules()->protected_terms;

$manual_sum = function () use ( $wpdb ) {
	$all = '';
	foreach ( $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}trp_%'" ) as $t ) {
		if ( ! $wpdb->get_var( "SHOW COLUMNS FROM $t LIKE 'status'" ) ) {
			continue;
		}
		foreach ( $wpdb->get_results( "SELECT id, translated FROM $t WHERE status = 2 ORDER BY id", ARRAY_A ) as $r ) {
			$all .= $t . '|' . $r['id'] . '|' . $r['translated'] . "\n";
		}
	}
	return md5( $all );
};

if ( $mode === 'seed' ) {
	// gettext rows survive reset.php: undo a previous seed first.
	if ( is_file( $file ) ) {
		$prev = json_decode( (string) file_get_contents( $file ), true );
		foreach ( isset( $prev['rows'] ) ? $prev['rows'] : array() as $s ) {
			if ( $s['kind'] === 'gettext' ) {
				$wpdb->update( $wpdb->prefix . 'trp_gettext_' . strtolower( $s['lang'] ), array( 'translated' => $s['good'], 'status' => 1 ), array( 'id' => $s['id'] ) );
			}
		}
	}
	$seed = array();
	foreach ( array( 'fr_FR', 'ja', 'ar' ) as $l ) {
		$t    = $dict( $l );
		$rows = $wpdb->get_results( "SELECT id, original, translated FROM $t WHERE status = 1 ORDER BY id", ARRAY_A );
		$used = array();
		// 1. Shifted block of 8 rows: row i gets the translation of row i+1 (HHG misalignment).
		$start = 40;
		for ( $i = $start; $i < $start + 8; $i++ ) {
			$bad = $rows[ $i + 1 ]['translated'];
			$wpdb->update( $t, array( 'translated' => $bad ), array( 'id' => $rows[ $i ]['id'] ) );
			$seed[]                       = array( 'kind' => 'dictionary', 'lang' => $l, 'id' => (int) $rows[ $i ]['id'], 'type' => 'shift', 'original' => $rows[ $i ]['original'], 'good' => $rows[ $i ]['translated'], 'bad' => $bad, 'long' => mb_strlen( $rows[ $i ]['original'] ) >= 40 );
			$used[ $rows[ $i ]['id'] ]    = true;
			$used[ $rows[ $i + 1 ]['id'] ] = true;
		}
		$pick = function ( $test, $n ) use ( $rows, &$used ) {
			$out = array();
			foreach ( $rows as $k => $r ) {
				if ( $k < 5 || isset( $used[ $r['id'] ] ) || isset( $used[ $r['id'] - 1 ] ) || isset( $used[ $r['id'] + 1 ] ) || ! $test( $r ) ) {
					continue;
				}
				$out[]             = $r;
				$used[ $r['id'] ] = true;
				if ( count( $out ) >= $n ) {
					break;
				}
			}
			return $out;
		};
		// 2. English saved as translation (headline-like).
		foreach ( $pick( function ( $r ) { return str_word_count( wp_strip_all_tags( $r['original'] ) ) >= 7 && strlen( $r['original'] ) < 300; }, 3 ) as $r ) {
			$wpdb->update( $t, array( 'translated' => $r['original'] ), array( 'id' => $r['id'] ) );
			$seed[] = array( 'kind' => 'dictionary', 'lang' => $l, 'id' => (int) $r['id'], 'type' => 'english', 'original' => $r['original'], 'good' => $r['translated'], 'bad' => $r['original'] );
		}
		// 3. Cut down to one word.
		foreach ( $pick( function ( $r ) { return strlen( $r['original'] ) >= 120; }, 3 ) as $r ) {
			$words = preg_split( '~\s+~u', trim( wp_strip_all_tags( $r['translated'] ) ) );
			$bad   = $l === 'ja' ? mb_substr( wp_strip_all_tags( $r['translated'] ), 0, 4 ) : $words[0];
			$wpdb->update( $t, array( 'translated' => $bad ), array( 'id' => $r['id'] ) );
			$seed[] = array( 'kind' => 'dictionary', 'lang' => $l, 'id' => (int) $r['id'], 'type' => 'truncated', 'original' => $r['original'], 'good' => $r['translated'], 'bad' => $bad );
		}
		// 4. Bad manual rows: status 2 must be left alone by the check, the repair and the rollback.
		foreach ( $pick( function ( $r ) { return strlen( $r['original'] ) >= 60; }, 2 ) as $r ) {
			$wpdb->update( $t, array( 'translated' => 'Manuel', 'status' => 2 ), array( 'id' => $r['id'] ) );
			$seed[] = array( 'kind' => 'dictionary', 'lang' => $l, 'id' => (int) $r['id'], 'type' => 'manual', 'original' => $r['original'], 'good' => $r['translated'], 'bad' => 'Manuel' );
		}
	}
	// 5. Structured data in fr_FR: one broken, one correct.
	$t   = $dict( 'fr_FR' );
	$src = '{"@context":"https://schema.org","@type":"FAQPage","mainEntity":[{"@type":"Question","name":"What is TR90?","acceptedAnswer":{"@type":"Answer","text":"A flexible frame material."}}]}';
	$ok  = '{"@context":"https://schema.org","@type":"FAQPage","mainEntity":[{"@type":"Question","name":"Qu\'est-ce que le TR90 ?","acceptedAnswer":{"@type":"Answer","text":"Un matériau de monture flexible."}}]}';
	$bad = '{"@context":"https://schema.org","@type":"PageFAQ","entitePrincipale":[{"@type":"Question","nom":"Qu\'est-ce que le TR90 ?"}]}';
	$wpdb->insert( $t, array( 'original' => $src, 'translated' => $bad, 'status' => 1, 'block_type' => 0 ) );
	$seed[] = array( 'kind' => 'dictionary', 'lang' => 'fr_FR', 'id' => (int) $wpdb->insert_id, 'type' => 'structured', 'original' => $src, 'good' => $ok, 'bad' => $bad );
	$wpdb->insert( $t, array( 'original' => str_replace( 'TR90', 'UV400', $src ), 'translated' => str_replace( 'TR90', 'UV400', $ok ), 'status' => 1, 'block_type' => 0 ) );
	// 6. gettext fr_FR: placeholder lost.
	$gt  = $wpdb->prefix . 'trp_gettext_fr_fr';
	$ot  = $wpdb->prefix . 'trp_gettext_original_strings';
	$row = $wpdb->get_row( "SELECT tt.id, COALESCE(NULLIF(ot.original,''), tt.original) original, tt.translated FROM $gt tt LEFT JOIN $ot ot ON tt.original_id = ot.id WHERE tt.status = 1 AND tt.translated LIKE '%\\%s%' ORDER BY tt.id LIMIT 1", ARRAY_A );
	if ( $row ) {
		$bad = trim( str_replace( '%s', '', $row['translated'] ) );
		$wpdb->update( $gt, array( 'translated' => $bad ), array( 'id' => $row['id'] ) );
		$seed[] = array( 'kind' => 'gettext', 'lang' => 'fr_FR', 'id' => (int) $row['id'], 'type' => 'placeholder', 'original' => $row['original'], 'good' => $row['translated'], 'bad' => $bad );
	}
	if ( ! is_dir( dirname( $file ) ) ) {
		mkdir( dirname( $file ), 0777, true );
	}
	file_put_contents( $file, wp_json_encode( array( 'rows' => $seed, 'manual_sum' => $manual_sum() ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) );
	echo wp_json_encode( array( 'seeded' => count( $seed ) ) ) . "\n";
	return;
}

if ( $mode === 'dump' ) {
	echo file_get_contents( $file ) . "\n";
	return;
}

if ( $mode === 'check' ) {
	$seed = json_decode( file_get_contents( $file ), true );
	$out  = array();
	foreach ( $seed['rows'] as $s ) {
		$t     = $s['kind'] === 'gettext' ? $wpdb->prefix . 'trp_gettext_' . strtolower( $s['lang'] ) : $dict( $s['lang'] );
		$r     = $wpdb->get_row( $wpdb->prepare( "SELECT translated, status FROM $t WHERE id = %d", $s['id'] ), ARRAY_A );
		$out[] = array( 'kind' => $s['kind'], 'lang' => $s['lang'], 'id' => $s['id'], 'type' => $s['type'], 'status' => $r ? (int) $r['status'] : -1, 'is_bad' => $r && $r['translated'] === $s['bad'], 'is_good' => $r && $r['translated'] === $s['good'], 'empty' => $r && ( $r['translated'] === null || $r['translated'] === '' ) );
	}
	echo wp_json_encode( array( 'rows' => $out, 'manual_sum' => $manual_sum() ) ) . "\n";
	return;
}

if ( $mode === 'restore-gettext' ) {
	// Planted gettext rows are not emptied by reset.php: put the good text back.
	$seed = json_decode( file_get_contents( $file ), true );
	foreach ( $seed['rows'] as $s ) {
		if ( $s['kind'] === 'gettext' ) {
			$wpdb->update( $wpdb->prefix . 'trp_gettext_' . strtolower( $s['lang'] ), array( 'translated' => $s['good'], 'status' => 1 ), array( 'id' => $s['id'] ) );
		}
	}
	echo "{}\n";
	return;
}

if ( $mode === 'edit' ) {
	$wpdb->update( $dict( $args[1] ), array( 'translated' => $args[3] ), array( 'id' => (int) $args[2] ) );
	echo "ok\n";
	return;
}

if ( $mode === 'manual' ) {
	$wpdb->update( $dict( $args[1] ), array( 'status' => 2, 'translated' => 'Corrigé à la main' ), array( 'id' => (int) $args[2] ) );
	echo "ok\n";
	return;
}

if ( $mode === 'big' ) {
	$n    = isset( $args[1] ) ? (int) $args[1] : 50000;
	$t    = $dict( 'es_MX' );
	$base = array( 'Lightweight frames for comfortable everyday wear', 'Our team checks every lens before shipping', 'Choose the frame shape that fits your face', 'Polarized lenses reduce glare on water and snow', 'Contact us for a quote on custom colors', 'Free samples for qualified distributors in 30 countries' );
	$vals = array();
	for ( $i = 0; $i < $n; $i++ ) {
		$o      = $base[ $i % count( $base ) ] . ' — batch ' . $i . ' model OK-' . ( 1000 + $i % 900 ) . '.';
		$vals[] = $wpdb->prepare( '(%s, %s, 1, 9)', $o, aitp_e2e_pseudo( $o, 'es_MX', $terms ) );
		if ( count( $vals ) === 1000 ) {
			$wpdb->query( "INSERT INTO $t (original, translated, status, block_type) VALUES " . implode( ',', $vals ) );
			$vals = array();
		}
	}
	if ( $vals ) {
		$wpdb->query( "INSERT INTO $t (original, translated, status, block_type) VALUES " . implode( ',', $vals ) );
	}
	echo wp_json_encode( array( 'inserted' => $n ) ) . "\n";
	return;
}

if ( $mode === 'unbig' ) {
	echo wp_json_encode( array( 'removed' => (int) $wpdb->query( 'DELETE FROM ' . $dict( 'es_MX' ) . ' WHERE block_type = 9' ) ) ) . "\n";
	return;
}

if ( $mode === 'import-file' ) {
	$seed = json_decode( file_get_contents( $file ), true );
	$list = array();
	foreach ( $seed['rows'] as $s ) {
		$list[] = array( 'language' => $s['lang'], 'id' => (string) $s['id'], 'original' => $s['original'], 'old' => $s['bad'], 'table' => $s['kind'] );
	}
	// A candidate whose stored translation is different now, and one in a language that does not exist.
	$list[] = array( 'language' => 'fr_FR', 'id' => (string) $seed['rows'][0]['id'], 'original' => $seed['rows'][0]['original'], 'old' => 'not what is stored' );
	$list[] = array( 'language' => 'xx_XX', 'id' => '1', 'original' => 'a', 'old' => 'b' );
	file_put_contents( WP_CONTENT_DIR . '/e2e-state/import.json', wp_json_encode( $list, JSON_UNESCAPED_UNICODE ) );
	echo wp_json_encode( array( 'candidates' => count( $list ) ) ) . "\n";
}
