<?php
/**
 * Regression tests for the bridge CTR report (issue #201).
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/abilities/get-bridge-ctr.php';

/**
 * Verify bridge CTR aggregation honors the canonical bot stamp.
 */
final class GetBridgeCtrTest extends TestCase {

	/**
	 * Clear fixture state after each test.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['extrachill_analytics_event_fixture_rows'] );
	}

	/**
	 * Bot-stamped scanner rows must not reach totals or destination cuts.
	 */
	public function test_bot_stamped_rows_are_excluded_from_all_aggregation(): void {
		$GLOBALS['extrachill_analytics_event_fixture_rows'] = array(
			(object) array(
				'event_type' => 'bridge_click',
				'event_data' => array(
					'dest_site' => 'select198766667891',
					'is_bot'    => true,
				),
			),
			(object) array(
				'event_type' => 'bridge_impression',
				'event_data' => array(
					'dest_site' => 'etcpasswd',
					'is_bot'    => true,
				),
			),
		);

		$report = extrachill_analytics_ability_get_bridge_ctr( array( 'days' => 0 ) );

		$this->assertSame( 0, $report['clicks'] );
		$this->assertSame( 0, $report['impressions'] );
		$this->assertSame( array(), $report['by_dest_site'] );
	}

	/**
	 * Explicit human and legacy missing-stamp rows must remain eligible.
	 */
	public function test_false_and_missing_bot_stamps_remain_eligible(): void {
		$GLOBALS['extrachill_analytics_event_fixture_rows'] = array(
			(object) array(
				'event_type' => 'bridge_click',
				'event_data' => array(
					'dest_site' => 'events',
					'is_bot'    => false,
				),
			),
			(object) array(
				'event_type' => 'bridge_impression',
				'event_data' => array(
					'dest_site' => 'events',
				),
			),
		);

		$report = extrachill_analytics_ability_get_bridge_ctr( array( 'days' => 0 ) );

		$this->assertSame( 1, $report['clicks'] );
		$this->assertSame( 1, $report['impressions'] );
		$this->assertSame( 1.0, $report['ctr'] );
		$this->assertSame(
			array(
				array(
					'dest_site'   => 'events',
					'clicks'      => 1,
					'impressions' => 1,
					'ctr'         => 1.0,
				),
			),
			$report['by_dest_site']
		);
		$this->assertStringContainsString( 'legacy missing-stamp rows remain eligible', $report['note'] );
	}
}
