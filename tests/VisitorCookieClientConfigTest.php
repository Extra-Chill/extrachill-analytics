<?php
/**
 * Tests for the client-side visitor-cookie mint boundary.
 *
 * The server no longer mints `ec_vid` (a `Set-Cookie` response is refused by
 * the edge cache); the browser mints it from config this plugin localizes.
 * These tests pin that boundary: which requests receive a mint-capable config,
 * that the computed domain stays the leading-dot network root, and that no
 * server code path mints or emits `Set-Cookie` anymore.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

/**
 * Verify visitor identity config is exposed only to eligible first-party requests.
 */
final class VisitorCookieClientConfigTest extends TestCase {
	/**
	 * Load the request-boundary helpers.
	 */
	public static function setUpBeforeClass(): void {
		require_once dirname( __DIR__ ) . '/inc/core/assets.php';
	}

	/**
	 * Set a normal first-party browser request before each test.
	 */
	protected function setUp(): void {
		$_SERVER['REQUEST_METHOD']                           = 'GET';
		$_SERVER['HTTP_HOST']                                = 'extrachill.com';
		$GLOBALS['extrachill_analytics_test_network_domain'] = 'extrachill.com';
		unset( $_COOKIE[ EXTRACHILL_ANALYTICS_VISITOR_COOKIE ] );
	}

	/**
	 * Restore request fixtures after each test.
	 */
	protected function tearDown(): void {
		unset(
			$_SERVER['REQUEST_METHOD'],
			$_SERVER['HTTP_HOST'],
			$_SERVER['HTTP_SEC_GPC'],
			$_SERVER['HTTP_DNT'],
			$_COOKIE[ EXTRACHILL_ANALYTICS_VISITOR_COOKIE ],
			$GLOBALS['extrachill_analytics_test_network_domain'],
			$GLOBALS['extrachill_analytics_test_is_preview'],
			$GLOBALS['extrachill_analytics_test_is_admin'],
			$GLOBALS['extrachill_analytics_test_doing_ajax'],
			$GLOBALS['extrachill_analytics_test_doing_cron']
		);
	}

	/**
	 * Eligible first-party requests receive the full client mint config.
	 */
	public function test_first_party_public_request_receives_mint_config(): void {
		$config = extrachill_analytics_visitor_cookie_client_config();

		$this->assertSame( 'ec_vid', $config['cookieName'] );
		$this->assertSame( YEAR_IN_SECONDS, $config['cookieMaxAge'] );
	}

	/**
	 * The cookie domain stays the leading-dot network root so ONE visitor id
	 * spans every subdomain.
	 *
	 * @dataProvider first_party_host_provider
	 *
	 * @param string $host First-party network host.
	 */
	public function test_cookie_domain_is_the_leading_dot_network_root( $host ): void {
		$_SERVER['HTTP_HOST'] = $host;

		$config = extrachill_analytics_visitor_cookie_client_config();

		$this->assertSame( '.extrachill.com', $config['cookieDomain'] );
	}

	/**
	 * First-party public route hosts.
	 *
	 * @return array<string,array{string}>
	 */
	public function first_party_host_provider() {
		return array(
			'network homepage' => array( 'extrachill.com' ),
			'subdomain'        => array( 'events.extrachill.com' ),
			'community login'  => array( 'community.extrachill.com' ),
		);
	}

	/**
	 * Preview and non-template runtimes receive no mintable domain.
	 *
	 * @dataProvider ineligible_runtime_provider
	 *
	 * @param string $fixture Runtime fixture global.
	 */
	public function test_non_template_runtimes_receive_no_mint_domain( $fixture ): void {
		$GLOBALS[ $fixture ] = true;

		$config = extrachill_analytics_visitor_cookie_client_config();

		$this->assertSame( '', $config['cookieDomain'] );
	}

	/**
	 * Runtime fixture globals.
	 *
	 * @return array<string,array{string}>
	 */
	public function ineligible_runtime_provider() {
		return array(
			'preview' => array( 'extrachill_analytics_test_is_preview' ),
			'admin'   => array( 'extrachill_analytics_test_is_admin' ),
			'ajax'    => array( 'extrachill_analytics_test_doing_ajax' ),
			'cron'    => array( 'extrachill_analytics_test_doing_cron' ),
		);
	}

	/**
	 * Unsafe methods and custom-domain hosts must not mint the network cookie.
	 */
	public function test_post_and_third_party_hosts_receive_no_mint_domain(): void {
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$this->assertSame( '', extrachill_analytics_visitor_cookie_client_config()['cookieDomain'] );

		$_SERVER['REQUEST_METHOD'] = 'GET';
		$_SERVER['HTTP_HOST']      = 'artist.example';
		$this->assertSame( '', extrachill_analytics_visitor_cookie_client_config()['cookieDomain'] );
	}

	/**
	 * GPC and DNT opt-out requests receive no mintable domain.
	 *
	 * @dataProvider privacy_header_provider
	 *
	 * @param string $header Privacy request header.
	 */
	public function test_privacy_opt_out_requests_receive_no_mint_domain( $header ): void {
		$_SERVER[ $header ] = '1';

		$config = extrachill_analytics_visitor_cookie_client_config();

		$this->assertSame( '', $config['cookieDomain'] );
	}

	/**
	 * Supported privacy headers.
	 *
	 * @return array<string,array{string}>
	 */
	public function privacy_header_provider() {
		return array(
			'global privacy control' => array( 'HTTP_SEC_GPC' ),
			'do not track'           => array( 'HTTP_DNT' ),
		);
	}

	/**
	 * The server is read-only: no mint function exists and no source file
	 * emits `Set-Cookie`.
	 */
	public function test_server_never_mints_or_emits_set_cookie(): void {
		$this->assertFalse(
			function_exists( 'extrachill_analytics_get_or_mint_visitor_id' )
		);
		$this->assertStringNotContainsString(
			'setcookie(',
			$this->read_source( 'inc/core/assets.php' )
		);
		$this->assertStringNotContainsString(
			'setcookie(',
			$this->read_source( 'inc/core/abilities/track-page-view.php' )
		);
	}

	/**
	 * The read-only resolver stitches to an existing cookie and attributes
	 * anonymously (empty string, no mint) when one is absent.
	 */
	public function test_read_visitor_id_resolves_existing_cookie_or_stays_anonymous(): void {
		$_COOKIE[ EXTRACHILL_ANALYTICS_VISITOR_COOKIE ] = '123e4567-e89b-42d3-a456-426614174000';
		$this->assertSame(
			'123e4567-e89b-42d3-a456-426614174000',
			extrachill_analytics_read_visitor_id()
		);

		unset( $_COOKIE[ EXTRACHILL_ANALYTICS_VISITOR_COOKIE ] );
		$this->assertSame( '', extrachill_analytics_read_visitor_id() );
		$this->assertArrayNotHasKey(
			EXTRACHILL_ANALYTICS_VISITOR_COOKIE,
			$_COOKIE
		);
	}

	/**
	 * GPC and DNT opt-out still suppresses identity resolution server-side.
	 */
	public function test_opted_out_requests_resolve_no_visitor_id(): void {
		$_COOKIE[ EXTRACHILL_ANALYTICS_VISITOR_COOKIE ] = '123e4567-e89b-42d3-a456-426614174000';
		$_SERVER['HTTP_SEC_GPC']                        = '1';

		$this->assertSame( '', extrachill_analytics_read_visitor_id() );
	}

	/**
	 * REST and CLI guards remain explicit in the request boundary.
	 */
	public function test_rest_and_cli_requests_are_explicitly_ineligible(): void {
		$source = $this->read_source( 'inc/core/assets.php' );

		$this->assertStringContainsString( "defined( 'REST_REQUEST' ) && REST_REQUEST", $source );
		$this->assertStringContainsString( "defined( 'WP_CLI' )", $source );
	}

	/**
	 * Read a production source file.
	 *
	 * @param string $relative_path Repository-relative path.
	 * @return string
	 */
	private function read_source( $relative_path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading a local source fixture.
		$source = file_get_contents( dirname( __DIR__ ) . '/' . $relative_path );

		$this->assertNotFalse( $source );

		return $source;
	}
}
