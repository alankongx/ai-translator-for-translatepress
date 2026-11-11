<?php
/**
 * Removes the plugin's own tables and options. TranslatePress data is not touched.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}
require_once __DIR__ . '/includes/wp/class-aitp-schema.php';
AITP_Schema::uninstall();
wp_clear_scheduled_hook( 'aitp_daily' );
wp_clear_scheduled_hook( 'aitp_autofix_tick' );
wp_clear_scheduled_hook( 'aitp_map_tick' );
wp_clear_scheduled_hook( 'aitp_purge_tick' );
wp_clear_scheduled_hook( 'aitp_queue_tick' );
wp_clear_scheduled_hook( 'aitp_audit_tick' );
