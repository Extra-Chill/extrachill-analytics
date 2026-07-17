<?php
/**
 * Tests for privacy-safe email outcome tracking.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/email-tracking.php';

/**
 * Verify bounded payload helpers and privacy contracts.
 */
final class EmailTrackingPrivacyTest extends TestCase {

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
		$this->assertStringContainsString( "add_action( 'extrachill_analytics_email_cleanup'", $source );
		$this->assertStringContainsString( "add_filter( 'wp_privacy_personal_data_exporters'", $source );
		$this->assertStringContainsString( "add_filter( 'wp_privacy_personal_data_erasers'", $source );
	}
}
