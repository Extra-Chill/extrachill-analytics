<?php
/**
 * PHP Fatal-Rate Alarm
 *
 * Near-real-time detection + beacon for new or spiking PHP fatal signatures.
 *
 * Why this exists (issue #48): on 2026-06-20, v0.14.0 introduced an uncaught
 * ValueError in the ec_vid cookie mint path that 500'd the entire network's
 * front door for ~3h22m. A brand-new fatal signature went 0 -> ~3,400/hr and
 * NOTHING alerted — it was caught only because a human read debug.log hours
 * later. This module closes that gap: it reads the LIVE debug.log tail on a
 * tight (~5 min) cadence, computes per-signature fatal rates, and beacons a
 * terse Discord alert the moment a new or spiking fatal appears.
 *
 * Architecture / layer rules:
 *   - ALL error detection lives here (this plugin owns the error-capture
 *     surface). It reuses the existing parser/normalizer and the existing
 *     persisted table — no second parser, no second table.
 *   - Discord delivery is delegated to the data-machine-business ability
 *     `datamachine/post-message-discord`. We never reimplement Discord HTTP.
 *     If DMB is absent the alarm degrades gracefully (logs, no beacon).
 *   - No hardcoded vendor channel id in source as a magic literal: the target
 *     channel comes from a site option / filter with a documented default.
 *
 * Scope: severity=fatal (and parse) ONLY for v1 — highest signal, lowest noise.
 *
 * @package ExtraChill\Analytics
 * @since 0.15.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cron hook fired on the tight (~5 min) cadence to evaluate the alarm.
 */
const EXTRACHILL_ANALYTICS_FATAL_ALARM_HOOK = 'extrachill_analytics_fatal_alarm_check';

/**
 * Custom cron interval name used by the alarm (independent of any other plugin).
 */
const EXTRACHILL_ANALYTICS_FATAL_ALARM_SCHEDULE = 'extrachill_analytics_five_minutes';

/**
 * Site-option key holding per-signature alarm state (last-alerted bookkeeping).
 */
const EXTRACHILL_ANALYTICS_FATAL_ALARM_STATE_OPTION = 'extrachill_analytics_fatal_alarm_state';

/**
 * Site-option key for the configured Discord channel id (overridable by filter).
 */
const EXTRACHILL_ANALYTICS_FATAL_ALARM_CHANNEL_OPTION = 'extrachill_analytics_fatal_alarm_channel';

/**
 * Register the ~5-minute cron interval used by the alarm.
 *
 * The network already fires `wp cron event run --due-now` per site every 5 min
 * via the system cron heartbeat, so a recurring WP-cron event on this interval
 * is reliably drained even on low-traffic sites.
 *
 * @param array $schedules Existing cron schedules.
 * @return array Schedules with the alarm interval added.
 */
function extrachill_analytics_fatal_alarm_cron_schedule( $schedules ) {
	if ( ! isset( $schedules[ EXTRACHILL_ANALYTICS_FATAL_ALARM_SCHEDULE ] ) ) {
		$schedules[ EXTRACHILL_ANALYTICS_FATAL_ALARM_SCHEDULE ] = array(
			// A sub-15-min interval is intentional: near-real-time fatal
			// detection is the entire point (issue #48). The network's 5-min
			// system-cron heartbeat drains it reliably.
			'interval' => 5 * MINUTE_IN_SECONDS, // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
			'display'  => __( 'Every 5 Minutes (Extra Chill fatal alarm)', 'extrachill-analytics' ),
		);
	}

	return $schedules;
}
// Sub-15-min interval is intentional (near-real-time fatal detection, #48).
add_filter( 'cron_schedules', 'extrachill_analytics_fatal_alarm_cron_schedule' ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval

/**
 * Ensure the alarm cron is scheduled.
 */
function extrachill_analytics_schedule_fatal_alarm() {
	if ( ! wp_next_scheduled( EXTRACHILL_ANALYTICS_FATAL_ALARM_HOOK ) ) {
		wp_schedule_event( time() + MINUTE_IN_SECONDS, EXTRACHILL_ANALYTICS_FATAL_ALARM_SCHEDULE, EXTRACHILL_ANALYTICS_FATAL_ALARM_HOOK );
	}
}
add_action( 'init', 'extrachill_analytics_schedule_fatal_alarm' );

/**
 * Cron callback: run a live alarm evaluation and beacon any new/spiking fatals.
 */
function extrachill_analytics_fatal_alarm_cron() {
	extrachill_analytics_evaluate_fatal_alarm( false );
}
add_action( EXTRACHILL_ANALYTICS_FATAL_ALARM_HOOK, 'extrachill_analytics_fatal_alarm_cron' );

/**
 * The fatal-rate threshold (fatals per hour) above which a signature alarms.
 *
 * A 0->nonzero NEW fatal always alarms regardless of this threshold; the
 * threshold governs re-alerting on a *known* signature that escalates.
 *
 * @return int Threshold in fatals/hour. Default 50.
 */
function extrachill_analytics_fatal_alarm_threshold() {
	/**
	 * Filter the fatal-rate alarm threshold (fatals per hour).
	 *
	 * @param int $threshold Default 50 fatals/hour.
	 */
	return (int) apply_filters( 'extrachill_analytics_fatal_alarm_threshold', 50 );
}

/**
 * Resolve the Discord channel id the alarm beacons to.
 *
 * Resolution order: site option -> filter -> documented default. The default
 * is the platform's `#extra-chill` coordination channel; it is intentionally a
 * config-resolved value, not a magic literal scattered through detection logic.
 *
 * @return string Discord channel id, or '' when unconfigured.
 */
function extrachill_analytics_fatal_alarm_channel() {
	$channel = (string) get_site_option( EXTRACHILL_ANALYTICS_FATAL_ALARM_CHANNEL_OPTION, '' );

	if ( '' === $channel ) {
		// Documented default: the platform `#extra-chill` channel.
		$channel = '1476075959806590989';
	}

	/**
	 * Filter the Discord channel id the fatal-rate alarm beacons to.
	 *
	 * @param string $channel Discord channel id.
	 */
	return (string) apply_filters( 'extrachill_analytics_fatal_alarm_channel', $channel );
}

/**
 * The lookback window (seconds) used to compute the current fatal rate.
 *
 * @return int Window in seconds. Default 1 hour.
 */
function extrachill_analytics_fatal_alarm_window_seconds() {
	/**
	 * Filter the rate-evaluation window for the fatal alarm.
	 *
	 * @param int $seconds Default HOUR_IN_SECONDS.
	 */
	return (int) apply_filters( 'extrachill_analytics_fatal_alarm_window', HOUR_IN_SECONDS );
}

/**
 * The recency horizon (seconds) within which a signature's NEWEST occurrence
 * must fall for it to be considered "still firing".
 *
 * This is the #48 fix. A fatal can be inside the (1-hour) rate window yet have
 * stopped firing minutes ago (e.g. the cookie fatal resolved at 03:56 still
 * shows in a 04:50 one-hour window). Only signatures whose last_seen is within
 * this horizon are live; anything older is RESOLVED and never alarms — so a
 * just-fixed signature does not re-alarm off persisted/window residue.
 *
 * Default 2× the 5-minute cron cadence (10 min) so one quiet cron cycle is
 * tolerated but a genuinely stopped fatal goes silent fast.
 *
 * @return int Recency horizon in seconds. Default 10 minutes.
 */
function extrachill_analytics_fatal_alarm_recency_seconds() {
	/**
	 * Filter the recency horizon that defines "still firing" for the alarm.
	 *
	 * @param int $seconds Default 10 minutes (2 * the 5-min cadence).
	 */
	return (int) apply_filters( 'extrachill_analytics_fatal_alarm_recency', 10 * MINUTE_IN_SECONDS );
}

/**
 * Severities that are eligible to alarm. v1: fatal-class only.
 *
 * @return string[] Lowercased severities.
 */
function extrachill_analytics_fatal_alarm_severities() {
	/**
	 * Filter which severities the alarm evaluates. Default fatal + parse.
	 *
	 * @param string[] $severities Lowercased canonical severities.
	 */
	return (array) apply_filters( 'extrachill_analytics_fatal_alarm_severities', array( 'fatal', 'parse' ) );
}

/**
 * Evaluate the fatal-rate alarm against the LIVE debug.log tail.
 *
 * Computes, per fatal-class signature, the number of fatals whose last_seen is
 * within the evaluation window. A signature alarms when it is NEW (never alerted
 * and freshly firing) OR when its in-window rate crosses the threshold. Already
 * alerted signatures are deduped until they go quiet and return, or escalate
 * past their last-alerted rate. Signatures whose newest occurrence is older than
 * the window are treated as RESOLVED and never alarm (this is the #48 fix: a
 * stopped fatal must not re-alarm off persisted residue).
 *
 * @param bool $dry_run When true, compute and return what WOULD alarm without
 *                      sending Discord or mutating dedupe state.
 * @return array{
 *     evaluated_at:int,
 *     window_seconds:int,
 *     threshold:int,
 *     candidates:array<int, array<string, mixed>>,
 *     alarms:array<int, array<string, mixed>>,
 *     dry_run:bool,
 *     beacon:array{attempted:bool, sent:int, channel:string, ability:bool}
 * }
 */
function extrachill_analytics_evaluate_fatal_alarm( $dry_run = false ) {
	$now            = time();
	$window         = extrachill_analytics_fatal_alarm_window_seconds();
	$since_ts       = $now - $window;
	$recency        = extrachill_analytics_fatal_alarm_recency_seconds();
	$fresh_after_ts = $now - $recency;
	$threshold      = extrachill_analytics_fatal_alarm_threshold();
	$alarm_sevs     = array_map( 'strtolower', extrachill_analytics_fatal_alarm_severities() );
	$log_path       = extrachill_analytics_php_error_log_path();

	$result = array(
		'evaluated_at'   => $now,
		'window_seconds' => $window,
		'threshold'      => $threshold,
		'candidates'     => array(),
		'alarms'         => array(),
		'dry_run'        => (bool) $dry_run,
		'beacon'         => array(
			'attempted' => false,
			'sent'      => 0,
			'channel'   => '',
			'ability'   => function_exists( 'wp_get_ability' ) && null !== wp_get_ability( 'datamachine/post-message-discord' ),
		),
	);

	// Read the live tail only — the alarm is about what is firing NOW, not the
	// persisted blend (which retains resolved history and would re-alarm #48).
	// Cap the read so an enormous unrotated log can't blow memory.
	$parsed = extrachill_analytics_parse_php_error_log( $log_path, $since_ts, 32 * MB_IN_BYTES );

	// Aggregate in-window fatal-class entries per signature.
	$agg = array();
	foreach ( $parsed['entries'] as $entry ) {
		if ( ! in_array( $entry['severity'], $alarm_sevs, true ) ) {
			continue;
		}
		if ( null === $entry['ts'] || $entry['ts'] < $since_ts ) {
			continue;
		}

		$sig = $entry['signature'];
		if ( ! isset( $agg[ $sig ] ) ) {
			$agg[ $sig ] = array(
				'signature' => $sig,
				'severity'  => $entry['severity'],
				'file'      => $entry['file'],
				'sample'    => $entry['sample'],
				'count'     => 0,
				'first_ts'  => $entry['ts'],
				'last_ts'   => $entry['ts'],
			);
		}

		++$agg[ $sig ]['count'];
		$agg[ $sig ]['first_ts'] = min( $agg[ $sig ]['first_ts'], $entry['ts'] );
		$agg[ $sig ]['last_ts']  = max( $agg[ $sig ]['last_ts'], $entry['ts'] );
	}

	$state = get_site_option( EXTRACHILL_ANALYTICS_FATAL_ALARM_STATE_OPTION, array() );
	if ( ! is_array( $state ) ) {
		$state = array();
	}

	$alarms     = array();
	$candidates = array();

	foreach ( $agg as $sig => $data ) {
		// Extrapolate an hourly rate from the in-window count. Using the window
		// length normalizes "12 in 5 min" to a per-hour rate so a short cadence
		// doesn't understate a spike.
		$span    = max( 1, $data['last_ts'] - $data['first_ts'] );
		$rate_hr = (int) round( $data['count'] * ( HOUR_IN_SECONDS / max( $span, 60 ) ) );

		// RESOLVED guard (#48 fix): a signature whose newest occurrence predates
		// the recency horizon has stopped firing. It may still sit inside the
		// 1-hour rate window (and in the persisted blend forever), but it is NOT
		// live and must never alarm. This is what keeps a just-fixed fatal from
		// re-alarming off residue.
		$is_live = $data['last_ts'] >= $fresh_after_ts;

		$prior      = isset( $state[ $sig ] ) && is_array( $state[ $sig ] ) ? $state[ $sig ] : null;
		$is_new     = null === $prior;
		$last_alert = $prior['last_alerted_at'] ?? 0;
		$last_rate  = $prior['last_alerted_rate'] ?? 0;

		// Re-arm: if the signature went quiet (last alert older than 2 windows
		// and the prior last_seen predates this window's data resuming), treat a
		// fresh occurrence as new again.
		$quiet_for   = $now - (int) $last_alert;
		$rearmed     = ! $is_new && $quiet_for > ( 2 * $window );
		$escalated   = ! $is_new && $rate_hr >= max( 1, $threshold ) && $rate_hr > ( (int) $last_rate * 2 );
		$over_thresh = $rate_hr >= max( 1, $threshold );

		$reasons = array();
		if ( $is_new ) {
			// Any brand-new fatal signature firing in-window alarms immediately
			// (0->nonzero), regardless of threshold — this is the #48 trigger.
			$reasons[] = 'new_signature';
		}
		if ( $rearmed ) {
			$reasons[] = 'returned_after_quiet';
		}
		if ( $over_thresh && ( $is_new || $rearmed || $escalated ) ) {
			$reasons[] = 'over_threshold';
		}
		if ( $escalated ) {
			$reasons[] = 'escalated';
		}

		// Only a LIVE signature can alarm; resolved residue is reported as a
		// candidate (would_alarm=false) but never beacons.
		$should_alarm = $is_live && ( $is_new || $rearmed || $escalated );

		if ( ! $is_live ) {
			$reasons = array( 'resolved_not_firing' );
		}

		$row = array(
			'signature'   => $data['signature'],
			'severity'    => $data['severity'],
			'file'        => $data['file'],
			'sample'      => $data['sample'],
			'count'       => $data['count'],
			'rate_per_hr' => $rate_hr,
			'first_seen'  => gmdate( 'Y-m-d H:i:s', $data['first_ts'] ),
			'last_seen'   => gmdate( 'Y-m-d H:i:s', $data['last_ts'] ),
			'is_new'      => $is_new,
			'is_live'     => $is_live,
			'reasons'     => $reasons,
			'would_alarm' => $should_alarm,
		);

		$candidates[] = $row;

		if ( $should_alarm ) {
			$alarms[] = $row;
		}
	}

	$result['candidates'] = $candidates;
	$result['alarms']     = $alarms;

	if ( $dry_run || empty( $alarms ) ) {
		return $result;
	}

	// Beacon + persist dedupe state for the signatures we actually alarmed on.
	$channel                     = extrachill_analytics_fatal_alarm_channel();
	$result['beacon']['channel'] = $channel;

	foreach ( $alarms as $alarm ) {
		$result['beacon']['attempted'] = true;
		$sent                          = extrachill_analytics_send_fatal_alarm_beacon( $alarm, $channel );
		if ( $sent ) {
			++$result['beacon']['sent'];
		}

		$state[ $alarm['signature'] ] = array(
			'last_alerted_at'   => $now,
			'last_alerted_rate' => $alarm['rate_per_hr'],
			'last_seen'         => $alarm['last_seen'],
		);
	}

	// Prune state entries that are stale (not seen in 7 days) to keep the option
	// from growing unbounded across the network's whole signature history.
	$cutoff = $now - ( 7 * DAY_IN_SECONDS );
	foreach ( $state as $sig => $entry ) {
		$ts = isset( $entry['last_alerted_at'] ) ? (int) $entry['last_alerted_at'] : 0;
		if ( $ts > 0 && $ts < $cutoff ) {
			unset( $state[ $sig ] );
		}
	}

	update_site_option( EXTRACHILL_ANALYTICS_FATAL_ALARM_STATE_OPTION, $state );

	return $result;
}

/**
 * Deliver a single fatal-rate alarm to Discord via the DMB ability.
 *
 * Degrades gracefully (returns false) when the DMB Discord ability is absent
 * or the channel is unconfigured.
 *
 * @param array  $alarm   One alarm row from the evaluator.
 * @param string $channel Discord channel id.
 * @return bool True when the beacon was sent successfully.
 */
function extrachill_analytics_send_fatal_alarm_beacon( $alarm, $channel ) {
	if ( '' === $channel ) {
		return false;
	}

	if ( ! function_exists( 'wp_get_ability' ) ) {
		return false;
	}

	$ability = wp_get_ability( 'datamachine/post-message-discord' );
	if ( null === $ability ) {
		return false;
	}

	$content = extrachill_analytics_format_fatal_alarm_message( $alarm );

	$response = $ability->execute(
		array(
			'channel_id' => $channel,
			'content'    => $content,
		)
	);

	if ( is_wp_error( $response ) ) {
		return false;
	}

	return is_array( $response ) && ! empty( $response['success'] );
}

/**
 * Format the terse, actionable Discord message body for a fatal alarm.
 *
 * @param array $alarm One alarm row from the evaluator.
 * @return string Message content.
 */
function extrachill_analytics_format_fatal_alarm_message( $alarm ) {
	$reason_labels = array(
		'new_signature'        => 'NEW fatal signature',
		'returned_after_quiet' => 'returned after going quiet',
		'over_threshold'       => 'over rate threshold',
		'escalated'            => 'escalating',
	);

	$reasons = array();
	foreach ( (array) $alarm['reasons'] as $reason ) {
		$reasons[] = $reason_labels[ $reason ] ?? $reason;
	}

	$header = '🚨 **PHP fatal alarm** — ' . ( $reasons ? implode( ', ', $reasons ) : 'fatal detected' );

	$lines = array(
		$header,
		'',
		'**Severity:** ' . strtoupper( (string) $alarm['severity'] ),
		'**Where:** `' . $alarm['file'] . '`',
		'**Signature:** `' . $alarm['signature'] . '`',
		'**In window:** ' . (int) $alarm['count'] . ' (~' . (int) $alarm['rate_per_hr'] . '/hr)',
		'**First seen:** ' . $alarm['first_seen'] . ' UTC',
		'**Last seen:** ' . $alarm['last_seen'] . ' UTC',
		'',
		'```' . substr( (string) $alarm['sample'], 0, 300 ) . '```',
		'Triage: `wp extrachill analytics errors --since=1h --severity=fatal`',
	);

	$message = implode( "\n", $lines );

	// Discord hard-caps a message at 2000 chars.
	if ( strlen( $message ) > 1900 ) {
		$message = substr( $message, 0, 1897 ) . '...';
	}

	return $message;
}
