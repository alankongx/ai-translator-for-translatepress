<?php
/**
 * Checks one translation against its source.
 *
 * Hard problems reject the translation (it is retried, never saved). Soft problems
 * trigger one corrective retry and then, depending on settings, either save the
 * translation and put it on the review list or keep it unsaved.
 *
 * These checks catch structural damage, misalignment, truncation, untranslated text and
 * a few known meaning shifts. They cannot prove a translation is correct; human review
 * is still required for quality.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Validator {

	/** Soft code meaning "identical to the source, ask the model once to confirm". */
	const CONFIRM_IDENTICAL = 'identical_confirm';

	/**
	 * Soft review flag: returned unchanged, and the text itself already reads like the target
	 * language (TranslatePress stores some originals in other languages, e.g. language
	 * switcher labels). Asked once like CONFIRM_IDENTICAL, then kept for a person to look at.
	 */
	const ALREADY_TARGET = 'already_target';

	/** @var AITP_Rules */
	private $rules;

	/**
	 * Distinctive English function words (3+ letters) used to spot untranslated English
	 * in Latin-script targets.
	 *
	 * @var string[]
	 */
	private static $en_function_words = array(
		'the', 'and', 'with', 'your', 'our', 'you', 'this', 'that', 'these', 'those', 'which', 'from',
		'have', 'has', 'will', 'would', 'should', 'could', 'their', 'they', 'them', 'what', 'when',
		'where', 'while', 'into', 'than', 'then', 'there', 'here', 'about', 'after', 'before',
		'because', 'without', 'between', 'through', 'during', 'also', 'only', 'more', 'most', 'other',
		'such', 'each', 'every', 'both', 'very', 'just', 'been', 'being', 'were', 'was', 'are', 'its',
		'can', 'all', 'any', 'some', 'how', 'why', 'who', 'not', 'for', 'her', 'she', 'his', 'out',
		'over', 'under', 'does', 'did',
	);

	/**
	 * Words from the list above that are also ordinary words in the target language.
	 *
	 * @var array<string,string[]>
	 */
	private static $en_exempt = array(
		'de' => array( 'was', 'will', 'also' ),
		'nl' => array( 'was', 'over', 'been' ),
		'sv' => array( 'for', 'over', 'under' ),
		'da' => array( 'for', 'over', 'under' ),
		'nb' => array( 'for', 'over', 'under' ),
		'nn' => array( 'for', 'over', 'under' ),
		'no' => array( 'for', 'over', 'under' ),
		'pt' => array( 'for' ),
		'es' => array( 'has' ),
		'ro' => array( 'are' ),
		'it' => array( 'are' ),
		'tr' => array( 'her' ),
	);

	/**
	 * Short, frequent words that mark text as written in a language (two letters or more,
	 * none of them an ordinary English word), and that language's accented letters.
	 * Used only to recognise text that is already in the target language.
	 *
	 * @var array<string,array{words:string[],letters:string}>
	 */
	private static $markers = array(
		'fr' => array(
			'words'   => array( 'le', 'la', 'les', 'des', 'du', 'de', 'et', 'pour', 'avec', 'sur', 'dans', 'une', 'un', 'au', 'aux', 'est', 'sont', 'vos', 'votre', 'nos', 'notre', 'sans', 'ou', 'qui', 'que', 'nous', 'vous', 'ces', 'cette', 'leur', 'leurs', 'en' ),
			'letters' => 'àâæçéèêëîïôœùûüÿ',
		),
		'de' => array(
			'words'   => array( 'der', 'die', 'das', 'den', 'dem', 'des', 'und', 'für', 'mit', 'von', 'zu', 'zum', 'zur', 'ein', 'eine', 'einen', 'ist', 'sind', 'sie', 'wir', 'ihr', 'ihre', 'auf', 'bei', 'nach', 'oder', 'nicht', 'unser', 'unsere' ),
			'letters' => 'äöüß',
		),
		'es' => array(
			'words'   => array( 'el', 'la', 'los', 'las', 'del', 'de', 'para', 'con', 'por', 'en', 'una', 'un', 'su', 'sus', 'es', 'nuestro', 'nuestra', 'nuestros', 'que', 'más' ),
			'letters' => 'áéíóúñü¿¡',
		),
		'pt' => array(
			'words'   => array( 'de', 'da', 'dos', 'das', 'para', 'por', 'em', 'uma', 'seu', 'sua', 'seus', 'suas', 'nosso', 'nossa', 'ao', 'ou', 'que', 'mais' ),
			'letters' => 'ãõçáéíóúâêôà',
		),
		'it' => array(
			'words'   => array( 'il', 'la', 'gli', 'le', 'di', 'del', 'della', 'dei', 'con', 'una', 'un', 'che', 'sono', 'nostro', 'nostra' ),
			'letters' => 'àèéìòù',
		),
		'nl' => array(
			'words'   => array( 'de', 'het', 'een', 'en', 'uw', 'onze', 'naar', 'bij', 'niet' ),
			'letters' => 'ëï',
		),
	);

	/**
	 * @param AITP_Rules $rules Site rules.
	 */
	public function __construct( AITP_Rules $rules ) {
		$this->rules = $rules;
	}

	/**
	 * @param string $source Source string as sent to the engine.
	 * @param mixed  $target Candidate translation.
	 * @param array  $si     Source language info.
	 * @param array  $ti     Target language info.
	 * @return array{hard:string[],soft:string[],details:array<string,mixed>}
	 */
	public function check( $source, $target, array $si, array $ti ) {
		$hard    = array();
		$soft    = array();
		$details = array();

		if ( ! is_string( $target ) || trim( $target ) === '' ) {
			return self::result( array( 'empty' ), array(), array() );
		}

		$source = (string) $source;

		if ( preg_match( '~[\x00-\x08\x0B\x0C\x0E-\x1F]~', $target ) && ! preg_match( '~[\x00-\x08\x0B\x0C\x0E-\x1F]~', $source ) ) {
			$hard[] = 'control_chars';
		}

		$leak = '~```|"translations"\s*:|\{\s*"id"\s*:~u';
		if ( preg_match( $leak, $target ) && ! preg_match( $leak, $source ) ) {
			$hard[] = 'format_leak';
		}

		// 1. Markup structure.
		$s_sig = AITP_Markup::signatures( $source );
		$t_sig = AITP_Markup::signatures( $target );
		if ( $s_sig !== $t_sig ) {
			$hard[]             = 'markup_mismatch';
			$details['markup'] = array(
				'missing' => array_slice( AITP_Text::missing_items( $s_sig, $t_sig ), 0, 5 ),
				'extra'   => array_slice( AITP_Text::missing_items( $t_sig, $s_sig ), 0, 5 ),
			);
		} elseif ( $s_sig && AITP_Markup::is_balanced( $source ) && ! AITP_Markup::is_balanced( $target ) ) {
			$hard[] = 'markup_unbalanced';
		}

		foreach ( AITP_Markup::no_translate_texts( $source ) as $inner ) {
			if ( strpos( $target, $inner ) === false ) {
				$hard[] = 'no_translate_changed';
				break;
			}
		}

		$s_text = AITP_Text::normalize_punct( AITP_Text::decode_entities( AITP_Markup::text_only( $source ) ) );
		$t_text = AITP_Text::normalize_punct( AITP_Text::decode_entities( AITP_Markup::text_only( $target ) ) );

		// 2. Placeholders, URLs and e-mail addresses.
		$ph  = AITP_Text::placeholder_patterns();
		$s_p = AITP_Text::matches( $s_text, $ph );
		$t_p = AITP_Text::matches( $t_text, $ph );
		if ( ! AITP_Text::same_multiset( $s_p, $t_p ) ) {
			$hard[]                   = 'placeholder_mismatch';
			$details['placeholders'] = array_slice( AITP_Text::missing_items( $s_p, $t_p ), 0, 5 );
		}
		$links = array( AITP_Text::URL_REGEX, AITP_Text::EMAIL_REGEX );
		foreach ( array_unique( AITP_Text::matches( $s_text, $links ) ) as $link ) {
			if ( strpos( $t_text, $link ) === false ) {
				$hard[]           = 'link_changed';
				$details['link'] = $link;
				break;
			}
		}

		// 3. Protected terms (brands, product names).
		$terms   = array();
		$missing = array();
		foreach ( $this->rules->protected_terms as $term ) {
			$term = trim( (string) $term );
			if ( $term === '' ) {
				continue;
			}
			$cs = AITP_Text::count_term( $s_text, $term );
			if ( $cs === 0 ) {
				continue;
			}
			$terms[] = $term;
			$ct      = AITP_Text::count_term( $t_text, $term );
			if ( $ct < $cs ) {
				$missing[] = $term;
			} elseif ( $ct > $cs ) {
				$soft[] = 'brand_added';
			}
		}
		if ( $missing ) {
			$hard[]                    = 'protected_term_missing';
			$details['missing_terms'] = $missing;
		}

		// 3b. Ordinary words written like the brand (e.g. "EYEWEAR" from "OKAY EYEWEAR").
		$case_hits = $this->brand_case_hits( $s_text, $t_text );
		if ( $case_hits ) {
			$hard[]                 = 'brand_case';
			$details['brand_case'] = $case_hits;
		}

		// 4. Protected patterns and codes.
		$needles = AITP_Text::matches( $s_text, $this->rules->patterns );
		if ( $this->rules->code_guard ) {
			$needles = array_merge( $needles, AITP_Rules::extract_codes( $s_text ) );
		}
		$needles     = array_values( array_unique( $needles ) );
		$missing_pat = array();
		foreach ( $needles as $needle ) {
			if ( AITP_Text::count_term( $t_text, $needle ) === 0 && strpos( $t_text, $needle ) === false ) {
				$missing_pat[] = $needle;
			}
		}
		if ( $missing_pat ) {
			$hard[]                       = 'protected_pattern_missing';
			$details['missing_patterns'] = array_slice( $missing_pat, 0, 5 );
		}

		if ( $this->rules->size_guard && AITP_Rules::is_size_string( $s_text ) && AITP_Text::squash( $s_text ) !== AITP_Text::squash( $t_text ) ) {
			$hard[] = 'size_changed';
		}

		// Text used for language analysis: everything that legitimately stays unchanged is removed.
		$s_an = $this->analysis_text( $s_text, $terms, $needles );
		$t_an = $this->analysis_text( $t_text, $terms, $needles );

		// 5. Numbers. 万/千/亿 are written out on the CJK side ("1万" = "10,000"); a time that
		// only moved from 12- to 24-hour writing ("5pm" → "17 Uhr") is not a lost number.
		$cjk   = array( 'ja', 'zh', 'ko' );
		$s_num = array_values( array_unique( AITP_Text::numbers( in_array( $si['base'], $cjk, true ) ? AITP_Text::expand_cjk_units( $s_an ) : $s_an ) ) );
		$t_num = AITP_Text::numbers( in_array( $ti['base'], $cjk, true ) ? AITP_Text::expand_cjk_units( $t_an ) : $t_an );
		$lost  = array_values( array_diff( $s_num, $t_num ) );
		if ( $lost && preg_match( '~\d\s*(?:p\.?\s?m\b|p\.m\.)~i', $s_an ) ) {
			$lost = array_values(
				array_filter(
					$lost,
					function ( $n ) use ( $t_num ) {
						return ! ( (int) $n >= 1 && (int) $n <= 12 && in_array( (string) ( (int) $n + 12 ), $t_num, true ) );
					}
				)
			);
		}
		if ( $lost ) {
			if ( in_array( $ti['base'], array( 'ja', 'zh', 'ko' ), true ) && preg_match( '~[万億亿兆千百十萬]~u', $t_an ) ) {
				$soft[] = 'number_format';
			} else {
				$hard[] = 'number_mismatch';
			}
			$details['missing_numbers'] = array_slice( $lost, 0, 5 );
		}

		// 6. Writing systems. Skipped for a language this plugin does not know (its writing
		// system is unknown). A short name in a third writing system returned as is (a language
		// switcher's "العربية" on an English site) is not left-over source text.
		$s_scripts = AITP_Text::script_counts( $s_an );
		$t_scripts = AITP_Text::script_counts( $t_an );
		$identical = AITP_Text::fold( $s_text ) === AITP_Text::fold( $t_text );
		$short     = count( preg_split( '~\s+~u', trim( $s_an ), -1, PREG_SPLIT_NO_EMPTY ) ) <= 3;
		foreach ( empty( $ti['known'] ) ? array() : $t_scripts as $script => $n ) {
			if ( $script === 'Latin' || in_array( $script, $ti['scripts'], true ) ) {
				continue;
			}
			if ( $identical && $short && ! in_array( $script, $si['scripts'], true ) ) {
				continue;
			}
			if ( empty( $s_scripts[ $script ] ) ) {
				$hard[]                    = 'foreign_script';
				$details['foreign_script'] = $script;
				break;
			}
			if ( $n >= max( 4, 0.2 * $s_scripts[ $script ] ) ) {
				$hard[]                   = 'source_residue';
				$details['residue_script'] = $script;
				break;
			}
		}

		// 6b. Traditional / Simplified Chinese written in the other form; Korean without Hangul.
		$wrong = self::wrong_script( $t_an, $ti, $t_scripts, $identical );
		if ( $wrong !== '' ) {
			if ( $wrong === 'no_kana' ) {
				$soft[] = $wrong;
			} else {
				$hard[] = $wrong;
			}
		}

		// 7. Untranslated source text.
		$untranslated = $this->untranslated( $s_text, $t_text, $s_an, $t_an, $si, $ti );
		if ( $untranslated === self::CONFIRM_IDENTICAL ) {
			$soft[] = self::CONFIRM_IDENTICAL;
		} elseif ( $untranslated === self::ALREADY_TARGET ) {
			$soft[] = self::CONFIRM_IDENTICAL;
			$soft[] = self::ALREADY_TARGET;
		} elseif ( $untranslated !== '' ) {
			$hard[] = $untranslated;
		}

		// 8. Length and completeness.
		$sl = AITP_Text::letter_count( $s_an );
		$tl = AITP_Text::letter_count( $t_an );
		if ( $sl >= 3 && $tl === 0 && $s_an !== '' && ! $needles && ! $terms ) {
			$hard[] = 'no_letters';
		} elseif ( $sl >= 20 ) {
			$expected = $sl * ( $ti['factor'] / max( 0.1, $si['factor'] ) );
			$ratio    = $tl / max( 1, $expected );
			$min      = $sl >= 80 ? 0.45 : 0.35;
			if ( $ratio < $min ) {
				$hard[] = 'too_short';
			} elseif ( $ratio > 3.0 && $tl > $expected + 30 ) {
				$hard[] = 'too_long';
			}
			$details['length_ratio'] = round( $ratio, 2 );
		}
		if ( $sl >= 200 && ! in_array( $ti['script'], array( 'Thai', 'Laoo', 'Khmr' ), true ) ) {
			$ss = AITP_Text::sentence_count( $s_an );
			$ts = AITP_Text::sentence_count( $t_an );
			if ( $ss >= 4 && $ts < (int) ceil( $ss * 0.5 ) ) {
				$hard[]                = 'sentences_missing';
				$details['sentences'] = array( $ss, $ts );
			}
		}

		// 9. Glossary (soft).
		foreach ( $this->rules->glossary_for( $s_text, $ti ) as $rule ) {
			if ( $rule['require'] ) {
				$ok = false;
				foreach ( $rule['require'] as $form ) {
					if ( AITP_Rules::contains_form( $t_text, $form ) ) {
						$ok = true;
						break;
					}
				}
				if ( ! $ok ) {
					$soft[]                  = 'glossary_required';
					$details['glossary'][] = $rule['term'];
				}
			}
			foreach ( $rule['forbid'] as $form ) {
				if ( AITP_Rules::contains_form( $t_text, $form ) && ! AITP_Rules::contains_form( $s_text, $form ) ) {
					$soft[]                  = 'glossary_forbidden';
					$details['glossary'][] = $rule['term'] . ' !' . $form;
				}
			}
		}

		// 10. Added claims (soft).
		if ( $this->rules->claim_checks ) {
			$t_con = AITP_Claims::concepts_in( $t_an, $ti['base'], true );
			$s_con = AITP_Claims::concepts_in( $s_an, $si['base'] );
			if ( is_array( $t_con ) && is_array( $s_con ) ) {
				foreach ( array_diff( $t_con, $s_con ) as $concept ) {
					$soft[] = 'added_claim:' . $concept;
				}
			}
		}

		return self::result( $hard, $soft, $details );
	}

	/**
	 * @param string   $text    Plain text.
	 * @param string[] $terms   Protected terms present in the source.
	 * @param string[] $needles Protected pattern matches and codes.
	 * @return string
	 */
	private function analysis_text( $text, array $terms, array $needles ) {
		$text = AITP_Text::remove( $text, array_merge( AITP_Text::placeholder_patterns(), array( AITP_Text::URL_REGEX, AITP_Text::EMAIL_REGEX ) ) );
		foreach ( $terms as $t ) {
			$text = AITP_Text::remove_term( $text, $t );
		}
		usort(
			$needles,
			function ( $a, $b ) {
				return strlen( $b ) - strlen( $a );
			}
		);
		foreach ( $needles as $n ) {
			$text = str_replace( $n, ' ', $text );
		}
		return trim( (string) preg_replace( '~\s+~u', ' ', $text ) );
	}

	/**
	 * Detect ordinary words written in a multi-word brand's capitals.
	 *
	 * @param string $s_text Source text.
	 * @param string $t_text Target text.
	 * @return string[]
	 */
	private function brand_case_hits( $s_text, $t_text ) {
		$hits = array();
		foreach ( $this->rules->protected_terms as $term ) {
			$words = preg_split( '~\s+~u', trim( (string) $term ) );
			if ( count( $words ) < 2 ) {
				continue;
			}
			$s_rest = AITP_Text::remove_term( $s_text, $term );
			$t_rest = AITP_Text::remove_term( $t_text, $term );
			foreach ( $words as $w ) {
				if ( AITP_Text::len( $w ) < 4 || ! preg_match( '~^[\p{Lu}\p{N}&.-]+$~u', $w ) || ! preg_match( '~\p{Lu}~u', $w ) ) {
					continue;
				}
				if ( AITP_Text::count_term( $t_rest, $w ) > AITP_Text::count_term( $s_rest, $w ) ) {
					$hits[] = $w;
				}
			}
		}
		return array_values( array_unique( $hits ) );
	}

	/**
	 * Whether a capitalised English text reads like a headline or question rather than a name:
	 * ends with ? or !, has two or more function words ("How … Your …"), or seven words or more.
	 *
	 * @param string $s Text.
	 * @return bool
	 */
	private function sentence_like( $s ) {
		if ( preg_match( '~[?!؟]\s*$~u', $s ) ) {
			return true;
		}
		$all = preg_match_all( '~\p{L}+~u', $s, $m ) ? $m[0] : array();
		if ( count( $all ) >= 7 ) {
			return true;
		}
		$fw = 0;
		foreach ( $all as $w ) {
			if ( in_array( AITP_Text::lower( $w ), self::$en_function_words, true ) ) {
				$fw++;
			}
		}
		return $fw >= 2;
	}

	/**
	 * Whether a text shows clear signs of being written in the target language already and
	 * none of being English when the source language is English.
	 *
	 * Latin-script targets: at least one marker word or accented letter of the target language
	 * and no distinctive English function word. Other targets: more letters in the target's
	 * writing system than Latin letters, when the source language uses a different one.
	 *
	 * @param string $s  Source text without markup and protected terms.
	 * @param array  $si Source language info.
	 * @param array  $ti Target language info.
	 * @return bool
	 */
	private function already_in_target( $s, array $si, array $ti ) {
		if ( ! in_array( 'Latin', $ti['scripts'], true ) ) {
			if ( array_intersect( $si['scripts'], $ti['scripts'] ) ) {
				return false;
			}
			$counts = AITP_Text::script_counts( $s );
			$own    = 0;
			foreach ( $ti['scripts'] as $sc ) {
				$own += isset( $counts[ $sc ] ) ? $counts[ $sc ] : 0;
			}
			return $own > 0 && $own > ( isset( $counts['Latin'] ) ? $counts['Latin'] : 0 );
		}
		if ( ! isset( self::$markers[ $ti['base'] ] ) || ! in_array( 'Latin', $si['scripts'], true ) ) {
			return false;
		}
		$words = array_map( array( 'AITP_Text', 'lower' ), AITP_Text::latin_words( $s, 2 ) );
		if ( ! $words ) {
			return false;
		}
		if ( $si['base'] === 'en' ) {
			$exempt = isset( self::$en_exempt[ $ti['base'] ] ) ? self::$en_exempt[ $ti['base'] ] : array();
			foreach ( $words as $w ) {
				if ( in_array( $w, self::$en_function_words, true ) && ! in_array( $w, $exempt, true ) ) {
					return false;
				}
			}
		} elseif ( isset( self::$markers[ $si['base'] ] ) ) {
			// Source language with its own markers: words shared by both languages prove nothing.
			$own = array_diff( self::$markers[ $ti['base'] ]['words'], self::$markers[ $si['base'] ]['words'] );
			return (bool) array_intersect( $words, $own );
		}
		$m = self::$markers[ $ti['base'] ];
		if ( array_intersect( $words, $m['words'] ) ) {
			return true;
		}
		return (bool) preg_match( '~[' . $m['letters'] . ']~u', AITP_Text::lower( $s ) );
	}

	/** Characters used only in Simplified Chinese. */
	const SIMPLIFIED_ONLY = '们这个为说时会对发经过还进现么样买卖产东车门问间听见边让给关于开实质请应专业订单运费选购级标务图类条确认联网电话简镜销货优颜设计场帮欢';

	/** Their Traditional forms. */
	const TRADITIONAL_ONLY = '們這個為說時會對發經過還進現麼樣買賣產東車門問間聽見邊讓給關於開實質請應專業訂單運費選購級標務圖類條確認聯網電話簡鏡銷貨優顏設計場幫歡';

	/**
	 * Writing-system problems within one language: 'wrong_script' (hard), 'no_kana' (soft)
	 * or ''.
	 *
	 * @param string $t_an      Translation (analysis text).
	 * @param array  $ti        Target info.
	 * @param array  $t_scripts Script counts of the translation.
	 * @param bool   $identical Translation equals the source.
	 * @return string
	 */
	private static function wrong_script( $t_an, array $ti, array $t_scripts, $identical ) {
		if ( $ti['base'] === 'zh' && in_array( $ti['script'], array( 'Hans', 'Hant' ), true ) ) {
			$other = $ti['script'] === 'Hant' ? self::SIMPLIFIED_ONLY : self::TRADITIONAL_ONLY;
			$hits  = preg_match_all( '~[' . $other . ']~u', $t_an );
			return $hits >= 2 ? 'wrong_script' : '';
		}
		$han = isset( $t_scripts['Han'] ) ? $t_scripts['Han'] : 0;
		if ( $ti['base'] === 'ko' && $han >= 2 && empty( $t_scripts['Hangul'] ) ) {
			return 'wrong_script';
		}
		if ( $ti['base'] === 'ja' && ! $identical && $han >= 6 && empty( $t_scripts['Hiragana'] ) && empty( $t_scripts['Katakana'] ) ) {
			return 'no_kana'; // Long all-kanji Japanese exists, but it may be Chinese: look at it.
		}
		return '';
	}

	/**
	 * @return string '' when fine, a hard code, CONFIRM_IDENTICAL or ALREADY_TARGET.
	 */
	private function untranslated( $s_text, $t_text, $s_an, $t_an, array $si, array $ti ) {
		if ( $si['base'] === $ti['base'] ) {
			return '';
		}
		$words       = preg_match_all( '~\p{L}{3,}~u', $s_an, $m ) ? $m[0] : array();
		$lower_words = array();
		$name_words  = array();
		foreach ( $words as $w ) {
			if ( preg_match( '~^\p{Ll}+$~u', $w ) ) {
				$lower_words[] = $w;
			} elseif ( ! preg_match( '~^\p{Lu}+$~u', $w ) ) {
				$name_words[] = $w; // Capitalised: could be a name or a title-cased phrase.
			}
		}

		if ( AITP_Text::fold( $s_text ) === AITP_Text::fold( $t_text ) ) {
			// File names, slugs and identifiers (no spaces, with _ / \ or a file extension).
			$st = trim( $s_text );
			if ( ! preg_match( '~\s~u', $st ) && preg_match( '~[_/\\\\]|\.[A-Za-z0-9]{2,5}$~u', $st ) ) {
				return '';
			}
			// Source languages without capital letters (Chinese, Japanese, Arabic …): text in
			// the source language's own writing system returned unchanged is untranslated, at
			// any length. Chinese ↔ Japanese share Han characters: short names may stay.
			$s_counts = AITP_Text::script_counts( $s_an );
			$own      = 0;
			foreach ( $si['scripts'] as $sc ) {
				if ( $sc !== 'Latin' && isset( $s_counts[ $sc ] ) ) {
					$own += $s_counts[ $sc ];
				}
			}
			if ( $own > 0 ) {
				if ( ! array_intersect( $si['scripts'], $ti['scripts'] ) || $ti['base'] === 'ko' ) {
					return 'untranslated';
				}
				return $own >= 6 ? 'untranslated' : self::CONFIRM_IDENTICAL;
			}
			// A non-Latin source site with English strings, translated into English: those
			// strings are already in the target language.
			if ( $ti['base'] === 'en' && ! in_array( 'Latin', $si['scripts'], true ) && AITP_Text::latin_words( $s_an, 2 ) ) {
				return self::ALREADY_TARGET;
			}
			// Text that is already in the target language (no English function word, clear
			// signs of the target language): ask once, then keep it for review. Real English
			// left unchanged still falls through to the hard rules below.
			if ( $this->already_in_target( $s_an, $si, $ti ) ) {
				return self::ALREADY_TARGET;
			}
			if ( count( $lower_words ) >= 2 || ( count( $lower_words ) >= 1 && count( $words ) >= 2 ) ) {
				return 'untranslated';
			}
			if ( $si['base'] === 'en' && $this->sentence_like( $s_an ) ) {
				return 'untranslated'; // Title-cased headline or question, not a name.
			}
			if ( $lower_words || $name_words ) {
				return self::CONFIRM_IDENTICAL;
			}
			return '';
		}

		$t_is_latin = in_array( 'Latin', $ti['scripts'], true );
		if ( $t_is_latin ) {
			if ( $si['base'] !== 'en' ) {
				return '';
			}
			$s_tokens = AITP_Text::latin_words( $s_an, 2 );
			if ( count( $s_tokens ) < 6 ) {
				return '';
			}
			$exempt = isset( self::$en_exempt[ $ti['base'] ] ) ? self::$en_exempt[ $ti['base'] ] : array();
			$t_tok  = array_map( array( 'AITP_Text', 'lower' ), AITP_Text::latin_words( $t_an, 3 ) );
			$hits   = 0;
			foreach ( $t_tok as $w ) {
				if ( in_array( $w, self::$en_function_words, true ) && ! in_array( $w, $exempt, true ) ) {
					$hits++;
				}
			}
			if ( $hits >= 3 && $hits / max( 1, count( $t_tok ) ) >= 0.15 ) {
				return 'english_residue';
			}
			return '';
		}

		// Non-Latin target: lower-case source words copied into the translation.
		$src_low = array();
		foreach ( AITP_Text::latin_words( $s_an, 3 ) as $w ) {
			if ( preg_match( '~^\p{Ll}+$~u', $w ) ) {
				$src_low[ $w ] = true;
			}
		}
		if ( ! $src_low ) {
			return '';
		}
		$copied = 0;
		foreach ( AITP_Text::latin_words( $t_an, 3 ) as $w ) {
			if ( isset( $src_low[ AITP_Text::lower( $w ) ] ) ) {
				$copied++;
			}
		}
		if ( $copied >= 4 && $copied >= 0.25 * count( $src_low ) ) {
			return 'english_residue';
		}
		return '';
	}

	/**
	 * @return array{hard:string[],soft:string[],details:array}
	 */
	private static function result( array $hard, array $soft, array $details ) {
		return array(
			'hard'    => array_values( array_unique( $hard ) ),
			'soft'    => array_values( array_unique( $soft ) ),
			'details' => $details,
		);
	}

	/**
	 * Instruction sent back to the model when a translation is retried.
	 *
	 * @param string $code  Problem code.
	 * @param array  $details Details from check().
	 * @param array  $ti    Target language info.
	 * @return string
	 */
	public static function hint( $code, array $details, array $ti ) {
		$lang = $ti['name'];
		if ( strpos( $code, 'added_claim:' ) === 0 ) {
			return 'Do not add claims, promises or intensifiers that are not in the source (' . substr( $code, 12 ) . ').';
		}
		switch ( $code ) {
			case 'empty':
			case 'no_letters':
				return 'Return a complete translation of the text.';
			case 'markup_mismatch':
			case 'markup_unbalanced':
				return 'Keep every HTML tag and attribute exactly as in the source; only translate the text between tags.';
			case 'no_translate_changed':
				return 'Text inside elements marked translate="no" or notranslate must stay unchanged.';
			case 'placeholder_mismatch':
				return 'Keep placeholders, shortcodes and tokens exactly as in the source.';
			case 'link_changed':
				return 'Keep URLs and e-mail addresses exactly as in the source.';
			case 'protected_term_missing':
				return 'Keep these terms exactly as written: ' . implode( ', ', isset( $details['missing_terms'] ) ? $details['missing_terms'] : array() ) . '.';
			case 'brand_case':
				return 'Ordinary words must be translated normally; do not write them in capital letters like the brand name (' . implode( ', ', isset( $details['brand_case'] ) ? $details['brand_case'] : array() ) . ').';
			case 'protected_pattern_missing':
				return 'Copy these codes and references unchanged: ' . implode( ', ', isset( $details['missing_patterns'] ) ? $details['missing_patterns'] : array() ) . '.';
			case 'size_changed':
				return 'Size codes (XS, S, M, L, XL …) must stay exactly as in the source.';
			case 'number_mismatch':
			case 'number_format':
				return 'Keep every number from the source, written with digits' . ( ! empty( $details['missing_numbers'] ) ? ' (missing: ' . implode( ', ', $details['missing_numbers'] ) . ')' : '' ) . '.';
			case 'foreign_script':
			case 'source_residue':
				return 'Write only in ' . $lang . '; remove characters from other writing systems.';
			case 'wrong_script':
				return 'Write in ' . $lang . ' with its own characters' . ( $ti['base'] === 'zh' ? ( $ti['script'] === 'Hant' ? ' (Traditional Chinese characters, not Simplified)' : ' (Simplified Chinese characters, not Traditional)' ) : '' ) . '.';
			case 'no_kana':
				return 'Write natural Japanese, with hiragana and katakana where Japanese uses them; do not answer in Chinese.';
			case 'untranslated':
			case 'english_residue':
				return 'Translate all of the text into ' . $lang . '; keep only names, brands and codes unchanged.';
			case self::CONFIRM_IDENTICAL:
			case self::ALREADY_TARGET:
				return 'Your previous answer was identical to the source. If this is a name, brand or code that must stay unchanged, or the text is already written in ' . $lang . ', return it unchanged; otherwise translate it into ' . $lang . '.';
			case 'too_short':
			case 'sentences_missing':
				return 'Translate the whole text completely. Do not shorten, summarise or skip sentences.';
			case 'too_long':
				return 'Do not add content that is not in the source.';
			case 'glossary_required':
			case 'glossary_forbidden':
				return 'Follow the glossary entries for this text.';
			case 'brand_added':
				return 'Do not add brand names that are not in the source.';
			case 'format_leak':
			case 'control_chars':
				return 'Return only the translated text inside the JSON "text" field.';
			default:
				return 'Translate faithfully and completely.';
		}
	}
}
