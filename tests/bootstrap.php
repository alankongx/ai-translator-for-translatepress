<?php
/**
 * Test bootstrap: loads the WordPress-independent core without WordPress.
 */
define( 'ABSPATH', __DIR__ . '/' );
error_reporting( E_ALL );
ini_set( 'display_errors', '1' );

$aitp_root = dirname( __DIR__ ) . '/includes/';
foreach ( array(
	'core/class-aitp-languages.php',
	'core/class-aitp-text.php',
	'core/class-aitp-markup.php',
	'core/class-aitp-rules.php',
	'core/class-aitp-claims.php',
	'core/class-aitp-validator.php',
	'core/class-aitp-segmenter.php',
	'core/class-aitp-batcher.php',
	'core/class-aitp-protocol.php',
	'core/class-aitp-contracts.php',
	'core/class-aitp-circuit-breaker.php',
	'core/class-aitp-translator.php',
	'core/class-aitp-queue-logic.php',
	'core/class-aitp-audit-logic.php',
	'core/class-aitp-autofix-logic.php',
	'core/class-aitp-pair-checker.php',
	'providers/class-aitp-provider.php',
	'providers/class-aitp-provider-gemini.php',
	'providers/class-aitp-provider-openai.php',
) as $f ) {
	require_once $aitp_root . $f;
}
require_once __DIR__ . '/lib/harness.php';
require_once __DIR__ . '/lib/fakes.php';
