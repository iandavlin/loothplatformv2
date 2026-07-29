# Deploying the outbox worker timer

*`mirror-dispatch` lane, 2026-07-29. Written before the run, rollback first.*

The outbox makes the WP→mirror dispatch durable. **The worker is what makes it
deliver**, and a `git pull` does not install a systemd unit — so without this
step the outbox records every event and nothing ever redelivers the ones the fast
path drops. That is strictly better than today (the events are at least recorded
and the divergence becomes visible) but it is **not the guarantee**.

## Rollback, stated before anything is applied

Two commands, safe at any time, safe to run twice:

```bash
sudo systemctl disable --now bb-mirror-outbox.timer
sudo rm -f /etc/systemd/system/bb-mirror-outbox.{service,timer}
sudo systemctl daemon-reload
```

Nothing else has to be undone. Stopping the timer cannot corrupt anything: the
outbox is a queue, rows simply stop being drained and go back to accumulating —
which is exactly the pre-deploy state. No data is deleted, and the rows that
built up deliver on the next tick once it is re-enabled.

## What a pull does NOT handle

| coupling | why the pull misses it |
|---|---|
| the systemd units | `/etc/systemd/system/` is not in the repo tree |
| **enabling** the timer | installing a unit does not start it |
| the mu-plugin symlink | `bb-mirror-sync.php` changed; the symlink SET is not in the repo (see keeper's standing note) |

## The order matters

**Pull first, install the timer second.** The unit's `ExecStart` points at
`/srv/bb-mirror/bin/outbox-worker.php`, which does not exist until the branch is
deployed. A timer enabled ahead of the code fails every 60 seconds and leaves
`systemctl --failed` permanently red — which destroys the alert channel this
component depends on, because the worker signals "the mirror is diverging" *by*
failing. A red unit that is always red tells nobody anything.

```bash
# 1. the code
git -C ~/loothplatformv2-clean pull --ff-only origin main     # dev2
#    (on live: lg-deploy)

# 2. confirm the worker is actually there before arming anything
test -f /srv/bb-mirror/bin/outbox-worker.php && echo OK

# 3. the units — REPO COPY IS THE SOURCE OF TRUTH (lg-deploy WARNs on drift)
sudo cp /srv/loothplatformv2/platform/systemd/bb-mirror-outbox.service \
        /srv/loothplatformv2/platform/systemd/bb-mirror-outbox.timer \
        /etc/systemd/system/
sudo systemctl daemon-reload

# 4. drift check — silence means installed == repo
for u in bb-mirror-outbox.service bb-mirror-outbox.timer; do
  diff /etc/systemd/system/$u platform/systemd/$u && echo "$u identical"
done

# 5. arm it
sudo systemctl enable --now bb-mirror-outbox.timer
systemctl list-timers bb-mirror-outbox.timer --all
```

Adjust the source path in step 3 to wherever the monorepo is checked out on the
box (`~/loothplatformv2-clean` on dev2).

## Verify it is actually delivering

Not "the timer is active" — *events are arriving*. `bin/rehearse-outbox.php`
drives a real WordPress change into the queue with the fast path blocked, so the
worker is the only thing that can heal it:

```bash
sudo -u looth-dev REHEARSE=seed    wp --path=/var/www/dev eval-file /srv/bb-mirror/bin/rehearse-outbox.php
# wait ~2 min (60s grace + a tick), then:
sudo -u looth-dev REHEARSE=status  wp --path=/var/www/dev eval-file /srv/bb-mirror/bin/rehearse-outbox.php
sudo -u looth-dev REHEARSE=cleanup wp --path=/var/www/dev eval-file /srv/bb-mirror/bin/rehearse-outbox.php
```

`seed` leaves a **real ghost** — WP row gone, mirror row still rendering. A
healthy box heals it within about two minutes and `status` reports
`mirror: gone (delivered)`. **Always run `cleanup`**; it is idempotent.

> On the site's own timezone: the worker logs in UTC (server clock). The site is
> America/New_York and Ian is UTC-7 — never quote one as another when reading
> `next_attempt_at`, which is stored in UTC.

## Reading the alarm

The worker's **exit code is the alert channel**, deliberately not `wp_mail`
(on dev2 that is a false positive — mailpit swallows it).

```bash
systemctl --failed | grep bb-mirror-outbox        # the alarm
journalctl -u bb-mirror-outbox.service -n 50      # why
```

`exit 1` means dead rows, or rows pending well past `BB_MIRROR_OUTBOX_ALERT_AGE`
(900s) — **the mirror is diverging from WordPress right now**. The same message
is banked in the mirror's own `sync_state` table under `outbox_alert`, and is
cleared automatically once the queue is healthy, so the key means "right now"
rather than "at some point in the past".

```sql
SELECT * FROM wp_bb_mirror_outbox WHERE status IN ('dead','pending') ORDER BY id;
SELECT value FROM sync_state WHERE key = 'outbox_alert';
```

A `dead` row never ages out and is never pruned — it is an open incident, and
pruning it would re-hide the failure the component exists to surface.

## PRE-MERGE REHEARSAL DROP-IN — delete it

While this branch was unmerged, `/srv/bb-mirror` did not carry the worker, so the
rehearsal ran against the worktree via a drop-in:

```
/etc/systemd/system/bb-mirror-outbox.service.d/99-premerge-rehearsal.conf
```

**It must be removed as part of this deploy**, or the timer keeps running a lane
worktree instead of the serving checkout — a file no deploy updates:

```bash
sudo rm -rf /etc/systemd/system/bb-mirror-outbox.service.d
sudo systemctl daemon-reload
systemctl cat bb-mirror-outbox.service | head -5   # confirm ExecStart is /srv/bb-mirror
```

## Never set this in production

`BB_MIRROR_OUTBOX_PORT` exists so the retry ladder and the alarm can be rehearsed
without stopping nginx or php-fpm on a shared box. Set to anything but 443 it
sends every event at a port nothing is listening on, while the outbox reports
healthy retries — the exact invisible divergence this component exists to end. It
banners loudly to stdout, STDERR and `error_log` whenever it is engaged. If you
ever see that banner outside a rehearsal, something is misconfigured.
