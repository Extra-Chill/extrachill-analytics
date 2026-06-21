<?php
/**
 * Mediavine Revenue Store
 *
 * Per-URL ad-revenue snapshots imported from the Mediavine Dashboard "Pages"
 * CSV export. Mediavine exposes NO per-page revenue API — the Control Panel
 * plugin only fetches ad-script settings from scripts.mediavine.com/tags/ — so
 * a manual CSV import is the only path to per-URL revenue. This table makes the
 * one-off "join a Mediavine pages CSV to content categories" stitch repeatable.
 *
 * Each row is one (slug, import batch) snapshot: the metrics Mediavine reports
 * for that page over the date range the operator selected in the dashboard. The
 * `period_start`/`period_end` columns record that range so a "recent vs
 * lifetime" lens is possible — an all-time export and a last-30-days export can
 * coexist and be queried separately by window.
 *
 * Network-wide table (base_prefix) to mirror the events table, but every row
 * carries the blog_id it was imported for so multisite revenue never collides.
 *
 * @package ExtraChill\Analytics
 * @since 0.16.0
 */

defined( 'ABSPATH' ) || exit;

define( 'EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION', '1.0' );
define( 'EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION_OPTION', 'extrachill_analytics_revenue_db_version' );

/**
 * Create or update the Mediavine revenue table when the DB version changes.
 *
 * Uses base_prefix for a network-wide table shared across all sites.
 */
function extrachill_analytics_revenue_create_table() {
	$current_db_version = get_site_option( EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION_OPTION );

	if ( EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION === $current_db_version ) {
		return;
	}

	global $wpdb;
	$charset_collate = $wpdb->get_charset_collate();
	$table_name      = extrachill_analytics_revenue_table();

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	// One row per (blog_id, slug, period_start, period_end, import_batch). The
	// unique key makes a re-import of the same export idempotent (REPLACE INTO
	// updates in place instead of stacking duplicate snapshots).
	$sql = "CREATE TABLE {$table_name} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		blog_id int(11) NOT NULL DEFAULT 1,
		slug varchar(400) NOT NULL DEFAULT '',
		url varchar(2083) NOT NULL DEFAULT '',
		post_id bigint(20) unsigned DEFAULT NULL,
		views bigint(20) unsigned NOT NULL DEFAULT 0,
		revenue decimal(12,4) NOT NULL DEFAULT 0,
		rpm decimal(10,4) NOT NULL DEFAULT 0,
		cpm decimal(10,4) NOT NULL DEFAULT 0,
		viewability decimal(7,4) NOT NULL DEFAULT 0,
		fill_rate decimal(7,4) NOT NULL DEFAULT 0,
		impressions_per_pageview decimal(10,4) NOT NULL DEFAULT 0,
		period_start date DEFAULT NULL,
		period_end date DEFAULT NULL,
		import_batch varchar(64) NOT NULL DEFAULT '',
		imported_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
		PRIMARY KEY  (id),
		UNIQUE KEY snapshot (blog_id, slug(191), period_start, period_end, import_batch),
		KEY slug_idx (slug(191)),
		KEY post_id_idx (post_id),
		KEY blog_id_idx (blog_id),
		KEY period_idx (period_start, period_end),
		KEY import_batch_idx (import_batch)
	) {$charset_collate};";

	dbDelta( $sql );

	update_site_option( EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION_OPTION, EXTRACHILL_ANALYTICS_REVENUE_DB_VERSION );
}

add_action( 'admin_init', 'extrachill_analytics_revenue_create_table' );

/**
 * Get the Mediavine revenue table name.
 *
 * @return string Table name with base prefix.
 */
function extrachill_analytics_revenue_table() {
	global $wpdb;
	return $wpdb->base_prefix . 'extrachill_analytics_mediavine_revenue';
}

/**
 * Insert (or replace) a single revenue snapshot row.
 *
 * REPLACE-on-conflict against the unique (blog_id, slug, period, batch) key, so
 * re-importing the same export is idempotent rather than additive.
 *
 * @param array $row {
 *     Snapshot data.
 *
 *     @type int    $blog_id                  Blog the page belongs to.
 *     @type string $slug                     Normalized slug (path), e.g. "some-post".
 *     @type string $url                      Original URL/path from the CSV.
 *     @type int    $post_id                  Resolved post ID, or 0/null.
 *     @type int    $views                    Pageviews.
 *     @type float  $revenue                  Ad revenue in dollars.
 *     @type float  $rpm                      Revenue per mille (per 1k views).
 *     @type float  $cpm                      Cost per mille.
 *     @type float  $viewability              Viewability ratio/percent.
 *     @type float  $fill_rate                Fill rate ratio/percent.
 *     @type float  $impressions_per_pageview Impressions per pageview.
 *     @type string $period_start             Window start (Y-m-d) or ''.
 *     @type string $period_end               Window end (Y-m-d) or ''.
 *     @type string $import_batch             Import batch identifier.
 * }
 * @return int|false Inserted row ID, or false on failure.
 */
function extrachill_analytics_revenue_upsert( array $row ) {
	global $wpdb;

	$table = extrachill_analytics_revenue_table();

	$data = array(
		'blog_id'                  => isset( $row['blog_id'] ) ? (int) $row['blog_id'] : get_current_blog_id(),
		'slug'                     => isset( $row['slug'] ) ? (string) $row['slug'] : '',
		'url'                      => isset( $row['url'] ) ? (string) $row['url'] : '',
		'post_id'                  => ! empty( $row['post_id'] ) ? (int) $row['post_id'] : null,
		'views'                    => isset( $row['views'] ) ? max( 0, (int) $row['views'] ) : 0,
		'revenue'                  => isset( $row['revenue'] ) ? (float) $row['revenue'] : 0.0,
		'rpm'                      => isset( $row['rpm'] ) ? (float) $row['rpm'] : 0.0,
		'cpm'                      => isset( $row['cpm'] ) ? (float) $row['cpm'] : 0.0,
		'viewability'              => isset( $row['viewability'] ) ? (float) $row['viewability'] : 0.0,
		'fill_rate'                => isset( $row['fill_rate'] ) ? (float) $row['fill_rate'] : 0.0,
		'impressions_per_pageview' => isset( $row['impressions_per_pageview'] ) ? (float) $row['impressions_per_pageview'] : 0.0,
		'period_start'             => ! empty( $row['period_start'] ) ? $row['period_start'] : null,
		'period_end'               => ! empty( $row['period_end'] ) ? $row['period_end'] : null,
		'import_batch'             => isset( $row['import_batch'] ) ? (string) $row['import_batch'] : '',
		'imported_at'              => current_time( 'mysql', true ),
	);

	$formats = array( '%d', '%s', '%s', '%d', '%d', '%f', '%f', '%f', '%f', '%f', '%f', '%s', '%s', '%s', '%s' );

	// REPLACE INTO honors the UNIQUE KEY: same snapshot overwrites in place.
	$columns      = implode( ', ', array_keys( $data ) );
	$placeholders = implode( ', ', $formats );
	$sql          = "REPLACE INTO {$table} ({$columns}) VALUES ({$placeholders})";

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- columns/placeholders are static; values prepared below.
	$prepared = $wpdb->prepare( $sql, array_values( $data ) );
	$result   = $wpdb->query( $prepared ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	return false === $result ? false : (int) $wpdb->insert_id;
}

/**
 * Fetch revenue snapshot rows for a blog, optionally scoped to a window.
 *
 * When both $period_start and $period_end are supplied, only snapshots whose
 * recorded window matches (or falls within) the requested window are returned —
 * this is what separates a "recent" lens from the all-time lens. When the window
 * is omitted, every snapshot for the blog is returned (lifetime view): callers
 * that mix multiple imports should pass an import_batch to avoid double-counting.
 *
 * @param array $args {
 *     Query args.
 *
 *     @type int    $blog_id      Blog ID (default: current blog).
 *     @type string $import_batch Restrict to one import batch (default: '' = any).
 *     @type string $period_start Inclusive window start (Y-m-d), or '' for any.
 *     @type string $period_end   Inclusive window end (Y-m-d), or '' for any.
 * }
 * @return array<int, object> Snapshot rows.
 */
function extrachill_analytics_revenue_get_rows( array $args = array() ) {
	global $wpdb;

	$table        = extrachill_analytics_revenue_table();
	$blog_id      = isset( $args['blog_id'] ) ? (int) $args['blog_id'] : get_current_blog_id();
	$import_batch = isset( $args['import_batch'] ) ? (string) $args['import_batch'] : '';
	$period_start = isset( $args['period_start'] ) ? (string) $args['period_start'] : '';
	$period_end   = isset( $args['period_end'] ) ? (string) $args['period_end'] : '';

	$where  = array( 'blog_id = %d' );
	$values = array( $blog_id );

	if ( '' !== $import_batch ) {
		$where[]  = 'import_batch = %s';
		$values[] = $import_batch;
	}

	if ( '' !== $period_start && '' !== $period_end ) {
		// Snapshot window must sit inside the requested window.
		$where[]  = 'period_start >= %s';
		$values[] = $period_start;
		$where[]  = 'period_end <= %s';
		$values[] = $period_end;
	}

	$where_clause = implode( ' AND ', $where );
	$sql          = "SELECT * FROM {$table} WHERE {$where_clause}";

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return (array) $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
}

/**
 * List distinct import batches for a blog, newest first.
 *
 * @param int $blog_id Blog ID (default: current blog).
 * @return array<int, object> Rows with import_batch, period_start, period_end, rows, imported_at.
 */
function extrachill_analytics_revenue_list_batches( $blog_id = 0 ) {
	global $wpdb;

	$table   = extrachill_analytics_revenue_table();
	$blog_id = $blog_id > 0 ? (int) $blog_id : get_current_blog_id();

	$sql = "SELECT import_batch, period_start, period_end, COUNT(*) AS rows_count, MAX(imported_at) AS imported_at,
		SUM(revenue) AS revenue, SUM(views) AS views
		FROM {$table} WHERE blog_id = %d
		GROUP BY import_batch, period_start, period_end
		ORDER BY imported_at DESC";

	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return (array) $wpdb->get_results( $wpdb->prepare( $sql, $blog_id ) );
}
