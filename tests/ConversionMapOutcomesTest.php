<?php
/**
 * Tests for conversion-map concrete outcome attribution.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/abilities/get-conversion-map.php';

/**
 * Protect outcome deduplication, attribution boundaries, and coverage semantics.
 */
final class ConversionMapOutcomesTest extends TestCase {
	/**
	 * The outcome lens preserves concrete canonical lifecycle event names.
	 */
	public function test_outcome_types_include_proven_lifecycle_completions(): void {
		$this->assertSame(
			array(
				'newsletter_signup',
				'user_registration',
				'onboarding_completed',
				'artist_profile_first_publish',
				'local_scene_prompt_completed',
			),
			extrachill_analytics_conversion_outcome_types()
		);
	}

	/**
	 * Registration deduplication uses the created user ID stored in event_data.
	 */
	public function test_registration_deduplicates_by_payload_user_before_visitor(): void {
		$key = extrachill_analytics_conversion_outcome_dedupe_key(
			array(
				'id'         => 91,
				'user_id'    => 0,
				'visitor_id' => 'visitor-a',
				'event_data' => array( 'user_id' => 647 ),
			)
		);

		$this->assertSame( 'user:647', $key );
	}

	/**
	 * Identity-free outcomes remain distinct observable events.
	 */
	public function test_identity_free_outcomes_deduplicate_by_event(): void {
		$this->assertSame(
			'event:92',
			extrachill_analytics_conversion_outcome_dedupe_key(
				array(
					'id'         => 92,
					'event_data' => array(),
				)
			)
		);
	}

	/**
	 * Journey attribution preserves before-entry, same-session, and later-session.
	 */
	public function test_outcome_journey_stage_uses_explicit_session_boundary(): void {
		$journey = array(
			'entry_ts'             => 1000,
			'same_session_through' => 2800,
		);

		$this->assertNull( extrachill_analytics_conversion_outcome_journey_stage( 999, $journey ) );
		$this->assertSame( 'same_session', extrachill_analytics_conversion_outcome_journey_stage( 2800, $journey ) );
		$this->assertSame( 'later_session', extrachill_analytics_conversion_outcome_journey_stage( 2801, $journey ) );
	}

	/**
	 * Direct attribution accepts published main-site articles and rejects other hosts.
	 */
	public function test_direct_source_requires_published_entry_blog_article(): void {
		$post              = new WP_Post();
		$post->ID          = 173;
		$post->post_type   = 'post';
		$post->post_status = 'publish';

		$GLOBALS['extrachill_analytics_classifier_posts']  = array( 173 => $post );
		$GLOBALS['extrachill_analytics_test_url_post_ids'] = array(
			'https://extrachill.com/article/' => 173,
		);
		$GLOBALS['extrachill_analytics_test_home_urls']    = array( 1 => 'https://extrachill.com' );

		$this->assertSame( 173, extrachill_analytics_conversion_source_article_id( 'https://extrachill.com/article/', 1 ) );
		$this->assertSame( 0, extrachill_analytics_conversion_source_article_id( 'https://events.extrachill.com/article/', 1 ) );
	}

	/**
	 * No visitor instrumentation produces null journey counts, not measured zero.
	 */
	public function test_missing_identity_coverage_is_not_reported_as_zero(): void {
		$coverage                             = extrachill_analytics_conversion_outcome_zero_coverage();
		$coverage['deduplicated_outcomes']    = 3;
		$coverage['with_source_url']          = 3;
		$coverage['direct_source_attributed'] = 3;
		$coverage['missing_visitor_identity'] = 3;
		$finalized                            = extrachill_analytics_conversion_finalize_outcome_coverage( $coverage );
		$coverage_by_type                     = array(
			'newsletter_signup' => $finalized,
			'user_registration' => $finalized,
		);
		$row                                  = extrachill_analytics_conversion_outcome_row(
			extrachill_analytics_conversion_outcome_zero_bucket(),
			$coverage_by_type
		);

		$this->assertSame( 'not_instrumented', $row['newsletter_signup']['visitor_journey']['coverage_status'] );
		$this->assertNull( $row['newsletter_signup']['visitor_journey']['same_session_count'] );
		$this->assertNull( $row['newsletter_signup']['visitor_journey']['later_session_count'] );
		$this->assertSame( 0, $row['newsletter_signup']['direct_source']['count'] );
	}

	/**
	 * Partial coverage preserves measured counts while disclosing the gap.
	 */
	public function test_partial_coverage_preserves_only_observed_counts(): void {
		$coverage                             = extrachill_analytics_conversion_outcome_zero_coverage();
		$coverage['deduplicated_outcomes']    = 4;
		$coverage['with_source_url']          = 2;
		$coverage['direct_source_attributed'] = 1;
		$coverage['with_visitor_identity']    = 3;
		$coverage['missing_source_url']       = 2;
		$coverage['missing_visitor_identity'] = 1;
		$finalized                            = extrachill_analytics_conversion_finalize_outcome_coverage( $coverage );

		$this->assertSame( 'partial', $finalized['direct_source_status'] );
		$this->assertSame( 'partial', $finalized['visitor_journey_status'] );
		$this->assertSame( 2, $finalized['missing_source_url'] );
		$this->assertSame( 1, $finalized['missing_visitor_identity'] );
	}

	/**
	 * Identified lifecycle outcomes retain same/later-session attribution and dedupe.
	 */
	public function test_lifecycle_outcome_fixtures_preserve_identity_dedupe_and_order(): void {
		$types    = array(
			'onboarding_completed',
			'artist_profile_first_publish',
			'local_scene_prompt_completed',
		);
		$rows     = array(
			$this->outcome_row( 1, 'local_scene_prompt_completed', 21, 'visitor-c', 900 ),
			$this->outcome_row( 2, 'artist_profile_first_publish', 11, 'visitor-a', 1200 ),
			$this->outcome_row( 3, 'local_scene_prompt_completed', 21, 'visitor-c', 1200 ),
			$this->outcome_row( 4, 'artist_profile_first_publish', 11, 'visitor-a', 1300 ),
			$this->outcome_row( 5, 'onboarding_completed', 31, '', 1400 ),
			$this->outcome_row( 6, 'artist_profile_first_publish', 12, 'visitor-b', 3000 ),
		);
		$journeys = array(
			'visitor-a' => array(
				'post_id'              => 0,
				'entry_ts'             => 1000,
				'same_session_through' => 2000,
			),
			'visitor-b' => array(
				'post_id'              => 0,
				'entry_ts'             => 1000,
				'same_session_through' => 2000,
			),
			'visitor-c' => array(
				'post_id'              => 0,
				'entry_ts'             => 1000,
				'same_session_through' => 2000,
			),
		);

		$overall     = extrachill_analytics_conversion_outcome_zero_bucket( $types );
		$coverage    = array_fill_keys( $types, extrachill_analytics_conversion_outcome_zero_coverage() );
		$by_article  = array();
		$by_category = array();
		$seen        = array();

		extrachill_analytics_conversion_attribute_outcome_rows(
			$rows,
			1,
			$journeys,
			$overall,
			$by_article,
			$by_category,
			$coverage,
			$seen
		);

		$this->assertSame( 1, $overall['artist_profile_first_publish']['same_session'] );
		$this->assertSame( 1, $overall['artist_profile_first_publish']['later_session'] );
		$this->assertSame( 1, $coverage['artist_profile_first_publish']['duplicate_events'] );
		$this->assertSame( 1, $coverage['onboarding_completed']['missing_visitor_identity'] );
		$this->assertSame( 1, $coverage['local_scene_prompt_completed']['outcome_before_entry'] );
		$this->assertSame( 1, $coverage['local_scene_prompt_completed']['duplicate_events'] );
		$this->assertSame( 0, $overall['local_scene_prompt_completed']['same_session'] );
	}

	/**
	 * An outcome with no stored instrumentation is not projected as measured zero.
	 */
	public function test_absent_lifecycle_instrumentation_projects_null_counts(): void {
		$type        = 'onboarding_completed';
		$coverage    = extrachill_analytics_conversion_finalize_outcome_coverage( extrachill_analytics_conversion_outcome_zero_coverage(), true );
		$outcome_row = extrachill_analytics_conversion_outcome_row(
			extrachill_analytics_conversion_outcome_zero_bucket( array( $type ) ),
			array( $type => $coverage )
		);

		$this->assertSame( 'not_observed', $coverage['instrumentation_status'] );
		$this->assertSame( 'not_instrumented', $outcome_row[ $type ]['direct_source']['coverage_status'] );
		$this->assertNull( $outcome_row[ $type ]['direct_source']['count'] );
		$this->assertNull( $outcome_row[ $type ]['visitor_journey']['same_session_count'] );
		$this->assertNull( $outcome_row[ $type ]['visitor_journey']['later_session_count'] );
	}

	/**
	 * Existing outcome zero semantics remain backward compatible.
	 */
	public function test_absent_established_outcome_remains_measured_zero(): void {
		$type        = 'newsletter_signup';
		$coverage    = extrachill_analytics_conversion_finalize_outcome_coverage( extrachill_analytics_conversion_outcome_zero_coverage() );
		$outcome_row = extrachill_analytics_conversion_outcome_row(
			extrachill_analytics_conversion_outcome_zero_bucket( array( $type ) ),
			array( $type => $coverage )
		);

		$this->assertSame( 'measured', $outcome_row[ $type ]['direct_source']['coverage_status'] );
		$this->assertSame( 0, $outcome_row[ $type ]['direct_source']['count'] );
		$this->assertSame( 0, $outcome_row[ $type ]['visitor_journey']['same_session_count'] );
		$this->assertSame( 0, $outcome_row[ $type ]['visitor_journey']['later_session_count'] );
	}

	/**
	 * Build a stored outcome fixture.
	 *
	 * @param int    $id         Event row ID.
	 * @param string $event_type Concrete outcome event name.
	 * @param int    $user_id    Payload and stored user identity.
	 * @param string $visitor_id Anonymous visitor identity.
	 * @param int    $timestamp  Event timestamp.
	 * @return object Outcome row.
	 */
	private function outcome_row( int $id, string $event_type, int $user_id, string $visitor_id, int $timestamp ): object {
		return (object) array(
			'id'         => $id,
			'event_type' => $event_type,
			'event_data' => wp_json_encode( array( 'user_id' => $user_id ) ),
			'source_url' => '',
			'user_id'    => $user_id,
			'visitor_id' => $visitor_id,
			'ts'         => $timestamp,
		);
	}
}
