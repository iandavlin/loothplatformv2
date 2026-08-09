# 3.9 — bb-mirror realtime sync silently stalls

Lane `mirror-sync`, 2026-08-09. Ian: *"Yes, lets do it."*

**One line:** the hub's mirror had no working safety net for 11 days, so every
dropped sync became permanent, and nothing anywhere said a word.

---

## 1. What was actually wrong

`bb-mirror-reconcile.service` runs every 10 minutes and is the **only** thing
that repairs a dropped realtime sync. On live it had been dying on an uncaught
`PDOException` every single run since **2026-07-29 23:20:00 UTC** — 3,084 runs,
11 days — and the failure is self-perpetuating:

```
2026-07-29T23:10:00Z  Reconcile window: 2026-07-29 22:59:00 UTC → now   ← healthy, 10-min rolling
2026-07-29T23:20:00Z  Reconcile window: 2026-05-31 23:59:00 UTC → now   ← bookmark rewound to 06-01
2026-07-29T23:20:11Z  PHP Fatal error: Uncaught PDOException: SQLSTATE[23503]:
                      Foreign key violation ... "reply_topic_id_fkey"
                      DETAIL: Key (topic_id)=(71685) is not present in table "topic".
```

The mechanism, in order:

1. Someone rewound `forums.sync_state.last_reconcile_at` to `1780272000`
   (2026-06-01 00:00:00 UTC) — a deliberate widening, presumably to backfill.
2. That pulled reply **71720** into the walk. Its `_bbp_topic_id` (and its
   `post_parent`) point at post **71685**, which is an **attachment**
   (`post_status=inherit`), not a topic. The FK threw.
3. `bin/reconcile.php` walked its delta with a bare `foreach`, so the throw was
   fatal. It died at row 109 of 385.
4. **The bookmark write sits after the walk.** It was never reached — so the next
   run rewalked the identical window, hit the identical row, and died identically.
   Every 10 minutes. For 11 days.
5. The ghost sweep, the `reply_count` rollup and both forum rollups sit after the
   walk too. None of them ran either.

### The measured cost

| window | replies posted | synced ≤60s | still invisible >1h |
|---|---|---|---|
| 2026-06-16 → 07-29 23:20 (net alive) | 201 | 0 (0%) | 18 (9%) |
| 2026-07-29 23:20 → 08-09 (net dead) | 70 | 59 (84%) | 4 (5.7%) |

11 of those 70 replies (**16%**) missed the realtime path. With reconcile dead,
**all 11 were rescued only when their author happened to EDIT the post** — the
sync timestamps match `post_modified_gmt` to the second, never `post_date_gmt`:

```
72589  posted 08-07 17:42:10   edited 08-08 17:29:07   synced 08-08 17:29:08   INVISIBLE 23h47m
72560  posted 08-05 16:35:18   edited 08-08 15:05:25   synced 08-08 15:05:26   INVISIBLE 2d22h30m
72569  posted 08-05 22:36:12   edited 08-06 16:41:00   synced 08-06 16:41:01   INVISIBLE 18h05m
```

A reply whose author never edits it is invisible **forever**. Four are, right
now, and have been for ~2 months (§4).

### The 0% column is a second finding

Before ~2026-07-30, **zero of 201** replies synced within 60s: the realtime
dispatch was not working at all and reconcile was silently carrying 100% of the
load at ~10-minute latency. Something in the 07-29/30 window started making the
realtime path work (84%). That is *why nobody noticed the wedge* — the two
changes landed together, so the visible symptom only appeared for the 16% that
still drop. The cause of the improvement was not chased down; it is not needed
for this fix, and it is recorded here only so the 0% is not read as a typo.

### Why nothing caught it

`systemctl status bb-mirror-reconcile` said **failed** the entire time. No watch
read that unit, and **dev2's reconcile was green throughout** — dev2's bookmark
was on its healthy 10-minute rolling window, so its walk never reached the
poisoned row. Verifying on dev2 would have shown a perfectly healthy mirror.

---

## 2. What changed

Infra only. No member-facing behaviour changes, so no flag — but a gate, per the
CRAFT law.

**`bb-mirror/lib/materializers.php`**
- New `bb_mirror_walk_ids($ids, $fn, $db)`: a throwing row is **data, not control
  flow**. Skip it, record it, keep walking, and always reach the bookmark. Rolls
  back an aborted transaction so Postgres cannot turn one bad row into a dead run
  by the "current transaction is aborted" route.
- `bb_mirror_upsert_reply()` now **self-heals both foreign keys** before hitting
  them. A missing parent topic is materialized on the spot (this is a real race —
  the fire-and-forget dispatch can deliver a reply's sync before its topic's, or
  drop the topic's entirely; healing it fixes the reply *and* the missing
  discussion). A missing parent *reply* gets one non-recursive attempt, then the
  thread link is dropped to NULL rather than the reply: **rendering at the top
  level is a cosmetic defect; not rendering at all is the defect we are here to
  kill.** The link is re-read from `_bbp_reply_to` on every later upsert, so it
  repairs itself.
- Genuinely unmirrorable rows return quietly instead of throwing. Detection of
  those belongs to the tripwire, which audits the store rather than trusting the
  writer.

**`bb-mirror/bin/reconcile.php`**
- The delta walk goes through `bb_mirror_walk_ids`, `ORDER BY ID` so the skip
  report is reproducible.
- Skips are reported loudly (capped at 10 per kind) — a silent skip is the same
  failure as a silent drop, it just fails slower.
- Ghost sweep, `reply_count` rollup and both `->exec()` rollups are each wrapped,
  so a throw in the tail cannot skip the bookmark either — that is the same wedge
  one section over.
- **Exits 0 even with skips, on purpose.** A non-zero exit parks the unit in
  `systemctl --failed` for as long as live's 4 orphaned replies exist, and a
  permanently-red unit is a dead alert channel, not an alert.

**`tools/gates/mirror-reconcile-poison-gate.php`** — gate 23/23 (re-minted 20 -> 21 -> 23 across two
rebases while this lane sat unmerged). Static +
in-process, so it cannot go DEAD for environmental reasons.

**`tools/mirror-sync/watch-mirror-sync.sh`** — the tripwire (§3).

### Proof, not assertion

The wedge was **reproduced on dev2** (which carries the identical poisoned rows)
by rewinding its bookmark to live's exact value, then fixed on the same box:

```
main's reconcile:   55 topic(s) →  0 reply(s) → Fatal: reply_parent_reply_id_fkey → exit 255
fixed reconcile:    55 topic(s) → 242 reply(s) → skipped=0 → exit 0, bookmark advanced
second run:         window 14:29:26 → now, complete            ← the wedge is gone
```

Note the red-first died on a *different* FK than live's. One poisoned row kills
the whole sweep; which constraint it happens to violate is incidental. That is
what makes it a class rather than a bug.

All 7 gate assertions were reddened by mutation
(`tools/gates/mirror-reconcile-poison-redfirst.sh`), a pass that caught **two
decorative assertions in the gate itself**: a `str_contains('last_reconcile_at')`
bookmark check that passed happily on `'last_reconcile_at_DISABLED'`, and a
fixture whose exception escaped the gate so a real regression exited 255 with no
verdict. Both fixed. `run-all.sh`: **23/23 GREEN**.

---

## 3. The tripwire

`tools/mirror-sync/watch-mirror-sync.sh` — runs on dev2, reads live **read-only**
over `ssh live-ro`, alerts to the `msg` board + `/tmp/claude-ian-action` + its own
log. Follows `watch-roundup.sh` exactly, including "a dead watch must never read
as all clear".

**An unsynced reply has NO ROW AT ALL.** Measured: 0 of 5,282 `forums.reply` rows
have a null `sync_at`, while 4 published WP replies have no row whatsoever. A lag
query over the mirror is therefore structurally blind to the failure it is meant
to catch, so every check walks **WP → mirror**.

| # | fires when | why it exists |
|---|---|---|
| 1 | `bb-mirror-reconcile.service` is failed | would have caught 2026-07-29 within 10 min |
| 2 | `last_reconcile_at` older than 30 min | catches a wedge that leaves the unit looking fine |
| 3 | a WP reply <24h old has no mirror row | invisible on the hub *right now* — loud every run |
| 4 | a long-missing reply id appears that wasn't there before | new breakage, not the known backlog |
| 5 | recent sync lag past **15** min | see below |
| 6 | mirror content older than WP's | a dropped **edit** — the row exists, so nothing else would notice |
| 7 | ssh fails, or the payload is truncated | blind ≠ clear |
| 8 | zero WP rows read | refuses to score a broken read as a clean mirror |

**Lag is measured at 5 min but alerted at 15.** Reconcile's timer is
`OnUnitActiveSec=10min`, so a dropped dispatch is *expected* to surface 5–11
minutes later — that is the safety net working. Paging on it would get this watch
muted within a day. Past 15 min the net is not catching, which is the actual
2026-07-29 failure. The 5-minute count is logged every run, so the drop rate is a
tracked number instead of a memory.

**Red-first proven, 9/9** (`tools/mirror-sync/redfirst-watch.sh`) against pinned
synthetic captures — including the real 8/7 incident in both its shapes (72589
missing at +18h, and mirrored at +23h47m) — and **two negative controls**: a
healthy mirror is silent, and a 7-minute lag is silent. The alert channel itself
was proven separately with `--selftest`; the message landed on the board.

Against live as it stands, it fires 4 true findings (§4).

---

## 4. Staged commands — nothing below has been run

### Keeper (after Ian's word)

Merge `origin/mirror-sync`. Then install the cron on dev2 — the watch is useless
un-armed:

```bash
( crontab -l; echo '*/15 * * * * /usr/bin/bash /home/ubuntu/keeper-repo/tools/mirror-sync/watch-mirror-sync.sh >/dev/null 2>&1' ) | crontab -
bash /home/ubuntu/keeper-repo/tools/mirror-sync/watch-mirror-sync.sh --selftest   # prove delivery
```

No symlink coupling: no mu-plugin added or removed, no new webroot file. A plain
pull is sufficient on both boxes.

### Ian, on live — a deploy and nothing else

```bash
lg-deploy
```

`/srv/bb-mirror` symlinks into `loothplatformv2-clean`, so the pull is the whole
deploy. Within 10 minutes the timer should produce its first clean run since
2026-07-29. Verify:

```bash
systemctl status bb-mirror-reconcile.service        # expect: inactive (dead), 0/SUCCESS
journalctl -u bb-mirror-reconcile -n 30 --no-pager  # expect: "Reconcile complete: ... skipped=4"
```

**Expect the first run to be big and slow-ish** (~92 topics + ~385 replies, plus
the ghost sweep and rollups that have not run in 11 days). It took ~20s on dev2.
It will re-stamp `sync_at` on everything it walks — expected, harmless.

Optionally clear the cosmetic failed state (the next success clears it anyway):

```bash
sudo systemctl reset-failed bb-mirror-reconcile.service
```

### Ian — a data decision, not urgent

Four replies are **unmirrorable** because their WP parentage is broken. They have
been invisible on the hub for ~2 months and the deploy will not change that:

| reply | posted | parent it claims | what that is |
|---|---|---|---|
| 71433 | 2026-06-04 | `post_parent=0` | nothing at all |
| 71720 | 2026-06-15 | 71685 | an **attachment** |
| 71722 | 2026-06-15 | 71685 | an **attachment** |
| 71728 | 2026-06-16 | 71671 | does not exist |

Either repoint `_bbp_topic_id`/`post_parent` at the right topic, or trash them.
Until then reconcile reports `skipped=4` on every run and the watch keeps them in
its known-backlog baseline (silent unless the set grows). **These are live
writes, so they are yours** — no command staged, because the right topic id is a
judgement call per reply.

---

## 5. What this does NOT fix — the honest part

**The realtime dispatch still drops ~16% of replies.** This work removes the
*permanence*, not the drop: after the deploy a missed reply is invisible for up
to ~10 minutes instead of forever. That is the difference between "the hub is
slightly behind" and "members post into the void", but it is not zero.

The dispatch is `wp_remote_post(blocking=false, timeout=1)` over TLS loopback,
and it **discards its return value entirely** — so a WP_Error, a 5xx, a refused
connection and a successful POST are indistinguishable to the caller. Nothing
retries and nothing logs.

What was measured on dev2, not guessed:
- idle box: **120/120 dispatches landed**. The transport is not inherently lossy.
- looth-dev FPM pool saturated (`pm.max_children=8`, all busy): **60/60 landed**,
  but each dispatch took ~1s instead of ~0.2s — the firing loop went from 13s to
  61s. Under load the call spends its **entire 1-second timeout**, and the margin
  before a silent drop is exactly that one second.
- `fastcgi_ignore_client_abort` is absent from nginx everywhere (default `off`),
  and `_sync.php` runs on **the same 8-worker pool** as the request that fires it.

That is a plausible mechanism, not a proven one — the drop did not reproduce on
dev2 under the load it was possible to generate there. **Do not treat it as
diagnosed.** The tripwire now logs the 5-minute lag count every 15 minutes, so
the next person gets a real rate to work against instead of an anecdote. Worth a
follow-on backlog item: a durable outbox, or simply reading the dispatch's return
value and logging failures. Note `docs/` records an outbox timer that is
**installed but deliberately disabled** on dev2 — read that history first.

---

## 6. dev2 side effects from this investigation

Recorded because they are real changes to a shared box:

- **13 ghost `forums.reply` rows were deleted from dev2's mirror** (71413, 71416,
  71417, 71418, 71419, 71420, 71421, 71422, 71431, 71443, 71451, 71464, 71472).
  A first transport probe sampled `ORDER BY sync_at ASC`, which is precisely the
  stale-ghost end of the table; `bb_mirror_upsert_reply()`'s not-a-reply branch
  deleted them, as designed. They had no WP source — the ghost sweep's own job,
  done early. Live is untouched. The probe now intersects with WP first and is
  non-destructive.
- dev2's `last_reconcile_at` was rewound to 2026-06-01 to reproduce the wedge,
  then advanced normally by the fixed run. Currently healthy.
- dev2's mirror gained 2 reply rows via the new self-heal.
- One `--selftest` alert was sent to the `msg` board and touched
  `/tmp/claude-ian-action`. Not a real anomaly.
