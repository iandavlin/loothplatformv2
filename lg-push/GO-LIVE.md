# Web-push — status + go-live plan

**Author:** buck/auto · **Updated:** 2026-06-06 · **For:** coordinator (ubuntu)

## BUILT + self-tested (all STAGED — nothing live)

In `/srv/lg-push/` (owner buck:loothdevs):
- `vendor/` — `minishlink/web-push v10.1.0`.
- `lib.php` — sender core. Reads VAPID root-only (`/etc/lg-vapid/*.b64url`) + WP DB via PDO
  (creds parsed from wp-config). `lgpush_send()` signs+delivers to `wp_lg_push_subscriptions`,
  returns `{total,sent,failed,pruned}`, prunes 404/410. `lgpush_count()`.
- `send-test.php` — CLI (`--count/--dry/--title/--body/--url/--user/--endpoint`).
- `run-queue.php` — **drainer** (root cron). Drains pending `wp_lg_push_queue` rows → send → mark sent.
- `run-event-reminders.php` — **Trigger B** (root cron). post_type=event, start meta
  **`_events_start_date_and_time_`** (confirmed), reminds N min before start (default 60),
  dedupes via postmeta `_lg_push_reminded`. `--dry` lists candidates.
- `staged/lg-push-publish.php` — **Trigger A** publish hook, STAGED (NOT in mu-plugins).
  On fresh publish of a Hub content CPT it ENQUEUES into `wp_lg_push_queue` (never touches VAPID).

DB: `wp_lg_push_queue` table created (id, payload, target_type, target_id, status, attempts, created_at, sent_at).

### Self-test results (run as root)
- Sender: stale sub → attempted → expired → pruned (`{total:1,sent:0,failed:1,pruned:1}`); count now 0.
- Drainer: test row → drained → marked `sent` with timestamp.
- Event reminder `--dry`: 9 published events found, 0 within the 60-min window (correct).
- All files `php -l` clean. Queue cleared back to 0 rows after testing.

## GO-LIVE (reserved coordinator/Ian — none done)
1. **Live-delivery test:** subscribe a real device, `sudo php send-test.php` → confirm `sent:1`
   (the only branch not yet proven — all subs tested so far were stale).
2. **VAPID read access:** sender runs from **root cron** only, so no perms change needed if cron is root.
3. **Trigger A:** copy `staged/lg-push-publish.php` → `/var/www/dev/wp-content/mu-plugins/lg-push-publish.php`
   AND confirm the `$cpts` list matches the real Hub content CPT slugs (currently
   `post-imgcap, post-type-videos, sponsor-post, event` — TODO verify).
4. **Cron (root):** `* * * * *` (or every few min) → `run-queue.php`; same cadence → `run-event-reminders.php`.
5. **Client flip:** `push.js` is already live with the real VAPID public key + PUSH_ENABLED; verify, then
   the publish/reminder flow lights up once cron + the mu-plugin are in.

Nothing auto-activates as shipped: no cron, no mu-plugin placed, no perms changed.
