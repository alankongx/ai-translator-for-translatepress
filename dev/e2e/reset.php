<?php
/** wp eval-file /e2e/reset.php — empties test translations (TranslatePress dictionaries of the test site) and plugin state. */
global $wpdb;
foreach ( $wpdb->get_col( "SHOW TABLES LIKE '{$wpdb->prefix}trp_dictionary_%'" ) as $t ) {
	$wpdb->query( "TRUNCATE TABLE $t" );
}
foreach ( array( 'trp_machine_translation_locks', 'aitp_cache', 'aitp_failures', 'aitp_review', 'aitp_log' ) as $t ) {
	$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}$t" );
}
update_option( 'trp_machine_translation_counter', 0 );
delete_option( 'aitp_breaker' );
delete_option( 'aitp_caps' );
echo "reset\n";
