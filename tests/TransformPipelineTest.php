<?php

use PHPUnit\Framework\TestCase;

final class TransformPipelineTest extends TestCase {

	private $transform;
	private $label;

	protected function setUp(): void {
		$reflection = new ReflectionClass( 'Slug_Sync' );
		$this->transform = $reflection->getMethod( 'transform_source_title' );
		$this->transform->setAccessible( true );
		$this->label = $reflection->getMethod( 'transformation_label' );
		$this->label->setAccessible( true );
		$GLOBALS['slug_sync_test_meta'] = array();
	}

	public function test_selected_transformations_run_together_and_explain_the_report_row() {
		$GLOBALS['slug_sync_test_meta'][42]['_sku'] = 'BCS-500';
		$row = (object) array( 'ID' => 42 );

		$result = $this->transform->invoke( null, 'Кофеварка Shirt - SKU BCS-500', $row, 'product', true, true, false );

		$this->assertSame( 'Kofevarka Shirt', $result['source'] );
		$this->assertSame( array( 'assigned SKU removed', 'transliterated to Latin' ), $result['notes'] );
	}

	public function test_sku_option_is_ignored_outside_products() {
		$GLOBALS['slug_sync_test_meta'][42]['_sku'] = 'BCS-500';
		$row = (object) array( 'ID' => 42 );

		$result = $this->transform->invoke( null, 'Blue Shirt BCS-500', $row, 'post', false, true, true );

		$this->assertSame( 'Blue Shirt BCS-500', $result['source'] );
		$this->assertSame( array(), $result['notes'] );
	}

	public function test_assigned_sku_can_be_added_from_product_data_and_transliterated() {
		$GLOBALS['slug_sync_test_meta'][42]['_sku'] = 'БС-500';
		$row = (object) array( 'ID' => 42 );

		$result = $this->transform->invoke( null, 'Кофеварка Shirt', $row, 'product', true, false, true );

		$this->assertSame( 'Kofevarka Shirt BS-500', $result['source'] );
		$this->assertSame( array( 'assigned SKU added', 'transliterated to Latin' ), $result['notes'] );
	}

	public function test_remove_takes_precedence_if_invalid_input_requests_both_sku_modes() {
		$GLOBALS['slug_sync_test_meta'][42]['_sku'] = 'BCS-500';
		$row = (object) array( 'ID' => 42 );

		$result = $this->transform->invoke( null, 'Blue Shirt BCS-500', $row, 'product', false, true, true );

		$this->assertSame( 'Blue Shirt', $result['source'] );
		$this->assertSame( array( 'assigned SKU removed' ), $result['notes'] );
	}

	public function test_run_summary_names_the_exact_slug_building_choices() {
		$this->assertSame( 'Title only', $this->label->invoke( null, array() ) );
		$this->assertSame(
			'Latin transliteration · exact SKU removal',
			$this->label->invoke( null, array( 'transliterate' => true, 'remove_sku' => true ) )
		);
		$this->assertSame(
			'add-on rules · Latin transliteration',
			$this->label->invoke( null, array( 'addon_rules' => true, 'transliterate' => true ) )
		);
		$this->assertSame(
			'assigned SKU included',
			$this->label->invoke( null, array( 'include_sku' => true ) )
		);
	}
}
