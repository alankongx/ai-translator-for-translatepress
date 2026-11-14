<?php
/**
 * 0.6.6: text that is already in the target language, echoed entries, &amp; duplicates,
 * thinking switched off for Gemini 3.8 and DeepSeek.
 */

/** Responder: echo the listed sources unchanged, pseudo-translate everything else. */
function echo_responder( array $echo, $same = array() ) {
	return function ( $r ) use ( $echo, $same ) {
		list( $items ) = req_items( $r );
		$out           = array();
		foreach ( $items as $id => $t ) {
			$out[ $id ] = in_array( $t, $echo, true ) ? $t : ( isset( $same[ $t ] ) ? $same[ $t ] : pseudo( $t ) );
		}
		return gemini_ok( $out );
	};
}

test( 'already target: real TP strings returned unchanged are not called untranslated', function () {
	foreach ( array(
		array( 'Sélecteur de langue du site web', 'fr_FR' ),
		array( 'Sprachauswahl der Website', 'de_DE' ),
		array( 'Seletor de idiomas do site', 'pt_BR' ),
		array( 'Sélection de la langue', 'fr_FR' ),
		array( 'Política de privacidad', 'es_MX' ),
		array( 'اختيار اللغة', 'ar' ),
		array( '言語を選択', 'ja' ),
	) as $c ) {
		$v = vcheck( $c[0], $c[0], $c[1] );
		eq( array(), $v['hard'], $c[0] );
		ok( in_array( 'already_target', $v['soft'], true ), $c[0] );
		ok( in_array( 'identical_confirm', $v['soft'], true ), $c[0] . ' asked once' );
	}
} );

test( 'already target: no sign of the language in the text -> still rejected, alone', function () {
	// Nothing in these two words tells them apart from English; they fail on their own.
	foreach ( array( array( 'Langues disponibles', 'fr_FR' ), array( 'Idiomas disponibles', 'es_MX' ) ) as $c ) {
		$v = vcheck( $c[0], $c[0], $c[1] );
		eq( array( 'untranslated' ), $v['hard'], $c[0] );
	}
} );

test( 'already target: English left unchanged is still rejected', function () {
	foreach ( array(
		array( 'Contact our sales team', 'fr_FR' ),
		array( 'How Do UV-Protective Lenses in Kids’ Sunglasses Work?', 'fr_FR' ),
		array( 'Free shipping on all orders', 'de_DE' ),
		array( 'Polarized sunglasses wholesale', 'es_MX' ),
		array( 'Le Mans Edition Sunglasses for Men', 'fr_FR' ),
		array( 'Café Racer Frames with Spring Hinges', 'fr_FR' ),
		array( 'Request a quote for custom frames', 'ar' ),
		array( 'Request a quote for custom frames', 'ja' ),
		array( 'Sprachauswahl der Website', 'fr_FR' ), // German is not French.
	) as $c ) {
		$v = vcheck( $c[0], $c[0], $c[1] );
		ok( (bool) $v['hard'], $c[0] . ' → ' . $c[1] );
		ok( ! in_array( 'already_target', $v['soft'], true ), $c[0] );
	}
} );

test( 'already target: Codex repro batch delivers the English sentence, breaker stays closed', function () {
	$st    = new AITP_Memory_State();
	$tr    = new AITP_Test_Transport( echo_responder( array( 'Sélecteur de langue du site web', 'Langues disponibles' ) ) );
	$items = array( 'a' => 'Sélecteur de langue du site web', 'b' => 'Langues disponibles', 'c' => 'Contact our sales team' );
	$r     = make_translator( $tr, array(), array( 'breaker' => new AITP_Circuit_Breaker( $st ) ) )->translate( $items, 'en_US', 'fr_FR', later() );
	eq( pseudo( 'Contact our sales team' ), $r['translations']['c'] );
	eq( 'Sélecteur de langue du site web', $r['translations']['a'], 'kept after one confirmation' );
	eq( array( 'already_target' ), $r['flagged']['a'], 'and listed for review' );
	eq( 'untranslated', $r['failed']['b'] );
	ok( empty( $st->state['status'] ) || $st->state['status'] === 'closed', 'breaker closed' );
	ok( count( $tr->calls ) <= 4, 'bounded: ' . count( $tr->calls ) . ' calls' );
} );

test( 'already target: all five real strings mixed with English, every language keeps working', function () {
	$st      = new AITP_Memory_State();
	$breaker = new AITP_Circuit_Breaker( $st );
	$five    = array( 'Sélecteur de langue du site web', 'Langues disponibles', 'Sprachauswahl der Website', 'Seletor de idiomas do site', 'Idiomas disponibles' );
	$english = array( 'Contact our sales team', 'Request a sample today', 'Sunglasses for every face shape', 'Our factory makes acetate frames', 'Fast delivery to your warehouse', 'Choose the lens colour you need' );
	foreach ( array( 'fr_FR', 'de_DE', 'pt_BR', 'es_MX', 'ja', 'fr_FR', 'de_DE' ) as $tl ) {
		$tr    = new AITP_Test_Transport( echo_responder( $five ) );
		$items = array_merge( $five, $english );
		$r     = make_translator( $tr, array( 'batch_items' => 11 ), array( 'breaker' => $breaker ) )->translate( $items, 'en_US', $tl, later() );
		foreach ( $english as $i => $e ) {
			eq( pseudo( $e ), $r['translations'][ 5 + $i ], $tl . ' ' . $e );
		}
		ok( ! isset( $r['stats']['stop_reason'] ) || $r['stats']['stop_reason'] !== 'breaker', $tl . ' not stopped by the breaker' );
		ok( empty( $st->state['fails'] ), $tl . ' retry rounds of the same echoes are not provider failures' );
	}
	ok( empty( $st->state['status'] ) || $st->state['status'] === 'closed', 'breaker closed after all languages' );
} );

test( 'already target: provider echoing whole batches is distrusted and holds that language', function () {
	$st    = new AITP_Memory_State();
	$tr    = new AITP_Test_Transport( function ( $r ) {
		list( $items ) = req_items( $r );
		return gemini_ok( $items );
	} );
	// Names would pass after a "confirmation", but not from a provider that echoes everything.
	$items = array( 'Contact our sales team', 'Request a sample today', 'Home – Okay Eyewear', 'Taizhou', 'Sunglasses for every face shape', 'Our factory makes acetate frames' );
	$t     = make_translator( $tr, array(), array( 'breaker' => new AITP_Circuit_Breaker( $st ) ) );
	$r     = $t->translate( $items, 'en_US', 'fr_FR', later() );
	eq( array(), $r['translations'], 'nothing saved, names included' );
	$t->translate( array( 'Fast delivery to your warehouse', 'Choose the lens colour you need', 'Frames for every face', 'Talk to our team' ), 'en_US', 'fr_FR', later() );
	$b = new AITP_Circuit_Breaker( $st );
	ok( ! $b->allow( 'fr_FR' ), 'French held' );
	ok( $b->allow( 'de_DE' ), 'other languages go on' );
	eq( 'misaligned:echoed', $st->state['locales']['fr_FR']['message'] );
} );

test( 'misaligned: "&" and "&amp;" copies of one sentence may get the same translation', function () {
	$a     = 'How to Prevent Scratches When Shipping TR90 & TPEE Kids Sunglasses?';
	$b     = 'How to Prevent Scratches When Shipping TR90 &amp; TPEE Kids Sunglasses?';
	$tr    = new AITP_Test_Transport( echo_responder( array(), array( $b => pseudo( $a ) ) ) );
	$items = array( 'a' => $a, 'b' => $b, 'c' => 'Contact our sales team' );
	$r     = make_translator( $tr )->translate( $items, 'en_US', 'fr_FR', later() );
	eq( 1, count( $tr->calls ) );
	eq( 3, count( $r['translations'] ) );
} );

test( 'gemini 3.8: thinking off tries MINIMAL, then budget 0, then LOW', function () {
	$caps = new AITP_Memory_Caps();
	$p    = new AITP_Provider_Gemini( array( 'api_key' => 'test-key-123456', 'model' => 'gemini-3.8-flash', 'thinking' => 'off' ) );
	$tr   = new AITP_Test_Transport( pseudo_responder( function ( $out, $items, $n ) {
		if ( $n === 1 ) {
			return http( 400, array( 'error' => array( 'code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'Thinking level MINIMAL is not supported for this model.' ) ) );
		}
		return $out;
	} ) );
	$r    = make_translator( $tr, array(), array( 'caps' => $caps ), array(), $p )->translate( array( 'Alpha frames for men' ), 'en_US', 'fr_FR', later() );
	eq( 1, count( $r['translations'] ) );
	$b1 = json_decode( $tr->calls[0]['body'], true );
	$b2 = json_decode( $tr->calls[1]['body'], true );
	eq( array( 'thinkingLevel' => 'minimal' ), $b1['generationConfig']['thinkingConfig'] );
	eq( array( 'thinkingBudget' => 0 ), $b2['generationConfig']['thinkingConfig'] );
	eq( 512, $p->thinking_reserve( array_merge( $p->default_caps(), array( 'thinking_minimal' => false ) ) ) );
	// Caps stored by 0.6.5 (MINIMAL refused, no budget-0 flag yet) now get budget 0.
	$old = array_merge( $p->default_caps(), array( 'thinking_minimal' => false ) );
	unset( $old['thinking_budget0'] );
	$b = json_decode( $p->build_request( 's', 'u', 100, $old )['body'], true );
	eq( array( 'thinkingBudget' => 0 ), $b['generationConfig']['thinkingConfig'] );
	// Budget 0 refused as well: LOW.
	$c = $p->downgrade( $old, 'thinking' );
	eq( false, $c['thinking_budget0'] );
	$b = json_decode( $p->build_request( 's', 'u', 100, $c )['body'], true );
	eq( array( 'thinkingLevel' => 'low' ), $b['generationConfig']['thinkingConfig'] );
	// Thinking on (low) is unchanged.
	$p = new AITP_Provider_Gemini( array( 'api_key' => 'test-key-123456', 'model' => 'gemini-3.8-flash', 'thinking' => 'low' ) );
	$b = json_decode( $p->build_request( 's', 'u', 100, $p->default_caps() )['body'], true );
	eq( array( 'thinkingLevel' => 'low' ), $b['generationConfig']['thinkingConfig'] );
} );

test( 'deepseek: thinking switched off, JSON mode, no thinking reserve', function () {
	$p = new AITP_Provider_OpenAI( array( 'api_key' => 'sk-test123456', 'model' => 'deepseek-flash', 'base_url' => 'https://api.deepseek.com/v1', 'thinking' => 'off' ) );
	$c = $p->default_caps();
	$b = json_decode( $p->build_request( 's', 'u', 1000, $c )['body'], true );
	eq( array( 'type' => 'disabled' ), $b['thinking'] );
	eq( array( 'type' => 'json_object' ), $b['response_format'] );
	ok( ! isset( $b['reasoning_effort'] ) );
	eq( 0, $p->thinking_reserve( $c ) );
	ok( substr( $p->fingerprint(), -12 ) === '|ds-thinking', 'caps remembered by 0.6.5 are not reused' );
	$p = new AITP_Provider_OpenAI( array( 'api_key' => 'sk-test123456', 'model' => 'deepseek-flash', 'base_url' => 'https://api.deepseek.com/v1', 'thinking' => 'low' ) );
	$b = json_decode( $p->build_request( 's', 'u', 1000, $p->default_caps() )['body'], true );
	eq( array( 'type' => 'enabled' ), $b['thinking'] );
	ok( $p->thinking_reserve( $p->default_caps() ) > 0 );
	$p = new AITP_Provider_OpenAI( array( 'api_key' => 'sk-test123456', 'model' => 'deepseek-flash', 'base_url' => 'https://api.deepseek.com/v1', 'thinking' => 'auto' ) );
	$b = json_decode( $p->build_request( 's', 'u', 1000, $p->default_caps() )['body'], true );
	ok( ! isset( $b['thinking'] ) );
	// Field refused by the endpoint: switched off and remembered like any other feature.
	$p = new AITP_Provider_OpenAI( array( 'api_key' => 'sk-test123456', 'model' => 'deepseek-flash', 'base_url' => 'https://api.deepseek.com/v1', 'thinking' => 'off' ) );
	$r = $p->parse_response( 400, array(), json_encode( array( 'error' => array( 'message' => 'Unknown parameter: thinking' ) ) ), $p->default_caps() );
	eq( 'thinking', $r['unsupported'] );
	$c = $p->downgrade( $p->default_caps(), 'thinking' );
	$b = json_decode( $p->build_request( 's', 'u', 1000, $c )['body'], true );
	ok( ! isset( $b['thinking'] ) );
	eq( 8192, $p->thinking_reserve( $c ) );
	// Other OpenAI-compatible hosts: no thinking field, schema still tried first.
	$p = new AITP_Provider_OpenAI( array( 'api_key' => 'sk-test123456', 'model' => 'gpt-4.1-mini', 'thinking' => 'off' ) );
	$b = json_decode( $p->build_request( 's', 'u', 1000, $p->default_caps() )['body'], true );
	ok( ! isset( $b['thinking'] ) );
	eq( 'json_schema', $b['response_format']['type'] );
	eq( 'deepseek-flash', AITP_Provider_OpenAI::presets()['deepseek']['model'] );
} );
