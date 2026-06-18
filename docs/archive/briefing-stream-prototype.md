# Briefing — Activity-Stream Prototype lane

**Paste into a fresh chat.** Build a working `/stream/` prototype: ONE unified, inline-functional
activity stream of everything at Looth. Real data, real interactions — not a mockup. Ian may ship
it, so build production-minded. Launch date is flex; correctness + the "wow" beat speed.

Stay in-lane: the `/stream/` route + cards + the **likes** system, on the **archive-poc stack**.
Cross-cutting (the /whoami contract, the shared header) routes to the coordinator — do NOT modify
either.

## The vision (why it's a "socks off" launch)
Today the community lives on a BuddyBoss feed; content lives in the archive. This merges them into
ONE living stream people move to **once** (Ian's hard rule: don't make users change how they consume
twice). The differentiator is **minimal click-through** — you like, comment, and download *in the
stream*, without opening the post. Media-forward cards (videos, 3D-scanned loothprints, repairs)
make it visually unlike any text-list feed.

## Hard constraints (non-negotiable)
1. **Header: consumer only.** `require_once '/srv/lg-shared/site-header.php'; lg_shared_render_site_header($ctx);`
   with `$ctx` mapped from `/whoami` VERBATIM (avatar_url, capabilities, tier, display_name,
   profile_url=/u/<slug>). Mirror `archive-poc/web/_chrome.php`. Do NOT edit the header or its CSS.
2. **Gating = server-side ABSENCE.** For an unentitled viewer, gated payload (member-only text,
   download URLs, gated chapters, full bodies behind a tier) must NOT appear in the HTML or any
   network response — render a CTA/teaser instead. Same discipline as `archive-poc/standalone/render.php`'s
   per-viewer gating. "Secure from the inspector" is the acceptance test: open DevTools as anon →
   the protected bytes are simply not there.
3. **Reuse, don't rebuild:** comments = the existing `?lg_comments=1` iframe modal (just restyled,
   e07d541); gating = the gate-CTA pattern; the feed source = the existing archive index
   (`article_blobs` / content index in Postgres `looth`), which already holds all post types + the
   1,263 forum discussions with dates + comment counts.

## Scope (thin but real)
- **Feed query:** time-ordered (cursor by recency), all managed CPTs (post-imgcap, post-type-videos,
  sponsor-post, loothprint, loothcuts, useful_links, member-benefit) + forum discussions. Paginated.
  Per-viewer gated at the query/render layer.
- **Cards (media-forward, per-type):** video → thumb+play; loothprint → preview + gated download;
  discussion → snippet + reply count; article → hero + dek. Each card shows like-count + comment-count.
- **Inline interactions (the wow):**
  - **Like / unlike** — NET-NEW system (see below). Tabulated count + per-viewer liked-state.
  - **Comment** — open the existing comments modal inline (no full navigation).
  - **Download** (loothprints etc.) — gated download via an entitlement-checked endpoint.
  - Keep a small "open post" affordance, but the goal is do-it-here.

## The one net-new system: Likes
- **Storage:** Postgres table (e.g. `discovery.likes`): `(post_type, post_id|item_id, user_uuid, created_at)`,
  UNIQUE(post_type, item, user) for idempotent like/unlike + cheap COUNT (or a denormalized counter).
- **Endpoint:** `POST /archive-api/v0/like` (toggle), authenticated only. Reuse the archive-poc auth
  (whoami) + the rest-nonce/loopback bridge for CSRF. Server enforces: must be authenticated; one
  like per user per item; returns new count + liked-state.
- **Display:** count always visible (social proof); logged-out → clicking prompts login, never writes.
- Tabulation must be queryable for "most-liked" later (don't paint into a corner).

## Security checklist (the acceptance bar)
- [ ] Anon DevTools on a gated card: no member text, no download URL, no gated body in DOM or XHR.
- [ ] Download endpoint checks entitlement server-side; serves via auth-gated handler (X-Accel-Redirect
      / signed token) — no raw file path in the page.
- [ ] Like/comment endpoints reject unauthenticated + enforce nonce; no IDOR (can't like/act as another user).
- [ ] Tier gating is by /whoami tier server-side, never a cookie/JS check.

## Dependencies / notes
- `/whoami` tier currently reads `public` for everyone until the poller is reactivated (whoami/gating
  lane) — gating LOGIC still works; just verify with `?as=lite|pro` preview or after poller is live.
- bb-mirror's "hub" is being subsumed by this — coordinate via coordinator before duplicating its work.

## Report back to coordinator
Route built + URL, like-system design (table + endpoint), which reused vs net-new, the security
checklist results (esp. the anon-DevTools gated test), and a screenshot of the stream. Flag any
/whoami-contract or header need (don't fix cross-cutting yourself).
