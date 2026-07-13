<?php
/**
 * Behavioral + contract tests for the content-revenue diagnostics lens (#141).
 *
 * The pure core (every check's status/evidence/totals + the summary rollup) is
 * unit-tested directly against a synthesized snapshot; the WordPress-dependent
 * resolution gate is locked down via a source-string contract on the callback.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/abilities/get-content-revenue-diagnostics.php';

/**
 * Verify diagnostics contracts.
 */
final class GetContentRevenueDiagnosticsTest extends TestCase {

	/**
	 * Helper: a normalized row.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	private function row( array $over = array() ) {
		return array_merge(
			array(
				'period_label'             => '2026-05',
				'import_batch'             => 'mv-2026-05',
				'period_start'             => '2026-05-01',
				'period_end'               => '2026-05-31',
				'imported_at'              => gmdate( 'Y-m-d H:i:s' ),
				'slug'                     => '/some-post/',
				'post_id'                  => 1,
				'is_content'               => true,
				'route_family'             => '',
				'format'                   => 'song-meaning',
				'views'                    => 1000,
				'revenue'                  => 100.0,
				'source_rpm'               => 100.0,
				'cpm'                      => 5.0,
				'viewability'              => 80.0,
				'fill_rate'                => 90.0,
				'impressions_per_pageview' => 2.0,
				'derived_rpm'              => 100.0,
			),
			$over
		);
	}

	/**
	 * Helper: a period-batch aggregate.
	 *
	 * @param array $over Overrides.
	 * @return array
	 */
	private function period( array $over = array() ) {
		return array_merge(
			array(
				'period_label' => '2026-05',
				'import_batch' => 'mv-2026-05',
				'period_start' => '2026-05-01',
				'period_end'   => '2026-05-31',
				'rows'         => 100,
				'revenue'      => 1000.0,
				'views'        => 50000,
				'imported_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			$over
		);
	}

	/**
	 * Helper: find one check by name in a built result.
	 *
	 * @param array  $built Built diagnostics.
	 * @param string $name  Check name.
	 * @return array|null
	 */
	private function check( array $built, $name ) {
		foreach ( $built['checks'] as $c ) {
			if ( $name === $c['check'] ) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * Empty store → freshness fails, overall fail.
	 */
	public function test_empty_store_freshness_fails(): void {
		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => array(),
				'rows'              => array(),
				'timeseries_totals' => array(
					'views'   => 0,
					'revenue' => 0.0,
				),
			)
		);

		$freshness = $this->check( $built, 'latest_period_freshness' );
		$this->assertSame( 'fail', $freshness['status'] );
		$this->assertSame( 'fail', $built['overall_status'] );
	}

	/**
	 * Only the flat "all-time" bucket → freshness warns (no dated periods).
	 */
	public function test_only_alltime_bucket_warns(): void {
		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => array(
					$this->period(
						array(
							'period_label' => 'all-time',
							'period_start' => null,
							'period_end'   => null,
						)
					),
				),
				'rows'              => array( $this->row() ),
				'timeseries_totals' => array(
					'views'   => 1000,
					'revenue' => 100.0,
				),
			)
		);

		$freshness = $this->check( $built, 'latest_period_freshness' );
		$this->assertSame( 'warning', $freshness['status'] );
	}

	/**
	 * A recent dated period → freshness passes.
	 */
	public function test_recent_dated_period_passes_freshness(): void {
		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => array( $this->period() ),
				'rows'              => array( $this->row() ),
				'timeseries_totals' => array(
					'views'   => 1000,
					'revenue' => 100.0,
				),
			)
		);

		$freshness = $this->check( $built, 'latest_period_freshness' );
		$this->assertSame( 'pass', $freshness['status'] );
	}

	/**
	 * A dated period imported >45 days ago → freshness warns (stale).
	 */
	public function test_stale_dated_period_warns(): void {
		$stale = gmdate( 'Y-m-d H:i:s', strtotime( '-60 days' ) );

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => array( $this->period( array( 'imported_at' => $stale ) ) ),
				'rows'              => array( $this->row( array( 'imported_at' => $stale ) ) ),
				'timeseries_totals' => array(
					'views'   => 1000,
					'revenue' => 100.0,
				),
			)
		);

		$freshness = $this->check( $built, 'latest_period_freshness' );
		$this->assertSame( 'warning', $freshness['status'] );
	}

	/**
	 * Contiguous monthly periods → missing_periods passes.
	 */
	public function test_contiguous_periods_pass(): void {
		$periods = array();
		foreach ( array( '2026-03', '2026-04', '2026-05' ) as $label ) {
			$periods[] = $this->period(
				array(
					'period_label' => $label,
					'period_start' => $label . '-01',
				)
			);
		}

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => $periods,
				'rows'              => array( $this->row() ),
				'timeseries_totals' => array(
					'views'   => 1000,
					'revenue' => 100.0,
				),
			)
		);

		$missing = $this->check( $built, 'missing_periods' );
		$this->assertSame( 'pass', $missing['status'] );
		$this->assertSame( 0, $missing['totals']['missing_periods'] );
	}

	/**
	 * A gap in the monthly sequence → missing_periods warns with the gap listed.
	 */
	public function test_gap_in_periods_warns(): void {
		$periods = array();
		foreach ( array( '2026-03', '2026-05' ) as $label ) {
			$periods[] = $this->period(
				array(
					'period_label' => $label,
					'period_start' => $label . '-01',
				)
			);
		}

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => $periods,
				'rows'              => array( $this->row() ),
				'timeseries_totals' => array(
					'views'   => 1000,
					'revenue' => 100.0,
				),
			)
		);

		$missing = $this->check( $built, 'missing_periods' );
		$this->assertSame( 'warning', $missing['status'] );
		$this->assertContains( '2026-04', $missing['totals']['missing'] );
	}

	/**
	 * Same period_label across two batches → duplicate_period_batches warns.
	 */
	public function test_duplicate_period_batches_warns(): void {
		$periods = array(
			$this->period(
				array(
					'period_label' => '2026-05',
					'import_batch' => 'batch-a',
				)
			),
			$this->period(
				array(
					'period_label' => '2026-05',
					'import_batch' => 'batch-b',
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => $periods,
				'rows'              => array( $this->row() ),
				'timeseries_totals' => array(
					'views'   => 1000,
					'revenue' => 100.0,
				),
			)
		);

		$dup = $this->check( $built, 'duplicate_period_batches' );
		$this->assertSame( 'warning', $dup['status'] );
		$this->assertSame( 1, $dup['totals']['duplicate_labels'] );
		$this->assertArrayHasKey( '2026-05', $dup['totals']['duplicates'] );
	}

	/**
	 * Row-sum equals timeseries → reconciliation passes.
	 */
	public function test_reconciliation_passes_when_row_sum_matches(): void {
		$rows = array(
			$this->row(
				array(
					'views'   => 1000,
					'revenue' => 100.0,
				)
			),
			$this->row(
				array(
					'slug'    => '/other/',
					'views'   => 500,
					'revenue' => 25.0,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => array( $this->period() ),
				'rows'              => $rows,
				'timeseries_totals' => array(
					'views'   => 1500,
					'revenue' => 125.0,
				),
			)
		);

		$recon = $this->check( $built, 'totals_reconciliation' );
		$this->assertSame( 'pass', $recon['status'] );
		$this->assertTrue( $recon['totals']['reconciled'] );
	}

	/**
	 * Row-sum diverges from timeseries → reconciliation FAILS.
	 */
	public function test_reconciliation_fails_on_mismatch(): void {
		$rows = array(
			$this->row(
				array(
					'views'   => 1000,
					'revenue' => 100.0,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => array( $this->period() ),
				'rows'              => $rows,
				'timeseries_totals' => array(
					'views'   => 2000,
					'revenue' => 999.0,
				),
			)
		);

		$recon = $this->check( $built, 'totals_reconciliation' );
		$this->assertSame( 'fail', $recon['status'] );
		$this->assertFalse( $recon['totals']['reconciled'] );
	}

	/**
	 * High unresolved ratio → resolution_coverage warns; below threshold passes.
	 */
	public function test_resolution_coverage_thresholds(): void {
		$rows = array();
		// 2 resolved, 3 unresolved of 5 total = 60% unresolved → warning.
		$rows[] = $this->row( array( 'is_content' => true ) );
		$rows[] = $this->row(
			array(
				'slug'       => '/a/',
				'is_content' => true,
			)
		);
		$rows[] = $this->row(
			array(
				'slug'         => '/home/',
				'is_content'   => false,
				'route_family' => 'home',
			)
		);
		$rows[] = $this->row(
			array(
				'slug'         => '/page/2/',
				'is_content'   => false,
				'route_family' => 'pagination',
			)
		);
		$rows[] = $this->row(
			array(
				'slug'         => '/old.html',
				'is_content'   => false,
				'route_family' => 'legacy-html',
			)
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => array( $this->period() ),
				'rows'              => $rows,
				'timeseries_totals' => array(
					'views'   => 8000,
					'revenue' => 107.0,
				),
			)
		);

		$cov = $this->check( $built, 'resolution_coverage' );
		$this->assertSame( 'warning', $cov['status'] );
		$this->assertSame( 3, $cov['totals']['unresolved_rows'] );

		// Now mostly resolved → pass.
		$rows2 = array();
		for ( $i = 0; $i < 8; $i++ ) {
			$rows2[] = $this->row(
				array(
					'slug'       => '/r' . $i . '/',
					'is_content' => true,
				)
			);
		}
		$rows2[] = $this->row(
			array(
				'slug'         => '/home/',
				'is_content'   => false,
				'route_family' => 'home',
			)
		);

		$ts_views = 0;
		$ts_rev   = 0.0;
		foreach ( $rows2 as $r ) {
			$ts_views += $r['views'];
			$ts_rev   += $r['revenue'];
		}

		$built2 = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => array( $this->period() ),
				'rows'              => $rows2,
				'timeseries_totals' => array(
					'views'   => $ts_views,
					'revenue' => $ts_rev,
				),
			)
		);

		$cov2 = $this->check( $built2, 'resolution_coverage' );
		$this->assertSame( 'pass', $cov2['status'] );
	}

	/**
	 * High uncategorized ratio → format_coverage warns.
	 */
	public function test_format_coverage_warns_on_high_uncategorized(): void {
		$rows = array();
		// 2 classified, 3 uncategorized of 5 resolved = 60% → warning (>30%).
		$rows[] = $this->row( array( 'format' => 'song-meaning' ) );
		$rows[] = $this->row(
			array(
				'slug'   => '/b/',
				'format' => 'news',
			)
		);
		$rows[] = $this->row(
			array(
				'slug'   => '/c/',
				'format' => 'uncategorized',
			)
		);
		$rows[] = $this->row(
			array(
				'slug'   => '/d/',
				'format' => 'uncategorized',
			)
		);
		$rows[] = $this->row(
			array(
				'slug'   => '/e/',
				'format' => 'uncategorized',
			)
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => array( $this->period() ),
				'rows'              => $rows,
				'timeseries_totals' => array(
					'views'   => 5000,
					'revenue' => 500.0,
				),
			)
		);

		$fmt = $this->check( $built, 'format_coverage' );
		$this->assertSame( 'warning', $fmt['status'] );
		$this->assertSame( 3, $fmt['totals']['uncategorized_rows'] );
	}

	/**
	 * Zero-view revenue → warning, revenue preserved in totals.
	 */
	public function test_zero_view_revenue_warns(): void {
		$rows = array(
			$this->row(
				array(
					'slug'    => '/z/',
					'views'   => 0,
					'revenue' => 12.50,
				)
			),
			$this->row(
				array(
					'slug'    => '/ok/',
					'views'   => 1000,
					'revenue' => 100.0,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => array( $this->period() ),
				'rows'              => $rows,
				'timeseries_totals' => array(
					'views'   => 1000,
					'revenue' => 112.5,
				),
			)
		);

		$zv = $this->check( $built, 'zero_view_revenue' );
		$this->assertSame( 'warning', $zv['status'] );
		$this->assertSame( 1, $zv['totals']['rows'] );
		$this->assertEquals( 12.5, $zv['totals']['revenue'] );
	}

	/**
	 * Views-positive zero-revenue rows → warning above 20%.
	 */
	public function test_views_zero_revenue_warns_above_threshold(): void {
		$rows = array();
		// 3 of 10 rows have views but $0 revenue = 30% → warning.
		for ( $i = 0; $i < 7; $i++ ) {
			$rows[] = $this->row(
				array(
					'slug'    => '/r' . $i . '/',
					'views'   => 1000,
					'revenue' => 10.0,
				)
			);
		}
		for ( $i = 0; $i < 3; $i++ ) {
			$rows[] = $this->row(
				array(
					'slug'    => '/z' . $i . '/',
					'views'   => 500,
					'revenue' => 0.0,
				)
			);
		}

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => array( $this->period() ),
				'rows'              => $rows,
				'timeseries_totals' => array(
					'views'   => 8500,
					'revenue' => 70.0,
				),
			)
		);

		$vz = $this->check( $built, 'views_zero_revenue' );
		$this->assertSame( 'warning', $vz['status'] );
		$this->assertSame( 3, $vz['totals']['rows'] );
	}

	/**
	 * Negative revenue → negative_impossible_values FAILS.
	 */
	public function test_negative_values_fail(): void {
		$rows = array(
			$this->row(
				array(
					'slug'    => '/bad/',
					'revenue' => -5.0,
				)
			),
			$this->row(
				array(
					'slug'    => '/ok/',
					'revenue' => 10.0,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => array( $this->period() ),
				'rows'              => $rows,
				'timeseries_totals' => array(
					'views'   => 2000,
					'revenue' => 5.0,
				),
			)
		);

		$neg = $this->check( $built, 'negative_impossible_values' );
		$this->assertSame( 'fail', $neg['status'] );
		$this->assertSame( 1, $neg['totals']['rows'] );
		// A fail here drives the overall status to fail.
		$this->assertSame( 'fail', $built['overall_status'] );
	}

	/**
	 * Negative views → also fail.
	 */
	public function test_negative_views_fail(): void {
		$rows = array(
			$this->row(
				array(
					'slug'  => '/bad/',
					'views' => -100,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => array( $this->period() ),
				'rows'              => $rows,
				'timeseries_totals' => array(
					'views'   => -100,
					'revenue' => 100.0,
				),
			)
		);

		$neg = $this->check( $built, 'negative_impossible_values' );
		$this->assertSame( 'fail', $neg['status'] );
	}

	/**
	 * Source anomalies (zero-view revenue, variance) do NOT fail on their own.
	 */
	public function test_source_anomalies_are_warnings_not_failures(): void {
		// 1000 views, $100 revenue → derived RPM 100. Source says 50 → 100% variance.
		$rows = array(
			$this->row(
				array(
					'slug'       => '/v/',
					'views'      => 0,
					'revenue'    => 5.0,
					'source_rpm' => 0.0,
				)
			),
			$this->row(
				array(
					'slug'        => '/ok/',
					'views'       => 1000,
					'revenue'     => 100.0,
					'source_rpm'  => 50.0,
					'derived_rpm' => 100.0,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => array( $this->period() ),
				'rows'              => $rows,
				'timeseries_totals' => array(
					'views'   => 1000,
					'revenue' => 105.0,
				),
			)
		);

		$this->assertSame( 'warning', $this->check( $built, 'zero_view_revenue' )['status'] );
		$this->assertSame( 'warning', $this->check( $built, 'stored_vs_derived_rpm_variance' )['status'] );
		$this->assertSame( 'pass', $this->check( $built, 'negative_impossible_values' )['status'] );
		// No fail-level check → overall is warning, not fail.
		$this->assertSame( 'warning', $built['overall_status'] );
	}

	/**
	 * Stored-vs-derived RPM variance flags rows that disagree by >20%.
	 */
	public function test_rpm_variance_flags_high_disagreement(): void {
		$rows = array();
		// 6 views-positive rows; 2 disagree by >20% → 33% → warning (>10%).
		// Agreeing: views 1000, revenue 100 → derived 100, source 100.
		for ( $i = 0; $i < 4; $i++ ) {
			$rows[] = $this->row(
				array(
					'slug'        => '/ok' . $i . '/',
					'views'       => 1000,
					'revenue'     => 100.0,
					'source_rpm'  => 100.0,
					'derived_rpm' => 100.0,
				)
			);
		}
		// Disagreeing: derived 100, source 50 → 100% variance.
		for ( $i = 0; $i < 2; $i++ ) {
			$rows[] = $this->row(
				array(
					'slug'        => '/v' . $i . '/',
					'views'       => 1000,
					'revenue'     => 100.0,
					'source_rpm'  => 50.0,
					'derived_rpm' => 100.0,
				)
			);
		}

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => array( $this->period() ),
				'rows'              => $rows,
				'timeseries_totals' => array(
					'views'   => 6000,
					'revenue' => 600.0,
				),
			)
		);

		$var = $this->check( $built, 'stored_vs_derived_rpm_variance' );
		$this->assertSame( 'warning', $var['status'] );
		$this->assertSame( 6, $var['totals']['checked'] );
		$this->assertSame( 2, $var['totals']['high_variance'] );
	}

	/**
	 * Missing import_batch → provenance_coverage warns.
	 */
	public function test_provenance_coverage_warns_on_missing_batch(): void {
		$rows = array(
			$this->row( array( 'import_batch' => '' ) ),
			$this->row( array( 'slug' => '/ok/' ) ),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => array( $this->period() ),
				'rows'              => $rows,
				'timeseries_totals' => array(
					'views'   => 2000,
					'revenue' => 200.0,
				),
			)
		);

		$prov = $this->check( $built, 'provenance_coverage' );
		$this->assertSame( 'warning', $prov['status'] );
		$this->assertSame( 1, $prov['totals']['missing_batch'] );
	}

	/**
	 * A clean store → all pass, overall pass.
	 */
	public function test_clean_store_overall_pass(): void {
		$periods = array();
		foreach ( array( '2026-04', '2026-05', '2026-06' ) as $label ) {
			$periods[] = $this->period(
				array(
					'period_label' => $label,
					'period_start' => $label . '-01',
				)
			);
		}

		$rows = array();
		for ( $i = 0; $i < 10; $i++ ) {
			$rows[] = $this->row(
				array(
					'slug'        => '/r' . $i . '/',
					'views'       => 1000,
					'revenue'     => 100.0,
					'source_rpm'  => 100.0,
					'derived_rpm' => 100.0,
					'format'      => 'song-meaning',
				)
			);
		}

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => $periods,
				'rows'              => $rows,
				'timeseries_totals' => array(
					'views'   => 10000,
					'revenue' => 1000.0,
				),
			)
		);

		$this->assertSame( 'pass', $built['overall_status'] );
		$this->assertSame( 11, $built['summary']['total'] );
		$this->assertSame( 11, $built['summary']['pass'] );
		$this->assertSame( 0, $built['summary']['warning'] );
		$this->assertSame( 0, $built['summary']['fail'] );
	}

	/**
	 * Overall status precedence: fail beats warning beats pass.
	 */
	public function test_overall_status_fail_beats_warning(): void {
		// A negative value (fail) alongside a clean period.
		$rows = array(
			$this->row(
				array(
					'slug'    => '/bad/',
					'revenue' => -1.0,
				)
			),
		);

		$built = extrachill_analytics_revenue_build_diagnostics(
			array(
				'periods'           => array( $this->period() ),
				'rows'              => $rows,
				'timeseries_totals' => array(
					'views'   => 1000,
					'revenue' => -1.0,
				),
			)
		);

		$this->assertSame( 'fail', $built['overall_status'] );
		$this->assertGreaterThan( 0, $built['summary']['fail'] );
	}

	/**
	 * Source-string contract: the callback reuses the shared store resolver and
	 * classifiers, publish-gates content, and delegates to the pure builder.
	 */
	public function test_callback_reuses_shared_substrate_and_delegates(): void {
		$source = $this->ability_source();

		$this->assertStringContainsString( "'publish' === get_post_status( \$post_id )", $source );
		$this->assertStringContainsString( 'extrachill_analytics_revenue_resolve_post_id', $source );
		$this->assertStringContainsString( 'extrachill_analytics_revenue_classify_route_family', $source );
		$this->assertStringContainsString( 'extrachill_analytics_classify_format', $source );
		$this->assertStringContainsString( 'extrachill_analytics_revenue_get_rows', $source );
		$this->assertStringContainsString( 'extrachill_analytics_revenue_list_batches', $source );
		$this->assertStringContainsString( 'extrachill_analytics_revenue_build_diagnostics', $source );
		// No new table creation inside this ability.
		$this->assertStringNotContainsString( 'CREATE TABLE', $source );
	}

	/**
	 * Read the production ability source.
	 *
	 * @return string
	 */
	private function ability_source() {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source file.
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/abilities/get-content-revenue-diagnostics.php' );
		$this->assertNotFalse( $source );

		return $source;
	}
}
