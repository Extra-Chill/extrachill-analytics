<?php
/**
 * Get Content Revenue Pages Ability
 *
 * Page-level sibling of extrachill/get-content-revenue. The rollup ability owns
 * format/category/timeseries buckets; this ability owns the FLAT per-page lens —
 * every resolved published post (or unresolved route) as its own ranked row with
 * the full Mediavine delivery stack (views, revenue, derived RPM, source RPM,
 * CPM, viewability, fill rate, impressions/pageview) plus post metadata.
 *
 * Why a sibling and not a new group_by axis on the rollup: the rollup output
 * contract is rollups/totals/unresolved — it cannot cleanly represent a flat,
 * machine-sortable page list with per-page rate metrics and a benchmark flag.
 * The issue explicitly permits narrowly scoped siblings where the existing
 * contract cannot represent the new lens. This ability reuses the SAME store,
 * resolver, content-format classifier, and route-family classifier as the
 * rollup — no new tables, no parallel aggregation, no client.
 *
 * Derived vs source RPM (kept distinct, on purpose): derived_rpm is recomputed
 * here as revenue / (views / 1000); source_rpm is the Mediavine-reported `rpm`
 * column from the CSV. They can disagree (Mediavine rounds; its denominator may
 * differ from pageviews). Both are reported and both are sort keys, so the
 * analyst can rank on either and spot variance. They are never blended into one
 * "rpm" field.
 *
 * Zero-view revenue handling: a Mediavine row CAN report revenue > 0 with views
 * = 0 (rounding, sub-threshold fills, a missed views cell). derived_rpm is set
 * to 0.0 (NEVER a division error, NEVER infinity), the page is flagged
 * `zero_views => true`, and the revenue is preserved and visible — never hidden.
 * A caveat discloses the cohort so the analyst is never misled.
 *
 * Benchmark opportunity (optional, defensible): a page qualifies when it shows
 * genuinely strong RPM AT meaningful volume — derived_rpm >= 1.5x the cohort
 * median derived_rpm AND views >= the cohort median views (both medians taken
 * over pages with views > 0). High RPM on tiny volume never qualifies ("high RPM
 * on tiny volume = pennies"). Requires at least 5 views-positive pages or the
 * flag is suppressed (insufficient sample). This is an analytical signal ("this
 * page is worth studying"), not a recommendation engine.
 *
 * @package ExtraChill\Analytics
 * @since 0.28.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the get-content-revenue-pages ability.
 */
function extrachill_analytics_register_content_revenue_pages_ability() {
	wp_register_ability(
		'extrachill/get-content-revenue-pages',
		array(
			'label'               => __( 'Get Content Revenue Pages Lens', 'extrachill-analytics' ),
			'description'         => __( 'Page-level Mediavine revenue lens. Returns every resolved published post (or unresolved route) as its own ranked row with the full delivery stack: views, revenue, DERIVED RPM (revenue / views/1000), SOURCE RPM (Mediavine-reported), CPM, viewability, fill rate, impressions/pageview, plus post metadata (ID, title, URL/path, category, content format, publication date) when resolved. Derived and source RPM are kept distinct — they can disagree and both are sort keys. Revenue rows with zero pageviews are flagged zero_views and preserved (derived_rpm = 0, never a division error). Supports cohort (resolved/unresolved/all), minimum-views threshold, stable sorting by any metric, limit, and an optional defensible benchmark-opportunity flag (high RPM at meaningful volume). Reuses the same store, resolver, and classifiers as extrachill/get-content-revenue — no new tables. CLI consumers map args and format output only.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'period'            => array(
						'type'        => 'string',
						'description' => __( 'Scope to one time bucket, e.g. "2026-05" or "all-time". Empty = every bucket combined.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'period_start'      => array(
						'type'        => 'string',
						'description' => __( 'Inclusive window start (Y-m-d). With period_end, restricts to snapshots imported for that window (the recent lens).', 'extrachill-analytics' ),
						'default'     => '',
					),
					'period_end'        => array(
						'type'        => 'string',
						'description' => __( 'Inclusive window end (Y-m-d). See period_start.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'import_batch'      => array(
						'type'        => 'string',
						'description' => __( 'Restrict to one import batch so multiple imports are never double-counted. Empty = all batches.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'blog_id'           => array(
						'type'        => 'integer',
						'description' => __( 'Blog ID whose revenue to read. 0 = current blog.', 'extrachill-analytics' ),
						'default'     => 0,
					),
					'hostname'          => array(
						'type'        => 'string',
						'description' => __( 'Hostname for resolving any still-unresolved slugs to posts (default: extrachill.com).', 'extrachill-analytics' ),
						'default'     => 'extrachill.com',
					),
					'cohort'            => array(
						'type'        => 'string',
						'description' => __( 'Page cohort: "resolved" (published content only, default), "unresolved" (route cohort), or "all".', 'extrachill-analytics' ),
						'default'     => 'resolved',
					),
					'min_views'         => array(
						'type'        => 'integer',
						'description' => __( 'Exclude pages with fewer than this many views. Default 0 (no floor).', 'extrachill-analytics' ),
						'default'     => 0,
					),
					'sort_by'           => array(
						'type'        => 'string',
						'description' => __( 'Sort key: views, revenue, derived_rpm, source_rpm, cpm, viewability, fill_rate, impressions_per_pageview, dollars_per_page, or benchmark_opportunity. Default derived_rpm.', 'extrachill-analytics' ),
						'default'     => 'derived_rpm',
					),
					'order'             => array(
						'type'        => 'string',
						'description' => __( 'Sort direction: "desc" (default) or "asc".', 'extrachill-analytics' ),
						'default'     => 'desc',
					),
					'limit'             => array(
						'type'        => 'integer',
						'description' => __( 'Maximum pages to return. 0 = unlimited. Default 50.', 'extrachill-analytics' ),
						'default'     => 50,
					),
					'include_post_meta' => array(
						'type'        => 'boolean',
						'description' => __( 'Include post metadata (title, URL, path, category, format, publication date) on resolved pages. Default true.', 'extrachill-analytics' ),
						'default'     => true,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'pages array (each: page_key, cohort, post_id, title, url, path, categories, format, route_family, published_date, views, revenue, derived_rpm, source_rpm, cpm, viewability, fill_rate, impressions_per_pageview, dollars_per_page, zero_views, benchmark_opportunity, benchmark_score), plus totals, sample metadata, sort/window labels, and caveats.', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_get_content_revenue_pages',
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
 * Execute callback for get-content-revenue-pages ability.
 *
 * Owns the WordPress-dependent half: SQL (via the shared store helper),
 * slug->post resolution, the publish-status gate, term/format/post-metadata
 * lookup. Hands one record per resolved row to the pure builder, which owns
 * dedupe, aggregation, metric derivation, thresholding, sorting, and the
 * benchmark computation.
 *
 * @param array $input Input parameters.
 * @return array Page-level response.
 */
function extrachill_analytics_ability_get_content_revenue_pages( $input ) {
	$period            = isset( $input['period'] ) ? (string) $input['period'] : '';
	$period_start      = isset( $input['period_start'] ) ? (string) $input['period_start'] : '';
	$period_end        = isset( $input['period_end'] ) ? (string) $input['period_end'] : '';
	$import_batch      = isset( $input['import_batch'] ) ? (string) $input['import_batch'] : '';
	$blog_id           = ! empty( $input['blog_id'] ) ? (int) $input['blog_id'] : get_current_blog_id();
	$hostname          = ! empty( $input['hostname'] ) ? (string) $input['hostname'] : 'extrachill.com';
	$cohort            = isset( $input['cohort'] ) ? (string) $input['cohort'] : 'resolved';
	$min_views         = isset( $input['min_views'] ) ? max( 0, (int) $input['min_views'] ) : 0;
	$sort_by           = isset( $input['sort_by'] ) ? (string) $input['sort_by'] : 'derived_rpm';
	$order             = isset( $input['order'] ) ? (string) $input['order'] : 'desc';
	$limit             = isset( $input['limit'] ) ? max( 0, (int) $input['limit'] ) : 50;
	$include_post_meta = isset( $input['include_post_meta'] ) ? (bool) $input['include_post_meta'] : true;

	if ( ! in_array( $cohort, array( 'resolved', 'unresolved', 'all' ), true ) ) {
		$cohort = 'resolved';
	}
	if ( ! in_array( $order, array( 'desc', 'asc' ), true ) ) {
		$order = 'desc';
	}

	$valid_sorts = array(
		'views',
		'revenue',
		'derived_rpm',
		'source_rpm',
		'cpm',
		'viewability',
		'fill_rate',
		'impressions_per_pageview',
		'dollars_per_page',
		'benchmark_opportunity',
	);
	if ( ! in_array( $sort_by, $valid_sorts, true ) ) {
		$sort_by = 'derived_rpm';
	}

	$rows = extrachill_analytics_revenue_get_rows(
		array(
			'blog_id'      => $blog_id,
			'import_batch' => $import_batch,
			'period_label' => $period,
			'period_start' => $period_start,
			'period_end'   => $period_end,
		)
	);

	if ( empty( $rows ) ) {
		return array(
			'success' => true,
			'rows'    => 0,
			'blog_id' => $blog_id,
			'window'  => extrachill_analytics_revenue_window_label( $period_start, $period_end, $period ),
			'cohort'  => $cohort,
			'sort_by' => $sort_by,
			'order'   => $order,
			'pages'   => array(),
			'totals'  => array(
				'pages_before_limit' => 0,
				'pages_returned'     => 0,
				'zero_views_pages'   => 0,
			),
			'sample'  => array(
				'rows_in_window'           => 0,
				'resolved_pages'           => 0,
				'unresolved_pages'         => 0,
				'after_min_views'          => 0,
				'truncated'                => false,
				'benchmark_computed'       => false,
				'sufficient_for_benchmark' => false,
			),
			'caveat'  => __( 'No revenue snapshots for this blog/window. Import a Mediavine Pages CSV first: wp extrachill analytics revenue import <csv>.', 'extrachill-analytics' ),
		);
	}

	// Resolve each snapshot row to a content record or an unresolved-route
	// record — the SAME classification gate as the rollup (publish-status
	// recheck keeps stale post IDs out of content). Post metadata is attached
	// once per resolved post_id (cached) so variant rows of one post share it.
	$records    = array();
	$post_cache = array();

	foreach ( $rows as $row ) {
		$post_id = (int) $row->post_id;
		if ( $post_id <= 0 && '' !== $row->slug ) {
			$post_id = extrachill_analytics_revenue_resolve_post_id( $row->url ? $row->url : $row->slug, $hostname );
		}

		$is_content = $post_id > 0 && 'publish' === get_post_status( $post_id );

		$title          = '';
		$url            = $row->url ? $row->url : $row->slug;
		$path           = '';
		$categories     = array();
		$format         = '';
		$published_date = '';
		$route_family   = '';

		if ( $is_content ) {
			if ( ! isset( $post_cache[ $post_id ] ) ) {
				$post = get_post( $post_id );
				if ( $post ) {
					$permalink              = get_permalink( $post_id );
					$categories_list        = get_the_terms( $post_id, 'category' );
					$post_cache[ $post_id ] = array(
						'title'          => $post->post_title,
						'url'            => is_string( $permalink ) ? $permalink : '',
						'published_date' => $post->post_date,
						'categories'     => ( is_array( $categories_list ) && ! empty( $categories_list ) )
							? wp_list_pluck( $categories_list, 'slug' )
							: array( 'uncategorized' ),
						'format'         => extrachill_analytics_classify_format( $post_id ),
					);
				} else {
					$post_cache[ $post_id ] = null;
				}
			}

			$cached = $post_cache[ $post_id ];
			if ( is_array( $cached ) ) {
				$title          = $include_post_meta ? $cached['title'] : '';
				$url            = $include_post_meta ? $cached['url'] : ( $row->url ? $row->url : $row->slug );
				$published_date = $include_post_meta ? $cached['published_date'] : '';
				$categories     = $cached['categories'];
				$format         = $cached['format'];
			}
		} else {
			$route_family = extrachill_analytics_revenue_classify_route_family( $row->url ? $row->url : $row->slug );
		}

		$records[] = array(
			'page_key'                 => $is_content ? 'p' . $post_id : 'u' . md5( (string) $row->slug ),
			'is_content'               => $is_content,
			'post_id'                  => $is_content ? $post_id : 0,
			'format'                   => $format,
			'categories'               => $categories,
			'route_family'             => $route_family,
			'views'                    => (int) $row->views,
			'revenue'                  => (float) $row->revenue,
			'source_rpm'               => (float) $row->rpm,
			'cpm'                      => (float) $row->cpm,
			'viewability'              => (float) $row->viewability,
			'fill_rate'                => (float) $row->fill_rate,
			'impressions_per_pageview' => (float) $row->impressions_per_pageview,
			'url'                      => $url,
			'title'                    => $title,
			'path'                     => $path,
			'published_date'           => $published_date,
		);
	}

	$built = extrachill_analytics_revenue_build_pages(
		$records,
		array(
			'cohort'    => $cohort,
			'min_views' => $min_views,
			'sort_by'   => $sort_by,
			'order'     => $order,
			'limit'     => $limit,
		)
	);

	return array(
		'success' => true,
		'rows'    => count( $rows ),
		'blog_id' => $blog_id,
		'window'  => extrachill_analytics_revenue_window_label( $period_start, $period_end, $period ),
		'cohort'  => $cohort,
		'sort_by' => $sort_by,
		'order'   => $order,
		'pages'   => $built['pages'],
		'totals'  => $built['totals'],
		'sample'  => $built['sample'],
		'caveat'  => __( 'Pages cover the requested cohort. derived_rpm = revenue / (views/1000), recomputed here; source_rpm is the Mediavine-reported rpm column — they can disagree and are kept distinct (rank on either). Pages with revenue but zero views are flagged zero_views with derived_rpm = 0 (never a division error) and revenue preserved. benchmark_opportunity flags genuinely strong RPM at meaningful volume (>= 1.5x cohort median RPM AND >= cohort median views; requires >= 5 views-positive pages). URL-variant rows of one post collapse by page_key; rate metrics are views-weighted averages across variants. Revenue is Mediavine-imported (the only source of ad income); never estimated.', 'extrachill-analytics' ),
	);
}

/**
 * Build the page-level lens from pre-classified revenue records.
 *
 * PURE — no WordPress / DB dependency — so dedupe, aggregation, metric
 * derivation, thresholding, sorting, and the benchmark computation are all
 * unit-testable in isolation. The execute_callback owns resolution and
 * post-metadata; this function owns the analytical contract.
 *
 * CONTRACT (issue #141):
 *   - One record per ROW is passed in; this function dedupes by page_key (the
 *     same 'p'.post_id / 'u'.hash(slug) convention as the rollup) so URL
 *     variants of one post collapse into one page row, summing views/revenue.
 *   - Rate metrics (source_rpm, cpm, viewability, fill_rate,
 *     impressions_per_pageview) are views-weighted averages across collapsed
 *     variants — rates are never summed.
 *   - derived_rpm = revenue / (views/1000), recomputed AFTER aggregation. It is
 *     NEVER averaged from source values and NEVER collides with source_rpm.
 *   - Zero-view revenue: derived_rpm = 0.0, zero_views = true, revenue kept.
 *   - Cohort filter (resolved/unresolved/all) and min_views threshold are
 *     applied AFTER aggregation (so a page's full volume counts toward the
 *     floor) and BEFORE sorting.
 *   - Sorting is stable: the primary sort key is followed by a deterministic
 *     page_key tiebreaker so output is machine-stable across runs.
 *
 * @param array $records Pre-classified per-row records (see execute_callback).
 * @param array $args {
 *     Build options.
 *     @type string $cohort    'resolved' | 'unresolved' | 'all'.
 *     @type int    $min_views Minimum views floor.
 *     @type string $sort_by   Sort key.
 *     @type string $order     'desc' | 'asc'.
 *     @type int    $limit     Max pages (0 = unlimited).
 * }
 * @return array { pages, totals, sample }
 */
function extrachill_analytics_revenue_build_pages( array $records, array $args ) {
	$cohort    = isset( $args['cohort'] ) ? (string) $args['cohort'] : 'resolved';
	$min_views = isset( $args['min_views'] ) ? max( 0, (int) $args['min_views'] ) : 0;
	$sort_by   = isset( $args['sort_by'] ) ? (string) $args['sort_by'] : 'derived_rpm';
	$order     = isset( $args['order'] ) && 'asc' === $args['order'] ? 'asc' : 'desc';
	$limit     = isset( $args['limit'] ) ? max( 0, (int) $args['limit'] ) : 0;

	$resolved_pages   = 0;
	$unresolved_pages = 0;

	// 1. Aggregate by page_key, carrying metadata from the first variant.
	$agg = array();
	foreach ( $records as $rec ) {
		$key = (string) ( isset( $rec['page_key'] ) ? $rec['page_key'] : '' );
		if ( '' === $key ) {
			continue;
		}

		$is_content = ! empty( $rec['is_content'] );

		if ( ! isset( $agg[ $key ] ) ) {
			if ( $is_content ) {
				++$resolved_pages;
			} else {
				++$unresolved_pages;
			}
			$agg[ $key ] = array(
				'page_key'            => $key,
				'is_content'          => $is_content,
				'post_id'             => (int) ( isset( $rec['post_id'] ) ? $rec['post_id'] : 0 ),
				'format'              => isset( $rec['format'] ) ? (string) $rec['format'] : '',
				'categories'          => isset( $rec['categories'] ) && is_array( $rec['categories'] ) ? array_values( $rec['categories'] ) : array(),
				'route_family'        => isset( $rec['route_family'] ) ? (string) $rec['route_family'] : '',
				'views'               => 0,
				'revenue'             => 0.0,
				'source_rpm_values'   => array(),
				'source_rpm_weights'  => array(),
				'cpm_values'          => array(),
				'cpm_weights'         => array(),
				'viewability_values'  => array(),
				'viewability_weights' => array(),
				'fill_rate_values'    => array(),
				'fill_rate_weights'   => array(),
				'impressions_values'  => array(),
				'impressions_weights' => array(),
				'url'                 => isset( $rec['url'] ) ? (string) $rec['url'] : '',
				'title'               => isset( $rec['title'] ) ? (string) $rec['title'] : '',
				'path'                => isset( $rec['path'] ) ? (string) $rec['path'] : '',
				'published_date'      => isset( $rec['published_date'] ) ? (string) $rec['published_date'] : '',
			);
		}

		$a             = &$agg[ $key ];
		$views         = (int) ( isset( $rec['views'] ) ? $rec['views'] : 0 );
		$a['views']   += $views;
		$a['revenue'] += (float) ( isset( $rec['revenue'] ) ? $rec['revenue'] : 0.0 );

		$a['source_rpm_values'][]   = (float) ( isset( $rec['source_rpm'] ) ? $rec['source_rpm'] : 0.0 );
		$a['source_rpm_weights'][]  = $views;
		$a['cpm_values'][]          = (float) ( isset( $rec['cpm'] ) ? $rec['cpm'] : 0.0 );
		$a['cpm_weights'][]         = $views;
		$a['viewability_values'][]  = (float) ( isset( $rec['viewability'] ) ? $rec['viewability'] : 0.0 );
		$a['viewability_weights'][] = $views;
		$a['fill_rate_values'][]    = (float) ( isset( $rec['fill_rate'] ) ? $rec['fill_rate'] : 0.0 );
		$a['fill_rate_weights'][]   = $views;
		$a['impressions_values'][]  = (float) ( isset( $rec['impressions_per_pageview'] ) ? $rec['impressions_per_pageview'] : 0.0 );
		$a['impressions_weights'][] = $views;
		unset( $a );
	}

	// 2. Finalize per-page derived metrics + cohort/min_views filtering.
	$pages = array();
	foreach ( $agg as $key => $a ) {
		$views   = (int) $a['views'];
		$revenue = (float) $a['revenue'];

		// Cohort filter.
		if ( 'resolved' === $cohort && ! $a['is_content'] ) {
			continue;
		}
		if ( 'unresolved' === $cohort && $a['is_content'] ) {
			continue;
		}

		// min_views floor (applied AFTER aggregation so full volume counts).
		if ( $views < $min_views ) {
			continue;
		}

		$source_rpm  = extrachill_analytics_revenue_weighted_average( $a['source_rpm_values'], $a['source_rpm_weights'] );
		$cpm         = extrachill_analytics_revenue_weighted_average( $a['cpm_values'], $a['cpm_weights'] );
		$viewability = extrachill_analytics_revenue_weighted_average( $a['viewability_values'], $a['viewability_weights'] );
		$fill_rate   = extrachill_analytics_revenue_weighted_average( $a['fill_rate_values'], $a['fill_rate_weights'] );
		$impressions = extrachill_analytics_revenue_weighted_average( $a['impressions_values'], $a['impressions_weights'] );

		$zero_views  = $views <= 0;
		$derived_rpm = $views > 0 ? round( $revenue / ( $views / 1000 ), 4 ) : 0.0;

		$pages[ $key ] = array(
			'page_key'                 => $key,
			'cohort'                   => $a['is_content'] ? 'resolved' : 'unresolved',
			'post_id'                  => $a['post_id'],
			'title'                    => $a['title'],
			'url'                      => $a['url'],
			'path'                     => $a['path'],
			'categories'               => $a['categories'],
			'format'                   => $a['is_content'] ? $a['format'] : '',
			'route_family'             => $a['is_content'] ? '' : $a['route_family'],
			'published_date'           => $a['published_date'],
			'views'                    => $views,
			'revenue'                  => round( $revenue, 4 ),
			'derived_rpm'              => $derived_rpm,
			'source_rpm'               => round( $source_rpm, 4 ),
			'cpm'                      => round( $cpm, 4 ),
			'viewability'              => round( $viewability, 4 ),
			'fill_rate'                => round( $fill_rate, 4 ),
			'impressions_per_pageview' => round( $impressions, 4 ),
			'dollars_per_page'         => round( $revenue, 4 ),
			'zero_views'               => $zero_views,
			'benchmark_opportunity'    => false,
			'benchmark_score'          => null,
		);
	}

	// 3. Benchmark opportunity — computed over the in-cohort, post-threshold
	// pages with views > 0. Conservative: requires >= 5 views-positive pages.
	$benchmark_computed       = false;
	$sufficient_for_benchmark = false;
	$views_positive           = array();
	foreach ( $pages as $p ) {
		if ( $p['views'] > 0 ) {
			$views_positive[] = $p;
		}
	}

	if ( count( $views_positive ) >= 5 ) {
		$sufficient_for_benchmark = true;
		$benchmark_computed       = true;
		$rpm_values               = array_map(
			static function ( $p ) {
				return $p['derived_rpm'];
			},
			$views_positive
		);
		$views_values             = array_map(
			static function ( $p ) {
				return $p['views'];
			},
			$views_positive
		);
		$median_rpm               = extrachill_analytics_revenue_median( $rpm_values );
		$median_views             = extrachill_analytics_revenue_median( $views_values );

		foreach ( $pages as $key => $p ) {
			if ( $p['views'] <= 0 || $median_rpm <= 0 ) {
				$pages[ $key ]['benchmark_score'] = $p['views'] > 0 ? 0.0 : null;
				continue;
			}
			$score                                  = round( $p['derived_rpm'] / $median_rpm, 4 );
			$pages[ $key ]['benchmark_score']       = $score;
			$pages[ $key ]['benchmark_opportunity'] = ( $score >= 1.5 && $p['views'] >= $median_views );
		}
	}

	// 4. Stable sort: primary key then page_key tiebreaker.
	$pages = extrachill_analytics_revenue_sort_pages( array_values( $pages ), $sort_by, $order );

	// 5. Limit.
	$pages_before_limit = count( $pages );
	$truncated          = false;
	if ( $limit > 0 && $pages_before_limit > $limit ) {
		$pages     = array_slice( $pages, 0, $limit );
		$truncated = true;
	}

	$zero_views_pages = 0;
	$total_views      = 0;
	$total_revenue    = 0.0;
	foreach ( $pages as $p ) {
		if ( ! empty( $p['zero_views'] ) ) {
			++$zero_views_pages;
		}
		$total_views   += $p['views'];
		$total_revenue += $p['revenue'];
	}

	return array(
		'pages'  => $pages,
		'totals' => array(
			'pages_before_limit' => $pages_before_limit,
			'pages_returned'     => count( $pages ),
			'zero_views_pages'   => $zero_views_pages,
			'views'              => $total_views,
			'revenue'            => round( $total_revenue, 4 ),
		),
		'sample' => array(
			'rows_in_window'           => count( $records ),
			'resolved_pages'           => $resolved_pages,
			'unresolved_pages'         => $unresolved_pages,
			'after_min_views'          => $pages_before_limit,
			'truncated'                => $truncated,
			'benchmark_computed'       => $benchmark_computed,
			'sufficient_for_benchmark' => $sufficient_for_benchmark,
		),
	);
}

/**
 * Sort a pages array by the requested key with a deterministic page_key tiebreaker.
 *
 * Stable: ties on the primary key are broken by page_key (ascending) so two
 * runs over the same input produce identical output — the contract a
 * machine-readable lens needs.
 *
 * @param array  $pages   Page rows.
 * @param string $sort_by Sort key.
 * @param string $order   'desc' | 'asc'.
 * @return array Sorted pages.
 */
function extrachill_analytics_revenue_sort_pages( array $pages, $sort_by, $order ) {
	$desc = 'asc' !== $order;

	usort(
		$pages,
		static function ( $a, $b ) use ( $sort_by, $desc ) {
			$av = extrachill_analytics_revenue_sort_value( $a, $sort_by );
			$bv = extrachill_analytics_revenue_sort_value( $b, $sort_by );

			// Primary key. Nulls sort last regardless of direction.
			if ( null === $av && null !== $bv ) {
				return 1;
			}
			if ( null === $bv && null !== $av ) {
				return -1;
			}
			if ( null === $av && null === $bv ) {
				$cmp = 0;
			} else {
				$cmp = ( $av <=> $bv );
			}
			if ( 0 !== $cmp ) {
				return $desc ? -$cmp : $cmp;
			}

			// Deterministic tiebreaker on page_key (always ascending).
			return strcmp( (string) $a['page_key'], (string) $b['page_key'] );
		}
	);

	return $pages;
}

/**
 * Resolve a sort key to the comparable scalar on a page row.
 *
 * The benchmark_opportunity sort maps to a composite value so the flag wins
 * and the score orders within. Null scores sort last.
 *
 * @param array  $page    Page row.
 * @param string $sort_by Sort key.
 * @return float|int|null Comparable value.
 */
function extrachill_analytics_revenue_sort_value( array $page, $sort_by ) {
	switch ( $sort_by ) {
		case 'views':
			return (int) $page['views'];
		case 'revenue':
		case 'dollars_per_page':
			return (float) $page['revenue'];
		case 'derived_rpm':
			return (float) $page['derived_rpm'];
		case 'source_rpm':
			return (float) $page['source_rpm'];
		case 'cpm':
			return (float) $page['cpm'];
		case 'viewability':
			return (float) $page['viewability'];
		case 'fill_rate':
			return (float) $page['fill_rate'];
		case 'impressions_per_pageview':
			return (float) $page['impressions_per_pageview'];
		case 'benchmark_opportunity':
			// Composite: flag (0/1) in the high band, score in the low band.
			// flag=1 sorts above flag=0; within flag, higher score sorts above.
			if ( null === $page['benchmark_score'] ) {
				return null;
			}
			return ( ! empty( $page['benchmark_opportunity'] ) ? 1000000 : 0 ) + (float) $page['benchmark_score'];
		default:
			return (float) $page['derived_rpm'];
	}
}

/**
 * Views-weighted average of a set of values; falls back to a simple average
 * when the total weight is zero (all-zero views) so rate metrics stay sane.
 *
 * @param array $values  Numeric values.
 * @param array $weights Parallel non-negative weights.
 * @return float
 */
function extrachill_analytics_revenue_weighted_average( array $values, array $weights ) {
	$total_weight = 0.0;
	$weighted_sum = 0.0;
	$simple_sum   = 0.0;
	$count        = count( $values );

	for ( $i = 0; $i < $count; $i++ ) {
		$v             = isset( $values[ $i ] ) ? (float) $values[ $i ] : 0.0;
		$w             = isset( $weights[ $i ] ) ? (float) $weights[ $i ] : 0.0;
		$weighted_sum += $v * $w;
		$total_weight += $w;
		$simple_sum   += $v;
	}

	if ( $total_weight > 0 ) {
		return $weighted_sum / $total_weight;
	}
	if ( $count > 0 ) {
		return $simple_sum / $count;
	}
	return 0.0;
}

/**
 * Median of a numeric list (averages the two middle values on even counts).
 *
 * @param array $values Numeric values.
 * @return float
 */
function extrachill_analytics_revenue_median( array $values ) {
	$values = array_values(
		array_filter(
			$values,
			static function ( $v ) {
				return null !== $v;
			}
		)
	);
	$n      = count( $values );
	if ( 0 === $n ) {
		return 0.0;
	}
	sort( $values, SORT_NUMERIC );
	if ( 1 === $n % 2 ) {
		return (float) $values[ (int) floor( $n / 2 ) ];
	}
	$lo = (float) $values[ ( $n / 2 ) - 1 ];
	$hi = (float) $values[ $n / 2 ];
	return ( $lo + $hi ) / 2.0;
}
