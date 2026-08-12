# Legacy URL redirects — MEASURED STATE ON LIVE, 2026-08-09

Backlog 3.5, folded into the hub-seo-landing lane. Everything below is measured
on **live**, read-only, against the origin (`-k --resolve …:127.0.0.1` — a plain
public curl gets Cloudflare-challenged into a 403 that reads as an outage).

**Headline: most of 3.5 is already done and shipped.** The per-URL topic
redirects — the charter's main deliverable — are live and correct for both legacy
topic shapes, including one the original findings never looked at and which turns
out to be the *largest* by traffic. What remains is ~89 requests/window across
three shapes, and each needs a different disposition rather than a single rule.

---

## What Googlebot and friends actually request

Source: `/var/log/nginx/dev.loothgroup.access.log{,.1}` (469,904 lines).

⚠️ Not `access.log` — that file holds 2,537 lines and **zero** legacy hits. Read
it and you would conclude the legacy traffic had stopped entirely. The vhost logs
to `dev.loothgroup.access.log` (150MB) despite the retired-sounding name.

| shape | reqs | current behaviour | verdict |
|---|---:|---|---|
| `/groups/<group>/forum/topic/<slug>/` | **185** | 1 hop → `/hub/<forum>/<slug>/`, **200** | ✅ correct |
| `/all-forums-all-topics/topic/<slug>/` | 76 | 1 hop → `/hub/<forum>/<slug>/`, **200** | ✅ correct |
| `/all-forums-all-topics/topic-tag/<tag>/` | 55 | 1 hop → bare `/hub/` | ❌ many-to-one |
| `/all-forums-all-topics/reply/<id>/` | 23 | 1 hop → bare `/hub/` | ❌ many-to-one, **and resolvable** |
| `/all-forums-all-topics/page/<n>/` | 11 | 1 hop → bare `/hub/` | ⚠️ many-to-one, arguably right |
| `/merch/` | 9 | **200** | Ian's ruling, see below |
| `/forums/topic/<slug>/` | 8 | **2 hops** → canonical, 200 | ✅ correct, one hop more than needed |
| `/shop/` | — | 200 | ✅ **ALIVE — do not touch** |

## Three corrections to the charter

**1. The per-topic redirects already ship.** `bb-mirror/api/v0/seo-redirect.php`
(on main since the cut lane, 2026-06-15) resolves a slug → canonical permalink
and 301s, never 404ing. `platform/nginx/strangler-bb-mirror.conf` already routes
the legacy shapes into it, and that conf is symlinked into the serving checkout on
**both** dev2 and live. Verified by request, not by reading the conf.

**2. The biggest legacy shape was never in the original findings.**
`/groups/<group>/forum/topic/<slug>/` draws **185** requests — more than twice
`/all-forums-all-topics/topic/<slug>/` (76), which is the only one §3 of
SITEMAP-SEO-FINDINGS.md measured. It is already handled correctly; the point is
that an inventory anchored on one prefix missed the larger one, so the remaining
work below is scoped from the log, not from the prefix list.

**3. "Still 301 many-to-one to bare /hub/" is true only of the leftovers.**
It does not describe the topic shapes, which resolve per-URL today. It describes
`topic-tag`, `reply` and `page` — 89 requests between them.

⚠️ `grep -r` **skips symlinks**, which is why an early check of `/etc/nginx/`
found no `all-forums-all-topics` and suggested the wiring was missing. It was
installed the whole time. Grep the symlink target, or `grep -c` the file directly.

## What is actually left, and what each one needs

### `/all-forums-all-topics/reply/<id>/` — 23 reqs — DO THIS ONE
The only leftover that is genuinely resolvable per-URL. `forums.reply.id` joins to
its topic and forum, so `<id>` → `/hub/<forum>/<topic>/`. **Verified on live:**
both sampled ids (14770, 16948) exist in `forums.reply`; on dev2, 5,110 replies
join to a public, published topic.

Recommended: extend `seo-redirect.php` with a numeric-id branch (it already owns
"resolve or fall back to /hub/, never 404"), and route
`^/all-forums-all-topics/reply/(\d+)/?$` to it. One new rewrite, one new query, no
new endpoint.

### `/all-forums-all-topics/topic-tag/<tag>/` — 55 reqs — NOT a 301 target
There is nowhere per-URL to send these. The hub's tag view is `/hub/?…`, and live
`robots.txt` carries `Disallow: /hub/?` — so a 301 into it points Google at a URL
it is forbidden to fetch, which is worse than the soft-404 it replaces.

Two honest options, and this is a judgement call rather than a fix:
- **410 Gone** — tells Google to drop the URL. Correct if tag archives are not
  coming back. No authority is lost that a tag archive plausibly held.
- **Leave at `/hub/`** — status quo; the URLs linger in the index.

I lean 410, but it is a "do we ever want tag archives again" question, not a
technical one.

### `/all-forums-all-topics/page/<n>/` — 11 reqs — leave it
Pagination of the old index. Bare `/hub/` genuinely *is* the equivalent page, so
this is many-to-one by nature and not the harmful shape. Not worth a rule.

### `/forums/topic/<slug>/` — 8 reqs — optional polish
Correct, but takes 2 hops via an intermediate `/hub/topic/<slug>/`. Google follows
several hops, so this is tidiness, not a defect. Worth collapsing only if that
intermediate is touched for another reason.

### `/merch/` — 9 reqs — BLOCKED ON IAN, not on me
SITEMAP-SEO-FINDINGS.md §5 already put the choice to him and it has not been
answered: 301 to `/shop/` preserves authority and stops humans seeing a raw
WooCommerce shortcode, but merch (Looth-branded goods) and shop (Loothtool tools)
are not equivalent, so Google may treat it as a soft 404 anyway. If merch is
coming back, `noindex` + park the URL is the better move. **Not a technical call.**

### `/shop/` — leave at 200, indexed
Alive, actively maintained, linked from the homepage/front page/hub/events, live
JSON catalog. Changing its status would break live internal navigation for real
members.

## Deploy shape

`platform/nginx/strangler-bb-mirror.conf` is symlinked into the serving checkout
on both boxes, so a rewrite lands with a plain pull — **but nginx must be
reloaded**, and on live that reload is Ian's. `seo-redirect.php` is under
`bb-mirror/api/`, which is a directory symlink, so it needs no per-file step.
