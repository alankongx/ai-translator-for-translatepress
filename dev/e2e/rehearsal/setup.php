<?php
/**
 * Rehearsal of the test-site acceptance (dev/e2e only, never shipped).
 * wp eval-file /e2e/rehearsal/setup.php <phase>
 *
 *  content   4 pages like the test site's 24 (front page, TR90 collection, a product page with a
 *            non-ASCII address, a long article), ~150 strings each, 20 footer strings on every
 *            page, 900 posts.
 *  (then rehearsal/run.mjs setup visits the 4 pages × 6 languages with the mock AI)
 *  bulk      what the test site has around them: rows of the 900 posts' content linked to their
 *            post (TranslatePress post_parent_id), the footer strings linked to all 900 posts,
 *            90,000 more rows per language, manual translations (status 2), errors.
 *  snapshot  copies every TranslatePress table to zz_rh_<table> and the two TranslatePress
 *            options to zz_rh_options (the baseline of step 0), prints row counts and max ids.
 *  compare   the baseline check of step 7: rows changed / missing / new against the copies.
 *  drop      removes the zz_rh_ copies.
 */
global $wpdb;
$phase = isset( $args[0] ) ? $args[0] : '';
$langs = array( 'fr_FR', 'es_MX', 'ja', 'ar', 'de_DE', 'pt_BR' );

/** The mock AI's pseudo translation (dev/mock-llm/server.js), so stored rows pass every check. */
function rh_pseudo( $text, $lang ) {
	static $kata = null, $arab = null;
	if ( $kata === null ) {
		$kata = preg_split( '//u', 'アイウエオカキクケコサシスセソタチツテトナニヌネノハヒフヘホマミムメモヤユヨラリルレロワヲン', -1, PREG_SPLIT_NO_EMPTY );
		$arab = preg_split( '//u', 'ابتثجحخدذرزسشصضطظعغفقكلمنهوي', -1, PREG_SPLIT_NO_EMPTY );
	}
	$alpha = $lang === 'ja' ? $kata : ( $lang === 'ar' ? $arab : null );
	$parts = preg_split( '~(<[^>]*>)~', $text, -1, PREG_SPLIT_DELIM_CAPTURE );
	foreach ( $parts as $i => $p ) {
		if ( $p === '' || $p[0] === '<' ) {
			continue;
		}
		$p           = str_replace( 'OKAY EYEWEAR', "\x01", $p );
		$parts[ $i ] = str_replace(
			"\x01",
			'OKAY EYEWEAR',
			preg_replace_callback(
				'~\p{L}+~u',
				function ( $m ) use ( $alpha ) {
					$w = $m[0];
					$c = preg_split( '//u', $w, -1, PREG_SPLIT_NO_EMPTY );
					if ( count( $c ) < 3 || preg_match( '~^\p{Lu}+$~u', $w ) ) {
						return $w;
					}
					if ( $alpha ) {
						return implode( '', array_map( function ( $x ) use ( $alpha ) { return $alpha[ mb_ord( $x ) % count( $alpha ) ]; }, $c ) );
					}
					$r = implode( '', array_reverse( $c ) );
					return $r === $w ? $w . 'x' : $r;
				},
				$p
			)
		);
	}
	return implode( '', $parts );
}

function rh_pk( $t ) {
	global $wpdb;
	$k = $wpdb->get_row( "SHOW KEYS FROM `$t` WHERE Key_name = 'PRIMARY'", ARRAY_A );
	return $k ? $k['Column_name'] : '';
}

function rh_tables() {
	global $wpdb;
	return array_values( array_filter( $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}trp\\_%'" ), function ( $t ) { return strpos( $t, "machine_translation_locks" ) === false; } ) );
}

if ( $phase === 'content' ) {
	$topics = array( 'Kids sunglasses', 'Optical frames', 'TR90 material', 'Silicone nose pads', 'Polarized lenses', 'Packaging', 'OEM service', 'Quality control' );
	$pages  = array(
		'home'       => array( 'okay-kids-eyewear-factory', 'OKAY EYEWEAR – Kids Eyewear Factory', 1000 ),
		'collection' => array( 'kids-tr90-collection', 'TR90 Kids Collection', 2000 ),
		'product'    => array( 'ok-a1001-キッズ-サングラス', 'OK-A1001 Kids Sunglasses', 3000 ),
		'article'    => array( 'uv-protection-guide-for-kids', 'UV Protection Guide for Kids', 4000 ),
	);
	$out = array();
	foreach ( $pages as $key => $p ) {
		$base = $p[2];
		$html = '';
		for ( $i = 1; $i <= 150; $i++ ) {
			$n = $base + $i;
			switch ( $i % 10 ) {
				case 0:
					$html .= '<h2>' . $topics[ $i % 8 ] . ' ' . $n . '</h2>' . "\n";
					break;
				case 1:
					$html .= '<p>Model OK-A' . $n . ' kids sunglasses come with ' . ( 44 + $i % 8 ) . '&nbsp;mm lenses and UV400 protection.</p>' . "\n";
					break;
				case 2:
					$html .= '<p><strong>OKAY EYEWEAR</strong> R&amp;D team packs ' . ( 100 + $i * 7 ) . ' frames per carton for order ' . $n . '.</p>' . "\n";
					break;
				case 3:
					$html .= '<p>Our TR90 frames weigh only ' . ( 12 + $i % 9 ) . ' g, about ' . ( $i % 3 + 2 ) . ' times lighter than metal frame ' . $n . '.</p>' . "\n";
					break;
				case 4:
					$html .= '<p>See the <a href="/kids-tr90-collection/">TR90 collection</a> for ' . ( $i + 3 ) . ' more styles like style ' . $n . '.</p>' . "\n";
					break;
				case 5:
					$html .= '<p>Polarized lenses block ' . ( 90 + $i % 10 ) . '% of glare; lens batch ' . $n . ' passed the test.</p>' . "\n";
					break;
				case 6:
					$html .= '<p>The MOQ is ' . ( 300 + $i * 10 ) . ' pieces and samples ship in ' . ( 3 + $i % 5 ) . ' days (item ' . $n . ').</p>' . "\n";
					break;
				case 7:
					$html .= '<h3>Can children wear frame ' . $n . ' all day?</h3>' . "\n";
					break;
				case 8:
					$html .= '<p>Write to sales@example.com and quote reference ' . $n . ' for a price list.</p>' . "\n";
					break;
				default:
					$html .= '<p>Soft silicone temple tips protect children\'s ears; size ' . ( $i % 4 + 1 ) . ' fits ages ' . ( 3 + $i % 6 ) . ' to ' . ( 6 + $i % 6 ) . ' (design ' . $n . ').</p>' . "\n";
			}
		}
		$existing = get_page_by_path( $p[0] );
		$id       = wp_insert_post( array( 'ID' => $existing ? $existing->ID : 0, 'post_type' => 'page', 'post_status' => 'publish', 'post_name' => $p[0], 'post_title' => $p[1], 'post_content' => $html ) );
		$out[ $key ] = array( 'id' => $id, 'url' => get_permalink( $id ) );
	}
	update_option( 'show_on_front', 'page' );
	update_option( 'page_on_front', $out['home']['id'] );
	$out['home']['url'] = home_url( '/' );
	$footer = array();
	for ( $i = 1; $i <= 20; $i++ ) {
		$footer[] = array( 'Footer line ' . $i . ': OKAY EYEWEAR\'s kids eyewear, made in Xiamen since ' . ( 2000 + $i ) . '.', '' );
	}
	update_option( 'aitp_e2e_footer', $footer );
	$have = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_name LIKE 'kids-eyewear-note-%'" );
	for ( $n = $have + 1; $n <= 900; $n++ ) {
		wp_insert_post(
			array(
				'post_type'    => 'post',
				'post_status'  => 'publish',
				'post_name'    => 'kids-eyewear-note-' . $n,
				'post_title'   => 'Kids eyewear note ' . $n,
				'post_content' => '<p>Note ' . $n . ': frame ' . $n . ' weighs ' . ( 10 + $n % 9 ) . ' g.</p>' . "\n" . '<p>Note ' . $n . ': lenses block ' . ( 90 + $n % 10 ) . '% of glare.</p>' . "\n" . '<p>Note ' . $n . ': ' . ( 100 + $n ) . ' pieces per carton.</p>',
			)
		);
	}
	flush_rewrite_rules();
	echo wp_json_encode( $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . "\n";
	return;
}

if ( $phase === 'bulk' ) {
	$os   = $wpdb->prefix . 'trp_original_strings';
	$om   = $wpdb->prefix . 'trp_original_meta';
	$dict = function ( $l ) use ( $wpdb ) { return $wpdb->prefix . 'trp_dictionary_en_us_' . strtolower( $l ); };
	$out  = array();

	// 1. Content of the 900 posts: rows as TranslatePress would have stored them, linked to the post.
	$posts = $wpdb->get_results( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_name LIKE 'kids-eyewear-note-%' ORDER BY ID", ARRAY_A );
	foreach ( $posts as $p ) {
		preg_match_all( '~<p>(.*?)</p>~', $p['post_content'], $m );
		foreach ( $m[1] as $text ) {
			$oid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $os WHERE original = %s", $text ) );
			if ( ! $oid ) {
				$wpdb->insert( $os, array( 'original' => $text ) );
				$oid = (int) $wpdb->insert_id;
				$wpdb->insert( $om, array( 'original_id' => $oid, 'meta_key' => 'post_parent_id', 'meta_value' => (string) $p['ID'] ) );
				foreach ( $langs as $l ) {
					$wpdb->insert( $dict( $l ), array( 'original' => $text, 'translated' => rh_pseudo( $text, $l ), 'status' => 1, 'block_type' => 0, 'original_id' => $oid ) );
				}
			}
		}
	}
	$out['post_rows_per_lang'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . $dict( 'fr_FR' ) . " WHERE original LIKE 'Note %'" );

	// 2. Footer strings shown on every page: linked to all 900 posts (as TranslatePress links strings seen on a post's page).
	$fids = $wpdb->get_col( "SELECT id FROM $os WHERE original LIKE 'Footer line %'" );
	$have = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $om WHERE meta_key = 'post_parent_id' AND original_id IN (" . implode( ',', array_map( 'intval', $fids ?: array( 0 ) ) ) . ')' );
	if ( $fids && $have < 900 ) {
		foreach ( $fids as $fid ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO $om (original_id, meta_key, meta_value) SELECT %d, 'post_parent_id', ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' AND post_name LIKE 'kids-eyewear-note-%%'", (int) $fid ) );
		}
	}
	$out['footer_strings'] = count( $fids );
	$out['footer_links']   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $om WHERE meta_key = 'post_parent_id' AND original_id IN (" . implode( ',', array_map( 'intval', $fids ?: array( 0 ) ) ) . ')' );

	// 3. 90,000 more rows per language (the test site has ~533,000), originals included.
	if ( ! $wpdb->get_var( "SELECT COUNT(*) FROM $os WHERE original LIKE 'Catalogue line %'" ) ) {
		$wpdb->query( "INSERT INTO $os (original) SELECT CONCAT('Catalogue line ', seq, ': kids frame in size ', seq % 7 + 1, '.') FROM seq_1_to_90000" );
		foreach ( $langs as $l ) {
			$tr = array(
				'fr_FR' => "CONCAT('Eugolatac enil ', seq, ': sdik emarf ni ezis ', seq % 7 + 1, '.')",
				'es_MX' => "CONCAT('Línea de catálogo ', seq, ': montura infantil talla ', seq % 7 + 1, '.')",
				'ja'    => "CONCAT('カタログ行 ', seq, '：子供用フレーム サイズ ', seq % 7 + 1, '。')",
				'ar'    => "CONCAT('سطر الكتالوج ', seq, ': إطار أطفال مقاس ', seq % 7 + 1, '.')",
				'de_DE' => "CONCAT('Katalogzeile ', seq, ': Kinderfassung in Größe ', seq % 7 + 1, '.')",
				'pt_BR' => "CONCAT('Linha do catálogo ', seq, ': armação infantil tamanho ', seq % 7 + 1, '.')",
			);
			$wpdb->query( "INSERT INTO " . $dict( $l ) . " (original, translated, status, block_type, original_id) SELECT o.original, " . str_replace( 'seq', 's.seq', $tr[ $l ] ) . ", IF(s.seq % 97 = 0, 2, 1), 0, o.id FROM seq_1_to_90000 s JOIN $os o ON o.original = CONCAT('Catalogue line ', s.seq, ': kids frame in size ', s.seq % 7 + 1, '.')" );
		}
	}

	// 4. Errors like HHG's: wrong numbers, English stored, a neighbour's translation. On post rows
	//    (shown on known pages) and catalogue rows (on no page).
	$err = array();
	foreach ( $langs as $k => $l ) {
		$t = $dict( $l );
		$err[ $l ]  = $wpdb->query( "UPDATE $t SET translated = REPLACE(translated, ' g.', '0 g.') WHERE status = 1 AND original LIKE 'Note %: frame % weighs %' AND MOD(original_id, 9) = " . ( $k + 1 ) );
		$err[ $l ] += $wpdb->query( "UPDATE $t SET translated = original WHERE status = 1 AND original LIKE 'Note %: lenses block%' AND MOD(original_id, 23) = " . ( $k + 1 ) );
		$err[ $l ] += $wpdb->query( "UPDATE $t SET translated = CONCAT(translated, ' 2') WHERE status = 1 AND original LIKE 'Catalogue line %' AND MOD(original_id, 600) = " . ( $k + 1 ) );
	}
	$out['errors'] = $err;

	// 5. Manual translations (status 2) on the 4 pages (~3 %), in the posts' rows (~1 %).
	$man = array();
	foreach ( $langs as $k => $l ) {
		$t        = $dict( $l );
		$man[ $l ] = $wpdb->query( "UPDATE $t SET status = 2, translated = CONCAT('✍ ', translated) WHERE status = 1 AND original_id IS NOT NULL AND MOD(id, 33) = " . ( $k + 2 ) . " AND original NOT LIKE 'Catalogue line %' AND original NOT LIKE 'Note %'" );
		$man[ $l ] += $wpdb->query( "UPDATE $t SET status = 2 WHERE status = 1 AND original LIKE 'Note %' AND MOD(id, 101) = " . ( $k + 2 ) );
	}
	$out['manual'] = $man;
	foreach ( $langs as $l ) {
		$out['rows'][ $l ] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $dict( $l ) );
	}
	echo wp_json_encode( $out ) . "\n";
	return;
}

if ( $phase === 'snapshot' ) {
	$o = array();
	foreach ( rh_tables() as $t ) {
		$c = 'zz_rh_' . $t;
		$wpdb->query( "DROP TABLE IF EXISTS `$c`" );
		$wpdb->query( "CREATE TABLE `$c` LIKE `$t`" );
		$wpdb->query( "INSERT INTO `$c` SELECT * FROM `$t`" );
		$pk      = rh_pk( $t );
		$o[ $t ] = array( 'rows' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$t`" ), 'max' => $pk ? (int) $wpdb->get_var( "SELECT MAX(`$pk`) FROM `$t`" ) : null );
	}
	$wpdb->query( 'DROP TABLE IF EXISTS zz_rh_options' );
	$wpdb->query( "CREATE TABLE zz_rh_options AS SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name IN ('trp_settings', 'trp_machine_translation_settings', 'active_plugins')" );
	update_option( 'zz_rh_snapshot', $o, false );
	echo wp_json_encode( $o ) . "\n";
	return;
}

if ( $phase === 'compare' ) {
	$snap = get_option( 'zz_rh_snapshot', array() );
	$o    = array();
	foreach ( $snap as $t => $s ) {
		$c  = 'zz_rh_' . $t;
		$pk = rh_pk( $t );
		if ( ! $pk ) {
			$now = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$t`" );
			if ( $now !== (int) $s['rows'] ) {
				$o['tables'][ $t ] = array( 'rows_before' => (int) $s['rows'], 'rows_now' => $now );
			}
			continue;
		}
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM `$t`" );
		$same = implode( ' AND ', array_map( function ( $x ) { return "BINARY a.`$x` <=> BINARY b.`$x`"; }, $cols ) );
		$r = array(
			'changed' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$c` a JOIN `$t` b ON a.$pk = b.$pk WHERE NOT ($same)" ),
			'missing' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$c` a LEFT JOIN `$t` b ON a.$pk = b.$pk WHERE b.$pk IS NULL" ),
			'new'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `$t` b WHERE b.$pk > " . (int) $s['max'] ),
		);
		if ( $r['changed'] ) {
			$r['examples'] = $wpdb->get_results( "SELECT a.$pk AS id, " . ( in_array( 'translated', $cols, true ) ? 'a.original, a.translated AS before_t, b.translated AS after_t, a.status AS before_s, b.status AS after_s' : "'' AS x" ) . " FROM `$c` a JOIN `$t` b ON a.$pk = b.$pk WHERE NOT ($same) LIMIT 5", ARRAY_A );
		}
		if ( $r['changed'] || $r['missing'] || $r['new'] ) {
			$o['tables'][ $t ] = $r;
		}
	}
	foreach ( $wpdb->get_results( 'SELECT option_name, option_value FROM zz_rh_options', ARRAY_A ) as $row ) {
		$now = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $row['option_name'] ) );
		$b = maybe_unserialize( $row['option_value'] );
		$n = maybe_unserialize( $now );
		if ( $now === $row['option_value'] ) {
			$o['options'][ $row['option_name'] ] = 'same';
		} elseif ( $row['option_name'] === 'active_plugins' ) {
			// Order is not meaningful: compare as sets.
			$x = array_values( (array) $b );
			$y = array_values( (array) $n );
			sort( $x );
			sort( $y );
			$o['options'][ $row['option_name'] ] = $x === $y ? 'same' : array( 'before' => $x, 'after' => $y );
		} elseif ( is_array( $b ) && is_array( $n ) ) {
			// Per key; TranslatePress rewrites its daily counter keys by itself.
			$d = array();
			foreach ( array_unique( array_merge( array_keys( $b ), array_keys( $n ) ) ) as $k ) {
				if ( in_array( $k, array( 'machine_translation_counter_date', 'machine_translation_counter', 'trigger_quota_notification' ), true ) ) {
					continue;
				}
				$bv = array_key_exists( $k, $b ) ? $b[ $k ] : '(missing)';
				$nv = array_key_exists( $k, $n ) ? $n[ $k ] : '(missing)';
				if ( $bv !== $nv ) {
					$d[ $k ] = array( 'before' => $bv, 'after' => $nv );
				}
			}
			$o['options'][ $row['option_name'] ] = $d ? $d : 'same';
		} else {
			$o['options'][ $row['option_name'] ] = array( 'before' => $b, 'after' => $n );
		}
	}
	echo wp_json_encode( $o, JSON_UNESCAPED_UNICODE ) . "\n";
	return;
}

if ( $phase === 'drop' ) {
	foreach ( $wpdb->get_col( "SHOW TABLES LIKE 'zz\\_rh\\_%'" ) as $t ) {
		$wpdb->query( "DROP TABLE `$t`" );
	}
	delete_option( 'zz_rh_snapshot' );
	echo "dropped\n";
	return;
}
echo "phase?\n";
