<?php
$hard = function ( $src, $tgt, $tl, $rules = null ) {
	return vcheck( $src, $tgt, $tl, $rules )['hard'];
};

/* --- Real OKAY failures: must be rejected. --- */
test( 'validator: "20+ Countries" -> person name rejected (fr/ja/ar)', function () use ( $hard ) {
	$s = 'Serving Brands Across 20+ Countries';
	ok( (bool) $hard( $s, 'Dr. Claire C.', 'fr_FR' ), 'fr' );
	ok( (bool) $hard( $s, 'クレア・C博士', 'ja' ), 'ja' );
	ok( (bool) $hard( $s, 'د. كلير سي.', 'ar' ), 'ar' );
} );

test( 'validator: slogan -> country name rejected (fr/ja/ar)', function () use ( $hard ) {
	$s = 'Your Brand, Built on Our Manufacturing Foundation';
	has( 'too_short', $hard( $s, 'Indonésie', 'fr_FR' ) );
	has( 'too_short', $hard( $s, 'インドネシア', 'ja' ) );
	has( 'too_short', $hard( $s, 'إندونيسيا', 'ar' ) );
} );

test( 'validator: long paragraph -> one word rejected', function () use ( $hard ) {
	$s = 'We work with distributors, retailers and private-label brands to develop sunglasses and optical frames that match their market. Our team handles design, sampling, quality inspection and shipping, so you can focus on selling.';
	has( 'too_short', $hard( $s, 'فيتنام.', 'ar' ) );
} );

test( 'validator: brand capitals on ordinary word rejected', function () use ( $hard ) {
	has( 'brand_case', $hard( 'Explore our complete eyewear range', 'Découvrez notre gamme complète d\'EYEWEAR', 'fr_FR' ) );
	eq( array(), $hard( 'Explore the OKAY EYEWEAR range', 'Découvrez la gamme OKAY EYEWEAR', 'fr_FR' ) );
} );

test( 'validator: brand missing / translated rejected', function () use ( $hard ) {
	has( 'protected_term_missing', $hard( 'Welcome to OKAY EYEWEAR', 'Bienvenue chez OKAY LUNETTES', 'fr_FR' ) );
} );

test( 'validator: Arabic letters inside Spanish rejected', function () use ( $hard ) {
	has( 'foreign_script', $hard( 'Blend in with any outfit', 'Blendانهن con cualquier atuendo', 'es_MX' ) );
} );

test( 'validator: English left in Japanese rejected', function () use ( $hard ) {
	has( 'english_residue', $hard( 'Our team will help you choose the right frames for your market', '私たちの team will help you choose the right frames です', 'ja' ) );
} );

test( 'validator: English left in French rejected', function () use ( $hard ) {
	has( 'english_residue', $hard( 'Our team will help you choose the right frames for your market and your customers', 'Notre équipe will help you choose the right frames for your market', 'fr_FR' ) );
} );

test( 'validator: identical English sentence rejected', function () use ( $hard ) {
	has( 'untranslated', $hard( 'Contact our sales team today', 'Contact our sales team today', 'de_DE' ) );
} );

test( 'validator: size codes translated rejected', function () use ( $hard ) {
	has( 'size_changed', $hard( 'L/XL', 'G/XG', 'es_MX' ) );
	eq( array(), $hard( 'L/XL', 'L/XL', 'es_MX' ) );
} );

test( 'validator: number changed rejected', function () use ( $hard ) {
	has( 'number_mismatch', $hard( 'MOQ 300 pieces per model', 'MOQ 500 piezas por modelo', 'es_MX' ) );
	eq( array(), $hard( 'MOQ 300 pieces per model', 'MOQ 300 piezas por modelo', 'es_MX' ) );
	eq( array(), $hard( 'Over 1,000 models', 'Plus de 1 000 modèles', 'fr_FR' ) );
} );

test( 'validator: code and standard changed rejected', function () use ( $hard ) {
	has( 'protected_pattern_missing', $hard( 'TR90 frames with UV400 lenses', 'Montures TR 90 avec verres UV 400', 'fr_FR' ) );
	has( 'protected_pattern_missing', $hard( 'Tested to EN ISO 12312-1', 'Testé selon la norme ISO 12312', 'fr_FR' ) );
} );

test( 'validator: markup changed rejected, attribute text may change', function () use ( $hard ) {
	has( 'markup_mismatch', $hard( 'Read <a href="/faq">our FAQ</a> first', 'Lisez d\'abord notre FAQ', 'fr_FR' ) );
	has( 'markup_mismatch', $hard( 'Read <a href="/faq">our FAQ</a> first', 'Lisez <a href="/fr/faq">notre FAQ</a>', 'fr_FR' ) );
	eq( array(), $hard( '<img src="a.jpg" alt="Black frames"> Shop now', '<img src="a.jpg" alt="Montures noires"> Achetez maintenant', 'fr_FR' ) );
} );

test( 'validator: placeholders and links kept', function () use ( $hard ) {
	has( 'placeholder_mismatch', $hard( 'You have %d items in %s', 'Vous avez %d articles', 'fr_FR' ) );
	has( 'link_changed', $hard( 'Email sales@okayeyewear.com for a quote', 'Écrivez à ventes@okayeyewear.com pour un devis', 'fr_FR' ) );
} );

test( 'validator: model output leaked into text rejected', function () use ( $hard ) {
	has( 'format_leak', $hard( 'Hello world', '{"id":"t1","text":"Hola mundo"}', 'es_MX' ) );
	has( 'empty', $hard( 'Hello world', '   ', 'es_MX' ) );
} );

/* --- Soft flags (saved + review list by default). --- */
test( 'validator: tinted -> polarized flagged, not rejected', function () {
	$r = vcheck( 'Fashion sunglasses with tinted lenses', 'Lentes de sol de moda con micas polarizadas', 'es_MX' );
	eq( array(), $r['hard'] );
	has( 'added_claim:polarized', $r['soft'] );
	has( 'glossary_forbidden', $r['soft'] );
} );

test( 'validator: flexible -> reinforced flagged (ar)', function () {
	$r = vcheck( 'Flexible frames for everyday comfort', 'إطارات مقواة للراحة اليومية', 'ar' );
	eq( array(), $r['hard'] );
	has( 'added_claim:reinforced', $r['soft'] );
} );

test( 'validator: existing models -> in stock flagged (ja)', function () {
	$r = vcheck( 'Customize existing models with your logo', '在庫モデルにロゴを入れてカスタマイズ', 'ja' );
	eq( array(), $r['hard'] );
	has( 'added_claim:in_stock', $r['soft'] );
} );

test( 'validator: guarantee added flagged', function () {
	$r = vcheck( 'Quality inspection before shipping', 'Inspection qualité garantie avant expédition', 'fr_FR' );
	has( 'added_claim:guarantee', $r['soft'] );
} );

/* --- Legitimately unchanged or correct: must pass. --- */
test( 'validator: brand, codes, OEM, URL, numbers unchanged pass', function () use ( $hard ) {
	foreach ( array( 'OKAY EYEWEAR', 'UV400', 'TR-90', 'OEM', 'OEM / ODM', 'https://okayeyewear.com/contact/', '123', '2024', 'CE', 'FDA' ) as $s ) {
		foreach ( array( 'fr_FR', 'ja', 'ar', 'es_MX', 'de_DE' ) as $tl ) {
			eq( array(), $hard( $s, $s, $tl ), "$s -> $tl" );
		}
	}
} );

test( 'validator: proper names identical only ask to confirm', function () {
	foreach ( array( array( 'Taizhou', 'fr_FR' ), array( 'Baby', 'de_DE' ), array( 'Aviator', 'es_MX' ) ) as $c ) {
		$r = vcheck( $c[0], $c[0], $c[1] );
		eq( array(), $r['hard'], $c[0] );
		has( AITP_Validator::CONFIRM_IDENTICAL, $r['soft'] );
	}
} );

test( 'validator: correct translations pass (fr/ja/ar/es/de/pt)', function () use ( $hard ) {
	$cases = array(
		array( 'Serving Brands Across 20+ Countries', 'Au service de marques dans plus de 20 pays', 'fr_FR' ),
		array( 'Serving Brands Across 20+ Countries', '20か国以上のブランドにサービスを提供', 'ja' ),
		array( 'Serving Brands Across 20+ Countries', 'نخدم العلامات التجارية في أكثر من 20 دولة', 'ar' ),
		array( 'Your Brand, Built on Our Manufacturing Foundation', '御社のブランドを、当社の製造基盤の上に', 'ja' ),
		array( 'Your Brand, Built on Our Manufacturing Foundation', 'Tu marca, construida sobre nuestra base de fabricación', 'es_MX' ),
		array( 'Explore the OKAY EYEWEAR range of TR90 sunglasses', 'Entdecken Sie die OKAY EYEWEAR Kollektion von TR90-Sonnenbrillen', 'de_DE' ),
		array( 'Fashion sunglasses with tinted lenses', 'Lentes de sol de moda con micas tintadas', 'es_MX' ),
		array( 'Lead time: 30-45 days after sample approval', 'Prazo de entrega: 30-45 dias após a aprovação da amostra', 'pt_BR' ),
		array( 'Read <a href="/faq">our FAQ</a> first', 'Lisez d\'abord <a href="/faq">notre FAQ</a>', 'fr_FR' ),
	);
	foreach ( $cases as $c ) {
		eq( array(), $hard( $c[0], $c[1], $c[2] ), $c[1] );
	}
} );

test( 'validator: no claim flags on faithful translations', function () {
	$r = vcheck( 'Fashion sunglasses with tinted lenses', 'Lentes de sol de moda con micas tintadas', 'es_MX' );
	eq( array(), $r['soft'] );
} );

test( 'validator: hints mention the problem', function () {
	$ti = AITP_Languages::info( 'ja' );
	$h  = AITP_Validator::hint( 'number_mismatch', array( 'missing_numbers' => array( '20' ) ), $ti );
	ok( strpos( $h, '20' ) !== false, $h );
	$h = AITP_Validator::hint( 'too_short', array(), $ti );
	ok( $h !== '', 'too_short hint' );
} );

test( 'validator: claim markers do not match inside other words', function () {
	eq( array(), vcheck( 'Existing styles', 'Bestehende Stile', 'de_DE' )['soft'] );
	eq( array(), vcheck( 'Check hinges, temples and assembly against the approved sample.', 'افحص المفصلات والهياكل والتجميع مقارنة بالعينة المعتمدة.', 'ar' )['soft'] );
	eq( array(), vcheck( 'Polarization filters glare to increase visual comfort', 'La polarización filtra el resplandor para mejorar el confort visual', 'es_MX' )['soft'] );
	has( 'added_claim:best', vcheck( 'Choose a frame for your kids', 'Elija el mejor armazón para sus hijos', 'es_MX' )['soft'] );
	has( 'added_claim:all_items', vcheck( 'Check our models', 'تحقق من جميع موديلاتنا', 'ar' )['soft'] );
} );

test( 'validator: Arabic "include" is not "guarantee" (cases from the HHG repair)', function () {
	$soft = function ( $s, $t ) {
		return vcheck( $s, $t, 'ar' )['soft'];
	};
	not_has( 'added_claim:guarantee', $soft( 'Long-term risks linked to unprotected exposure include', 'وتتضمن المخاطر طويلة الأجل المرتبطة بالتعرض دون حماية' ) );
	not_has( 'added_claim:guarantee', $soft( 'The kit includes a case', 'تتضمن المجموعة علبة' ) );
	not_has( 'added_claim:guarantee', $soft( 'The kit includes a case', 'يتضمن الطقم علبة' ) );
	has( 'added_claim:guarantee', $soft( 'Quality checks', 'فحوصات الجودة تضمن السلامة' ) );
	has( 'added_claim:guarantee', $soft( 'Quality checks', 'فحوصات الجودة وضمان السلامة' ) );
	not_has( 'added_claim:guarantee', $soft( 'Quality guarantee', 'ضمان الجودة' ) );
} );

test( 'text: URL boundaries with quotes, markdown and parentheses', function () {
	$m = function ( $s ) {
		return AITP_Text::matches( $s, array( AITP_Text::URL_REGEX ) );
	};
	eq( array( 'https://schema.org' ), $m( '“https://schema.org”' ) );
	eq( array( 'https://okayeyewear.com/x/' ), $m( '[Silicone loop](https://okayeyewear.com/x/)s need' ) );
	eq( array( 'https://en.wikipedia.org/wiki/Foo_(bar)' ), $m( 'See https://en.wikipedia.org/wiki/Foo_(bar).' ) );
	eq( array( 'www.okayeyewear.com' ), $m( 'Visit www.okayeyewear.com.' ) );
	eq( array(), vcheck( 'Visit “https://okayeyewear.com/contact/” today', 'Visitez « https://okayeyewear.com/contact/ » aujourd\'hui', 'fr_FR' )['hard'] );
} );

test( 'text: shortcodes vs bracketed words', function () {
	$p = AITP_Text::placeholder_patterns();
	eq( array( '[gallery ids="1,2"]', '[/caption]', '[number]' ), AITP_Text::matches( 'a [gallery ids="1,2"] b [/caption] c [number] d [Silicone loop]', $p ) );
} );

test( 'text: structured data detected', function () {
	ok( AITP_Text::is_structured_data( "{\n  &#8220;@context&#8221;: &#8220;https://schema.org&#8221;,\n  &#8220;@type&#8221;: &#8220;FAQPage&#8221;" ) );
	ok( AITP_Text::is_structured_data( '[{"a":1}]' ) );
	ok( ! AITP_Text::is_structured_data( '[Silicone loop] keeps glasses on' ) );
	ok( ! AITP_Text::is_structured_data( '{name} is ready' ) );
} );
