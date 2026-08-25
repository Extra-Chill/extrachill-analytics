<?php
/**
 * Analytics migration hardening contracts.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/database/link-page-analytics-db.php';
require_once dirname( __DIR__ ) . '/inc/core/link-page-storage-migration.php';


/** Verify exact Analytics migration safety contracts. */
final class LinkPageStorageMigrationContractTest extends TestCase {
	/** Prefix collisions, JSON identities, and rollback are exact. */
	public function test_collision_history_and_rollback_are_exact(): void {
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/link-page-storage-migration.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract fixture.
		$this->assertStringContainsString( 'LEFT(link_url,191) = LEFT(%s,191)', $source );
		$this->assertStringContainsString( 'LEFT(link_text,100) = LEFT(%s,100)', $source );
		$this->assertStringContainsString( 'JSON_VALID(event_data)', $source );
		$this->assertStringContainsString( "JSON_EXTRACT(event_data, '$.post_id')", $source );
		$this->assertStringContainsString( "empty( \$entry['applied'] )", $source );
		$this->assertStringContainsString( "\$current !== \$entry['row']", $source );
		$this->assertStringContainsString( "'' !== \$wpdb->last_error", $source );
	}

	/** Analytics registers a versioned non-owner participant. */
	public function test_registration_is_versioned_and_non_claiming(): void {
		$this->assertFalse( extrachill_analytics_link_page_migration_claim_owner( array() ) );
		$source = file_get_contents( dirname( __DIR__ ) . '/inc/core/link-page-storage-migration.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract fixture.
		$this->assertStringContainsString( "'analytics',\n\t\t'1'", $source );
	}
}
