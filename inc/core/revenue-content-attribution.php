<?php
/**
 * Persisted content ownership for revenue rows.
 *
 * @package ExtraChill\Analytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Return a normalized host-relative path suitable for the Network resolver.
 *
 * @param string $value CSV path or URL.
 * @return string|null Host-relative path, or null when it cannot be resolved.
 */
function extrachill_analytics_revenue_frontend_path( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value || 0 === strpos( $value, '//' ) ) {
		return null;
	}

	$parts = wp_parse_url( $value );
	if ( false === $parts ) {
		return null;
	}
	$path = isset( $parts['path'] ) ? (string) $parts['path'] : $value;
	if ( '' === $path || '/' !== $path[0] ) {
		return null;
	}

	return '/' === $path ? '/' : '/' . trim( $path, '/' ) . '/';
}

/**
 * Resolve unresolved content paths through the Network batch contract.
 *
 * This deliberately runs before the ingestion transaction. An incomplete scan
 * must never partially replace a deterministic snapshot.
 *
 * @param array<int,string> $paths Unique host-relative paths.
 * @return array{success:bool,results:array,error:string}
 */
function extrachill_analytics_revenue_resolve_network_paths( array $paths ) {
	if ( empty( $paths ) ) {
		return array(
			'success' => true,
			'results' => array(),
			'error'   => '',
		);
	}
	if ( ! function_exists( 'ec_resolve_frontend_paths' ) ) {
		return array(
			'success' => false,
			'results' => array(),
			'error'   => 'The Extra Chill Network batch path resolver is unavailable or outdated; snapshot replacement was aborted.',
		);
	}

	$results = array();
	$chunk   = array();
	$bytes   = 0;
	foreach ( $paths as $path ) {
		$path_bytes = strlen( $path );
		if ( count( $chunk ) >= 100 || ( ! empty( $chunk ) && $bytes + $path_bytes > 64000 ) ) {
			$scan = ec_resolve_frontend_paths( $chunk );
			if ( ! is_array( $scan ) || 'complete' !== ( $scan['scan']['status'] ?? '' ) ) {
				return array(
					'success' => false,
					'results' => array(),
					'error'   => 'The Network path resolver returned an incomplete scan; snapshot replacement was aborted.',
				);
			}
			foreach ( $scan['results'] as $result ) {
				$results[ $result['path'] ?? '' ] = $result;
			}
			$chunk = array();
			$bytes = 0;
		}
		$chunk[] = $path;
		$bytes  += $path_bytes;
	}
	if ( ! empty( $chunk ) ) {
		$scan = ec_resolve_frontend_paths( $chunk );
		if ( ! is_array( $scan ) || 'complete' !== ( $scan['scan']['status'] ?? '' ) ) {
			return array(
				'success' => false,
				'results' => array(),
				'error'   => 'The Network path resolver returned an incomplete scan; snapshot replacement was aborted.',
			);
		}
		foreach ( $scan['results'] as $result ) {
			$results[ $result['path'] ?? '' ] = $result;
		}
	}

	return array(
		'success' => true,
		'results' => $results,
		'error'   => '',
	);
}

/**
 * Read persisted content metadata in its owning site context.
 *
 * @param int      $blog_id Owning content blog.
 * @param callable $callback Metadata reader.
 * @return mixed Callback result.
 */
function extrachill_analytics_revenue_with_content_blog( $blog_id, $callback ) {
	$switched = false;
	if ( $blog_id > 0 && function_exists( 'switch_to_blog' ) && get_current_blog_id() !== (int) $blog_id ) {
		$switched = (bool) switch_to_blog( $blog_id );
	}
	try {
		return call_user_func( $callback );
	} finally {
		if ( $switched && function_exists( 'restore_current_blog' ) ) {
			restore_current_blog();
		}
	}
}
