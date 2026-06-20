# Incident (b) — double event reminder: root cause + recommended fix (NOT applied)
**Lane:** email-audit · branch `email-audit` · **report only, no code changed** (Ian: "don't fix, report")
**Owner to apply:** Showrunner / sheets-bridge owner · **Date:** 2026-06-20

## What it is
The duplicate "event reminder" is the **Showrunner asset-reminder** sent by the Google Apps Script
`sendReminders_()` in `platform/mu-plugins/loothdev-sheets-bridge.apps-script.gs.txt` (the reference
copy pasted into the Apps Script editor). It emails the showrunner (CC Max @14/7d, CC Ian @7d/overdue)
at 21 / 14 / 7 / overdue days before air date.

**Important:** it sends via **GmailApp from Google** — it does **NOT** go through wp_mail / FluentSMTP.
So the `simulate_emails = yes` kill-switch on live does **nothing** to it; it can still send (and double-
send) regardless of the WordPress-side mail state. Any "safe re-enable" must treat this as a separate
channel.

## Root cause (why it double-sends)
Dedup today is a **soft 6-day cooldown** read from the `Last Reminder Sent` column, with the stamp written
**after** the send (non-atomic read → send → stamp). That fails in several ways, any of which duplicates:
1. **Multiple daily triggers.** Each Google account that opens the sheet and runs "Install Daily Reminder
   Trigger" creates its *own* `sendRemindersLive` time-trigger. `installDailyTrigger()` only deletes the
   *current user's* matching triggers, so it can't dedupe across owners → N owners = N daily fires.
2. **Manual + auto collision.** "Send Reminders Now (live)" run the same morning as the daily trigger,
   before the cooldown stamp commits → both send.
3. **At-least-once trigger semantics.** Apps Script can fire a time trigger more than once.
4. **Cooldown is coarse.** It's a single timestamp per row, not per urgency tier, and non-atomic, so any
   two near-simultaneous executions both pass the check.

Not the materializer: `lg-article-materializer` re-materializes events on save but never touches reminder
state — confirmed in `archive-poc/api/v0/_materialize.php`. It's exonerated.

## Recommended fix (for the owner to apply in the Apps Script editor)
Three changes to `sendReminders_()` / `installDailyTrigger()`:
1. **Script lock** — serialize executions so concurrent/duplicate/at-least-once runs can't overlap:
   ```js
   const lock = LockService.getScriptLock();
   const haveLock = lock.tryLock(0);
   if (!dryRun && !haveLock) { Logger.log('skipping - concurrent run'); return; }
   try { /* ...existing loop... */ } finally { if (haveLock) lock.releaseLock(); }
   ```
2. **Hard per-(episode, tier) sent ledger** — replace the 6-day cooldown with a one-and-done key in
   Script Properties, so each episode+urgency sends at most once ever:
   ```js
   const rowKey  = String(row[CONFIG.COL.CALENDAR_EVENT_ID - 1]
                   || (title + '|' + Utilities.formatDate(airDate, TIMEZONE, 'yyyy-MM-dd')));
   const sentKey = 'rem:' + rowKey + ':' + urgency;
   const props   = PropertiesService.getScriptProperties();
   if (!dryRun && props.getProperty(sentKey)) continue;     // already sent this tier
   // ...after GmailApp.sendEmail(...):
   props.setProperty(sentKey, new Date().toISOString());
   ```
3. **Single-owner trigger guard** — document that exactly ONE Google account owns the daily trigger;
   optionally store the installer's email in Script Properties and refuse `installDailyTrigger()` from a
   different account. (The lock + ledger already make duplicate triggers harmless, but this stops them at
   the source.)

Effect: even if two triggers fire or a manual run races the daily one, the lock serializes them and the
ledger makes the second a no-op. Worth keeping the existing `Last Reminder Sent` / `Reminder Count`
column writes for the human-readable audit trail.

## Verify (owner, after pasting)
- Run "Send Reminders Now (dry run)" twice — same preview both times, no sends.
- Run "Send Reminders Now (live)" twice back-to-back — the 2nd sends nothing (ledger hit).
- Confirm exactly one `sendRemindersLive` trigger exists (Apps Script → Triggers), delete extras.

## Note
A patched copy of `sendReminders_` was prototyped and verified to splice cleanly into the source, then
**reverted** per instruction — nothing is committed as a code change. This report is the deliverable; the
owner applies the change in the Apps Script project (it lives in Google, not deployable from the box).
Cross-ref: full path map + safe re-enable plan in `docs/EMAIL-AUDIT.md`; incident (a) in `docs/INCIDENT-A-FIX.md`.
