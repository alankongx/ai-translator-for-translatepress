<?php
/**
 * 0.7.0 batch 1: provider errors, batch isolation, thinking models, output limits, languages.
 * One test per finding of the 0.6.6 review (private/review-0.6.6/A-core.md).
 */

function oai_ok_list( array $items, $mutate = null ) {
	$list = array();
	foreach ( $items as $id => $t ) {
		$list[] = array( 'id' => (string) $id, 'text' => $mutate ? $mutate( $t ) : pseudo( $t ) );
	}
	return http( 200, array( 'choices' => array( array( 'message' => array( 'content' => json_encode( array( 'translations' => $list ), JSON_UNESCAPED_UNICODE ) ), 'finish_reason' => 'stop' ) ), 'usage' => array( 'prompt_tokens' => 50, 'completion_tokens' => 40 ) ) );
}

function deepseek( array $extra = array() ) {
	return new AITP_Provider_OpenAI( array_merge( array( 'api_key' => 'sk-test-123456', 'model' => 'deepseek-chat', 'base_url' => 'https://api.deepseek.com/v1', 'thinking' => 'off' ), $extra ) );
}

/* --- A02 / A24: error classification --- */

test( 'A02: Gemini per-minute 429 that mentions "billing" is a rate limit, not billing', function () {
	$p = new AITP_Provider_Gemini( array( 'api_key' => 'k-123456', 'model' => 'gemini-2.5-flash' ) );
	$body = json_encode( array( 'error' => array( 'code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'You exceeded your current quota, please check your plan and billing details.', 'details' => array( array( '@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '41s' ) ) ) ) );
	$r = $p->parse_response( 429, array(), $body );
	eq( 'rate_limit', $r['error'] );
	eq( 41, $r['retry_after'] );
	$o = new AITP_Provider_OpenAI( array( 'api_key' => 'k-123456', 'model' => 'gpt-4.1-mini' ) );
	eq( 'rate_limit', $o->parse_response( 429, array(), '{"error":{"code":"rate_limit_exceeded","message":"Rate limit reached for gpt-4.1-mini on requests per min (RPM): Limit 3. You can increase your rate limit by adding a payment method at https://platform.openai.com/account/billing."}}' )['error'] );
	eq( 'billing', $o->parse_response( 429, array(), '{"error":{"type":"insufficient_quota","message":"You exceeded your current quota"}}' )['error'] );
	eq( 'billing', $p->parse_response( 429, array(), json_encode( array( 'error' => array( 'code' => 429, 'status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded for metric: generate_content_free_tier_requests, limit: 0' ) ) ) )['error'] );
} );

test( 'A02: daily wording -> quota_daily; HTTP date in Retry-After is parsed', function () {
	$o = new AITP_Provider_OpenAI( array( 'api_key' => 'k-123456', 'model' => 'gpt-4.1-mini' ) );
	eq( 'quota_daily', $o->parse_response( 429, array(), '{"error":{"message":"Rate limit reached on requests per day (RPD)"}}' )['error'] );
	$when = gmdate( 'D, d M Y H:i:s', time() + 120 ) . ' GMT';
	$ra   = $o->parse_response( 429, array( 'retry-after' => $when ), '{"error":{"message":"Too many requests"}}' )['retry_after'];
	ok( $ra >= 100 && $ra <= 125, (string) $ra );
} );

test( 'A24: moderation 400 -> content_rejected; missing model on 503 -> not_found', function () {
	$p = deepseek();
	eq( 'content_rejected', $p->parse_response( 400, array(), '{"error":{"message":"Content Exists Risk","type":"invalid_request_error"}}' )['error'] );
	eq( 'content_rejected', $p->parse_response( 400, array(), '{"error":{"message":"Input data may contain inappropriate content.","code":"data_inspection_failed"}}' )['error'] );
	eq( 'not_found', $p->parse_response( 503, array(), '{"error":{"message":"The model `deepseek-chatt` does not exist"}}' )['error'] );
	eq( 'bad_request', $p->parse_response( 400, array(), '{"error":{"message":"messages: field required"}}' )['error'] );
} );

/* --- A06 / A04: one refused text is isolated, neighbours still translated --- */

test( 'A06: one text refused with HTTP 400 is isolated; neighbours translated; engine not paused', function () {
	$items = array();
	for ( $i = 0; $i < 12; $i++ ) {
		$items[ 'k' . $i ] = "Plain product sentence number $i about our frames";
	}
	$items['k7'] = 'SENSITIVE sentence about frames';
	$st = new AITP_Memory_State();
	$tr = new AITP_Test_Transport( function ( $r ) {
		list( $it ) = req_items( $r );
		if ( in_array( 'SENSITIVE sentence about frames', $it, true ) ) {
			return http( 400, array( 'error' => array( 'message' => 'Content Exists Risk', 'type' => 'invalid_request_error' ) ) );
		}
		return oai_ok_list( $it );
	} );
	$r = make_translator( $tr, array(), array( 'breaker' => new AITP_Circuit_Breaker( $st ) ), array(), deepseek() )->translate( $items, 'en_US', 'fr_FR', later() );
	eq( 11, count( $r['translations'] ) );
	eq( 'content_rejected', $r['failed']['k7'] );
	ok( empty( $st->state['status'] ) || $st->state['status'] === 'closed', 'breaker stays closed' );
} );

test( 'A04: safety-blocked batch is bisected; only the blocked text fails', function () {
	$items = array();
	for ( $i = 0; $i < 8; $i++ ) {
		$items[ 'k' . $i ] = "Ordinary frame description number $i";
	}
	$items['k3'] = 'BLOCKED frame description';
	$tr = new AITP_Test_Transport( function ( $r ) {
		list( $it ) = req_items( $r );
		if ( in_array( 'BLOCKED frame description', $it, true ) ) {
			return gemini_text( '', 'SAFETY' );
		}
		$o = array();
		foreach ( $it as $id => $t ) {
			$o[ $id ] = pseudo( $t );
		}
		return gemini_ok( $o );
	} );
	$r = make_translator( $tr )->translate( $items, 'en_US', 'fr_FR', later() );
	eq( 7, count( $r['translations'] ) );
	ok( isset( $r['failed']['k3'] ), 'blocked text failed' );
} );

test( 'A06: bad_request from two batches in one round is systemic (engine stops)', function () {
	$items = array();
	for ( $i = 0; $i < 40; $i++ ) {
		$items[ 'k' . $i ] = "Plain product sentence number $i about our frames";
	}
	$st = new AITP_Memory_State();
	$tr = new AITP_Test_Transport( function () {
		return http( 400, array( 'error' => array( 'message' => 'messages: field required' ) ) );
	} );
	$r = make_translator( $tr, array( 'batch_items' => 10, 'concurrency' => 4 ), array( 'breaker' => new AITP_Circuit_Breaker( $st ) ), array(), deepseek() )->translate( $items, 'en_US', 'fr_FR', later() );
	eq( 0, count( $r['translations'] ) );
	ok( count( $tr->calls ) <= 8, 'does not bisect a systemic error: ' . count( $tr->calls ) );
	ok( isset( $st->state['bad'] ) && $st->state['bad'] >= 1, 'counted against the provider' );
} );

/* --- A01: parallel rejections downgrade one step only --- */

test( 'A01: parallel batches rejected for the same feature downgrade it only once', function () {
	$items = array();
	for ( $i = 0; $i < 30; $i++ ) {
		$items[ 'k' . $i ] = "Plain product sentence number $i about our frames";
	}
	$caps = new AITP_Memory_Caps();
	$p    = new AITP_Provider_OpenAI( array( 'api_key' => 'sk-test-123456', 'model' => 'some-model', 'base_url' => 'https://relay.example.com/v1' ) );
	$tr   = new AITP_Test_Transport( function ( $r ) {
		$b = json_decode( $r['body'], true );
		if ( isset( $b['response_format'] ) && $b['response_format']['type'] === 'json_schema' ) {
			return http( 400, '{"error":{"message":"response_format type json_schema is not supported"}}' );
		}
		list( $it ) = req_items( $r );
		return oai_ok_list( $it );
	} );
	$r = make_translator( $tr, array( 'batch_items' => 10, 'concurrency' => 3 ), array( 'caps' => $caps ), array(), $p )->translate( $items, 'en_US', 'fr_FR', later() );
	eq( 30, count( $r['translations'] ) );
	$learned = array_values( $caps->rows )[0];
	ok( empty( $learned['schema'] ) && ! empty( $learned['json'] ), 'schema off, json mode kept: ' . json_encode( $learned ) );
} );

/* --- A05 / A29: thinking models on OpenAI-compatible endpoints --- */

test( 'A05: DeepSeek reasoner / Qwen3 / Zhipu GLM reserve output for thinking', function () {
	ok( deepseek( array( 'model' => 'deepseek-reasoner' ) )->thinking_reserve( deepseek( array( 'model' => 'deepseek-reasoner' ) )->default_caps() ) >= 4096 );
	$z = new AITP_Provider_OpenAI( array( 'api_key' => 'k-123456', 'model' => 'glm-4.6', 'base_url' => 'https://open.bigmodel.cn/api/paas/v4', 'thinking' => 'off' ) );
	$b = json_decode( $z->build_request( 's', 'u', 100, $z->default_caps() )['body'], true );
	eq( 'disabled', $b['thinking']['type'] );
} );

test( 'A05: truncation with most of the budget spent thinking is learned', function () {
	$items = array( 'a' => 'Polarized sunglasses for driving', 'b' => 'Blue light glasses for office work' );
	$caps  = new AITP_Memory_Caps();
	$tr    = new AITP_Test_Transport( function ( $r, $n ) {
		$b = json_decode( $r['body'], true );
		$mt = isset( $b['max_tokens'] ) ? $b['max_tokens'] : $b['max_completion_tokens'];
		if ( $mt < 8192 ) {
			return http( 200, array( 'choices' => array( array( 'message' => array( 'content' => '{"translations":[{"id":"t1","te' ), 'finish_reason' => 'length' ) ), 'usage' => array( 'prompt_tokens' => 50, 'completion_tokens' => $mt, 'completion_tokens_details' => array( 'reasoning_tokens' => (int) ( $mt * 0.9 ) ) ) ) );
		}
		list( $it ) = req_items( $r );
		return oai_ok_list( $it );
	} );
	$p = new AITP_Provider_OpenAI( array( 'api_key' => 'k-123456', 'model' => 'mystery-model', 'base_url' => 'https://relay.example.com/v1' ) );
	$r = make_translator( $tr, array(), array( 'caps' => $caps ), array(), $p )->translate( $items, 'en_US', 'fr_FR', later() );
	eq( 2, count( $r['translations'] ) );
	$learned = array_values( $caps->rows )[0];
	ok( ! empty( $learned['thinks'] ), json_encode( $learned ) );
} );

test( 'A29: refused "minimal" effort falls back to "low", never to the default', function () {
	$p    = new AITP_Provider_OpenAI( array( 'api_key' => 'k-123456', 'model' => 'gpt-5-mini', 'thinking' => 'off' ) );
	$caps = $p->default_caps();
	eq( 'minimal', json_decode( $p->build_request( 's', 'u', 100, $caps )['body'], true )['reasoning_effort'] );
	$feature = $p->parse_response( 400, array(), '{"error":{"message":"Unsupported value: \'reasoning_effort\' does not support \'minimal\' with this model."}}', $caps )['unsupported'];
	$caps    = $p->downgrade( $caps, $feature );
	ok( is_array( $caps ) );
	eq( 'low', json_decode( $p->build_request( 's', 'u', 100, $caps )['body'], true )['reasoning_effort'] );
} );

/* --- A18: output limit learned from the error text --- */

test( 'A18: "supports at most 4096 completion tokens" -> limit learned, parameter name kept', function () {
	$items = array();
	for ( $i = 0; $i < 120; $i++ ) {
		$items[ 'k' . $i ] = "This is product sentence number $i, describing our polarized frames for outdoor sports in some detail.";
	}
	$caps  = new AITP_Memory_Caps();
	$st    = new AITP_Memory_State();
	$param = array();
	$tr    = new AITP_Test_Transport( function ( $r ) use ( &$param ) {
		$b  = json_decode( $r['body'], true );
		$pn = isset( $b['max_tokens'] ) ? 'max_tokens' : 'max_completion_tokens';
		$param[ $pn ] = true;
		if ( $b[ $pn ] > 4096 ) {
			return http( 400, array( 'error' => array( 'type' => 'invalid_request_error', 'message' => "$pn is too large: {$b[$pn]}. This model supports at most 4096 completion tokens, whereas you provided {$b[$pn]}." ) ) );
		}
		list( $it ) = req_items( $r );
		return oai_ok_list( $it );
	} );
	// A thinking model behind a relay: the plugin asks for 8192 extra tokens for thinking.
	$p = new AITP_Provider_OpenAI( array( 'api_key' => 'sk-test-123456', 'model' => 'qwen3-235b-a22b', 'base_url' => 'https://relay.example/v1' ) );
	$r = make_translator( $tr, array(), array( 'caps' => $caps, 'breaker' => new AITP_Circuit_Breaker( $st ) ), array(), $p )->translate( $items, 'en_US', 'fr_FR', later() );
	eq( 120, count( $r['translations'] ) );
	$learned = array_values( $caps->rows )[0];
	eq( 4096, (int) $learned['max_out'] );
	eq( array( 'max_tokens' => true ), $param, 'relay parameter not switched' );
	ok( empty( $st->state['status'] ) || $st->state['status'] === 'closed' );
} );

/* --- A19: reasoning tags and chatter around the JSON --- */

test( 'A19: <think> block and chatter around the JSON are accepted', function () {
	$r = AITP_Protocol::parse( "<think>\nlet me see {\"x\":1}\n</think>\nSure! Here it is:\n{\"translations\":[{\"id\":\"t1\",\"text\":\"Bonjour\"}]}\nHope this helps.", array( 't1' ) );
	eq( 'ok', $r['status'] );
	eq( 'Bonjour', $r['translations']['t1'] );
} );

/* --- A03 / A12: same text for two sources, per-language breaker --- */

test( 'A03: two sources that legitimately share a translation are both saved', function () {
	$tr = new AITP_Test_Transport( pseudo_responder( function ( $out, $items ) {
		foreach ( $items as $id => $t ) {
			if ( $t === 'Contact' || $t === 'Contact us' ) {
				$out[ $id ] = 'Contacto';
			}
		}
		return $out;
	} ) );
	$items = array( 'a' => 'Contact', 'b' => 'Contact us', 'c' => 'Shop all frames', 'd' => 'Our story', 'e' => 'Request a sample' );
	$r = make_translator( $tr )->translate( $items, 'en_US', 'es_MX', later() );
	eq( 'Contacto', $r['translations']['a'] );
	eq( 'Contacto', $r['translations']['b'] );
	eq( 5, count( $r['translations'] ) );
} );

test( 'A12: unusable answers for one language hold that language only', function () {
	$b = new AITP_Circuit_Breaker( new AITP_Memory_State() );
	for ( $i = 0; $i < 3; $i++ ) {
		$b->failure( 'bad_output', null, 'misaligned', 'ur' );
	}
	ok( ! $b->allow( 'ur' ) );
	ok( $b->allow( 'fr_FR' ) );
	ok( $b->allow() );
	eq( array( 'ur' ), array_keys( $b->held_locales() ) );
	$b->success( 'fr_FR' );
	ok( ! $b->allow( 'ur' ), 'another language succeeding does not release it' );
	for ( $i = 0; $i < 3; $i++ ) {
		$b->failure( 'bad_output', null, 'x', 'de_DE' );
		$b->failure( 'bad_output', null, 'x', 'ja' );
	}
	ok( ! $b->allow( 'it_IT' ), 'three languages held -> provider-wide' );
} );

/* --- A17: a text that always times out on its own --- */

test( 'A17: single-text timeout is recorded for that text, engine keeps going', function () {
	$st = new AITP_Memory_State();
	$tr = new AITP_Test_Transport( function ( $r ) {
		list( $it ) = req_items( $r );
		if ( in_array( 'SLOW', $it, true ) ) {
			return array( 'status' => 0, 'headers' => array(), 'body' => '', 'error' => 'Operation timed out', 'error_type' => 'timeout', 'duration_ms' => 30000 );
		}
		$o = array();
		foreach ( $it as $id => $t ) {
			$o[ $id ] = pseudo( $t );
		}
		return gemini_ok( $o );
	} );
	$r = make_translator( $tr, array(), array( 'breaker' => new AITP_Circuit_Breaker( $st ) ) )->translate( array( 'a' => 'Frames for kids', 'b' => 'SLOW', 'c' => 'Frames for adults' ), 'en_US', 'fr_FR', later() );
	eq( 2, count( $r['translations'] ) );
	eq( 'timeout_item', $r['failed']['b'] );
	ok( empty( $st->state['status'] ) || $st->state['status'] === 'closed' );
} );

/* --- A23: usage recorded --- */

test( 'A23: token usage of every request is recorded per target language', function () {
	$usage = new AITP_Memory_Usage();
	$tr    = new AITP_Test_Transport( pseudo_responder() );
	make_translator( $tr, array(), array( 'usage' => $usage ) )->translate( array( 'a' => 'Frames for kids' ), 'en_US', 'fr_FR', later() );
	eq( 1, count( $usage->rows ) );
	eq( 'fr_FR', $usage->rows[0]['target'] );
	eq( 1, $usage->rows[0]['requests'] );
	eq( 10, $usage->rows[0]['input'] );
} );

/* --- A10 / E06: functional strings returned as they are --- */

test( 'A10: date formats and text direction are answered locally, never sent', function () {
	$tr = new AITP_Test_Transport( pseudo_responder() );
	$r  = make_translator( $tr )->translate( array( 'd' => 'F j, Y', 't' => 'g:i a', 'dir' => 'ltr', 'x' => 'Frames for kids' ), 'en_US', 'ar', later() );
	eq( 'F j, Y', $r['translations']['d'] );
	eq( 'g:i a', $r['translations']['t'] );
	eq( 'rtl', $r['translations']['dir'] );
	list( $sent ) = req_items( $tr->calls[0] );
	eq( array( 'Frames for kids' ), array_values( $sent ) );
	eq( 'ltr', AITP_Translator::fixed_translation( 'rtl', AITP_Languages::info( 'fr_FR' ) ) );
} );

/* --- Languages: A11 / A08 / A14 --- */

test( 'A11: languages TranslatePress offers are known (scripts), aliases map 3-letter codes', function () {
	foreach ( array( 'or' => 'Orya', 'bo' => 'Tibt', 'dv' => 'Thaa', 'si' => 'Sinh', 'my' => 'Mymr', 'km' => 'Khmr' ) as $code => $script ) {
		$i = AITP_Languages::info( $code );
		ok( $i['known'], $code );
		eq( $script, $i['script'], $code );
	}
	AITP_Languages::alias( 'bel', 'be' );
	eq( 'Cyrl', AITP_Languages::info( 'bel' )['script'] );
	eq( 'Latn', AITP_Languages::info( 'sr@latin' )['script'] );
	$unknown = AITP_Languages::info( 'xx_YY' );
	ok( ! $unknown['known'] );
	$v = new AITP_Validator( okay_rules() );
	$c = $v->check( 'Our frames', 'Zzz qqq', AITP_Languages::info( 'en_US' ), $unknown );
	ok( ! in_array( 'foreign_script', $c['hard'], true ), 'unknown language: no script check' );
} );

test( 'A08: Urdu, Greek and Khmer full stops count as sentence ends', function () {
	eq( 3, AITP_Text::sentence_count( 'یہ پہلا ہے۔ یہ دوسرا ہے۔ یہ تیسرا ہے۔' ) );
	ok( AITP_Text::sentence_count( "Πρώτη πρόταση\u{037E} Δεύτερη πρόταση\u{037E}" ) >= 2 );
} );

test( 'A14: Simplified in a Traditional target and Japanese without kana are caught', function () {
	has( 'wrong_script', vcheck( 'Free shipping on all orders over 50 dollars', '所有订单满50美元免运费，欢迎选购', 'zh_TW' )['hard'] );
	ok( ! in_array( 'wrong_script', vcheck( 'Free shipping on all orders over 50 dollars', '所有訂單滿50美元免運費，歡迎選購', 'zh_TW' )['hard'], true ) );
	has( 'wrong_script', vcheck( 'Free shipping on all orders over 50 dollars', '所有訂單滿50美元免運費，歡迎選購', 'zh_CN' )['hard'] );
	has( 'no_kana', vcheck( 'Our factory produces sunglasses for global brands', '我们工厂为全球品牌生产太阳镜', 'ja' )['soft'] );
	has( 'wrong_script', vcheck( 'Our factory produces sunglasses', '我们工厂为全球品牌生产太阳镜', 'ko_KR' )['hard'] );
	$i = AITP_Languages::info( 'zh_HK', 'Chinese (Hong Kong)' );
	ok( strpos( $i['name'], 'Traditional' ) !== false, $i['name'] );
} );

/* --- A15: numbers written differently --- */

test( 'A15: 万/千 units, Indian grouping and 12h->24h are not "numbers changed"', function () {
	$h = function ( $s, $t, $l, $sl = 'en_US' ) {
		$v = new AITP_Validator( okay_rules() );
		return $v->check( $s, $t, AITP_Languages::info( $sl ), AITP_Languages::info( $l ) )['hard'];
	};
	ok( ! in_array( 'number_mismatch', $h( '年产量300万副眼镜', 'Annual output of 3,000,000 pairs of glasses', 'en_US', 'zh_CN' ), true ) );
	ok( ! in_array( 'number_mismatch', $h( 'Over 1,500,000 frames shipped', '15,00,000 से अधिक फ्रेम भेजे गए', 'hi_IN' ), true ) );
	ok( ! in_array( 'number_mismatch', $h( 'Open until 5 pm every day', 'Ouvert jusqu\'à 17 h tous les jours', 'fr_FR' ), true ) );
} );

/* --- A07: non-Latin source returned unchanged --- */

test( 'A07: Chinese source returned unchanged into French is untranslated', function () {
	$v = new AITP_Validator( okay_rules() );
	$c = $v->check( '我们的工厂生产高品质太阳镜', '我们的工厂生产高品质太阳镜', AITP_Languages::info( 'zh_CN' ), AITP_Languages::info( 'fr_FR' ) );
	has( 'untranslated', $c['hard'] );
} );

/* --- A09: other languages' own names in a language switcher --- */

test( 'A09: a language switcher entry in its own script may stay unchanged', function () {
	$c = vcheck( 'Español', 'Español', 'ja' );
	ok( ! $c['hard'], json_encode( $c['hard'] ) );
	$c = vcheck( 'Русский', 'Русский', 'ar' );
	ok( ! $c['hard'], json_encode( $c['hard'] ) );
} );

/* --- A13: English target from a non-English source --- */

test( 'A13: English target does not flag claims that are in the Chinese source', function () {
	$v = new AITP_Validator( okay_rules() );
	$c = $v->check( '我们提供两年质保', 'We offer a two-year warranty', AITP_Languages::info( 'zh_CN' ), AITP_Languages::info( 'en_US' ) );
	foreach ( array_merge( $c['hard'], $c['soft'] ) as $code ) {
		ok( strpos( $code, 'added_claim' ) !== 0, $code );
	}
	$c = $v->check( 'Rated first by our customers', '被客户评为第一', AITP_Languages::info( 'en_US' ), AITP_Languages::info( 'zh_CN' ) );
	foreach ( array_merge( $c['hard'], $c['soft'] ) as $code ) {
		ok( $code !== 'added_claim:best', $code );
	}
} );

/* --- A25 / A26 --- */

test( 'A25: English title left in a non-Latin target (apostrophe or full stop changed) is asked again', function () {
	has( 'identical_confirm', vcheck( "Men's Polarized Sunglasses", 'Men’s Polarized Sunglasses', 'ja' )['soft'] );
	has( 'identical_confirm', vcheck( 'Polarized Sunglasses For Men', 'Polarized Sunglasses For Men.', 'ar' )['soft'] );
} );

test( 'A26: lowercase bracket word is only protected when it is a registered shortcode', function () {
	$old = AITP_Text::$shortcodes;
	AITP_Text::$shortcodes = array( 'gallery' );
	$ph = AITP_Text::matches( 'See [gallery] and [optional] notes [/caption]', AITP_Text::placeholder_patterns() );
	AITP_Text::$shortcodes = $old;
	ok( in_array( '[gallery]', $ph, true ), json_encode( $ph ) );
	ok( ! in_array( '[optional]', $ph, true ), json_encode( $ph ) );
} );

/* --- Review of batch 1 (Opus): regressions guarded --- */

test( 'review: a batch cut off on every view is charged to its language and then held', function () {
	$items = array();
	for ( $i = 0; $i < 10; $i++ ) {
		$items[ 'k' . $i ] = "Plain product sentence number $i about our frames";
	}
	$st = new AITP_Memory_State();
	$b  = new AITP_Circuit_Breaker( $st );
	$sent = 0;
	for ( $view = 0; $view < 5; $view++ ) {
		$tr = new AITP_Test_Transport( function () {
			return gemini_text( '{"translations":[{"id":"t1","te', 'MAX_TOKENS' );
		} );
		make_translator( $tr, array(), array( 'breaker' => $b ) )->translate( $items, 'en_US', 'fr_FR', later() );
		$sent += count( $tr->calls );
	}
	ok( ! $b->allow( 'fr_FR' ), 'language held' );
	ok( $b->allow( 'de_DE' ), 'others go on' );
	ok( $sent < 60, (string) $sent );
} );

test( 'review: two refused texts in one batch do not pause the engine', function () {
	$items = array();
	for ( $i = 0; $i < 20; $i++ ) {
		$items[ 'k' . $i ] = "Plain product sentence number $i about our frames";
	}
	$items['k2']  = 'BAD one about frames';
	$items['k15'] = 'BAD two about frames';
	$st = new AITP_Memory_State();
	$tr = new AITP_Test_Transport( function ( $r ) {
		list( $it ) = req_items( $r );
		foreach ( $it as $t ) {
			if ( strpos( $t, 'BAD' ) === 0 ) {
				return http( 400, array( 'error' => array( 'message' => 'Invalid request' ) ) );
			}
		}
		return oai_ok_list( $it );
	} );
	$r = make_translator( $tr, array(), array( 'breaker' => new AITP_Circuit_Breaker( $st ) ), array(), deepseek() )->translate( $items, 'en_US', 'fr_FR', later() );
	eq( 18, count( $r['translations'] ) );
	eq( 'provider_rejected', $r['failed']['k2'] );
	eq( 'provider_rejected', $r['failed']['k15'] );
	ok( empty( $st->state['status'] ) || $st->state['status'] === 'closed', json_encode( $st->state ) );
} );

test( 'review: provider refusing everything costs a few requests, not two per entry', function () {
	$items = array();
	for ( $i = 0; $i < 40; $i++ ) {
		$items[ 'k' . $i ] = "Plain product sentence number $i about our frames";
	}
	$st = new AITP_Memory_State();
	$tr = new AITP_Test_Transport( function () {
		return http( 400, array( 'error' => array( 'message' => 'Content Exists Risk' ) ) );
	} );
	$r = make_translator( $tr, array(), array( 'breaker' => new AITP_Circuit_Breaker( $st ) ), array(), deepseek() )->translate( $items, 'en_US', 'fr_FR', later() );
	ok( count( $tr->calls ) <= 7, (string) count( $tr->calls ) );
	eq( 0, count( $r['translations'] ) );
	eq( 'content_rejected', $r['failed']['k0'] );
} );

test( 'review: "too large" splits without charging the provider', function () {
	$items = array();
	for ( $i = 0; $i < 12; $i++ ) {
		$items[ 'k' . $i ] = "Plain product sentence number $i about our frames";
	}
	$st = new AITP_Memory_State();
	$tr = new AITP_Test_Transport( function ( $r ) {
		list( $it ) = req_items( $r );
		if ( count( $it ) > 3 ) {
			return http( 413, array( 'error' => array( 'message' => 'Request too large' ) ) );
		}
		return oai_ok_list( $it );
	} );
	$r = make_translator( $tr, array(), array( 'breaker' => new AITP_Circuit_Breaker( $st ) ), array(), deepseek() )->translate( $items, 'en_US', 'fr_FR', later() );
	eq( 12, count( $r['translations'] ) );
	ok( empty( $st->state['fails'] ) && ( empty( $st->state['status'] ) || $st->state['status'] === 'closed' ), json_encode( $st->state ) );
} );

test( 'review: Dhivehi is written right to left; words like "AM / PM" are not date formats', function () {
	eq( 'rtl', AITP_Translator::fixed_translation( 'ltr', AITP_Languages::info( 'dv' ) ) );
	foreach ( array( 'AM / PM', 'Mo - Fr', 'S, M, L', 'Y/N', 'A - Z', 'Hi, I' ) as $w ) {
		ok( ! AITP_Text::is_date_format( $w ), $w );
	}
	foreach ( array( 'F j, Y', 'Y-m-d', 'g:i a', 'jS F Y', 'd/m/Y', 'H:i' ) as $f ) {
		ok( AITP_Text::is_date_format( $f ), $f );
	}
} );

test( 'review: a held language does not use up the paused probe', function () {
	$now = 1000;
	$st  = new AITP_Memory_State();
	$b   = new AITP_Circuit_Breaker( $st, function () use ( &$now ) {
		return $now;
	} );
	for ( $i = 0; $i < 3; $i++ ) {
		$b->failure( 'bad_output', null, 'x', 'ur' );
	}
	$b->failure( 'auth' );
	$now += AITP_Circuit_Breaker::PROBE_INTERVAL + 1;
	$st->state['locales']['ur']['until'] = $now + 100;
	ok( ! $b->allow( 'ur' ) );
	ok( $b->allow( 'fr_FR' ), 'probe still available for another language' );
} );
