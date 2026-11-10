<?php
/**
 * TranslatePress machine translation engine "AI Translator".
 *
 * TranslatePress saves exactly the keys this engine returns (status "machine translated") and
 * leaves every other string untranslated, to be retried on a later page view. The engine
 * therefore returns only translations that passed validation.
 *
 * Loaded only when TRP_Machine_Translator exists.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AITP_TP_Engine extends TRP_Machine_Translator {

	const ENGINE = 'aitp';

	/**
	 * @param array       $new_strings          key => decoded string (keys preserved).
	 * @param string      $target_language_code TranslatePress language code, e.g. es_MX.
	 * @param string|null $source_language_code Source language code.
	 * @return array key => translation (only validated translations).
	 */
	public function translate_array( $new_strings, $target_language_code, $source_language_code = null ) {
		if ( $source_language_code === null ) {
			$source_language_code = isset( $this->settings['default-language'] ) ? $this->settings['default-language'] : '';
		}
		if ( empty( $new_strings ) || ! is_array( $new_strings ) || ! $this->verify_request_parameters( $target_language_code, $source_language_code ) ) {
			return array();
		}
		$translator = AITP_Plugin::instance()->translator( $this->machine_translator_logger );
		if ( ! $translator ) {
			return array();
		}

		$budget   = AITP_Plugin::instance()->time_budget( $this->get_machine_translation_lock_timeout() );
		$names    = array(
			'source' => $this->english_name( $source_language_code ),
			'target' => $this->english_name( $target_language_code ),
		);
		$result   = $translator->translate( $new_strings, $source_language_code, $target_language_code, microtime( true ) + $budget, $names );

		/**
		 * Fires after a batch was processed (for monitoring).
		 *
		 * @param array  $result Translations, failed (key => reason), flagged, stats.
		 * @param string $target Target language code.
		 */
		do_action( 'aitp_translated_batch', $result, $target_language_code );

		AITP_Journal::record( $new_strings, $result['translations'], $target_language_code );

		return $result['translations'];
	}

	/**
	 * @param string $code TranslatePress language code.
	 * @return string|null
	 */
	private function english_name( $code ) {
		if ( $this->trp_languages && method_exists( $this->trp_languages, 'get_language_names' ) ) {
			$names = $this->trp_languages->get_language_names( array( $code ), 'english_name' );
			if ( is_array( $names ) && ! empty( $names[ $code ] ) ) {
				return (string) $names[ $code ];
			}
		}
		return null;
	}

	/** @return int Strings per call; one call can run several API requests in parallel. */
	public function get_chunk_size() {
		$s = AITP_Settings::get();
		return (int) $s['chunk_size'];
	}

	/**
	 * TranslatePress only checks that a key exists; the real key never leaves this plugin.
	 *
	 * @return string|false
	 */
	public function get_api_key() {
		return AITP_Settings::is_configured() ? 'aitp-configured' : false;
	}

	/** @return array{message:string,error:bool} */
	public function check_api_key_validity() {
		if ( AITP_Settings::is_configured() ) {
			return array(
				'message' => '',
				'error'   => false,
			);
		}
		return array(
			'message' => __( 'AI Translator is not configured yet: add the API key and model under Settings → AI Translator.', 'ai-translator-for-translatepress' ),
			'error'   => true,
		);
	}

	/** @return array Language models handle any language; report the site's languages as supported. */
	public function get_supported_languages() {
		$codes = array_values( (array) $this->machine_translation_codes );
		return array_values( array_unique( array_merge( AITP_Languages::base_codes(), $codes ) ) );
	}

	/**
	 * @param array $languages TranslatePress language codes.
	 * @return array
	 */
	public function get_engine_specific_language_codes( $languages ) {
		$out = array();
		foreach ( (array) $languages as $l ) {
			$out[] = isset( $this->machine_translation_codes[ $l ] ) ? $this->machine_translation_codes[ $l ] : $l;
		}
		return $out;
	}

	/** Not used: the connection test lives on the plugin's own settings page. */
	public function test_request() {
		return array();
	}
}
