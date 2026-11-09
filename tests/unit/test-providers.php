<?php
test( 'gemini: key in header, never in URL; schema and thinking set', function () {
	$p = new AITP_Provider_Gemini( array( 'api_key' => 'AIzaSECRETKEY1234567890', 'model' => 'gemini-3.5-flash' ) );
	$r = $p->build_request( 'sys', 'user', 4000, $p->default_caps() );
	ok( strpos( $r['url'], 'AIza' ) === false && strpos( $r['url'], 'key=' ) === false, 'url clean' );
	eq( 'AIzaSECRETKEY1234567890', $r['headers']['x-goog-api-key'] );
	$b = json_decode( $r['body'], true );
	eq( 'application/json', $b['generationConfig']['responseMimeType'] );
	ok( isset( $b['generationConfig']['responseSchema'] ) );
	eq( 'low', $b['generationConfig']['thinkingConfig']['thinkingLevel'] );
	eq( 4000, $b['generationConfig']['maxOutputTokens'] );
} );

test( 'gemini: 2.5-flash uses thinking budget 0', function () {
	$p = new AITP_Provider_Gemini( array( 'api_key' => 'k123456', 'model' => 'gemini-2.5-flash', 'thinking' => 'off' ) );
	$b = json_decode( $p->build_request( 's', 'u', 100, $p->default_caps() )['body'], true );
	eq( 0, $b['generationConfig']['thinkingConfig']['thinkingBudget'] );
} );

test( 'gemini: finish reasons and thought parts', function () {
	$p   = new AITP_Provider_Gemini( array( 'api_key' => 'k123456', 'model' => 'gemini-3.5-flash' ) );
	$res = function ( $parts, $finish ) {
		return json_encode( array( 'candidates' => array( array( 'content' => array( 'parts' => $parts ), 'finishReason' => $finish ) ) ) );
	};
	$r = $p->parse_response( 200, array(), $res( array( array( 'text' => 'thinking...', 'thought' => true ), array( 'text' => '{"a":1}' ) ), 'STOP' ) );
	eq( 'stop', $r['finish'] );
	eq( '{"a":1}', $r['text'] );
	eq( 'length', $p->parse_response( 200, array(), $res( array( array( 'text' => '{"tr' ) ), 'MAX_TOKENS' ) )['finish'] );
	eq( 'safety', $p->parse_response( 200, array(), $res( array(), 'SAFETY' ) )['finish'] );
	eq( 'other', $p->parse_response( 200, array(), $res( array( array( 'text' => 'x' ) ), 'MALFORMED_FUNCTION_CALL' ) )['finish'] );
	eq( 'safety', $p->parse_response( 200, array(), json_encode( array( 'promptFeedback' => array( 'blockReason' => 'SAFETY' ) ) ) )['finish'] );
	eq( 'invalid_response', $p->parse_response( 200, array(), 'not json' )['error'] );
} );

test( 'gemini: error classification and retry delay', function () {
	$p   = new AITP_Provider_Gemini( array( 'api_key' => 'AIzaSECRETKEY1234567890', 'model' => 'gemini-3.5-flash' ) );
	$err = function ( $code, $status, $msg, $details = array() ) {
		return json_encode( array( 'error' => array( 'code' => $code, 'status' => $status, 'message' => $msg, 'details' => $details ) ) );
	};
	eq( 'auth', $p->parse_response( 400, array(), $err( 400, 'INVALID_ARGUMENT', 'API key not valid. Please pass a valid API key.' ) )['error'] );
	$r = $p->parse_response( 429, array(), $err( 429, 'RESOURCE_EXHAUSTED', 'Quota exceeded for metric generate_content_requests_per_minute', array( array( '@type' => 'type.googleapis.com/google.rpc.RetryInfo', 'retryDelay' => '37s' ) ) ) );
	eq( 'rate_limit', $r['error'] );
	eq( 37, $r['retry_after'] );
	eq( 'quota_daily', $p->parse_response( 429, array(), $err( 429, 'RESOURCE_EXHAUSTED', 'Quota exceeded for metric generate_requests_per_model_per_day' ) )['error'] );
	eq( 'not_found', $p->parse_response( 404, array(), $err( 404, 'NOT_FOUND', 'models/gemini-9 is not found' ) )['error'] );
	eq( 'server', $p->parse_response( 503, array(), $err( 503, 'UNAVAILABLE', 'The model is overloaded.' ) )['error'] );
	$r = $p->parse_response( 400, array(), $err( 400, 'INVALID_ARGUMENT', 'Invalid JSON payload received. Unknown name "responseSchema" at generation_config' ), $p->default_caps() );
	eq( 'schema', $r['unsupported'] );
	$r = $p->parse_response( 400, array(), $err( 400, 'INVALID_ARGUMENT', 'Thinking level is not supported for this model.' ), $p->default_caps() );
	eq( 'thinking', $r['unsupported'] );
} );

test( 'provider: redaction removes keys everywhere', function () {
	$p = new AITP_Provider_Gemini( array( 'api_key' => 'AIzaSECRETKEY1234567890', 'model' => 'm' ) );
	$s = $p->redact( 'GET https://x/y?key=AIzaSECRETKEY1234567890&alt=json Authorization: Bearer sk-abcdefghijklmnop other AIzaOTHERKEY99999999' );
	ok( strpos( $s, 'SECRET' ) === false && strpos( $s, 'sk-abc' ) === false && strpos( $s, 'OTHERKEY' ) === false, $s );
} );

test( 'openai: request shape, schema, bearer, max tokens', function () {
	$p = new AITP_Provider_OpenAI( array( 'api_key' => 'sk-test-1234567890', 'model' => 'gpt-4.1-mini', 'base_url' => 'https://api.openai.com/v1/' ) );
	$r = $p->build_request( 'sys', 'user', 3000, $p->default_caps() );
	eq( 'https://api.openai.com/v1/chat/completions', $r['url'] );
	eq( 'Bearer sk-test-1234567890', $r['headers']['Authorization'] );
	$b = json_decode( $r['body'], true );
	eq( 'json_schema', $b['response_format']['type'] );
	eq( 3000, $b['max_completion_tokens'] );
	ok( ! isset( $b['reasoning_effort'] ) );
} );

test( 'openai: relay uses max_tokens, reasoning model gets effort', function () {
	$p = new AITP_Provider_OpenAI( array( 'api_key' => 'k123456', 'model' => 'deepseek-chat', 'base_url' => 'https://api.deepseek.com/v1' ) );
	$b = json_decode( $p->build_request( 's', 'u', 100, $p->default_caps() )['body'], true );
	eq( 100, $b['max_tokens'] );
	$p = new AITP_Provider_OpenAI( array( 'api_key' => 'k123456', 'model' => 'gpt-5-mini' ) );
	$b = json_decode( $p->build_request( 's', 'u', 100, $p->default_caps() )['body'], true );
	eq( 'low', $b['reasoning_effort'] );
} );

test( 'openai: finish reasons, refusal, error inside 200', function () {
	$p   = new AITP_Provider_OpenAI( array( 'api_key' => 'k123456', 'model' => 'gpt-4.1-mini' ) );
	$res = function ( $content, $finish, $extra = array() ) {
		return json_encode( array( 'id' => 'r1', 'choices' => array( array( 'message' => array_merge( array( 'content' => $content ), $extra ), 'finish_reason' => $finish ) ) ) );
	};
	eq( 'stop', $p->parse_response( 200, array(), $res( '{}', 'stop' ) )['finish'] );
	eq( 'length', $p->parse_response( 200, array(), $res( '{"tr', 'length' ) )['finish'] );
	eq( 'safety', $p->parse_response( 200, array(), $res( null, 'stop', array( 'refusal' => 'I cannot' ) ) )['finish'] );
	eq( 'safety', $p->parse_response( 200, array(), $res( '', 'content_filter' ) )['finish'] );
	$r = $p->parse_response( 200, array(), json_encode( array( 'error' => array( 'message' => 'Insufficient balance', 'code' => 402 ) ) ) );
	ok( ! $r['ok'] );
	eq( 'billing', $r['error'] );
	eq( 'billing', $p->parse_response( 429, array(), '{"error":{"type":"insufficient_quota","message":"You exceeded your current quota"}}' )['error'] );
	eq( 'rate_limit', $p->parse_response( 429, array( 'retry-after' => '20' ), '{"error":{"message":"Rate limit reached"}}' )['error'] );
	eq( 20, $p->parse_response( 429, array( 'retry-after' => '20' ), '{"error":{"message":"Rate limit reached"}}' )['retry_after'] );
} );

test( 'openai: unsupported features detected and downgraded', function () {
	$p    = new AITP_Provider_OpenAI( array( 'api_key' => 'k123456', 'model' => 'some-model', 'base_url' => 'https://relay.example.com/v1' ) );
	$caps = $p->default_caps();
	$r    = $p->parse_response( 400, array(), '{"error":{"message":"response_format type json_schema is not supported"}}', $caps );
	eq( 'schema', $r['unsupported'] );
	$caps = $p->downgrade( $caps, 'schema' );
	ok( ! $caps['schema'] && $caps['json'] );
	$caps = $p->downgrade( $caps, 'json' );
	ok( ! $caps['json'] );
	eq( null, $p->downgrade( $caps, 'json' ) );
	$b = json_decode( $p->build_request( 's', 'u', 10, $caps )['body'], true );
	ok( ! isset( $b['response_format'] ) );
} );
