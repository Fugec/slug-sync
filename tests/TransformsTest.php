<?php

use PHPUnit\Framework\TestCase;

final class TransformsTest extends TestCase {

	public function test_latin_text_passes_through() {
		$this->assertSame( 'Blue Cotton Shirt', Slug_Sync_Transforms::transliterate( 'Blue Cotton Shirt' ) );
	}

	public function test_portable_fallback_maps_cyrillic_and_greek() {
		$this->assertSame( 'Kofevarka', Slug_Sync_Transforms::transliterate_fallback( 'Кофеварка' ) );
		$this->assertSame( 'Kafes', Slug_Sync_Transforms::transliterate_fallback( 'Καφες' ) );
	}

	public function test_transliteration_produces_ascii_for_cyrillic() {
		$out = Slug_Sync_Transforms::transliterate( 'Кофеварка Bosch' );

		$this->assertSame( 1, preg_match( '/^[\x20-\x7E]+$/', $out ), 'Expected ASCII-only output, got: ' . $out );
	}

	/**
	 * The bundled map owns Cyrillic and Greek, so the same title cannot
	 * produce one slug on a host with ext-intl and another on a host without.
	 *
	 * This asserts it on a machine that HAS intl: if transliterate() still
	 * equals transliterate_fallback(), then a host without intl -- where
	 * transliterate() *is* the fallback -- necessarily agrees.
	 *
	 * @dataProvider host_independent_titles
	 * @param string $title Source title.
	 */
	public function test_cyrillic_and_greek_do_not_depend_on_ext_intl( $title ) {
		$this->assertSame(
			Slug_Sync_Transforms::transliterate_fallback( $title ),
			Slug_Sync_Transforms::transliterate( $title ),
			'ext-intl changed the answer for: ' . $title
		);
	}

	public function host_independent_titles() {
		return array(
			array( 'Φίλτρου' ),
			array( 'Ευρώπη' ),
			array( 'Ναύπλιο' ),
			array( 'Θεσσαλονίκη' ),
			array( 'Καφετιέρα Φίλτρου Deluxe' ),
			array( 'Кофеварка Эспрессо Про' ),
			array( 'Україна' ),
			array( 'Ђорђе' ),
		);
	}

	/**
	 * ELOT 743 reads these as pairs, which a per-character map cannot see.
	 *
	 * @dataProvider greek_digraph_cases
	 * @param string $expected Expected transliteration.
	 * @param string $title    Source title.
	 */
	public function test_greek_digraphs_follow_elot_743( $expected, $title ) {
		$this->assertSame( $expected, Slug_Sync_Transforms::transliterate( $title ) );
	}

	public function greek_digraph_cases() {
		return array(
			// "ου" is "ou", never the character-by-character "oy".
			'ou is a pair'                => array( 'Filtrou', 'Φίλτρου' ),
			'ou keeps its case'           => array( 'OUZO', 'ΟΥΖΟ' ),
			'ou after a consonant'        => array( 'Kouzina', 'Κουζίνα' ),
			// "αυ/ευ/ηυ" take v before a vowel or voiced consonant.
			'ev before a vowel'           => array( 'Evropi', 'Ευρώπη' ),
			'av before a voiced consonant' => array( 'Avgoustos', 'Αύγουστος' ),
			'av before a liquid'          => array( 'Pavlos', 'Παύλος' ),
			// ...and f everywhere else.
			'af before a voiceless stop'  => array( 'Nafplio', 'Ναύπλιο' ),
			'ef before a voiceless stop'  => array( 'Lefkada', 'Λευκάδα' ),
			'af before tau'               => array( 'Aftomato', 'Αυτόματο' ),
			// Modern Greek eta is i, not the ancient e ICU reaches for.
			'eta is i'                    => array( 'Thessaloniki', 'Θεσσαλονίκη' ),
		);
	}

	/**
	 * Scripts the bundled map does not cover are still handed to intl, so the
	 * map-first order adds determinism without narrowing coverage.
	 *
	 * @requires extension intl
	 */
	public function test_intl_still_handles_scripts_outside_the_bundled_map() {
		$this->assertSame( 'ka fei ji', Slug_Sync_Transforms::transliterate( '咖啡机' ) );
		$this->assertSame( 'Kofe ka fei ji', Slug_Sync_Transforms::transliterate( 'Кофе 咖啡机' ) );
		$this->assertSame( 'makynt qhwt rbyt', Slug_Sync_Transforms::transliterate( 'ماكينة قهوة عربية' ) );
	}

	/**
	 * Tidying a removal must not slice a multi-byte character in half.
	 *
	 * trim() takes a byte list, so trimming the en/em dash also trimmed their
	 * bytes 0xE2/0x80/0x93/0x94 -- and Cyrillic "р" ends 0x80. "товар-12882"
	 * came back as invalid UTF-8, and because a single-token title cannot hit
	 * the two-word guard, that corruption reached the slug.
	 *
	 * @dataProvider multibyte_tails
	 * @param string $expected Expected source after removal.
	 * @param string $title    Product title.
	 * @param string $sku      Assigned SKU.
	 */
	public function test_removal_never_corrupts_multibyte_characters( $expected, $title, $sku ) {
		$out = Slug_Sync_Transforms::remove_exact_sku( $title, $sku );

		$this->assertTrue(
			(bool) preg_match( '//u', $out ),
			'Removal produced invalid UTF-8 for: ' . $title
		);
		$this->assertSame( $expected, $out );
	}

	public function multibyte_tails() {
		return array(
			// Cyrillic "р" is 0xD1 0x80; the 0x80 was being trimmed away.
			'single token ending in er' => array( 'товар', 'товар-12882', '12882' ),
			'longer single token'       => array( 'прибор', 'прибор-77', '77' ),
			// Greek "π" is 0xCF 0x80, "Γ" is 0xCE 0x93, "Δ" is 0xCE 0x94.
			'greek pi tail'             => array( 'Καφέ π', 'Καφέ π-12', '12' ),
			'greek gamma tail'          => array( 'Γάλα Γ', 'Γάλα Γ-5', '5' ),
			'greek delta tail'          => array( 'Σπίτι Δ', 'Σπίτι Δ-8', '8' ),
			// Latin is unaffected, and must stay that way.
			'latin is unchanged'        => array( 'Blue Cotton Shirt', 'Blue Cotton Shirt SKU 4823-BLK', '4823-BLK' ),
		);
	}

	public function test_exact_sku_and_its_label_are_removed_and_tidied() {
		$this->assertSame(
			'Blue Cotton Shirt',
			Slug_Sync_Transforms::remove_exact_sku( 'Blue Cotton Shirt - SKU: BCS-500', 'BCS-500' )
		);
		$this->assertSame(
			'Blue Cotton Shirt',
			Slug_Sync_Transforms::remove_exact_sku( 'Blue Cotton Shirt (BCS-500)', 'bcs-500' )
		);
	}

	public function test_sku_matching_is_bounded_and_other_codes_remain() {
		$this->assertSame(
			'Wireless Mouse 1500 B09XYZ123',
			Slug_Sync_Transforms::remove_exact_sku( 'Wireless Mouse 1500 B09XYZ123', '15' )
		);
		$this->assertSame(
			'Wireless Mouse B09XYZ123',
			Slug_Sync_Transforms::remove_exact_sku( 'Wireless Mouse B09XYZ123 SKU BCS-500', 'BCS-500' )
		);
	}

	public function test_sku_removal_will_not_destroy_the_product_name() {
		$this->assertSame(
			'Sony WH-1000XM5',
			Slug_Sync_Transforms::remove_exact_sku( 'Sony WH-1000XM5', 'WH-1000XM5' )
		);
		$this->assertSame( 'BCS-500', Slug_Sync_Transforms::remove_exact_sku( 'BCS-500', 'BCS-500' ) );
	}

	public function test_exact_assigned_sku_is_added_when_absent() {
		$this->assertSame(
			'Blue Cotton Shirt BCS-500',
			Slug_Sync_Transforms::add_exact_sku( 'Blue Cotton Shirt', 'BCS-500' )
		);
		$this->assertSame(
			'Mouse 1500 Model 15',
			Slug_Sync_Transforms::add_exact_sku( 'Mouse 1500 Model', '15' )
		);
	}

	public function test_assigned_sku_is_not_added_twice() {
		$this->assertSame(
			'Blue Cotton Shirt SKU: bcs-500',
			Slug_Sync_Transforms::add_exact_sku( 'Blue Cotton Shirt SKU: bcs-500', 'BCS-500' )
		);
	}

	public function test_an_unreadable_title_is_left_alone_rather_than_doubled() {
		// Malformed UTF-8 makes preg_match return false under /u. False is
		// falsy, so a boolean test read "no match" and appended a code the
		// title already carried.
		$broken = "Blue \xC3\x28 Shirt bcs-500";

		$this->assertSame( $broken, Slug_Sync_Transforms::add_exact_sku( $broken, 'BCS-500' ) );
		$this->assertSame( 1, preg_match( '/bcs-500/i', Slug_Sync_Transforms::add_exact_sku( $broken, 'BCS-500' ) ) );
	}

	public function test_non_string_inputs_are_safe() {
		$this->assertSame( '', Slug_Sync_Transforms::transliterate( null ) );
		$this->assertSame( '', Slug_Sync_Transforms::remove_exact_sku( null, 'ABC' ) );
		$this->assertSame( 'Blue Shirt', Slug_Sync_Transforms::remove_exact_sku( 'Blue Shirt', array( 'ABC' ) ) );
		$this->assertSame( '', Slug_Sync_Transforms::add_exact_sku( null, 'ABC' ) );
		$this->assertSame( 'Blue Shirt', Slug_Sync_Transforms::add_exact_sku( 'Blue Shirt', array( 'ABC' ) ) );
		$this->assertSame( 'Blue Shirt', Slug_Sync_Transforms::add_exact_sku( 'Blue Shirt', '---' ) );
	}
}
