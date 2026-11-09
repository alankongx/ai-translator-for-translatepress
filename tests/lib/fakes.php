<?php
/**
 * Fake transport and response builders.
 */
class AITP_Test_Transport implements AITP_Transport {
	public $calls = array();
	private $responder;
	public function __construct( $responder ) {
		$this->responder = $responder;
	}
	public function send( array $requests, $concurrency ) {
		$out = array();
		foreach ( $requests as $id => $r ) {
			$this->calls[] = $r;
			$out[ $id ]    = call_user_func( $this->responder, $r, count( $this->calls ) );
		}
		return $out;
	}
}

/** Items and notes from a Gemini request. */
function req_items( array $r ) {
	$body    = json_decode( $r['body'], true );
	$user    = isset( $body['contents'] ) ? $body['contents'][0]['parts'][0]['text'] : $body['messages'][1]['content'];
	$payload = json_decode( $user, true );
	$items   = array();
	foreach ( $payload['items'] as $it ) {
		$items[ $it['id'] ] = $it['text'];
	}
	$notes = array();
	if ( ! empty( $payload['notes'] ) ) {
		foreach ( $payload['notes'] as $n ) {
			$notes[ $n['id'] ] = $n['note'];
		}
	}
	return array( $items, $notes, $payload );
}

function http( $status, $body, $headers = array() ) {
	return array( 'status' => $status, 'headers' => $headers, 'body' => is_string( $body ) ? $body : json_encode( $body, JSON_UNESCAPED_UNICODE ), 'error' => null, 'error_type' => null, 'duration_ms' => 3 );
}

function gemini_text( $text, $finish = 'STOP' ) {
	return http( 200, array( 'candidates' => array( array( 'content' => array( 'parts' => array( array( 'text' => $text ) ) ), 'finishReason' => $finish ) ), 'usageMetadata' => array( 'promptTokenCount' => 10, 'candidatesTokenCount' => 10 ) ) );
}

/** Gemini response from id => translation (order preserved as given). */
function gemini_ok( array $translations, $finish = 'STOP' ) {
	$list = array();
	foreach ( $translations as $id => $t ) {
		$list[] = array( 'id' => (string) $id, 'text' => $t );
	}
	return gemini_text( json_encode( array( 'translations' => $list ), JSON_UNESCAPED_UNICODE ), $finish );
}

/**
 * Pseudo-translation for Latin targets: reverses ordinary words, keeps tags, numbers,
 * codes, all-caps words and protected terms. Passes every structural check.
 */
function pseudo( $text ) {
	$out = '';
	foreach ( AITP_Markup::tokenize( $text ) as $t ) {
		if ( $t['type'] !== 'text' ) {
			$out .= $t['value'];
			continue;
		}
		$out .= preg_replace_callback(
			'~\p{L}+~u',
			function ( $m ) {
				$w = $m[0];
				if ( preg_match( '~^\p{Lu}+$~u', $w ) || mb_strlen( $w ) < 3 ) {
					return $w;
				}
				$r = implode( '', array_reverse( preg_split( '~~u', $w, -1, PREG_SPLIT_NO_EMPTY ) ) );
				return $r === $w ? $w . 'x' : $r;
			},
			$t['value']
		);
	}
	return $out;
}

function make_translator( $transport, array $opt = array(), array $deps = array(), array $rules_cfg = array(), $provider = null ) {
	$rules                  = new AITP_Rules();
	$rules->protected_terms = isset( $rules_cfg['terms'] ) ? $rules_cfg['terms'] : array( 'OKAY EYEWEAR' );
	$rules->patterns        = AITP_Rules::default_patterns();
	if ( isset( $rules_cfg['glossary'] ) ) {
		$g              = AITP_Rules::parse_glossary( $rules_cfg['glossary'] );
		$rules->glossary = $g['rules'];
	}
	$provider = $provider ? $provider : new AITP_Provider_Gemini( array( 'api_key' => 'test-key-123456', 'model' => 'gemini-3.5-flash' ) );
	$deps     = array_merge( array( 'transport' => $transport ), $deps );
	return new AITP_Translator( $provider, $rules, $deps, $opt );
}

function okay_rules() {
	$rules                  = new AITP_Rules();
	$rules->protected_terms = array( 'OKAY EYEWEAR' );
	$rules->patterns        = AITP_Rules::default_patterns();
	$g                      = AITP_Rules::parse_glossary( "tinted lenses => es_MX: micas tintadas | lentes tintados ; !polarizad\nflexible => ar: مرن | مرنة ; !مقوى | !مقواة | !معزز" );
	$rules->glossary        = $g['rules'];
	return $rules;
}

function vcheck( $src, $tgt, $tl, $rules = null ) {
	$v = new AITP_Validator( $rules ? $rules : okay_rules() );
	return $v->check( $src, $tgt, AITP_Languages::info( 'en_US' ), AITP_Languages::info( $tl ) );
}
