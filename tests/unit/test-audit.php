<?php
/**
 * Audit rules and the AI pair check.
 */
function audit_row( $src, $tgt, $tl = 'fr_FR' ) {
	return AITP_Audit_Logic::check_row( new AITP_Validator( okay_rules() ), $src, $tgt, AITP_Languages::info( 'en_US' ), AITP_Languages::info( $tl ) );
}

test( 'audit: good stored translation -> nothing found', function () {
	eq( null, audit_row( 'Explore our complete eyewear range', pseudo( 'Explore our complete eyewear range' ) ) );
	eq( null, audit_row( 'Taizhou', 'Taizhou' ), 'name kept unchanged is fine' );
	eq( null, audit_row( 'OKAY EYEWEAR', 'OKAY EYEWEAR' ), 'brand kept unchanged is fine' );
	eq( 'suspect', audit_row( 'TR90 Kids Sunglasses: OEM & ODM Models | OKAY EYEWEAR', 'TR90 Kids Sunglasses: OEM & ODM Models | OKAY EYEWEAR' )['severity'], 'title left in English' );
} );

test( 'audit: the three HHG failure types are errors', function () {
	$long = 'A new mold makes sense when your design is genuinely distinctive — a signature shape that sets your collection apart and justifies the tooling cost.';
	$r    = audit_row( $long, 'حجم طلب كافٍ', 'ar' );
	eq( 'error', $r['severity'] );
	has( 'too_short', $r['codes'] );
	$r = audit_row( 'How Do UV-Protective Lenses in Kids’ Sunglasses Work?', 'How Do UV-Protective Lenses in Kids’ Sunglasses Work?' );
	eq( 'error', $r['severity'], 'English headline stored as translation' );
	$r = audit_row( 'Serving Brands Across 20+ Countries', 'Dr. Claire C.' );
	eq( 'error', $r['severity'], 'translation of another string' );
	eq( 'error', audit_row( 'Hello there friend', '' )['severity'], 'empty' );
} );

test( 'audit: added claim is for review, not an error', function () {
	$r = audit_row( 'Lenses that block UV light', 'Des verres certifiés qui bloquent la lumière UV' );
	eq( 'review', $r['severity'] );
} );

test( 'audit: structured data must keep its shape', function () {
	$o = '{"@context":"https://schema.org","@type":"FAQPage","mainEntity":[{"@type":"Question","name":"What is TR90?","acceptedAnswer":{"@type":"Answer","text":"A flexible material."}}]}';
	$t = '{"@context":"https://schema.org","@type":"FAQPage","mainEntity":[{"@type":"Question","name":"Qu\'est-ce que le TR90 ?","acceptedAnswer":{"@type":"Answer","text":"Un matériau flexible."}}]}';
	eq( null, audit_row( $o, $t ) );
	eq( 'structured_broken', audit_row( $o, 'Qu\'est-ce que le TR90 ? Un matériau flexible.' )['codes'][0] );
	eq( 'structured_broken', audit_row( $o, str_replace( '"FAQPage"', '"PageFAQ"', $t ) )['codes'][0], '@type translated' );
	eq( 'structured_broken', audit_row( $o, str_replace( '"name"', '"nom"', $t ) )['codes'][0], 'key translated' );
} );

test( 'audit: one translation shared by unrelated originals is suspect', function () {
	$t = 'Des montures légères pour un usage quotidien et confortable';
	eq( array( 10, 11 ), AITP_Audit_Logic::shared_suspects( $t, array( 10 => 'Lightweight frames for comfortable everyday wear', 11 => 'Contact our sales team today' ) ) );
	eq( array(), AITP_Audit_Logic::shared_suspects( $t, array( 10 => 'Lightweight frames for comfortable everyday wear', 11 => 'Lightweight frames for comfortable everyday wear!' ) ), 'punctuation variant' );
	eq( array(), AITP_Audit_Logic::shared_suspects( $t, array( 10 => 'Lightweight frames for comfortable everyday wear', 11 => '<strong>Lightweight frames</strong> for comfortable everyday wear' ) ), 'markup variant' );
	eq( array(), AITP_Audit_Logic::shared_suspects( 'En savoir plus', array( 1 => 'Read more', 2 => 'Learn more' ) ), 'short label' );
} );

test( 'audit: reset only rows unchanged since the scan, never manual ones', function () {
	$f   = array( 'original_hash' => sha1( 'Hello world' ), 'translated_hash' => sha1( 'Bonjour monde' ) );
	$row = array( 'original' => 'Hello world', 'translated' => 'Bonjour monde', 'status' => 1 );
	eq( '', AITP_Audit_Logic::reset_block( $f, $row ) );
	eq( 'manual', AITP_Audit_Logic::reset_block( $f, array( 'status' => 2 ) + $row ) );
	eq( 'changed', AITP_Audit_Logic::reset_block( $f, array( 'translated' => 'Salut le monde' ) + $row ) );
	eq( 'untranslated', AITP_Audit_Logic::reset_block( $f, array( 'status' => 0 ) + $row ) );
	eq( 'missing', AITP_Audit_Logic::reset_block( $f, null ) );
} );

test( 'audit: rollback restores reset or machine rows, never manual ones', function () {
	$b = array( 'original' => 'Hello world', 'translated' => 'Bonjour monde', 'status' => 1 );
	eq( '', AITP_Audit_Logic::restore_block( $b, array( 'original' => 'Hello world', 'translated' => null, 'status' => 0 ) ) );
	eq( '', AITP_Audit_Logic::restore_block( $b, array( 'original' => 'Hello world', 'translated' => 'Salut', 'status' => 1 ) ) );
	eq( 'manual', AITP_Audit_Logic::restore_block( $b, array( 'original' => 'Hello world', 'translated' => 'Salut', 'status' => 2 ) ) );
	eq( 'changed', AITP_Audit_Logic::restore_block( $b, array( 'original' => 'Other', 'translated' => '', 'status' => 0 ) ) );
	eq( 'missing', AITP_Audit_Logic::restore_block( $b, null ) );
} );

test( 'audit: import reads JSON and CSV candidate lists', function () {
	$j = AITP_Audit_Logic::parse_import( json_encode( array( array( 'language' => 'ar', 'id' => '1172', 'original' => 'A', 'old' => 'B', 'old_status' => '1' ), array( 'language' => 'x y', 'id' => '1' ) ) ) );
	eq( 1, count( $j['rows'] ) );
	eq( 1, $j['bad'] );
	eq( array( 'lang' => 'ar', 'id' => 1172, 'original' => 'A', 'old' => 'B', 'table' => 'dictionary' ), $j['rows'][0] );
	$c = AITP_Audit_Logic::parse_import( "\xEF\xBB\xBFlanguage,id,original,translated\nfr_FR,5,\"Hello, world\",\"Bonjour, monde\"\nfr_FR,abc,x,y\n" );
	eq( 1, count( $c['rows'] ) );
	eq( 'Hello, world', $c['rows'][0]['original'] );
	eq( 1, $c['bad'] );
} );

test( 'audit: AI verdicts', function () {
	eq( 'match', AITP_Audit_Logic::verdict( ' Match. ' ) );
	eq( 'mismatch', AITP_Audit_Logic::verdict( 'mismatch' ) );
	eq( 'unsure', AITP_Audit_Logic::verdict( 'yes' ) );
} );

function ai_checker( $responder, $deps = array() ) {
	$p = new AITP_Provider_Gemini( array( 'api_key' => 'test-key-123456', 'model' => 'gemini-3.5-flash' ) );
	return new AITP_Pair_Checker( $p, $p->default_caps(), array_merge( array( 'transport' => new AITP_Test_Transport( $responder ) ), $deps ) );
}
function ai_items( array $r ) {
	$b = json_decode( $r['body'], true );
	return json_decode( $b['contents'][0]['parts'][0]['text'], true );
}

test( 'ai check: verdicts mapped back by id, texts sent as data', function () {
	$seen = null;
	$c    = ai_checker(
		function ( $r ) use ( &$seen ) {
			$seen = ai_items( $r );
			$out  = array();
			foreach ( $seen['items'] as $it ) {
				$out[ $it['id'] ] = $it['translation'] === pseudo( $it['source'] ) ? 'match' : 'mismatch';
			}
			return gemini_ok( array_reverse( $out, true ) );
		}
	);
	$res = $c->check( array( 7 => array( 'Hello world', pseudo( 'Hello world' ) ), 9 => array( 'Contact us', pseudo( 'Hello world' ) ) ), AITP_Languages::info( 'en_US' ), AITP_Languages::info( 'fr_FR' ), microtime( true ) + 30 );
	$v = $res['verdicts'];
	ksort( $v );
	eq( array( '7' => 'match', '9' => 'mismatch' ), $v );
	eq( 2, count( $seen['items'] ) );
	ok( strpos( json_encode( $seen ), 'test-key' ) === false );
} );

test( 'ai check: incomplete answer is not used and counts as bad output', function () {
	$breaker = new AITP_Circuit_Breaker( new AITP_Memory_State() );
	$c       = ai_checker(
		function () {
			return gemini_ok( array( 'c1' => 'match' ) );
		},
		array( 'breaker' => $breaker )
	);
	$res = $c->check( array( 1 => array( 'A b c', 'x' ), 2 => array( 'D e f', 'y' ) ), AITP_Languages::info( 'en_US' ), AITP_Languages::info( 'fr_FR' ), microtime( true ) + 30 );
	eq( array(), $res['verdicts'] );
	eq( 'bad_output', $res['stop'] );
} );

test( 'ai check: quota refused -> nothing sent', function () {
	$t = new AITP_Test_Transport( function () {
		return gemini_ok( array() );
	} );
	$p = new AITP_Provider_Gemini( array( 'api_key' => 'test-key-123456', 'model' => 'm' ) );
	$c = new AITP_Pair_Checker( $p, $p->default_caps(), array( 'transport' => $t, 'quota' => new AITP_Memory_Quota( 5 ) ) );
	$res = $c->check( array( 1 => array( 'Hello world', 'Bonjour monde' ) ), AITP_Languages::info( 'en_US' ), AITP_Languages::info( 'fr_FR' ), microtime( true ) + 30 );
	eq( 'quota', $res['stop'] );
	eq( 0, count( $t->calls ) );
} );

test( 'audit: neighbours of several misplaced rows become suspects', function () {
	eq( array( array( 40, 50 ) ), AITP_Audit_Logic::neighbor_ranges( array( 42, 46, 48 ) ) );
	eq( array(), AITP_Audit_Logic::neighbor_ranges( array( 10, 40 ) ), 'isolated errors' );
	eq( array( array( 1, 5 ), array( 98, 103 ) ), AITP_Audit_Logic::neighbor_ranges( array( 101, 2, 3, 100 ) ) );
	ok( AITP_Audit_Logic::shift_like( array( 'too_short' ) ) );
	ok( ! AITP_Audit_Logic::shift_like( array( 'added_claim:certified' ) ) );
} );

test( 'audit: JSON with typographic quotes (texturized by WordPress) is still checked', function () {
	$o = "{\n  &#8220;@context&#8221;: &#8220;https://schema.org&#8221;, &#8220;@type&#8221;: &#8220;FAQPage&#8221;, &#8220;name&#8221;: &#8220;What is TR90?&#8221;}";
	eq( 'structured_broken', audit_row( $o, 'TR90' )['codes'][0] );
	eq( null, audit_row( $o, "{\n  &#8220;@context&#8221;: &#8220;https://schema.org&#8221;, &#8220;@type&#8221;: &#8220;FAQPage&#8221;, &#8220;name&#8221;: &#8220;Qu'est-ce que le TR90 ?&#8221;}" ) );
} );

test( 'audit: accepted rows are never reset, only while the text is unchanged', function () {
	$row = array( 'original' => 'Kids sunglasses', 'translated' => 'نظارات شمسية للأطفال', 'status' => 1 );
	$f   = array( 'original_hash' => sha1( $row['original'] ), 'translated_hash' => sha1( $row['translated'] ) );
	$acc = array( array( sha1( $row['original'] ), sha1( $row['translated'] ) ) );
	eq( 'accepted', AITP_Audit_Logic::reset_block( $f, $row, $acc ) );
	eq( '', AITP_Audit_Logic::reset_block( $f, $row ) );
	// The accepted text was replaced later: no longer protected.
	eq( '', AITP_Audit_Logic::reset_block( $f, $row, array( array( sha1( $row['original'] ), sha1( 'other' ) ) ) ) );
	// Manual and changed rows keep their own reason.
	eq( 'manual', AITP_Audit_Logic::reset_block( $f, array( 'status' => 2 ) + $row, $acc ) );
	ok( AITP_Audit_Logic::is_accepted( $row, $acc ) );
	ok( ! AITP_Audit_Logic::is_accepted( array( 'translated' => 'x' ) + $row, $acc ) );
} );

test( 'audit: accepted list reads "new" columns and {rows:[...]} files', function () {
	$json = json_encode( array( 'rows' => array( array( 'language' => 'ar', 'id' => '5', 'original' => 'TPEE Kids Sunglasses', 'new' => 'نظارات', 'old' => 'قديم', 'new_status' => '1' ) ) ) );
	$p    = AITP_Audit_Logic::parse_import( $json, array( 'new', 'translated', 'translation', 'text', 'old' ) );
	eq( 1, count( $p['rows'] ) );
	eq( 'نظارات', $p['rows'][0]['old'] );
	// The candidate import still reads the old text.
	eq( 'قديم', AITP_Audit_Logic::parse_import( $json )['rows'][0]['old'] );
} );
