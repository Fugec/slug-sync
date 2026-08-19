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

	/* Boundary cases. These pin the exact thresholds is_code_token() documents;
	   without them a mutation loosening either threshold passes unnoticed. */

	public function test_five_digit_run_is_not_a_code() {
		$this->assertFalse( Slug_Sync_Signals::detect( 'Replacement Filter 48203' )['code'] );
	}

	public function test_six_digit_run_is_a_code() {
		$this->assertTrue( Slug_Sync_Signals::detect( 'Replacement Filter 482039' )['code'] );
	}

	public function test_three_digits_in_mixed_token_is_not_a_code() {
		$this->assertFalse( Slug_Sync_Signals::detect( 'Spare Part AB123' )['code'] );
	}

	public function test_four_digits_in_mixed_token_is_a_code() {
		$this->assertTrue( Slug_Sync_Signals::detect( 'Spare Part AB1234' )['code'] );
	}

	public function test_dosage_is_not_a_code() {
		$this->assertFalse( Slug_Sync_Signals::detect( 'Ibuprofen 400mg Tablets' )['code'] );
	}

	public function test_iu_dosage_is_not_a_code() {
		$this->assertFalse( Slug_Sync_Signals::detect( 'Vitamin D3 10000 IU' )['code'] );
	}

	public function test_measurement_is_not_a_code() {
		$this->assertFalse( Slug_Sync_Signals::detect( 'Screws M4 x 20mm' )['code'] );
	}

	public function test_pack_quantity_is_not_a_code() {
		$this->assertFalse( Slug_Sync_Signals::detect( 'AAA Batteries 4-Pack' )['code'] );
	}

	public function test_fused_model_number_is_not_a_code() {
		$this->assertFalse( Slug_Sync_Signals::detect( 'HP LaserJet Pro M404dn' )['code'] );
	}

	public function test_short_model_designation_is_not_a_code() {
		$this->assertFalse( Slug_Sync_Signals::detect( 'Samsung Galaxy S23 Ultra' )['code'] );
	}

	public function test_three_digit_style_number_is_not_a_code() {
		$this->assertFalse( Slug_Sync_Signals::detect( "Levi's 501 Original Fit" )['code'] );
	}

	public function test_large_capacity_measurement_is_not_a_code() {
		$this->assertFalse( Slug_Sync_Signals::detect( 'Power Bank 20000mAh' )['code'] );
	}

	public function test_four_digit_dosage_is_not_a_code() {
		$this->assertFalse( Slug_Sync_Signals::detect( 'Vitamin C 1000mg Tablets' )['code'] );
	}

	public function test_rated_model_designation_is_not_a_code() {
		$this->assertFalse( Slug_Sync_Signals::detect( 'Bosch GSB 18V-55' )['code'] );
	}
}
