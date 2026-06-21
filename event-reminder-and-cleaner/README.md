# LG Event Reminders & Auto-Archive

First-party WordPress plugin. On publish/update of an `event` CPT post it creates a
**single** FluentCRM scheduled campaign timed to the event start, subscribes list 4
("Event Reminder Email List") + the "Event Reminders" tag, and auto-archives events
1 day after they end.

Was previously an **untracked** raw directory on dev2
(`/var/www/dev/wp-content/plugins/event-reminder-and-cleaner/`, file name had spaces:
`event reminder and cleaner.php`). Now tracked here, renamed to
`event-reminder-and-cleaner.php` (no spaces).

## 3.4.0 — duplicate-campaign fix (idempotency)

Root cause of the subscriber double:
- **Lost linkage** — dedupe relied solely on post-meta `_lg_er_fcrm_campaign_id`. A DB
  reload (or any meta loss) wiped it → the prior scheduled campaign was left stranded and
  a new one created = duplicate. The slug embedded `time()`, so it couldn't dedupe by slug
  either.
- **Already-sent** — re-saving an event after its reminder sent cleared the meta and
  created a fresh scheduled campaign → it sent again.

Fix:
- Stable, queryable slug `lg-event-reminder-<postid>-<YmdHi>` (deterministic, no `time()`).
- `lg_er_find_event_campaigns()` finds **all** campaigns for an event by slug prefix
  (`LIKE 'lg-event-reminder-<id>-%'`) + the legacy post-meta pointer; `delete_campaign_for_post`
  removes every scheduled/draft/paused one (never sent/working).
- Past-window guard in `create_or_update`: if the computed send time is already in the past,
  do not (re)create — only a re-save of a current/past event lands there. A genuinely
  rescheduled event (time moved forward) re-schedules normally.

## Deploy to dev2 (serve wiring — keeper)

Other platform code is served to dev2 from the serve clone of this repo. To wire this plugin
the same way (raw dir → symlink), the keeper does, on dev2:

```bash
# 1. point WP at the renamed file in the serve clone
PLUGDIR=/var/www/dev/wp-content/plugins/event-reminder-and-cleaner
sudo rm -rf "$PLUGDIR"
sudo ln -s <serve-clone>/platform/plugins/event-reminder-and-cleaner "$PLUGDIR"

# 2. the active_plugins option still references the OLD spaced filename — update it,
#    or WP silently deactivates the plugin after the rename:
#    'event-reminder-and-cleaner/event reminder and cleaner.php'
#      -> 'event-reminder-and-cleaner/event-reminder-and-cleaner.php'
mysql -ulooth_dev_user -p'***' looth_import -e "
  UPDATE wp_options
  SET option_value = REPLACE(option_value,
    'event-reminder-and-cleaner/event reminder and cleaner.php',
    'event-reminder-and-cleaner/event-reminder-and-cleaner.php')
  WHERE option_name='active_plugins';"
```

Until that cutover, dev2 runs the fixed file in place (deployed 3.4.0 content over the
original spaced filename so it stays active).

## Live cleanup of pre-existing duplicate / orphan SCHEDULED campaigns (Ian/keeper-held)

Run on **live** only (dev2 is already clean — verified 0 duplicates / 0 orphans). Never
touch `sent`/`working`; only `scheduled`/`draft`/`paused`.

```sql
-- A) DETECT duplicate scheduled campaigns (same event id, >1):
SELECT CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(slug,'-',4),'-',-1) AS UNSIGNED) AS post_id,
       GROUP_CONCAT(id ORDER BY id) ids, COUNT(*) n
FROM wp_fc_campaigns
WHERE status IN ('scheduled','draft','paused') AND slug LIKE 'lg-event-reminder-%'
GROUP BY post_id HAVING n > 1;

-- B) DETECT orphan scheduled campaigns (event post missing or not published):
SELECT c.id, c.slug, c.status
FROM wp_fc_campaigns c
WHERE c.status IN ('scheduled','draft','paused') AND c.slug LIKE 'lg-event-reminder-%'
  AND NOT EXISTS (
    SELECT 1 FROM wp_posts p
    WHERE p.ID = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(c.slug,'-',4),'-',-1) AS UNSIGNED)
      AND p.post_status = 'publish' AND p.post_type = 'event');
```

For each duplicate event, keep the newest campaign id and delete the rest; delete all
orphans. For every campaign id `$X` you remove, also delete its recipient rows:

```sql
DELETE FROM wp_fc_campaign_emails WHERE campaign_id = $X;
DELETE FROM wp_fc_campaigns       WHERE id = $X AND status IN ('scheduled','draft','paused');
```

Preferred: run the bundled idempotent cleanup script (`tools/cleanup.php` pattern used on
dev2 — see the lane report) which derives post_id from the slug, keeps one per event, and
never touches sent/working. After the 3.4.0 plugin is live, every event save self-heals to a
single campaign, so this is a one-time sweep.
