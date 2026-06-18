# Batch 04 — understand the live role-writer + collision grep (read-only)

> Run on live. Read-only. Smaller batch than usual — the big move is
> understanding what we're actually replacing.

```bash
WP_PATH=/var/www/html

# 37. Full body of mu-plugins/looth-roles.php — only file grep flagged for role writes
sudo cat $WP_PATH/wp-content/mu-plugins/looth-roles.php

# 38. Full body of code-snippet #44 "Patreon Tier Toggler"
sudo -u looth-live wp --path=$WP_PATH db query \
  "SELECT code FROM wp_snippets WHERE id = 44" --skip-column-names | head -200

# 39. lg-patreon-onboard — what does Sync Engine actually do?
sudo find $WP_PATH/wp-content/plugins/lg-patreon-onboard -type f -name "*.php" | head -20
sudo grep -lE "(add_role|->add_role|wp_update_user|set_role|->roles\s*=|add_user_meta.*looth|update_user_meta.*looth)" \
  $WP_PATH/wp-content/plugins/lg-patreon-onboard/ -r 2>/dev/null

# 40. Walk into the most likely Sync Engine file
sudo cat $WP_PATH/wp-content/plugins/lg-patreon-onboard/includes/sync-engine.php 2>/dev/null \
  || sudo cat $WP_PATH/wp-content/plugins/lg-patreon-onboard/src/sync-engine.php 2>/dev/null \
  || sudo find $WP_PATH/wp-content/plugins/lg-patreon-onboard -name "*sync*" -type f

# 41. The Patreon cron hook fires hourly per BATCH-02 cron list:
#     lgpo_patreon_auto_sync. Find its handler.
sudo grep -rn "lgpo_patreon_auto_sync" $WP_PATH/wp-content/plugins/lg-patreon-onboard/ 2>/dev/null | head

# 42. lg-looth4-expiry — what does it actually do? (short plugin)
sudo find $WP_PATH/wp-content/plugins/lg-looth4-expiry -type f -name "*.php" \
  -exec wc -l {} \; \
  -exec head -20 {} \;

# 43. Collision grep — does anything in plugins or theme hardcode the
#     strangler URLs? (We need to know before nginx intercepts them.)
sudo grep -rln --include="*.php" --include="*.js" \
  -E "(/profile/edit|/directory/members|/u/[\\\$\{a-z]|/p/[\\\$\{a-z])" \
  $WP_PATH/wp-content/plugins/lg-* \
  $WP_PATH/wp-content/mu-plugins/ \
  $WP_PATH/wp-content/themes/buddyboss-theme-child-1.0.0 \
  2>/dev/null | head -30

# 44. Where does WP redirect /u/<slug>, /profile/edit, etc. to? Follow
#     one redirect chain to see what's claiming those paths.
for path in /profile/edit /u/some-slug /directory/members; do
  echo "=== $path ==="
  curl -sk -o /dev/null -w "Status: %{http_code}\nRedirect: %{redirect_url}\n" \
    -H "Host: loothgroup.com" "https://127.0.0.1$path"
done

# 45. Is there a "patreon-connect" or vanilla Patreon plugin alongside
#     lg-patreon-onboard? (lg-* is the lookup layer; the actual Patreon
#     SDK may be a separate plugin.)
sudo ls $WP_PATH/wp-content/plugins/ | grep -iE 'patreon'
```

No writes. After paste-back:
- We'll understand exactly what writes looth* roles today
- We'll know whether the `/whoami` design can read from
  `lg-patreon-onboard`'s data model (Path B) or whether we have to ship
  the Stripe poller first (Path A)
- We'll know if any plugin hardcoded our strangler URLs

Then I can write the Path A vs B vs C analysis for Ian + coord.
