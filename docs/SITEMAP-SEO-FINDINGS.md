# sitemap-seo (backlog #3.5) — measured findings + recommendation

Lane: `sitemap-seo`, branch `sitemap-seo`. Parked 2026-07-31 on keeper's budget
hold, BEFORE any code was written. Everything below is measured, not inferred.
Sources: live via `ssh live-ro` (nginx logs 17–31 Jul, 2,187,338 lines; live PG
`looth`; live MySQL `looth_import`), dev2 PG.

---

## 0. TL;DR for keeper

Three of the charter's premises are wrong in ways that change the work. Read §1
and §4 before approving anything.

| Charter said | Measured reality |
|---|---|
| Reuse `lg_following_topic_url()` — "it already resolves a topic to its canonical hub URL" | It **deliberately does not**. It emits `/hub/?topic=…`, which live robots.txt **Disallows**. Unusable for a sitemap. |
| Mirrored legacy forum is crawled because "robots.txt does not disallow it" | It is **already 301'd** — but *every* legacy URL lands on bare `/hub/`. That is a soft-404 pattern, which is *why* the URLs linger in the index. |
| "/shop/ + /merch/ defunct" | **/shop/ is alive and actively maintained** (Loothtool storefront, live JSON catalog, linked from the homepage/hub/events). Only **/merch/** is defunct. |

No DB grant is needed. Deploy is a plain pull.

---

## 1. `lg_following_topic_url()` cannot be reused (charter premise is wrong)

`membership-pages/lib/following-data.php:308` returns

```
/hub/?topic=<urlencoded forum-slug/topic-slug>
```

a query param on the **feed** route that auto-opens the discussion modal. Its own
docblock is explicit that this is not the canonical form:

> "…it is why this does NOT link `/hub/<forum>/<topic>/` — that is the standalone
> permalink, the right thing to SHARE and the wrong thing to arrive at from your
> account page."

Two independent disqualifiers for sitemap use:

1. **It is not the canonical URL.** Google needs the permalink.
2. **Live robots.txt blocks that exact shape.** `Disallow: /hub/?` (added for
   faceted filter URLs). Submitting a sitemap full of robots-blocked URLs is the
   same self-contradiction the sitemap header already refuses to commit for
   noindex pages.

`Disallow: /hub/?` does **not** block `/hub/<forum>/<topic>/` — a prefix match on
the literal `/hub/?` cannot match a path segment. The permalink is safe to submit.

**What IS reusable** is the rule the function encodes: a topic in a non-public
forum gets no URL, because `_single-topic.php` gates both its lookups on
`f.visibility = 'public'` (:48) and the cold fetch 404s. That guard must be
shared, not re-derived — which is the real intent behind "do not write a 4th URL
builder."

**Proposed:** one dependency-free `lg-shared/hub-topic-url.php` owning both
shapes (`lg_hub_topic_permalink()` + `lg_hub_topic_deeplink()`) behind a single
visibility guard; `sitemap.php` takes the permalink, and `lg_following_topic_url()`
delegates to the deeplink so the guard has exactly one owner. The
following-data.php leg is a pure refactor and must be gated byte-identical across
all topics before it ships — that file is live-critical (it caused a P0 on 7/31)
and Ian-approved as-is.

## 2. Root cause of the zero discussions — confirmed

`tools/cut/forums-grant.sql` records it: *"the content_item kind=discussion sync
was retired 6/5"*. The sitemap's content section reads `discovery.content_item`,
so when discussion rows stopped being synced there, discussions silently left the
sitemap. Nothing replaced them.

Live sitemap today — matches keeper's count exactly:

| section | `<loc>` count |
|---|---|
| static | 2 |
| content | 655 |
| profiles | 1,734 |
| **total** | **2,391** |

**1,322** live topics qualify (`status IN ('publish','closed')`, forum
`visibility='public'`) and are absent. All 1,322 are `tier_gate='public'`, and
`_single-topic.php` states reads are not tier-gated — so there is no paywalled URL
to accidentally advertise.

**No grant needed.** `has_schema_privilege('archive-poc','forums','USAGE')` and
`SELECT` on `forums.topic` + `forums.forum` are already `t` on **both live and
dev2** (granted by `tools/cut/forums-grant.sql` for the front page). The sitemap
runs as that role.

`forums.topic` carries `modified_at` and `last_active_at` — either gives a real
`<lastmod>`, unlike the content section's `published_at`.

## 3. The mirrored legacy forum is `/all-forums-all-topics/`

Found by asking what Googlebot actually fetches, not by guessing. Over 15 days
Googlebot hit `/all-forums-all-topics/reply/` 35× and `/all-forums-all-topics/topic/` 20×.

`/forums/`, `/forum/`, `/forums-poc/`, `/groups/` all already 301 → `/hub/`. So do
the legacy topic URLs — **and that is the bug**:

```
/all-forums-all-topics/topic/all-mahogany-martin-1-top-thickness/  301 → /hub/
/all-forums-all-topics/topic/fishman-rare-earth-hiss/              301 → /hub/
/all-forums-all-topics/topic-tag/bracing/                          301 → /hub/
```

Every distinct legacy URL redirects to **one generic page**. Google treats a
many-to-one redirect as a **soft 404**: it does not transfer authority and it does
not consolidate the URL, so the old URL keeps its own index entry. That is exactly
the reported symptom, and it means a blanket `Disallow` would make things *worse*
(a disallowed URL cannot be recrawled, so it stays indexed with no content —
already flagged in the charter).

**Recommendation: per-topic 301**, `/all-forums-all-topics/topic/<slug>/` →
`/hub/<forum-slug>/<slug>/`. Verified feasible: legacy slugs map 1:1 onto
`forums.topic.slug`, which knows its forum. Spot-checked 4/4 —

```
all-mahogany-martin-1-top-thickness        → quick-questions
fishman-rare-earth-hiss                    → quick-questions
binding-tape-for-satin-nitro-lacquer       → quick-questions
grain-filling-with-epoxy-and-compatibility → quick-questions
```

(live MySQL has 1,337 published `topic` posts; live PG has 1,322 public — the gap
is the hidden/private forums, which correctly have no hub URL and should keep
redirecting to `/hub/`.)

This beats a canonical tag: the legacy pages are already gone, so there is no page
left on which to put a canonical tag. A canonical is the gentler option only when
both URLs still serve content.

## 4. /shop/ is NOT defunct — do not touch it

15-day traffic. `/shop/` earns **27 requests, 24 of them real browsers**
(iPhone/Android/Mac/Windows), only 2 bots:

```
referrers: loothgroup.com/ (8), /front-page/ (6), /hub/ (3),
           /hub/?sort=random&show=3d-club (1),
           /hub/3d-printing/cool-organizer/ (1), /events/ (1)
```

It is **linked from the live homepage, front page, hub and events**, and it
renders a real, current storefront:

- `<title>Loothtool — Shop luthier tools & gear</title>`
- purpose-built page skinned to loothtool.com's *current* theme
- catalog fetched live from `/shop-feed.json` → **200, 8,837 bytes**, real stock
  ("Fret Whapper – Hand Tool Handle for StewMac Fret Press Cauls", $34.99, …)

Changing its status code would break live internal navigation for real members —
the exact `/shop-layout-planner/` mistake the charter warns against.

**Recommendation: leave `/shop/` at 200 and indexed. No change.**

## 5. /merch/ is the genuinely defunct one — 301, not 410

`/merch/` is a WordPress page whose entire body is an **unrendered WooCommerce
shortcode** (WooCommerce is gone), served under the retired BuddyBoss theme:

```
Merch
[product_categories number="9" columns="1" hide_empty="1" orderby="slug" order="desc"]
```

…with the old nav (CONTENT / CALENDAR / FORUMS / LOOTHS / CONNECT) and old footer.
It is `<link rel="canonical" href="https://loothgroup.com/merch/">` and
`robots: max-image-preview:large` — i.e. fully indexable. This is what Ian is
seeing.

But it is **not trafficless**: 106 requests / 15 days, of which

- **6 arrive from `https://www.google.com/`** — live organic search entries
- 20 from `loothgroup.com`, 2 from `m.facebook.com` — still linked
- 44 are the `/merch` → `/merch/` 301; the rest are bots (Amzn-SearchBot 28,
  bingbot, Applebot, PetalBot)

**Recommendation: 301 `/merch/` → `/shop/`.** It preserves the accumulated
authority and keeps the 6 organic entries and the social/internal referrals
landing somewhere real, and it immediately stops humans seeing a raw shortcode.
410 would discard authority *and* dead-end live inbound links — strictly worse
while real referred traffic still arrives.

⚠️ **One judgement call for Ian, not for me:** `/merch/` was Looth-branded merch;
`/shop/` sells Loothtool *tools*. Related but not equivalent, and Google may treat
a non-equivalent 301 as a soft 404 anyway. If Ian wants merch to come back, the
right move is `noindex` + keep the URL parked rather than redirect it away. The
internal homepage link to `/merch/` should be repointed either way.

---

## 6. Deploy shape (no coupling traps)

`/srv/lg-shared` and `/srv/archive-poc` are **directory** symlinks into
`loothplatformv2-clean`, so a *new file* under `lg-shared/` and an edit to
`archive-poc/web/sitemap.php` both arrive with a plain
`git -C ~/loothplatformv2-clean pull --ff-only origin main`. No per-file symlink
step (unlike mu-plugins), no grant, no migration.

## 7. Remaining work when unparked

1. `lg-shared/hub-topic-url.php` + `discussions` section in `sitemap.php`
   (register in the index alongside static/content/profiles).
2. Delegate `lg_following_topic_url()` to the shared guard — gated byte-identical.
3. Per-topic 301 map for `/all-forums-all-topics/topic/<slug>/` (nginx map or PHP;
   nginx reload on live is Ian's).
4. `/merch/` disposition once Ian rules on §5.
5. **Gate**: sitemap contains discussion URLs AND a sample resolves 200 to the hub
   *anonymously* — assert both, since a member-only 200 would false-pass. Register
   it from `main`, not this branch (numbering collides otherwise).

### Sanity note on the gate

Verify anonymously with a **live** slug — dev2 and live hold different data and a
slug from one 404s on the other. Live slugs to test with:

```
/hub/general/off-gassing/
/hub/acoustic/discoloration-inside-acoustic-guitar/
```
