<?php
/**
 * Agent-dispatch prompt contract for the PHP fatal alarm (issue #323).
 *
 * @package ExtraChill\Analytics
 */

/**
 * Keep the issue-to-cook workflow and incident evidence within transport limits.
 */
final class FatalRateAlarmPromptTest extends Extrachill_Analytics_TestCase {

	/**
	 * A realistic alarm retains all evidence alongside the current workflow.
	 */
	public function test_fix_prompt_tracks_and_cooks_issue_without_truncating_evidence(): void {
		update_site_option( 'extrachill_analytics_fatal_alarm_mention', '123456789012345678' );

		$file  = '/var/www/extrachill.com/htdocs/wp-content/plugins/extrachill-analytics/inc/abilities/reports/class-content-revenue-diagnostics-ability.php';
		$error = 'PHP Fatal error: Uncaught TypeError: array_merge(): Argument #2 must be of type array, null given in ' . $file . ':128';
		$alarm = array(
			'severity'    => 'fatal',
			'file'        => $file,
			'signature'   => hash( 'sha256', $error ),
			'count'       => 42,
			'rate_per_hr' => 168,
			'first_seen'  => '2026-05-01 12:00:00',
			'last_seen'   => '2026-05-01 12:15:00',
			'sample'      => substr( $error . ' Stack trace: #0 {main}', 0, 240 ),
			'reasons'     => array( 'new_signature', 'over_threshold', 'escalated' ),
		);

		$prompt = extrachill_analytics_format_fatal_alarm_fix_prompt( $alarm );

		$this->assertSame( 240, strlen( $alarm['sample'] ) );
		$this->assertStringStartsWith( '<@123456789012345678> 🚨 **PHP fatal alarm — investigate and fix.**', $prompt );
		foreach ( array(
			'**Workflow:**',
			'wp extrachill analytics errors --since=1h --severity=fatal',
			'`grep` the read-only plugin/theme tree for the owning repo',
			'File an issue there with fatal evidence',
			'homeboy agent-task cook',
			'one cook per issue',
			'**Goal:** root-cause and fix it.',
			'If it stopped, still file and cook any real defect',
			'**Site-breaking:** Still firing on a request path',
			'page/front-door 500s reproduced via `curl` or sustained high rate on a core path',
			'if fix is minimal, revertable and PR CI green',
			'gh api -X PUT repos/<owner>/<repo>/pulls/<n>/merge -f merge_method=squash',
			'Wait for release; dispatch extrachill-network `deploy.yml` for that one component, stating emergency',
			'Confirm signature stopped: `wp extrachill analytics errors --since=15m --severity=fatal`',
			'affected site returns 200',
			'**Otherwise PR-only:** cron/CLI-only, contained, multi-repo/migration, red CI or uncertain root cause: end at PR; operator merges',
			'Report issue/PR URLs and actions',
			'**Fatal details (from the live debug.log tail):**',
			'- Severity: FATAL',
			'- Location: `' . $alarm['file'] . '`',
			'- Signature: `' . $alarm['signature'] . '`',
			'- Rate: 42 in the last window (~168/hr)',
			'- First seen: 2026-05-01 12:00:00 UTC · Last seen: 2026-05-01 12:15:00 UTC',
			'- Sample: `' . $alarm['sample'] . '`',
		) as $expected ) {
			$this->assertStringContainsString( $expected, $prompt );
		}
		foreach ( array( 'Hard constraints', 'Do NOT', 'fix it in a worktree' ) as $outdated ) {
			$this->assertStringNotContainsString( $outdated, $prompt );
		}
		$this->assertLessThan( 1800, strlen( $prompt ) );
		$this->assertStringEndsNotWith( '...', $prompt );
	}
}
