<?php
/**
 * Analytics-owned participant for Link Page storage migration.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verify one owned table has the required columns and indexes.
 *
 * @param string $table Table name.
 * @param array  $columns Required columns.
 * @param array  $indexes Required indexes.
 */
function extrachill_analytics_link_page_migration_table_ready( $table, $columns, $indexes ) {
	global $wpdb;
	$column_rows    = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact owned schema preflight.
	$actual_columns = array();
	foreach ( $column_rows ? $column_rows : array() as $row ) {
		$actual_columns[ $row['Field'] ] = array(
			'type'            => strtolower( $row['Type'] ),
			'null'            => $row['Null'],
			'default_is_null' => null === $row['Default'],
			'default'         => $row['Default'],
			'extra'           => strtolower( $row['Extra'] ),
		);
	}
	if ( $actual_columns !== $columns ) {
		return new WP_Error( 'analytics_link_page_migration_schema_mismatch', 'An Analytics destination table does not match its owned column contract.', array( 'table' => $table ) );
	}
	$index_rows = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact schema preflight.
	$actual     = array();
	foreach ( $index_rows ? $index_rows : array() as $row ) {
		$actual[ $row['Key_name'] ]['unique']                                = '0' === (string) $row['Non_unique'];
		$actual[ $row['Key_name'] ]['columns'][ (int) $row['Seq_in_index'] ] = array( $row['Column_name'], null === $row['Sub_part'] ? null : (int) $row['Sub_part'] );
	}
	foreach ( $actual as &$index ) {
		ksort( $index['columns'], SORT_NUMERIC );
		$index['columns'] = array_values( $index['columns'] );
	}
	unset( $index );
	$actual_names   = array_keys( $actual );
	$expected_names = array_keys( $indexes );
	sort( $actual_names, SORT_STRING );
	sort( $expected_names, SORT_STRING );
	if ( $actual_names !== $expected_names ) {
		return new WP_Error( 'analytics_link_page_migration_schema_mismatch', 'An Analytics destination table has an unexpected index contract.', array( 'table' => $table ) );
	}
	foreach ( $indexes as $name => $index_contract ) {
		if ( ! isset( $actual[ $name ] ) || $actual[ $name ] !== $index_contract ) {
			return new WP_Error(
				'analytics_link_page_migration_schema_mismatch',
				'An Analytics destination table does not match its owned index contract.',
				array(
					'table' => $table,
					'index' => $name,
				)
			);
		}
	}
	return true;
}

/**
 * Read exact owned rows for a Link Page ID set.
 *
 * @param array $link_page_ids Link Page IDs.
 */
function extrachill_analytics_link_page_migration_rows( $link_page_ids ) {
	global $wpdb;
	$ids = implode( ',', array_map( 'absint', $link_page_ids ) );
	if ( '' === $ids ) {
		return array(
			'views'  => array(),
			'clicks' => array(),
		); }
	$views = $wpdb->get_results( 'SELECT view_id, link_page_id, stat_date, view_count FROM `' . extrachill_analytics_link_page_views_table() . "` WHERE link_page_id IN ({$ids}) ORDER BY view_id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locally cast integer list and exact migration snapshot.
	if ( '' !== $wpdb->last_error ) {
		return new WP_Error( 'analytics_link_page_migration_source_read_failed', $wpdb->last_error ); }
	$clicks = $wpdb->get_results( 'SELECT click_id, link_page_id, stat_date, link_url, link_text, click_count FROM `' . extrachill_analytics_link_page_clicks_table() . "` WHERE link_page_id IN ({$ids}) ORDER BY click_id", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locally cast integer list and exact migration snapshot.
	if ( '' !== $wpdb->last_error ) {
		return new WP_Error( 'analytics_link_page_migration_source_read_failed', $wpdb->last_error ); }
	return array(
		'views'  => array_values( $views ? $views : array() ),
		'clicks' => array_values( $clicks ? $clicks : array() ),
	);
}

/**
 * Read exact rows from a specified site and restore nested context.
 *
 * @param int   $blog_id Site ID.
 * @param array $link_page_ids Link Page IDs.
 */
function extrachill_analytics_link_page_migration_rows_for_blog( $blog_id, $link_page_ids ) {
	$switched = get_current_blog_id() !== (int) $blog_id;
	if ( $switched && ! switch_to_blog( (int) $blog_id ) ) {
		return new WP_Error( 'analytics_link_page_migration_context_failed', 'An Analytics migration context is unavailable.' ); }
	try {
		return extrachill_analytics_link_page_migration_rows( $link_page_ids ); } finally {
		if ( $switched ) {
			restore_current_blog(); }
		}
}

/** Return the exact daily-view column contract. */
function extrachill_analytics_link_page_migration_view_columns() {
	return array(
		'view_id'      => array(
			'type'            => 'bigint(20) unsigned',
			'null'            => 'NO',
			'default_is_null' => true,
			'default'         => null,
			'extra'           => 'auto_increment',
		),
		'link_page_id' => array(
			'type'            => 'bigint(20) unsigned',
			'null'            => 'NO',
			'default_is_null' => true,
			'default'         => null,
			'extra'           => '',
		),
		'stat_date'    => array(
			'type'            => 'date',
			'null'            => 'NO',
			'default_is_null' => true,
			'default'         => null,
			'extra'           => '',
		),
		'view_count'   => array(
			'type'            => 'bigint(20) unsigned',
			'null'            => 'NO',
			'default_is_null' => false,
			'default'         => '0',
			'extra'           => '',
		),
	); }
/** Return the exact click column contract. */
function extrachill_analytics_link_page_migration_click_columns() {
	return array(
		'click_id'     => array(
			'type'            => 'bigint(20) unsigned',
			'null'            => 'NO',
			'default_is_null' => true,
			'default'         => null,
			'extra'           => 'auto_increment',
		),
		'link_page_id' => array(
			'type'            => 'bigint(20) unsigned',
			'null'            => 'NO',
			'default_is_null' => true,
			'default'         => null,
			'extra'           => '',
		),
		'stat_date'    => array(
			'type'            => 'date',
			'null'            => 'NO',
			'default_is_null' => true,
			'default'         => null,
			'extra'           => '',
		),
		'link_url'     => array(
			'type'            => 'varchar(2083)',
			'null'            => 'NO',
			'default_is_null' => true,
			'default'         => null,
			'extra'           => '',
		),
		'link_text'    => array(
			'type'            => 'varchar(255)',
			'null'            => 'NO',
			'default_is_null' => false,
			'default'         => '',
			'extra'           => '',
		),
		'click_count'  => array(
			'type'            => 'bigint(20) unsigned',
			'null'            => 'NO',
			'default_is_null' => false,
			'default'         => '0',
			'extra'           => '',
		),
	); }
/** Return the exact daily-view index contract. */
function extrachill_analytics_link_page_migration_view_indexes() {
	return array(
		'PRIMARY'           => array(
			'unique'  => true,
			'columns' => array( array( 'view_id', null ) ),
		),
		'unique_daily_view' => array(
			'unique'  => true,
			'columns' => array( array( 'link_page_id', null ), array( 'stat_date', null ) ),
		),
	); }
/** Return the exact click index contract. */
function extrachill_analytics_link_page_migration_click_indexes() {
	return array(
		'PRIMARY'                 => array(
			'unique'  => true,
			'columns' => array( array( 'click_id', null ) ),
		),
		'unique_daily_link_click' => array(
			'unique'  => true,
			'columns' => array( array( 'link_page_id', null ), array( 'stat_date', null ), array( 'link_url', 191 ), array( 'link_text', 100 ) ),
		),
		'link_page_date'          => array(
			'unique'  => false,
			'columns' => array( array( 'link_page_id', null ), array( 'stat_date', null ) ),
		),
	); }
/** Validate both owned tables in the current site context. */
function extrachill_analytics_link_page_migration_schema_ready() {
	$ready = extrachill_analytics_link_page_migration_table_ready( extrachill_analytics_link_page_views_table(), extrachill_analytics_link_page_migration_view_columns(), extrachill_analytics_link_page_migration_view_indexes() );
	return is_wp_error( $ready ) ? $ready : extrachill_analytics_link_page_migration_table_ready( extrachill_analytics_link_page_clicks_table(), extrachill_analytics_link_page_migration_click_columns(), extrachill_analytics_link_page_migration_click_indexes() );
}
/**
 * Validate both owned tables in one blog context.
 *
 * @param int $blog_id Blog ID.
 */
function extrachill_analytics_link_page_migration_schema_for_blog( $blog_id ) {
	$switched = get_current_blog_id() !== (int) $blog_id;
	if ( $switched && ! switch_to_blog( (int) $blog_id ) ) {
		return new WP_Error( 'analytics_link_page_migration_context_failed', 'The Analytics schema context is unavailable.' ); }
	try {
		return extrachill_analytics_link_page_migration_schema_ready(); } finally {
		if ( $switched ) {
			restore_current_blog(); }
		}
}

/**
 * Build the read-only Analytics participant plan.
 *
 * @param array $context Migration context.
 */
function extrachill_analytics_link_page_migration_plan( $context ) {
	$source = extrachill_analytics_link_page_migration_rows( $context['link_page_ids'] );
	if ( is_wp_error( $source ) ) {
		return $source; }
	$source_blog_id = get_current_blog_id();
	$source_schema  = extrachill_analytics_link_page_migration_schema_ready();
	if ( is_wp_error( $source_schema ) ) {
		return $source_schema; }
	if ( 'readiness' !== ( $context['mode'] ?? '' ) ) {
		return extrachill_analytics_link_page_migration_plan_result( $source, $source_blog_id, $context );
	}
	if ( ! switch_to_blog( (int) $context['destination_blog_id'] ) ) {
		return new WP_Error( 'analytics_link_page_migration_context_failed', 'The Analytics destination context is unavailable.' ); }
	try {
		$views_table  = extrachill_analytics_link_page_views_table();
		$clicks_table = extrachill_analytics_link_page_clicks_table();
		$ready        = extrachill_analytics_link_page_migration_table_ready(
			$views_table,
			extrachill_analytics_link_page_migration_view_columns(),
			array(
				'PRIMARY'           => array(
					'unique'  => true,
					'columns' => array( array( 'view_id', null ) ),
				),
				'unique_daily_view' => array(
					'unique'  => true,
					'columns' => array( array( 'link_page_id', null ), array( 'stat_date', null ) ),
				),
			)
		);
		if ( is_wp_error( $ready ) ) {
			return $ready; }
		$ready = extrachill_analytics_link_page_migration_table_ready(
			$clicks_table,
			extrachill_analytics_link_page_migration_click_columns(),
			array(
				'PRIMARY'                 => array(
					'unique'  => true,
					'columns' => array( array( 'click_id', null ) ),
				),
				'unique_daily_link_click' => array(
					'unique'  => true,
					'columns' => array( array( 'link_page_id', null ), array( 'stat_date', null ), array( 'link_url', 191 ), array( 'link_text', 100 ) ),
				),
				'link_page_date'          => array(
					'unique'  => false,
					'columns' => array( array( 'link_page_id', null ), array( 'stat_date', null ) ),
				),
			)
		);
		if ( is_wp_error( $ready ) ) {
			return $ready; }
		global $wpdb;
		foreach ( $source['views'] as $row ) {
			$collision = $wpdb->get_var( $wpdb->prepare( "SELECT view_id FROM `{$views_table}` WHERE view_id = %d OR (link_page_id = %d AND stat_date = %s) LIMIT 1", $row['view_id'], $row['link_page_id'], $row['stat_date'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Owned table preflight.
			if ( $collision ) {
				return new WP_Error( 'analytics_link_page_migration_collision', 'A destination daily-view primary or unique key is occupied.', array( 'view_id' => $row['view_id'] ) ); }
		}
		foreach ( $source['clicks'] as $row ) {
			$collision = $wpdb->get_var( $wpdb->prepare( "SELECT click_id FROM `{$clicks_table}` WHERE click_id = %d OR (link_page_id = %d AND stat_date = %s AND LEFT(link_url,191) = LEFT(%s,191) AND LEFT(link_text,100) = LEFT(%s,100)) LIMIT 1", $row['click_id'], $row['link_page_id'], $row['stat_date'], $row['link_url'], $row['link_text'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reproduces owned prefix unique index under destination collation.
			if ( $collision ) {
				return new WP_Error( 'analytics_link_page_migration_collision', 'A destination click primary or unique key is occupied.', array( 'click_id' => $row['click_id'] ) ); }
		}
	} finally {
		restore_current_blog(); }
	return extrachill_analytics_link_page_migration_plan_result( $source, $source_blog_id, $context );
}

/**
 * Build deterministic rows and exact historical event identities.
 *
 * @param array $source Source aggregate rows.
 * @param int   $source_blog_id Source blog ID.
 * @param array $context Migration context.
 */
function extrachill_analytics_link_page_migration_plan_result( $source, $source_blog_id, $context ) {
	global $wpdb;
	$historical = array();
	if ( function_exists( 'extrachill_analytics_events_table' ) ) {
		$ids  = implode( ',', array_map( 'absint', $context['link_page_ids'] ) );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, event_type, blog_id, source_url, event_data FROM `' . extrachill_analytics_events_table() . "` WHERE blog_id = %d AND JSON_VALID(event_data) AND CAST(JSON_UNQUOTE(JSON_EXTRACT(event_data, '$.post_id')) AS UNSIGNED) IN ({$ids}) ORDER BY id", $source_blog_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact JSON identity report.
		if ( '' !== $wpdb->last_error ) {
			return new WP_Error( 'analytics_link_page_migration_historical_read_failed', $wpdb->last_error ); }
		foreach ( $rows ? $rows : array() as $row ) {
			$decoded      = json_decode( $row['event_data'], true );
			$historical[] = array(
				'id'              => (int) $row['id'],
				'event_type'      => $row['event_type'],
				'matched_post_id' => (int) $decoded['post_id'],
				'blog_id'         => (int) $row['blog_id'],
				'source_url'      => $row['source_url'],
			); }
	}
	$data                = array(
		'rows'                      => $source,
		'historical_network_events' => $historical,
		'attachment_ids'            => array(),
	);
	$data['fingerprint'] = hash( 'sha256', wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	return $data;
}

/**
 * Analytics does not claim canonical owners.
 *
 * @param array $context Owner context.
 */
function extrachill_analytics_link_page_migration_claim_owner( $context ) {
	unset( $context );
	return false; }

/**
 * Insert every exact row through the owner journal callback.
 *
 * @param array $context Migration context.
 */
function extrachill_analytics_link_page_migration_apply( $context ) {
	$plan = extrachill_analytics_link_page_migration_rows_for_blog( $context['source_blog_id'], $context['link_page_ids'] );
	if ( is_wp_error( $plan ) ) {
		return $plan; }
	$expected = $context['participant_plans']['analytics']['fingerprint'] ?? '';
	$actual   = hash(
		'sha256',
		wp_json_encode(
			array(
				'rows'                      => $plan,
				'historical_network_events' => $context['participant_plans']['analytics']['historical_network_events'] ?? array(),
				'attachment_ids'            => array(),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		)
	);
	if ( ! $expected || ! hash_equals( $expected, $actual ) ) {
		return new WP_Error( 'analytics_link_page_migration_source_drift', 'Analytics rows changed after planning.' ); }
	if ( ! switch_to_blog( (int) $context['destination_blog_id'] ) ) {
		return new WP_Error( 'analytics_link_page_migration_context_failed', 'The Analytics destination context is unavailable.' ); }
	try {
		global $wpdb;
		foreach ( array( 'views', 'clicks' ) as $kind ) {
			$table = 'views' === $kind ? extrachill_analytics_link_page_views_table() : extrachill_analytics_link_page_clicks_table();
			$key   = 'views' === $kind ? 'view_id' : 'click_id';
			foreach ( $plan[ $kind ] as $row ) {
				$result = call_user_func(
					$context['journal_record'],
					array(
						'participant' => 'analytics',
						'operation'   => 'insert',
						'table_kind'  => $kind,
						'row_id'      => (int) $row[ $key ],
						'row'         => $row,
					),
					static function () use ( $wpdb, $table, $row ) {
						return 1 === $wpdb->insert( $table, $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Participant owns exact journaled inserts.
					}
				);
				if ( is_wp_error( $result ) ) {
					return $result; }
			}
		}
		return true;
	} finally {
		restore_current_blog(); }
}

/**
 * Compare exact source and destination rows.
 *
 * @param array $context Migration context.
 */
function extrachill_analytics_link_page_migration_validate( $context ) {
	$source_schema = extrachill_analytics_link_page_migration_schema_for_blog( $context['source_blog_id'] );
	if ( is_wp_error( $source_schema ) ) {
		return $source_schema; }
	$source = extrachill_analytics_link_page_migration_rows_for_blog( $context['source_blog_id'], $context['link_page_ids'] );
	if ( is_wp_error( $source ) ) {
		return $source; }
	if ( ! switch_to_blog( (int) $context['destination_blog_id'] ) ) {
		return new WP_Error( 'analytics_link_page_migration_context_failed', 'The Analytics destination context is unavailable.' ); }
	try {
		$ready = extrachill_analytics_link_page_migration_schema_ready();
		if ( is_wp_error( $ready ) ) {
			return $ready; }
		$destination = extrachill_analytics_link_page_migration_rows( $context['link_page_ids'] );
		if ( is_wp_error( $destination ) ) {
			return $destination; }
	} finally {
		restore_current_blog(); }
	return $source === $destination ? true : new WP_Error( 'analytics_link_page_migration_validation_failed', 'Destination Analytics rows differ from source rows.' );
}

/**
 * Delete only exact participant insert entries recorded by this journal.
 *
 * @param array $context Migration context.
 */
function extrachill_analytics_link_page_migration_rollback( $context ) {
	if ( ! switch_to_blog( (int) $context['destination_blog_id'] ) ) {
		return new WP_Error( 'analytics_link_page_migration_context_failed', 'The Analytics destination context is unavailable.' ); }
	try {
		global $wpdb;
		foreach ( array_reverse( $context['journal_entries'] ?? array() ) as $entry ) {
			if ( 'participant' !== ( $entry['type'] ?? '' ) || 'analytics' !== ( $entry['participant'] ?? '' ) || empty( $entry['applied'] ) || ! empty( $entry['rolled_back'] ) ) {
				continue; }
			$table   = 'views' === $entry['table_kind'] ? extrachill_analytics_link_page_views_table() : extrachill_analytics_link_page_clicks_table();
			$key     = 'views' === $entry['table_kind'] ? 'view_id' : 'click_id';
			$current = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE {$key} = %d", $entry['row_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact journal ownership proof.
			if ( '' !== $wpdb->last_error ) {
				return new WP_Error( 'analytics_link_page_migration_rollback_read_failed', $wpdb->last_error ); }
			if ( ! $current ) {
				continue; }
			if ( $current !== $entry['row'] ) {
				return new WP_Error( 'analytics_link_page_migration_rollback_mismatch', 'An Analytics row no longer matches its journal snapshot.', array( 'row_id' => $entry['row_id'] ) ); }
			if ( false === $wpdb->delete( $table, array( $key => (int) $entry['row_id'] ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rollback deletes only exact journal-owned rows.
				return new WP_Error( 'analytics_link_page_migration_rollback_failed', 'A journal-owned Analytics row could not be removed.' ); }
		}
		return true;
	} finally {
		restore_current_blog(); }
}

/** Register after all network plugins have loaded their public contracts. */
function extrachill_analytics_register_link_page_migration_participant() {
	if ( ! function_exists( 'ec_register_link_page_migration_participant' ) ) {
		return; }
	ec_register_link_page_migration_participant(
		'analytics',
		'1',
		array(
			'claim_owner' => 'extrachill_analytics_link_page_migration_claim_owner',
			'plan'        => 'extrachill_analytics_link_page_migration_plan',
			'apply'       => 'extrachill_analytics_link_page_migration_apply',
			'validate'    => 'extrachill_analytics_link_page_migration_validate',
			'rollback'    => 'extrachill_analytics_link_page_migration_rollback',
		),
		20
	);
}

add_action( 'plugins_loaded', 'extrachill_analytics_register_link_page_migration_participant', 30 );
