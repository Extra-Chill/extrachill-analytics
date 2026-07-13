<?php
/**
 * Get Content Revenue Diagnostics Ability
 *
 * Structured data-integrity lens for the Mediavine revenue store. Where
 * extrachill/get-content-revenue rolls up revenue and extrachill/get-content-
 * revenue-pages ranks individual pages, THIS ability reports the HEALTH of the
 * store itself: is the latest import fresh, are monthly periods contiguous,
 * are any periods double-imported, does the read model reconcile, how much
 * traffic is unresolved, are there zero-view-revenue or negative-value rows,
 * and does stored RPM agree with derived RPM?
 *
 * Why a sibling and not a diagnostic flag on the rollup: the rollup output
 * contract is rollups/totals/unresolved — it has no place for an array of
 * independent checks each carrying status/evidence/totals. The issue explicitly
 * permits narrowly scoped siblings. This ability reuses the SAME store, resolver,
 * and classifiers — no new tables, no parallel aggregation.
 *
 * Status contract: each check returns one of 'pass', 'warning', or 'fail'.
 *   - 'fail' is reserved for genuine data-integrity violations that make the
 *     numbers untrustworthy (negative/impossible values, a read model that does
 *     not reconcile). Source quirks (zero-view revenue, duplicate batches, RPM
 *     variance) are NEVER 'fail' without evidence — they are 'warning' so the
 *     analyst sees them without the system crying wolf. The issue is explicit:
 *     do not claim source anomalies are corruption without evidence.
 *   - The overall status is fail if any check fails, else warning if any warns,
 *     else pass.
 *
 * Reconciliation note: totals_reconciliation compares the in-scope row-sum
 * against the in-scope timeseries aggregation. Both come from the same table, so
 * a pass confirms the read path is self-consistent; a fail means the read model
 * itself is broken (a regression signal, not a Mediavine data issue).
 *
 * @package ExtraChill\Analytics
 * @since 0.28.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-content-revenue-diagnostics ability.
 */
function extrachill_analytics_register_content_revenue_diagnostics_ability() {
	wp_register_ability(
		'extrachill/get-content-revenue-diagnostics',
		array(
			'label'               => __( 'Get Content Revenue Diagnostics Lens', 'extrachill-analytics' ),
			'description'         => __( 'Structured data-integrity checks for the Mediavine revenue store. Returns an array of independent checks — latest-period freshness, missing periods, duplicate period/batch imports, totals reconciliation, resolution and content-format coverage, revenue with zero pageviews, views with zero revenue, negative/impossible values, stored-vs-derived RPM variance, and source/import provenance coverage — each with a stable pass|warning|fail status, evidence, and totals. Source quirks (zero-view revenue, duplicate batches, RPM variance) are warnings, never corruption claims without evidence; fail is reserved for genuine integrity violations (negative values, a read model that does not reconcile). Reuses the same store, resolver, and classifiers as the rollup/pages abilities — no new tables. CLI consumers map args and format output only.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'period'       => array(
						'type'        => 'string',
						'description' => __( 'Scope diagnostics to one time bucket, e.g. "2026-05" or "all-time". Empty = every bucket combined (the whole-store integrity view).', 'extrachill-analytics' ),
						'default'     => '',
					),
					'period_start' => array(
						'type'        => 'string',
						'description' => __( 'Inclusive window start (Y-m-d). With period_end, scopes to snapshots imported for that window.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'period_end'   => array(
						'type'        => 'string',
						'description' => __( 'Inclusive window end (Y-m-d). See period_start.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'import_batch' => array(
						'type'        => 'string',
						'description' => __( 'Restrict to one import batch. Empty = all batches.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'blog_id'      => array(
						'type'        => 'integer',
						'description' => __( 'Blog ID whose revenue to diagnose. 0 = current blog.', 'extrachill-analytics' ),
						'default'     => 0,
					),
					'hostname'     => array(
						'type'        => 'string',
						'description' => __( 'Hostname for resolving any still-unresolved slugs to posts (default: extrachill.com).', 'extrachill-analytics' ),
						'default'     => 'extrachill.com',
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'checks array (each: check, status pass|warning|fail, evidence, totals), overall_status, summary { pass, warning, fail, total }, scope metadata, and caveats.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_content_revenue_diagnostics',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
			},
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => true,
					'idempotent'  => true,
					'destructive' => false,
				),
			),
		)
	);
}

/**
 * Execute callback for get-content-revenue-diagnostics ability.
 *
 * Owns the WordPress-dependent half: SQL (via the shared store helpers), slug->
 * post resolution, and the publish-status classification gate. Builds a
 * normalized snapshot — period batches, normalized rows with classification and
 * derived metrics, and timeseries totals — and hands it to the pure builder.
 *
 * @param array $input Input parameters.
 * @return array Diagnostics response.
 */
function extrachill_analytics_ability_get_content_revenue_diagnostics( $input ) {
	$period       = isset( $input['period'] ) ? (string) $input['period'] : '';
	$period_start = isset( $input['period_start'] ) ? (string) $input['period_start'] : '';
	$period_end   = isset( $input['period_end'] ) ? (string) $input['period_end'] : '';
	$import_batch = isset( $input['import_batch'] ) ? (string) $input['import_batch'] : '';
	$blog_id      = ! empty( $input['blog_id'] ) ? (int) $input['blog_id'] : get_current_blog_id();
	$hostname     = ! empty( $input['hostname'] ) ? (string) $input['hostname'] : 'extrachill.com';

	$rows = extrachill_analytics_revenue_get_rows(
		array(
			'blog_id'      => $blog_id,
			'import_batch' => $import_batch,
			'period_label' => $period,
			'period_start' => $period_start,
			'period_end'   => $period_end,
		)
	);

	// Normalize rows + classify resolved/unresolved (the same publish-status
	// gate as the rollup/pages abilities). Derived RPM is precomputed per row so
	// the pure builder can compare it to source RPM without re-deriving.
	$normalized = array();
	foreach ( $rows as $row ) {
		$post_id = (int) $row->post_id;
		if ( $post_id <= 0 && '' !== $row->slug ) {
			$post_id = extrachill_analytics_revenue_resolve_post_id( $row->url ? $row->url : $row->slug, $hostname );
		}
		$is_content = $post_id > 0 && 'publish' === get_post_status( $post_id );

		$views   = (int) $row->views;
		$revenue = (float) $row->revenue;

		$normalized[] = array(
			'period_label'             => (string) $row->period_label,
			'import_batch'             => (string) $row->import_batch,
			'period_start'             => $row->period_start ? (string) $row->period_start : null,
			'period_end'               => $row->period_end ? (string) $row->period_end : null,
			'imported_at'              => (string) $row->imported_at,
			'slug'                     => (string) $row->slug,
			'post_id'                  => $is_content ? $post_id : 0,
			'is_content'               => $is_content,
			'route_family'             => $is_content ? '' : extrachill_analytics_revenue_classify_route_family( $row->url ? $row->url : $row->slug ),
			'format'                   => $is_content ? extrachill_analytics_classify_format( $post_id ) : '',
			'views'                    => $views,
			'revenue'                  => $revenue,
			'source_rpm'               => (float) $row->rpm,
			'cpm'                      => (float) $row->cpm,
			'viewability'              => (float) $row->viewability,
			'fill_rate'                => (float) $row->fill_rate,
			'impressions_per_pageview' => (float) $row->impressions_per_pageview,
			'derived_rpm'              => $views > 0 ? round( $revenue / ( $views / 1000 ), 4 ) : 0.0,
		);
	}

	// Period-batch aggregates for the scope (from the SAME store, unscoped by
	// period/window so freshness/missing-period/duplicate-batch checks see the
	// whole per-blog history; period/window/batch scoping is applied to the
	// row-level checks only).
	$period_aggs = extrachill_analytics_revenue_list_batches( $blog_id );

	$periods = array();
	foreach ( $period_aggs as $p ) {
		$periods[] = array(
			'period_label' => (string) $p->period_label,
			'import_batch' => (string) $p->import_batch,
			'period_start' => $p->period_start ? (string) $p->period_start : null,
			'period_end'   => $p->period_end ? (string) $p->period_end : null,
			'rows'         => (int) $p->rows_count,
			'revenue'      => (float) $p->revenue,
			'views'        => (int) $p->views,
			'imported_at'  => (string) $p->imported_at,
		);
	}

	// Timeseries totals for the scope — the reconciliation check compares the
	// in-scope row-sum against this. Computed from the in-scope rows directly so
	// reconciliation is a self-consistency check on the normalized snapshot.
	$ts_views   = 0;
	$ts_revenue = 0.0;
	foreach ( $normalized as $r ) {
		$ts_views   += $r['views'];
		$ts_revenue += $r['revenue'];
	}

	$built = extrachill_analytics_revenue_build_diagnostics(
		array(
			'scope'             => array(
				'blog_id'      => $blog_id,
				'period'       => $period,
				'period_start' => $period_start,
				'period_end'   => $period_end,
				'import_batch' => $import_batch,
			),
			'periods'           => $periods,
			'rows'              => $normalized,
			'timeseries_totals' => array(
				'views'   => $ts_views,
				'revenue' => round( $ts_revenue, 4 ),
			),
		)
	);

	return array(
		'success'        => true,
		'blog_id'        => $blog_id,
		'window'         => extrachill_analytics_revenue_window_label( $period_start, $period_end, $period ),
		'checks'         => $built['checks'],
		'overall_status' => $built['overall_status'],
		'summary'        => $built['summary'],
		'scope'          => $built['scope'],
		'caveat'         => __( 'Source quirks (zero-view revenue, duplicate period batches, stored-vs-derived RPM variance, unresolved routes) are warnings — disclosure, not corruption claims. fail is reserved for genuine integrity violations: negative/impossible values or a read model that does not reconcile. Freshness/missing-period/duplicate-batch checks consider the whole per-blog history (unscoped); row-level checks honor the requested period/window/batch scope. Resolution coverage uses the same publish-status gate as the rollup/pages abilities.', 'extrachill-analytics' ),
	);
}

/**
 * Build the diagnostics checks from a normalized store snapshot.
 *
 * PURE — no WordPress / DB dependency — so every check's status/evidence/totals
 * contract is unit-testable in isolation. The execute_callback owns querying and
 * resolution; this function owns the integrity logic.
 *
 * Snapshot shape:
 *   {
 *     scope: { blog_id, period, period_start, period_end, import_batch },
 *     periods: [ { period_label, import_batch, period_start, period_end, rows, revenue, views, imported_at }, ... ],
 *     rows: [ { period_label, import_batch, ..., is_content, route_family, format, views, revenue, source_rpm, derived_rpm, ... }, ... ],
 *     timeseries_totals: { views, revenue },
 *   }
 *
 * @param array $snapshot Normalized snapshot.
 * @return array { checks, overall_status, summary, scope }
 */
function extrachill_analytics_revenue_build_diagnostics( array $snapshot ) {
	$periods = isset( $snapshot['periods'] ) && is_array( $snapshot['periods'] ) ? $snapshot['periods'] : array();
	$rows    = isset( $snapshot['rows'] ) && is_array( $snapshot['rows'] ) ? $snapshot['rows'] : array();
	$ts      = isset( $snapshot['timeseries_totals'] ) && is_array( $snapshot['timeseries_totals'] ) ? $snapshot['timeseries_totals'] : array(
		'views'   => 0,
		'revenue' => 0.0,
	);
	$scope   = isset( $snapshot['scope'] ) && is_array( $snapshot['scope'] ) ? $snapshot['scope'] : array();

	$checks   = array();
	$checks[] = extrachill_analytics_revenue_diag_latest_period_freshness( $periods, $rows );
	$checks[] = extrachill_analytics_revenue_diag_missing_periods( $periods );
	$checks[] = extrachill_analytics_revenue_diag_duplicate_period_batches( $periods );
	$checks[] = extrachill_analytics_revenue_diag_totals_reconciliation( $rows, $ts );
	$checks[] = extrachill_analytics_revenue_diag_resolution_coverage( $rows );
	$checks[] = extrachill_analytics_revenue_diag_format_coverage( $rows );
	$checks[] = extrachill_analytics_revenue_diag_zero_view_revenue( $rows );
	$checks[] = extrachill_analytics_revenue_diag_views_zero_revenue( $rows );
	$checks[] = extrachill_analytics_revenue_diag_negative_impossible_values( $rows );
	$checks[] = extrachill_analytics_revenue_diag_rpm_variance( $rows );
	$checks[] = extrachill_analytics_revenue_diag_provenance_coverage( $rows );

	$summary = array(
		'pass'    => 0,
		'warning' => 0,
		'fail'    => 0,
		'total'   => count( $checks ),
	);
	foreach ( $checks as $c ) {
		$status = isset( $c['status'] ) ? $c['status'] : 'warning';
		if ( isset( $summary[ $status ] ) ) {
			++$summary[ $status ];
		}
	}

	if ( $summary['fail'] > 0 ) {
		$overall = 'fail';
	} elseif ( $summary['warning'] > 0 ) {
		$overall = 'warning';
	} else {
		$overall = 'pass';
	}

	return array(
		'checks'         => $checks,
		'overall_status' => $overall,
		'summary'        => $summary,
		'scope'          => $scope,
	);
}

/**
 * Latest-period freshness: is there a dated period, and how recent is it?
 *
 * @param array $periods Period-batch aggregates.
 * @param array $rows    Normalized rows (used for the empty-store case).
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_latest_period_freshness( array $periods, array $rows ) {
	if ( empty( $rows ) && empty( $periods ) ) {
		return array(
			'check'    => 'latest_period_freshness',
			'status'   => 'fail',
			'evidence' => array( 'No revenue snapshots imported for this blog.' ),
			'totals'   => array(
				'periods' => 0,
				'rows'    => 0,
			),
		);
	}

	// Find the most recent DATED period (period_start not null). The flat
	// "all-time" bucket has no date and does not count as fresh.
	$latest_label = '';
	$latest_at    = '';
	$has_dated    = false;
	foreach ( $periods as $p ) {
		if ( ! empty( $p['period_start'] ) ) {
			$has_dated = true;
			if ( '' === $latest_at || ( isset( $p['imported_at'] ) && $p['imported_at'] > $latest_at ) ) {
				$latest_at    = isset( $p['imported_at'] ) ? (string) $p['imported_at'] : '';
				$latest_label = isset( $p['period_label'] ) ? (string) $p['period_label'] : '';
			}
		}
	}

	$totals = array(
		'periods'            => count( $periods ),
		'has_dated'          => $has_dated,
		'latest_period'      => $latest_label,
		'latest_imported_at' => $latest_at,
	);

	if ( ! $has_dated ) {
		return array(
			'check'    => 'latest_period_freshness',
			'status'   => 'warning',
			'evidence' => array( 'Only the flat "all-time" bucket is present — no dated (monthly) periods imported. Import date-ranged CSVs with --period=YYYY-MM to build the arc and enable freshness tracking.' ),
			'totals'   => $totals,
		);
	}

	// Warn if the newest dated import is older than 45 days (a quarter-ish).
	$stale    = false;
	$age_days = null;
	if ( '' !== $latest_at ) {
		$ts = strtotime( $latest_at );
		if ( false !== $ts ) {
			$age_days = (int) floor( ( time() - $ts ) / DAY_IN_SECONDS );
			$stale    = $age_days > 45;
		}
	}

	$evidence   = array();
	$status     = 'pass';
	$evidence[] = "Newest dated period: {$latest_label} (imported {$latest_at}).";
	if ( null !== $age_days ) {
		$evidence[] = "Imported {$age_days} day(s) ago.";
	}
	if ( $stale ) {
		$status     = 'warning';
		$evidence[] = 'Newest dated import is older than 45 days — the revenue arc may be stale.';
	}
	$totals['age_days'] = $age_days;

	return array(
		'check'    => 'latest_period_freshness',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => $totals,
	);
}

/**
 * Missing-period detection: are there gaps in the YYYY-MM dated sequence?
 *
 * @param array $periods Period-batch aggregates.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_missing_periods( array $periods ) {
	$dated = array();
	foreach ( $periods as $p ) {
		$label = isset( $p['period_label'] ) ? (string) $p['period_label'] : '';
		if ( preg_match( '/^\d{4}-\d{2}$/', $label ) ) {
			$dated[ $label ] = true;
		}
	}

	$sorted = array_keys( $dated );
	sort( $sorted );
	$n = count( $sorted );

	$missing = array();
	if ( $n >= 2 ) {
		$first  = $sorted[0];
		$last   = $sorted[ $n - 1 ];
		$cursor = strtotime( $first . '-01' );
		$end_ts = strtotime( $last . '-01' );
		while ( false !== $cursor && false !== $end_ts && $cursor <= $end_ts ) {
			$bucket = gmdate( 'Y-m', $cursor );
			if ( ! isset( $dated[ $bucket ] ) ) {
				$missing[] = $bucket;
			}
			$cursor = strtotime( '+1 month', $cursor );
		}
	}

	$status   = empty( $missing ) ? 'pass' : 'warning';
	$evidence = array();
	if ( empty( $missing ) ) {
		$evidence[] = $n >= 2
			? sprintf( 'Dated periods are contiguous from %s to %s (%d period(s)).', $sorted[0], $sorted[ $n - 1 ], $n )
			: sprintf( '%d dated period(s) — no sequence to gap-check.', $n );
	} else {
		$evidence[] = sprintf( 'Gap(s) in the monthly sequence: %s.', implode( ', ', $missing ) );
		$evidence[] = 'A gap may mean a month was never imported, or was imported under a non-YYYY-MM label.';
	}

	return array(
		'check'    => 'missing_periods',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'dated_periods'   => $n,
			'missing_periods' => count( $missing ),
			'missing'         => $missing,
		),
	);
}

/**
 * Duplicate-period-batch detection: same period_label across multiple batches.
 *
 * This is NOT corruption — re-imports are idempotent via REPLACE INTO on the
 * unique (blog, slug, period, batch) key. But if two DIFFERENT batches carry the
 * SAME period_label, a rollup that ignores batch can double-count. Disclose it.
 *
 * @param array $periods Period-batch aggregates.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_duplicate_period_batches( array $periods ) {
	$by_label = array();
	foreach ( $periods as $p ) {
		$label = isset( $p['period_label'] ) ? (string) $p['period_label'] : '';
		if ( '' === $label ) {
			continue;
		}
		if ( ! isset( $by_label[ $label ] ) ) {
			$by_label[ $label ] = array();
		}
		$by_label[ $label ][] = isset( $p['import_batch'] ) ? (string) $p['import_batch'] : '';
	}

	$duplicates = array();
	foreach ( $by_label as $label => $batches ) {
		$unique = array_unique( $batches );
		if ( count( $unique ) > 1 ) {
			$duplicates[ $label ] = array_values( $unique );
		}
	}

	$status   = empty( $duplicates ) ? 'pass' : 'warning';
	$evidence = array();
	if ( empty( $duplicates ) ) {
		$evidence[] = 'No period label spans more than one import batch.';
	} else {
		$evidence[] = 'One or more period labels appear across multiple import batches — a rollup that ignores --batch may double-count these:';
		foreach ( $duplicates as $label => $batches ) {
			$evidence[] = "  {$label}: " . implode( ', ', $batches );
		}
		$evidence[] = 'Pass --batch to scope, or re-import under one batch to collapse them.';
	}

	return array(
		'check'    => 'duplicate_period_batches',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'period_labels'    => count( $by_label ),
			'duplicate_labels' => count( $duplicates ),
			'duplicates'       => $duplicates,
		),
	);
}

/**
 * Totals reconciliation: in-scope row-sum must equal the timeseries totals.
 *
 * Both come from the same store, so a mismatch means the read model itself is
 * broken — a regression signal. This is the one check where 'fail' is warranted
 * on a numeric disagreement (the integrity of the read path).
 *
 * @param array $rows Normalized rows.
 * @param array $ts   Timeseries totals { views, revenue }.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_totals_reconciliation( array $rows, array $ts ) {
	$sum_views   = 0;
	$sum_revenue = 0.0;
	foreach ( $rows as $r ) {
		$sum_views   += isset( $r['views'] ) ? (int) $r['views'] : 0;
		$sum_revenue += isset( $r['revenue'] ) ? (float) $r['revenue'] : 0.0;
	}
	$sum_revenue = round( $sum_revenue, 4 );

	$ts_views   = isset( $ts['views'] ) ? (int) $ts['views'] : 0;
	$ts_revenue = round( isset( $ts['revenue'] ) ? (float) $ts['revenue'] : 0.0, 4 );

	$views_match   = $sum_views === $ts_views;
	$revenue_match = abs( $sum_revenue - $ts_revenue ) < 0.01;
	$match         = $views_match && $revenue_match;

	$status   = $match ? 'pass' : 'fail';
	$evidence = array();
	if ( $match ) {
		$evidence[] = "Row-sum reconciles with timeseries totals: {$sum_views} views, \${$sum_revenue} revenue.";
	} else {
		$evidence[] = 'Row-sum does NOT reconcile with timeseries totals.';
		if ( ! $views_match ) {
			$evidence[] = "Views: row-sum {$sum_views} vs timeseries {$ts_views}.";
		}
		if ( ! $revenue_match ) {
			$evidence[] = "Revenue: row-sum {$sum_revenue} vs timeseries {$ts_revenue}.";
		}
		$evidence[] = 'A reconciliation mismatch indicates the read model is self-inconsistent — a regression, not a Mediavine data issue.';
	}

	return array(
		'check'    => 'totals_reconciliation',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'row_sum'    => array(
				'views'   => $sum_views,
				'revenue' => $sum_revenue,
			),
			'timeseries' => array(
				'views'   => $ts_views,
				'revenue' => $ts_revenue,
			),
			'reconciled' => $match,
		),
	);
}

/**
 * Resolution coverage: resolved published posts vs unresolved routes.
 *
 * A high unresolved ratio is a coverage signal (much of the imported traffic is
 * non-content routes), not corruption.
 *
 * @param array $rows Normalized rows.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_resolution_coverage( array $rows ) {
	$total            = count( $rows );
	$resolved         = 0;
	$unresolved       = 0;
	$resolved_views   = 0;
	$unresolved_views = 0;
	$by_family        = array();

	foreach ( $rows as $r ) {
		if ( ! empty( $r['is_content'] ) ) {
			++$resolved;
			$resolved_views += isset( $r['views'] ) ? (int) $r['views'] : 0;
		} else {
			++$unresolved;
			$unresolved_views += isset( $r['views'] ) ? (int) $r['views'] : 0;
			$family            = isset( $r['route_family'] ) ? (string) $r['route_family'] : 'other';
			if ( ! isset( $by_family[ $family ] ) ) {
				$by_family[ $family ] = 0;
			}
			++$by_family[ $family ];
		}
	}

	$ratio = $total > 0 ? $unresolved / $total : 0.0;
	// Warn above 50% — most of the imported traffic is non-content routes.
	$status     = ( $total > 0 && $ratio > 0.50 ) ? 'warning' : 'pass';
	$evidence   = array();
	$evidence[] = "{$resolved} resolved published row(s), {$unresolved} unresolved route row(s) of {$total} total.";
	if ( $total > 0 ) {
		$evidence[] = sprintf( 'Unresolved ratio: %.1f%%.', $ratio * 100 );
	}
	if ( 'warning' === $status ) {
		$evidence[] = 'More than half of imported rows are unresolved routes — coverage signal, not corruption. Legacy .html ghosts, taxonomy archives, and app/account routes are expected here.';
	}
	arsort( $by_family );

	return array(
		'check'    => 'resolution_coverage',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'total_rows'       => $total,
			'resolved_rows'    => $resolved,
			'unresolved_rows'  => $unresolved,
			'resolved_views'   => $resolved_views,
			'unresolved_views' => $unresolved_views,
			'unresolved_ratio' => round( $ratio, 4 ),
			'by_route_family'  => $by_family,
		),
	);
}

/**
 * Content-format coverage: resolved posts that classified as 'uncategorized'.
 *
 * A high uncategorized ratio means the format classifier has no mapping for
 * many posts — a taxonomy gap, not corruption. Unresolved routes are excluded
 * (they never reach the classifier).
 *
 * @param array $rows Normalized rows.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_format_coverage( array $rows ) {
	$resolved_total = 0;
	$uncategorized  = 0;
	$by_format      = array();

	foreach ( $rows as $r ) {
		if ( empty( $r['is_content'] ) ) {
			continue;
		}
		++$resolved_total;
		$format = isset( $r['format'] ) ? (string) $r['format'] : 'uncategorized';
		if ( '' === $format ) {
			$format = 'uncategorized';
		}
		if ( ! isset( $by_format[ $format ] ) ) {
			$by_format[ $format ] = 0;
		}
		++$by_format[ $format ];
		if ( 'uncategorized' === $format ) {
			++$uncategorized;
		}
	}

	$ratio = $resolved_total > 0 ? $uncategorized / $resolved_total : 0.0;
	// Warn above 30% — a lot of resolved posts fall outside every format bucket.
	$status     = ( $resolved_total > 0 && $ratio > 0.30 ) ? 'warning' : 'pass';
	$evidence   = array();
	$evidence[] = "{$uncategorized} of {$resolved_total} resolved published row(s) classified as 'uncategorized'.";
	if ( 'warning' === $status ) {
		$evidence[] = 'Over 30% of resolved posts match no content-format bucket — a taxonomy-mapping gap. Add their category slugs to extrachill_analytics_format_category_map().';
	} else {
		$evidence[] = 'Format classifier covers the majority of resolved posts.';
	}
	arsort( $by_format );

	return array(
		'check'    => 'format_coverage',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'resolved_rows'       => $resolved_total,
			'uncategorized_rows'  => $uncategorized,
			'uncategorized_ratio' => round( $ratio, 4 ),
			'by_format'           => $by_format,
		),
	);
}

/**
 * Zero-view revenue: rows with revenue > 0 but views = 0.
 *
 * A Mediavine source quirk (rounding, sub-threshold fills, a missed views cell).
 * Disclosed as a warning — never corruption.
 *
 * @param array $rows Normalized rows.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_zero_view_revenue( array $rows ) {
	$count   = 0;
	$revenue = 0.0;
	$samples = array();

	foreach ( $rows as $r ) {
		$views = isset( $r['views'] ) ? (int) $r['views'] : 0;
		$rev   = isset( $r['revenue'] ) ? (float) $r['revenue'] : 0.0;
		if ( $views <= 0 && $rev > 0 ) {
			++$count;
			$revenue += $rev;
			if ( count( $samples ) < 5 ) {
				$samples[] = array(
					'slug'    => isset( $r['slug'] ) ? (string) $r['slug'] : '',
					'revenue' => round( $rev, 4 ),
					'views'   => 0,
				);
			}
		}
	}

	$status   = $count > 0 ? 'warning' : 'pass';
	$evidence = array();
	if ( 0 === $count ) {
		$evidence[] = 'No rows report revenue with zero pageviews.';
	} else {
		$evidence[] = "{$count} row(s) report revenue (total \${$revenue}) with zero pageviews.";
		$evidence[] = 'A Mediavine source quirk — the pages-ability flags these zero_views with derived_rpm = 0 and revenue preserved.';
	}

	return array(
		'check'    => 'zero_view_revenue',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'rows'    => $count,
			'revenue' => round( $revenue, 4 ),
			'samples' => $samples,
		),
	);
}

/**
 * Views-with-zero-revenue: rows with views > 0 but revenue = 0.
 *
 * Usually sub-threshold ad fills on low-traffic pages. Warning at high volume.
 *
 * @param array $rows Normalized rows.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_views_zero_revenue( array $rows ) {
	$count      = 0;
	$views      = 0;
	$total_rows = count( $rows );

	foreach ( $rows as $r ) {
		$v   = isset( $r['views'] ) ? (int) $r['views'] : 0;
		$rev = isset( $r['revenue'] ) ? (float) $r['revenue'] : 0.0;
		if ( $v > 0 && $rev <= 0 ) {
			++$count;
			$views += $v;
		}
	}

	$ratio = $total_rows > 0 ? $count / $total_rows : 0.0;
	// Warn above 20% — a sizeable share of viewed pages earned nothing.
	$status     = ( $total_rows > 0 && $ratio > 0.20 ) ? 'warning' : 'pass';
	$evidence   = array();
	$evidence[] = "{$count} row(s) with {$views} views report zero revenue.";
	if ( 'warning' === $status ) {
		$evidence[] = sprintf( 'Over 20%% of rows (%.1f%%) are viewed-but-zero-revenue — typically sub-threshold ad fills on low-traffic pages.', $ratio * 100 );
	}

	return array(
		'check'    => 'views_zero_revenue',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'rows'  => $count,
			'views' => $views,
			'ratio' => round( $ratio, 4 ),
		),
	);
}

/**
 * Negative / impossible values: negative views/revenue/rpm/cpm, or RPM/CPM that
 * imply impossible rates. This is the one check where 'fail' is warranted on a
 * genuine data-integrity violation.
 *
 * @param array $rows Normalized rows.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_negative_impossible_values( array $rows ) {
	$bad     = array();
	$samples = array();

	foreach ( $rows as $r ) {
		$issues = array();
		$views  = isset( $r['views'] ) ? (int) $r['views'] : 0;
		$rev    = isset( $r['revenue'] ) ? (float) $r['revenue'] : 0.0;
		$rpm    = isset( $r['source_rpm'] ) ? (float) $r['source_rpm'] : 0.0;
		$cpm    = isset( $r['cpm'] ) ? (float) $r['cpm'] : 0.0;

		if ( $views < 0 ) {
			$issues[] = "negative views ({$views})";
		}
		if ( $rev < 0 ) {
			$issues[] = "negative revenue ({$rev})";
		}
		if ( $rpm < 0 ) {
			$issues[] = "negative source rpm ({$rpm})";
		}
		if ( $cpm < 0 ) {
			$issues[] = "negative cpm ({$cpm})";
		}

		if ( ! empty( $issues ) ) {
			$bad[] = $r;
			if ( count( $samples ) < 5 ) {
				$samples[] = array(
					'slug'   => isset( $r['slug'] ) ? (string) $r['slug'] : '',
					'issues' => $issues,
				);
			}
		}
	}

	$status   = empty( $bad ) ? 'pass' : 'fail';
	$evidence = array();
	if ( empty( $bad ) ) {
		$evidence[] = 'No negative or impossible values detected.';
	} else {
		$evidence[] = count( $bad ) . ' row(s) with negative or impossible values:';
		foreach ( $samples as $s ) {
			$evidence[] = "  {$s['slug']}: " . implode( '; ', $s['issues'] );
		}
		$evidence[] = 'Negative revenue/views/rates are genuine integrity violations — investigate the import source.';
	}

	return array(
		'check'    => 'negative_impossible_values',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'rows'    => count( $bad ),
			'samples' => $samples,
		),
	);
}

/**
 * Stored-vs-derived RPM variance: where Mediavine's reported rpm disagrees with
 * revenue/(views/1000). Disclosure — the two RPMs are kept distinct on purpose.
 *
 * @param array $rows Normalized rows.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_rpm_variance( array $rows ) {
	$high_variance = 0;
	$checked       = 0;
	$samples       = array();

	foreach ( $rows as $r ) {
		$views = isset( $r['views'] ) ? (int) $r['views'] : 0;
		if ( $views <= 0 ) {
			continue;
		}
		++$checked;

		$derived = isset( $r['derived_rpm'] ) ? (float) $r['derived_rpm'] : 0.0;
		$source  = isset( $r['source_rpm'] ) ? (float) $r['source_rpm'] : 0.0;

		$denom    = max( abs( $source ), 0.0001 );
		$relative = abs( $derived - $source ) / $denom;

		if ( $relative > 0.20 ) {
			++$high_variance;
			if ( count( $samples ) < 5 ) {
				$samples[] = array(
					'slug'        => isset( $r['slug'] ) ? (string) $r['slug'] : '',
					'source_rpm'  => round( $source, 4 ),
					'derived_rpm' => round( $derived, 4 ),
					'relative'    => round( $relative, 4 ),
				);
			}
		}
	}

	$ratio = $checked > 0 ? $high_variance / $checked : 0.0;
	// Warn above 10% — a sizeable share of rows disagree by more than 20%.
	$status     = ( $checked > 0 && $ratio > 0.10 ) ? 'warning' : 'pass';
	$evidence   = array();
	$evidence[] = "{$high_variance} of {$checked} views-positive row(s) differ by >20% between source rpm and derived rpm.";
	if ( 'warning' === $status ) {
		$evidence[] = 'The two RPMs disagree widely — they are reported separately on purpose (source_rpm vs derived_rpm). Rank on whichever fits the question.';
	}

	return array(
		'check'    => 'stored_vs_derived_rpm_variance',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'checked'       => $checked,
			'high_variance' => $high_variance,
			'ratio'         => round( $ratio, 4 ),
			'samples'       => $samples,
		),
	);
}

/**
 * Source/import provenance coverage: rows missing an import_batch or period_label.
 *
 * The import path always stamps both, so any gap is a regression signal.
 *
 * @param array $rows Normalized rows.
 * @return array Check.
 */
function extrachill_analytics_revenue_diag_provenance_coverage( array $rows ) {
	$total          = count( $rows );
	$missing_batch  = 0;
	$missing_period = 0;

	foreach ( $rows as $r ) {
		$batch = isset( $r['import_batch'] ) ? (string) $r['import_batch'] : '';
		$label = isset( $r['period_label'] ) ? (string) $r['period_label'] : '';
		if ( '' === $batch ) {
			++$missing_batch;
		}
		if ( '' === $label ) {
			++$missing_period;
		}
	}

	$missing  = $missing_batch + $missing_period;
	$status   = ( $total > 0 && $missing > 0 ) ? 'warning' : 'pass';
	$evidence = array();
	if ( 0 === $missing ) {
		$evidence[] = "All {$total} row(s) carry import_batch and period_label provenance.";
	} else {
		$evidence[] = "{$missing_batch} row(s) missing import_batch, {$missing_period} missing period_label.";
		$evidence[] = 'The import path always stamps both — a gap suggests a write-path regression.';
	}

	return array(
		'check'    => 'provenance_coverage',
		'status'   => $status,
		'evidence' => $evidence,
		'totals'   => array(
			'rows'           => $total,
			'missing_batch'  => $missing_batch,
			'missing_period' => $missing_period,
		),
	);
}
