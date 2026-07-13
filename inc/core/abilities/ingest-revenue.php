<?php
/**
 * Ingest Revenue Ability
 *
 * The first-class, idempotent mutation ability that owns Mediavine (and any
 * future per-URL ad-revenue) ingestion into the revenue store.
 *
 * WHY THIS EXISTS (issue #140 / extrachill-cli#92):
 * The CLI `revenue fetch` path used to serialize source rows to a RANDOM temp
 * CSV and call the importer without an explicit batch. The importer derived the
 * batch from that random filename, so re-fetching one period created a SECOND
 * snapshot for the same period_label. Because the revenue ARC and rollups SUM
 * every batch by default, a repeat operational fetch silently doubled that
 * month. This ability replaces that flow with a deterministic snapshot identity
 * keyed on STABLE inputs (source + source-site + blog + period) and explicit
 * replace/additive semantics, so identical inputs always land on the same
 * snapshot and leave totals unchanged.
 *
 * STORAGE OWNERSHIP:
 * Analytics owns persistence, period/snapshot identity, replacement policy,
 * resolution, and mutation validation. It reuses the existing writer
 * (`extrachill_analytics_revenue_upsert()`) and the shared slug resolver
 * (`extrachill_analytics_revenue_resolve_slug()`) — there is still exactly one
 * writer and one resolution path. The ability is agnostic about the Data
 * Machine Business source ability: callers hand it already-normalized revenue
 * rows plus source provenance.
 *
 * IDENTITY CONTRACT:
 * The default snapshot identity is deterministic. Running the same
 * (source, source_site, blog_id, period) twice MUST resolve to the same
 * `import_batch`, so the store's UNIQUE (blog_id, slug, period_label,
 * import_batch) key upserts the same rows in place instead of adding a parallel
 * snapshot. The identity is NEVER derived from a temp filename.
 *
 * REPLACEMENT vs ADDITIVE:
 * - `replace` (default): upsert the incoming rows into the deterministic
 *   snapshot AND remove ("replace") any row that belonged to that snapshot but
 *   disappeared from the refreshed source. Scoped to exactly one snapshot, in a
 *   transaction where supported. This is what a recurring fetch wants.
 * - `additive`: NEVER deletes, and REQUIRES an explicit operator-supplied
 *   `snapshot` identity (a distinct parallel batch). This is the only way to
 *   land a second snapshot for the same period on purpose. Additive behavior
 *   cannot happen by accident — the default is always replace.
 *
 * @package ExtraChill\Analytics
 * @since 0.28.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Compute a deterministic snapshot identity from stable inputs.
 *
 * Pure (no WordPress I/O): `extrachill_analytics_revenue_resolve_period()` is
 * pure and `sanitize_key()` is pure. The same inputs always produce the same
 * `import_batch`, which is the whole point — re-running identical inputs lands
 * on the same snapshot and the unique key upserts in place.
 *
 * @param array $args {
 *     Identity inputs.
 *
 *     @type int    $blog_id      Blog the rows belong to. 0 = current (resolved later).
 *     @type string $source       Source provenance slug, e.g. "mediavine". Default "mediavine".
 *     @type string $source_site  Source site / host the rows were pulled for. Falls back to hostname.
 *     @type string $hostname     Hostname fallback for source_site. Default "extrachill".
 *     @type string $period       Period token (YYYY-MM, YYYY, or '' for the flat lifetime file).
 *     @type string $period_start Explicit window start (Y-m-d) override.
 *     @type string $period_end   Explicit window end (Y-m-d) override.
 * }
 * @return array{import_batch:string,period_label:string,period_start:string,period_end:string,source:string,source_site:string,blog_id:int}
 */
function extrachill_analytics_revenue_snapshot_identity( array $args ) {
	$source = isset( $args['source'] ) && '' !== (string) $args['source']
		? sanitize_key( (string) $args['source'] )
		: 'mediavine';

	$source_site = isset( $args['source_site'] ) && '' !== (string) $args['source_site']
		? sanitize_key( (string) $args['source_site'] )
		: sanitize_key( isset( $args['hostname'] ) && '' !== (string) $args['hostname'] ? (string) $args['hostname'] : 'extrachill' );

	$blog_id = isset( $args['blog_id'] ) ? max( 0, (int) $args['blog_id'] ) : 0;

	$resolved = extrachill_analytics_revenue_resolve_period(
		isset( $args['period'] ) ? (string) $args['period'] : '',
		isset( $args['period_start'] ) ? (string) $args['period_start'] : '',
		isset( $args['period_end'] ) ? (string) $args['period_end'] : ''
	);

	// Deterministic, filename-free identity. Every part comes from stable
	// operator/source inputs, never a temp path, so identical runs collide on
	// purpose and the store's unique key dedupes instead of duplicating.
	$parts = array( $source, $source_site );
	if ( $blog_id > 0 ) {
		$parts[] = 'b' . $blog_id;
	}
	$parts[]      = sanitize_key( $resolved['label'] );
	$import_batch = trim( implode( '-', $parts ), '-' );

	return array(
		'import_batch' => $import_batch,
		'period_label' => $resolved['label'],
		'period_start' => $resolved['start'],
		'period_end'   => $resolved['end'],
		'source'       => $source,
		'source_site'  => $source_site,
		'blog_id'      => $blog_id,
	);
}

/**
 * Build a deterministic ingestion plan from incoming records and the snapshot's
 * existing rows.
 *
 * Pure (no I/O). This is the decision core that the engine unit-tests directly:
 * it classifies each incoming slug as an insert (new to the snapshot) or a
 * replace (already present), and — for replace mode — identifies the stale rows
 * that disappeared from the refreshed source and must be removed. The engine
 * then applies this plan through the store; the plan itself owns every count
 * the ability reports, so the load-bearing logic is fully testable without a
 * database.
 *
 * @param array              $records Normalized incoming records, each with at least a `slug`.
 * @param array<int, object> $existing Existing rows of the target snapshot (objects with `id`, `slug`).
 * @param string             $mode     'replace' or 'additive'.
 * @return array{inserts:array,replaces:array,deletes:array} Plan.
 */
function extrachill_analytics_revenue_build_ingestion_plan( array $records, array $existing, $mode ) {
	$existing_by_slug = array();
	foreach ( $existing as $row ) {
		$slug = is_object( $row ) && isset( $row->slug ) ? (string) $row->slug : (string) ( isset( $row['slug'] ) ? $row['slug'] : '' );
		if ( '' !== $slug ) {
			$id                        = is_object( $row ) && isset( $row->id ) ? (int) $row->id : (int) ( isset( $row['id'] ) ? $row['id'] : 0 );
			$existing_by_slug[ $slug ] = $id;
		}
	}

	$inserts  = array();
	$replaces = array();
	$seen_new = array();

	foreach ( $records as $rec ) {
		$slug = isset( $rec['slug'] ) ? (string) $rec['slug'] : '';
		if ( '' === $slug ) {
			continue;
		}
		if ( isset( $existing_by_slug[ $slug ] ) ) {
			$replaces[] = $rec;
		} else {
			$inserts[] = $rec;
		}
		$seen_new[ $slug ] = true;
	}

	$deletes = array();
	if ( 'replace' === $mode ) {
		foreach ( $existing_by_slug as $slug => $id ) {
			if ( ! isset( $seen_new[ $slug ] ) ) {
				$deletes[] = $id;
			}
		}
	}

	return array(
		'inserts'  => $inserts,
		'replaces' => $replaces,
		'deletes'  => $deletes,
	);
}

/**
 * Ingest a set of normalized revenue rows into the store with deterministic
 * snapshot identity and explicit replace/additive semantics.
 *
 * The engine resolves + normalizes each row (reusing the shared slug resolver),
 * computes a pure plan against the snapshot's existing rows, then applies it
 * through the supplied `$store` in a transaction (where supported). Dry runs
 * compute identical counts and write nothing.
 *
 * Resolution (url_to_postid) runs against the current blog; when importing for a
 * different blog the engine switches first so the stamped blog_id and resolved
 * post_id agree — mirroring the CSV importer.
 *
 * @param array                                   $input_rows Normalized revenue rows. Each row may carry a pre-resolved
 *                                                   `post_id`; otherwise the slug is resolved. Metric keys mirror
 *                                                   the store: views, revenue, rpm, cpm, viewability, fill_rate,
 *                                                   impressions_per_pageview. `slug`/`url` carry the path.
 * @param array                                   $args {
 *     Ingestion options.
 *
 *     @type int                 $blog_id      Target blog. 0 = current blog.
 *     @type string              $hostname     Hostname for slug->post resolution. Default extrachill.com.
 *     @type string              $source       Source provenance slug. Default mediavine.
 *     @type string              $source_site  Source site identifier. Default derived from hostname.
 *     @type string              $period       Period token (YYYY-MM / YYYY / '').
 *     @type string              $period_start Explicit window start override.
 *     @type string              $period_end   Explicit window end override.
 *     @type string              $mode         'replace' (default) or 'additive'.
 *     @type string              $snapshot     Explicit snapshot identity for additive mode (required when mode=additive).
 *     @type bool                $dry_run      Parse + plan but write nothing.
 * }
 * @param Extrachill_Analytics_Revenue_Store|null $store Storage handle. Null builds the production store.
 * @return array Ingestion result (see output_schema) or { success:false, error }.
 */
function extrachill_analytics_revenue_ingest_rows( array $input_rows, array $args, $store = null ) {
	$mode = isset( $args['mode'] ) ? (string) $args['mode'] : 'replace';
	if ( ! in_array( $mode, array( 'replace', 'additive' ), true ) ) {
		return array(
			'success' => false,
			'error'   => 'mode must be "replace" or "additive".',
		);
	}

	// Additive is the explicit opt-in for a parallel snapshot; it MUST NOT
	// delete, and it requires an operator-supplied snapshot identity so a second
	// snapshot for the same period is intentional, not accidental.
	$snapshot_override = isset( $args['snapshot'] ) ? trim( (string) $args['snapshot'] ) : '';
	if ( 'additive' === $mode && '' === $snapshot_override ) {
		return array(
			'success' => false,
			'error'   => 'additive mode requires an explicit snapshot identity (a distinct batch label). Use replace for the default deterministic snapshot.',
		);
	}

	$blog_id  = isset( $args['blog_id'] ) && (int) $args['blog_id'] > 0 ? (int) $args['blog_id'] : get_current_blog_id();
	$hostname = ! empty( $args['hostname'] ) ? (string) $args['hostname'] : 'extrachill.com';
	$dry_run  = ! empty( $args['dry_run'] );

	$identity            = extrachill_analytics_revenue_snapshot_identity(
		array(
			'blog_id'      => $blog_id,
			'source'       => isset( $args['source'] ) ? (string) $args['source'] : 'mediavine',
			'source_site'  => isset( $args['source_site'] ) ? (string) $args['source_site'] : $hostname,
			'hostname'     => $hostname,
			'period'       => isset( $args['period'] ) ? (string) $args['period'] : '',
			'period_start' => isset( $args['period_start'] ) ? (string) $args['period_start'] : '',
			'period_end'   => isset( $args['period_end'] ) ? (string) $args['period_end'] : '',
		)
	);
	$identity['blog_id'] = $blog_id;

	// An additive snapshot is named by the operator; the period still resolves
	// deterministically from the period token.
	if ( 'additive' === $mode && '' !== $snapshot_override ) {
		$identity['import_batch'] = $snapshot_override;
	}

	$period_label = $identity['period_label'];
	$period_start = $identity['period_start'];
	$period_end   = $identity['period_end'];
	$batch        = $identity['import_batch'];

	// Resolve + normalize each incoming row against the target blog. Resolution
	// (url_to_postid) is current-blog-scoped, so switch for the duration to keep
	// the stamped blog_id and resolved post_id in agreement on a multisite.
	$switched = false;
	if ( $blog_id > 0 && function_exists( 'switch_to_blog' ) && function_exists( 'get_current_blog_id' ) && get_current_blog_id() !== $blog_id ) {
		switch_to_blog( $blog_id );
		$switched = true;
	}

	$records    = array();
	$resolved   = 0;
	$unresolved = 0;

	foreach ( $input_rows as $input ) {
		$raw_slug = isset( $input['slug'] ) ? (string) $input['slug'] : ( isset( $input['url'] ) ? (string) $input['url'] : '' );
		if ( '' === trim( $raw_slug ) ) {
			continue;
		}

		if ( isset( $input['post_id'] ) && (int) $input['post_id'] > 0 ) {
			$post_id = (int) $input['post_id'];
			if ( function_exists( 'get_post' ) ) {
				$post = get_post( $post_id );
				$slug = ( is_object( $post ) && isset( $post->post_name ) ) ? $post->post_name : trim( (string) strtok( $raw_slug, '?#' ), '/' );
			} else {
				$slug = trim( (string) strtok( $raw_slug, '?#' ), '/' );
			}
		} else {
			$resolved_row = extrachill_analytics_revenue_resolve_slug( $raw_slug, $hostname );
			$post_id      = $resolved_row['post_id'];
			$slug         = $resolved_row['slug'];
		}

		if ( $post_id > 0 ) {
			++$resolved;
		} else {
			++$unresolved;
		}

		$records[] = array(
			'blog_id'                  => $blog_id,
			'slug'                     => $slug,
			'url'                      => $raw_slug,
			'post_id'                  => $post_id,
			'views'                    => isset( $input['views'] ) ? (int) $input['views'] : 0,
			'revenue'                  => isset( $input['revenue'] ) ? (float) $input['revenue'] : 0.0,
			'rpm'                      => isset( $input['rpm'] ) ? (float) $input['rpm'] : 0.0,
			'cpm'                      => isset( $input['cpm'] ) ? (float) $input['cpm'] : 0.0,
			'viewability'              => isset( $input['viewability'] ) ? (float) $input['viewability'] : 0.0,
			'fill_rate'                => isset( $input['fill_rate'] ) ? (float) $input['fill_rate'] : 0.0,
			'impressions_per_pageview' => isset( $input['impressions_per_pageview'] ) ? (float) $input['impressions_per_pageview'] : 0.0,
			'period_label'             => $period_label,
			'period_start'             => $period_start,
			'period_end'               => $period_end,
			'import_batch'             => $batch,
		);
	}

	if ( $switched && function_exists( 'restore_current_blog' ) ) {
		restore_current_blog();
	}

	$rows_count = count( $records );

	// Acquire the production store when the caller (production callback) did not
	// inject one. Tests inject an in-memory fake to exercise the full path.
	if ( null === $store ) {
		if ( ! class_exists( 'Extrachill_Analytics_Revenue_Store' ) ) {
			return array(
				'success' => false,
				'error'   => 'Revenue store is not available.',
			);
		}
		$store = new Extrachill_Analytics_Revenue_Store();
	}

	$existing = $store->get_snapshot( $blog_id, $period_label, $batch );
	$plan     = extrachill_analytics_revenue_build_ingestion_plan( $records, $existing, $mode );

	$inserts  = count( $plan['inserts'] );
	$replaces = count( $plan['replaces'] );
	$deletes  = count( $plan['deletes'] );

	$result = array(
		'success'    => true,
		'mode'       => $mode,
		'dry_run'    => $dry_run,
		'written'    => ! $dry_run,
		'rows'       => $rows_count,
		'resolved'   => $resolved,
		'unresolved' => $unresolved,
		'inserted'   => $inserts,
		'replaced'   => $replaces,
		'deleted'    => $dry_run ? 0 : $deletes,
		'identity'   => array(
			'import_batch' => $batch,
			'period_label' => $period_label,
			'period_start' => $period_start,
			'period_end'   => $period_end,
			'source'       => $identity['source'],
			'source_site'  => $identity['source_site'],
			'blog_id'      => $blog_id,
		),
	);

	// Dry run reports the full plan (including deletes that WOULD happen) but
	// mutates nothing.
	if ( $dry_run ) {
		$result['would_delete'] = $deletes;
		$result['deleted']      = 0;
		return $result;
	}

	// Nothing to write: no incoming slugs and nothing to remove is a clean
	// idempotent no-op (e.g. an empty refreshed source clearing a snapshot only
	// happens when existing rows exist — handled by the deletes branch below).
	if ( 0 === $inserts && 0 === $replaces && 0 === $deletes ) {
		return $result;
	}

	// Apply the plan atomically. On any write failure, roll back so a partially
	// replaced snapshot never replaces a good one.
	$txn = $store->begin();

	$write_ok = true;
	$error    = '';

	foreach ( $plan['inserts'] as $rec ) {
		if ( false === $store->upsert( $rec ) ) {
			$write_ok = false;
			$error    = 'Failed to insert a revenue row.';
			break;
		}
	}

	if ( $write_ok ) {
		foreach ( $plan['replaces'] as $rec ) {
			if ( false === $store->upsert( $rec ) ) {
				$write_ok = false;
				$error    = 'Failed to replace a revenue row.';
				break;
			}
		}
	}

	if ( $write_ok && ! empty( $plan['deletes'] ) ) {
		if ( false === $store->delete_ids( $plan['deletes'] ) ) {
			$write_ok = false;
			$error    = 'Failed to remove stale revenue rows.';
		}
	}

	if ( ! $write_ok ) {
		if ( $txn ) {
			$store->rollback();
		}
		$result['success'] = false;
		$result['written'] = false;
		unset( $result['mode'], $result['dry_run'] );
		$result['mode']    = $mode;
		$result['dry_run'] = false;
		$result['error']   = $error;
		return $result;
	}

	if ( $txn ) {
		$store->commit();
	}

	return $result;
}

/**
 * Register the ingest-revenue ability.
 */
function extrachill_analytics_register_ingest_revenue_ability() {
	if ( ! function_exists( 'wp_register_ability' ) ) {
		return;
	}

	wp_register_ability(
		'extrachill/ingest-revenue',
		array(
			'label'               => __( 'Ingest Revenue Snapshot', 'extrachill-analytics' ),
			'description'         => __( 'Idempotently ingest a set of per-URL ad-revenue rows (e.g. a Mediavine period export) into the revenue store with a deterministic snapshot identity. Default mode is deterministic REPLACE: the same source/site/blog/period always lands on the SAME snapshot, updating rows in place and removing any that disappeared from the refreshed source, so re-running identical inputs never double-counts the ARC or rollups. ADDITIVE mode never deletes and requires an explicit operator-supplied snapshot identity to create a distinct parallel snapshot on purpose. Accepts normalized revenue rows plus source provenance; agnostic about the upstream source ability. Supports dry-run. Owned by analytics: validation, period/snapshot identity, replacement, resolution, and persistence all live here.', 'extrachill-analytics' ),
			'category'            => 'extrachill-analytics',
			'input_schema'        => array(
				'type'       => 'object',
				'properties' => array(
					'rows'         => array(
						'type'        => 'array',
						'description' => __( 'Normalized per-URL revenue rows. Each row carries a slug/url plus metrics (views, revenue, rpm, cpm, viewability, fill_rate, impressions_per_pageview) and an optional pre-resolved post_id.', 'extrachill-analytics' ),
						'default'     => array(),
					),
					'blog_id'      => array(
						'type'        => 'integer',
						'description' => __( 'Blog the rows belong to. 0 = current blog.', 'extrachill-analytics' ),
						'default'     => 0,
					),
					'hostname'     => array(
						'type'        => 'string',
						'description' => __( 'Hostname for slug->post resolution (default extrachill.com).', 'extrachill-analytics' ),
						'default'     => 'extrachill.com',
					),
					'source'       => array(
						'type'        => 'string',
						'description' => __( 'Source provenance slug, e.g. "mediavine". Part of the deterministic snapshot identity. Default mediavine.', 'extrachill-analytics' ),
						'default'     => 'mediavine',
					),
					'source_site'  => array(
						'type'        => 'string',
						'description' => __( 'Source site / host the rows were pulled for. Part of the snapshot identity so the same period for different sites never collides. Defaults to the hostname.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'period'       => array(
						'type'        => 'string',
						'description' => __( 'Period token these rows belong to: YYYY-MM (a monthly export), YYYY (a year), or "" for the flat lifetime file. Part of the snapshot identity and the canonical period_label the ARC groups by.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'period_start' => array(
						'type'        => 'string',
						'description' => __( 'Explicit window start (Y-m-d) override. Defaults to the start derived from period.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'period_end'   => array(
						'type'        => 'string',
						'description' => __( 'Explicit window end (Y-m-d) override. Defaults to the end derived from period.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'mode'         => array(
						'type'        => 'string',
						'description' => __( '"replace" (default) updates the deterministic snapshot in place and removes stale rows; "additive" never deletes and requires an explicit snapshot identity to create a parallel snapshot. Additive behavior cannot happen by accident.', 'extrachill-analytics' ),
						'default'     => 'replace',
					),
					'snapshot'     => array(
						'type'        => 'string',
						'description' => __( 'Explicit snapshot identity (batch label). REQUIRED for additive mode (the operator names the parallel snapshot). Ignored for replace, which always uses the deterministic identity.', 'extrachill-analytics' ),
						'default'     => '',
					),
					'dry_run'      => array(
						'type'        => 'boolean',
						'description' => __( 'If true, resolve + plan but write nothing. Reported counts reflect what would happen.', 'extrachill-analytics' ),
						'default'     => false,
					),
				),
			),
			'output_schema'       => array(
				'type'        => 'object',
				'description' => __( 'Result with success, written, mode, dry_run, counts (rows, resolved, unresolved, inserted, replaced, deleted), and the stable identity (import_batch, period_label, period_start/end, source, source_site, blog_id).', 'extrachill-analytics' ),
			),
			'execute_callback'    => 'extrachill_analytics_ability_ingest_revenue',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' ) || ( defined( 'WP_CLI' ) && WP_CLI );
			},
			'meta'                => array(
				'show_in_rest' => false,
				'annotations'  => array(
					'readonly'    => false,
					'idempotent'  => true,
					'destructive' => true,
				),
			),
		)
	);
}

/**
 * Execute callback for the ingest-revenue ability.
 *
 * @param array $input Input parameters.
 * @return array Ingestion result.
 */
function extrachill_analytics_ability_ingest_revenue( $input ) {
	$rows = isset( $input['rows'] ) && is_array( $input['rows'] ) ? $input['rows'] : array();

	return extrachill_analytics_revenue_ingest_rows(
		$rows,
		array(
			'blog_id'      => isset( $input['blog_id'] ) ? (int) $input['blog_id'] : 0,
			'hostname'     => isset( $input['hostname'] ) ? (string) $input['hostname'] : 'extrachill.com',
			'source'       => isset( $input['source'] ) ? (string) $input['source'] : 'mediavine',
			'source_site'  => isset( $input['source_site'] ) ? (string) $input['source_site'] : '',
			'period'       => isset( $input['period'] ) ? (string) $input['period'] : '',
			'period_start' => isset( $input['period_start'] ) ? (string) $input['period_start'] : '',
			'period_end'   => isset( $input['period_end'] ) ? (string) $input['period_end'] : '',
			'mode'         => isset( $input['mode'] ) ? (string) $input['mode'] : 'replace',
			'snapshot'     => isset( $input['snapshot'] ) ? (string) $input['snapshot'] : '',
			'dry_run'      => ! empty( $input['dry_run'] ),
		)
	);
}
