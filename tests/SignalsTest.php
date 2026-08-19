<?php

use PHPUnit\Framework\TestCase;

final class SignalsTest extends TestCase {

	public function test_plain_title_has_no_signals() {
		$this->assertSame(
			array(
				'code'      => false,
				'stopword'  => false,
				'non_latin' => false,
			),
			Slug_Sync_Signals::detect( 'Blue Cotton Shirt' )
		);
	}

	public function test_sku_prefix_is_a_code() {
		$signals = Slug_Sync_Signals::detect( 'Nike Air Max 90 - White - SKU 4823-BLK' );
		$this->assertTrue( $signals['code'] );
	}

	public function test_bare_alphanumeric_token_is_a_code() {
		$signals = Slug_Sync_Signals::detect( 'Wireless Mouse B09XYZ123' );
		$this->assertTrue( $signals['code'] );
	}

	public function test_long_digit_run_is_a_code() {
		$signals = Slug_Sync_Signals::detect( 'Replacement Filter 4820391' );
		$this->assertTrue( $signals['code'] );
	}

	public function test_short_model_number_is_not_a_code() {
		$signals = Slug_Sync_Signals::detect( 'iPhone 15' );
		$this->assertFalse( $signals['code'] );
	}

	public function test_bracketed_code_is_a_code() {
		$signals = Slug_Sync_Signals::detect( 'Desk Lamp (AB-1234)' );
		$this->assertTrue( $signals['code'] );
	}

	public function test_stopwords_are_detected() {
		$signals = Slug_Sync_Signals::detect( 'The Best of the Summer Collection' );
		$this->assertTrue( $signals['stopword'] );
	}

	public function test_cyrillic_is_non_latin() {
		$signals = Slug_Sync_Signals::detect( 'Кофеварка Bosch' );
		$this->assertTrue( $signals['non_latin'] );
	}

	public function test_latin_diacritics_are_not_non_latin() {
		$signals = Slug_Sync_Signals::detect( 'Čokoladni Kolač' );
		$this->assertFalse( $signals['non_latin'] );
	}
}
