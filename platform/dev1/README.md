# platform/dev1 — dev1 (keeper box) idle auto-off

**dev1-ONLY. Deploy-by-copy. Do NOT install on dev2 or live.**
dev2 has no idle daemon of its own — dev1 stops dev2 as part of its own
shutdown (the coupling block below). Installing this anywhere else would
stop boxes it must not touch.

## Files

| repo file | installs to | mode |
|---|---|---|
| `idle-shutdown-daemon.sh` | `/usr/local/bin/idle-shutdown-daemon.sh` | root:root 755 |
| `idle-shutdown.service`   | `/etc/systemd/system/idle-shutdown.service` | root:root 644 |
| `idle-hold`               | `/usr/local/bin/idle-hold` | root:root 755 |

## What the daemon does

Every 60s it checks three activity signals (TTY sessions, file activity in
`~ubuntu/.claude` + `~ubuntu/projects` + all-team Claude conversation files,
and the browser `/__heartbeat` nginx log). After 10 idle minutes on ALL
three it starts a 5-minute countdown, then stops the instance via
`aws ec2 stop-instances`. An idle-notice email goes to Ian after 60 idle
minutes. Log: `/var/log/idle-shutdown.log`.

**dev2 stop-coupling (Ian-mandated — preserve exactly):** right before
stopping itself, the daemon resolves dev2 by its stable EIP
(`34.193.244.53`, reload-proof against instance-id churn) and sends it a
stop too. Holding dev1 therefore also holds dev2. The instance id in
`STOP_CMD` is dev1's own (`i-01e54ed6c9a4ba91e`).

## Hold file: `/tmp/no-idle-shutdown`

| state | meaning |
|---|---|
| absent | armed — normal auto-off |
| numeric content | **timed hold**: epoch expiry; once past due the daemon DELETES the file and resumes checks (logged) |
| anything else (incl. empty `touch`) | indefinite hold — legacy behavior, stays until removed |

The hold is honored in both the main idle check and the countdown loop
(setting a hold cancels a running countdown).

Manage it by hand with `idle-hold`:

```
idle-hold 1h        # pause auto-off for 1 hour (also 30m, 2h30m, 90 = minutes)
idle-hold off       # remove the hold, re-arm
idle-hold           # status: armed / held until / held indefinitely
```

History: a "1 hour hold" on 2026-07-06 was a plain `touch` and kept dev1 AND
dev2 up for two days (keeper incident 7/08). Timed holds exist so that can't
recur; plain `touch` still works when an indefinite hold is truly wanted.

## Test hooks (all under /tmp)

- `idle-shutdown-dryrun` — daemon logs `WOULD start countdown` instead of counting down
- `idle-shutdown-cancel` — cancels a running countdown
- `idle-shutdown-countdown` — present while a countdown runs (epoch of scheduled stop)

## Deploy (dev1 only, by copy)

Never edit the running script in place — install to a temp name, then `mv`
(atomic), then restart:

```bash
cd <repo>/platform/dev1
sudo install -o root -g root -m 755 idle-shutdown-daemon.sh /usr/local/bin/idle-shutdown-daemon.sh.new
sudo mv /usr/local/bin/idle-shutdown-daemon.sh.new /usr/local/bin/idle-shutdown-daemon.sh
sudo install -o root -g root -m 755 idle-hold /usr/local/bin/idle-hold
sudo install -o root -g root -m 644 idle-shutdown.service /etc/systemd/system/idle-shutdown.service
sudo systemctl daemon-reload && sudo systemctl restart idle-shutdown.service
```

Verify: `systemctl status idle-shutdown` active, and
`sudo tail /var/log/idle-shutdown.log` shows a fresh
`=== idle-shutdown daemon started (... hold-ttl aware) ===` line.
