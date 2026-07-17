<?php
/**
 * WPDB fixture for email privacy tests.
 *
 * @package ExtraChill\Analytics
 */

/**
 * Capture prepared queries and return configured database results.
 */
final class Email_Privacy_Wpdb_Fixture {
	/**
	 * Configured mutation results.
	 *
	 * @var array<int, int|false>
	 */
	public $query_results = array();

	/**
	 * Per-query database errors, including empty success values.
	 *
	 * @var string[]
	 */
	public $query_errors = array();

	/**
	 * Per-query callbacks used to simulate concurrent lock replacement.
	 *
	 * @var callable[]
	 */
	public $query_callbacks = array();

	/**
	 * Configured result pages.
	 *
	 * @var array<int, array<object>>
	 */
	public $rows = array();

	/**
	 * Snapshot maximum event ID.
	 *
	 * @var int
	 */
	public $max_id = 0;

	/**
	 * Captured prepared queries.
	 *
	 * @var string[]
	 */
	public $queries = array();

	/**
	 * Configured database error.
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * Substitute basic wpdb placeholders for query assertions.
	 *
	 * @param string $query SQL with placeholders.
	 * @param mixed  ...$args Placeholder values.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		$index = 0;
		return preg_replace_callback(
			'/%[sdf]/',
			function ( $placeholder ) use ( $args, &$index ) {
				$value = $args[ $index++ ];
				return '%s' === $placeholder[0] ? "'" . addslashes( (string) $value ) . "'" : (string) (int) $value;
			},
			$query
		);
	}

	/**
	 * Record a mutation and return its configured result.
	 *
	 * @param string $query Prepared SQL.
	 * @return int|false
	 */
	public function query( $query ) {
		$this->queries[]  = $query;
		$this->last_error = (string) array_shift( $this->query_errors );
		$callback         = array_shift( $this->query_callbacks );
		if ( is_callable( $callback ) ) {
			$callback();
		}
		return array_shift( $this->query_results );
	}

	/**
	 * Record a scalar query and return the snapshot maximum.
	 *
	 * @param string $query Prepared SQL.
	 * @return int
	 */
	public function get_var( $query ) {
		$this->queries[] = $query;
		return $this->max_id;
	}

	/**
	 * Record a row query and return its configured page.
	 *
	 * @param string $query Prepared SQL.
	 * @return array<object>|false
	 */
	public function get_results( $query ) {
		$this->queries[] = $query;
		return array_shift( $this->rows );
	}
}
