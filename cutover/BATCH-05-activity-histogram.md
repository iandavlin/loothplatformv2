# Batch 05 — BB activity hour-of-week histogram (read-only)

> Run on live alongside BATCH-04. Read-only. Pure SELECT against
> `wp_bp_activity.date_recorded`. Purpose: cross-check the canonical
> "Sunday 22:00–02:00 ET" cutover window against actual user activity
> data instead of picking a window from gut.

```bash
WP_PATH=/var/www/html

# 46. Hour-of-week activity histogram (last 90 days).
# date_recorded is UTC; we convert to America/New_York for ET.
# Columns: day-of-week (0=Sun…6=Sat ET) | hour-of-day ET | activity_count
sudo -u looth-live wp --path=$WP_PATH db query "
SELECT
  DAYOFWEEK(CONVERT_TZ(date_recorded, '+00:00', 'America/New_York')) - 1 AS dow_et,
  HOUR(CONVERT_TZ(date_recorded, '+00:00', 'America/New_York'))         AS hour_et,
  COUNT(*) AS activity_count
FROM wp_bp_activity
WHERE date_recorded >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)
GROUP BY dow_et, hour_et
ORDER BY dow_et, hour_et
"

# 47. Same shape, but rolled up to find the absolute quietest 4-hour
# windows across the whole week — surfaces candidates without us
# having to scan the full grid by eye.
sudo -u looth-live wp --path=$WP_PATH db query "
SELECT
  DAYOFWEEK(CONVERT_TZ(date_recorded, '+00:00', 'America/New_York')) - 1 AS dow_et,
  HOUR(CONVERT_TZ(date_recorded, '+00:00', 'America/New_York'))         AS start_hour_et,
  COUNT(*) AS hourly,
  SUM(COUNT(*)) OVER (
    ORDER BY
      DAYOFWEEK(CONVERT_TZ(date_recorded, '+00:00', 'America/New_York')) - 1,
      HOUR(CONVERT_TZ(date_recorded, '+00:00', 'America/New_York'))
    ROWS BETWEEN CURRENT ROW AND 3 FOLLOWING
  ) AS rolling_4h
FROM wp_bp_activity
WHERE date_recorded >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)
GROUP BY dow_et, start_hour_et
ORDER BY rolling_4h ASC
LIMIT 12
"

# 48. Sanity check: total activity count + first/last timestamps so
# we know whether the 90-day window actually has signal. (If the site
# only logs activity for some interactions, the dataset may be sparse.)
sudo -u looth-live wp --path=$WP_PATH db query "
SELECT
  COUNT(*)                                                   AS rows_90d,
  MIN(date_recorded)                                         AS earliest,
  MAX(date_recorded)                                         AS latest,
  COUNT(DISTINCT user_id)                                    AS unique_actors_90d
FROM wp_bp_activity
WHERE date_recorded >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 90 DAY)
"
```

No writes. After paste-back:
- The hour-of-week grid lets us visually confirm Sunday 22:00–02:00 ET
  is actually quiet, or surface a better window
- The rolling-4h ranking gives a shortlist of the quietest 4-hour
  blocks for Ian to pick from
- The sanity check guards against drawing conclusions from a sparse
  dataset (if BB activity is rare, we'd want a different signal —
  maybe `wp_users.user_registered` recent activity, or nginx access
  log analysis)

Output folds into CUTOVER-PLAN.md §"Cutover window timing".
