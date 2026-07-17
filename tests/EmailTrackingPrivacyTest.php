<?php
/**
 * Tests for privacy-safe email outcome tracking.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/class-email-privacy-wpdb-fixture.php';
require_once dirname( __DIR__ ) . '/inc/core/email-tracking.php';

/**
 * Verify bounded payload helpers and privacy contracts.
 */
final class EmailTrackingPrivacyTest extends TestCase {
	/**
	 * WPDB value restored after each test.
	 *
	 * @var mixed
	 */
	private $original_wpdb;

	/**
	 * Reset network, cron, user, and database fixtures.
	 */
	protected function setUp(): void {
		global $wpdb;

		$this->original_wpdb = $wpdb ?? null;
		$wpdb                = new Email_Privacy_Wpdb_Fixture();

		$GLOBALS['extrachill_analytics_test_is_main_site']    = true;
		$GLOBALS['extrachill_analytics_test_scheduled']       = array();
		$GLOBALS['extrachill_analytics_test_recurring']       = array();
		$GLOBALS['extrachill_analytics_test_single']          = array();
		$GLOBALS['extrachill_analytics_test_site_options']    = array();
		$GLOBALS['extrachill_analytics_test_site_transients'] = array();
		$GLOBALS['extrachill_analytics_test_transient_ttls']  = array();
		$GLOBALS['extrachill_analytics_test_user']            = (object) array( 'ID' => 42 );
		$GLOBALS['extrachill_analytics_test_uuid4']           = array( 'worker-token' );
		$GLOBALS['extrachill_analytics_test_cache_deletes']   = array();
	}

	/**
	 * Restore the prior wpdb value.
	 */
	protected function tearDown(): void {
		global $wpdb;

		$wpdb = $this->original_wpdb;
	}

	/**
	 * Recipient addresses become only a bounded count.
	 */
	public function test_recipient_count_does_not_retain_addresses(): void {
		$this->assertSame( 2, extrachill_analytics_email_recipient_count( 'one@example.com, two@example.com' ) );
		$this->assertSame( 2, extrachill_analytics_email_recipient_count( array( 'one@example.com', 'two@example.com' ) ) );
		$this->assertSame( 100, extrachill_analytics_email_recipient_count( array_fill( 0, 150, 'person@example.com' ) ) );
	}

	/**
	 * Operational context is restricted to a short identifier.
	 */
	public function test_context_is_bounded_and_normalized(): void {
		$this->assertSame( 'theme:extrachill', extrachill_analytics_normalize_email_context( 'Theme:Extra Chill' ) );
		$this->assertSame( 64, strlen( extrachill_analytics_normalize_email_context( str_repeat( 'a', 100 ) ) ) );
	}

	/**
	 * Source contract excludes the prior direct-PII payload fields.
	 */
	public function test_tracking_payload_excludes_direct_pii(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/email-tracking.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.

		$this->assertStringNotContainsString( "'subject' =>", $source );
		$this->assertStringNotContainsString( "'to'      =>", $source );
		$this->assertStringNotContainsString( 'get_error_message()', $source );
		$this->assertStringContainsString( "'recipient_count'", $source );
		$this->assertStringContainsString( "'error_code'", $source );
	}

	/**
	 * Retention and Core privacy hooks remain registered.
	 */
	public function test_retention_and_privacy_contracts_are_registered(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/email-tracking.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.

		$this->assertStringContainsString( 'EXTRACHILL_ANALYTICS_EMAIL_EVENT_RETENTION_DAYS = 30', $source );
		$this->assertStringContainsString( "EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_HOOK         = 'extrachill_analytics_email_cleanup'", $source );
		$this->assertStringContainsString( "add_filter( 'wp_privacy_personal_data_exporters'", $source );
		$this->assertStringContainsString( "add_filter( 'wp_privacy_personal_data_erasers'", $source );
	}

	/**
	 * Only the main site owns the shared-table cron and privacy callbacks.
	 */
	public function test_main_site_is_network_authority(): void {
		extrachill_analytics_schedule_email_cleanup();
		$this->assertCount( 1, $GLOBALS['extrachill_analytics_test_recurring'] );
		$this->assertArrayHasKey( 'extrachill-email-analytics', extrachill_analytics_register_email_event_exporter( array() ) );
		$this->assertArrayHasKey( 'extrachill-email-analytics', extrachill_analytics_register_email_event_eraser( array() ) );

		$GLOBALS['extrachill_analytics_test_is_main_site'] = false;
		$GLOBALS['extrachill_analytics_test_scheduled']    = array();
		$GLOBALS['extrachill_analytics_test_recurring']    = array();

		extrachill_analytics_schedule_email_cleanup();
		$this->assertSame( array(), $GLOBALS['extrachill_analytics_test_recurring'] );
		$this->assertSame( array(), extrachill_analytics_register_email_event_exporter( array() ) );
		$this->assertSame( array(), extrachill_analytics_register_email_event_eraser( array() ) );
		$this->assertSame(
			array(
				'data' => array(),
				'done' => true,
			),
			extrachill_analytics_email_event_exporter( 'person@example.com', 1 )
		);
	}

	/**
	 * Full batches continue under a network lock until the backlog drains.
	 */
	public function test_cleanup_schedules_bounded_continuation_and_prevents_overlap(): void {
		global $wpdb;

		$wpdb->query_results = array( 1000, 25, 0, 1 );
		$this->assertTrue( extrachill_analytics_cleanup_email_events() );
		$this->assertCount( 4, $wpdb->queries );
		$this->assertCount( 1, $GLOBALS['extrachill_analytics_test_single'] );
		$this->assertArrayNotHasKey( EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_LOCK, $GLOBALS['extrachill_analytics_test_site_options'] );
		$this->assertCount( 2, $GLOBALS['extrachill_analytics_test_cache_deletes'] );

		$wpdb->queries = array();
		$GLOBALS['extrachill_analytics_test_site_options'][ EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_LOCK ] = array(
			'token'       => 'active-worker',
			'acquired_at' => time(),
		);
		$this->assertFalse( extrachill_analytics_cleanup_email_events() );
		$this->assertSame( array(), $wpdb->queries );
	}

	/**
	 * Replacement between stale-lock read and update makes takeover fail closed.
	 */
	public function test_stale_lock_takeover_is_atomic_against_replacement(): void {
		global $wpdb;

		$stale_lock = array(
			'token'       => 'stale-worker',
			'acquired_at' => time() - ( 11 * MINUTE_IN_SECONDS ),
		);
		$GLOBALS['extrachill_analytics_test_site_options'][ EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_LOCK ] = $stale_lock;
		$wpdb->query_results          = array( 0 );
		$wpdb->before_query_callbacks = array(
			function () {
				$GLOBALS['extrachill_analytics_test_site_options'][ EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_LOCK ] = array(
					'token'       => 'replacement-worker',
					'acquired_at' => time(),
				);
			},
		);

		$this->assertFalse( extrachill_analytics_cleanup_email_events() );
		$this->assertSame( 'replacement-worker', $GLOBALS['extrachill_analytics_test_site_options'][ EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_LOCK ]['token'] );
		$this->assertCount( 1, $wpdb->queries );
		$this->assertStringContainsString( 'UPDATE wp_sitemeta SET meta_value', $wpdb->queries[0] );
		$this->assertStringContainsString( addslashes( maybe_serialize( $stale_lock ) ), $wpdb->queries[0] );
		$this->assertSame( array(), $GLOBALS['extrachill_analytics_test_cache_deletes'] );
	}

	/**
	 * Successful conditional takeover invalidates Core's network-option caches.
	 */
	public function test_successful_atomic_takeover_flushes_network_option_cache(): void {
		global $wpdb;

		$expected            = array(
			'token'       => 'stale-worker',
			'acquired_at' => 1,
		);
		$replacement         = array(
			'token'       => 'new-worker',
			'acquired_at' => 2,
		);
		$wpdb->query_results = array( 1 );

		$this->assertTrue( extrachill_analytics_compare_and_swap_email_cleanup_lock( $expected, $replacement ) );
		$this->assertSame(
			array(
				array(
					'key'   => '1:' . EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_LOCK,
					'group' => 'site-options',
				),
				array(
					'key'   => '1:notoptions',
					'group' => 'site-options',
				),
			),
			$GLOBALS['extrachill_analytics_test_cache_deletes']
		);
	}

	/**
	 * Replacement immediately before conditional delete makes release fail.
	 */
	public function test_release_is_atomic_against_replacement(): void {
		global $wpdb;

		$owned_lock = array(
			'token'       => 'worker-token',
			'acquired_at' => time(),
		);
		$GLOBALS['extrachill_analytics_test_site_options'][ EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_LOCK ] = $owned_lock;
		$wpdb->query_results          = array( 0 );
		$wpdb->before_query_callbacks = array(
			function () {
				$GLOBALS['extrachill_analytics_test_site_options'][ EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_LOCK ] = array(
					'token'       => 'replacement-worker',
					'acquired_at' => time(),
				);
			},
		);

		$this->assertFalse( extrachill_analytics_release_email_cleanup_lock( $owned_lock ) );
		$this->assertSame( 'replacement-worker', $GLOBALS['extrachill_analytics_test_site_options'][ EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_LOCK ]['token'] );
		$this->assertStringContainsString( 'DELETE FROM wp_sitemeta', $wpdb->queries[0] );
		$this->assertStringContainsString( addslashes( maybe_serialize( $owned_lock ) ), $wpdb->queries[0] );
		$this->assertSame( array(), $GLOBALS['extrachill_analytics_test_cache_deletes'] );
	}

	/**
	 * Database failures remain visible and are retried by continuation.
	 */
	public function test_cleanup_surfaces_failures(): void {
		global $wpdb;

		$wpdb->query_results = array( false, 0, 0, 1 );
		$wpdb->query_errors  = array( 'first query failure', '', '', '' );

		$this->assertFalse( extrachill_analytics_cleanup_email_events() );
		$this->assertSame( 'expired_delete', $GLOBALS['extrachill_analytics_test_site_options'][ EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_ERROR ]['operation'] );
		$this->assertSame( '', $wpdb->last_error );
		$this->assertSame( 'first query failure', $GLOBALS['extrachill_analytics_test_site_options'][ EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_ERROR ]['error'] );
		$this->assertCount( 1, $GLOBALS['extrachill_analytics_test_single'] );
	}

	/**
	 * Export pagination uses a fixed snapshot and stable ID cursor.
	 */
	public function test_export_uses_network_wide_keyset_pagination(): void {
		global $wpdb;

		$first_page = array();
		for ( $id = 1; $id <= 500; $id++ ) {
			$first_page[] = (object) array(
				'id'         => $id,
				'blog_id'    => 7,
				'event_type' => 'email_sent',
				'event_data' => '{}',
				'created_at' => '2026-07-17 12:00:00',
			);
		}
		$wpdb->max_id = 700;
		$wpdb->rows   = array(
			$first_page,
			array(
				(object) array(
					'id'         => 700,
					'blog_id'    => 2,
					'event_type' => 'email_failed',
					'event_data' => '{"error_code":"smtp_failed"}',
					'created_at' => '2026-07-17 12:01:00',
				),
			),
		);

		$page_one = extrachill_analytics_email_event_exporter( 'person@example.com', 1 );
		$page_two = extrachill_analytics_email_event_exporter( 'person@example.com', 2 );

		$this->assertFalse( $page_one['done'] );
		$this->assertTrue( $page_two['done'] );
		$this->assertStringNotContainsString( 'OFFSET', $wpdb->queries[1] );
		$this->assertStringContainsString( 'id > 0 AND id <= 700', $wpdb->queries[1] );
		$this->assertStringContainsString( 'id > 500 AND id <= 700', $wpdb->queries[2] );
		$this->assertStringNotContainsString( 'blog_id =', $wpdb->queries[1] );
		$this->assertSame( '2', $page_two['data'][0]['data'][2]['value'] );
	}

	/**
	 * Erasure is network-wide only when invoked from the main site.
	 */
	public function test_eraser_has_explicit_network_scope(): void {
		global $wpdb;

		$GLOBALS['extrachill_analytics_test_is_main_site'] = false;
		$result = extrachill_analytics_email_event_eraser( 'person@example.com', 1 );
		$this->assertTrue( $result['done'] );
		$this->assertSame( array(), $wpdb->queries );

		$GLOBALS['extrachill_analytics_test_is_main_site'] = true;
		$wpdb->query_results                               = array( 500 );
		$result = extrachill_analytics_email_event_eraser( 'person@example.com', 1 );
		$this->assertFalse( $result['done'] );
		$this->assertTrue( $result['items_removed'] );
		$this->assertStringContainsString( 'user_id = 42', $wpdb->queries[0] );
		$this->assertStringNotContainsString( 'blog_id', $wpdb->queries[0] );
	}
}
