<?php
/**
 * Content-format category-map tests.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/content-format-classifier.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/get-content-revenue.php';

/**
 * Verify conservative taxonomy coverage additions preserve revenue semantics.
 */
final class ContentFormatClassifierTest extends TestCase {

	/**
	 * High-revenue editorial categories map to their existing semantic formats.
	 */
	public function test_high_revenue_categories_use_existing_formats(): void {
		$map = extrachill_analytics_format_category_map();

		$this->assertContains( 'famous-guitars', $map['guitar-history'] );
		$this->assertContains( 'band-art', $map['music-history'] );
		$this->assertContains( 'musical-curiosities', $map['music-history'] );
	}

	/**
	 * A format label changes only the rollup bucket, never resolved totals.
	 */
	public function test_reclassification_preserves_resolved_revenue_totals(): void {
		$record = array(
			'is_content' => true,
			'page_key'   => 'p145',
			'categories' => array( 'band-art' ),
			'views'      => 1250,
			'revenue'    => 37.50,
			'url'        => '/album-art-history/',
		);

		$before = extrachill_analytics_revenue_build_rollups(
			array( array_merge( $record, array( 'format' => 'uncategorized' ) ) ),
			'format'
		);
		$after  = extrachill_analytics_revenue_build_rollups(
			array( array_merge( $record, array( 'format' => 'music-history' ) ) ),
			'format'
		);

		$this->assertSame( $before['totals'], $after['totals'] );
		$this->assertSame( $before['unresolved'], $after['unresolved'] );
		$this->assertSame( 'music-history', $after['rollups']['by_format'][0]['bucket'] );
	}
}
