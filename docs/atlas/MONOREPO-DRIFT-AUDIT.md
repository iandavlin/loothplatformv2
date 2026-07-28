# MONOREPO-DRIFT-AUDIT.md — what dev2 serves from outside the repo

Ian's principle, 2026-07-28: *"everything should be in mono repo, that's why all the
symlink."* This is the inventory that principle needs before anyone can act on it.

**AUDIT AND PROPOSAL ONLY — nothing here has been moved, deleted or rewired.** Every path
below was read, not changed. The serve was not touched (`porcelain` empty throughout).

Scope: every file **served** by dev2 that does not come from `~/loothplatformv2-clean`.
Method: `find -type l/-type f` across `/var/www/dev`, `/srv`, `/etc/nginx`, `/etc/looth`,
plus `git ls-tree` / `git grep` against `origin/main` to decide whether a repo twin exists.

---

## The verdict in one line

The symlink farm is in **good** shape — 37 docroot symlinks, 33/33 mu-plugins, all
`/srv` apps and 13 of 16 nginx snippets point into the repo, and there are **zero dangling
links** on either side. The drift is not in the farm. It is **33 real files sitting in the
docroot** and **two symlinks that escape the repo entirely**, one of which points into
another lane's worktree.

---

## 1. Legitimate box-local runtime state — LEAVE IT

These are correctly outside the repo. Committing any of them would be the bug.

| Path | Why it must stay out |
|---|---|
| `/etc/nginx/snippets/loothdev-tokens.conf` | The dev gate secret. Repo tracks only `.example`. This split is deliberate (deploy-one-pull) and the gate harness depends on it. |
| `/etc/looth/env`, `jwt-private.pem`, `live-wp-keys.php`, `profile-r2` | Secrets and per-env values. Read by `lg-env.php` / `profile-auth.php`. |
| `/var/www/dev/wp-config.php` | Carries DB credentials and salts. Per-box. |
| WP core (`wp-*.php`, `xmlrpc.php`, `index.php`, `license.txt`, `readme.html`) | Vendor code, installed by WP, not ours to vendor. 13 files. |
| `/var/www/dev/wp-content/uploads` → `/mnt/loothgroup-uploads-dev` | User-uploaded media on its own volume. Correct. |
| `/srv/profile-app-media`, `profile-app-message-media`, `thumb-app`, `lg-stripe-billing`, `lg-sudo-queue` | Runtime media + a separately-deployed app. Out of scope for the webroot principle. |

## 2. Real DRIFT — repo-able content living only in the docroot

Twenty files. None has a repo twin, so none is currently *shadowing* the repo — the
`mobile-hub.css` failure mode is **not** present. The risk is different: this content exists
**only** on this box's disk. A rebuild loses it, and nothing traces it to a commit.

| File | Date | Size | What it looks like |
|---|---|---|---|
| `slug-dry-run.html` | 07-27 | 8 KB | **Mine.** Lane report. |
| `slug-dry-run-liveshape.html` | 07-27 | 314 KB | **Mine.** Lane report. |
| `tier-mockup.html` | 05-12 | 12 KB | Mockup for Ian |
| `weekly-patreon-paste.html` | 06-15 | 7 KB | One-off tool output |
| `pwa-test.html` | 06-05 | 570 B | Scratch test page |
| `shop-feed.json`, `shop-vendors.json` | 06-17 | 10 KB | Data feeds — may be load-bearing, **owner unknown** |
| `looth-app.apk` | 06-11 | 2.4 MB | Android build artifact |
| `guitardle-icon.png`, `guitardle-icon-512.webp` | 06-12 | 43 KB | App icons — these look like they *belong* in `webroot/icons/` |
| `cdp_tab.py` | 06-06 | 3 KB | Tooling; `tools/` is its home |
| `CLAUDE.md`, `dev-environment.md`, `memdir-handoff.md`, `.gitconfig` | 03-23/24 | 55 KB | **Docs and a git config being served over HTTP.** Not a secret, but nothing here should be publicly fetchable. |

> `.gitconfig` and `memdir-handoff.md` being world-fetchable from the docroot is worth a look
> independently of the monorepo question. This lane did not test whether nginx actually serves
> them — that needs the perimeter check in `infra-sec-gate.sh`, not a guess.

## 3. Symlinks that ESCAPE the repo — the two that matter

```
/var/www/dev/footer-mockups -> /home/ubuntu/projects/footer-mockups
/var/www/dev/lib/quill      -> /home/ubuntu/worktrees/dmv-native/webroot/lib/quill
```

**`footer-mockups`** is keeper's badge page. Served from `~/projects`, which is not the repo
and not deployed by anything. Same class as §2, just reached by a link instead of a copy.

**`lib/quill` is the serious one.** dev2 serves a 209 KB JS library **out of an unmerged lane's
git worktree**:

- link created 2026-07-15, target `worktrees/dmv-native/webroot/lib/quill/quill.js`
- `dmv-native` @7d6c614 is **not an ancestor of main** — this content has never been reviewed
  or merged
- the repo has `webroot/lib/leaflet/` but **no** `webroot/lib/quill`, and the serve clone's
  `webroot/lib/` contains only `leaflet`
- it is live right now: `GET /lib/quill/quill.js` → **200, 209274 bytes**

`git worktree remove dmv-native`, or that lane checking out a different branch, would 404 the
path with no warning and no commit to bisect.

**Being precise about the blast radius, because it cuts the other way too:** nothing on `main`
references `/lib/quill`. The shipped editors load Quill from **`cdn.jsdelivr.net`**
(`bb-mirror/web/_chrome.php:701`, `profile-app/web/_richedit.php:45`,
`webroot/hub-polish.js:3881`). So this is **not** currently load-bearing for composer v2 — I
checked specifically because the composer had just shipped and a wrong "the editor is about to
break" claim would have been worse than the drift. What it is: an unreferenced, unreviewed,
publicly-served artifact wired to a lane's scratch space — and a half-finished attempt to
self-host Quill off the CDN, which is a decision someone should either finish or undo.

## 4. Two smaller traps found on the way

- `/etc/nginx/sites-available/dev2.loothgroup.com.conf.bak-gatearm-20260704` is a **symlink to
  the live conf**, not a backup of it. Restoring from it restores today's file. A backup that
  cannot restore is worse than no backup.
- `~/loothplatformv2-serve` — the path 10+ repo docs still name as the serve clone — **does not
  exist**. The serve is `~/loothplatformv2-clean`. Fixed in `OVERLAY-SERVE-DEPLOY.md`; still
  present in `SYSTEM-MAP.md`, `HUB-RENDER-ARCHITECTURE-AUDIT.md`,
  `SERVE-CONSOLIDATE-MEMBERSHIP-RUNBOOK.md`, `EVENT-SHEET-BRIDGE.md`, two ship runbooks,
  `runbooks/preview-a-true-preview.md`, `profile-app/SESSION-HANDOFF.md` and two handoffs.
  Left alone deliberately — a ten-file sweep is its own change, not a footnote to this one.

---

## 5. PROPOSAL — where lane reports live

This is the recurring case and the one Ian owes a ruling on. A lane generates an HTML report,
needs Ian to *see* it, and the only surface anyone knows is the docroot. So it gets written
there, and it never leaves. That is how §2 accumulated, and both my files are in it.

**The rule this lane proposes:**

> **A lane report is an ARTIFACT, not a deploy. It never lives in the docroot, and it is never
> the repo's job to carry it.**
>
> 1. **Reports are generated to `~/lane-reports/<lane>/`**, mode 0600. Not the docroot, not
>    `~/projects`, not the repo.
> 2. **A report is NEVER committed.** It is output — derived, re-runnable, and often stale the
>    day after. What gets committed is the *number and the method* (a short section in the
>    lane's atlas doc), so the finding survives in a form that can be checked against the
>    source, and the bulky HTML does not enter git history forever.
> 3. **To show Ian, publish deliberately and temporarily.** One reviewed surface —
>    `/reports/<lane>/<file>` — served from a directory the repo does *not* own, behind the dev
>    gate, and swept on a timer. Publishing is an explicit act with an expiry, not a side effect
>    of writing a file.
> 4. **PII never gets published at all.** My live dry-run embeds four member email addresses.
>    The dev gate is a *shared* credential, not per-person auth, so "behind the gate" is not
>    an access control for personal data. Those reports stay 0600 on disk and Ian reads them
>    over his own session.

**Why not just commit them:** it fails Ian's principle for the right reason. The monorepo rule
exists so that *what is served* is traceable to a commit. A report is not served software — it
is a measurement of a moment. Committing 314 KB of generated HTML per run buys no traceability
and permanently bloats the repo.

**Disposition of the 20 files in §2** — for Ian, not for a lane to act on:
- my two `slug-dry-run*.html` → **delete**; superseded by live numbers and re-runnable from the
  contract
- `guitardle-icon*` → **into `webroot/icons/`** if still used; these look genuinely repo-able
- `cdp_tab.py` → **into `tools/`**
- `shop-feed.json` / `shop-vendors.json` → **find the owner first.** These could be serving real
  data; deleting them blind is the one move here that could break something.
- `CLAUDE.md`, `.gitconfig`, `memdir-handoff.md`, `dev-environment.md` → **remove from the
  docroot**; they are docs, and one is a git config
- `looth-app.apk` → build artifact; belongs wherever builds are published, not in git
- mockups / one-offs → `~/lane-reports/` or delete

Nothing above is actioned. It needs Ian's ruling, and `shop-feed.json` needs an owner.
