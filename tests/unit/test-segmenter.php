<?php
test( 'segmenter: short text is one part with trailing space kept', function () {
	$s = AITP_Segmenter::split( "  Hello world.  ", 100 );
	eq( '  ', $s['lead'] );
	eq( 1, count( $s['parts'] ) );
	eq( 'Hello world.', $s['parts'][0]['text'] );
	eq( '  ', $s['parts'][0]['sep'] );
} );

test( 'segmenter: long paragraph split at sentence ends and rejoined', function () {
	$sent = array();
	for ( $i = 1; $i <= 12; $i++ ) {
		$sent[] = "This is sentence number $i about sunglasses and frames.";
	}
	$text = implode( ' ', $sent );
	$s    = AITP_Segmenter::split( $text, 200 );
	ok( count( $s['parts'] ) > 1 );
	foreach ( $s['parts'] as $p ) {
		ok( strlen( $p['text'] ) <= 200, 'part size' );
		ok( substr( $p['text'], -1 ) === '.', 'ends at sentence' );
	}
	eq( $text, AITP_Segmenter::join( array_column( $s['parts'], 'text' ), $s, 'Latn' ) );
} );

test( 'segmenter: never splits inside inline markup', function () {
	$text = str_repeat( 'Intro text here. ', 8 ) . '<a href="/x">Linked. Sentence. Inside.</a> ' . str_repeat( 'More text here. ', 8 );
	$s    = AITP_Segmenter::split( $text, 120 );
	foreach ( $s['parts'] as $p ) {
		eq( substr_count( $p['text'], '<a ' ), substr_count( $p['text'], '</a>' ), 'balanced' );
	}
	eq( $text, AITP_Segmenter::join( array_column( $s['parts'], 'text' ), $s, 'Latn' ) );
} );

test( 'segmenter: splits after block tags', function () {
	$text = '<p>' . str_repeat( 'First paragraph text. ', 6 ) . '</p><p>' . str_repeat( 'Second paragraph text. ', 6 ) . '</p>';
	$s    = AITP_Segmenter::split( $text, 200 );
	eq( 2, count( $s['parts'] ) );
	ok( substr( $s['parts'][0]['text'], -4 ) === '</p>' );
} );

test( 'segmenter: CJK join drops spaces between sentences', function () {
	$s = AITP_Segmenter::split( str_repeat( 'One sentence here. ', 20 ), 100 );
	$t = array_fill( 0, count( $s['parts'] ), '文です。' );
	ok( strpos( rtrim( AITP_Segmenter::join( $t, $s, 'Jpan' ) ), ' ' ) === false );
} );
