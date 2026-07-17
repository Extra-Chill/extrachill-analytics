<?php
/**
 * Drift tests for canonical analytics event contracts.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/event-types.php';

/**
 * Keep active emitters and report readers on canonical names.
 */
final class EventContractsTest extends TestCase {
	/**
	 * Active cross-plugin and report-critical names remain exact.
	 */
	public function test_canonical_active_event_names(): void {
		$this->assertSame( 'user_registration', EC_ANALYTICS_EVENT_USER_REGISTRATION );
		$this->assertSame( 'newsletter_signup', EC_ANALYTICS_EVENT_NEWSLETTER_SIGNUP );
		$this->assertSame( 'search', EC_ANALYTICS_EVENT_SEARCH );
		$this->assertSame( 'search_attack', EC_ANALYTICS_EVENT_SEARCH_ATTACK );
		$this->assertSame( 'share_click', EC_ANALYTICS_EVENT_SHARE_CLICK );
		$this->assertSame( 'bridge_click', EC_ANALYTICS_EVENT_BRIDGE_CLICK );
		$this->assertSame( 'bridge_impression', EC_ANALYTICS_EVENT_BRIDGE_IMPRESSION );
		$this->assertSame( 'outbound_click', EC_ANALYTICS_EVENT_OUTBOUND_CLICK );
		$this->assertSame( '404_error', EC_ANALYTICS_EVENT_404_ERROR );
		$this->assertSame( 'email_sent', EC_ANALYTICS_EVENT_EMAIL_SENT );
		$this->assertSame( 'email_failed', EC_ANALYTICS_EVENT_EMAIL_FAILED );
		$this->assertSame( 'redirect_fire', EC_ANALYTICS_EVENT_REDIRECT_FIRE );
		$this->assertSame( 'experiment_assignment', EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT );
		$this->assertSame( 'experiment_exposure', EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE );
		$this->assertNotSame( EC_ANALYTICS_EVENT_EXPERIMENT_ASSIGNMENT, EC_ANALYTICS_EVENT_EXPERIMENT_EXPOSURE );
	}

	/**
	 * Real Analytics-owned emitters and critical readers reference constants.
	 */
	public function test_emitters_and_readers_do_not_redescribe_contract_names(): void {
		$expectations = array(
			'inc/core/404-tracking.php'                  => 'EC_ANALYTICS_EVENT_404_ERROR',
			'inc/core/email-tracking.php'                => 'EC_ANALYTICS_EVENT_EMAIL_SENT',
			'inc/core/abilities.php'                     => 'EC_ANALYTICS_EVENT_SEARCH_ATTACK',
			'inc/core/abilities/get-search-gaps.php'     => 'EC_ANALYTICS_EVENT_SEARCH',
			'inc/core/abilities/get-attack-summary.php'  => 'EC_ANALYTICS_EVENT_SEARCH_ATTACK',
			'inc/core/abilities/get-bridge-ctr.php'      => 'EC_ANALYTICS_EVENT_BRIDGE_IMPRESSION',
			'inc/core/abilities/get-outbound-clicks.php' => 'EC_ANALYTICS_EVENT_OUTBOUND_CLICK',
			'inc/core/abilities/get-conversion-map.php'  => 'EC_ANALYTICS_EVENT_USER_REGISTRATION',
			'inc/core/abilities/get-404-summary.php'     => 'EC_ANALYTICS_EVENT_404_ERROR',
		);

		foreach ( $expectations as $relative_path => $constant ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source contract fixture.
			$source = file_get_contents( dirname( __DIR__ ) . '/' . $relative_path );
			$this->assertIsString( $source );
			$this->assertStringContainsString( $constant, $source, $relative_path );
		}
	}
}
