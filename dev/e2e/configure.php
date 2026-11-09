<?php
/**
 * wp eval-file /e2e/configure.php [gemini|openai]
 * Configures TranslatePress (7 languages, automatic translation with AI Translator) and the plugin.
 */
$api   = isset( $args[0] ) ? $args[0] : 'gemini';
$langs = array( 'en_US', 'fr_FR', 'es_MX', 'ja', 'ar', 'de_DE', 'pt_BR' );
$slugs = array( 'en_US' => 'en', 'fr_FR' => 'fr', 'es_MX' => 'es', 'ja' => 'ja', 'ar' => 'ar', 'de_DE' => 'de', 'pt_BR' => 'pt' );

$trp_settings                          = get_option( 'trp_settings', array() );
$trp_settings['default-language']      = 'en_US';
$trp_settings['translation-languages'] = $langs;
$trp_settings['publish-languages']     = $langs;
$trp_settings['url-slugs']             = $slugs;
$trp_settings['add-subdirectory-to-default-language'] = 'no';
$trp_settings['force-language-to-custom-links']       = 'yes';
update_option( 'trp_settings', $trp_settings );

$mt                              = get_option( 'trp_machine_translation_settings', array() );
$mt['machine-translation']       = 'yes';
$mt['translation-engine']        = 'aitp';
$mt['block-crawlers']            = 'no';
$mt['machine_translation_limit'] = 1000000;
$mt['machine_translation_limit_enabled'] = 'yes';
update_option( 'trp_machine_translation_settings', $mt );

$trp = TRP_Translate_Press::get_trp_instance();
$q   = $trp->get_component( 'query' );
$gt  = $q->get_query_component( 'gettext_table_creation' );
foreach ( $langs as $l ) {
	if ( $l !== 'en_US' ) {
		$q->check_table( 'en_US', $l );
	}
	$gt->check_gettext_table( $l );
}
$q->check_original_table();
$q->check_original_meta_table();
$gt->check_gettext_original_table();
$gt->check_gettext_original_meta_table();
$q->check_machine_translation_lock_table();
flush_rewrite_rules();

$s = array_merge(
	AITP_Settings::defaults(),
	array(
		'provider'        => $api === 'openai' ? 'openai' : 'gemini',
		'preset'          => 'custom',
		'base_url'        => $api === 'openai' ? 'http://mock:8080/v1' : 'http://mock:8080/v1beta',
		'model'           => $api === 'openai' ? 'gpt-4.1-mini' : 'gemini-3.5-flash',
		'api_key_enc'     => AITP_Settings::encrypt( 'test-key-123' ),
		'protected_terms' => "OKAY EYEWEAR",
		'site_context'    => 'B2B manufacturer of kids sunglasses and optical frames.',
		'glossary'        => "tinted lenses => es_MX: micas tintadas | lentes tintados ; !polarizad",
	)
);
update_option( 'aitp_settings', $s );
AITP_Settings::flush();
delete_option( 'aitp_breaker' );
delete_option( 'aitp_caps' );
echo 'configured ' . $api . ' / ' . ( AITP_Settings::is_configured() ? 'ready' : 'NOT READY' ) . "\n";
