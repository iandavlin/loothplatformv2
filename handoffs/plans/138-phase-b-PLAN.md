# PLAN — #138 phase B · approval auto-spins PRE-STAGED work

Lane `138-phase-b`. Branch at parity with origin/main (0/0). Nothing written yet.

Ian, 8/20 (verbatim, the ruling that ended A's probation): *"I want to keep the
server working. If a lane goes Idle waiting for me to make a decision, I want the
next work in line to start up while I'm screwing around."*

## What exists today (read, not remembered)

`tools/approved-watcher.sh` is 25 lines. Every 5 min (`approved-watcher.timer`,
`OnCalendar=*:2/5`, `User=ubuntu`) it lists open issues labeled `approved`, and for
each number **not already in `~/.approved-acks`** it appends the number, posts one
board line to `ubuntu`, and touches `/tmp/claude-ian-action`. Verified running:
28 acks on file, last board post 17:37 today. **Phase B is not there at all** — the
file says so in its header.

Two things phase B must borrow rather than reinvent:

- **The door.** `tools/lanes/spin-lane.sh` already holds the plan-mode wall
  (open + `approved`, checked against the API), per-worktree identity, the
  folder==branch check, the upstream==`origin/<lane>` repoint (75a0fb6), and the
  LANE-RULES + domain-dossier prompt assembly. Phase B calls it. It does not
  duplicate one line of it and does not add a bypass.
- **The WORKING detector.** `tools/lanes-status.sh` owns `probe_agent()` and the
  `AGENT_RE` that survived the 8/20 CLI change. Charter: *don't fork a second
  definition.* Phase B shells that same file (`--json --no-live`) and counts
  `agent == "working"` against `capacity.working_cap`.

One gap worth naming up front: **`spin-lane.sh` does not create the tmux session** —
it `exec`s claude in the foreground. Keeper has been typing `tmux new-session`
by hand. Phase B must wrap it, or the lane it spawns is invisible to `lanes`,
to the cap it just checked, and to `respawn-fleet` after a reboot.

## The shape

Phase B is a second block in the same file, after phase A's bell. Phase A's
behaviour does not change: **approval alone still only rings.**

### Global holds — checked once per tick, before any candidate

| # | Hold | Why |
|---|---|---|
| H1 | `/tmp/keeper-quiet` **or** `~/.keeper-quiet` exists | keeper's manual hold (charter names `/tmp`; `~` is added because `/tmp` does not survive a reboot and a hold that silently evaporates is worse than none) |
| H2 | `~/.fleet-manifest` non-empty **and** zero lane tmux sessions | the post-reboot signature. `respawn-fleet` is deliberately manual so a reboot never relights paid sessions unwatched — the watcher must not become the thing that does it |
| H3 | 1-min load > 4 | keeper's standing rule after dev2 hit load 15 and Ian's pages timed out |

### Per-issue guards, in refusal order

- **G1 CHARTER REQUIRED.** Exactly one `~/lane-prompts/<n>-*.md`. Zero ⇒ bell only
  (a charterless agent supervised nothing for a day, 7/27). Two or more ⇒ refuse and
  say which — `107-consent-followup.md` and `107-featured-anyone.md` are both on disk
  right now, so this branch is live, not hypothetical.
- **G2 The charter names the seat.** Lane = the charter's basename
  (`138-phase-b.md` → `138-phase-b`). Nothing is invented or slugified.
- **G3 ONE SPIN PER ISSUE EVER**, two ways:
  - `~/.autospin-log` records every issue the watcher has ever spun.
  - **Backstop:** branch `<lane>` already exists (local or `origin/`) with no
    worktree ⇒ a prior lane, parked or merged ⇒ refuse, post once, never spin.
    *This guard is load-bearing on day one:* nine parked branches are sitting there
    with open+approved issues and charters still on disk (107, 129, 132, 148, 150,
    155, 165, 170…). Without it, arming the watcher re-spins the lot.
- **G4** tmux session `<lane>` already exists ⇒ already running, skip silently.
- **G5 Worktree.** Present ⇒ its branch must equal the lane name, else refuse
  (never `checkout`, never rename). Missing ⇒ cut it exactly as keeper does —
  `git worktree add -b <lane> ~/worktrees/<lane> origin/main` then
  `git push -u origin <lane>` so the upstream **is** `origin/<lane>` — but only if
  `seats_used < seat_ceiling`. *Seats are 6/6 today*, so in practice the watcher
  will refuse to cut until one frees, and will say so.
- **G6 CAP.** WORKING count from lanes-status's own JSON, `< working_cap` (3).

### Then: dry-run, and the flip

- **Default = dry-run** (the mode file absent, or holding anything but `live`).
  Posts one board line per issue — *"WOULD SPIN `<lane>` for #n"* plus the numbers
  it decided on — announced once via `~/.autospin-dryrun`, and **does not** burn the
  one-spin-ever record, so the first real spin still happens after the flip.
- **Live** = `~/.autospin-mode` contains `live`. Keeper flips it with one `echo`
  after watching one correct decision. No code edit, no redeploy.
- **Order on a live spin: record first, spin second.** The `~/.autospin-log` line is
  written *before* tmux is touched, so a crash or a half-started session can never
  turn into a spin loop. A failed spin is then a keeper problem, reported on the
  board — which is the safe direction to fail in.

## Gate 82 — `tools/gates/approved-autospin-gate.py`

Number keeper-minted (charter). Verified free on main: CRAFT-STANDARD's table tops
out at 79 and no `82` appears in `run-all.sh`.

No network, no real seat, no claude process — gate 77's three refusals, for the same
reasons. The watcher gets one clearly-marked `LG_AW_*` override block (state dir,
prompts dir, worktree root, the lanes command, an issues fixture, the spin command,
the msg command) so the gate drives **the real script** against fixtures. That is
lanes-status's own `LG_LANES_*` precedent, put in place for gate 77.

Assertions, each named for a scar:

1. a charterless approved issue does **not** spin — and the bell still rings
2. **…and a fully-staged issue in the same run DOES spin** — the liveness half;
   an absence assertion alone is true of a box with no watcher at all
3. at `working_cap` nothing spins; at `cap-1` the same fixture spins
4. one-spin-ever: an issue in the log never spins twice
5. …and the branch-exists backstop refuses an issue that was never logged
6. dry-run posts and spins **nothing**; `live` spins — same fixture, one file apart
7. dry-run does not consume the one-spin-ever record
8. `/tmp/keeper-quiet` blocks both modes
9. two charters for one issue ⇒ refuse, and the post names both
10. worktree on the wrong branch ⇒ refuse, never repair

Plus `tools/gates/approved-autospin-redfirst.sh`: one mutation per assertion,
applied to a **snapshot copy** (never `git checkout --`), each mutation reddening
the assertion that names it, and **two no-op mutations that must stay green**.

## Files I expect to touch

| File | Note |
|---|---|
| `tools/approved-watcher.sh` | phase B |
| `tools/gates/approved-autospin-gate.py` | new — gate 82 |
| `tools/gates/approved-autospin-redfirst.sh` | new |
| `tools/gates/run-all.sh` | register 82 — ⚠ **collides** with `169-front-polish` and `emoji-picker-build`; append-only registry, keep BOTH sides on conflict |
| `docs/CRAFT-STANDARD.md` | gate 82 row — ⚠ **collides** with `169-front-polish` |
| `docs/domains/INFRA.md` | domain law: closing a domain-labeled issue updates its dossier in the same commit |
| `platform/systemd/approved-watcher.service` | its Description is about to stop being true. ⚠ `/etc/systemd/system/approved-watcher.service` is a **copy, not a symlink** (root-owned, 195 bytes) — a pull does NOT deploy it. Needs keeper `cp` + `daemon-reload`, and that goes in the flip kit, not in a merge assumption |
| `handoffs/plans/138-phase-b-PLAN.md` | this file |
| `docs/FLAGS.md` | only if the mode file reads as a flag on inspection — it is a runtime state file, outside the repo and outside gate 62's scan (new `platform/config/*`, `'enabled' =>`, `define('LG_*')`, `getenv('LG_*')`) |

**Deliberately not touched:** `tools/lanes/spin-lane.sh` (the wall stays the only
door — no bypass flag exists, deliberately) and `tools/lanes-status.sh` (forking the
WORKING detector is the one thing the charter forbids by name).

## Noticed, not fixing

- Seats are **6/6** right now. Any auto-spin needing a fresh worktree refuses until
  keeper frees one; only a pre-staged seat can spin today.
- The watcher cannot detect "Ian is actively on dev2" (the 1-lane rule). `keeper-quiet`
  is the honest lever for that, and it is manual on purpose.
- Phase A's `~/.approved-acks` and phase B's `~/.autospin-log` stay separate files:
  ringing is not spinning, and merging them would make one ack silence the other.
