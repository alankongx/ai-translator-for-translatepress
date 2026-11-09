<?php
$ids = array( 't1', 't2', 't3' );
$enc = function ( $list ) {
	return json_encode( array( 'translations' => $list ), JSON_UNESCAPED_UNICODE );
};

test( 'protocol: reordered ids map by id', function () use ( $ids, $enc ) {
	$p = AITP_Protocol::parse( $enc( array( array( 'id' => 't3', 'text' => 'C' ), array( 'id' => 't1', 'text' => 'A' ), array( 'id' => 't2', 'text' => 'B' ) ) ), $ids );
	eq( 'ok', $p['status'] );
	eq( array( 't3' => 'C', 't1' => 'A', 't2' => 'B' ), $p['translations'] );
	eq( array(), $p['missing'] );
} );

test( 'protocol: missing id is partial, not shifted', function () use ( $ids, $enc ) {
	$p = AITP_Protocol::parse( $enc( array( array( 'id' => 't1', 'text' => 'A' ), array( 'id' => 't3', 'text' => 'C' ) ) ), $ids );
	eq( 'ok', $p['status'] );
	eq( array( 't2' ), $p['missing'] );
	eq( 'C', $p['translations']['t3'] );
	ok( ! isset( $p['translations']['t2'] ) );
} );

test( 'protocol: duplicate id rejects whole batch', function () use ( $ids, $enc ) {
	$p = AITP_Protocol::parse( $enc( array( array( 'id' => 't1', 'text' => 'A' ), array( 'id' => 't1', 'text' => 'B' ), array( 'id' => 't2', 'text' => 'C' ) ) ), $ids );
	eq( 'id_conflict', $p['status'] );
	eq( array(), $p['translations'] );
} );

test( 'protocol: unknown / extra id rejects whole batch', function () use ( $ids, $enc ) {
	$p = AITP_Protocol::parse( $enc( array( array( 'id' => 't1', 'text' => 'A' ), array( 'id' => 't2', 'text' => 'B' ), array( 'id' => 't3', 'text' => 'C' ), array( 'id' => 't4', 'text' => 'D' ) ) ), $ids );
	eq( 'id_conflict', $p['status'] );
	eq( array( 't4' ), $p['unknown'] );
} );

test( 'protocol: numeric id is invalid structure', function () use ( $ids ) {
	$p = AITP_Protocol::parse( '{"translations":[{"id":1,"text":"A"}]}', $ids );
	eq( 'invalid_structure', $p['status'] );
} );

test( 'protocol: invalid json / plain text / empty', function () use ( $ids ) {
	eq( 'invalid_json', AITP_Protocol::parse( '{"translations":[{"id":"t1","text":"A"}', $ids )['status'] );
	eq( 'invalid_json', AITP_Protocol::parse( 'Voici la traduction : Bonjour', $ids )['status'] );
	eq( 'invalid_json', AITP_Protocol::parse( '', $ids )['status'] );
} );

test( 'protocol: object keyed by index is invalid structure', function () use ( $ids ) {
	eq( 'invalid_structure', AITP_Protocol::parse( '{"0":"A","1":"B","2":"C"}', $ids )['status'] );
	eq( 'invalid_structure', AITP_Protocol::parse( '{"translations":{"t1":"A"}}', $ids )['status'] );
} );

test( 'protocol: whole-answer code fence accepted, top-level list accepted', function () use ( $ids ) {
	$p = AITP_Protocol::parse( "```json\n[{\"id\":\"t1\",\"text\":\"A\"},{\"id\":\"t2\",\"text\":\"B\"},{\"id\":\"t3\",\"text\":\"C\"}]\n```", $ids );
	eq( 'ok', $p['status'] );
	eq( 3, count( $p['translations'] ) );
} );

test( 'protocol: text before json is rejected', function () use ( $ids ) {
	eq( 'invalid_json', AITP_Protocol::parse( "Sure!\n{\"translations\":[]}", $ids )['status'] );
} );

test( 'protocol: user payload keeps ids and separates context', function () {
	$si = AITP_Languages::info( 'en_US' );
	$ti = AITP_Languages::info( 'es_MX' );
	$u  = json_decode( AITP_Protocol::user_payload( array( 't1' => 'Hello', 't2' => 'World' ), $si, $ti, array( 'context_before' => array( 'Prev' ), 'notes' => array( 't2' => 'Keep 20' ) ) ), true );
	eq( array( 't1', 't2' ), array_column( $u['items'], 'id' ) );
	eq( 'Prev', $u['context_before'][0] );
	eq( 't2', $u['notes'][0]['id'] );
	ok( strpos( $u['target_language'], 'Mexico' ) !== false, 'target names region' );
} );

test( 'protocol: system prompt names region variant', function () {
	$s = AITP_Protocol::system_prompt( AITP_Languages::info( 'en_US' ), AITP_Languages::info( 'pt_BR' ) );
	ok( strpos( $s, 'Brazil' ) !== false );
	ok( strpos( $s, 'JSON' ) !== false );
} );
