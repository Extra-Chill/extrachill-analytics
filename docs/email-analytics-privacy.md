# Email Analytics Privacy

Email delivery tracking stores short-lived operational outcomes only. New `email_sent` rows contain `recipient_count` and a bounded `context`; new `email_failed` rows additionally contain a sanitized `error_code`. Recipient addresses, subjects, message bodies, headers, attachments, provider payloads, credentials, and free-form failure messages are not stored.

Email outcome rows are retained for 30 days. The plugin schedules `extrachill_analytics_email_cleanup` daily and deletes at most 1,000 expired rows per run. The same bounded cleanup removes the legacy JSON keys `to`, `subject`, and `error` from up to 1,000 remaining rows per run. Invalid legacy JSON cannot be safely scrubbed and is deleted in bounded batches. Repeated daily runs drain a larger backlog without an unbounded table operation.

WordPress Core's personal-data tools expose email analytics rows that are linked through the events table's existing `user_id`. Exports are paginated in batches of 500 and contain only delivery result, timestamp, context, recipient count, and failure code. Erasure deletes user-linked email outcome rows in batches of 500. Rows emitted by background or anonymous sends have no user linkage and are handled by automatic retention rather than email-address lookup; no recipient hash or other persistent indirect identifier is stored.

After deployment, operators should confirm the daily cron exists and run the cleanup hook once if immediate legacy cleanup is required:

```sh
wp cron event list --fields=hook,next_run_relative | grep extrachill_analytics_email_cleanup
wp cron event run extrachill_analytics_email_cleanup
```

Run the cleanup hook repeatedly only when each prior invocation has completed. Each invocation is intentionally bounded, so production tables with a legacy backlog may require multiple runs. A database backup should follow the site's normal operational policy before manually accelerating destructive cleanup.
