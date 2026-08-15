# dev2 disk — the offender list

**Measured 2026-08-15 by the stripe-membership lane. NOTHING DELETED.**
This is a decision list, not a cleanup. Every `du` walk was `nice`d and
`ionice`d, and load was checked before starting (0.81) and during (1.51).

**The state:** `/` is **29 GB, 26 GB used, 2.6 GB free — 91%**, over the 90%
red line. Two hours earlier swap was 0.4 GB; at the time of writing it is
**1016 MB, effectively at the 1 GB stop line**. Both point the same way.

---

## ⚠ CORRECTED — the worktree headline was wrong

My first pass here said *"79 orphaned worktree directories, 2.6 GB, git knows
nothing about them."* **That was wrong and is retracted.** I checked registration
against `keeper-repo` only; the 89 directories actually belong to **three** parent
repos — 71 to `loothplatformv2-clean`, 9 to `projects`, 9 to `keeper-repo` — and
**all 89 are properly registered. None are orphaned.**

The verified figures, after reading each directory's own gitdir pointer:

| | Count | Size |
|---|---|---|
| Live lanes — never touch | 6 | — |
| **SAFE** — merged, clean tree, nothing unpushed | **48** | **1,555 MB (1.5 GB)** |
| **HOLD** — would lose work | 35 | 1,268 MB |

So the honest worktree reclaim is **~1.5 GB, not 2.6 GB**, and it must be done with
`git worktree remove` from the correct parent — never `rm -rf`, which would leave
three registries pointing at nothing.

**Full per-directory list, with the hold reasons:**
`docs/DEV2-WORKTREE-SAFE-LIST-2026-08-15.md`.

## Everything else, biggest first

| Size | What | Kind | Who rules |
|---|---|---|---|
| **2.9 GB** | `/var/lib/mysql` | **member data** — the live databases | **Nobody deletes this.** Listed only so the 26 GB adds up. |
| **1.5 GB** | 48 finished worktrees (verified above) | worktree | **keeper** |
| **1.6 GB** | `/var/www/dev.bak-overlays-20260625-020312` | archive — a June docroot backup | **keeper** (Ian if he wants it kept) |
| **1.4 GB** | `~/.local/share` — 888 MB `claude`, 461 MB `code-server` | cache / tooling | **keeper** |
| **1.3 GB** | `~/dev1-import` | archive — carried over from retired dev1 | **Ian** — it is the dev1 history |
| **700 MB** | `~/dev26-archive-20260704` | archive | **Ian** |
| **681 MB** | `~/backups` | archive — old DB/file backups | **keeper**, with Ian on anything live-derived |
| **636 MB** | `~/.claude` (545 MB transcripts, 81 MB file-history) | log — session transcripts | **keeper** — but see the note below |
| **550 MB** | `~/projects` | **member/post work** — articles, packs, mockups | **Ian** |
| **464 MB** | `/var/lib/snapd` | cache | **keeper** |
| **367 MB** | `~/.cache` | cache | **keeper** — safe |
| **324 MB** | `/var/cache` (277 MB apt) | cache | **keeper** — `apt clean` is safe |
| **293 MB** | `/var/log` (131 MB journal) | log | **keeper** — `journalctl --vacuum` is safe |
| **236 MB** | `/var/lib/chrome-dev` | cache — headless Chrome profile | **keeper** |
| **231 MB** | `~/loothplatformv2-clean` | **the serving checkout** | **Never.** Pull-only. |
| **109+73 MB** | `~/thumbnail-gen-editor`, `-2` | archive | **Ian** |
| **46 MB** | `~/poller-old-plugin.bak-20260627-…` | archive | **keeper** |

---

## What I would clear first, and why

1. **The 48 finished worktrees — 1.5 GB, keeper's call.** Verified merged, clean
   and fully pushed. Remove with `git worktree remove` from the parent named in the
   companion list, never `rm -rf`.
2. **Caches — roughly 1.0 GB combined and genuinely safe:** `apt clean` (277 MB),
   `journalctl --vacuum` (131 MB), `~/.cache` (367 MB), snapd (464 MB, partial).
   These regenerate; nothing is lost.
3. **The June docroot backup — 1.6 GB.** Two months old and superseded. Worth one
   question to Ian before it goes, since it is a backup.

That is **~4 GB without touching anything anyone might want** — which moves 91%
back to roughly 77%. (Was stated as ~5 GB / 74% before the worktree figure was
corrected downward.)

**Held for Ian, not keeper:** `~/dev1-import`, `~/dev26-archive-…`, `~/projects`,
the thumbnail editors. These are history and member-facing work; their value is
his to judge, not ours.

---

## One caution on the transcripts

`~/.claude` is 636 MB, and 545 MB of that is session transcripts. They are
tempting because they are large and look like logs — but **this lane's own brief
was distilled out of transcripts that were about to be pruned**, and the charter
called them prunable precisely because nobody expects them to last. Clearing
them is safe for the *box* and lossy for the *fleet*. If they go, the durable
things (briefs, build notes, memory files) should be written first.

---

## How this was measured

`du -xh --max-depth=1|2` over `/home`, `/var`, `/usr`, all `nice`d and `ionice`d.
`-x` keeps each walk on one filesystem so nothing is double-counted.

For the worktrees, **each directory's own `.git` pointer** was read to find its
parent repo — the first pass assumed a single parent and got the headline wrong.
`origin/main` was then confirmed present in all three parents before any
merged/unmerged verdict was trusted.

Sizes are as reported at 2026-08-15 16:30; they drift.
