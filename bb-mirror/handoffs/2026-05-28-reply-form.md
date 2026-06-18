# BB-mirror — Session Handoff (2026-05-28, queue #1 reply form JS shipped)

## What this project is

Read-side strangler for BB/bbPress forum threads. Reads from postgres mirror
at native speed; writes round-trip through BB REST. Mu-plugin syncs WP→pg
in real time; systemd timer reconciles every 10 min.

Scope contract: [STRANGLER-COORDINATION.md §3f](../docs/STRANGLER-COORDINATION.md).
Storage: [§3i](../docs/STRANGLER-COORDINATION.md).
Reply form briefing: [reply-to-bb-mirror-reply-form.md](../docs/reply-to-bb-mirror-reply-form.md).

## Current state — reply form shipping end-to-end

Authenticated viewers can now post replies; anonymous viewers see a sign-in CTA.
The full write→sync→render loop is closed.

### Verified end-to-end (2026-05-28 13:44 UTC)

```
POST /wp-json/buddyboss/v1/reply  →  200 (reply 69463 created)
   ↓  bbp_new_reply fires
mu-plugin → POST /bb-mirror-api/v0/_sync (loopback)
   ↓  ~3s
SELECT * FROM forums.reply WHERE id=69463  →  row present, content_text correct, author_name "Ian B Davlin The Looth Group"
```

Cleaned up after test — reply deleted in WP, `bbp_deleted_reply` fired, row dropped from pg.

## What shipped this session

| File | Role |
|---|---|
| [api/v0/auth.php](api/v0/auth.php) | **new** — browser-callable, dev-cookie-gated, runs on WP pool. Returns `{authenticated, wp_user_id, display_name, nonce}` |
| [web/forums.js](web/forums.js) | **new** — single file: auth fetch on load, anon/authed state swap, per-post Reply button prefill, submit handler POSTing to BB REST with `X-WP-Nonce`, reload on 200 |
| [web/forums/_single-topic.php](web/forums/_single-topic.php) | Reply form rewritten as three-state wrapper (`loading | anon | authed`); each post now carries a hidden `Reply` button revealed by JS for authed viewers |
| [web/forums.css](web/forums.css) | New rules for `.reply-form--anon/--authed/--loading`, `.reply-form__replying-to`, `.post__reply-btn` |
| `/etc/nginx/snippets/strangler-bb-mirror.conf` | New `location = /bb-mirror-api/v0/auth.php` (NOT loopback — dev-cookie-gated). `_sync.php` stays loopback-only. |
| (no schema change) | |

## How the gating actually works today

| Viewer | What they see |
|---|---|
| No dev cookie (outside-LAN visitor) | nginx 403 from cookie gate, never reaches the page |
| Dev cookie, no WP login | "Sign in to post a reply" → links to `/wp-login.php?redirect_to=…` |
| Dev cookie + WP login | Textarea enabled, submit POSTs to BB REST with the X-WP-Nonce |

Group-membership gating (e.g. "Join SoCal to post here" on group-attached forums) is **not yet wired** per the brief — it's the future hook point. When `/whoami` ships with `groups[]`, the JS will check `effective_group_id` against the viewer's memberships and swap in a "Join <group> to post" CTA. BB REST enforces membership server-side as a backstop in the meantime.

## Threaded replies

The "Reply" button on each post (revealed when authed) prefills `parent_reply_id` and surfaces a "↩ replying to <author>" banner above the textarea with a "cancel" link to clear it. Submitting with `parent_reply_id` set passes `reply_to` to BB REST — same code path the existing 1,592 threaded replies came from.

## Files changed this session — full diff scope

- **new files:** `api/v0/auth.php`, `web/forums.js`
- **edited:** `web/forums/_single-topic.php` (form + per-post Reply buttons), `web/forums.css` (new state styles), `/etc/nginx/snippets/strangler-bb-mirror.conf` (auth route + cookie-gate scope correction)
- **system:** nginx reloaded; no FPM pool changes (`auth.php` rides the existing `looth-dev` socket); no schema migration

## Notes / gotchas

- **`auth.php` rewrite from `/bb-mirror-api/v0/auth` to `/auth.php` does NOT fire.** Tried to give the endpoint a clean URL; nginx's `alias` resolves the URI to a filesystem path before the rewrite fires (the `_sync` rewrite works because the mu-plugin hits the exact same shape and nginx-fastcgi handles it differently). JS just calls `/bb-mirror-api/v0/auth.php` directly — no rewrite needed.
- **Cookie-gate placement matters.** Putting `if ($loothdev_is_authorized != 1) { return 403; }` on the OUTER `/bb-mirror-api/v0/` block would 403 the loopback `_sync.php` POSTs (which don't carry the dev cookie). The gate must live INSIDE the `auth.php`-specific nested location. Verified _sync still works after the snippet edit.
- **Both cookies required for write.** Browser fetches/POSTs from the same origin get the WP `wordpress_logged_in_*` cookie automatically. CLI curl tests need to send both the dev `loothdev_auth` and the WP login cookie — easy to forget when testing.
- **`bbp_new_reply` fires on REST writes.** Verified — the BB REST endpoint internally calls `bbp_new_reply_handler` which fires the hook. Our mu-plugin catches it like any other reply.
- **Reload pattern, no client-side render.** Same as lg-fe-editor's pattern — server-rendered HTML stays canonical. The ~3s round-trip (REST POST returns, sync fires async, page reloads ~800ms later) is well under "feels broken" threshold.

## Postgres infrastructure on dev (unchanged)

- DB `looth`, schema `forums`, role `bb-mirror`
- 9 tables; 55 forums, 1128 topics, 4405 replies (1592 threaded), 465 persons, 20 bp_groups

## Next session queue

1. **Search box** — FTS index populated; UI not built. Single input on the topic-list page → `topic.search_doc @@ plainto_tsquery('english', :q)` results page
2. **`forum_read_state` "mark seen" endpoint** — table exists, endpoint not built. Powers unread/NEW chrome
3. **Attachment harvest** — schema in; harvest job not built. Source priority: `_bbp_attachment_*` → BB Platform `bp_media` → inline `<img>` URLs from `post_content`
4. **Sticky topics** — `_bbp_sticky_topics` (CSV on forum, not topic) not read at backfill
5. **Retire SQLite fallback** — rollback window long passed
6. **Group-member-aware private visibility + reply-form group gating** — needs `/whoami` + user-group memberships. One-line addition to `forums.js` once `/whoami` ships

## How to test

```bash
TOK=$(sudo grep -E 'set \$loothdev_token' \
  /etc/nginx/sites-available/dev.loothgroup.com.conf | \
  head -1 | grep -oE '"[^"]+"' | tr -d '"')
curl -s "https://dev.loothgroup.com/claim?t=$TOK" -c /tmp/bbjar -o /dev/null

# auth endpoint — anon
curl -s -b /tmp/bbjar https://dev.loothgroup.com/bb-mirror-api/v0/auth.php
# expected: {"authenticated":false}

# Render a single topic — form, per-post Reply buttons (hidden until JS reveals)
curl -s -b /tmp/bbjar https://dev.loothgroup.com/forums-poc/general/stripped-out-trussrod/ \
  | grep -cE 'reply-form-wrap|post__reply-btn|forums.js'
# expected: 20+ (one wrap, many reply-btns, one script tag)

# End-to-end write (CLI simulation of what the browser does)
cd /var/www/dev
WP_COOKIE=$(sudo -u www-data wp eval '$exp=time()+3600;$m=WP_Session_Tokens::get_instance(1);$t=$m->create($exp);echo wp_generate_auth_cookie(1,$exp,"logged_in",$t);' 2>&1 | tail -1)
COOKIE_NAME=$(sudo -u www-data wp eval 'echo "wordpress_logged_in_".COOKIEHASH;' 2>&1 | tail -1)
NONCE=$(curl -s -b /tmp/bbjar -b "$COOKIE_NAME=$WP_COOKIE" \
  https://dev.loothgroup.com/bb-mirror-api/v0/auth.php \
  | python3 -c "import json,sys;print(json.load(sys.stdin)['nonce'])")
TID=56941; FID=3876   # pick any real topic + its forum
curl -sk -b /tmp/bbjar -b "$COOKIE_NAME=$WP_COOKIE" \
  -X POST "https://dev.loothgroup.com/wp-json/buddyboss/v1/reply" \
  -H "Content-Type: application/json" -H "X-WP-Nonce: $NONCE" \
  -d "{\"content\":\"smoke test\",\"topic_id\":$TID,\"forum_id\":$FID}"
sleep 3
sudo -u bb-mirror psql -d looth -c "
  SELECT id, substring(content_text,1,40), to_char(sync_at,'HH24:MI:SS')
    FROM forums.reply WHERE topic_id=$TID ORDER BY id DESC LIMIT 1;"
# Clean up: wp post delete <reply_id> --force, do_action('bbp_deleted_reply', <reply_id>)
```

## Pointers

- Coordination doc: [/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md](../docs/STRANGLER-COORDINATION.md)
- Reply form briefing: [/home/ubuntu/projects/docs/reply-to-bb-mirror-reply-form.md](../docs/reply-to-bb-mirror-reply-form.md)
- P5 briefing: [/home/ubuntu/projects/docs/reply-to-bb-mirror-p5.md](../docs/reply-to-bb-mirror-p5.md)
- Audit briefing: [/home/ubuntu/projects/docs/reply-to-bb-mirror-audit-findings.md](../docs/reply-to-bb-mirror-audit-findings.md)
- Mockup v2: https://dev.loothgroup.com/mockups/forums.html
- Prior handoffs: [handoffs/](handoffs/) — latest before this is `2026-05-28-p5-reconcile.md`

## Handoff rotation

When superseding this file, rename `handoffs/YYYY-MM-DD[-suffix].md` and write
fresh per the project schema in [/home/ubuntu/projects/CLAUDE.md](../CLAUDE.md).
