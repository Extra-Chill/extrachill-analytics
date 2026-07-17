<?php
/**
 * Email Send Tracking
 *
 * Logs privacy-safe wp_mail() outcomes as short-lived analytics events.
 * Provides visibility into email delivery across the network — catches
 * silent SMTP failures that would otherwise go unnoticed.
 *
 * Event types:
 * - `email_sent`   — wp_mail() returned true
 * - `email_failed` — wp_mail() returned false or wp_mail_failed fired
 *
 * @package ExtraChill\Analytics
 * @since 0.6.0
 */

defined( 'ABSPATH' ) || exit;

const EXTRACHILL_ANALYTICS_EMAIL_EVENT_RETENTION_DAYS = 30;
const EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_BATCH_SIZE   = 1000;
const EXTRACHILL_ANALYTICS_EMAIL_PRIVACY_BATCH_SIZE   = 500;

/**
 * Count recipients without retaining their addresses.
 *
 * @param string|string[] $recipients Recipient argument passed to wp_mail().
 * @return int Recipient count, capped to keep the analytics dimension bounded.
 */
function extrachill_analytics_email_recipient_count( $recipients ) {
	if ( is_array( $recipients ) ) {
		$count = count( array_filter( $recipients ) );
	} else {
		$count = count( preg_split( '/\s*,\s*/', (string) $recipients, -1, PREG_SPLIT_NO_EMPTY ) );
	}

	return min( 100, $count );
}

/**
 * Normalize an operational context to a bounded non-PII identifier.
 *
 * @param string $context Detected plugin, theme, or core context.
 * @return string Bounded context identifier.
 */
function extrachill_analytics_normalize_email_context( $context ) {
	$context = strtolower( (string) $context );
	$context = preg_replace( '/[^a-z0-9:_-]/', '', $context );

	return substr( $context ? $context : 'unknown', 0, 64 );
}

/**
 * Log successful email sends.
 *
 * Hooks into `wp_mail_succeeded` (WP 5.9+). Falls back to nothing on
 * older versions — the `wp_mail` filter + `wp_mail_failed` pair still
 * catches failures regardless.
 *
 * @param array $mail_data Data about the sent email.
 */
function extrachill_analytics_log_email_sent( $mail_data ) {
	extrachill_track_analytics_event(
		'email_sent',
		array(
			'recipient_count' => extrachill_analytics_email_recipient_count( $mail_data['to'] ?? array() ),
			'context'         => extrachill_analytics_normalize_email_context( extrachill_analytics_detect_email_context() ),
		)
	);
}
add_action( 'wp_mail_succeeded', 'extrachill_analytics_log_email_sent' );

/**
 * Log failed email sends.
 *
 * WordPress fires `wp_mail_failed` with a WP_Error when PHPMailer
 * throws an exception or SMTP authentication fails.
 *
 * @param WP_Error $error The error object with failure details.
 */
function extrachill_analytics_log_email_failed( $error ) {
	$error_data = $error->get_error_data();
	$recipients = is_array( $error_data ) && isset( $error_data['to'] ) ? $error_data['to'] : array();
	$error_code = sanitize_key( (string) $error->get_error_code() );

	extrachill_track_analytics_event(
		'email_failed',
		array(
			'recipient_count' => extrachill_analytics_email_recipient_count( $recipients ),
			'error_code'      => substr( $error_code ? $error_code : 'unknown', 0, 64 ),
			'context'         => extrachill_analytics_normalize_email_context( extrachill_analytics_detect_email_context() ),
		)
	);
}
add_action( 'wp_mail_failed', 'extrachill_analytics_log_email_failed' );

/**
 * Prune expired email events and remove direct PII fields from legacy rows.
 *
 * Each statement is capped so cleanup cannot lock a production-sized events
 * table for an unbounded period. Repeated daily runs drain any backlog.
 */
function extrachill_analytics_cleanup_email_events() {
	global $wpdb;

	$table  = extrachill_analytics_events_table();
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( EXTRACHILL_ANALYTICS_EMAIL_EVENT_RETENTION_DAYS * DAY_IN_SECONDS ) );

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded maintenance query against the plugin-owned analytics table.
		$wpdb->prepare(
			"DELETE FROM {$table} WHERE event_type IN (%s, %s) AND created_at < %s ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Code-defined table name; values are prepared.
			'email_sent',
			'email_failed',
			$cutoff,
			EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_BATCH_SIZE
		)
	);

	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded maintenance query against the plugin-owned analytics table.
		$wpdb->prepare(
			"UPDATE {$table} SET event_data = JSON_REMOVE(event_data, '$.to', '$.subject', '$.error') WHERE event_type IN (%s, %s) AND JSON_VALID(event_data) = 1 AND (JSON_EXTRACT(event_data, '$.to') IS NOT NULL OR JSON_EXTRACT(event_data, '$.subject') IS NOT NULL OR JSON_EXTRACT(event_data, '$.error') IS NOT NULL) ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Code-defined table name; values are prepared.
			'email_sent',
			'email_failed',
			EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_BATCH_SIZE
		)
	);

	// Invalid legacy payloads cannot be safely scrubbed, so remove those rows.
	$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded maintenance query against the plugin-owned analytics table.
		$wpdb->prepare(
			"DELETE FROM {$table} WHERE event_type IN (%s, %s) AND JSON_VALID(event_data) = 0 ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Code-defined table name; values are prepared.
			'email_sent',
			'email_failed',
			EXTRACHILL_ANALYTICS_EMAIL_CLEANUP_BATCH_SIZE
		)
	);
}
add_action( 'extrachill_analytics_email_cleanup', 'extrachill_analytics_cleanup_email_events' );

/**
 * Schedule daily email-event privacy cleanup.
 */
function extrachill_analytics_schedule_email_cleanup() {
	if ( ! wp_next_scheduled( 'extrachill_analytics_email_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'extrachill_analytics_email_cleanup' );
	}
}
add_action( 'init', 'extrachill_analytics_schedule_email_cleanup' );

/**
 * Register the Core personal-data exporter for user-linked email events.
 *
 * @param array $exporters Registered exporters.
 * @return array Registered exporters.
 */
function extrachill_analytics_register_email_event_exporter( $exporters ) {
	$exporters['extrachill-email-analytics'] = array(
		'exporter_friendly_name' => __( 'Extra Chill Email Analytics', 'extrachill-analytics' ),
		'callback'               => 'extrachill_analytics_email_event_exporter',
	);

	return $exporters;
}
add_filter( 'wp_privacy_personal_data_exporters', 'extrachill_analytics_register_email_event_exporter' );

/**
 * Export email outcome rows linked to the account matching an email address.
 *
 * @param string $email_address Requested email address.
 * @param int    $page          Export page, starting at one.
 * @return array Export data and completion state.
 */
function extrachill_analytics_email_event_exporter( $email_address, $page = 1 ) {
	global $wpdb;

	$user = get_user_by( 'email', $email_address );
	if ( ! $user ) {
		return array(
			'data' => array(),
			'done' => true,
		);
	}

	$page   = max( 1, (int) $page );
	$offset = ( $page - 1 ) * EXTRACHILL_ANALYTICS_EMAIL_PRIVACY_BATCH_SIZE;
	$table  = extrachill_analytics_events_table();
	$rows   = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core privacy request requires current plugin-owned rows.
		$wpdb->prepare(
			"SELECT id, event_type, event_data, created_at FROM {$table} WHERE user_id = %d AND event_type IN (%s, %s) ORDER BY id ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Code-defined table name; values are prepared.
			$user->ID,
			'email_sent',
			'email_failed',
			EXTRACHILL_ANALYTICS_EMAIL_PRIVACY_BATCH_SIZE,
			$offset
		)
	);

	$data = array();
	foreach ( $rows as $row ) {
		$event_data = json_decode( $row->event_data, true );
		$event_data = is_array( $event_data ) ? $event_data : array();
		$fields     = array(
			array(
				'name'  => __( 'Delivery result', 'extrachill-analytics' ),
				'value' => 'email_sent' === $row->event_type ? __( 'Sent', 'extrachill-analytics' ) : __( 'Failed', 'extrachill-analytics' ),
			),
			array(
				'name'  => __( 'Recorded at', 'extrachill-analytics' ),
				'value' => $row->created_at,
			),
		);

		foreach ( array( 'context', 'recipient_count', 'error_code' ) as $key ) {
			if ( isset( $event_data[ $key ] ) && '' !== (string) $event_data[ $key ] ) {
				$fields[] = array(
					'name'  => ucwords( str_replace( '_', ' ', $key ) ),
					'value' => (string) $event_data[ $key ],
				);
			}
		}

		$data[] = array(
			'group_id'          => 'extrachill-email-analytics',
			'group_label'       => __( 'Extra Chill Email Analytics', 'extrachill-analytics' ),
			'group_description' => __( 'Short-lived operational email delivery outcomes linked to this account.', 'extrachill-analytics' ),
			'item_id'           => 'email-event-' . (int) $row->id,
			'data'              => $fields,
		);
	}

	return array(
		'data' => $data,
		'done' => count( $rows ) < EXTRACHILL_ANALYTICS_EMAIL_PRIVACY_BATCH_SIZE,
	);
}

/**
 * Register the Core personal-data eraser for user-linked email events.
 *
 * @param array $erasers Registered erasers.
 * @return array Registered erasers.
 */
function extrachill_analytics_register_email_event_eraser( $erasers ) {
	$erasers['extrachill-email-analytics'] = array(
		'eraser_friendly_name' => __( 'Extra Chill Email Analytics', 'extrachill-analytics' ),
		'callback'             => 'extrachill_analytics_email_event_eraser',
	);

	return $erasers;
}
add_filter( 'wp_privacy_personal_data_erasers', 'extrachill_analytics_register_email_event_eraser' );

/**
 * Erase a bounded batch of email outcome rows linked to an account.
 *
 * The page argument is intentionally not used as an offset because deleting
 * rows shifts subsequent pages; Core repeats the callback until done is true.
 *
 * @param string $email_address Requested email address.
 * @param int    $page          Eraser page supplied by Core.
 * @return array Erasure result.
 */
function extrachill_analytics_email_event_eraser( $email_address, $page = 1 ) {
	global $wpdb;

	$page = (int) $page;
	$user = get_user_by( 'email', $email_address );
	if ( ! $user ) {
		return array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	$table   = extrachill_analytics_events_table();
	$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core erasure must delete plugin-owned personal data.
		$wpdb->prepare(
			"DELETE FROM {$table} WHERE user_id = %d AND event_type IN (%s, %s) ORDER BY id ASC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Code-defined table name; values are prepared.
			$user->ID,
			'email_sent',
			'email_failed',
			EXTRACHILL_ANALYTICS_EMAIL_PRIVACY_BATCH_SIZE
		)
	);

	if ( false === $deleted ) {
		return array(
			'items_removed'  => false,
			'items_retained' => true,
			'messages'       => array( __( 'Email analytics rows could not be erased.', 'extrachill-analytics' ) ),
			'done'           => true,
		);
	}

	return array(
		'items_removed'  => $deleted > 0,
		'items_retained' => false,
		'messages'       => array(),
		'done'           => $deleted < EXTRACHILL_ANALYTICS_EMAIL_PRIVACY_BATCH_SIZE,
	);
}

/**
 * Detect which plugin/system triggered the email.
 *
 * Walks the call stack to find the originating plugin or theme.
 * Returns a human-readable context string.
 *
 * @return string Context identifier (e.g., 'extrachill-contact', 'woocommerce', 'WordPress').
 */
function extrachill_analytics_detect_email_context() {
	$trace = debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 20 ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace

	foreach ( $trace as $frame ) {
		if ( empty( $frame['file'] ) ) {
			continue;
		}

		$file = str_replace( '\\', '/', $frame['file'] );

		// Check plugins.
		if ( preg_match( '/wp-content\/plugins\/([^\/]+)/', $file, $matches ) ) {
			$plugin_slug = $matches[1];

			// Skip ourselves and the SMTP transport plugin.
			if ( in_array( $plugin_slug, array( 'extrachill-analytics', 'easy-wp-smtp', 'fluent-smtp' ), true ) ) {
				continue;
			}

			return $plugin_slug;
		}

		// Check themes.
		if ( preg_match( '/wp-content\/themes\/([^\/]+)/', $file, $matches ) ) {
			return 'theme:' . $matches[1];
		}

		// Check mu-plugins.
		if ( false !== strpos( $file, 'wp-content/mu-plugins' ) ) {
			return 'mu-plugin';
		}
	}

	return 'WordPress';
}
