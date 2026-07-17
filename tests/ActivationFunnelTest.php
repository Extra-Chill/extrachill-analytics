<?php
/**
 * Tests for ordered activation-funnel progression.
 *
 * @package ExtraChill\Analytics
 */

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/inc/core/abilities/get-activation-funnel.php';

/**
 * Protect chronological, per-person funnel semantics.
 */
final class ActivationFunnelTest extends TestCase {
	/**
	 * Required funnel steps used by every fixture.
	 *
	 * @var string[]
	 */
	private $steps = array( 'started', 'created', 'published' );

	/**
	 * Downstream-only events never enter an ordered funnel population.
	 */
	public function test_downstream_without_upstream_is_excluded(): void {
		$rows = array(
			$this->row( 1, 'person-a', 'created', 100 ),
			$this->row( 2, 'person-a', 'published', 101 ),
		);

		$this->assertSame( array( 0, 0, 0 ), extrachill_analytics_activation_ordered_counts( $rows, $this->steps ) );
	}

	/**
	 * An out-of-order step is ignored until it occurs after its prerequisite.
	 */
	public function test_out_of_order_events_do_not_advance_early(): void {
		$rows = array(
			$this->row( 1, 'person-a', 'started', 100 ),
			$this->row( 2, 'person-a', 'published', 101 ),
			$this->row( 3, 'person-a', 'created', 102 ),
		);

		$this->assertSame( array( 1, 1, 0 ), extrachill_analytics_activation_ordered_counts( $rows, $this->steps ) );
	}

	/**
	 * Repeated lifecycle emits count a person once at each reached step.
	 */
	public function test_repeated_steps_do_not_inflate_people(): void {
		$rows = array(
			$this->row( 1, 'person-a', 'started', 100 ),
			$this->row( 2, 'person-a', 'started', 101 ),
			$this->row( 3, 'person-a', 'created', 102 ),
			$this->row( 4, 'person-a', 'created', 103 ),
			$this->row( 5, 'person-a', 'published', 104 ),
		);

		$this->assertSame( array( 1, 1, 1 ), extrachill_analytics_activation_ordered_counts( $rows, $this->steps ) );
	}

	/**
	 * Equal timestamps use the stored event ID as insertion-order tie-breaker.
	 */
	public function test_equal_timestamps_are_ordered_by_event_id(): void {
		$rows = array(
			$this->row( 12, 'person-a', 'published', 100 ),
			$this->row( 10, 'person-a', 'started', 100 ),
			$this->row( 11, 'person-a', 'created', 100 ),
		);

		$this->assertSame( array( 1, 1, 1 ), extrachill_analytics_activation_ordered_counts( $rows, $this->steps ) );
	}

	/**
	 * Independent people progress normally while unknown identity stays excluded.
	 */
	public function test_normal_progression_is_per_person_and_excludes_unknown_identity(): void {
		$rows = array(
			$this->row( 1, 'person-a', 'started', 100 ),
			$this->row( 2, '', 'started', 101 ),
			$this->row( 3, 'person-b', 'started', 102 ),
			$this->row( 4, 'person-a', 'created', 103 ),
			$this->row( 5, 'person-a', 'published', 104 ),
		);

		$this->assertSame( array( 2, 1, 1 ), extrachill_analytics_activation_ordered_counts( $rows, $this->steps ) );
	}

	/**
	 * Build one event-row fixture.
	 *
	 * @param int    $id         Stored event ID.
	 * @param string $person_id Person identity.
	 * @param string $event_type Event type.
	 * @param int    $timestamp  Event timestamp.
	 * @return object Event row.
	 */
	private function row( $id, $person_id, $event_type, $timestamp ) {
		return (object) array(
			'id'         => $id,
			'person_id'  => $person_id,
			'event_type' => $event_type,
			'ts'         => $timestamp,
		);
	}
}
