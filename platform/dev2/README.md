# platform/dev2 — dev2 idle auto-off

**dev2-ONLY. Install once per box, by copy. NEVER install on live.**
**NEVER install on dev1** — dev1 has its own daemon in `../dev1/`.

dev2 had no auto-off at all: it ran until someone stopped it by hand. This
closes that gap. The two boxes were decoupled on 2026-07-08 (dev1 used to stop
dev2 as part of its own shutdown); nothing here reintroduces a cross-box stop.
**Each box stops only itself.**

Waking the box back up: **[WAKE.md](WAKE.md)**.

## Files

| repo file | installs to | mode |
|---|---|---|
| `dev2-idle-shutdown.sh` | `/usr/local/bin/dev2-idle-shutdown.sh` | root:root 755 |
| `dev2-idle-shutdown.service` | `/etc/systemd/system/dev2-idle-shutdown.service` | root:root 644 |
| `idle-hold` | `/usr/local/bin/idle-hold` | root:root 755 |

`idle-hold` is a near-duplicate of `../dev1/idle-hold`; the two differ only in
the box name and the paths at the top (dev1 and dev2 keep separate log and
countdown files). Kept as two files on purpose — one shared script would have
to be installed identically on both boxes, and dev1's copy is already merged
and running. Fold them together later if it earns its keep.

## Why "recency", not "presence"

buck drives dev2 from his desktop Claude as a series of **short-lived ssh
commands** — there is no session held open between them. A daemon that asked
"is anyone logged in right now?" would stop the box between two of his commands
and strand him. Everything below therefore keys on *when something last
happened*, not on what is open.

## The four signals

The box is idle-eligible only when **all four** are quiet for 45 minutes. Any
one of them busy resets the clock.

| # | signal | busy when | source |
|---|---|---|---|
| 1 | `ssh` | an sshd `Accepted` line landed in the window | `journalctl -u ssh` |
| 2 | `files` | any file mtime churned under the watched dirs | `find -mmin` |
| 3 | `web` | a real browser tab hit `/__heartbeat` | `/var/log/nginx/heartbeat.log` |
| 4 | `procs` | headless chrome / a `/tmp/ut-*` CDP harness / wp-cli / a claude runner is doing work | `/proc` |

Watched dirs (signal 2): `/home/buck/loothplatformv2`, `/home/buck/worktrees`,
`/home/ubuntu/worktrees`, `/home/ubuntu/loothplatformv2-clean`.

### Signal 3 is `/__heartbeat`, not the access log

nginx already injects a snippet into every HTML page on dev2 that POSTs
`/__heartbeat` on mousemove/click/keydown, **only while the tab is visible**,
throttled to once per 30s. It logs to its own file.

Scanning the raw access log instead would be a trap, and dev1 already fell into
it and backed out: WP cron, WP autosave, the PWA service worker, and crawlers
all hit the access log with nobody at the keyboard. `/__heartbeat` needs a
browser to run JS, so a gate-blocked bot 403 can never trip it — which also
means the "exclude raw bot 403s" requirement is satisfied by construction
rather than by a status-code filter. A bot UA filter (`BOT_UA_PAT`) covers the
residual case of something that executes JS and identifies itself as a crawler.

Note the access log could not have carried a cookie check anyway: dev2 logs in
nginx's default `combined` format, which has no cookie field, and the cookie
gate is currently commented out (`GATE OFF for cut 2026-06-20`).

### Signal 4 must not count the system timers

`lg-wp-cron.timer` fires **every 60 seconds** and runs `wp cron event run`.
`bb-mirror-reconcile` runs `wp eval-file`. A naive "is wp-cli running?" check
would find one of these on most passes and dev2 would never, ever sleep.

They are excluded **structurally, not by name**: a process only counts as
activity if its cgroup is under `user.slice`. Everything systemd starts from a
timer lives under `system.slice`. New timers are therefore excluded the day
they are added, without touching this script. `EXCLUDED_UNITS` names the known
ones as a backstop only.

```
$ cat /proc/<chrome from a CDP harness>/cgroup
0::/user.slice/user-1000.slice/session-1243.scope     <- counts
$ systemd-cgls /system.slice/lg-wp-cron.service
  -> /system.slice/lg-wp-cron.service                 <- never counts
```

### Two more traps this script is built around

**Matching processes by cmdline matches yourself.** `pgrep -f 'wp-cli'` also
matches the shell that merely *mentions* `wp-cli` — including the very command
hunting for it. Observed live: a probe reported two wp-cli processes, both of
which were the probe. So every process check verifies the target through
`/proc/<pid>/exe` (what the kernel says is executing) and treats the cmdline as
a filter only, never as proof.

**A leaked headless chrome is not work.** Chrome left behind by a crashed CDP
harness sits alive at 0% CPU forever. Counting "chrome is running" as activity
would pin the box awake indefinitely — the exact leak housekeeping keeps
reaping (five stale `/tmp/ut-*` dirs on 2026-07-09). Chrome counts only when
it is *doing* something: burning CPU since the last pass, freshly started, or
writing its CDP profile dir.

## Stopping

45 quiet minutes → a **5-minute countdown**, announced by `wall` and logged.
Any new ssh accept cancels it (checked every 10s — buck logging in mid-countdown
must never lose the box), as does any other signal going busy, a hold, or
touching `/tmp/dev2-idle-cancel`.

Then the box stops **itself**:

- the instance id comes from **IMDSv2 at stop time**, never a constant. dev2's
  id changes on every rebuild; it is on its third already.
- a **safety guard** reads the instance's `Name` tag first and **refuses to
  stop anything not named `dev2*`**. If this file is ever copied to live by
  mistake, it will not stop live. It logs and stays up.
- the box **never powers itself off**. `devgbox-cli` cannot read
  `InstanceInitiatedShutdownBehavior`, so a `poweroff` might *terminate* rather
  than stop. Only `aws ec2 stop-instances` is ever used.

Every failure path — no IMDS, no tag, wrong tag, AWS call fails — **leaves the
box running** and says so in the log. The daemon errs toward costing money, not
toward losing buck's work.

### Credentials

dev2 has **no IAM instance profile**. The stop call borrows the `devgbox-cli`
key from `/home/looth-dev/.aws/` via `Environment=` in the unit. Confirmed
sufficient:

```
$ aws ec2 stop-instances --instance-ids i-0811803ce91ecd6d3 --dry-run
DryRunOperation: Request would have succeeded, but DryRun flag is set.
```

If an instance profile is ever attached, delete the three `Environment=` lines
and the SDK will pick up IMDS credentials by itself.

## Hold file: `/tmp/no-idle-shutdown`

| state | meaning |
|---|---|
| absent | armed — normal auto-off |
| numeric content | **timed hold**: epoch expiry; once past due the daemon DELETES the file and resumes (logged) |
| anything else (incl. empty `touch`) | indefinite hold — stays until removed |

Honored in both the idle check and the countdown loop, so setting a hold
cancels a running countdown. Manage it with `idle-hold`:

```
idle-hold 3h        # before an unattended job. also 30m, 2h30m, 90 (= minutes)
idle-hold off       # re-arm
idle-hold           # status
```

A plain `touch` of the hold file kept dev1 *and* dev2 up for two days in July
2026. Timed holds exist so that cannot recur.

## Tests

| script | reads | run it |
|---|---|---|
| `tools/dev2-idle/selftest.sh` | nothing real — fixtures + a mocked `aws` | anywhere, incl. dev1. 22 assertions, ~60s |
| `tools/dev2-idle/live-signals.sh` | the real journal, nginx log, and `/proc` | on dev2, before arming and after each rebuild. ~4 min |

Neither can stop a box: the first mocks `aws` entirely, the second only ever
invokes the daemon as `--check-once`.

`live-signals.sh` is the one that catches a **box-specific** regression — a
renamed unit, a moved heartbeat log, a new 60s timer that would pin the box
awake. Run it after any rebuild.

## Test hooks

Every path in the script is env-overridable, so it can be exercised against
fixtures without touching a real box. `tools/dev2-idle/selftest.sh` uses them.

| hook | effect |
|---|---|
| `/tmp/dev2-idle-dryrun` | countdown completes, guard runs, stop is **logged, never executed** |
| `/tmp/dev2-idle-cancel` | cancels a running countdown |
| `/tmp/dev2-idle-countdown` | present while a countdown runs (epoch of the scheduled stop) |
| `SSH_SOURCE=file:<path>` | read ssh accepts as epochs from a file instead of the journal |
| `AWS_BIN`, `INSTANCE_ID_OVERRIDE` | mock the stop path |
| `IDLE_THRESHOLD_MIN`, `COUNTDOWN_SECS`, `INTERVAL` | shrink the clocks |

`dev2-idle-shutdown.sh --check-once` prints the four signals and exits 0 if
idle-eligible, 1 if busy. It never stops anything. Safe to run any time:

```
$ sudo dev2-idle-shutdown.sh --check-once
RESULT: idle_eligible=no   ssh=busy files=quiet web=busy procs=busy  (threshold=45m)
```

## Deploy (dev2 only, by copy)

Never edit the running script in place — install to a temp name, then `mv`
(atomic), then restart:

```bash
cd <repo>/platform/dev2
sudo install -o root -g root -m 755 dev2-idle-shutdown.sh /usr/local/bin/dev2-idle-shutdown.sh.new
sudo mv /usr/local/bin/dev2-idle-shutdown.sh.new /usr/local/bin/dev2-idle-shutdown.sh
sudo install -o root -g root -m 755 idle-hold /usr/local/bin/idle-hold
sudo install -o root -g root -m 644 dev2-idle-shutdown.service /etc/systemd/system/dev2-idle-shutdown.service
sudo systemctl daemon-reload
sudo systemctl enable --now dev2-idle-shutdown.service
```

Verify:

```bash
systemctl is-active dev2-idle-shutdown          # active
sudo dev2-idle-shutdown.sh --check-once         # prints the four signals
sudo tail /var/log/dev2-idle-shutdown.log       # fresh "=== dev2-idle-shutdown started ..."
idle-hold                                       # "No hold -- auto-off is ARMED (dev2 ...)"
```

First install is worth watching for one cycle with the dry-run hook on
(`sudo touch /tmp/dev2-idle-dryrun`): the daemon will log `WOULD STOP` at the
end of a countdown instead of stopping, so a mistuned signal shows up as a log
line rather than as a box that vanished under someone. Remove the file to arm.
