<?php
/**
 * Mediavine Pages CSV Importer
 *
 * Ingests a Mediavine Dashboard "Pages" export into the revenue store. Mediavine
 * has no per-page revenue API (its Control Panel plugin only pulls ad-script
 * settings), so this CSV import is the canonical path from "Mediavine knows what
 * each page earned" to "we can join that to content categories."
 *
 * Expected columns (header names are matched case-insensitively and loosely, so
 * minor dashboard-export renames still map):
 *   slug | views | revenue | rpm | cpm | viewability | fillRate | impressionsPerPageview
 *
 * The slug column may carry a bare slug, a host-relative path, or a full URL —
 * the resolver normalizes all three. Each imported row is stamped with the
 * import batch + optional period so a recent export and an all-time export can
 * coexist for the recent-vs-lifetime lens.
 *
 * @package ExtraChill\Analytics
 * @since 0.16.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Canonical header => store-field map.
 *
 * Keys are normalized header tokens (lowercased, non-alphanumerics stripped);
 * the matcher normalizes each CSV header the same way before lookup so
 * "Page RPM", "rpm", and "RPM" all collapse to "rpm".
 *
 * @return array<string, string>
 */
function extrachill_analytics_revenue_csv_header_map() {
	return array(
		'slug'                   => 'slug',
		'url'                    => 'slug',
		'page'                   => 'slug',
		'pagepath'               => 'slug',
		'path'                   => 'slug',
		'views'                  => 'views',
		'pageviews'              => 'views',
		'sessions'               => 'views',
		'revenue'                => 'revenue',
		'earnings'               => 'revenue',
		'estimatedrevenue'       => 'revenue',
		'rpm'                    => 'rpm',
		'pagerpm'                => 'rpm',
		'cpm'                    => 'cpm',
		'viewability'            => 'viewability',
		'fillrate'               => 'fill_rate',
		'impressionsperpageview' => 'impressions_per_pageview',
		'impressionsperpv'       => 'impressions_per_pageview',
	);
}

/**
 * Normalize a header token for loose matching.
 *
 * @param string $header Raw header cell.
 * @return string
 */
function extrachill_analytics_revenue_normalize_header( $header ) {
	return preg_replace( '/[^a-z0-9]/', '', strtolower( trim( (string) $header ) ) );
}

/**
 * Parse a money/number cell into a float (strips $, commas, %).
 *
 * @param string $value Raw cell.
 * @return float
 */
function extrachill_analytics_revenue_parse_number( $value ) {
	$value = (string) $value;
	$value = preg_replace( '/[^0-9.\-]/', '', $value );

	return '' === $value ? 0.0 : (float) $value;
}

/**
 * Import a Mediavine Pages CSV into the revenue store.
 *
 * @param string $file  Absolute path to the CSV.
 * @param array  $args {
 *     Import options.
 *
 *     @type int    $blog_id      Blog the pages belong to (default: current blog).
 *     @type string $hostname     Hostname for slug->post resolution (default: extrachill.com).
 *     @type string $period_start Window start (Y-m-d) for these snapshots, or '' (default: '').
 *     @type string $period_end   Window end (Y-m-d) for these snapshots, or '' (default: '').
 *     @type string $import_batch Batch label (default: auto from filename + timestamp).
 *     @type bool   $dry_run      Parse + resolve but do not write (default: false).
 * }
 * @return array {
 *     Import result.
 *
 *     @type bool   $success
 *     @type int    $rows       Rows parsed.
 *     @type int    $imported   Rows written (0 on dry-run).
 *     @type int    $resolved   Rows whose slug resolved to a post.
 *     @type int    $unresolved Rows that did not resolve to a post.
 *     @type string $batch      Import batch label used.
 *     @type array  $samples    First few resolved rows (slug, post_id, revenue).
 *     @type string $error      Error message on failure.
 * }
 */
function extrachill_analytics_revenue_import_csv( $file, array $args = array() ) {
	$blog_id      = isset( $args['blog_id'] ) ? (int) $args['blog_id'] : get_current_blog_id();
	$hostname     = ! empty( $args['hostname'] ) ? (string) $args['hostname'] : 'extrachill.com';
	$period_start = isset( $args['period_start'] ) ? (string) $args['period_start'] : '';
	$period_end   = isset( $args['period_end'] ) ? (string) $args['period_end'] : '';
	$dry_run      = ! empty( $args['dry_run'] );
	$batch        = ! empty( $args['import_batch'] )
		? (string) $args['import_batch']
		: sanitize_key( basename( $file ) ) . '-' . gmdate( 'YmdHis' );

	if ( ! is_readable( $file ) ) {
		return array(
			'success' => false,
			'error'   => "CSV not readable: {$file}",
		);
	}

	$handle = fopen( $file, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen -- CSV is stream-parsed line-by-line via fgetcsv; WP_Filesystem has no streaming reader.
	if ( false === $handle ) {
		return array(
			'success' => false,
			'error'   => "Could not open CSV: {$file}",
		);
	}

	$header_map = extrachill_analytics_revenue_csv_header_map();
	$columns    = array();
	$headers    = fgetcsv( $handle );

	if ( false === $headers ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose -- paired with the streaming fopen above.
		return array(
			'success' => false,
			'error'   => 'CSV is empty (no header row).',
		);
	}

	foreach ( $headers as $index => $raw ) {
		$key = extrachill_analytics_revenue_normalize_header( $raw );
		if ( isset( $header_map[ $key ] ) ) {
			$columns[ $header_map[ $key ] ] = $index;
		}
	}

	if ( ! isset( $columns['slug'] ) ) {
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose -- paired with the streaming fopen above.
		return array(
			'success' => false,
			'error'   => 'CSV has no slug/url/page column. Headers seen: ' . implode( ', ', array_map( 'sanitize_text_field', $headers ) ),
		);
	}

	$rows       = 0;
	$imported   = 0;
	$resolved   = 0;
	$unresolved = 0;
	$samples    = array();

	$cell = static function ( $line, $columns, $field ) {
		if ( ! isset( $columns[ $field ] ) ) {
			return '';
		}
		$idx = $columns[ $field ];
		return isset( $line[ $idx ] ) ? $line[ $idx ] : '';
	};

	// phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition -- idiomatic fgetcsv streaming loop.
	while ( false !== ( $line = fgetcsv( $handle ) ) ) {
		$raw_slug = trim( (string) $cell( $line, $columns, 'slug' ) );
		if ( '' === $raw_slug ) {
			continue;
		}

		++$rows;

		$post_id = extrachill_analytics_revenue_resolve_post_id( $raw_slug, $hostname );
		if ( $post_id > 0 ) {
			++$resolved;
		} else {
			++$unresolved;
		}

		// Normalize the slug to a stable key: prefer the post's slug when
		// resolved, else the path's last segment.
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			$slug = $post instanceof WP_Post ? $post->post_name : $raw_slug;
		} else {
			$path = strtok( $raw_slug, '?#' );
			$slug = trim( (string) $path, '/' );
		}

		$record = array(
			'blog_id'                  => $blog_id,
			'slug'                     => $slug,
			'url'                      => $raw_slug,
			'post_id'                  => $post_id,
			'views'                    => (int) extrachill_analytics_revenue_parse_number( $cell( $line, $columns, 'views' ) ),
			'revenue'                  => extrachill_analytics_revenue_parse_number( $cell( $line, $columns, 'revenue' ) ),
			'rpm'                      => extrachill_analytics_revenue_parse_number( $cell( $line, $columns, 'rpm' ) ),
			'cpm'                      => extrachill_analytics_revenue_parse_number( $cell( $line, $columns, 'cpm' ) ),
			'viewability'              => extrachill_analytics_revenue_parse_number( $cell( $line, $columns, 'viewability' ) ),
			'fill_rate'                => extrachill_analytics_revenue_parse_number( $cell( $line, $columns, 'fill_rate' ) ),
			'impressions_per_pageview' => extrachill_analytics_revenue_parse_number( $cell( $line, $columns, 'impressions_per_pageview' ) ),
			'period_start'             => $period_start,
			'period_end'               => $period_end,
			'import_batch'             => $batch,
		);

		if ( ! $dry_run ) {
			$ok = extrachill_analytics_revenue_upsert( $record );
			if ( false !== $ok ) {
				++$imported;
			}
		}

		if ( count( $samples ) < 5 ) {
			$samples[] = array(
				'slug'    => $slug,
				'post_id' => $post_id,
				'revenue' => round( $record['revenue'], 2 ),
				'views'   => $record['views'],
			);
		}
	}

	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fclose -- paired with the streaming fopen above.

	return array(
		'success'    => true,
		'rows'       => $rows,
		'imported'   => $imported,
		'resolved'   => $resolved,
		'unresolved' => $unresolved,
		'batch'      => $batch,
		'dry_run'    => $dry_run,
		'samples'    => $samples,
	);
}
