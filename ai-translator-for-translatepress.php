<?php
/**
 * Plugin Name:       AI Translator for TranslatePress
 * Plugin URI:        https://github.com/alankongx/ai-translator-for-translatepress
 * Description:       Translation engine for TranslatePress using Google Gemini or any OpenAI-compatible API. Translations are mapped by ID and validated before TranslatePress saves them; failed strings stay untranslated and are retried later instead of being saved wrong.
 * Version:           0.6.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            alankongx
 * Author URI:        https://github.com/alankongx
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       ai-translator-for-translatepress
 * Domain Path:       /languages
 * Update URI:        https://github.com/alankongx/ai-translator-for-translatepress
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'AITP_VERSION' ) ) {
	return; // Another copy is already loaded.
}

define( 'AITP_VERSION', '0.6.3' );
define( 'AITP_FILE', __FILE__ );
define( 'AITP_DIR', plugin_dir_path( __FILE__ ) );
define( 'AITP_URL', plugin_dir_url( __FILE__ ) );
define( 'AITP_TP_ENGINE', 'aitp' );

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
	'wp/class-aitp-schema.php',
	'wp/class-aitp-settings.php',
	'wp/class-aitp-wp-transport.php',
	'wp/class-aitp-wp-stores.php',
	'wp/class-aitp-admin.php',
	'wp/class-aitp-admin-queue.php',
	'wp/class-aitp-admin-audit.php',
	'wp/class-aitp-admin-pages.php',
	'wp/class-aitp-cache-purge.php',
	'wp/class-aitp-audit.php',
	'wp/class-aitp-autofix.php',
	'wp/class-aitp-pagemap.php',
	'wp/class-aitp-journal.php',
	'wp/class-aitp-updater.php',
	'wp/class-aitp-queue.php',
	'wp/class-aitp-plugin.php',
) as $aitp_file ) {
	require_once AITP_DIR . 'includes/' . $aitp_file;
}
unset( $aitp_file );

register_activation_hook( __FILE__, array( 'AITP_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'AITP_Plugin', 'deactivate' ) );

AITP_Plugin::instance()->boot();
