# buck-surfaces audit — loothgroup front-end / PWA layer (2026-06-13)

Fresh-eyes security audit of buck's `/var/www/dev` layer (~28 files: 3 PHP endpoints, sw.js, pwa.js,
push flow, mobile/sheets, shop) — never covered by the strangler audit. Code-only, adversarially verified.
6 finders, 27 findings → **11 verified (3 confirmed, 7 partial, 1 refuted), 16 low.** No criticals.

## Bottom line
buck's layer is **mostly solid.** Verified by the audit: gating leaks NOTHING client-side (server is the
gate — no fetch-then-hide), no SQLi, no read-IDOR, VAPID private keys are safe (root:root 0600, only the
public key client-side), the service worker never caches authenticated navigations (no cross-user cache
leak), and the PWA loader only injects hardcoded same-origin scripts. The real issues cluster in the
**push endpoint**, one **dead endpoint**, and a **third-party shop feed**.

## CONFIRMED — fix before push goes live / before cut

**H1 (HIGH) — `push-subscribe.php`: unauthenticated write + subscription hijack.**
Anyone can POST a push subscription — no auth, no nonce, no rate-limit. The table's unique key is
`endpoint_hash` alone, and the upsert overwrites the owner column, so an attacker who learns a victim's
push endpoint can re-POST it and **reassign that subscription to themselves** → member-targeted push
(renewal alerts, DM pings) goes to the attacker; the victim silently stops receiving. Plus anonymous
table-flooding. Verified by live-fire. *Mitigating reality:* the push feature is **staged, not live yet**
(no cron, publisher in `staged/`), and the dev cookie gate fronts it today — so this is "fix before you
turn push on / before cut," not actively exploited now.
*Fix:* require a logged-in user to subscribe; make the unique key `(wp_user_id, endpoint_hash)` or refuse
to reassign an existing row's owner; add a `limit_req` zone on the push route.

**M1 (MEDIUM) — `saved-posts.php`: orphaned dead endpoint, no CSRF.**
The live bookmark client uses the canonical nonce-protected `/archive-api/v0/save-post`. `saved-posts.php`
has **zero callers** but is still deployed and writes without a nonce → a CSRF surface that goes
internet-reachable at cut. *Fix:* decommission it (and its table).

**M2 (MEDIUM) — pre-paint boot script reflects a localStorage theme blob unsanitized** into a `<style>`
rule and a `<link href>` (nginx conf:34 sink, from app-settings.js). Not directly reachable (only
app-settings.js writes that key), but a **persistent pivot** for any other XSS/extension. *Fix:* validate
the blob (color regex, font href allowlist).

## PARTIAL — real but overstated (lower priority)
- **SW push-`url` → openWindow / notificationclick open-redirect** (2 findings, claimed HIGH → actually
  LOW): the push `url` is authored only by trusted server code (the public endpoint never accepts a url;
  the publisher strips to a same-origin path). Add a same-origin check as defense-in-depth.
- **push-subscribe CSRF + no-rate-limit** (medium): real, but behind the gate today; same fix as H1.
- **shop feed stored-XSS** (medium): the shop feed is rebuilt hourly from a **3rd-party WooCommerce/Dokan
  store with vendor-controlled strings**, and one hand-rolled `esc()` misses single-quote escaping in a
  single-quoted JS context (`shop/index.html:278`) — a vendor name like `';alert(1)` can survive. Real
  latent stored-XSS. *Fix:* fix `esc()` to escape single quotes / use proper context encoding.
- **directory.js bare-global coupling** (medium → fragility): real, but all buck-owned (not cross-lane).

## 🔎 Root cause of the reverting logo (found here)
`/home/buck/bin/mirror-vendor-logos.py`, run **hourly by buck's cron** (`refresh-shop-feed.sh`), re-mirrors
the theme-source logo at full resolution with no resize guard, and a separate resizer races it. **So the
coordinator's 320px resize reverts every hour.** Durable fix is in buck's mirror script (add a resize, or
stop it mirroring the logo) — already flagged to buck via `msg`.

## REFUTED (1)
- "event sheet leaks the join/zoom link" — the client harvests it, but only if the server returns it to a
  non-entitled viewer; the server is the gate. Not a client-side leak.

## Lows (16) — mostly "solid" confirmations + nits
No Origin/Referer enforcement on the JSON writes; hardcoded Zoom URL in loothalong.php; icon cache
staleness; logo cache-bust `?v=4` vs `?v=2` mismatch; `sponsors-deck/` internal pitch sandbox served
publicly with no noindex; the `esc()` single-quote gap (also noted above).

Full raw result: workflow task `wpck0crso` (`/tmp/.../tasks/wpck0crso.output` — volatile).
