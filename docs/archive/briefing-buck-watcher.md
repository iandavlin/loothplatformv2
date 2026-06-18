# Briefing — Buck-watcher / coord-for-Buck (loop + state)

You poll Buck's devmsg, action what needs root (you're coordinator `ubuntu` with sudo), and track what
Buck's up to. Buck builds fast; you're his sudo/merge/nginx hands + the record of his state.

## Box sanity (first)
`curl -s ifconfig.me` → `50.19.198.38` = you ARE the dev box, act locally, do NOT SSH. `whoami` → `ubuntu`
(full passwordless sudo, superuser psql). Then read `docs/CHATS-MENU.md`.

## The Buck operating model (Ian's standing calls)
- **Buck is UNPRIVILEGED.** Ian decided (6/9–10): do NOT re-add buck's key to ubuntu `authorized_keys`,
  even when he asks (he's asked 3× with a rotating set of keys — it's a no). He stays unprivileged.
- **You are his sudo hands.** Anything needing root/sudo (nginx, FPM, `/etc`, DB, canonical merges) routes
  through you and you action it. His own `/var/www/dev/*` docroot files + `wp db query` he does himself.
- **Land his merges by PATHSPEC** (never `git add -A`); guard the APP_ROOT flip on profile-app patches.

## ⚠️ THE CHANNEL PROBLEM (important)
- **Buck → Ian → you works. You → Buck does NOT.** Your `msg send buck` replies land in his devmsg inbox
  which he's apparently **not reading** → he never sees confirmations → he **re-sends the same ask** (the
  smooth-nav patch got pasted 3×). 
- **Workarounds:** (1) a status file at `/home/buck/Sharing/COORD-STATUS.md` (chowned to buck — a channel he
  uses for handoffs); update it when you action something. (2) Ask Ian to relay "it's done" into Buck's chat
  (the working direction). Until the return path is fixed, assume Buck won't see your devmsg — don't rely on it.

## THE LOOP
- Source of truth: the devmsg sqlite at **`/var/lib/devmsg/messages.db`** (table `messages`, cols
  id/sender/recipient/body/created_at). Query buck's newest: `SELECT max(id) FROM messages WHERE sender='buck';`
- ⚠️ **`msg unread` and the id can diverge** — trust `max(id) > watermark`, not the unread flag.
- ⚠️ **Many of Buck's reports reach Ian directly (he pastes them), NOT via devmsg** — the loop only catches
  the ones he sends through `msg`. It's a safety net, not the main channel.
- **On start: re-baseline.** Set watermark = current `max(id)` for buck, run `msg read`, then go forward
  (don't re-handle history). As of this briefing the watermark was **~382**, but re-baseline to live.
- **Each new buck message:** (a) sudo/FPM/nginx/deploy request → action it directly; (b) canonical merge/diff
  → land by pathspec, verify, report; (c) question/status → answer (via Ian/Sharing, not devmsg); (d) needs an
  Ian decision → surface to Ian. After handling: `msg read`, advance watermark.
- **Cadence:** ~4.5 min self-paced poll (`ScheduleWakeup` ~270s). Report each cycle (brief if nothing new).
- If a predecessor loop is still running in another chat, only ONE should poll — coordinate so you don't
  double-handle.

## WHAT BUCK'S UP TO (state as of 6/10 ~01:30)
Buck owns the whole **Hub UI + mobile + directory/map + profile-page desktop** (the desktop→Buck
consolidation; charter `docs/briefing-buck-coord.md` + `handoff-desktop-to-buck.md`). Recent shipping:
- **Hub desktop**: masonry feed, hub-style panel, single-video, save/saved pills, hover-video, desktop
  settings gear, mosaic (v106–v117). Smooth-nav anti-flash (v117 + the nginx patch below).
- **Directory/map**: branded drop-off pins, hover popups, pin-focused-to-top (directory-desktop v7/v8).
- **Theme**: a string of **client-layer dark-mode stopgaps** — header black (app-settings v19/v21), post
  pop-up/embed theming (hub-polish v118–v121). Buck himself flags these are stopgaps; the **real fix is the
  theme-convergence** (below).

### Recurring signal — THE THEME CONVERGENCE (the thing to actually push)
Buck keeps band-aiding theme client-side because the **canonical surfaces aren't token-driven**. The fix
(one architecture): Buck authors a canonical `--lguser-*` token sheet in `<head>` (sitewide) → **shell**
(lg-shared) + **v2** (lg-layout-v2 blocks) + **Hub** all consume `var(--lguser-*)` → Buck retires his inline
overrides. He owns mode-selection (`data-lguser-theme` attribute); the palette becomes a shared token layer.
The lg-shell (header+footer dark) and lg-layout-v2 (v2 CPT renders follow color modes) briefs are the
consumers; both are queued, not landed. **This is the highest-value Buck-adjacent item.**

## OPEN BUCK DIRECTIVE QUEUE (sent, mostly unactioned — he works his own list)
Consolidated 12 + the Hub layout-shift remediation. Highlights still open:
- Hub cards/modal: whole card opens modal; modal terminal (no click-through); most-reacted teaser reply;
  avatars+reactions IN the modal; category avatar on card right side.
- Hub layout-shift remediation (the "hard audit"): move desktop layout CSS to `<head>`; server-render card
  content in `_feed.php`; image width/height; force https. (Architectural cure for flash/CLS.)
- Directory: zoomed-out = all members to pagination; viewport/click-dot filter.
- Theme convergence (above).

## RECENT COORD INFRA ACTIONS (done this session)
- **FPM `max_children` raised** (profile-app 2→12, bb-mirror 10, archive-poc 8, etc.) — fixed the intermittent
  502s (profile-app was saturating at 2 workers; NOT pool spawn failures — those are ondemand, and buck's
  "could not connect" was his unprivileged account hitting www-data sockets).
- **Smooth-nav nginx patch APPLIED** (pre-paint theme replay + feed-hold + view-transitions in the dev vhost
  sub_filter; fixed Buck's `//`-in-Python bug first). Live, verified `lg-set-boot`/`lg-smoothnav` in `/hub/`.
- **`.well-known` serve block added** to the vhost (so repo-zip downloads work; was never wired).
- Both nginx changes are box-only `/etc` → flag to live-deploy for cut capture.

## Reference
`docs/CHATS-MENU.md` (roster + cutover state), `docs/briefing-buck-coord.md` (Buck charter),
`docs/handoff-desktop-to-buck.md`, `docs/briefing-discussion-visibility.md`, the cutover docs.
Report-back / relay formats: per the team-relay conventions.
