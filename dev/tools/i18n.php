<?php
/**
 * Interface translations without WP-CLI.
 *
 *   php dev/tools/i18n.php missing   Strings in the code that the zh_CN .po does not have yet (JSON).
 *   php dev/tools/i18n.php build     .po -> .mo (msgfmt) and .l10n.php (WordPress 6.5+).
 *
 * New strings are added to the .po by hand (msgid / msgstr at the end), then "build".
 * Not shipped in the release zip (dev/ is excluded).
 */

$root   = dirname( __DIR__, 2 );
$domain = 'ai-translator-for-translatepress';
$po     = "$root/languages/$domain-zh_CN.po";
$cmd    = isset( $argv[1] ) ? $argv[1] : '';

function po_unescape( $s ) {
	return stripcslashes( $s );
}

/** @return array{headers:string,messages:array<string,string>} */
function po_read( $file ) {
	$text = (string) file_get_contents( $file );
	$out  = array( 'headers' => '', 'messages' => array() );
	preg_match_all( '~^msgid ((?:".*"\R?)+)msgstr ((?:".*"\R?)+)~m', $text, $m, PREG_SET_ORDER );
	foreach ( $m as $e ) {
		$join = function ( $block ) {
			preg_match_all( '~"(.*)"~', $block, $p );
			return po_unescape( implode( '', $p[1] ) );
		};
		$id  = $join( $e[1] );
		$str = $join( $e[2] );
		if ( $id === '' ) {
			$out['headers'] = $str;
		} elseif ( $str !== '' ) {
			$out['messages'][ $id ] = $str;
		}
	}
	return $out;
}

function code_strings( $root, $domain ) {
	$files = array( "$root/ai-translator-for-translatepress.php" );
	$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( "$root/includes" ) );
	foreach ( $it as $f ) {
		if ( substr( (string) $f, -4 ) === '.php' ) {
			$files[] = (string) $f;
		}
	}
	$found = array();
	foreach ( $files as $f ) {
		$src = (string) file_get_contents( $f );
		preg_match_all( "~\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*'((?:[^'\\\\]|\\\\.)*)'\s*,\s*'" . preg_quote( $domain, '~' ) . "'~", $src, $m );
		foreach ( $m[1] as $s ) {
			$found[ str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $s ) ] = str_replace( $root . '/', '', $f );
		}
	}
	return $found;
}

if ( $cmd === 'missing' ) {
	$have = po_read( $po )['messages'];
	$miss = array();
	foreach ( code_strings( $root, $domain ) as $s => $file ) {
		if ( ! isset( $have[ $s ] ) ) {
			$miss[ $s ] = $file;
		}
	}
	echo json_encode( $miss, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), "\n";
	exit( 0 );
}

if ( $cmd === 'build' ) {
	$data = po_read( $po );
	$head = array( 'domain' => $domain );
	foreach ( preg_split( '~\n~', $data['headers'] ) as $line ) {
		if ( preg_match( '~^([A-Za-z-]+):\s*(.*)$~', $line, $h ) ) {
			$k = strtolower( $h[1] );
			if ( in_array( $k, array( 'plural-forms', 'language', 'project-id-version', 'pot-creation-date', 'po-revision-date', 'x-generator' ), true ) ) {
				$head[ $k ] = $h[2];
			}
		}
	}
	$head['messages'] = $data['messages'];
	file_put_contents( "$root/languages/$domain-zh_CN.l10n.php", "<?php\nreturn " . var_export( $head, true ) . ";\n" );
	passthru( 'msgfmt -c -o ' . escapeshellarg( "$root/languages/$domain-zh_CN.mo" ) . ' ' . escapeshellarg( $po ), $rc );
	echo 'messages: ', count( $data['messages'] ), ( $rc ? " (msgfmt failed: $rc)" : '' ), "\n";
	exit( $rc );
}

fwrite( STDERR, "usage: php dev/tools/i18n.php missing|build\n" );
exit( 2 );
