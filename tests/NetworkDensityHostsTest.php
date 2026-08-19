<?php
/**
 * Tests for the Data Machine Business network-density host scope (#242).
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/network-density.php';

/**
 * Verify the consumer-owned network scope passed to Data Machine Business.
 */
final class NetworkDensityHostsTest extends TestCase {

	/**
	 * Reset canonical multisite fixtures before each test.
	 */
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['extrachill_analytics_test_active_site_ids'] = array( 1, 2, 7, 12 );
		$GLOBALS['extrachill_analytics_test_home_urls']       = array(
			1  => 'HTTPS://EXTRACHILL.COM./',
			2  => 'https://community.extrachill.com/',
			7  => 'https://events.extrachill.com/',
			12 => 'https://studio.extrachill.com/',
			13 => 'https://archived.extrachill.com/',
		);
	}

	/**
	 * The DMB extension point is registered at plugin load.
	 */
	public function test_network_density_filter_is_registered(): void {
		$this->assertContains(
			array( 'datamachine_network_density_hosts', 'extrachill_analytics_network_density_hosts' ),
			$GLOBALS['extrachill_analytics_test_registered_filters']
		);
	}

	/**
	 * Main-site execution returns normalized apex and active subdomain hosts.
	 */
	public function test_scope_uses_canonical_active_multisite_hosts(): void {
		$this->assertSame(
			array(
				'extrachill.com',
				'community.extrachill.com',
				'events.extrachill.com',
				'studio.extrachill.com',
			),
			extrachill_analytics_network_density_hosts( array() )
		);
		$this->assertNotContains( 'archived.extrachill.com', extrachill_analytics_network_density_hosts( array() ) );
	}

	/**
	 * The consumer replaces, rather than extends, an externally supplied scope.
	 */
	public function test_dmb_boundary_excludes_external_hosts(): void {
		$this->assertNotContains(
			'google.com',
			extrachill_analytics_network_density_hosts( array( 'google.com' ) )
		);
	}
}
