# Dev / live split — measured classification + proposed mechanism

**Lane:** dev-live-split · **Date:** 2026-08-01 · **Status:** PLAN — awaiting keeper approval.
Nothing has been moved. No history rewritten. This document is measurement + recommendation.

Ian, 8/1: *"We need to separate out stuff for making the dev2 server do things. That stuff
doesn't need to ride to live."*

---

## 0. Headline: two findings that are live RIGHT NOW

The split is worth doing on noise grounds alone, but the measurement turned up **present-tense
anonymous exposure of dev material on production**. These are not hypotheticals and they are
not the wp-content class the 7/31 dev-files gate already covers.

### F1 — `/v2/` serves the whole lg-layout-v2 tree, including `tests/`, to ANONYMOUS requests

`platform/nginx/dev2.loothgroup.com.conf` (the conf live actually runs — it is symlinked into
live's `sites-enabled/`) contains:

```nginx
location ^~ /v2/ {          # comment above it claims "(static, gated)"
    alias /srv/lg-layout-v2/;
    autoindex off;
    location ~ \.php$ { return 403; }
}
```

There is **no auth directive inside that location** — verified by extracting the block and
grepping it. The comment saying "gated" is wrong. Measured on live (`54.146.118.131`,
`siteurl=https://loothgroup.com`), loopback + `--resolve`, no cookies of any kind:

| path | code | bytes |
|---|---|---|
| `/robots.txt` (control — proves probe alive) | 200 | 385 |
| `/v2/mockup/render-pipeline.html` | **200** | 37,912 |
| `/v2/mockup/editor-pipeline.html` | **200** | 28,641 |
| `/v2/tests/fixtures/simple-article.json` | **200** | 1,522 |
| `/v2/tests/fixtures/edge-cases.json` | **200** | 3,248 |
| `/v2/src/Plugin.php` | 403 | — (PHP deny holds) |
| `/v2/README.md` | 404 | — (file genuinely absent, not a block) |

The PHP deny and `autoindex off` hold. Everything else in the tree serves by path.

**Why the existing guard misses it:** the 7/31 dev-files rules are anchored to
`^/wp-content/(plugins|mu-plugins)/…`. `/v2/` is a different URL prefix reaching the *same
files* through a `/srv` alias. The guard was written one directory over from this. (Same shape
as the `/archive-api/v0/*.php` source-disclosure miss.)

**On Cloudflare:** a public fetch returns 403 *"Just a moment…"* — that is a **bot challenge,
not access control**. A human browser passes it and gets the file. Do not read that 403 as
protection.

### F2 — `/footer-mockups/` is anonymously readable on live

Live's docroot has `/var/www/dev/footer-mockups -> /home/ubuntu/projects/footer-mockups`
(a real, populated directory on live, dated May 18). Anonymous GET `/footer-mockups/` → **200,
4,543 bytes**, serving the mockup picker page.

This one is **not repo-managed** — it is a hand-made symlink to a `projects/` dir on live, so
no amount of repo restructuring removes it. It needs a symlink removal on live (Ian's hands).

### F3 — dev2's nginx vhost is DETACHED from the repo, so dev2 is weaker than live

Chasing why `/wp-content/plugins/lg-weekly-digest/dev/run-suite.sh` answers **200 (7,255B)
on dev2** but 404 on live turned up the cause. On live:

```
/etc/nginx/sites-enabled/dev2.loothgroup.com.conf -> …/platform/nginx/dev2.loothgroup.com.conf
```

On dev2 the same path is a **regular file owned by `ubuntu`** — not a symlink. It is 55 diff
lines from the tracked conf, and the delta is one-sided:

- **48 lines the tracked conf has and dev2 lacks** — the *entire* 7/31 dev-files guard block.
- **5 lines dev2 has and the tracked conf lacks** — the `lane-preview` include block
  (`account-following`, `weekly-digest-recap`, `thread-follow`).

Verified against dev2's *running* config (`nginx -T`, 3,774 lines, positive control
`server_name`×10 so the search is known-live): `previs` → 0 hits, `__tests__` → 0 hits,
`wp-content/(?:plugins` → 0 hits. **The guard is not loaded on dev2.**

**Root cause, proven not inferred:** `tools/preview/lane-preview.sh:54` runs
`sudo -n sed -i` against `$VHOST`. GNU `sed -i` writes a temp file and renames over the
path, which **replaces a symlink with a regular file** (it does not pass `--follow-symlinks`).
The tool's own header at line 22 claims it "never re-points a symlink" — it believes it is
non-invasive. The first lane-preview run detached the vhost, and every tracked nginx change
since has silently not reached dev2.

This inverts the usual assumption: **live is guarded, dev2 is not** — and dev2 is the box
live's image is built from. It also means reachability results measured on dev2 do not
transfer to live in either direction, which is why §5's gate targets an explicit origin.

**Do not "fix" this with `sed -i --follow-symlinks`** — that would edit the file *through*
the symlink and leave `~/loothplatformv2-clean` dirty, which `lg-deploy` refuses to run
against. The correct fix is to stop editing the vhost at all: give the tracked conf a
permanent `include /etc/nginx/snippets/lane-previews/*.conf;` and have `lane-preview.sh`
only add/remove snippet files in that directory. The symlink is then never touched.

Sequencing matters: restoring the symlink *before* that change would drop three lanes' live
preview includes. Order is (1) add the glob include to the tracked conf, (2) rework
`lane-preview.sh`, (3) restore dev2's symlink, (4) reload.

---

## 1. LIVE-SERVING, derived from what live actually symlinks

Not assumed. Enumerated by walking `/var/www /etc/nginx /srv /etc/systemd /usr/local/bin /opt
/etc/cron.d` on live and resolving every symlink back into
`/home/ubuntu/loothplatformv2-clean`. **122 symlinks**, landing in **14 top-level roots**:

```
archive-poc   bb-mirror   bug-report   events   lg-layout-v2   lg-legacy-import
lg-patreon-stripe-poller  lg-shared  lg-snippets  lg-weekly-digest  membership-pages
platform      profile-app  webroot
```

Shapes differ, and the difference is what makes some safe and some not:

- **Whole-directory symlinks into a docroot** — `lg-layout-v2`, `lg-legacy-import`,
  `lg-snippets`, `lg-weekly-digest` (→ `wp-content/plugins/`),
  `lg-patreon-stripe-poller` (→ `wp-content/mu-plugins/`). Everything in the tree is under the
  docroot. **This is the risk shape.**
- **Whole-directory symlinks into `/srv`** — `archive-poc`, `bb-mirror`, `events`,
  `lg-shared`, `membership-pages`, `profile-app`. nginx aliases only a `web/` or `api/`
  subdirectory, so sibling `bin/` and `tests/` are *structurally* unreachable. Safer by design.
  **Except `/v2/`, which aliases `lg-layout-v2` at its root — see F1.**
- **File-by-file symlinks** — `platform/mu-plugins/*` (33), `platform/nginx/*`,
  `webroot/*`, `bug-report/lg-bug-report.php`, `platform/bin/lg-deploy`. Only named files
  exist on the live side; adding a file to these dirs does not expose it. Safest shape.

## 2. LIVE-OPERATOR — in the deploy, not served, and must NOT be exiled

The charter warned that some "dev" material is genuinely needed on live. It is:

| path | why live needs it | evidence |
|---|---|---|
| `platform/nginx/`, `platform/fpm/`, `platform/systemd/` | `lg-deploy` diffs these and reloads nginx/FPM | `platform/bin/lg-deploy:41-52` |
| `webroot/install-symlinks.sh` | `lg-deploy` runs it every deploy | `lg-deploy:38` |
| `platform/bin/lg-deploy` | **is** the deploy | `/usr/local/bin/lg-deploy` symlink |
| `docs/runbooks/` | read on live during a deploy; 4 files present on live | listed on live |
| `cutover/` | Ian runs these on live | 23 files, present on live |
| `membership-pages/web/test-checklist.php` | **named "test" but is a real member-facing route** — admin-gated QA checklist, deliberately routed in `strangler-membership.conf:54` | `/test-checklist` → 200 |

`test-checklist.php` is exactly the trap: a category-based sweep on the word "test" would have
deleted a live route. To anon it renders "This page isn't available yet" (the admin gate holds).

**Copy-deployed, NOT symlinked — would be wrongly exiled by a symlink-only heuristic:**

| repo tree | on live as |
|---|---|
| `lg-apps` | `wp-content/plugins/lg-apps` (**real dir**) |
| `lg-anonymous-authors` | `wp-content/plugins/lg-anonymous-authors` (**real dir**) |
| `lg-recent-posts-widget` | `wp-content/plugins/lg-recent-posts-widget` (**real dir**) |
| `event-reminder-and-cleaner` | `wp-content/plugins/event-reminder-and-cleaner` (**real dir**) |
| `lg-push` | `/srv/lg-push` (**real dir**) |

A pull does not update these, but they are live code and the repo is their source. They are
**LIVE-INSTALLED**, a third category — not DEV-ONLY.

`lg-stripe-billing` is different again: live's `/srv/lg-stripe-billing` is **its own git repo**
(`github.com/iandavlin/lg-stripe-billing`), deployed independently. The monorepo's 93-file copy
serves live through no path at all — it is a duplicate. Flagging, not exiling; that is Ian's call.

## 3. DEV-ONLY — measured in *tracked* bytes (what actually ships)

Disk size is misleading (`evidence/` is 3.6MB on disk but 9 tracked files). Tracked bytes:

| path | tracked | files | what it is |
|---|---|---|---|
| `footer-mockups` | **34.49 MB** | 188 | mockup PNGs/HTML — the deploy-diff noise Ian felt |
| `docs` | 6.14 MB | 337 | **minus `docs/runbooks/`** (live-operator) |
| `evidence` | 3.49 MB | 9 | before/after screenshots |
| `evidence-c` | 2.69 MB | 9 | before/after screenshots |
| `tools` | 1.46 MB | 121 | gates, harnesses, capture scripts (run on dev2) |
| `run-reactions` | 0.49 MB | 2 | two PNGs |
| `guitardle`, `lg-shell` | 0.18 MB | 21 | **absent on live entirely** |
| `deploy`, `fast-follow` | 0.08 MB | 11 | superseded by `lg-deploy` / one-off |

`footer-mockups` alone is **~40% of all tracked bytes in the repo**. Live carries 194MB total
(107MB `.git` + 87MB tree).

## 4. Recommendation — hybrid (c), three independent layers

Two measurements decide this:

1. **Zero runtime coupling.** No live-serving file `require`s / `include`s / reads anything in
   `tools/ docs/ cutover/ footer-mockups/ evidence*/`, and no nginx conf references them. The
   ~350 `docs/` mentions in live code are **comments**. So a structural move is safe.
2. **But the blast radius is lopsided:** `docs/` is referenced in **349 files**, `tools/` in
   **135**. Moving those churns 400+ files to fix comment strings — cost with no risk reduction,
   because none of it is inside a symlinked tree.

So: **be structural exactly where the risk is, and cheap everywhere else.**

### L1 — RISK class. Structural. Do this first; it is the part that matters.

**L1a — close F1 now (one line, independent of everything else).** Add the gate check to
`location ^~ /v2/`, or narrow the alias to `mockup/` only. This is a live anon exposure and
should not wait on a file move.

**L1b — move dev/test/fixture dirs out of the docroot-symlinked plugin trees:**

| move from | tracked | note |
|---|---|---|
| `lg-layout-v2/tests` (41 files, 1.4MB) | → `dev/lg-layout-v2/tests` | self-contained; no `__DIR__` climbing out |
| `lg-layout-v2/mockup` | → `dev/lg-layout-v2/mockup` | referenced by the `/v2/` block — update together with L1a |
| `lg-weekly-digest/dev` (58 files, 968KB) | → `dev/lg-weekly-digest` | self-references are all *internal* (`__DIR__ . '/_load-under-test.php'`) so they travel intact |
| `lg-patreon-stripe-poller/tests` | → `dev/lg-patreon-stripe-poller/tests` | 3 files, one internal `__DIR__` ref |

Nothing symlinks a top-level `dev/`, so this **removes the reachable class** rather than
guarding it. The nginx rules stay as belt-and-braces (they also cover vendored PHPUnit suites
in third-party plugins, which we do not control and cannot move).

`archive-poc/shared/mockups` (1 file) and the `bin/` script dirs under `/srv`-only trees are
**not** in scope: their trees are aliased at `web/`, so they were never reachable.

### L2 — deploy hygiene: sparse-checkout with a fail-loud manifest

Live stops checking out DEV-ONLY paths. `git status --porcelain` stays clean and
`git pull --ff-only` still works, so `lg-deploy`'s two guards are unaffected.

**State the trade-off honestly — sparse-checkout does *not* do two things people assume:**
- It does **not** reduce what is fetched. Objects still transfer; only the working tree shrinks.
  (Cutting the wire cost needs a partial clone, `--filter=blob:none` — a re-clone, not worth it.)
- It does **not** clean the deploy diff. `lg-deploy` prints `git diff --name-only OLD NEW`,
  which reads commits, not the worktree. **So L2 alone does not solve Ian's noise complaint.**

What it *does* buy is real: 34MB of mockups never land on live's disk, so they cannot be served
by any future nginx accident. That is the same win as L1, applied to the NOISE class.

Fail-loud requirement: a manifest check that **errors** when a top-level path is neither
LIVE-SERVING, LIVE-OPERATOR, LIVE-INSTALLED nor DEV-ONLY — so a new top-level dir cannot
silently fail to deploy. Silent non-deploy is worse than noise.

### L3 — the actual fix for noise (cheap, ~10 lines, zero risk)

Teach `lg-deploy` to **classify its diff**: list member-facing changes in full, fold DEV-ONLY
paths into `… +47 dev-only files (footer-mockups, docs, tools)`. This is what Ian is actually
reading when he reviews a 288-commit pull, and it fixes the felt problem without moving a
single file.

### Reversibility

- L1b: `git mv` — reversible by `git mv` back; history follows with `--follow`.
- L2: live-side config; `git sparse-checkout disable` restores the full tree in one command.
- L3: additive change to one script.
- No history rewrite anywhere. Nothing here requires a force-push.

## 5. Gate — red-first against today

`tools/gates/dev-live-split-gate.py` (written, **deliberately not yet wired into
`run-all.sh`** — see below) asserts:

- **A.** No `dev|tests?|fixtures?|spec|__tests__|previs|sandbox|scratch|mockup` directory exists
  inside any tree that live symlinks into a **docroot**. → **RED today: 4 instances.**
- **B.** No DEV-ONLY path is reachable over HTTP. → **RED today: F1** (`/v2/mockup/*`,
  `/v2/tests/fixtures/*` serve 200 anon).
- **C.** Every top-level path is classified in the manifest. → green today; catches drift.

**Not wired into `run-all.sh` yet, on purpose.** It is red by design right now, and
`run-all.sh` is the push gate for *every* lane — wiring a red-first gate in would block all
lanes from pushing. It gets added to `run-all.sh` as **gate 16/16** (numbered from `origin/main`,
which has 15) in the same commit that lands the fixes. Until then it runs standalone.

## 6. Known gaps — stated, not papered over

- **`/var/spool/cron/crontabs` is unreadable as `looth-ro`**, so "no per-user cron references
  the repo" is **unverified**. `/etc/cron.d`, `/etc/crontab` and `/etc/systemd/system` *are*
  readable and clean (positive control: `grep` does find `/srv/` references in 3 units, so the
  search works). Ian can close this with `sudo crontab -l -u root; sudo crontab -l -u www-data`.
- Classification covers **top-level** paths. `docs/runbooks/` is the one known sub-path split;
  a second pass should confirm no other DEV-ONLY tree hides a live-operator sub-path.
- F2 (`/footer-mockups/`) is outside the repo's control and needs a live symlink removal.

## 7. Proposed order

1. **L1a** — close F1. One nginx line. Live exposure; should not wait. *(Ian reloads nginx.)*
2. **F2** — remove live's `/var/www/dev/footer-mockups` symlink. *(Ian.)*
3. **F3** — the lane-preview include-glob rework, then re-attach dev2's vhost symlink. Until
   this lands, **every tracked nginx change silently misses dev2** — which quietly weakens
   every other lane's verification, so it is worth doing early even though it is not
   strictly part of the split.
4. **L3** — `lg-deploy` diff classification. Cheap, immediate relief for the felt problem.
5. **L1b** — the `git mv`s, behind keeper approval.
6. **L2** — sparse-checkout + fail-loud manifest.
7. Wire the gate into `run-all.sh` as 16/16 once A and B are green.

Items 1–3 are independent of the split itself and each stands alone. Items 5–6 are the split.
