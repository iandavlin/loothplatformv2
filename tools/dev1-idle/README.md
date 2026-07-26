# dev1 idle-shutdown: worker awareness

`platform/dev1/idle-shutdown-daemon.sh` decides whether dev1 may power itself
off. Until 2026-07-26 it decided that from **side effects only** — TTY idle
time, file mtimes, a browser heartbeat. A lane grinding through one long tool
call produces none of those, so "busy" and "idle" were indistinguishable. At
02:59 that morning the box powered off mid-work and took every lane with it;
the only thing that had been keeping it up was someone remembering to run
`idle-hold`.

The daemon now also asks the process table directly (`check_worker_activity`).

## What counts as activity

| signal | counts when | bound |
|---|---|---|
| attached tmux client | any client attached on any socket | none — a terminal is literally connected |
| worker binary (`claude`) | its process **tree** burns ≥ `WORK_JIFFIES` per 60s | must actually burn CPU; presence alone is not enough |
| tmux pane | foreground command is not a bare shell, and the tree burns CPU | same threshold |
| engine (`chrome`) | **only alongside a live worker** | a leaked engine alone can never hold the box up |

Last real work is stamped and carried forward for `IDLE_THRESHOLD` minutes, so
the gaps where a lane is just waiting on the API do not read as idle. Once work
genuinely stops, the stamp goes stale and the box is free to shut down.

## Why presence alone is not "busy"

Measured on dev1 while writing this:

```
parked claude      54 jiffies/min      <- doing nothing, still not free
working claude    348 jiffies/min
working claude   1160 jiffies/min
leaked chrome       0 jiffies/min      <- alive 17 min, zero CPU, forever
```

`WORK_JIFFIES` defaults to 100/min, between parked and working. It is compared
**per process tree, never summed**, because five parked lanes at 54 each would
otherwise add up to "busy" permanently and the box would never shut down.

## Traps (each is a bug that was live at some point)

- **`pgrep -f claude` matches the hunting command's own cmdline.** Workers are
  matched on comm (`pgrep -x`) and confirmed through `/proc/<pid>/exe`.
- **cgroup slice does not discriminate on dev1.** On dev2, timer units sit in
  `system.slice` and human work in `user.slice`. On dev1 the claude workers are
  children of code-server and sit in `system.slice` too — filtering on
  `user.slice` would drop every real worker on this box.
- **A leaked headless chrome sits at 0% CPU forever.** Engines never count alone.
- **A parked claude still burns ~1 jiffy/s.** See above.

## Selftest

```
tools/dev1-idle/selftest.sh        # ~4 min
tools/dev1-idle/selftest.sh -k     # keep the sandbox for inspection
```

It runs **the real daemon script**, not a copy — every path and command is
redirected into a throwaway sandbox through the `IDLE_*` environment overrides
the script now reads. A forked test copy would drift from what ships; this
cannot. systemd starts the unit with a clean environment, so production always
gets the defaults.

Safety properties, all enforced by the script itself:

- `IDLE_STOP_CMD` is a mock that writes a marker file — **no instance can stop**.
- `IDLE_MAIL_CMD` is a mock — the idle email never reaches Ian.
- tmux fixtures live on a **private socket** (`tmux -L lg-idle-selftest`), never
  the shared default socket every lane runs on; only that private server is
  ever killed.
- fixtures are cheap: a renamed `/bin/bash` (spin loop) and a renamed
  `/bin/sleep`. **No real `claude` is ever spawned as a fixture** — doing that on
  the shared socket is what killed dev1's tmux server on 2026-07-26.
- RAM headroom is checked before any fixture starts; the run aborts below 400MB.
- No live path is touched or read: not `/var/log/idle-shutdown.log`, not
  `/tmp/no-idle-shutdown`, not `/run/idle-shutdown`, not the systemd unit. The
  live daemon keeps running unmodified throughout.

The run finishes with a read-only calibration pass that measures the *real*
claude/chrome processes on the box and prints where each falls relative to the
threshold. That is the number that actually matters, and it manufactures no
load to get it.

## Deploying

Monorepo law: `/usr/local/bin/idle-shutdown-daemon.sh` is a copy of the repo
file. Deploy is a checksum-verified copy plus a restart:

```
sudo install -m 755 platform/dev1/idle-shutdown-daemon.sh /usr/local/bin/idle-shutdown-daemon.sh
md5sum platform/dev1/idle-shutdown-daemon.sh /usr/local/bin/idle-shutdown-daemon.sh   # must match
sudo systemctl restart idle-shutdown
sudo tail -f /var/log/idle-shutdown.log     # the banner names the worker config
```

`idle-hold` remains the manual override and is unchanged.
