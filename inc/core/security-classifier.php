<?php
/**
 * Security Classifier
 *
 * Detects scanner / injection probe payloads in user-supplied strings so they
 * can be partitioned away from real user-behavior metrics. Used at analytics
 * insert time by callers like extrachill-search.
 *
 * Design intent: never silently drop attacks. Classify them and route to a
 * distinct event_type so the canary stays loud while real metrics stay clean.
 *
 * @package ExtraChill\Analytics
 * @since 0.7.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Classify a user-supplied search/query string against a catalog of known
 * scanner payload shapes.
 *
 * Returns the FIRST matching pattern, or null if the string looks benign.
 * Order is important: higher-confidence patterns fire first.
 *
 * @param string $term The raw search term.
 * @return array|null Classification array on match, null if benign.
 *                    Shape: [
 *                      'pattern_name'    => 'time_based_sqli',
 *                      'pattern_family'  => 'sqli',
 *                      'matched_token'   => 'sleep(',
 *                    ]
 */
function extrachill_analytics_classify_search_payload( $term ) {
	if ( ! is_string( $term ) || $term === '' ) {
		return null;
	}

	// Normalize once: HTML-entity decode (attackers double-encode), strip slashes.
	$normalized = wp_unslash( $term );
	$normalized = html_entity_decode( $normalized, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$normalized = rawurldecode( $normalized );

	$catalog = array(
		// Time-based blind SQLi — highest signal, near-zero false positives.
		array(
			'pattern_name'   => 'time_based_sqli',
			'pattern_family' => 'sqli',
			'regex'          => '/\bsleep\s*\(\s*\d+\s*\)/i',
		),
		array(
			'pattern_name'   => 'time_based_sqli',
			'pattern_family' => 'sqli',
			'regex'          => '/\bwaitfor\s+delay\b/i',
		),
		array(
			'pattern_name'   => 'time_based_sqli',
			'pattern_family' => 'sqli',
			'regex'          => '/\bDBMS_PIPE\.RECEIVE_MESSAGE\b/i',
		),
		array(
			'pattern_name'   => 'time_based_sqli',
			'pattern_family' => 'sqli',
			'regex'          => '/\bbenchmark\s*\(\s*\d+/i',
		),
		array(
			'pattern_name'   => 'time_based_sqli',
			'pattern_family' => 'sqli',
			'regex'          => '/\bpg_sleep\s*\(/i',
		),
		// Boolean / union SQLi.
		array(
			'pattern_name'   => 'boolean_sqli',
			'pattern_family' => 'sqli',
			'regex'          => '/\bunion\s+(?:all\s+)?select\b/i',
		),
		array(
			'pattern_name'   => 'boolean_sqli',
			'pattern_family' => 'sqli',
			// e.g. (select(0)from(select(sleep ...))) family — sqlmap fingerprint.
			'regex'          => '/\bselect\s*\(\s*\d+\s*\)\s*from\s*\(\s*select\b/i',
		),
		array(
			'pattern_name'   => 'boolean_sqli',
			'pattern_family' => 'sqli',
			// XOR(1*if(...)) family.
			'regex'          => "/\bxor\s*\(\s*\d+\s*\*\s*if\s*\(/i",
		),
		// XSS probes.
		array(
			'pattern_name'   => 'xss_script_tag',
			'pattern_family' => 'xss',
			'regex'          => '/<script\b/i',
		),
		array(
			'pattern_name'   => 'xss_event_handler',
			'pattern_family' => 'xss',
			'regex'          => '/\bon(?:error|load|click|mouseover)\s*=/i',
		),
		array(
			'pattern_name'   => 'xss_javascript_uri',
			'pattern_family' => 'xss',
			'regex'          => '/javascript\s*:/i',
		),
		// Path traversal.
		array(
			'pattern_name'   => 'path_traversal',
			'pattern_family' => 'lfi',
			'regex'          => '/\.\.\/\.\./',
		),
		array(
			'pattern_name'   => 'path_traversal',
			'pattern_family' => 'lfi',
			'regex'          => '/\/etc\/passwd\b/i',
		),
		// Excessive length — legitimate searches are rarely > 200 chars.
		array(
			'pattern_name'   => 'length_abuse',
			'pattern_family' => 'abuse',
			'regex'          => '/^.{300,}$/s',
		),
	);

	/**
	 * Filter the security classifier pattern catalog.
	 *
	 * Allows other plugins to add or remove detection patterns. Each entry must
	 * have keys: pattern_name (string), pattern_family (string), regex (string).
	 *
	 * @param array $catalog Array of pattern definitions.
	 */
	$catalog = apply_filters( 'extrachill_analytics_search_attack_patterns', $catalog );

	foreach ( $catalog as $entry ) {
		if ( empty( $entry['regex'] ) ) {
			continue;
		}
		if ( @preg_match( $entry['regex'], $normalized, $matches ) === 1 ) {
			return array(
				'pattern_name'   => isset( $entry['pattern_name'] ) ? (string) $entry['pattern_name'] : 'unknown',
				'pattern_family' => isset( $entry['pattern_family'] ) ? (string) $entry['pattern_family'] : 'unknown',
				'matched_token'  => isset( $matches[0] ) ? substr( (string) $matches[0], 0, 120 ) : '',
			);
		}
	}

	return null;
}

/**
 * Get the real client IP for the current request.
 *
 * Honors Cloudflare's CF-Connecting-IP header when present (set when the
 * nginx Cloudflare real-ip config is active), otherwise falls back to
 * REMOTE_ADDR. Always returns a sanitized string, possibly empty.
 *
 * @return string IP address or empty string.
 */
function extrachill_analytics_get_client_ip() {
	$candidates = array();

	if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
		$candidates[] = wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] );
	}
	if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
		$candidates[] = wp_unslash( $_SERVER['REMOTE_ADDR'] );
	}

	foreach ( $candidates as $candidate ) {
		$ip = trim( (string) $candidate );
		if ( $ip !== '' && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
	}

	return '';
}

/**
 * Get a sanitized truncated User-Agent string for the current request.
 *
 * @param int $max_length Maximum length to keep.
 * @return string
 */
function extrachill_analytics_get_user_agent( $max_length = 255 ) {
	if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
		return '';
	}
	$ua = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
	if ( strlen( $ua ) > $max_length ) {
		$ua = substr( $ua, 0, $max_length );
	}
	return $ua;
}
