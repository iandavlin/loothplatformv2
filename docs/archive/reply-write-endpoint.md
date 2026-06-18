# Reply-write endpoint — contract (bb-mirror lane)

**Owner:** bb-mirror · **Status:** live on dev · **For:** /stream/ inline replies + /hub/ reply forms
**File:** `bb-mirror/api/v0/reply.php` · **Route:** `POST /bb-mirror-api/v0/reply`

The single owned reply-WRITE path for the stack. Runs on the WP FPM pool, reuses
BuddyBoss's reply handler in-process (`rest_do_request('/buddyboss/v1/reply')`) so
media, counts, notifications and the bb→pg sync all fire as in the native path —
wrapped in one clean contract with explicit flood-throttle + moderation handling.

## Auth
Same-origin **WordPress login cookie** (browser sends it). bbPress is WP, so the
write needs the WP user; the cookie resolves the author. No nonce required (server
dispatch). Also dev-cookie-gated at nginx (`loothdev_auth`). 401 if not logged in.
*(Future: looth_id-JWT-only auth — map `sub`→wp_user_id + `wp_set_current_user()`.)*

## Request  (`Content-Type: application/json`)
```jsonc
{
  "topic_id":  71404,        // required — bbPress topic (discussion) ID
  "content":   "<p>…</p>",   // required unless media_ids — HTML, sanitized server-side
  "reply_to":  71400,        // optional — parent reply ID (nesting). omit/0 = top-level
  "media_ids": [123, 124]    // optional — bbp_media upload IDs (pre-upload via /wp-json/buddyboss/v1/media/upload)
}
```

## Responses
**200 — published**
```jsonc
{ "ok": true, "status": "published",
  "reply_id": 71424, "topic_id": 71404, "parent_reply_id": null,
  "author": { "wp_user_id": 1, "display_name": "Ian Davlin",
              "slug": "iandavlin", "avatar_url": "https://…/bpthumb.jpg" },
  "content_html": "…sanitized rendered reply…",
  "created_at": "2026-06-03T17:35:52-04:00",
  "permalink": "/hub/<forum>/<topic>/#reply-71424" }
```
**202 — held for moderation** `{ "ok": true, "status": "pending", "reply_id": N, "message": "…awaiting moderation." }`
**429 — flood throttle** (header `Retry-After: <s>`) `{ "ok": false, "error": "flood", "retry_after": 9, "message": "You're posting too fast — wait 9s…" }`
**400** invalid (`{error:"invalid"}`) · **401** `{error:"auth"}` · **403** `{error:"closed"}`/forbidden · **404** `{error:"not_found"}` · **405** `{error:"method"}` · **5xx** `{error:"server"}`

## Behaviour notes for the wiring UI
- **Throttle** ~10s/author (`_bbp_throttle_time`); keymasters/mods bypass. On 429,
  disable submit + count down `retry_after`, then re-enable.
- **Moderation** → 202: show "awaiting moderation", don't optimistically insert.
- **Media**: pre-upload to `/wp-json/buddyboss/v1/media/upload` (needs the REST nonce
  from `GET /bb-mirror-api/v0/auth`), then pass the returned `upload_id`s as `media_ids`.
- **Nesting**: `reply_to` = the parent reply id. The mirror flattens to 2 visual tiers.
- **Sync**: the new reply lands in Postgres via the bb→pg sync hook within ~1–2s;
  surfaces can optimistic-insert from the 200 payload meanwhile.
- **Permalink** points at /hub/ (the reader). v1 fallback: link out to /hub/ to reply.
