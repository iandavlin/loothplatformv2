> **⚠️ READ `docs/LAUNCH-HANDOFF.md` FIRST (current cut chain).** The NEW-box + DNS/CF-flip
> *strategy* below still holds, but the DATA/SESSION *method* changed (Ian 6/17): cut = **TOP-OFF +
> re-login**, NOT full-replace + session carry; deploy = **rsync from /home/ubuntu/projects**, not
> git-pull. Use this doc for the new-box rationale only; LAUNCH-HANDOFF/CUT-RUNBOOK are the active how.

# Live cut / deploy plan — ACTIVE (supersedes the DEAD `LIVE-DEPLOY-PLAN.md`)

Decided by Ian 2026-06-13. **Strategy: build a NEW box from dev, flip DNS** — NOT in-place. This
*reverses* the earlier "in-place / no second box" ruling, and it's safer: you test the real thing
before any user sees it, and DNS is reversible.

## The shape
Stand up a fresh prod box that IS dev's strangler stack, load current live data, test it fully, then
flip DNS `loothgroup.com` → new box. Old live stays as the rollback.

## Core principle: new box = dev's CODE, but live's IDENTITY + DATA
- **Code / apps / configs** → from DEV (profile-app, archive-poc, bb-mirror, poller, nginx, the gates).
- **WP secret keys** (`AUTH_KEY` + salts) → from **LIVE** — a FILE in live's `wp-config`, *not* in the DB.
  Required or every login cookie dies (dev's keys differ).
- **JWT signing key** (RS256, `/etc/looth/jwt-private.pem`) → carry the SAME key so existing JWTs stay
  valid and there's no re-mint storm.
- **Users / sessions / posts / orders** → from LIVE, CURRENT (incl. `wp_usermeta` `session_tokens`).

## Data path — NO SSH needed
- **Direct MySQL read to live**: creds in `/etc/lg-topoff.conf` (`LIVE_HOST/USER/PASS/DB`, root:www-data
  0640). `mysqldump -h $LIVE_HOST …` gives a full current dump — `topoff-dev-from-live.sh`'s `livedump()`
  already does exactly this. Live MySQL is reachable over the network from this box.
- **A fresh live copy is already on disk:** `/home/ubuntu/backups/looth_import_2026-06-13_154612.sql.gz`
  (63M, 6/13 15:46). Good for BUILD/TEST; it'll be stale by cut day → still needs the final top-off.
- `tools/topoff-dev-from-live.sh` is **additive, missing-rows-only** (never updates) → NOT the cut
  mechanism (won't carry sessions or changed rows). Use a full `mysqldump` for the cut.
- **Confirm the live creds are READ-ONLY** so the new box can never write prod during the build.

## Respecting logged-in state (Ian's requirement)
Same domain (DNS flip keeps `loothgroup.com`) → the browser keeps sending the login cookie. For it to be
ACCEPTED, the new box needs: (1) LIVE's WP secret keys + salts, (2) the user's `session_tokens` row
present (so the top-off carries current sessions), (3) the carried JWT key, (4) a first-request identity
refresh so nobody's stuck stale. The DEFAULT path does the opposite (a reload invalidates sessions; a dev
copy carries dev's keys) — staying-logged-in is a **deliberate choice**. Drop a session only where the
refresh can't resolve it (a clean re-login beats a stuck state).
⚠️ **OPEN — verify the refresh-JWT** re-mints cleanly for *expired / wrong-key / absent* JWT + a valid WP
cookie (the wrong-key case is new at cut). The "stale `looth_id` re-mint bounce" fix covers stale; confirm
wrong-key. Test before relying.

## Wire-swaps: dev → live (a copy carries dev wiring — each MUST be swapped)
- **Email**: mailpit (dev trap) → REAL SMTP, or no mail sends (welcome/reset/notify die silently).
- **Uploads**: dev's read-only R2 *clone* (IP-locked) → the REAL R2 bucket + write creds.
- **Cookie gate**: OFF — dev's `loothdev_auth` gate would 403 everyone. Drop the block / set the map
  default-allow. (Also closes the audit's "gate drops at cut" assumptions — re-check those endpoints.)
- **Secrets**: real Stripe/Patreon/VAPID/JWT/bridge/HMAC keys (dev's are test/dev).
- **Stripe/Patreon webhooks**: re-point at the new box; check in-flight subscriptions.
- **SSL**: a valid cert for `loothgroup.com` on the new box BEFORE the flip.
- **URL rewrite**: WP DB (siteurl/home/content) AND every non-WP app config (profile-app, archive-poc,
  bb-mirror, the JWT issuer) AND nginx `server_name`. `wp search-replace` only touches WP — misses the apps.
- **DSNs / re-arm**: peer-auth → password where the FPM user changes; the 5-way post-reload `/whoami`
  re-arm (poller, lgms creds, BB REST gate, the bridge gaps).
- Most of this list is catalogued in `docs/briefing-live-deploy.md` (salvage from the doc sweep) +
  `docs/master-path-map.md` (the source→target deploy map).

## Provisioning
"Copy dev" = **snapshot the dev instance → launch a new instance** (carries services, users, DB engines,
packages, rclone, systemd). NOT an rsync of files (which leaves the stack behind).

## Sequence
1. Dev feature-complete + audit fixes in (mostly done; conversions blocked on the DB refresh).
2. Snapshot dev → new prod-sized box.
3. Swap every dev wire for a live wire (SMTP, R2, gate off, real secrets, SSL, URL rewrite, **LIVE WP
   keys + the JWT key**).
4. Load CURRENT live data (full mysqldump incl. users/sessions; run conversions; reconcile the identity
   bridge). Test: gates green, real logins, payments in test mode, the refresh-JWT cases.
5. Pre-stage: lower DNS TTL (days ahead), point Stripe/Patreon webhooks at the new box, dress-rehearse.
6. Flip window: freeze old-live writes → **final delta top-off** (activity since the copy, incl. sessions)
   → flip DNS → verify → hold old-live as rollback for a defined window.

## Rollback
DNS is reversible, BUT: pre-set a **low TTL**, define a **go/no-go window**, and know that once users
write to the new box, flipping back loses that data — so rollback is clean only in a short window. Not
"hold our breath" — a defined window + a decision point.

## Strategic rulings (status)
- **REVERSED:** in-place / no-second-box → **new box + DNS flip.**
- Re-confirm with Ian: PG rebuilt from live WP · `/` = new front page · BB → one `/hub/` redirect ·
  F1 = the visibility dial decides · cut = idempotent top-off.

## Open / blockers
- Refresh-JWT verification (above). · Conversions blocked on the DB refresh.
- Get live's `wp-config` secret keys (a file on live — the direct DB read doesn't carry them).
- Confirm the live creds are read-only.
