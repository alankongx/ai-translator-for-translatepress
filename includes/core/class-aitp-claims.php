<?php
/**
 * Built-in lexicon for the "added claim" soft check.
 *
 * A concept is flagged when the translation contains one of its markers for the target
 * language while the source contains none of the source-language triggers. This is a
 * heuristic: it routes items to human review, it never decides on its own that a
 * translation is wrong (a marker can be legitimate, e.g. "garantir" for "ensure").
 *
 * Latin-script markers match at a word start (prefix); a trailing "$" makes them whole words
 * ("beste$" must not match "Bestehende"). Arabic markers match at a word start after the
 * usual attached prefixes (و ف ب ل ك ال). Other scripts match as substrings.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Claims {

	/**
	 * concept => language base => markers. "en" doubles as the (wide) trigger list for English
	 * sources; "en_target" is the narrow list used when English is the target, so that
	 * "Shipping information" or "Back to top" are not taken for added claims.
	 *
	 * @return array<string,array<string,string[]>>
	 */
	public static function lexicon() {
		return array(
			'guarantee'  => array(
				'en_target' => array( 'guarantee', 'guaranteed$', 'warranty', 'warranted$' ),
				'en' => array( 'guarantee', 'warrant', 'ensure', 'assure', 'promise', 'secure', 'commit' ),
				'fr' => array( 'garanti', 'assur' ),
				'es' => array( 'garantiz', 'garantía', 'asegur' ),
				'pt' => array( 'garanti', 'assegur' ),
				'de' => array( 'garantie', 'garantier', 'gewährleist', 'sicherstell' ),
				'it' => array( 'garanti', 'assicur' ),
				'nl' => array( 'garant' ),
				'ja' => array( '保証', '確約' ),
				'zh' => array( '保证', '保障', '确保', '保證', '质保', '質保', '保修', '保固' ),
				'ko' => array( '보장', '보증' ),
				'ar' => array( 'ضمان', 'نضمن', 'يضمن', 'تضمن', 'مضمون' ),
				'ru' => array( 'гарант' ),
			),
			'certified'  => array(
				'en_target' => array( 'certified$', 'certification', 'accredited$' ),
				'en' => array( 'certif', 'accredit', 'approv', 'complian', 'compli', 'conform', 'tested', 'test', 'standard', 'regulat' ),
				'fr' => array( 'certifi', 'homologu' ),
				'es' => array( 'certific', 'homolog' ),
				'pt' => array( 'certific', 'homolog' ),
				'de' => array( 'zertifi' ),
				'it' => array( 'certific', 'omologat' ),
				'nl' => array( 'gecertific' ),
				'ja' => array( '認証', '認定' ),
				'zh' => array( '认证', '認證' ),
				'ko' => array( '인증' ),
				'ar' => array( 'معتمد', 'شهادة', 'مصدق' ),
				'ru' => array( 'сертифиц' ),
			),
			'perfect'    => array(
				'en_target' => array( 'perfect$', 'perfectly$', 'flawless', 'impeccable' ),
				'en' => array( 'perfect', 'flawless', 'ideal', 'impeccable' ),
				'fr' => array( 'parfait' ),
				'es' => array( 'perfect' ),
				'pt' => array( 'perfeit' ),
				'de' => array( 'perfekt', 'makellos' ),
				'it' => array( 'perfett' ),
				'nl' => array( 'perfect' ),
				'ja' => array( '完璧' ),
				'zh' => array( '完美' ),
				'ko' => array( '완벽' ),
				'ar' => array( 'مثالي', 'مثالية' ),
				'ru' => array( 'идеальн', 'безупречн' ),
			),
			'in_stock'   => array(
				'en_target' => array( 'in stock', 'ships immediately', 'immediate delivery', 'ready to ship' ),
				'en' => array( 'stock', 'ready', 'inventory', 'immediate', 'availab', 'ships', 'shipping', 'warehouse' ),
				'fr' => array( 'en stock', 'livraison immédiate', 'disponible immédiatement' ),
				'es' => array( 'en existencia', 'en stock', 'entrega inmediata' ),
				'pt' => array( 'em estoque', 'pronta entrega' ),
				'de' => array( 'auf lager', 'sofort lieferbar', 'lagerware' ),
				'it' => array( 'pronta consegna', 'disponibile a magazzino' ),
				'nl' => array( 'op voorraad' ),
				'ja' => array( '在庫', '即納', '即日発送' ),
				'zh' => array( '现货', '庫存', '库存', '現貨' ),
				'ko' => array( '재고', '즉시 배송' ),
				'ar' => array( 'المخزون', 'متوفر فورا', 'شحن فوري' ),
				'ru' => array( 'в наличии' ),
			),
			'all_items'  => array(
				'en_target' => array( 'all products', 'all items', 'all models', 'all of our', 'every product', 'entire range' ),
				'en' => array( 'all', 'every', 'entire', 'whole', 'each', 'any', 'full', 'complete', 'across', 'around the world', 'worldwide', 'total' ),
				'fr' => array( 'tous les', 'toutes les', "l'ensemble" ),
				'es' => array( 'todos los', 'todas las' ),
				'pt' => array( 'todos os', 'todas as' ),
				'de' => array( 'sämtliche' ),
				'it' => array( 'tutti i', 'tutte le' ),
				'ja' => array( 'すべての', '全ての', '全モデル', '全品' ),
				'zh' => array( '所有', '全部' ),
				'ko' => array( '모든' ),
				'ar' => array( 'جميع' ),
				'ru' => array( 'все ', 'всех' ),
			),
			'polarized'  => array(
				'en_target' => array( 'polariz', 'polaris' ),
				'en' => array( 'polariz', 'polaris' ),
				'fr' => array( 'polaris' ),
				'es' => array( 'polariz' ),
				'pt' => array( 'polariz' ),
				'de' => array( 'polaris' ),
				'it' => array( 'polarizz' ),
				'nl' => array( 'gepolariseer', 'polaris' ),
				'ja' => array( '偏光' ),
				'zh' => array( '偏光' ),
				'ko' => array( '편광' ),
				'ar' => array( 'مستقطب', 'استقطاب' ),
				'ru' => array( 'поляриз' ),
			),
			'reinforced' => array(
				'en_target' => array( 'reinforced$', 'tempered$', 'hardened$' ),
				'en' => array( 'reinforc', 'strengthen', 'fortif', 'temper', 'rigid', 'robust', 'hardened', 'strong' ),
				'fr' => array( 'renforc' ),
				'es' => array( 'reforzad' ),
				'pt' => array( 'reforçad' ),
				'de' => array( 'verstärkt' ),
				'it' => array( 'rinforzat' ),
				'ja' => array( '強化' ),
				'zh' => array( '加固', '强化', '強化' ),
				'ar' => array( 'مقوى', 'مقواة', 'معزز', 'معززة', 'مدعم', 'مدعمة' ),
				'ru' => array( 'усилен' ),
			),
			'free'       => array(
				'en_target' => array( 'free of charge', 'for free', 'free shipping', 'complimentary', 'at no cost' ),
				'en' => array( 'free', 'no cost', 'no charge', 'complimentary', 'gratis' ),
				'fr' => array( 'gratuit' ),
				'es' => array( 'gratis', 'gratuit' ),
				'pt' => array( 'grátis', 'gratuit' ),
				'de' => array( 'kostenlos', 'gratis' ),
				'it' => array( 'gratuit', 'gratis' ),
				'ja' => array( '無料' ),
				'zh' => array( '免费', '免費' ),
				'ko' => array( '무료' ),
				'ar' => array( 'مجان' ),
				'ru' => array( 'бесплатн' ),
			),
			'best'       => array(
				'en_target' => array( 'best$', 'number one', 'no. 1', '#1', 'finest$', 'industry-leading' ),
				'en' => array( 'best', 'leading', 'top', 'number one', 'no. 1', '#1', 'finest', 'premier', 'superior', 'better', 'most', 'optimal' ),
				'fr' => array( 'meilleur', 'numéro un' ),
				'es' => array( 'mejor$', 'mejores$', 'número uno' ),
				'pt' => array( 'melhor$', 'melhores$' ),
				'de' => array( 'beste$', 'besten$', 'bester$', 'bestes$', 'bestem$' ),
				'it' => array( 'migliore$', 'migliori$' ),
				'ja' => array( '最高', '最良', 'ナンバーワン', '一番' ),
				'zh' => array( '最好', '最佳', '第一名', '排名第一', '第一品牌', '首屈一指' ),
				'ko' => array( '최고' ),
				'ar' => array( 'أفضل' ),
				'ru' => array( 'лучш' ),
			),
		);
	}

	/**
	 * Concepts whose markers occur in the text for the given language base.
	 *
	 * @param string $text Text (plain, markup removed).
	 * @param string $base      Language base code.
	 * @param bool   $as_target Text is a translation (markers), not a source (triggers).
	 * @return string[]|null Null when the lexicon has no data for this language.
	 */
	public static function concepts_in( $text, $base, $as_target = false ) {
		$found = array();
		$known = false;
		$low   = AITP_Text::lower( AITP_Text::normalize_punct( $text ) );
		$key   = $as_target && $base === 'en' ? 'en_target' : $base;
		foreach ( self::lexicon() as $concept => $langs ) {
			if ( ! isset( $langs[ $key ] ) ) {
				continue;
			}
			$known = true;
			foreach ( $langs[ $key ] as $marker ) {
				if ( self::has_marker( $low, AITP_Text::lower( $marker ) ) ) {
					$found[] = $concept;
					break;
				}
			}
		}
		return $known ? $found : null;
	}

	/**
	 * @param string $low    Lower-cased text.
	 * @param string $marker Lower-cased marker.
	 * @return bool
	 */
	private static function has_marker( $low, $marker ) {
		$whole = substr( $marker, -1 ) === '$';
		if ( $whole ) {
			$marker = substr( $marker, 0, -1 );
		}
		if ( preg_match( '~^[\p{Latin}\d\s\'#.\-]+$~u', $marker ) ) {
			return (bool) preg_match( '~(?<![\p{L}\p{N}])' . preg_quote( $marker, '~' ) . ( $whole ? '(?![\p{L}\p{N}])' : '' ) . '~u', $low );
		}
		if ( preg_match( '~^[\p{Arabic}\s]+$~u', $marker ) ) {
			return (bool) preg_match( '~(?<!\p{Arabic})(?:[وفبلك]{0,2}(?:ال)?)' . preg_quote( $marker, '~' ) . ( $whole ? '(?!\p{Arabic})' : '' ) . '~u', $low );
		}
		return strpos( $low, $marker ) !== false;
	}
}
