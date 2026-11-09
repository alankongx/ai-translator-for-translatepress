<?php
/** wp eval-file /e2e/state.php — plugin state as JSON. */
global $wpdb;
$b       = get_option( 'aitp_breaker', array() );
$reasons = array();
foreach ( $wpdb->get_col( "SELECT data FROM {$wpdb->prefix}aitp_log WHERE event = 'call'" ) as $j ) {
	$d = json_decode( $j, true );
	foreach ( (array) ( $d['reasons'] ?? array() ) as $r => $n ) {
		$reasons[ $r ] = ( $reasons[ $r ] ?? 0 ) + $n;
	}
}
$sent  = 0;
$reqs  = 0;
$trans = 0;
foreach ( $wpdb->get_col( "SELECT data FROM {$wpdb->prefix}aitp_log WHERE event = 'call'" ) as $j ) {
	$d     = json_decode( $j, true );
	$sent += (int) ( $d['sent_bytes'] ?? 0 );
	$reqs  += (int) ( $d['requests'] ?? 0 );
	$trans += (int) ( $d['translated'] ?? 0 );
}
echo wp_json_encode(
	array(
		'breaker'      => array( 'status' => $b['status'] ?? 'closed', 'reason' => $b['reason'] ?? '' ),
		'tp_counter'   => (int) $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'trp_machine_translation_counter'" ),
		'sent_bytes'   => $sent,
		'requests'     => $reqs,
		'translated'   => $trans,
		'failures'     => $wpdb->get_results( "SELECT reason, COUNT(*) n FROM {$wpdb->prefix}aitp_failures GROUP BY reason", ARRAY_A ),
		'fail_reasons' => $reasons,
		'review'       => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}aitp_review" ),
		'caps'         => get_option( 'aitp_caps', array() ),
	)
) . "\n";
