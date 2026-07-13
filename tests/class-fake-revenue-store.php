<?php
/**
 * In-memory revenue store for the ingestion ability tests.
 *
 * Mirrors the production store contract: get_snapshot / upsert (REPLACE on the
 * unique key) / delete_ids / begin / commit / rollback. upsert assigns stable
 * ids; transactions capture a restorable snapshot so rollback behavior is
 * testable. Used by IngestRevenueTest because this repository has no
 * WordPress-DB test scaffold.
 *
 * @package ExtraChill\Analytics
 */

/**
 * Fake revenue store mirroring the production contract.
 */
final class Fake_Revenue_Store {

	/**
	 * Stored snapshot rows.
	 *
	 * @var array<int, array>
	 */
	public $rows = array();

	/**
	 * Next auto-increment id.
	 *
	 * @var int
	 */
	private $next_id = 1;

	/**
	 * Restorable snapshot captured at begin().
	 *
	 * @var array<int, array>|null
	 */
	private $snapshot = null;

	/**
	 * When > 0, the Nth+1 upsert returns false to simulate a write failure.
	 *
	 * @var int
	 */
	public $fail_upsert_after = 0;

	/**
	 * Running upsert counter (for failure simulation).
	 *
	 * @var int
	 */
	public $upsert_count = 0;

	/**
	 * Return the existing rows of exactly one snapshot (objects with id, slug).
	 *
	 * @param int    $blog_id      Blog ID.
	 * @param string $period_label Period label.
	 * @param string $import_batch Batch identity.
	 * @return array<int, stdClass>
	 */
	public function get_snapshot( $blog_id, $period_label, $import_batch ) {
		$out = array();
		foreach ( $this->rows as $r ) {
			if ( $this->matches( $r, $blog_id, $period_label, $import_batch ) ) {
				$obj       = new stdClass();
				$obj->id   = (int) $r['id'];
				$obj->slug = (string) $r['slug'];
				$out[]     = $obj;
			}
		}
		return $out;
	}

	/**
	 * Insert or replace one row on the unique (blog, slug, period, batch) key.
	 *
	 * @param array $rec Record to write.
	 * @return int|false Row id, or false on simulated failure.
	 */
	public function upsert( array $rec ) {
		++$this->upsert_count;
		if ( $this->fail_upsert_after > 0 && $this->upsert_count > $this->fail_upsert_after ) {
			return false;
		}
		foreach ( $this->rows as $i => $r ) {
			if ( $this->matches( $r, $rec['blog_id'], $rec['period_label'], $rec['import_batch'] ) && (string) $r['slug'] === (string) $rec['slug'] ) {
				$rec['id']        = $r['id'];
				$this->rows[ $i ] = $rec;
				return (int) $rec['id'];
			}
		}
		$rec['id']    = $this->next_id++;
		$this->rows[] = $rec;
		return (int) $rec['id'];
	}

	/**
	 * Delete rows by primary-key id.
	 *
	 * @param array<int> $ids Row ids to delete.
	 * @return int Rows deleted.
	 */
	public function delete_ids( array $ids ) {
		$ids     = array_map( 'intval', $ids );
		$removed = 0;
		foreach ( $this->rows as $i => $r ) {
			if ( in_array( (int) $r['id'], $ids, true ) ) {
				unset( $this->rows[ $i ] );
				++$removed;
			}
		}
		$this->rows = array_values( $this->rows );
		return $removed;
	}

	/**
	 * Begin a transaction, capturing a restorable snapshot.
	 *
	 * @return bool
	 */
	public function begin() {
		$this->snapshot = array_map(
			static function ( $r ) {
				return $r;
			},
			$this->rows
		);
		return true;
	}

	/**
	 * Commit the active transaction.
	 *
	 * @return bool
	 */
	public function commit() {
		$this->snapshot = null;
		return true;
	}

	/**
	 * Roll back to the snapshot captured at begin().
	 *
	 * @return bool
	 */
	public function rollback() {
		if ( null !== $this->snapshot ) {
			$this->rows     = $this->snapshot;
			$this->snapshot = null;
		}
		return true;
	}

	/**
	 * Return full records for one snapshot (for assertions).
	 *
	 * @param int    $blog_id      Blog ID.
	 * @param string $period_label Period label.
	 * @param string $import_batch Batch identity.
	 * @return array<int, array>
	 */
	public function snapshot_records( $blog_id, $period_label, $import_batch ) {
		$out = array();
		foreach ( $this->rows as $r ) {
			if ( $this->matches( $r, $blog_id, $period_label, $import_batch ) ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	/**
	 * Sum views + revenue for one snapshot.
	 *
	 * @param int    $blog_id      Blog ID.
	 * @param string $period_label Period label.
	 * @param string $import_batch Batch identity.
	 * @return array{views:int,revenue:float}
	 */
	public function snapshot_totals( $blog_id, $period_label, $import_batch ) {
		$views = 0;
		$rev   = 0.0;
		foreach ( $this->snapshot_records( $blog_id, $period_label, $import_batch ) as $r ) {
			$views += (int) $r['views'];
			$rev   += (float) $r['revenue'];
		}
		return array(
			'views'   => $views,
			'revenue' => round( $rev, 4 ),
		);
	}

	/**
	 * Does a stored row belong to the given snapshot key?
	 *
	 * @param array  $r            Stored row.
	 * @param int    $blog_id      Blog ID.
	 * @param string $period_label Period label.
	 * @param string $import_batch Batch identity.
	 * @return bool
	 */
	private function matches( array $r, $blog_id, $period_label, $import_batch ) {
		return (int) $r['blog_id'] === (int) $blog_id
			&& (string) $r['period_label'] === (string) $period_label
			&& (string) $r['import_batch'] === (string) $import_batch;
	}
}
