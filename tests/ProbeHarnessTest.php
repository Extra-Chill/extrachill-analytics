<?php

require_once __DIR__ . '/class-extrachill-analytics-test-case.php';

final class ProbeHarnessTest extends Extrachill_Analytics_TestCase {
	public function test_probe(): void {
		$community = self::factory()->blog->create( array( 'domain' => 'community.example.org', 'path' => '/' ) );
		$err = is_wp_error( $community ) ? $community->get_error_message() . ' | ' . $community->get_error_code() : 'ok';
		$events    = self::factory()->blog->create( array( 'domain' => 'events.example.org', 'path' => '/' ) );
		$studio    = self::factory()->blog->create( array( 'domain' => 'studio.example.org', 'path' => '/' ) );
		$facts     = array(
			'err'      => $err,
			'ids'      => wp_json_encode( array( $community, $events, $studio ) ),
			'home_2'   => get_home_url( $community, '/' ),
			'home_3'   => get_home_url( $events, '/' ),
			'home_4'   => get_home_url( $studio, '/' ),
			'site_3'   => wp_json_encode( get_site( $events ) ),
			'opt_3'    => get_blog_option( $events, 'home' ),
			'term_dupe' => wp_json_encode( self::factory()->category->create( array( 'slug' => 'dup' ) ) ),
		);
		self::fail( 'PROBE::' . wp_json_encode( $facts ) );
	}
}
