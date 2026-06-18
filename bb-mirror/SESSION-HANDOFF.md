> ⚠️ **SUPERSEDED 2026-06-15 by `docs/HUB.md`** — reference only. Boot a hub lane from `HUB.md`, not this file. Specifics here (overlay versions, line-number refs, the multi-week refactor plan, Buck ownership) are STALE; live overlay is `hub-polish.js` v199, Buck is OUT, directive is "no new features — make what we have work." What stays useful is noted in HUB.md §9.

# BB-mirror / Hub — Session Handoff (2026-06-06 — one-page-Hub proto + polish)

Prior handoff rotated to `handoffs/2026-06-06-deploy-prep-mute.md`.

> **THE board is `docs/hub-deploy-roadmap.md`** (Done / Cut-blocking / Post-deploy + the One-page-Hub
> section). This file = session state; the roadmap = standing plan. Read the roadmap first.

## Where the Hub stands
- **Deploy-ready** (cut-blockers verified earlier: no h-scroll 390/360/320, desktop unchanged, filter
  narrows threads+CPTs, comments open/post + anon 401, server-side gating).
- **Mute scope RESOLVED (Ian 6/6):** Types (CPTs) + Categories (topics) only — rail `hub-sw` switches stay.
  **No person/author mute.** Spec in `hub-filter-nav-spec.md`.
- **Rail accordion** is native `<details>` (Type/Categories single-open via `name=`; PE fallback in
  forums.js for old Safari). Section headers are buttons; the collapsed section sits on top.

## One-page-Hub PROTO — POST-DEPLOY, flag-gated (`?proto=cards`; `?proto=off` clears; sticky)
Default feed is 100% untouched without the flag. Mockup: `/mockups/hub-card-tiers.html`.
- **Discussions = no click-through:** click a topic card (incl. its title) → expands to MAX in place,
  single-open: full body + full thread + engagement bar + **inline reply composer** (text-only, posts
  BB REST `/reply`). **CPTs click through** to the post (Ian: don't inline CPTs).
- **In-feed moderation (admin):** per-reply **Edit + Trash** on thread stubs, revealed only under
  `.feed--can-moderate` (auth `can_edit_others`). PUT/DELETE `/reply/{id}`; **server re-checks caps**.
  Gated to moderators for now (owner-edit needs author wp_user_id in the stub — not emitted yet).
- **Engagement bar** server-rendered on topic cards: comment count real; **reactions = our unicode
  palette stub (👍😮😂🧠)**; share/save = stubs.

## Ship-worthy fixes this session (some broader than the flag)
- Reply **line breaks** in the full thread (`format_snippet` `$preserve_breaks` + a closure-capture bug:
  `$preserve_breaks` wasn't in `$walk`'s `use()`).
- **Full untruncated replies** in the thread fragment (`_topic-replies.php`, was 200-char capped) — also
  improves the default "View N replies".
- **Colour-states cordoned to the Hub theme:** dead `--lguser-*` tokens aliased to `--lg-*` in forums.css
  `:root` (card titles were invisible in dark). **Shell chrome insulated** — `.lg-chrome`/`.lg-chrome-foot`
  pin their own palette so the Hub theme stops bleeding into the site header/footer.
- **Card overflow at large text-size** (desktop `.feed` had no `grid-template-columns`) → `minmax(0,1fr)`
  + `min-width:0` + `overflow-wrap`.
- **Text-size toggle** now scales cards (dead `--lguser-scale` → `--lg-read-scale`).

## Webroot (NOT git — backed up beside each file)
- `hub-polish.js` v51→v52: composer category chips now preserve the forum `<optgroup>` nesting (was a
  flat dup-prone list). Loader bumped in `pwa.js` (`v=51`→`v=52`).
- `pwa.js`: iframe guard (`window.top!==window.self` → return) so the app-shell (bottom nav, shop bubble)
  no longer loads inside the §4c comments iframe. (pwa.js is `no-cache`, applies on reload.)

## Git
- Committed **`e3d1d07`** (9 files) + this handoff rotation, on **`main`**, **UNPUSHED** (joins the lane
  stack). Ian + git-tsar gate the push — present all unpushed commits + diffstat before any push.
- NOT committed by me (left for the git-tsar to avoid bundling other lanes' in-flight edits): minor
  mute-wording edits in `docs/LANE-LEDGER.md` + `docs/briefing-coordinator-successor.md`.

## Key files (`bb-mirror/web/`)
- `forums.css` — Hub styling incl. proto tiers, engagement bar, moderation controls, theme tokens.
- `forums.js` — `1b4` proto IIFE (expand-in-place, inline composer, mod Edit/Trash, auth/nonce);
  `2`/`2b` lazy thread+body; `§4b` reply modal; `§4c` comments modal; `§3c/3d` single-topic edit/delete.
- `forums/_feed.php` — unified feed + card markup + engagement bar.
- `forums/_reply-render.php` — reply stub + `bb_mirror_format_snippet` (now `$preserve_breaks`).
- `forums/_topic-replies.php` — `?replies=` full-thread fragment (renders full replies).
- `forums/_filter-rail.php` — native `<details>` rail.

## NEXT
- Moderation: **Move / Split / Spam** (each needs a BB proxy endpoint) + **owner-edit** (emit author
  wp_user_id on the stub to gate). Then wire the **comments+reactions** lane's real reaction widget.
- **Save fast-follow** (roadmap): `discovery.saved` table + toggle endpoint + ☆ wire + a **"Saved" rail
  filter** (saved lives in discovery so `_feed.php` can WHERE on it — decided 6/6).
- Reach mockup parity: server-render the full MAX element set (member subline, breadcrumb) for discussions.
- Routed out: **lg-shell** owns the dark-mode shell-nav fix (their `.lg-chrome` token scoping).

## Test accounts / verify
- admin uid 1 (iandavlin, bypasses reply flood throttle). Headless CDP is **anon to WP** → moderation
  controls stay hidden + posts hit the auth gate there; test mod actions logged in.
- Raw canonical: `curl /hub/ --resolve dev.loothgroup.com:443:127.0.0.1 -H "Cookie: loothdev_auth=<$loothdev_token>"`.
- Phone/real view: `chrome-dev-login` skill.
