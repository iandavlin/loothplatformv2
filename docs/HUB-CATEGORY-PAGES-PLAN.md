# /hub/&lt;category&gt;/ — rebuild plan (Ian, 2026-08-11)

**Status: PLAN. Nothing built yet** — keeper asked for the plan first.

Ian's ruling: the category pages were never rebuilt; they still render the legacy
forum-style view, carry no canonical and no robots meta, and Google lists them.
Google's sitelinks also point at dead/stale WP pages. Rebuild them properly, with
the same craft as the topic landing.

Everything below is **measured**, on dev2 and on live (origin, `-k --resolve`).

---

## 1. What is actually wrong — measured, and one premise corrected

| URL | status | canonical | robots | legacy `nav-tree` | feed cards |
|---|---|---|---|---|---|
| `/hub/` | 200 | 0 | 0 | **0** | 18 |
| `/hub/general/` (category) | 200 | **0** | **0** | **84** | 18 |
| `/hub/general/<topic>/` (landing) | 200 | 0¹ | 0 | **0** | 18 |

¹ canonical lands with this lane's unmerged branch.

**Corrected premise, and it matters for the fix.** The category page is *not* a
wholesale legacy view — it already renders the **new feed cards**. What it also
renders, and the hub and the topic landing do not, is the **legacy left category
tree**. My first reading was that `nav-tree` was shared chrome; the control above
disproves it — `/hub/` has zero. Ian's eye was right and mine was wrong.

There is no "ACTIVITY banner" in the served HTML on dev2; that string appears
nowhere. If Ian is seeing one, it is worth one screenshot before we chase it,
because it may be live-only or may be the nav-tree's own "All activity" root
link, which *is* there (`nav-tree__root-label`: "All activity").

### The mechanism, exactly

`bb-mirror/web/_chrome.php:775` — the legacy left nav renders only when
`$GLOBALS['__bb_hub_rail']` is empty:

```php
<?php if (empty($GLOBALS['__bb_hub_rail'])): ?>
  <aside class="bb-layout__nav" id="bb-nav"> … bb_mirror_left_nav() … </aside>
```

and `_feed.php:540` branches on `$scoped_forum`: the **unscoped** branch sets
`__bb_hub_rail` (the modern rail, `_feed.php:657`); the **scoped** branch never
does. So "category page" and "legacy rail" are the same condition.

That is also *why the topic landing looks right*: `index.php` clears
`forum_slug` before handing off, so the landing takes the unscoped branch. The
category rebuild is the same move, done deliberately.

## 2. Scope — per keeper's fence

**IN:** category pages; canonical/robots on them; the stale-WP and dead-sitelink
cleanup.
**OUT:** the sitemap. Ian ruled tonight that the list is final (all 1742 profiles
stay). Nothing here touches `sitemap.php`. Category pages are *not* in the
discussions sitemap today and this plan does not add them — worth a separate
decision later, not folded in silently.
**OUT:** anything of Buck's (`*buck*`), per the 2026-08-11 fence.

## 3. Proposed work, in order

### A. Red-first gate before any code
New gate asserting, for a sample of the **45 public categories**:
- the page renders the **hub rail**, not the legacy `nav-tree` (assert absence
  *and* presence — an absence assertion alone passes on a blank page);
- a **self-referencing absolute canonical**, reusing the `$canonical_path` seam
  already added to `_chrome.php` for the topic landing;
- the category's discussions are in the **server HTML** (same contract as the
  landing — content, not a JS fetch);
- hidden/private forums still **404**.

Red-first is cheap here: today every one of those fails on canonical, and all 45
fail on `nav-tree`.

### B. Category page renders the hub look — BEHIND A FLAG
Member-visible, so: `LG_HUB_CATEGORY_RAIL`, **OFF by default**, OFF a proven
byte-identical no-op, gated per state. The change is to make the scoped branch
set `__bb_hub_rail` with the category pre-selected as a filter, rather than
falling through to `bb_mirror_left_nav()`.

⚠️ The risk I want to flag now: the scoped branch and the unscoped branch build
**different query state**, and the rail's facet counts are computed in the
unscoped branch only. Making the scoped branch produce a rail is therefore not a
one-line condition flip — it is "compute the same rail state with a category
constraint". I will not know the true size until I read `hub_facet_counts()`
against the scoped clauses. **If it turns out large, I will come back with a
revised estimate rather than quietly building for a day.**

### C. Canonical + robots on category pages
Self-referencing canonical on the bare `/hub/<category>/`.

**Recommend canonical, NOT noindex.** A category listing is a real content
surface with 45 instances and internal links; noindex throws away a legitimate
surface. The thing that actually needs suppressing is the **filtered/sorted
variants** (`?sort=`, `?type=`, `?q=`) — those are near-duplicates. Note
`robots.txt`'s `Disallow: /hub/?` does **not** cover `/hub/general/?…` (it is a
literal prefix match on `/hub/?`), so the variants are currently crawlable.
Cheapest correct answer: canonical the variants to the bare category URL. One
rule, no robots.txt change, no new Disallow to get wrong.

### D. Stale WP pages + the dead sitelink — measured on live

| URL | live | proposal |
|---|---|---|
| `/shop-organisation/` | **404**, noindex | **301 → `/hub/shop-organisation/`** — the hub category exists and serves 200. A real per-URL target, exactly the shape that fixed the legacy topic URLs. Not a judgement call. |
| `/featured-content/` | 200, canonical-to-self, indexable | **Ian's call.** Old WP page. 301 to the nearest live surface, or noindex-and-park. |
| `/merch/` | 200, canonical-to-self, indexable | **Ian's call, and already pending** — `SITEMAP-SEO-FINDINGS.md` §5 put 301-to-`/shop/` vs noindex-and-park to him and it was never answered. Merch ≠ Loothtool tools, so a 301 may read as a soft 404 anyway. |
| `/shop/` | 200, alive | **Leave.** Actively maintained, linked from homepage/front/hub/events. |

So D splits: **one item I can just do** (`/shop-organisation/`), and **two that
need one sentence from Ian**. I will build the first and not guess the others.

## 4. What I need before building

1. **Keeper's go on this plan** (that is why it is a doc, not a commit).
2. **Ian, one sentence each:** `/featured-content/` and `/merch/` — 301 (to
   where?) or noindex-and-park.
3. **One screenshot** of the "ACTIVITY banner" Ian saw, if it is not the
   nav-tree's "All activity" root link — I cannot find that string on dev2.

Nothing in §A, §B, §C or the `/shop-organisation/` half of §D is blocked on
those; item 2 blocks only the two WP pages, and item 3 only a cosmetic detail.

## 5. Deploy shape

`_chrome.php`, `_feed.php` and the gate all arrive by a plain pull.
`/shop-organisation/`'s redirect is nginx — `strangler-*.conf` is symlinked into
the serving checkout, so it needs **a reload**, and on live that reload is Ian's.
Same coupling as the reply-permalink rewrite already waiting in this lane.
