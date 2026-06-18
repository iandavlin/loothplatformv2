# Batch 04B — Sync Engine body (read-only, tiny)

> One file, full content. Crown jewel for the poller chat's Patreon
> adapter spec. They need this verbatim.

```bash
WP_PATH=/var/www/html

# 53. Full body of the Patreon Sync Engine — the role-writer
sudo cat $WP_PATH/wp-content/plugins/lg-patreon-onboard/includes/class-lgpo-sync-engine.php

# 54. Full body of the Sync Cron (caller into the Sync Engine)
sudo cat $WP_PATH/wp-content/plugins/lg-patreon-onboard/includes/class-lgpo-sync-cron.php

# 55. Main plugin file (just to see registered hooks + activation logic)
sudo cat $WP_PATH/wp-content/plugins/lg-patreon-onboard/lg-patreon-onboard.php
```

After paste-back: this gets forwarded verbatim to coordinator → poller
chat as the Patreon adapter spec input. The adapter will mimic the
data-reading half of this without touching the Patreon API itself.
