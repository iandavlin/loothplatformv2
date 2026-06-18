# Batch 03 — role-writer mystery, BB data scale, postgres state, loopback re-tests (read-only)

> Run on live. Read-only. Paste output back in one block.
> Topics: (a) who's writing looth* roles today, (b) BB data scale,
> (c) postgres state, (d) re-do strangler route check via loopback (CF
> challenged us last time), (e) read the cron-side mystery scripts.

```bash
WP_PATH=/var/www/html

# --- (a) role-writer mystery ---
# lg-patreon-stripe-poller isn't installed but looth1-4 roles still exist.
# Find what's actually writing them.

# 24. User counts per looth role — confirms the roles are populated
sudo -u looth-live wp --path=$WP_PATH user list --role=looth1 --format=count
sudo -u looth-live wp --path=$WP_PATH user list --role=looth2 --format=count
sudo -u looth-live wp --path=$WP_PATH user list --role=looth3 --format=count
sudo -u looth-live wp --path=$WP_PATH user list --role=looth4 --format=count

# 25. Grep all live plugins + mu-plugins + theme for "looth1" / "add_role" /
#     "wp_capabilities" writes — this finds whatever IS managing tier roles.
sudo grep -rn --include="*.php" -lE "(add_role|->add_role\(|wp_update_user.*role|set_role\(['\"]looth)" \
  $WP_PATH/wp-content/mu-plugins/ \
  $WP_PATH/wp-content/plugins/lg-* \
  $WP_PATH/wp-content/plugins/lg-patreon-onboard \
  $WP_PATH/wp-content/plugins/lg-looth4-expiry \
  $WP_PATH/wp-content/themes/buddyboss-theme-child-1.0.0 \
  2>/dev/null | head -30

# 26. Pull the actual code-snippets bodies for the active snippets
sudo -u looth-live wp --path=$WP_PATH db query \
  "SELECT id, name, LEFT(code, 400) AS code_head FROM wp_snippets WHERE active = 1 ORDER BY id" \
  --skip-column-names

# 27. lg-stripe-billing — is there a Slim app on disk anywhere?
sudo ls -la /var/www/billing/ /srv/lg-stripe-billing/ /var/www/html/wp-content/plugins/lg-patreon-stripe-poller/ 2>&1

# 28. Look at the patreon-onboard plugin top-level — what does it do?
sudo find $WP_PATH/wp-content/plugins/lg-patreon-onboard -maxdepth 2 -type f -name "*.php" -exec head -3 {} \; 2>/dev/null
sudo find $WP_PATH/wp-content/plugins/lg-looth4-expiry -maxdepth 2 -type f -name "*.php" -exec head -3 {} \; 2>/dev/null

# --- (b) BB data scale ---

# 29. Group + member counts (drives profile-app migration sizing)
sudo -u looth-live wp --path=$WP_PATH db query "
SELECT
  (SELECT COUNT(*) FROM wp_bp_groups)                                AS groups_total,
  (SELECT COUNT(*) FROM wp_bp_groups_members WHERE is_confirmed = 1) AS group_memberships,
  (SELECT COUNT(*) FROM wp_users)                                    AS users_total,
  (SELECT COUNT(*) FROM wp_bp_xprofile_fields)                       AS xprofile_fields,
  (SELECT COUNT(*) FROM wp_bp_xprofile_data)                         AS xprofile_data_rows,
  (SELECT COUNT(*) FROM wp_posts WHERE post_type='forum'   AND post_status='publish') AS forums,
  (SELECT COUNT(*) FROM wp_posts WHERE post_type='topic'   AND post_status='publish') AS topics,
  (SELECT COUNT(*) FROM wp_posts WHERE post_type='reply'   AND post_status='publish') AS replies,
  (SELECT COUNT(*) FROM wp_bp_activity)                              AS activity_rows
"

# 30. Group breakdown — confirm dev's inventory (9 regional + 5 auto-enroll +
#     4 conversational + 2 internal). Match against §3d coord doc.
sudo -u looth-live wp --path=$WP_PATH db query \
  "SELECT id, name, status, (SELECT COUNT(*) FROM wp_bp_groups_members WHERE group_id = g.id AND is_confirmed=1) AS member_count
   FROM wp_bp_groups g ORDER BY member_count DESC"

# 31. wp_users schema — has the business_name column been added on live?
sudo -u looth-live wp --path=$WP_PATH db query "DESCRIBE wp_users"

# --- (c) postgres state ---

# 32. Is postgres installed but unused, or installed-with-data?
sudo systemctl status postgresql --no-pager | head -20
dpkg -l | grep -E '^ii\s+postgresql' | head
sudo ls -la /var/lib/postgresql/ 2>&1
sudo -u postgres psql -c "SELECT version()" 2>&1 || echo "(psql failed — postgres may not be runnable as-is)"

# --- (d) loopback strangler-route re-test (bypasses Cloudflare) ---

# 33. Hit nginx directly via loopback. Real test of whether WP would render
#     something at these URLs today.
for path in /profile/edit /u/some-slug /p/some-slug /forums-poc/ /wp-json/looth-internal/v1/foo /directory/members /whoami; do
  code=$(curl -sk -o /dev/null -w "%{http_code}" -H "Host: loothgroup.com" "https://127.0.0.1$path")
  echo "$code  $path"
done

# --- (e) cron-side mysteries ---

# 34. Read the cache warmer
sudo cat /home/ubuntu/cache_warm.sh 2>&1 | head -50

# 35. Read the backup script (just to know what's being backed up — informs
#     rollback strategy)
sudo head -30 /usr/local/bin/backup-sites.sh 2>&1

# 36. Last archive-poc deploy from dev — what version is live actually on?
#     (cross-reference with lg-layout-v2 0.1.62 active above)
sudo grep -E "^\s*\*\s*Version" $WP_PATH/wp-content/plugins/lg-layout-v2/lg-layout-v2.php 2>/dev/null | head -1
sudo grep -E "LG_LAYOUT_V2_VERSION" $WP_PATH/wp-content/plugins/lg-layout-v2/lg-layout-v2.php 2>/dev/null | head -2
```

After paste-back:
- (a) identifies the current role-writer so we know what cutover replaces
- (b) sizes the migration/backfill workload
- (c) confirms profile-app can land its DB on this box (and whether the
      data dir is empty or has surprise contents)
- (d) gives a real answer on strangler-URL collision (CF won't be in the way)
- (e) catches any cron interactions before they bite us

No writes.
