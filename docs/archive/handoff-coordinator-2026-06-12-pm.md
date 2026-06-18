# COORDINATOR HANDOFF — 2026-06-12 evening (visibility/perf/cut session decommissioning)

Successor: you inherit a converging project. Ian is consolidating lanes into
ONE working chat ("we are narrowing down the need for lanes") — you are that
chat. Read this + memories `project_live_cut_six_rulings`,
`project_visibility_model_final`, `feedback_gates_not_lanes` before touching
anything. Everything below is PUSHED (main @ a5b6bd8+, bespoke-cutover @
4631c11).

## Protocol changes Ian made TODAY (override older docs/memories)

1. **No more relaying to Buck without Ian's say** — "don't relay to buck,
   we are fixing." Cross-surface fixes happen IN THIS CHAT, including
   Buck-owned overlays (/var/www/dev/*.js, live-truth, edit with sudo,
   back up to /var/www/dev-bak-archive/). The `msg` channel still exists;
   use it only when Ian directs.
2. **Gates, not lanes** (docs/CRAFT-STANDARD.md): `tools/gates/run-all.sh`
   (visibility matrix 67 + craft gate 9 pages) MUST be green before pushing
   user-facing changes. A defect class found twice becomes a gate BEFORE its
   second fix. Performance is a checkpoint, not a specialist lane (4 perf
   lanes died teaching us that; ~13 image refixes).
3. Ian gave broad push approval today; still summarize what you push.
   Plain English always; he will challenge jargon and vague claims.
4. When Ian says something contradicts the record, CHECK THE RECORD — he
   was right about dev being ~2 weeks stale (it's a ~June-1 snapshot, NOT
   6/11; the '6/11 reload' note in chrome-dev-login skill is wrong) and
   right about Lighthouse 90s (warm-cache runs in mockups/lh-hub*.json).

## The big finished things (don't redo, don't relitigate)

- **Visibility model**: src/Visibility.php = the ONE decision point; matrix
  `php /srv/profile-app/bin/visibility-matrix.php` = 67/67 GREEN. One-dial
  master switch; anon finder = dots + vis-only stack; /profile-media auth'd
  (+ now also RESIZES via ?w=, buckets 96..1600, Visibility decision first);
  hub search masked; members-only = the starting state everywhere.
- **Craft/perf day**: every page ≤ ~⅓ of its morning bytes. Hub 1.6MB→~650-
  820KB. Fixed: feed cover srcset, avatar buckets (img.php w=96 + profile-
  media w=96), 253KB loothtool logo→18KB (orig backed up), page-2 prefetch
  deferred to load+idle (hub-infinite.js), quill = anon never/member idle
  (_chrome.php; Buck's iOS sync-tap-focus VERIFIED intact @390 — editor is
  loaded long before any tap), front rows + rail cards via fp_img/srcset,
  img.php: buckets widened, bb_medias virtual-path remap + widest-variant
  fallback, decode by MIME not extension (uploads contain misnamed files).
- **Danny West bug = structural auth bug, FIXED + gated (matrix #67)**:
  present-but-invalid looth_id blocked the re-mint forever (fresh WP login
  does NOT clear that cookie). Self-heals on next profile visit. OPEN: Ian
  confirming Danny's bar is back (see Open Threads 1).
- **Weekly digest**: PUBLIC /weekly/ (vis-1) = the exact sent email in an
  iframe (base target=_top so links open the real pages), current issue
  default, /weekly/all/ archive, anon forum-bylines masked, anon signup bar
  → CRM list 7 w/ double opt-in (honeypot + rate limit), member auto-sub.
  Front-page Weekly card both audiences; event-reminder TOGGLE (CRM list 4,
  state-true on load, on attaches / off detaches — pivot-table proven).
- **Sheet→event pipeline**: bridge → event post → AUTO v2 render verified
  end-to-end incl. gated zoom. `Code_v2-20.gs` (repo root) = Ian's v2-19 +
  zoom wired through BOTH publish paths.
- **Live deploy**: canonical doc = docs/LIVE-DEPLOY-PLAN.md with IAN'S SIX
  RULINGS at top (in-place/one-DB on old live — ip-172-31-45-223 IS old
  live, no second box; PG REBUILT from live WP; / = new front page; one
  generic BB redirect → /hub/ keeping /members/<slug>→/u/; F1 closed: the
  dial decides). cutover/cut-day-runbook.md = superseded blue-green model,
  its LIVE-INVENTORY/batches still good reference. Conversion carry =
  tools/export-v2-layouts.php / apply-v2-layouts.php (674 by-ID, round-trip
  proven; bundle regenerates AT cut). Disk verified (15.3GB free). Timers
  exist (bb-mirror-reconcile + lg-person-vis-refresh, both active).
  Dev mail now → mailpit via FluentSMTP connection (LIVE SES creds were on
  dev — rotation listed in plan §2.5 F6).

## OPEN THREADS (your queue, in rough priority)

1. **Danny confirm**: he reloads his profile, same browser → bar should be
   back. If NOT (post-fix, same browser): get browser + whether the dark
   View-as bar shows at all; that splits auth vs client-side. If his fresh
   login was in a DIFFERENT browser and still broken, the token theory is
   out — go client-side.
2. **Ian deploys Code_v2-20.gs** (paste into Extensions>Apps Script) + add
   'Zoom URL' as LAST column of Episodes (or re-run setupSheet). Nothing
   WP-side needed.
3. **Cut prerequisites still pending Ian**: cut window; `free -h` on live
   (RAM check before adding PG + 4 pools); gap-conversion session (live IDs
   71434 council video = real conversion; 71529 multiple-looth-shop = dev
   copy sits TRASHED with layout — restore-or-redo ruling; 71540 total-vise
   = dev has a REWRITE under different slug 71443 — replace-or-convert
   ruling). Rerun the gap inventory line (plan §3c) near cut.
4. **Lighthouse median-of-3** at a quiet hour for a quotable number (single
   runs on this box swing wildly; the BYTE numbers are the reliable ones).
5. **Buck's 6/5 queue** (msg inbox): practice-block WS3 land, push
   notifications, footer-CSS canonicalization — now Ian-routed; ask him
   what he wants pulled into this chat.
6. Parked Ian decisions: 6 junk-slug shorties need titles; guitardle
   memory says decommissioned but fp lane LAUNCHED it 6/12 (memory stale).
7. Watch: profile-media `.cache/` growth (new); craft-gate PAGES map must
   gain any NEW surface as part of building it (the law).

## Tooling quickref

- Gates: `tools/gates/run-all.sh` | matrix alone: `php /srv/profile-app/bin/visibility-matrix.php`
  | craft alone: `python3 tools/gates/craft-gate.py [--page hub]`
- Mint viewer: `sudo -u profile-app php /srv/profile-app/bin/mint-dev-token.php <wp_id>`
  (wp 1=Ian/admin, 7=plain member, 1910=matrix fixture owner). WP cookie:
  `wp eval wp_generate_auth_cookie(1912,...)` (claude_admin).
- Browser: chrome-dev-login skill (CDP :9222). Gate token: in
  dev.loothgroup.com.conf ($loothdev_token).
- Repos: main = ~/projects; hub/bespoke surfaces = ~/worktrees/bespoke-cutover
  (branch bespoke-cutover; /srv/bb-mirror symlinks there). Buck overlays =
  /var/www/dev/*.js (NOT in git; live-truth; img.php's repo snapshot =
  bb-mirror/web/img.php, refresh it when you edit live).
- archive-poc files are www-data-owned: edit via sudo, git add works.

## Decommission note

This session also wore the front-page-fix hat late in the day with Ian's
explicit go. The separate front-page lane chat may still exist — coordinate
via Ian, not msg. CHAT-LINEAGE.md / CHATS-MENU.md were being edited by
another session today; leave them to Ian/their owner to update with this
rotation.
