# Disk audit — 2026-08-16

Ian's ask: disk hit 93%; keeper's sweep took it to 90%. This audit took it to **84%**.

| | before | after |
|---|---|---|
| used | 26G (90%) | 24G (84%) |
| free | 2.9G | 4.8G |
| worktree dirs | 39 | 6 |

**~1.9G reclaimed.** Nothing was deleted that is not provably recoverable. Every removal
below has a restore path in `/home/ubuntu/worktree-rescue-2026-08-16/RECOVERY.md`.

---

## Part 1 — the 39 holdout worktrees → 6

### Four measurement traps, any one of which would have destroyed work

1. **`git log --oneline --not --remotes=origin` drops the implicit HEAD.** Given only
   negative refs, git does not add HEAD as the positive ref — it printed **empty for all
   five salvage candidates regardless of their content**. The true counts were 11, 2, 1, 1, 1
   commits existing nowhere on origin. Correct form: `git log HEAD --not --remotes=origin`.
2. **`git log @{u}..HEAD` returns 0 when a branch has no upstream at all.**
   `messages-group-names` read as "nothing unpushed"; its single commit was on **no origin
   ref anywhere**. A `wc -l` over a failed command is a silent zero.
3. **The registration check was aimed at one repo.** These worktrees hang off **three**
   parents: `loothplatformv2-clean` (27), `keeper-repo` (8), `/home/ubuntu/projects` (8).
   The 8 first reported as ORPHAN were registered the whole time.
4. **"DELETE-SAFE" means content is recoverable, not that deletion is safe.** The first
   table marked all four **live lanes** deletable — including this lane's own worktree, and
   they are the four largest dirs (434M). Liveness was then cross-checked three ways: `tmux ls`,
   `~/.fleet-manifest`, and `/proc/*/cwd`.

### Executed (33 dirs)

- **13 clean + provably on origin** — removed via `git worktree remove` (refuses on dirt: a second net).
- **9 whose only content was a dead lane's `HANDOFF.md`/`LANE-TASK.md`** — 80K of notes copied to
  the rescue dir first; that 80K was the only thing blocking ~250M.
- **11 salvaged first**, then removed:
  - pushed to origin: `rescue/map-infinite`, `rescue/messages-group-names`,
    `rescue/reply-images-6`, `rescue/social-poster-meta` (SHAs re-verified present **after** deletion).
  - `consolidate-poller` carried **502 uncommitted lines across 6 poller files** — captured as a
    verified patch, not lost.
  - all 8 dirty trees captured as `git apply --check`-verified patches + verbatim untracked copies.
- `slug-backfill` blocked on two **root-owned `__pycache__`** files (residue from a gate run under
  sudo); it had already been unregistered by the failed removal, so its 2006 files were each proven
  present as blobs in the object store before deletion.

### Left alone deliberately

The 4 live lanes, plus `emoji-picker-build` and `front-page-editor` — keeper-registered, parked,
both carrying uncommitted work. Deleting a parked lane's dir would strip what `claude --continue`
resumes into. **Keeper's call, not mine.**

### Finding: `/home/ubuntu/projects` points at an unreachable repo

Its remote is `iandavlin/looth-platform` via ssh alias **`github-looth`, which has no entry in
`~/.ssh/config` on this box** — it cannot be pushed to or fetched from. Eight worktrees hung off it,
including `bespoke-cutover` with **11 real commits of hub work** that could not be pushed anywhere.

Mitigating (found in part 3): `dev26-archive-20260704/bespoke-cutover-FULL.bundle` contains
`a7e5233` and verifies as "a complete history". So those commits exist twice — but **both copies
are on this one disk**.

---

## Part 2 — `/var/www` (3.1G) and `~/.local` (1.4G)

### Executed

- **`~/.local/share/claude/versions`**: 3 versions, only `2.1.233` mapped by any process (the 4 live
  lanes). Removed `2.1.226` + `2.1.228` — **580M**, re-downloadable.
- **code-server logs**: 10 session dirs → newest 2 kept. **152M → 29M**.

### Proposed (not executed — needs Ian)

- **`/var/www/dev.bak-overlays-20260625-020312` — 1.6G, half of `/var/www`.** A real copy (distinct
  inodes, not hardlinks), unreferenced by any nginx/php config. Named for 2026-06-25, but files
  inside were written until **2026-07-04**, so something kept writing to it for nine days after the
  snapshot. **The identical directory also exists on live** (live's image was built from dev2), so
  this is 1.6G on *both* boxes. Recommend deleting on dev2; live is Ian's.
- npm caches: `/var/www/dev/.npm/_cacache` 240M ×2 (one inside the backup) — regenerable.
- `~/.cache/ms-playwright` 295M — **keep**, the e2e gates need it.

### Finding: 73M of source is publicly downloadable on live

`/.well-known/` is a **documented gate-exempt, anonymous** path (it must be — Android Digital Asset
Links verifies the TWA APK with an anonymous fetch). Someone parked build artifacts there:
**57 zips + 33 PHP source files renamed `.txt`** on live, including a full repo archive
(`looth-platform-<sha>.zip`, 1,481 files, carrying `.claude/settings.local.json`).

On live the dotfile deny is `location ~ /\.(?!well-known/) { deny all; }` — i.e. everything hidden
**except** this directory, and live has no gate.

Severity is bounded — scanned and **clean**:
- no live-key shapes (`sk_live_`/`rk_live_`/`AKIA`/`whsec_`/private keys) in the 33 `.txt` files;
- no `DB_PASSWORD`/`AUTH_KEY` literals — all 22 `DB_PASSWORD` hits are `$_ENV[...]` reads or
  references to the secret file `/etc/lg-poller-db`; **zero quoted literals**;
- one `sk_test_` (sandbox) in the poller zip — consistent with Ian's standing "keep sandbox keys" ruling.


#### Sharper on dev2: `.well-known` serves raw PHP **source**

`/var/www/dev/.well-known/Provision.php` exists on both boxes, and the two boxes leak it
differently — the same file, opposite mechanisms:

- **dev2** has `location ^~ /.well-known/ { ... }`. The `^~` prefix stops the `\.php$` regex
  location from matching, so there is no PHP handler and the file falls through as a **static
  asset**. Measured: `GET /.well-known/Provision.php` returns **all 8,665 bytes of raw source**,
  byte-for-byte identical to the file. This is the `/archive-api/v0/*.php` disclosure class
  (trap: *any `.php` without its own nginx location is served as a static file*) recurring one
  directory over — and the existing V2-PHP-SOURCE gate is blind here too.
- **live** has no `^~` block, so the regex PHP handler matches and the file executes. It is a pure
  class definition (`final class Provision`, no top-level statements, no `$_GET`/`$_POST`), so it
  emits **nothing**. No RCE, no disclosure — on live this file is inert.

So the disclosure surface is: **dev2 → PHP source + `.txt` sources; live → the 33 `.txt` sources
and 57 zips.**

**Caveat on reachability, stated plainly:** dev2's conf documents `/.well-known/` as gate-exempt
and anonymous, and that is the basis for calling it externally reachable — it was **not** measured
from outside. A loopback request bypasses the gate (proved by the control: `/hub/` returned 200
with no cookie), and an EIP hairpin does not route from inside the VPC, so this box cannot
self-test as an external client. Confirming it needs one fetch from off-box.

So: **source/IP disclosure, not a credential leak** — and crawlable, which ties to backlog 40.
No public curl was used to test this (Cloudflare bot-challenges it into a misleading 403); the
conclusion is read off live's own nginx config. A loopback curl on dev2 proved nothing —
the control (`/hub/` returning 200 with no cookie) showed loopback bypasses the gate.

---

## Part 3 — the archives (2.7G). **Inventory only. Nothing touched.**

| archive | size | what it is | newest file | duplicated? |
|---|---|---|---|---|
| `dev1-import` | 1.3G | dev1's history: `projects/` 738M (14,341 files) + `claude-transcripts/` 583M (394 transcripts) | 2026-07-28 | **No.** Distinct from `~/projects` (11,205 files, live to today) and from `~/.claude/projects` (dev2's own). |
| `dev26-archive-20260704` | 700M | a 2026-07-04 decommission capture: `backups/` 681M, `bespoke-cutover-FULL.bundle` 18M, `worktree-diffs/`, `docs/`, MANIFEST | 2026-07-04 | **`backups/` yes — a full duplicate of `~/backups`.** Identical file list by name+size (15/15) and identical md5 on the 571M dump. The bundle is **unique and valuable** (only archived copy of `bespoke-cutover`). |
| `backups` | 681M | pre-flip DB dumps: `wp-pre-flip` 571M, `pre-topoff looth_import` 63M, poller prod tgz 23M, pg dumps | 2026-06-28 | **Yes — the same 15 files sit inside `dev26-archive-20260704/backups`.** |

### Decision table for Ian

| # | decision | reclaim | risk |
|---|---|---|---|
| 1 | Delete **one** of the two identical `backups` copies (keep `~/backups`, drop the nested copy — or vice versa) | **681M** | Low — byte-verified duplicate, one copy survives. |
| 2 | Delete `/var/www/dev.bak-overlays-20260625-020312` on dev2 | **1.6G** | Low — unreferenced by any config; 6 weeks stale. |
| 3 | Same directory **on live** (deploy is yours) | 1.6G on live | Low, but a live write — your call. |
| 4 | Move `dev1-import` (1.3G) off the working disk to S3/Glacier | **1.3G** | Keeps the history, costs a restore step. It is dev1's only surviving copy. |
| 5 | Clear the `.well-known` build artifacts (57 zips + 33 `.txt` on live; **plus `Provision.php` on dev2, which serves as raw source**) | 73M + ends the disclosure | Must keep `assetlinks.json` (326B, verified present) — TWA APK verification depends on it. |
| 6 | `.npm` caches (167M + 240M×2) | ~400M | None — regenerable, costs one slow rebuild. |

If 1, 2, 4 and 6 are taken, the disk goes to roughly **60%**.

### The structural point

`dev1-import`'s 583M of transcripts and the `bespoke-cutover` bundle are **irreplaceable and exist
only here**. The 681M duplicate is the opposite — pure waste. Both sit on the same 29G root volume
with no off-box copy. The cheap durable fix is a bucket, not a bigger disk.
