# Coordinator report — profile-app lane: 2 issues from dev2-cut testing

**Lane:** profile-app (dev2 / identity). **Date:** 2026-06-14.
**TL;DR:** ISSUE 1 = identity-bridge gap, real cause found + fixed on dev + repeatable
dev2 recipe below (no code change — data reconcile). ISSUE 2 = NOT a profile-app
routing bug; it's a consumer hardcode → routes to the **events lane**. No profile-app
SHA from this work.

---

## ISSUE 1 — unbridged accounts → /whoami anon

### What `/whoami` needs (confirmed in `src/Whoami.php`)
An account resolves `authenticated:true` only with **both** halves:
- **PG:** a `profile_app.users` row + `wp_user_bridge` link (`reconcile-bridge.php`).
- **WP:** usermeta `_looth_uuid` == `users.uuid` (login mints the JWT `sub` from it;
  `backfill-looth-uuid.sh`). Missing either → `buildAuthed`/`buildForWpUserId` returns
  anon. Header reads the JWT (shows admin); composer reads `/whoami` (anon) → divergence.

### Finding: the gap is NOT just test accounts
On **dev**, before reconcile: **1820 wp_users, 1819 bridged, 9 missing `_looth_uuid`** —
and the 9 were **real members** (looth1/2/3, real emails, bbp_participant), not
disposables: allen.jeter, brice.giesbrecht, david.h, from.hero.to.artist, jim.hensley,
jscatches, kent.davis, kevinv, mikelle.davlin. All high WP IDs (1884–1893, 1848) =
**signups created after the backfill snapshot**. claude_admin/qa-disposable were already
bridged on dev. **Implication for dev2:** anyone who signed up on live after the dev2
backfill snapshot is unbridged — expect real members, not only test accounts.

### Fixed on dev (idempotent, GATE GREEN)
```
reconcile-bridge.php : created 0, existing 1819, linked 1
backfill-looth-uuid  : written=8 already-ok=1811  GATE: pass=1819 mismatch=0 missing=0 GREEN
```
Verified: kent.davis (was anon) now `/whoami` → `authenticated:true, slug:kent-davis, tier:pro`.

**One residual (data hygiene, not a backfill miss):** mikelle.davlin **wp 1848** can't bridge —
its email `mikelle.davlin@gmail.com` already belongs to `users` row bridged to **wp 1905**
(a duplicate WP account; `users.primary_email` is UNIQUE). Real member = 1905 (bridged, works);
1848 is a stale dup. Same class as the recycled-WP-ID staleness we've seen. Needs a dedup
decision (merge/archive 1848), not a bridge re-run.

### dev2 OUTCOME (Ian ran it, 2026-06-14) — FIXED, GATE GREEN
Audit found **112 real members missing `_looth_uuid`** on dev2 — almost all
`patreon_*` subscribers. Root cause: the original 1699-user backfill keyed on
**primary_email**, and Patreon-onboarded accounts have synthetic/empty emails, so
every one was skipped (1811 − 112 = 1699, exactly). Their **PG bridge rows already
existed** — only the WP usermeta half was missing. Fix run:
```
reconcile: created 0 existing 1811 linked 0      (bridge already complete)
backfill : written=112 fixed=0 already-ok=1699 skipped(no-wp-user)=8
GATE: pass=1811 mismatch=0 missing=0 -> GREEN
```
`fixed=0` = no existing identity overwritten (only empties filled). dev2 now fully
bridged. NB dev2's `profile_app` PG looks **cloned from dev** (identical 1819 bridge
rows / 116 ghosts / 10380 connections; 8 bridge rows have no dev2 wp_user) — flagged
a post-fix name-compare spot check to rule out a misaligned clone. **claude_admin /
qa-disposable were NOT in the 112** — if the admin that showed the composer
"Sign in to post" was already bridged, that residual is the bb-mirror whoami-gating
bug, not a bridge gap.

### Repeatable dev2 bridge recipe (you build → Ian pastes; I can't SSH dev2)
On `ubuntu@34.193.244.53` (`WP_PATH` = dev2's WP root — mirrors dev = `/var/www/dev`; confirm):
```bash
cd /srv/profile-app
# 1. PG half — users + wp_user_bridge for EVERY wp_users row (covers claude_admin, qa-disposable, post-snapshot members)
sudo -u profile-app php bin/reconcile-bridge.php
# 2. WP half — _looth_uuid usermeta from PG (idempotent; exits non-zero unless GATE GREEN)
sudo WP_PATH=/var/www/dev bin/backfill-looth-uuid.sh
# 3. affected users must LOG OUT + back in (JWT sub is minted at login from _looth_uuid)
```
Both idempotent — safe to re-run. For a test admin specifically, the same two cover it
(they walk all users); then log out/in to mint a fresh `looth_id` JWT.

### Diagnostic to run on dev2 (find real members still unbridged)
```bash
# (a) WP users missing _looth_uuid — are any REAL members vs disposables?
sudo -u www-data wp --path=/var/www/dev eval '
foreach(get_users(["fields"=>["ID","user_login","user_email"]]) as $u){
  if(get_user_meta($u->ID,"_looth_uuid",true)==="")
    echo $u->ID."\t".$u->user_login."\t".$u->user_email."\t".implode(",",get_userdata($u->ID)->roles)."\n";
}'
# (b) wp_users with NO PG bridge row
sudo -u www-data wp --path=/var/www/dev user list --field=ID | sort -n > /tmp/wpids.txt
sudo -u profile-app psql -d profile_app -tAc "SELECT wp_user_id FROM wp_user_bridge ORDER BY 1" | sort -n > /tmp/brids.txt
comm -23 /tmp/wpids.txt /tmp/brids.txt
```

### Other backfills (re: "did we miss more?") — dev coverage, bridged=1819
| backfill | dev gap | note |
|---|---|---|
| `_looth_uuid` (identity) | **was 9, now 0** (1 dup) | the critical one; fixed |
| slug | 5 missing | edge; reconcile sets `user-<id>` fallback |
| avatar_url | 92 null | mostly never-set → initials fallback (benign); patreon avatars excluded per Ian 6/13 |
| display_name | 0 | clean |
| synth-email ghosts | 116 `@invalid` | empty-email WP accounts; expected, triage later |
Other migrate scripts present + idempotent (re-runnable on dev2 if a coverage gap shows):
`backfill-avatars.php`, `migrate-from-xprofile.php`, `migrate-socials.php`,
`migrate-social-from-bb.php` (connections=10380 on dev), `snapshot-location-from-bb.php`,
`geocode.php`, `migrate-sponsors.php`. **Each has its own snapshot cutoff → post-snapshot
signups can miss any of them.** If you want, I'll run the same coverage diagnostic against
dev2 once Ian pastes a PG/WP shell, before deciding which to re-run.

> **Cross-lane (NOT mine, flagged only):** the durable fix is the Hub composer gating on the
> **WP login cookie** (server 401 = real lock), not `/whoami` — Ian's standing rule. Coordinator
> is routing that to bb-mirror. Did not touch bb-mirror.

---

## ISSUE 2 — "My Profile" → wrong page (events surface)

### Pinned: `/profile/edit` is profile-app, NOT legacy BuddyBoss
As a logged-in member (kent.davis, has slug, `/u/kent-davis` → 200) `/profile/edit` renders
`<title>Start your profile</title>`, `<body class="interstitial">` — the profile-app
onboarding/editor interstitial. Profile-app, not BB. But it's the **wrong target** for a
"My Profile" link — it's the editor entry / slug-less fallback, not the viewer's public profile.

### Canonical contract (already documented)
- `relay-header-convergence.md` §$ctx: `profile_url = whoami.slug ? '/u/'.rawurlencode(slug) : '/profile/edit'`.
- `site-header.php` docblock (L22/67) + L82 default + L316 "My Profile" link all use `profile_url`;
  reference impl = `profile-app/web/_chrome.php:38` (derives `/u/<slug>`).
- Owner editing happens **inline at `/u/<slug>`**; `/profile/edit` is only the slug-less fallback.

### Root cause = consumer hardcode (events lane)
`events/web/index.php:42` and `events/web/weekly.php:41` set `'profile_url' => '/profile/edit'`
instead of deriving `/u/<slug>` from whoami. So from the events surface "My Profile" opens the
editor interstitial rather than the public profile.

### Verdict + routing
**Fix belongs to the EVENTS lane** (convergence Step 1: "consumers must link `/u/<slug>`").
One-line change: copy the `_chrome.php:38` pattern —
`'profile_url' => !empty($who['slug']) ? '/u/'.rawurlencode($who['slug']) : '/profile/edit'`.
**NOT a `/profile/edit` routing change**, and `/profile/edit` should **not** redirect to `/u/<slug>`
— slug-less/onboarding users legitimately land there. Coordinator: route the two-file events edit
to the events lane.

---

## SHAs
- No new profile-app commit (Issue 1 = data reconcile on dev; Issue 2 = events-lane edit).
- Related earlier infra fix this session: `a7b258e` (strangler-profile-app `/members/*` BB redirect) — separate report.
</content>
