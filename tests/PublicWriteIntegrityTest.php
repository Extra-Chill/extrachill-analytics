<?php
/**
 * Tests for the public analytics write boundary.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/route-classifier.php';
require_once dirname( __DIR__ ) . '/inc/core/assets.php';
require_once dirname( __DIR__ ) . '/inc/core/write-integrity.php';
require_once dirname( __DIR__ ) . '/inc/core/abilities.php';

/**
 * Verify public writes carry consistent first-party evidence and stay bounded.
 */
final class PublicWriteIntegrityTest extends TestCase {
	/**
	 * Establish a normal browser request.
	 */
	protected function setUp(): void {
		$_SERVER['HTTP_ORIGIN'] = 'https://extrachill.com';
		unset( $_SERVER['HTTP_REFERER'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_SEC_GPC'], $_SERVER['HTTP_DNT'] );
		$GLOBALS['extrachill_analytics_test_blog_id']     = 1;
		$GLOBALS['extrachill_analytics_test_home_urls']   = array( 1 => 'https://extrachill.com' );
		$GLOBALS['extrachill_analytics_test_domain_map']  = array(
			'extrachill.com'  => 1,
			'extrachill.link' => 4,
		);
		$GLOBALS['extrachill_analytics_test_blog_slugs']  = array(
			1 => 'main',
			4 => 'artist',
			7 => 'events',
		);
		$GLOBALS['extrachill_analytics_test_transients']  = array();
		$GLOBALS['extrachill_analytics_test_events']      = array();
		$GLOBALS['extrachill_analytics_classifier_posts'] = array();
		$GLOBALS['extrachill_analytics_test_permalinks']  = array();
		$_COOKIE['ec_vid']                                = '123e4567-e89b-42d3-a456-426614174000';
	}

	/**
	 * Restore request fixtures.
	 */
	protected function tearDown(): void {
		unset( $_SERVER['HTTP_ORIGIN'], $_SERVER['HTTP_REFERER'], $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_SEC_GPC'], $_SERVER['HTTP_DNT'], $_COOKIE['ec_vid'] );
	}

	/**
	 * A mapped custom-domain render can submit its exact signed post tuple.
	 */
	public function test_mapped_custom_domain_post_view_is_accepted(): void {
		$GLOBALS['extrachill_analytics_test_blog_id']      = 4;
		$GLOBALS['extrachill_analytics_test_home_urls'][4] = 'https://artist.extrachill.com';
		$_SERVER['HTTP_ORIGIN']                            = 'https://extrachill.link';
		$post            = new WP_Post();
		$post->ID        = 42;
		$post->post_name = 'band';
		$GLOBALS['extrachill_analytics_classifier_posts'][42] = $post;
		$proof = extrachill_analytics_pageview_proof( 42, '/band/', 'singular', 'extrachill.link' );

		$this->assertTrue( extrachill_analytics_validate_pageview_write( 42, '/band/', 'singular', $proof ) );
	}

	/**
	 * The deployed custom-domain adapter remains valid without a signed proof.
	 */
	public function test_legacy_mapped_domain_view_requires_matching_public_post(): void {
		$GLOBALS['extrachill_analytics_test_blog_id']      = 4;
		$GLOBALS['extrachill_analytics_test_home_urls'][4] = 'https://artist.extrachill.com';
		$_SERVER['HTTP_ORIGIN']                            = 'https://extrachill.link';
		$post            = new WP_Post();
		$post->ID        = 42;
		$post->post_name = 'band';
		$GLOBALS['extrachill_analytics_classifier_posts'][42] = $post;
		$GLOBALS['extrachill_analytics_test_permalinks'][42]  = 'https://artist.extrachill.com/artist-link-page/band/';

		$this->assertTrue( extrachill_analytics_validate_pageview_write( 42, '/band/', 'singular', '' ) );
		$result = extrachill_analytics_validate_pageview_write( 42, '/another-band/', 'singular', '' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_pageview_source', $result->code );
	}

	/**
	 * A proof cannot be moved to another post, path, host, or route family.
	 */
	public function test_pageview_proof_rejects_changed_source_tuple(): void {
		$proof  = extrachill_analytics_pageview_proof( 0, '/events/', 'directory', 'extrachill.com' );
		$result = extrachill_analytics_validate_pageview_write( 99, '/fake/', 'singular', $proof );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_pageview_proof', $result->code );
	}

	/**
	 * Headerless clients cannot present public browser events as first-party.
	 */
	public function test_public_event_requires_current_site_browser_origin(): void {
		unset( $_SERVER['HTTP_ORIGIN'] );
		$result = extrachill_analytics_validate_public_event_write( 'outbound_click', array(), '/story/' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_event_origin', $result->code );
	}

	/**
	 * Absolute source claims must agree with the browser origin.
	 */
	public function test_public_event_rejects_source_host_mismatch(): void {
		$result = extrachill_analytics_validate_public_event_write( 'bridge_click', array(), 'https://attacker.example/story/' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_event_source', $result->code );
	}

	/**
	 * Public event dimensions are checked and source URLs become query-free paths.
	 */
	public function test_public_event_normalizes_consistent_network_dimensions(): void {
		$result = extrachill_analytics_validate_public_event_write(
			'bridge_impression',
			array(
				'source_site' => 'main',
				'dest_site'   => 'events',
			),
			'https://extrachill.com/story/?email=reader@example.test'
		);

		$this->assertIsArray( $result );
		$this->assertSame( '/story/', $result['source_url'] );
	}

	/**
	 * A source post cannot be attached to a different page path.
	 */
	public function test_public_event_rejects_source_post_mismatch(): void {
		$GLOBALS['extrachill_analytics_test_permalinks'][42] = 'https://extrachill.com/real-story/';
		$result = extrachill_analytics_validate_public_event_write(
			'bridge_click',
			array( 'source_post' => 42 ),
			'/other-story/'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_event_post', $result->code );
	}

	/**
	 * Public adapters cannot inject visitor identity, including under GPC.
	 */
	public function test_gpc_public_event_stays_anonymous(): void {
		$_SERVER['HTTP_SEC_GPC'] = '1';
		$result                  = extrachill_analytics_ability_track_event(
			array(
				'event_type' => 'outbound_click',
				'event_data' => array( 'dest_host' => 'tickets.example' ),
				'source_url' => '/story/',
				'visitor_id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
			)
		);

		$this->assertSame( 1, $result );
		$this->assertSame( '', $GLOBALS['extrachill_analytics_test_events'][0][3] );
	}

	/**
	 * Server-owned event types do not inherit browser admission requirements.
	 */
	public function test_internal_event_contract_remains_unchanged(): void {
		unset( $_SERVER['HTTP_ORIGIN'] );
		$result = extrachill_analytics_validate_public_event_write( 'user_registration', array( 'method' => 'form' ), '/register/' );

		$this->assertSame( '/register/', $result['source_url'] );
	}

	/**
	 * The Analytics-owned limiter rejects writes at its documented ceiling.
	 */
	public function test_public_write_rate_limit_is_bounded(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
		$key                    = 'ec_an_write_' . substr( hash_hmac( 'sha256', '203.0.113.10', wp_salt( 'nonce' ) ), 0, 32 );
		$GLOBALS['extrachill_analytics_test_transients'][ $key ] = 240;

		$result = extrachill_analytics_check_public_write_rate_limit();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'analytics_write_rate_limited', $result->code );
		$this->assertSame( 429, $result->data['status'] );
	}
}
