<?php
/**
 * Behavioral + contract tests for the revenue ingestion ability (issue #140).
 *
 * The pure decision core (deterministic identity + ingestion plan) is unit-
 * tested directly. The full engine path — resolution, replace/additive
 * semantics, stale-row removal, idempotency, isolation, dry-run, rollback — is
 * exercised against an in-memory fake store that mirrors the production store's
 * contract, because this repository has no WordPress-DB test scaffold. The
 * ability registration (annotations / schemas / permission) is locked down via
 * a source-string contract. WordPress function stubs live in bootstrap.php.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/content-format-classifier.php';
require_once dirname( __DIR__ ) . '/inc/database/mediavine-revenue-db.php';
require_once dirname( __DIR__ ) . '/inc/core/mediavine-csv-import.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities/ingest-revenue.php';
require_once dirname( __DIR__ ) . '/inc/database/class-extrachill-analytics-revenue-store.php';
require_once __DIR__ . '/class-fake-revenue-store.php';

/**
 * Verify deterministic identity, replace/additive semantics, idempotency,
 * isolation, dry-run, validation, resolution, rollback, and the output contract.
 */
final class IngestRevenueTest extends TestCase {

	/**
	 * Fake store under test.
	 *
	 * @var Fake_Revenue_Store
	 */
	private $store;

	/**
	 * Set up a fresh store and clear per-test resolution maps.
	 */
	protected function setUp(): void {
		$this->store = new Fake_Revenue_Store();
		unset( $GLOBALS['extrachill_ingest_url_map'], $GLOBALS['extrachill_ingest_post_map'] );
	}

	/**
	 * Identical identity inputs yield identical, filename-free batches.
	 */
	public function test_identity_is_deterministic_and_filename_free(): void {
		$base = array(
			'source'      => 'mediavine',
			'source_site' => 'extrachill.com',
			'blog_id'     => 1,
			'period'      => '2026-05',
		);

		$a = extrachill_analytics_revenue_snapshot_identity( $base );
		$b = extrachill_analytics_revenue_snapshot_identity( $base );

		$this->assertSame( $a['import_batch'], $b['import_batch'], 'identical inputs must yield identical batch' );
		$this->assertSame( 'mediavine-extrachillcom-b1-2026-05', $a['import_batch'] );
		$this->assertSame( '2026-05', $a['period_label'] );
		$this->assertSame( '2026-05-01', $a['period_start'] );
		$this->assertSame( '2026-05-31', $a['period_end'] );
		$this->assertStringNotContainsString( 'tmp', $a['import_batch'] );
	}

	/**
	 * Identity isolates across source, site, blog, and period.
	 */
	public function test_identity_isolates_by_source_site_blog_and_period(): void {
		$base  = array(
			'source'      => 'mediavine',
			'source_site' => 'extrachill.com',
			'blog_id'     => 1,
			'period'      => '2026-05',
		);
		$batch = extrachill_analytics_revenue_snapshot_identity( $base )['import_batch'];

		$other_period = extrachill_analytics_revenue_snapshot_identity( array_merge( $base, array( 'period' => '2026-06' ) ) )['import_batch'];
		$other_blog   = extrachill_analytics_revenue_snapshot_identity( array_merge( $base, array( 'blog_id' => 7 ) ) )['import_batch'];
		$other_site   = extrachill_analytics_revenue_snapshot_identity( array_merge( $base, array( 'source_site' => 'wire.extrachill.com' ) ) )['import_batch'];
		$other_source = extrachill_analytics_revenue_snapshot_identity( array_merge( $base, array( 'source' => 'adthrive' ) ) )['import_batch'];

		$this->assertNotSame( $batch, $other_period );
		$this->assertNotSame( $batch, $other_blog );
		$this->assertNotSame( $batch, $other_site );
		$this->assertNotSame( $batch, $other_source );
	}

	/**
	 * The plan classifies incoming slugs as inserts/replaces and stale deletes.
	 */
	public function test_plan_classifies_inserts_replaces_deletes(): void {
		$records  = $this->records( array( 'a', 'b', 'd' ) );
		$existing = array(
			$this->existing_row( 10, 'a' ),
			$this->existing_row( 11, 'b' ),
			$this->existing_row( 12, 'c' ),
		);

		$plan = extrachill_analytics_revenue_build_ingestion_plan( $records, $existing, 'replace' );

		$this->assertSame( array( 'd' ), $this->slugs( $plan['inserts'] ) );
		$this->assertSame( array( 'a', 'b' ), $this->slugs( $plan['replaces'] ) );
		$this->assertSame( array( 12 ), $plan['deletes'] );
	}

	/**
	 * Additive plans never delete stale rows.
	 */
	public function test_plan_additive_never_deletes(): void {
		$records  = $this->records( array( 'a', 'b' ) );
		$existing = array(
			$this->existing_row( 10, 'a' ),
			$this->existing_row( 11, 'c' ),
		);

		$plan = extrachill_analytics_revenue_build_ingestion_plan( $records, $existing, 'additive' );

		$this->assertSame( array(), $plan['deletes'], 'additive mode must never plan deletes' );
		$this->assertSame( array( 'b' ), $this->slugs( $plan['inserts'] ) );
		$this->assertSame( array( 'a' ), $this->slugs( $plan['replaces'] ) );
	}

	/**
	 * A repeat run (existing == incoming) plans all replaces, zero deletes.
	 */
	public function test_plan_repeat_run_is_all_replaces_zero_deletes(): void {
		$records  = $this->records( array( 'a', 'b', 'c' ) );
		$existing = array(
			$this->existing_row( 1, 'a' ),
			$this->existing_row( 2, 'b' ),
			$this->existing_row( 3, 'c' ),
		);

		$plan = extrachill_analytics_revenue_build_ingestion_plan( $records, $existing, 'replace' );

		$this->assertSame( array(), $this->slugs( $plan['inserts'] ) );
		$this->assertSame( array( 'a', 'b', 'c' ), $this->slugs( $plan['replaces'] ) );
		$this->assertSame( array(), $plan['deletes'] );
	}

	/**
	 * Re-running identical inputs is idempotent: totals do not double.
	 */
	public function test_repeat_ingest_is_idempotent_totals_unchanged(): void {
		$rows = $this->rows(
			array(
				'a' => 1000.0,
				'b' => 500.0,
			)
		);

		$first = $this->ingest( $rows, array( 'period' => '2026-05' ) );
		$this->assertTrue( $first['success'] );
		$this->assertSame( 2, $first['inserted'] );
		$this->assertSame( 0, $first['replaced'] );
		$this->assertSame( 0, $first['deleted'] );

		$totals_after_first = $this->store_totals( '2026-05' );

		$second = $this->ingest( $rows, array( 'period' => '2026-05' ) );
		$this->assertTrue( $second['success'] );
		$this->assertSame( 0, $second['inserted'], 'no new slugs on repeat' );
		$this->assertSame( 2, $second['replaced'] );
		$this->assertSame( 0, $second['deleted'] );

		$this->assertSame( $totals_after_first, $this->store_totals( '2026-05' ) );
		$this->assertSame( $first['identity']['import_batch'], $second['identity']['import_batch'] );
	}

	/**
	 * Replace mode removes rows that disappeared from the refreshed source.
	 */
	public function test_replace_removes_stale_rows_that_disappeared(): void {
		$this->ingest(
			$this->rows(
				array(
					'a' => 100.0,
					'b' => 50.0,
					'c' => 25.0,
				)
			),
			array( 'period' => '2026-05' )
		);
		$this->assertCount( 3, $this->store_records( '2026-05' ) );

		$result = $this->ingest(
			$this->rows(
				array(
					'a' => 100.0,
					'b' => 50.0,
				)
			),
			array( 'period' => '2026-05' )
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['inserted'] );
		$this->assertSame( 2, $result['replaced'] );
		$this->assertSame( 1, $result['deleted'], 'stale row c must be removed' );

		$this->assertSame( array( 'a', 'b' ), $this->slugs( $this->store_records( '2026-05' ) ) );
	}

	/**
	 * Replace updates changed metrics in place without duplicating.
	 */
	public function test_replace_updates_changed_metrics_in_place(): void {
		$this->ingest( $this->rows( array( 'a' => 100.0 ) ), array( 'period' => '2026-05' ) );
		$this->ingest(
			array(
				array(
					'slug'    => '/a/',
					'views'   => 2000,
					'revenue' => 300.0,
				),
			),
			array( 'period' => '2026-05' )
		);

		$totals = $this->store_totals( '2026-05' );
		$this->assertSame( 300.0, $totals['revenue'] );
		$this->assertSame( 2000, $totals['views'] );
	}

	/**
	 * Additive mode without an explicit snapshot is rejected and writes nothing.
	 */
	public function test_additive_without_explicit_snapshot_is_rejected(): void {
		$result = $this->ingest(
			$this->rows( array( 'a' => 1.0 ) ),
			array(
				'period' => '2026-05',
				'mode'   => 'additive',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'additive mode requires an explicit snapshot', $result['error'] );
		$this->assertSame( 0, count( $this->store->rows ) );
	}

	/**
	 * Additive mode never deletes and creates a distinct parallel snapshot.
	 */
	public function test_additive_never_removes_stale_and_creates_distinct_snapshot(): void {
		$this->ingest(
			$this->rows(
				array(
					'a' => 100.0,
					'b' => 50.0,
					'c' => 25.0,
				)
			),
			array( 'period' => '2026-05' )
		);
		$replace_batch = extrachill_analytics_revenue_snapshot_identity(
			array(
				'source'      => 'mediavine',
				'source_site' => 'extrachill.com',
				'blog_id'     => 1,
				'period'      => '2026-05',
			)
		)['import_batch'];

		$result = $this->ingest(
			$this->rows(
				array(
					'a' => 100.0,
					'b' => 50.0,
				)
			),
			array(
				'period'   => '2026-05',
				'mode'     => 'additive',
				'snapshot' => 'manual-recheck-2026-05',
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['deleted'], 'additive must not delete' );
		$this->assertSame( 'manual-recheck-2026-05', $result['identity']['import_batch'] );
		$this->assertNotSame( $replace_batch, $result['identity']['import_batch'] );

		$this->assertCount( 3, $this->store->snapshot_records( 1, '2026-05', $replace_batch ) );
		$this->assertCount( 2, $this->store->snapshot_records( 1, '2026-05', 'manual-recheck-2026-05' ) );
	}

	/**
	 * Different periods land in different snapshots and never collide.
	 */
	public function test_different_periods_do_not_collide(): void {
		$this->ingest( $this->rows( array( 'a' => 100.0 ) ), array( 'period' => '2026-05' ) );
		$this->ingest( $this->rows( array( 'a' => 200.0 ) ), array( 'period' => '2026-06' ) );

		$this->assertSame( 100.0, $this->store_totals( '2026-05' )['revenue'] );
		$this->assertSame( 200.0, $this->store_totals( '2026-06' )['revenue'] );
	}

	/**
	 * Different blogs are isolated even for the same period/slug.
	 */
	public function test_different_blogs_do_not_collide(): void {
		$this->ingest(
			$this->rows( array( 'a' => 100.0 ) ),
			array(
				'period'  => '2026-05',
				'blog_id' => 1,
			)
		);
		$this->ingest(
			$this->rows( array( 'a' => 999.0 ) ),
			array(
				'period'  => '2026-05',
				'blog_id' => 7,
			)
		);

		$blog1 = array_filter(
			$this->store->rows,
			static function ( $r ) {
				return 1 === (int) $r['blog_id'];
			}
		);
		$blog7 = array_filter(
			$this->store->rows,
			static function ( $r ) {
				return 7 === (int) $r['blog_id'];
			}
		);

		$this->assertCount( 1, $blog1 );
		$this->assertCount( 1, $blog7 );
		$this->assertSame( 100.0, (float) reset( $blog1 )['revenue'] );
		$this->assertSame( 999.0, (float) reset( $blog7 )['revenue'] );
	}

	/**
	 * Dry run reports the full plan (including would-delete) but writes nothing.
	 */
	public function test_dry_run_writes_nothing_but_reports_plan(): void {
		$this->ingest(
			$this->rows(
				array(
					'a' => 100.0,
					'b' => 50.0,
					'c' => 25.0,
				)
			),
			array( 'period' => '2026-05' )
		);

		$result = $this->ingest(
			$this->rows(
				array(
					'a' => 100.0,
					'b' => 50.0,
				)
			),
			array(
				'period'  => '2026-05',
				'dry_run' => true,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['written'] );
		$this->assertTrue( $result['dry_run'] );
		$this->assertSame( 0, $result['inserted'] );
		$this->assertSame( 2, $result['replaced'] );
		$this->assertSame( 0, $result['deleted'], 'dry run must report zero actual deletes' );
		$this->assertSame( 1, $result['would_delete'], 'dry run reports what it WOULD delete' );
		$this->assertCount( 3, $this->store_records( '2026-05' ) );
	}

	/**
	 * Dry run on an empty store writes nothing.
	 */
	public function test_dry_run_on_empty_store_writes_nothing(): void {
		$result = $this->ingest(
			$this->rows( array( 'a' => 100.0 ) ),
			array(
				'period'  => '2026-05',
				'dry_run' => true,
			)
		);

		$this->assertTrue( $result['success'] );
		$this->assertFalse( $result['written'] );
		$this->assertSame( 1, $result['inserted'] );
		$this->assertSame( 0, count( $this->store->rows ) );
	}

	/**
	 * An invalid mode is rejected with an error and no writes.
	 */
	public function test_invalid_mode_is_rejected(): void {
		$result = $this->ingest(
			$this->rows( array( 'a' => 1.0 ) ),
			array(
				'period' => '2026-05',
				'mode'   => 'bogus',
			)
		);

		$this->assertFalse( $result['success'] );
		$this->assertStringContainsString( 'mode must be', $result['error'] );
	}

	/**
	 * Empty input rows is a clean, successful no-op.
	 */
	public function test_empty_rows_is_a_clean_noop(): void {
		$result = $this->ingest( array(), array( 'period' => '2026-05' ) );

		$this->assertTrue( $result['success'] );
		$this->assertSame( 0, $result['rows'] );
		$this->assertSame( 0, $result['inserted'] );
	}

	/**
	 * Resolution counts one resolved post and one unresolved route.
	 */
	public function test_resolution_counts_resolved_and_unresolved(): void {
		$GLOBALS['extrachill_ingest_url_map']  = array( '/some-post/' => 42 );
		$GLOBALS['extrachill_ingest_post_map'] = array( 42 => 'some-post' );

		$result = $this->ingest(
			array(
				array(
					'slug'    => '/some-post/',
					'revenue' => 10.0,
				),
				array(
					'slug'    => '/old-ghost.html',
					'revenue' => 1.0,
				),
			),
			array( 'period' => '2026-05' )
		);

		$this->assertTrue( $result['success'] );
		$this->assertSame( 1, $result['resolved'] );
		$this->assertSame( 1, $result['unresolved'] );
		$this->assertSame( 2, $result['rows'] );
	}

	/**
	 * A mid-write failure rolls the snapshot back and reports an error.
	 */
	public function test_mid_write_failure_rolls_back_and_reports_error(): void {
		$this->ingest( $this->rows( array( 'a' => 100.0 ) ), array( 'period' => '2026-05' ) );
		$before = $this->store_records( '2026-05' );

		$this->store->fail_upsert_after = 1;

		$result = $this->ingest(
			$this->rows(
				array(
					'a' => 100.0,
					'b' => 50.0,
					'c' => 25.0,
				)
			),
			array( 'period' => '2026-05' )
		);

		$this->assertFalse( $result['success'] );
		$this->assertFalse( $result['written'] );
		$this->assertStringContainsString( 'Failed to', $result['error'] );

		$after = $this->store_records( '2026-05' );
		$this->assertSame( $this->slugs( $before ), $this->slugs( $after ) );
		$this->assertCount( 1, $after );
	}

	/**
	 * The output exposes all required count and identity keys.
	 */
	public function test_output_contract_keys(): void {
		$result = $this->ingest( $this->rows( array( 'a' => 100.0 ) ), array( 'period' => '2026-05' ) );

		$this->assertArrayHasKey( 'success', $result );
		$this->assertArrayHasKey( 'written', $result );
		$this->assertArrayHasKey( 'mode', $result );
		$this->assertArrayHasKey( 'dry_run', $result );
		foreach ( array( 'rows', 'resolved', 'unresolved', 'inserted', 'replaced', 'deleted' ) as $key ) {
			$this->assertArrayHasKey( $key, $result, "output must report $key" );
		}

		$identity = $result['identity'];
		foreach ( array( 'import_batch', 'period_label', 'period_start', 'period_end', 'source', 'source_site', 'blog_id' ) as $key ) {
			$this->assertArrayHasKey( $key, $identity, "identity must report $key" );
		}

		$this->assertSame( '2026-05', $identity['period_label'] );
		$this->assertSame( 'mediavine', $identity['source'] );
	}

	/**
	 * Registration marks the mutation destructive, idempotent, and gated.
	 */
	public function test_registration_marks_mutation_destructive_and_gates_permissions(): void {
		$source = $this->ability_source();

		$this->assertStringContainsString( "'readonly'    => false", $source );
		$this->assertStringContainsString( "'destructive' => true", $source );
		$this->assertStringContainsString( "'idempotent'  => true", $source );
		$this->assertStringContainsString( "current_user_can( 'manage_options' )", $source );
		$this->assertStringContainsString( 'defined( \'WP_CLI\' ) && WP_CLI', $source );
		$this->assertStringContainsString( "'rows'", $source );
		$this->assertStringContainsString( "'mode'", $source );
		$this->assertStringContainsString( "'snapshot'", $source );
		$this->assertStringContainsString( "'dry_run'", $source );
		$this->assertStringContainsString( 'inserted', $source );
		$this->assertStringContainsString( 'replaced', $source );
		$this->assertStringContainsString( 'deleted', $source );
		$this->assertStringContainsString( 'additive mode requires an explicit snapshot', $source );
	}

	/**
	 * Registration uses the deterministic identity helper (never a filename).
	 */
	public function test_registration_uses_deterministic_identity_not_filename(): void {
		$source = $this->ability_source();
		$this->assertStringContainsString( 'extrachill_analytics_revenue_snapshot_identity', $source );
		$this->assertStringContainsString( "sanitize_key( (string) \$args['source'] )", $source );
	}

	/**
	 * Run the engine with the fake store.
	 *
	 * @param array $rows  Input revenue rows.
	 * @param array $args  Ingestion options.
	 * @return array Engine result.
	 */
	private function ingest( array $rows, array $args ): array {
		$args = array_merge(
			array(
				'blog_id' => 1,
				'source'  => 'mediavine',
			),
			$args
		);
		return extrachill_analytics_revenue_ingest_rows( $rows, $args, $this->store );
	}

	/**
	 * Build slug-keyed revenue rows.
	 *
	 * @param array<string,float> $slug_to_revenue Map of slug => revenue.
	 * @return array<int, array>
	 */
	private function rows( array $slug_to_revenue ): array {
		$out = array();
		foreach ( $slug_to_revenue as $slug => $revenue ) {
			$out[] = array(
				'slug'    => '/' . $slug . '/',
				'views'   => 1000,
				'revenue' => (float) $revenue,
			);
		}
		return $out;
	}

	/**
	 * Build minimal slug-only records.
	 *
	 * @param array<int, string> $slugs Slugs.
	 * @return array<int, array>
	 */
	private function records( array $slugs ): array {
		$out = array();
		foreach ( $slugs as $slug ) {
			$out[] = array( 'slug' => $slug );
		}
		return $out;
	}

	/**
	 * Build a fake existing snapshot row object.
	 *
	 * @param int    $id   Row id.
	 * @param string $slug Slug.
	 * @return stdClass
	 */
	private function existing_row( $id, $slug ): stdClass {
		$r       = new stdClass();
		$r->id   = $id;
		$r->slug = $slug;
		return $r;
	}

	/**
	 * Pull slugs from a list of records/objects.
	 *
	 * @param array $records Records (arrays or objects).
	 * @return array<int, string>
	 */
	private function slugs( array $records ): array {
		$out = array();
		foreach ( $records as $rec ) {
			$slug = is_object( $rec ) && isset( $rec->slug ) ? $rec->slug : ( isset( $rec['slug'] ) ? $rec['slug'] : null );
			if ( null !== $slug ) {
				$out[] = (string) $slug;
			}
		}
		return $out;
	}

	/**
	 * Snapshot records for a period on the deterministic identity.
	 *
	 * @param string $period Period token.
	 * @return array<int, array>
	 */
	private function store_records( $period ): array {
		$identity = $this->identity( $period );
		return $this->store->snapshot_records( 1, $identity['period_label'], $identity['import_batch'] );
	}

	/**
	 * Snapshot totals (views, revenue) for a period.
	 *
	 * @param string $period Period token.
	 * @return array{views:int,revenue:float}
	 */
	private function store_totals( $period ): array {
		$identity = $this->identity( $period );
		return $this->store->snapshot_totals( 1, $identity['period_label'], $identity['import_batch'] );
	}

	/**
	 * Resolve the deterministic identity for a period on the default source/site.
	 *
	 * @param string $period Period token.
	 * @return array Identity.
	 */
	private function identity( $period ): array {
		return extrachill_analytics_revenue_snapshot_identity(
			array(
				'source'      => 'mediavine',
				'source_site' => 'extrachill.com',
				'blog_id'     => 1,
				'period'      => $period,
			)
		);
	}

	/**
	 * Read the production ability source for a string-contract assertion.
	 *
	 * @return string
	 */
	private function ability_source(): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a local source file.
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/abilities/ingest-revenue.php' );
		$this->assertNotFalse( $source );
		return $source;
	}
}
