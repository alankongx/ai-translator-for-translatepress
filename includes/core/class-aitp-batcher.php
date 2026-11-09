<?php
/**
 * Groups units into request batches and sizes the output budget.
 *
 * Character, byte and token are different units:
 *  - TranslatePress counts its daily limit in bytes (strlen) of the strings sent.
 *  - Providers bill tokens, including the prompt, context and any "thinking".
 *  - Batches are sized by an estimated token count (bytes / 3, conservative).
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Batcher {

	/**
	 * @param array<string,string> $texts      uid => text, in order.
	 * @param int                  $max_tokens Estimated input tokens per batch.
	 * @param int                  $max_items  Items per batch.
	 * @return array<int,string[]>
	 */
	public static function build( array $texts, $max_tokens, $max_items ) {
		$batches = array();
		$cur     = array();
		$tok     = 0;
		foreach ( $texts as $uid => $text ) {
			$t = AITP_Text::estimate_tokens( $text ) + 8;
			if ( $cur && ( $tok + $t > $max_tokens || count( $cur ) >= $max_items ) ) {
				$batches[] = $cur;
				$cur       = array();
				$tok       = 0;
			}
			$cur[] = (string) $uid;
			$tok  += $t;
		}
		if ( $cur ) {
			$batches[] = $cur;
		}
		return $batches;
	}

	/**
	 * Output token budget: room for the translation, JSON overhead and reasoning.
	 *
	 * @param array<string,string> $texts   Texts in the batch.
	 * @param array                $ti      Target info.
	 * @param int                  $reserve Thinking reserve.
	 * @param int                  $cap     Upper limit.
	 * @return int
	 */
	public static function max_output_tokens( array $texts, array $ti, $reserve, $cap ) {
		$in = 0;
		foreach ( $texts as $t ) {
			$in += AITP_Text::estimate_tokens( $t );
		}
		$mult = in_array( 'Latin', $ti['scripts'], true ) || in_array( 'Cyrillic', $ti['scripts'], true ) ? 1.6 : 2.2;
		$est  = (int) ceil( $in * $mult ) + 30 * count( $texts ) + 64;
		return (int) min( (int) $cap, max( 2048, $est ) + (int) $reserve );
	}
}
