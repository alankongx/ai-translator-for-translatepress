<?php
/**
 * Translator flows against a fake Gemini transport.
 */
function pseudo_responder( $mutate = null ) {
	return function ( $r, $n ) use ( $mutate ) {
		list( $items, $notes, $payload ) = req_items( $r );
		$out = array();
		foreach ( $items as $id => $t ) {
			$out[ $id ] = pseudo( $t );
		}
		if ( $mutate ) {
			$res = $mutate( $out, $items, $n, $notes, $payload );
			if ( isset( $res['status'] ) ) {
				return $res;
			}
			$out = $res;
		}
		return gemini_ok( $out );
	};
}

function later() {
	return microtime( true ) + 300;
}

test( 'translator: keys preserved, reordered response mapped by id', function () {
	$tr    = new AITP_Test_Transport( pseudo_responder( function ( $out ) {
		return array_reverse( $out, true );
	} ) );
	$items = array( 17 => 'Sunglasses for every face shape', 3 => 'Contact our sales team', 'x9' => 'Request a sample today' );
	$r     = make_translator( $tr )->translate( $items, 'en_US', 'es_MX', later() );
	eq( array( 17, 3, 'x9' ), array_keys( $r['translations'] ) );
	foreach ( $items as $k => $v ) {
		eq( pseudo( $v ), $r['translations'][ $k ] );
	}
	eq( 1, count( $tr->calls ) );
	eq( array(), $r['failed'] );
} );

test( 'translator: missing id -> whole answer distrusted, nothing from it saved', function () {
	$tr    = new AITP_Test_Transport( pseudo_responder( function ( $out, $items, $n ) {
		if ( $n === 1 ) {
			unset( $out['t2'] );
			$out['t1'] = 'WRONG ' . $out['t1']; // Would be caught only because the answer is distrusted.
		}
		return $out;
	} ) );
	$items = array( 'a' => 'Alpha frames for men', 'b' => 'Bravo frames for women', 'c' => 'Charlie frames for kids' );
	$r     = make_translator( $tr )->translate( $items, 'en_US', 'fr_FR', later() );
	eq( 2, count( $tr->calls ) );
	list( $second ) = req_items( $tr->calls[1] );
	eq( 3, count( $second ), 'all entries asked again' );
	eq( 3, count( $r['translations'] ) );
	foreach ( $items as $k => $v ) {
		eq( pseudo( $v ), $r['translations'][ $k ] );
	}
} );

test( 'translator: same text for two different sources -> answer distrusted', function () {
	$tr    = new AITP_Test_Transport( pseudo_responder( function ( $out, $items, $n ) {
		if ( $n === 1 ) {
			$out['t1'] = $out['t2']; // Shifted up with the last entry repeated: no id is missing.
			$out['t2'] = $out['t3'];
		}
		return $out;
	} ) );
	$items = array( 'a' => 'Home', 'b' => 'Shop', 'c' => 'Contact' );
	$r     = make_translator( $tr )->translate( $items, 'en_US', 'fr_FR', later() );
	eq( 2, count( $tr->calls ) );
	foreach ( $items as $k => $v ) {
		eq( pseudo( $v ), $r['translations'][ $k ] );
	}
} );

test( 'translator: one bad entry in a good answer -> only that entry retried', function () {
	$tr    = new AITP_Test_Transport( pseudo_responder( function ( $out, $items, $n ) {
		if ( $n === 1 ) {
			$out['t3'] = str_replace( '20', '30', $out['t3'] );
		}
		return $out;
	} ) );
	$items = array( 'Alpha frames for men', 'Bravo frames for women', 'Over 20 colours in stock', 'Delta lenses', 'Echo cases', 'Foxtrot cords' );
	$r     = make_translator( $tr )->translate( $items, 'en_US', 'fr_FR', later() );
	eq( 2, count( $tr->calls ) );
	list( $second ) = req_items( $tr->calls[1] );
	eq( array( 't1' => 'Over 20 colours in stock' ), $second );
	eq( 6, count( $r['translations'] ) );
} );

test( 'translator: model keeps shifting -> nothing saved, breaker opens, retried later', function () {
	$st    = new AITP_Memory_State();
	$f     = new AITP_Memory_Failures( 1000 );
	$tr    = new AITP_Test_Transport( pseudo_responder( function ( $out ) {
		$ids = array_keys( $out );
		$new = array();
		for ( $i = 0; $i + 1 < count( $ids ); $i++ ) {
			$new[ $ids[ $i ] ] = $out[ $ids[ $i + 1 ] ];
		}
		return $new;
	} ) );
	$items = array();
	foreach ( array( 'Home', 'Shop', 'About us', 'Contact', 'Blog', 'Sunglasses', 'Kids', 'Optical frames' ) as $i => $t ) {
		$items[ 'k' . $i ] = $t;
	}
	$t = make_translator( $tr, array( 'batch_items' => 4 ), array( 'breaker' => new AITP_Circuit_Breaker( $st ), 'failures' => $f ) );
	$r = $t->translate( $items, 'en_US', 'fr_FR', later() );
	eq( array(), $r['translations'] );
	eq( 2, count( $tr->calls ), 'stops after the first round' );
	ok( count( $f->rows ) > 0, 'items backed off for a later page view' );
	$t->translate( array( 'x' => 'Cases', 'y' => 'Lenses' ), 'en_US', 'fr_FR', later() );
	eq( 'open', $st->state['status'], 'breaker opens after repeated misaligned answers' );
	eq( 'bad_output', $st->state['reason'] );
} );

test( 'translator: duplicate id rejects batch, retried', function () {
	$tr = new AITP_Test_Transport( function ( $r, $n ) {
		list( $items ) = req_items( $r );
		if ( $n === 1 ) {
			return gemini_text( json_encode( array( 'translations' => array( array( 'id' => 't1', 'text' => 'x' ), array( 'id' => 't1', 'text' => 'y' ) ) ) ) );
		}
		$o = array();
		foreach ( $items as $id => $t ) {
			$o[ $id ] = pseudo( $t );
		}
		return gemini_ok( $o );
	} );
	$r  = make_translator( $tr )->translate( array( 'Alpha frames for men', 'Bravo frames for women' ), 'en_US', 'fr_FR', later() );
	eq( 2, count( $tr->calls ) );
	eq( 2, count( $r['translations'] ) );
} );

test( 'translator: ids mixed up by model -> whole answer rejected, all retried', function () {
	$items = array(
		'a' => 'Serving Brands Across 20+ Countries',
		'b' => 'Meet our founder',
		'c' => 'Your Brand, Built on Our Manufacturing Foundation',
		'd' => 'Indonesia',
	);
	$tr    = new AITP_Test_Transport( pseudo_responder( function ( $out, $items, $n ) {
		if ( $n === 1 ) {
			return array( 't1' => $out['t2'], 't2' => $out['t3'], 't3' => $out['t4'] ); // Shifted, t4 missing.
		}
		return $out;
	} ) );
	$r     = make_translator( $tr )->translate( $items, 'en_US', 'fr_FR', later() );
	foreach ( $items as $k => $v ) {
		eq( pseudo( $v ), $r['translations'][ $k ], $k . ' correct' );
	}
} );

test( 'translator: English headline left as is never saved, even unconfirmed', function () {
	$tr = new AITP_Test_Transport( function ( $r ) {
		list( $items ) = req_items( $r );
		return gemini_ok( $items );
	} );
	$r  = make_translator( $tr )->translate( array( 'q' => 'How Do UV-Protective Lenses in Kids’ Sunglasses Work?', 'h' => 'Your Brand, Built With Our Craft' ), 'en_US', 'fr_FR', later() );
	eq( array(), $r['translations'] );
} );

test( 'translator: identical candidate is not the fallback when confirmation never ran', function () {
	$clock = 0;
	$tr    = new AITP_Test_Transport( function ( $r ) {
		list( $items ) = req_items( $r );
		return gemini_ok( $items );
	} );
	$r     = make_translator( $tr, array( 'max_rounds' => 1 ) )->translate( array( 'k' => 'Taizhou' ), 'en_US', 'fr_FR', later() );
	eq( array(), $r['translations'] );
} );

test( 'translator: truncated output never saved', function () {
	$tr = new AITP_Test_Transport( function ( $r ) {
		return gemini_text( '{"translations":[{"id":"t1","text":"Montu', 'MAX_TOKENS' );
	} );
	$f  = new AITP_Memory_Failures( 1000 );
	$r  = make_translator( $tr, array(), array( 'failures' => $f ) )->translate( array( 'k' => 'Alpha frames for men' ), 'en_US', 'fr_FR', later() );
	eq( array(), $r['translations'] );
	eq( 'truncated', $r['failed']['k'] );
	eq( 3, count( $tr->calls ) );
	eq( 1, count( $f->rows ) );
} );

test( 'translator: truncated once then complete', function () {
	$tr = new AITP_Test_Transport( pseudo_responder( function ( $out, $items, $n ) {
		return $n === 1 ? gemini_text( '{"translations":[', 'MAX_TOKENS' ) : $out;
	} ) );
	$r  = make_translator( $tr )->translate( array( 'Alpha frames for men', 'Bravo frames for women' ), 'en_US', 'fr_FR', later() );
	eq( 2, count( $r['translations'] ) );
} );

test( 'translator: 429 stops, releases quota, opens breaker, no item backoff', function () {
	$tr = new AITP_Test_Transport( function () {
		return http( 429, array( 'error' => array( 'code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded per minute' ) ), array( 'retry-after' => '30' ) );
	} );
	$q  = new AITP_Memory_Quota( 100000 );
	$st = new AITP_Memory_State();
	$f  = new AITP_Memory_Failures( 1000 );
	$t  = make_translator( $tr, array(), array( 'quota' => $q, 'breaker' => new AITP_Circuit_Breaker( $st ), 'failures' => $f ) );
	$r  = $t->translate( array( 'Alpha frames for men', 'Bravo frames for women' ), 'en_US', 'fr_FR', later() );
	eq( 1, count( $tr->calls ) );
	eq( array( 'rate_limit', 'rate_limit' ), array_values( $r['failed'] ) );
	eq( 0, $q->used );
	eq( 0, $q->reserved );
	eq( 'open', $st->state['status'] );
	eq( array(), $f->rows );
	$r2 = $t->translate( array( 'Charlie frames' ), 'en_US', 'fr_FR', later() );
	eq( 1, count( $tr->calls ), 'breaker blocks further requests' );
	eq( 'breaker', $r2['failed'][0] );
} );

test( 'translator: invalid key pauses engine, key never logged', function () {
	$key = 'AIzaSECRETKEY1234567890';
	$tr  = new AITP_Test_Transport( function () use ( $key ) {
		return http( 400, array( 'error' => array( 'code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'API key not valid: ' . $key ) ) );
	} );
	$st  = new AITP_Memory_State();
	$log = new AITP_Memory_Log();
	$p   = new AITP_Provider_Gemini( array( 'api_key' => $key, 'model' => 'gemini-3.5-flash' ) );
	$t   = make_translator( $tr, array(), array( 'breaker' => new AITP_Circuit_Breaker( $st ), 'log' => $log ), array(), $p );
	$r   = $t->translate( array( 'Alpha frames for men' ), 'en_US', 'fr_FR', later() );
	eq( 'auth', $r['failed'][0] );
	eq( 'paused', $st->state['status'] );
	ok( strpos( json_encode( $log->rows ) . json_encode( $st->state ), 'SECRETKEY' ) === false, 'key leaked' );
	$t->translate( array( 'Bravo' ), 'en_US', 'fr_FR', later() );
	eq( 1, count( $tr->calls ) );
} );

test( 'translator: timeouts commit quota (may be billed), item not held', function () {
	$tr = new AITP_Test_Transport( function () {
		return array( 'status' => 0, 'headers' => array(), 'body' => '', 'error' => 'cURL error 28: Operation timed out', 'error_type' => 'timeout', 'duration_ms' => 30000 );
	} );
	$q  = new AITP_Memory_Quota();
	$f  = new AITP_Memory_Failures( 1000 );
	$r  = make_translator( $tr, array(), array( 'quota' => $q, 'failures' => $f ) )->translate( array( 'Alpha frames for men' ), 'en_US', 'fr_FR', later() );
	eq( 'timeout', $r['failed'][0] );
	eq( 3, count( $tr->calls ) );
	eq( 3 * strlen( 'Alpha frames for men' ), $q->used );
	eq( array(), $f->rows );
} );

test( 'translator: 500 then success; failed attempt not charged', function () {
	$tr = new AITP_Test_Transport( pseudo_responder( function ( $out, $items, $n ) {
		return $n === 1 ? http( 500, '{"error":{"message":"internal"}}' ) : $out;
	} ) );
	$q  = new AITP_Memory_Quota();
	$r  = make_translator( $tr, array(), array( 'quota' => $q ) )->translate( array( 'Alpha frames for men' ), 'en_US', 'fr_FR', later() );
	eq( 1, count( $r['translations'] ) );
	eq( strlen( 'Alpha frames for men' ), $q->used );
} );

test( 'translator: deadline reached -> nothing sent, deferred', function () {
	$tr = new AITP_Test_Transport( pseudo_responder() );
	$r  = make_translator( $tr )->translate( array( 'Alpha frames for men' ), 'en_US', 'fr_FR', microtime( true ) + 2 );
	eq( 0, count( $tr->calls ) );
	eq( 'deferred', $r['failed'][0] );
} );

test( 'translator: quota boundary sends only what fits', function () {
	$tr = new AITP_Test_Transport( pseudo_responder() );
	$q  = new AITP_Memory_Quota( 30 );
	$r  = make_translator( $tr, array( 'batch_items' => 1 ), array( 'quota' => $q ) )->translate( array( 'Alpha item text', 'Bravo item text', 'Delta item text' ), 'en_US', 'fr_FR', later() );
	eq( 2, count( $tr->calls ) );
	eq( 2, count( $r['translations'] ) );
	eq( 'quota', $r['failed'][2] );
	eq( 30, $q->used );
	eq( 0, $q->reserved );
} );

test( 'translator: cache hit sends nothing; poisoned cache rejected', function () {
	$tr    = new AITP_Test_Transport( pseudo_responder() );
	$cache = new AITP_Memory_Cache();
	$t     = make_translator( $tr, array(), array( 'cache' => $cache ) );
	$items = array( 'Contact our sales team today', 'Request a sample with your logo' );
	$t->translate( $items, 'en_US', 'fr_FR', later() );
	eq( 1, count( $tr->calls ) );
	$r = $t->translate( $items, 'en_US', 'fr_FR', later() );
	eq( 1, count( $tr->calls ), 'served from cache' );
	eq( 2, $r['stats']['cached'] );
	foreach ( $cache->rows as $k => $v ) {
		$cache->rows[ $k ] = 'Contact our sales team today'; // English stored as translation.
	}
	$r = $t->translate( $items, 'en_US', 'fr_FR', later() );
	eq( 2, count( $tr->calls ), 'poisoned entries re-requested' );
	eq( pseudo( $items[0] ), $r['translations'][0] );
} );

test( 'translator: repeated failure backs off across page views', function () {
	$tr = new AITP_Test_Transport( function ( $r ) {
		list( $items ) = req_items( $r );
		return gemini_ok( $items ); // Returns English unchanged.
	} );
	$f  = new AITP_Memory_Failures( 1000 );
	$t  = make_translator( $tr, array(), array( 'failures' => $f ) );
	$s  = array( 'Contact our sales team today' );
	eq( 'untranslated', $t->translate( $s, 'en_US', 'fr_FR', later() )['failed'][0] );
	eq( 3, count( $tr->calls ) );
	eq( 'untranslated', $t->translate( $s, 'en_US', 'fr_FR', later() )['failed'][0] );
	eq( 6, count( $tr->calls ) );
	eq( 'held', $t->translate( $s, 'en_US', 'fr_FR', later() )['failed'][0] );
	eq( 6, count( $tr->calls ), 'held: no request' );
} );

test( 'translator: long text split, translated in parts, rejoined', function () {
	$sent = array();
	for ( $i = 1; $i <= 12; $i++ ) {
		$sent[] = "Sentence number $i describes our sunglasses and optical frames.";
	}
	$text = implode( ' ', $sent );
	$tr   = new AITP_Test_Transport( pseudo_responder() );
	$r    = make_translator( $tr, array( 'split_bytes' => 300 ) )->translate( array( 'long' => $text ), 'en_US', 'fr_FR', later() );
	list( $items ) = req_items( $tr->calls[0] );
	ok( count( $items ) >= 3, 'sent as parts' );
	eq( pseudo( $text ), $r['translations']['long'] );
} );

test( 'translator: one failed part -> whole item not saved', function () {
	$sent = array();
	for ( $i = 1; $i <= 12; $i++ ) {
		$sent[] = "Sentence number $i describes our sunglasses and optical frames.";
	}
	$tr = new AITP_Test_Transport( pseudo_responder( function ( $out, $items ) {
		foreach ( $items as $id => $t ) {
			if ( strpos( $t, 'number 7 ' ) !== false ) {
				$out[ $id ] = $t;
			}
		}
		return $out;
	} ) );
	$r  = make_translator( $tr, array( 'split_bytes' => 300 ) )->translate( array( 'long' => implode( ' ', $sent ) ), 'en_US', 'fr_FR', later() );
	eq( array(), $r['translations'] );
	eq( 'untranslated', $r['failed']['long'] );
} );

test( 'translator: added claim retried once, then saved and listed for review', function () {
	$review = new AITP_Memory_Review();
	$tr     = new AITP_Test_Transport( function ( $r ) {
		return gemini_ok( array( 't1' => 'Montures TR90 renforcées pour un usage quotidien' ) );
	} );
	$r      = make_translator( $tr, array(), array( 'review' => $review ) )->translate( array( 'k' => 'Flexible TR90 frames for daily wear' ), 'en_US', 'fr_FR', later() );
	eq( 2, count( $tr->calls ) );
	list( , $notes ) = req_items( $tr->calls[1] );
	ok( isset( $notes['t1'] ), 'retry carries a note' );
	eq( 'Montures TR90 renforcées pour un usage quotidien', $r['translations']['k'] );
	eq( array( 'added_claim:reinforced' ), $r['flagged']['k'] );
	eq( 1, count( $review->rows ) );
	eq( true, $review->rows[0]['saved'] );
} );

test( 'translator: strict policy does not save flagged translation', function () {
	$review = new AITP_Memory_Review();
	$tr     = new AITP_Test_Transport( function () {
		return gemini_ok( array( 't1' => 'Montures TR90 renforcées pour un usage quotidien' ) );
	} );
	$r      = make_translator( $tr, array( 'soft_policy' => 'strict' ), array( 'review' => $review ) )->translate( array( 'k' => 'Flexible TR90 frames for daily wear' ), 'en_US', 'fr_FR', later() );
	eq( array(), $r['translations'] );
	eq( 'review_added_claim:reinforced', $r['failed']['k'] );
	eq( false, $review->rows[0]['saved'] );
} );

test( 'translator: corrected after soft retry -> no flag', function () {
	$review = new AITP_Memory_Review();
	$tr     = new AITP_Test_Transport( function ( $r, $n ) {
		return gemini_ok( array( 't1' => $n === 1 ? 'Montures TR90 renforcées pour un usage quotidien' : 'Montures TR90 flexibles pour un usage quotidien' ) );
	} );
	$r      = make_translator( $tr, array(), array( 'review' => $review ) )->translate( array( 'k' => 'Flexible TR90 frames for daily wear' ), 'en_US', 'fr_FR', later() );
	eq( 'Montures TR90 flexibles pour un usage quotidien', $r['translations']['k'] );
	eq( array(), $r['flagged'] );
	eq( array(), $review->rows );
} );

test( 'translator: proper name kept after one confirmation', function () {
	$tr = new AITP_Test_Transport( function ( $r ) {
		list( $items ) = req_items( $r );
		return gemini_ok( $items );
	} );
	$r  = make_translator( $tr )->translate( array( 'k' => 'Taizhou' ), 'en_US', 'fr_FR', later() );
	eq( 2, count( $tr->calls ) );
	eq( 'Taizhou', $r['translations']['k'] );
	eq( array(), $r['flagged'] );
} );

test( 'translator: unsupported schema downgraded and remembered', function () {
	$caps = new AITP_Memory_Caps();
	$q    = new AITP_Memory_Quota();
	$tr   = new AITP_Test_Transport( pseudo_responder( function ( $out, $items, $n ) {
		return $n === 1 ? http( 400, array( 'error' => array( 'code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Invalid JSON payload received. Unknown name "responseSchema"' ) ) ) : $out;
	} ) );
	$r    = make_translator( $tr, array(), array( 'caps' => $caps, 'quota' => $q ) )->translate( array( 'Alpha frames for men' ), 'en_US', 'fr_FR', later() );
	eq( 1, count( $r['translations'] ) );
	$b = json_decode( $tr->calls[1]['body'], true );
	ok( ! isset( $b['generationConfig']['responseSchema'] ) );
	eq( 'application/json', $b['generationConfig']['responseMimeType'] );
	$stored = array_values( $caps->rows )[0];
	eq( false, $stored['schema'] );
	eq( strlen( 'Alpha frames for men' ), $q->used );
	eq( 1, $r['stats']['rounds'] );
} );

test( 'translator: invalid JSON and safety block handled', function () {
	$tr = new AITP_Test_Transport( pseudo_responder( function ( $out, $items, $n ) {
		return $n === 1 ? gemini_text( 'Voici les traductions demandées' ) : $out;
	} ) );
	eq( 1, count( make_translator( $tr )->translate( array( 'Alpha frames for men' ), 'en_US', 'fr_FR', later() )['translations'] ) );
	$tr = new AITP_Test_Transport( function () {
		return gemini_text( '', 'SAFETY' );
	} );
	eq( 'blocked', make_translator( $tr )->translate( array( 'Alpha frames for men' ), 'en_US', 'fr_FR', later() )['failed'][0] );
} );

test( 'translator: empty source skipped, html kept', function () {
	$tr = new AITP_Test_Transport( pseudo_responder() );
	$r  = make_translator( $tr )->translate( array( 'e' => '   ', 'h' => 'Read <a href="/faq">our FAQ page</a> before ordering' ), 'en_US', 'fr_FR', later() );
	eq( 'empty_source', $r['failed']['e'] );
	eq( pseudo( 'Read <a href="/faq">our FAQ page</a> before ordering' ), $r['translations']['h'] );
} );

test( 'translator: neighbours sent as context, not as items', function () {
	$tr = new AITP_Test_Transport( pseudo_responder() );
	make_translator( $tr, array( 'batch_items' => 1 ) )->translate( array( 'First heading text', 'Second body text here', 'Third footer text' ), 'en_US', 'fr_FR', later() );
	list( $items, , $payload ) = req_items( $tr->calls[1] );
	eq( array( 't1' => 'Second body text here' ), $items );
	eq( array( 'First heading text' ), $payload['context_before'] );
	eq( array( 'Third footer text' ), $payload['context_after'] );
} );

test( 'translator: protected terms and glossary sent only when relevant', function () {
	$tr = new AITP_Test_Transport( pseudo_responder() );
	make_translator( $tr, array(), array(), array( 'terms' => array( 'OKAY EYEWEAR', 'Acme' ), 'glossary' => "tinted lenses => fr: verres teintés\nframes => de: Fassungen" ) )->translate( array( 'Shop OKAY EYEWEAR tinted lenses' ), 'en_US', 'fr_FR', later() );
	list( , , $payload ) = req_items( $tr->calls[0] );
	eq( array( 'OKAY EYEWEAR' ), $payload['protected_terms'] );
	eq( 1, count( $payload['glossary'] ) );
	eq( 'tinted lenses', $payload['glossary'][0]['source'] );
} );

test( 'translator: structured data never sent', function () {
	$tr = new AITP_Test_Transport( pseudo_responder() );
	$r  = make_translator( $tr )->translate( array( 'j' => '{"@context":"https://schema.org","@type":"FAQPage"}', 'p' => 'Plain text here' ), 'en_US', 'fr_FR', later() );
	eq( 'structured_data', $r['failed']['j'] );
	list( $items ) = req_items( $tr->calls[0] );
	eq( array( 't1' => 'Plain text here' ), $items );
} );

test( 'translator: requests the transport skipped are deferred, quota released', function () {
	$q  = new AITP_Memory_Quota();
	$tr = new AITP_Test_Transport( function ( $r, $n ) {
		if ( $n > 1 ) {
			return array( 'status' => 0, 'headers' => array(), 'body' => '', 'error' => 'Not sent', 'error_type' => 'skipped', 'duration_ms' => 0 );
		}
		list( $items ) = req_items( $r );
		$o = array();
		foreach ( $items as $id => $t ) {
			$o[ $id ] = pseudo( $t );
		}
		return gemini_ok( $o );
	} );
	$r  = make_translator( $tr, array( 'batch_items' => 1 ), array( 'quota' => $q ) )->translate( array( 'Alpha item text', 'Bravo item text' ), 'en_US', 'fr_FR', later() );
	eq( 1, count( $r['translations'] ) );
	eq( 'deferred', $r['failed'][1] );
	eq( 15, $q->used );
	eq( 0, $q->reserved );
	ok( isset( $tr->calls[0]['not_after'] ) && isset( $tr->calls[0]['deadline'] ) );
} );
