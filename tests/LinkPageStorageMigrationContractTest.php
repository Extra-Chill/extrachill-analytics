<?php
/**
 * Executable database behavior for the Link Page Analytics migration.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

// PHPUnit requires the *Test.php filename; the private wpdb fixture stays beside its only consumer.
// phpcs:disable WordPress.Files.FileName,Universal.Files.SeparateFunctionsFromOO,Generic.Files.OneObjectStructurePerFile,Squiz.Commenting.FunctionComment.MissingParamTag,Generic.Commenting.DocComment.MissingShort,Generic.CodeAnalysis.UnusedFunctionParameter

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

if ( ! function_exists( 'ec_register_link_page_migration_participant' ) ) {
	/** Capture the sibling runtime registration contract. */
	function ec_register_link_page_migration_participant( $name, $contract_version, $callbacks, $priority = 10 ) {
		$GLOBALS['extrachill_analytics_test_migration_participant'] = compact( 'name', 'contract_version', 'callbacks', 'priority' );
		return true;
	}
}

require_once dirname( __DIR__ ) . '/inc/database/link-page-analytics-db.php';
require_once dirname( __DIR__ ) . '/inc/core/link-page-storage-migration.php';

/** Minimal stateful wpdb double that executes the migration's owned SQL semantics. */
final class LinkPageMigrationWpdbFixture {
	/** @var string */
	public $last_error = '';

	/** @var array<string,array<int,array<string,string>>> */
	public $rows = array();

	/** @var array<int,array<string,mixed>> */
	public $events = array();

	/** @var array<string,string> */
	public $errors = array();

	/** @var array<int,array{query:string,args:array}> */
	public $prepared = array();

	/** @var string[] */
	public $operations = array();

	/** @var bool */
	public $return_invalid_historical_json = false;

	/** Resolve the active per-site prefix. */
	public function __get( $name ) {
		if ( 'prefix' === $name ) {
			return 'wp_' . get_current_blog_id() . '_';
		}
		return null;
	}

	/** Store a prepared statement and its typed arguments. */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$id                    = count( $this->prepared );
		$this->prepared[ $id ] = array(
			'query' => $query,
			'args'  => array_values( $args ),
		);
		return "/*ec-prepared:{$id}*/{$query}";
	}

	/** Return exact schema or table rows. */
	public function get_results( $sql, $output = ARRAY_A ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		list( $query, $args ) = $this->statement( $sql );
		$this->last_error     = '';
		if ( preg_match( '/^SHOW COLUMNS FROM `([^`]+)`/', $query, $match ) ) {
			if ( $this->fail( 'schema_columns:' . $match[1] ) ) {
				return false;
			}
			return $this->column_rows( $match[1] );
		}
		if ( preg_match( '/^SHOW INDEX FROM `([^`]+)`/', $query, $match ) ) {
			if ( $this->fail( 'schema_indexes:' . $match[1] ) ) {
				return false;
			}
			return $this->index_rows( $match[1] );
		}
		if ( false !== strpos( $query, 'extrch_link_page_daily_views' ) ) {
			if ( $this->fail( 'source_views:' . get_current_blog_id() ) ) {
				return false;
			}
			return $this->selected_rows( $query, 'views' );
		}
		if ( false !== strpos( $query, 'extrch_link_page_daily_link_clicks' ) ) {
			if ( $this->fail( 'source_clicks:' . get_current_blog_id() ) ) {
				return false;
			}
			return $this->selected_rows( $query, 'clicks' );
		}
		if ( false !== strpos( $query, 'wp_extrachill_analytics_events' ) ) {
			if ( $this->fail( 'historical' ) ) {
				return false;
			}
			if ( $this->return_invalid_historical_json ) {
				return array(
					array(
						'blog_id'    => (string) $args[0],
						'source_url' => 'https://artist.extrachill.com/bad',
						'event_data' => '{bad',
					),
				);
			}
			return $this->historical_rows( $query, (int) $args[0] );
		}
		return array();
	}

	/** Execute collision and rollback existence reads. */
	public function get_var( $sql ) {
		list( $query, $args ) = $this->statement( $sql );
		$this->last_error     = '';
		if ( false !== strpos( $query, 'WHERE view_id = %d OR' ) ) {
			if ( $this->fail( 'collision_views' ) ) {
				return null;
			}
			foreach ( $this->rows[ $this->table( 'views' ) ] ?? array() as $row ) {
				if ( (int) $row['view_id'] === (int) $args[0] || ( (int) $row['link_page_id'] === (int) $args[1] && $row['stat_date'] === $args[2] ) ) {
					return $row['view_id'];
				}
			}
			return null;
		}
		if ( false !== strpos( $query, 'WHERE click_id = %d OR' ) ) {
			if ( $this->fail( 'collision_clicks' ) ) {
				return null;
			}
			foreach ( $this->rows[ $this->table( 'clicks' ) ] ?? array() as $row ) {
				$primary = (int) $row['click_id'] === (int) $args[0];
				$unique  = (int) $row['link_page_id'] === (int) $args[1]
					&& $row['stat_date'] === $args[2]
					&& $this->collation_prefix( $row['link_url'], 191 ) === $this->collation_prefix( $args[3], 191 )
					&& $this->collation_prefix( $row['link_text'], 100 ) === $this->collation_prefix( $args[4], 100 );
				if ( $primary || $unique ) {
					return $row['click_id'];
				}
			}
			return null;
		}
		if ( preg_match( '/SELECT (view_id|click_id) FROM `([^`]+)` WHERE \1 = %d LIMIT 1/', $query, $match ) ) {
			if ( $this->fail( 'rollback_read' ) ) {
				return null;
			}
			foreach ( $this->rows[ $match[2] ] ?? array() as $row ) {
				if ( (int) $row[ $match[1] ] === (int) $args[0] ) {
					return $row[ $match[1] ];
				}
			}
		}
		return null;
	}

	/** Insert one row while enforcing the owned primary and unique keys. */
	public function insert( $table, $row ) {
		$this->last_error = '';
		$kind             = false !== strpos( $table, 'daily_views' ) ? 'views' : 'clicks';
		$key              = 'views' === $kind ? 'view_id' : 'click_id';
		foreach ( $this->rows[ $table ] ?? array() as $current ) {
			$duplicate = (int) $current[ $key ] === (int) $row[ $key ];
			if ( 'views' === $kind ) {
				$duplicate = $duplicate || ( (int) $current['link_page_id'] === (int) $row['link_page_id'] && $current['stat_date'] === $row['stat_date'] );
			} else {
				$duplicate = $duplicate || ( (int) $current['link_page_id'] === (int) $row['link_page_id'] && $current['stat_date'] === $row['stat_date'] && $this->collation_prefix( $current['link_url'], 191 ) === $this->collation_prefix( $row['link_url'], 191 ) && $this->collation_prefix( $current['link_text'], 100 ) === $this->collation_prefix( $row['link_text'], 100 ) );
			}
			if ( $duplicate ) {
				$this->last_error = 'Duplicate entry';
				return false;
			}
		}
		$this->rows[ $table ][] = $row;
		$this->operations[]     = 'insert:' . $table;
		return 1;
	}

	/** Execute an exact conditional DELETE. */
	public function query( $sql ) {
		list( $query, $args ) = $this->statement( $sql );
		$this->last_error     = '';
		if ( $this->fail( 'rollback_delete' ) ) {
			return false;
		}
		if ( ! preg_match( '/^DELETE FROM `([^`]+)`/', $query, $match ) ) {
			return false;
		}
		$table              = $match[1];
		$kind               = false !== strpos( $table, 'daily_views' ) ? 'views' : 'clicks';
		$columns            = 'views' === $kind ? array( 'view_id', 'link_page_id', 'stat_date', 'view_count' ) : array( 'click_id', 'link_page_id', 'stat_date', 'link_url', 'link_text', 'click_count' );
		$this->operations[] = 'delete:' . $table;
		foreach ( $this->rows[ $table ] ?? array() as $index => $row ) {
			$matches = true;
			foreach ( $columns as $position => $column ) {
				if ( in_array( $column, array( 'view_id', 'click_id', 'link_page_id', 'view_count', 'click_count' ), true ) ) {
					$matches = $matches && (int) $row[ $column ] === (int) $args[ $position ];
				} else {
					$matches = $matches && $row[ $column ] === $args[ $position ];
				}
			}
			if ( $matches ) {
				array_splice( $this->rows[ $table ], $index, 1 );
				return 1;
			}
		}
		return 0;
	}

	/** Resolve a test table name. */
	public function table( $kind, $blog_id = null ) {
		$blog_id = null === $blog_id ? get_current_blog_id() : $blog_id;
		return 'wp_' . $blog_id . '_extrch_link_page_daily_' . ( 'views' === $kind ? 'views' : 'link_clicks' );
	}

	/** Expand a stored prepared statement. */
	private function statement( $sql ) {
		if ( preg_match( '#^/\*ec-prepared:(\d+)\*/#', $sql, $match ) ) {
			return array_values( $this->prepared[ (int) $match[1] ] );
		}
		return array( $sql, array() );
	}

	/** Set a configured SQL error. */
	private function fail( $key ) {
		if ( ! isset( $this->errors[ $key ] ) ) {
			return false;
		}
		$this->last_error = $this->errors[ $key ];
		return true;
	}

	/** Return SHOW COLUMNS rows for the production-owned schema. */
	private function column_rows( $table ) {
		$contracts = false !== strpos( $table, 'daily_views' ) ? extrachill_analytics_link_page_migration_view_columns() : extrachill_analytics_link_page_migration_click_columns();
		$rows      = array();
		foreach ( $contracts as $field => $contract ) {
			$rows[] = array(
				'Field'   => $field,
				'Type'    => $contract['type'],
				'Null'    => $contract['null'],
				'Default' => $contract['default'],
				'Extra'   => $contract['extra'],
			);
		}
		if ( isset( $this->errors[ 'schema_default:' . $table ] ) ) {
			$rows[ count( $rows ) - 1 ]['Default'] = null;
		}
		return $rows;
	}

	/** Return SHOW INDEX rows for the production-owned schema. */
	private function index_rows( $table ) {
		$contracts = false !== strpos( $table, 'daily_views' ) ? extrachill_analytics_link_page_migration_view_indexes() : extrachill_analytics_link_page_migration_click_indexes();
		$rows      = array();
		foreach ( $contracts as $name => $contract ) {
			foreach ( $contract['columns'] as $position => $column ) {
				$rows[] = array(
					'Key_name'     => $name,
					'Non_unique'   => $contract['unique'] ? '0' : '1',
					'Seq_in_index' => $position + 1,
					'Column_name'  => $column[0],
					'Sub_part'     => $column[1],
				);
			}
		}
		return $rows;
	}

	/** Select migration rows for the requested ID list. */
	private function selected_rows( $query, $kind ) {
		preg_match( '/link_page_id IN \(([^)]+)\)/', $query, $match );
		$ids  = array_map( 'intval', explode( ',', $match[1] ?? '' ) );
		$rows = array_values(
			array_filter(
				$this->rows[ $this->table( $kind ) ] ?? array(),
				static function ( $row ) use ( $ids ) {
					return in_array( (int) $row['link_page_id'], $ids, true );
				}
			)
		);
		$key  = 'views' === $kind ? 'view_id' : 'click_id';
		usort(
			$rows,
			static function ( $left, $right ) use ( $key ) {
				return (int) $left[ $key ] <=> (int) $right[ $key ];
			}
		);
		return $rows;
	}

	/** Execute strict JSON integer matching, including JSON_VALID behavior. */
	private function historical_rows( $query, $blog_id ) {
		preg_match( '/ IN \(([^)]+)\)/', $query, $match );
		$ids    = array_map( 'intval', explode( ',', $match[1] ?? '' ) );
		$strict = false !== strpos( $query, "JSON_TYPE(JSON_EXTRACT(event_data, '$.post_id')) = 'INTEGER'" );
		$result = array();
		foreach ( $this->events as $event ) {
			$decoded = json_decode( $event['event_data'], true );
			if ( JSON_ERROR_NONE !== json_last_error() || (int) $event['blog_id'] !== $blog_id || ! is_array( $decoded ) || ! array_key_exists( 'post_id', $decoded ) ) {
				continue;
			}
			$post_id = $decoded['post_id'];
			$matches = $strict ? is_int( $post_id ) && in_array( $post_id, $ids, true ) : in_array( (int) $post_id, $ids, true );
			if ( $matches ) {
				$result[] = array_intersect_key( $event, array_flip( array( 'blog_id', 'source_url', 'event_data' ) ) );
			}
		}
		return $result;
	}

	/** Reproduce a case-insensitive indexed-prefix collation. */
	private function collation_prefix( $value, $length ) {
		return strtolower( substr( $value, 0, $length ) );
	}
}

/** Verify the participant against executable database behavior. */
final class LinkPageStorageMigrationContractTest extends TestCase {
	/** @var LinkPageMigrationWpdbFixture */
	private $database;

	/** Reset two isolated multisite table sets. */
	protected function setUp(): void {
		$this->database                                  = new LinkPageMigrationWpdbFixture();
		$GLOBALS['wpdb']                                 = $this->database;
		$GLOBALS['extrachill_analytics_test_blog_id']    = 4;
		$GLOBALS['extrachill_analytics_test_blog_stack'] = array();
		$GLOBALS['extrachill_analytics_test_migration_participant'] = null;
		foreach ( array( 4, 13 ) as $blog_id ) {
			$this->database->rows[ $this->database->table( 'views', $blog_id ) ]  = array();
			$this->database->rows[ $this->database->table( 'clicks', $blog_id ) ] = array();
		}
	}

	/** Schema SQL errors and default-value distinctions fail closed on either site. */
	public function test_source_and_destination_schema_failures_are_blockers(): void {
		$this->database->errors[ 'schema_columns:' . $this->database->table( 'views', 4 ) ] = 'source schema failed';
		$result = extrachill_analytics_link_page_migration_plan( $this->context( 'readiness' ) );
		$this->assertSame( 'analytics_link_page_migration_schema_read_failed', $result->code );

		$this->database->errors = array( 'schema_default:' . $this->database->table( 'clicks', 13 ) => 'different default' );
		$result                 = extrachill_analytics_link_page_migration_plan( $this->context( 'readiness' ) );
		$this->assertSame( 'analytics_link_page_migration_schema_mismatch', $result->code );

		$this->database->errors = array( 'schema_indexes:' . $this->database->table( 'views', 13 ) => 'destination schema failed' );
		$result                 = extrachill_analytics_link_page_migration_plan( $this->context( 'readiness' ) );
		$this->assertSame( 'analytics_link_page_migration_schema_read_failed', $result->code );
	}

	/** Source and destination collision reads fail closed on SQL errors. */
	public function test_source_and_collision_query_errors_fail_closed(): void {
		$this->database->errors['source_views:4'] = 'source read failed';
		$result                                   = extrachill_analytics_link_page_migration_plan( $this->context( 'readiness' ) );
		$this->assertSame( 'analytics_link_page_migration_source_read_failed', $result->code );

		$this->database->errors = array();
		$this->add_source_view();
		$this->database->errors['collision_views'] = 'collision read failed';
		$result                                    = extrachill_analytics_link_page_migration_plan( $this->context( 'readiness' ) );
		$this->assertSame( 'analytics_link_page_migration_collision_read_failed', $result->code );

		$this->database->rows[ $this->database->table( 'views', 4 ) ] = array();
		$this->database->errors                                       = array( 'collision_clicks' => 'click collision read failed' );
		$this->add_source_click( 'https://example.com', 'Example' );
		$result = extrachill_analytics_link_page_migration_plan( $this->context( 'readiness' ) );
		$this->assertSame( 'analytics_link_page_migration_collision_read_failed', $result->code );
	}

	/** Primary and indexed-prefix collisions use destination collation semantics. */
	public function test_primary_and_prefix_unique_collisions_block_planning(): void {
		$this->add_source_view();
		$this->database->rows[ $this->database->table( 'views', 13 ) ][] = array(
			'view_id'      => '7',
			'link_page_id' => '99',
			'stat_date'    => '2026-08-25',
			'view_count'   => '1',
		);
		$result = extrachill_analytics_link_page_migration_plan( $this->context( 'readiness' ) );
		$this->assertSame( 'analytics_link_page_migration_collision', $result->code );

		$this->database->rows[ $this->database->table( 'views', 4 ) ]  = array();
		$this->database->rows[ $this->database->table( 'views', 13 ) ] = array();
		$url = str_repeat( 'A', 191 );
		$this->add_source_click( $url . '/source', 'BUY TICKETS' );
		$this->database->rows[ $this->database->table( 'clicks', 13 ) ][] = array(
			'click_id'     => '88',
			'link_page_id' => '42',
			'stat_date'    => '2026-08-25',
			'link_url'     => strtolower( $url ) . '/other',
			'link_text'    => 'buy tickets',
			'click_count'  => '1',
		);
		$result = extrachill_analytics_link_page_migration_plan( $this->context( 'readiness' ) );
		$this->assertSame( 'analytics_link_page_migration_collision', $result->code );
	}

	/** Historical identity accepts only exact JSON integers and returns a deduped projection. */
	public function test_historical_json_identity_is_strict_and_deduped(): void {
		foreach ( array( 42, 42, '42', true, 42.0, '42suffix', 420 ) as $index => $post_id ) {
			$this->database->events[] = array(
				'blog_id'    => '4',
				'source_url' => 1 === $index ? 'https://artist.extrachill.com/link' : 'https://artist.extrachill.com/link',
				'event_data' => wp_json_encode( array( 'post_id' => $post_id ) ),
			);
		}
		$this->database->events[] = array(
			'blog_id'    => '4',
			'source_url' => 'bad',
			'event_data' => '{bad',
		);
		$result                   = extrachill_analytics_link_page_migration_plan_result(
			array(
				'views'  => array(),
				'clicks' => array(),
			),
			4,
			$this->context( 'source_inventory' )
		);
		$this->assertSame(
			array(
				array(
					'matched_post_id' => 42,
					'blog_id'         => 4,
					'source_url'      => 'https://artist.extrachill.com/link',
				),
			),
			$result['historical_network_events']
		);

		$this->database->errors['historical'] = 'historical query failed';
		$result                               = extrachill_analytics_link_page_migration_plan_result(
			array(
				'views'  => array(),
				'clicks' => array(),
			),
			4,
			$this->context( 'source_inventory' )
		);
		$this->assertSame( 'analytics_link_page_migration_historical_read_failed', $result->code );

		$this->database->errors                         = array();
		$this->database->return_invalid_historical_json = true;
		$result = extrachill_analytics_link_page_migration_plan_result(
			array(
				'views'  => array(),
				'clicks' => array(),
			),
			4,
			$this->context( 'source_inventory' )
		);
		$this->assertSame( 'analytics_link_page_migration_historical_json_failed', $result->code );
	}

	/** Interrupted intents, exact snapshots, absence, and mismatches have safe rollback semantics. */
	public function test_conditional_rollback_handles_interruption_absence_and_mismatch(): void {
		$row = $this->click_row();
		$this->database->rows[ $this->database->table( 'clicks', 13 ) ][] = $row;
		$entry = $this->journal_entry( 'clicks', $row, false );
		$this->assertTrue( extrachill_analytics_link_page_migration_rollback( $this->rollback_context( array( $entry ) ) ) );
		$this->assertSame( array(), $this->database->rows[ $this->database->table( 'clicks', 13 ) ] );
		$this->assertStringStartsWith( 'delete:', $this->database->operations[0] );

		$this->database->operations = array();
		$this->assertTrue( extrachill_analytics_link_page_migration_rollback( $this->rollback_context( array( $entry ) ) ) );
		$this->assertSame( array( 'delete:' . $this->database->table( 'clicks', 13 ) ), $this->database->operations );

		$changed              = $row;
		$changed['link_text'] = 'Case Changed';
		$this->database->rows[ $this->database->table( 'clicks', 13 ) ][] = $changed;
		$result = extrachill_analytics_link_page_migration_rollback( $this->rollback_context( array( $entry ) ) );
		$this->assertSame( 'analytics_link_page_migration_rollback_mismatch', $result->code );
		$this->assertSame( array( $changed ), $this->database->rows[ $this->database->table( 'clicks', 13 ) ] );
	}

	/** Apply leaves source immutable, validation succeeds, and source inventory stays stable. */
	public function test_apply_validate_inventory_and_context_are_stable(): void {
		$this->add_source_view();
		$this->add_source_click( 'https://example.com/tickets', 'Tickets' );
		$source_before                = $this->database->rows;
		$plan                         = extrachill_analytics_link_page_migration_plan( $this->context( 'source_inventory' ) );
		$entries                      = array();
		$context                      = $this->context( 'apply' );
		$context['source_blog_id']    = 4;
		$context['participant_plans'] = array( 'analytics' => $plan );
		$context['journal_record']    = static function ( $entry, $callback ) use ( &$entries ) {
			$result           = call_user_func( $callback );
			$entry['applied'] = true;
			$entries[]        = $entry;
			return $result;
		};

		$GLOBALS['extrachill_analytics_test_blog_id']    = 9;
		$GLOBALS['extrachill_analytics_test_blog_stack'] = array( 2 );
		$this->assertTrue( extrachill_analytics_link_page_migration_apply( $context ) );
		$this->assertSame( 9, get_current_blog_id() );
		$this->assertSame( array( 2 ), $GLOBALS['extrachill_analytics_test_blog_stack'] );
		$this->assertSame( $source_before[ $this->database->table( 'views', 4 ) ], $this->database->rows[ $this->database->table( 'views', 4 ) ] );
		$this->assertSame( $source_before[ $this->database->table( 'clicks', 4 ) ], $this->database->rows[ $this->database->table( 'clicks', 4 ) ] );
		$this->assertCount( 2, $entries );
		$this->assertTrue( extrachill_analytics_link_page_migration_validate( $context ) );
		$this->assertSame( 9, get_current_blog_id() );
		$this->assertSame( array( 2 ), $GLOBALS['extrachill_analytics_test_blog_stack'] );

		$GLOBALS['extrachill_analytics_test_blog_id'] = 4;
		$after                                        = extrachill_analytics_link_page_migration_plan( $this->context( 'source_inventory' ) );
		$this->assertSame( $plan, $after );
	}

	/** Registration exposes every latest sibling callback at contract version 1. */
	public function test_participant_registration_version_is_enforced(): void {
		extrachill_analytics_register_link_page_migration_participant();
		$participant = $GLOBALS['extrachill_analytics_test_migration_participant'];
		$this->assertSame( 'analytics', $participant['name'] );
		$this->assertSame( '1', $participant['contract_version'] );
		$this->assertSame( array( 'claim_owner', 'plan', 'apply', 'validate', 'rollback' ), array_keys( $participant['callbacks'] ) );
		foreach ( $participant['callbacks'] as $callback ) {
			$this->assertTrue( is_callable( $callback ) );
		}
		$this->assertFalse( extrachill_analytics_link_page_migration_claim_owner( array() ) );
	}

	/** Build a participant context. */
	private function context( $mode ) {
		return array(
			'mode'                => $mode,
			'source_blog_id'      => 4,
			'destination_blog_id' => 13,
			'link_page_ids'       => array( 42 ),
		);
	}

	/** Build rollback context. */
	private function rollback_context( $entries ) {
		return array(
			'destination_blog_id' => 13,
			'journal_entries'     => $entries,
		);
	}

	/** Add one source view row. */
	private function add_source_view() {
		$this->database->rows[ $this->database->table( 'views', 4 ) ][] = array(
			'view_id'      => '7',
			'link_page_id' => '42',
			'stat_date'    => '2026-08-25',
			'view_count'   => '12',
		);
	}

	/** Add one source click row. */
	private function add_source_click( $url, $text ) {
		$row              = $this->click_row();
		$row['link_url']  = $url;
		$row['link_text'] = $text;
		$this->database->rows[ $this->database->table( 'clicks', 4 ) ][] = $row;
	}

	/** Return one exact click snapshot. */
	private function click_row() {
		return array(
			'click_id'     => '9',
			'link_page_id' => '42',
			'stat_date'    => '2026-08-25',
			'link_url'     => 'https://example.com/Tickets?ref=A&B=1',
			'link_text'    => 'Tickets 100%',
			'click_count'  => '3',
		);
	}

	/** Return one participant insert entry. */
	private function journal_entry( $kind, $row, $applied ) {
		$key = 'views' === $kind ? 'view_id' : 'click_id';
		return array(
			'type'        => 'participant',
			'participant' => 'analytics',
			'operation'   => 'insert',
			'table_kind'  => $kind,
			'row_id'      => (int) $row[ $key ],
			'row'         => $row,
			'applied'     => $applied,
		);
	}
}
