<?php
/**
 * Locale metadata used for prompts and validation.
 *
 * Pure PHP, no WordPress dependency.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Languages {

	/**
	 * Base language => [English name, script code, length factor relative to English letters].
	 *
	 * The length factor is the typical ratio of letter count (target / English source).
	 * It only feeds the "obviously too short / too long" guard, which uses wide margins.
	 *
	 * @var array<string,array{0:string,1:string,2:float}>
	 */
	private static $base = array(
		'en' => array( 'English', 'Latn', 1.0 ),
		'fr' => array( 'French', 'Latn', 1.15 ),
		'de' => array( 'German', 'Latn', 1.15 ),
		'es' => array( 'Spanish', 'Latn', 1.15 ),
		'pt' => array( 'Portuguese', 'Latn', 1.12 ),
		'it' => array( 'Italian', 'Latn', 1.12 ),
		'nl' => array( 'Dutch', 'Latn', 1.12 ),
		'sv' => array( 'Swedish', 'Latn', 1.05 ),
		'da' => array( 'Danish', 'Latn', 1.05 ),
		'nb' => array( 'Norwegian Bokmål', 'Latn', 1.05 ),
		'nn' => array( 'Norwegian Nynorsk', 'Latn', 1.05 ),
		'no' => array( 'Norwegian', 'Latn', 1.05 ),
		'fi' => array( 'Finnish', 'Latn', 1.1 ),
		'is' => array( 'Icelandic', 'Latn', 1.05 ),
		'pl' => array( 'Polish', 'Latn', 1.08 ),
		'cs' => array( 'Czech', 'Latn', 1.0 ),
		'sk' => array( 'Slovak', 'Latn', 1.0 ),
		'sl' => array( 'Slovenian', 'Latn', 1.0 ),
		'hr' => array( 'Croatian', 'Latn', 1.0 ),
		'bs' => array( 'Bosnian', 'Latn', 1.0 ),
		'ro' => array( 'Romanian', 'Latn', 1.1 ),
		'hu' => array( 'Hungarian', 'Latn', 1.08 ),
		'et' => array( 'Estonian', 'Latn', 1.0 ),
		'lv' => array( 'Latvian', 'Latn', 1.0 ),
		'lt' => array( 'Lithuanian', 'Latn', 1.0 ),
		'tr' => array( 'Turkish', 'Latn', 1.05 ),
		'az' => array( 'Azerbaijani', 'Latn', 1.05 ),
		'uz' => array( 'Uzbek', 'Latn', 1.1 ),
		'id' => array( 'Indonesian', 'Latn', 1.12 ),
		'ms' => array( 'Malay', 'Latn', 1.12 ),
		'tl' => array( 'Tagalog', 'Latn', 1.2 ),
		'vi' => array( 'Vietnamese', 'Latn', 1.05 ),
		'sw' => array( 'Swahili', 'Latn', 1.1 ),
		'ca' => array( 'Catalan', 'Latn', 1.12 ),
		'gl' => array( 'Galician', 'Latn', 1.12 ),
		'eu' => array( 'Basque', 'Latn', 1.1 ),
		'ga' => array( 'Irish', 'Latn', 1.15 ),
		'cy' => array( 'Welsh', 'Latn', 1.1 ),
		'sq' => array( 'Albanian', 'Latn', 1.1 ),
		'mt' => array( 'Maltese', 'Latn', 1.1 ),
		'af' => array( 'Afrikaans', 'Latn', 1.05 ),
		'ru' => array( 'Russian', 'Cyrl', 1.05 ),
		'uk' => array( 'Ukrainian', 'Cyrl', 1.05 ),
		'bg' => array( 'Bulgarian', 'Cyrl', 1.05 ),
		'be' => array( 'Belarusian', 'Cyrl', 1.05 ),
		'mk' => array( 'Macedonian', 'Cyrl', 1.05 ),
		'sr' => array( 'Serbian', 'Cyrl', 1.0 ),
		'kk' => array( 'Kazakh', 'Cyrl', 1.05 ),
		'ky' => array( 'Kyrgyz', 'Cyrl', 1.05 ),
		'mn' => array( 'Mongolian', 'Cyrl', 1.05 ),
		'el' => array( 'Greek', 'Grek', 1.1 ),
		'ar' => array( 'Arabic', 'Arab', 0.85 ),
		'fa' => array( 'Persian', 'Arab', 0.85 ),
		'ur' => array( 'Urdu', 'Arab', 0.9 ),
		'ps' => array( 'Pashto', 'Arab', 0.9 ),
		'he' => array( 'Hebrew', 'Hebr', 0.8 ),
		'zh' => array( 'Chinese', 'Hans', 0.3 ),
		'ja' => array( 'Japanese', 'Jpan', 0.45 ),
		'ko' => array( 'Korean', 'Kore', 0.45 ),
		'th' => array( 'Thai', 'Thai', 0.9 ),
		'hi' => array( 'Hindi', 'Deva', 1.0 ),
		'mr' => array( 'Marathi', 'Deva', 1.0 ),
		'ne' => array( 'Nepali', 'Deva', 1.0 ),
		'bn' => array( 'Bengali', 'Beng', 1.0 ),
		'pa' => array( 'Punjabi', 'Guru', 1.0 ),
		'gu' => array( 'Gujarati', 'Gujr', 1.0 ),
		'ta' => array( 'Tamil', 'Taml', 1.2 ),
		'te' => array( 'Telugu', 'Telu', 1.1 ),
		'kn' => array( 'Kannada', 'Knda', 1.1 ),
		'ml' => array( 'Malayalam', 'Mlym', 1.2 ),
		'si' => array( 'Sinhala', 'Sinh', 1.0 ),
		'my' => array( 'Burmese', 'Mymr', 1.1 ),
		'km' => array( 'Khmer', 'Khmr', 1.0 ),
		'lo' => array( 'Lao', 'Laoo', 1.0 ),
		'ka' => array( 'Georgian', 'Geor', 1.1 ),
		'hy' => array( 'Armenian', 'Armn', 1.1 ),
		'am' => array( 'Amharic', 'Ethi', 0.8 ),
		// Further languages in TranslatePress' list (and common WordPress locales).
		'ary' => array( 'Moroccan Arabic', 'Arab', 0.85 ),
		'azb' => array( 'South Azerbaijani', 'Arab', 0.9 ),
		'ckb' => array( 'Central Kurdish (Sorani)', 'Arab', 0.9 ),
		'haz' => array( 'Hazaragi', 'Arab', 0.9 ),
		'ug'  => array( 'Uyghur', 'Arab', 0.9 ),
		'sd'  => array( 'Sindhi', 'Arab', 0.9 ),
		'skr' => array( 'Saraiki', 'Arab', 0.9 ),
		'as'  => array( 'Assamese', 'Beng', 1.0 ),
		'or'  => array( 'Odia', 'Orya', 1.0 ),
		'bo'  => array( 'Tibetan', 'Tibt', 1.0 ),
		'dzo' => array( 'Dzongkha', 'Tibt', 1.0 ),
		'dv'  => array( 'Dhivehi', 'Thaa', 0.9 ),
		'yi'  => array( 'Yiddish', 'Hebr', 0.9 ),
		'sah' => array( 'Sakha', 'Cyrl', 1.05 ),
		'tt'  => array( 'Tatar', 'Cyrl', 1.05 ),
		'tg'  => array( 'Tajik', 'Cyrl', 1.05 ),
		'ba'  => array( 'Bashkir', 'Cyrl', 1.05 ),
		'ceb' => array( 'Cebuano', 'Latn', 1.15 ),
		'eo'  => array( 'Esperanto', 'Latn', 1.05 ),
		'gd'  => array( 'Scottish Gaelic', 'Latn', 1.15 ),
		'kab' => array( 'Kabyle', 'Latn', 1.1 ),
		'oci' => array( 'Occitan', 'Latn', 1.12 ),
		'rhg' => array( 'Rohingya', 'Latn', 1.0 ),
		'szl' => array( 'Silesian', 'Latn', 1.0 ),
		'tah' => array( 'Tahitian', 'Latn', 1.1 ),
		'tk'  => array( 'Turkmen', 'Latn', 1.05 ),
		'ku'  => array( 'Kurdish (Kurmanji)', 'Latn', 1.05 ),
		'lb'  => array( 'Luxembourgish', 'Latn', 1.1 ),
		'fy'  => array( 'Frisian', 'Latn', 1.05 ),
		'br'  => array( 'Breton', 'Latn', 1.1 ),
		'fo'  => array( 'Faroese', 'Latn', 1.05 ),
		'zu'  => array( 'Zulu', 'Latn', 1.1 ),
		'xh'  => array( 'Xhosa', 'Latn', 1.1 ),
		'yo'  => array( 'Yoruba', 'Latn', 1.1 ),
		'ha'  => array( 'Hausa', 'Latn', 1.1 ),
		'so'  => array( 'Somali', 'Latn', 1.1 ),
		'jv'  => array( 'Javanese', 'Latn', 1.1 ),
		'su'  => array( 'Sundanese', 'Latn', 1.1 ),
		'mg'  => array( 'Malagasy', 'Latn', 1.15 ),
		'ht'  => array( 'Haitian Creole', 'Latn', 1.1 ),
		'mi'  => array( 'Maori', 'Latn', 1.1 ),
		'sm'  => array( 'Samoan', 'Latn', 1.1 ),
		'ig'  => array( 'Igbo', 'Latn', 1.1 ),
		'rw'  => array( 'Kinyarwanda', 'Latn', 1.1 ),
	);

	/**
	 * Three-letter and other codes TranslatePress / WordPress use for languages listed above.
	 *
	 * @var array<string,string>
	 */
	private static $aliases = array(
		'bel' => 'be',
		'kir' => 'ky',
		'fil' => 'tl',
		'tuk' => 'tk',
		'snd' => 'sd',
		'ory' => 'or',
		'hat' => 'ht',
	);

	/** @var array<string,string> */
	private static $regions = array(
		'US' => 'United States', 'GB' => 'United Kingdom', 'AU' => 'Australia', 'CA' => 'Canada',
		'NZ' => 'New Zealand', 'IE' => 'Ireland', 'ZA' => 'South Africa', 'IN' => 'India',
		'MX' => 'Mexico', 'ES' => 'Spain', 'AR' => 'Argentina', 'CO' => 'Colombia', 'CL' => 'Chile',
		'PE' => 'Peru', 'VE' => 'Venezuela', 'BR' => 'Brazil', 'PT' => 'Portugal', 'AO' => 'Angola',
		'FR' => 'France', 'BE' => 'Belgium', 'CH' => 'Switzerland', 'LU' => 'Luxembourg',
		'DE' => 'Germany', 'AT' => 'Austria', 'IT' => 'Italy', 'NL' => 'Netherlands',
		'CN' => 'Mainland China', 'TW' => 'Taiwan', 'HK' => 'Hong Kong', 'SG' => 'Singapore',
		'MO' => 'Macau', 'JP' => 'Japan', 'KR' => 'South Korea', 'SA' => 'Saudi Arabia',
		'AE' => 'United Arab Emirates', 'EG' => 'Egypt', 'MA' => 'Morocco', 'DZ' => 'Algeria',
		'RU' => 'Russia', 'UA' => 'Ukraine', 'TR' => 'Turkey', 'RS' => 'Serbia', 'IR' => 'Iran',
		'AF' => 'Afghanistan', 'PK' => 'Pakistan', 'IL' => 'Israel', 'NO' => 'Norway', 'SE' => 'Sweden',
		'DK' => 'Denmark', 'FI' => 'Finland', 'PL' => 'Poland', 'GR' => 'Greece', 'ID' => 'Indonesia',
		'MY' => 'Malaysia', 'TH' => 'Thailand', 'VN' => 'Vietnam', 'PH' => 'Philippines',
	);

	/**
	 * Unicode script names (PCRE \p{...}) that belong to each script code.
	 *
	 * @var array<string,string[]>
	 */
	private static $script_sets = array(
		'Latn' => array( 'Latin' ),
		'Cyrl' => array( 'Cyrillic' ),
		'Grek' => array( 'Greek' ),
		'Arab' => array( 'Arabic' ),
		'Hebr' => array( 'Hebrew' ),
		'Hans' => array( 'Han' ),
		'Hant' => array( 'Han' ),
		'Jpan' => array( 'Han', 'Hiragana', 'Katakana' ),
		'Kore' => array( 'Hangul', 'Han' ),
		'Thai' => array( 'Thai' ),
		'Deva' => array( 'Devanagari' ),
		'Beng' => array( 'Bengali' ),
		'Guru' => array( 'Gurmukhi' ),
		'Gujr' => array( 'Gujarati' ),
		'Taml' => array( 'Tamil' ),
		'Telu' => array( 'Telugu' ),
		'Knda' => array( 'Kannada' ),
		'Mlym' => array( 'Malayalam' ),
		'Sinh' => array( 'Sinhala' ),
		'Mymr' => array( 'Myanmar' ),
		'Khmr' => array( 'Khmer' ),
		'Laoo' => array( 'Lao' ),
		'Geor' => array( 'Georgian' ),
		'Armn' => array( 'Armenian' ),
		'Ethi' => array( 'Ethiopic' ),
		'Orya' => array( 'Oriya' ),
		'Tibt' => array( 'Tibetan' ),
		'Thaa' => array( 'Thaana' ),
		'Syrc' => array( 'Syriac' ),
	);

	/**
	 * Every script the validator counts. Common/Inherited are deliberately absent.
	 *
	 * @return string[]
	 */
	public static function all_scripts() {
		return array(
			'Latin', 'Cyrillic', 'Greek', 'Arabic', 'Hebrew', 'Han', 'Hiragana', 'Katakana', 'Hangul',
			'Thai', 'Devanagari', 'Bengali', 'Gurmukhi', 'Gujarati', 'Tamil', 'Telugu', 'Kannada',
			'Malayalam', 'Sinhala', 'Myanmar', 'Khmer', 'Lao', 'Georgian', 'Armenian', 'Ethiopic',
			'Oriya', 'Tibetan', 'Thaana', 'Syriac',
		);
	}

	/**
	 * Describe a WordPress / TranslatePress locale such as es_MX, de_DE_formal, zh_HK or ja.
	 *
	 * @param string      $locale       Locale code.
	 * @param string|null $english_name Optional name supplied by TranslatePress (e.g. "Spanish (Mexico)").
	 * @return array{locale:string,tag:string,base:string,region:string,name:string,script:string,scripts:string[],factor:float,formal:bool,known:bool}
	 */
	public static function info( $locale, $english_name = null ) {
		$locale = trim( (string) $locale );
		$parts  = preg_split( '/[_-]/', $locale );
		$base   = strtolower( isset( $parts[0] ) ? $parts[0] : '' );
		$region = '';
		$formal = false;
		$script = '';

		for ( $i = 1, $n = count( $parts ); $i < $n; $i++ ) {
			$p = $parts[ $i ];
			if ( preg_match( '/^[A-Za-z]{2}$/', $p ) || preg_match( '/^\d{3}$/', $p ) ) {
				$region = strtoupper( $p );
			} elseif ( preg_match( '/^[A-Za-z]{4}$/', $p ) ) {
				$script = ucfirst( strtolower( $p ) );
			} elseif ( strtolower( $p ) === 'formal' ) {
				$formal = true;
			}
		}

		$latin_sr = $base !== '' && strpos( strtolower( $locale ), 'latin' ) !== false;
		$base     = (string) preg_replace( '~@.*$~', '', $base );
		if ( isset( self::$aliases[ $base ] ) ) {
			$base = self::$aliases[ $base ];
		}
		if ( $base === 'sr' && $latin_sr ) {
			$script = 'Latn';
		}
		$known = isset( self::$base[ $base ] );
		$row   = $known ? self::$base[ $base ] : array( $base !== '' ? $base : 'Unknown', 'Latn', 1.0 );

		if ( $script === '' ) {
			$script = $row[1];
			if ( $base === 'zh' && in_array( $region, array( 'TW', 'HK', 'MO' ), true ) ) {
				$script = 'Hant';
			}
		}

		$name = $english_name;
		if ( ! is_string( $name ) || $name === '' ) {
			$name = $row[0];
			if ( $region !== '' ) {
				$name .= ' (' . ( isset( self::$regions[ $region ] ) ? self::$regions[ $region ] : $region ) . ')';
			}
			if ( $formal ) {
				$name .= ', formal register';
			}
		}
		// The writing system is spelled out even when TranslatePress supplies the name
		// ("Chinese (Taiwan)" says nothing about Traditional characters).
		if ( $base === 'zh' && stripos( $name, 'script' ) === false ) {
			$name .= $script === 'Hant' ? ', Traditional script' : ', Simplified script';
		} elseif ( $base === 'sr' && stripos( $name, 'script' ) === false ) {
			$name .= $script === 'Latn' ? ', Latin script' : ', Cyrillic script';
		}

		$tag = $base . ( $region !== '' ? '-' . $region : '' );

		return array(
			'locale'  => $locale,
			'tag'     => $tag,
			'base'    => $base,
			'region'  => $region,
			'name'    => $name,
			'script'  => $script,
			'scripts' => isset( self::$script_sets[ $script ] ) ? self::$script_sets[ $script ] : array( 'Latin' ),
			'factor'  => (float) $row[2],
			'formal'  => $formal,
			'known'   => $known,
		);
	}

	/**
	 * Teach an unknown language code the ISO code TranslatePress maps it to (e.g. a custom
	 * language "bel" → "be"), so its writing system is known.
	 *
	 * @param string $code Language code as used by the site.
	 * @param string $iso  ISO code given by TranslatePress.
	 */
	public static function alias( $code, $iso ) {
		$parts = preg_split( '/[_-]/', strtolower( trim( (string) $code ) ) );
		$base  = isset( $parts[0] ) ? $parts[0] : '';
		$ip    = preg_split( '/[_-]/', strtolower( trim( (string) $iso ) ) );
		$ib    = isset( $ip[0] ) ? $ip[0] : '';
		if ( $base !== '' && $ib !== '' && ! isset( self::$base[ $base ] ) && ! isset( self::$aliases[ $base ] ) && isset( self::$base[ $ib ] ) ) {
			self::$aliases[ $base ] = $ib;
		}
	}

	/**
	 * Whether sentences in this script are normally written without spaces between them.
	 *
	 * @param string $script Script code.
	 * @return bool
	 */
	public static function joins_without_spaces( $script ) {
		return in_array( $script, array( 'Hans', 'Hant', 'Jpan' ), true );
	}

	/**
	 * Base language codes treated as supported. LLMs handle far more, but TranslatePress
	 * only needs a list to compare against.
	 *
	 * @return string[]
	 */
	public static function base_codes() {
		return array_keys( self::$base );
	}
}
