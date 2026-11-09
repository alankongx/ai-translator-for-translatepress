<?php
/** wp eval-file /e2e/create-pages.php — creates/updates the test pages. */
$pages = array();
foreach ( array( '/e2e/.state/pages.json', '/e2e/edge-page.json' ) as $f ) {
	if ( is_file( $f ) ) {
		$pages = array_merge( $pages, json_decode( file_get_contents( $f ), true ) );
	}
}
foreach ( $pages as $p ) {
	if ( isset( $p['html'] ) ) {
		$content = $p['html'];
	} else {
		$content = '';
		foreach ( $p['strings'] as $s ) {
			$s        = esc_html( $s );
			$content .= ( mb_strlen( $s ) < 60 && ! preg_match( '~[.!?]$~', $s ) ) ? "<h3>$s</h3>\n" : "<p>$s</p>\n";
		}
	}
	$existing = get_page_by_path( $p['slug'] );
	$id       = wp_insert_post(
		array(
			'ID'           => $existing ? $existing->ID : 0,
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_name'    => $p['slug'],
			'post_title'   => $p['title'],
			'post_content' => $content,
		)
	);
	echo $p['slug'] . ' => ' . $id . "\n";
}
