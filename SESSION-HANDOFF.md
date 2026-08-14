# SESSION HANDOFF — lane `hub-seo-landing`

**Written 2026-08-14 at context rotation. Assume you are a fresh lane with zero
context — this is your charter.** Everything below was measured, not inferred;
where I am unsure I say so.

| | |
|---|---|
| Branch | `hub-seo-landing`, pushed, tip **`d1c394b`**, **7 commits ahead of `origin/main`** |
| Serve | dev2 serves `main`; my unmerged work is visible only via the lane preview |
| Preview | `bash tools/preview/lane-preview.sh up hub-seo-landing` → `/hub/preview-seo/…` |
| Box | 2 cores. **Serial browsers.** One Chrome, one tab, never parallel |
| Fence | `bash tools/gates/buck-surface-guard.sh` before every push. Never touch `*buck*` |

---

## 1. What this lane has already shipped (merged, on dev2, live pending Ian's deploy)

- `/hub/<forum>/<topic>/` renders **the hub with that discussion's modal open**,
  with the discussion's text in the **server HTML**. `_single-topic.php` is
  deleted (`docs/HUB-SINGLE-TOPIC-RETIREMENT.md` records what moved off it).
- **Self-referencing canonicals** on topic landings and category pages; the
  `?sort=`/`?type=` variants fold into the bare category URL.
- **Legacy URL redirects**: `/all-forums-all-topics/reply/<id>/` resolves per-URL;
  `/shop-organisation/` → its hub category; `/featured-content/` → `/hub/`;
  `/merch/` → `https://loothtool.com/` **(apex — `www` answers 522)**. `/shop/`
  is alive and must stay 200.
- `docs/LIVE-DEPLOY-DELTA-hub-seo.md` — **read this before the live deploy.**
  Two of those changes need an **nginx reload**, not just a pull.

## 2. THE UNMERGED WORK — the Google door (7 commits)

Ian, 2026-08-12 (`docs/IAN-RULINGS-2026-08-12.md` items 7-8): category pages are
**Google doors** — they exist so Google can rank a topic area, rebuilt in the hub
look, **no member-facing nav added**, contents **option A** (discussions +
related content mixed).

**The door is a THIRD shape, and this is the trap:**

| | |
|---|---|
| legacy category tree | **NO** — member nav, and what the rebuild replaces |
| hub rail / chipbar | **NO** — adding it *adds* member nav, which ruling 7 forbids |
| hub cards | **YES** |
| content items | **YES**, where the category has any (see §3) |

⚠️ The obvious implementation — route the page through the hub's own category
filter — **hands it the rail for free** and would sail past any gate that only
asks "is the legacy tree gone". Absence of the OLD nav is not the goal; absence
of ANY member nav is.

**How it works:** it is *routing*, not a second feed. The hub's `leaves` filter
already yields the unified topics ∪ content query scoped to a forum, so the door
seeds that filter from the **recursive subtree** already computed for the scoped
branch, then takes the unified branch. Subtree and not the single forum because
**6 of the 45** public forums have children.

Files: `bb-mirror/config.php` (`LG_HUB_CATEGORY_DOOR`, **OFF by default**),
`bb-mirror/web/forums/_feed.php` (routing + chipbar suppression),
`bb-mirror/web/_chrome.php` (both nav branches suppressed via `__bb_hub_door`).

**Flag OFF is a proven byte-identical no-op** — 9 routes vs `origin/main`.
Re-prove it before merging; it took two goes, because wrapping one line in a
separate comment block leaked **2 bytes** on every hub page. A 2-byte diff is
still not a no-op.

## 3. ⚠️ THE THIN REALITY — 15 of 45, and it shapes expectations

Only **15 of the 45** public categories have **any** related content. Most doors
show **discussions only**. Option A is real where content exists; elsewhere the
door is a clean discussion listing, and that is correct behaviour, not a bug.

Ian has been shown **both** — `/footer-mockups/hub-door-built/` draws a rich
category and a thin one side by side, and says this in plain words, so his
approval is informed.

**Separately, and NOT ours:** for `amps-pickups-and-pedals` the hub's *own*
`?leaf=` filter returns **zero** content while 5 content rows carry a
matching-ish label. Checked with each of the two forum ids that share that slug
and with both together — same answer. The door faithfully reproduces existing hub
behaviour; the gap is upstream. Flagged to keeper, not worked around.

## 4. THE `cat_key` LESSON — read this before writing any content assertion

Content attaches to a category through **`hub_reconcile_cat_key(forum_label)`** —
a coarse bucket (`acoustic`, `builds`, `repair`, `tools`, …), **not** the forum
title and **not** the forum id. `leaves` (per-forum ids) filters **topics**;
content is bucketed by cat_key.

I got this wrong once in a way that *passed*: I had gate 25 ask the DB whether a
category had content by **exact `forum_label`**. It went green — for the wrong
reason, on a category with 5 matching-ish rows. **An exact-label query is a second
implementation of that mapping, free to drift from the product's.**

The gate now asks the **product**: `/hub/?leaf=<subtree ids>` is the very query
the door routes into, so the door must show what that view shows. Differential
and self-sourcing — the same technique that gates the visibility masks. **Do not
re-derive the mapping.**

## 5. Gate states

| gate | state |
|---|---|
| `hub-category-page-gate.py` (25) | **GREEN** over 6 categories with `LG_HC_REQUIRE_RAIL=1` against the preview. Names `DOOR` / `LEGACY` / `RAIL` separately so a failure says which wrong shape it found |
| `hub-topic-landing-gate.py` | GREEN on the serve |
| `legacy-url-redirect-gate.py` | GREEN on the serve |
| `stale-page-redirect-gate.py` | GREEN on the serve, incl. the external destination answering |
| `buck-surface-guard.sh` | clean |
| `subscription-preserved` (17) | **not ours.** Red in-suite, GREEN standalone — known load flake |

All four of this lane's gates **pace** their requests (`LG_GATE_PACE`, 0.45s).
This box's dev gate refuses correctly-cookied requests **in bursts** — a single
request always succeeds, `/gatetest` says `auth=1`, the token is byte-identical.
It is pacing, not authorisation. Do not "fix" it by loosening the cookie.

## 6. What I would do next

1. **`bash tools/gates/run-all.sh`** with per-gate attribution — never assume a
   red is yours; run the failing gate standalone before believing it.
2. Re-prove the flag-OFF no-op, re-run gate 25, then hand keeper a merge.
3. On Ian's word, flip `LG_HUB_CATEGORY_DOOR` ON — same two-phase shape as the
   topic landing: merge OFF, verify on the serve, flip, then retire the flag.
4. Open, needing **product rulings, not code**: `topic-tag` (55 reqs/window,
   410-vs-leave) and `page/<n>` (11 reqs — my recommendation is leave it).
5. Cosmetic, for Ian: the category header carries an **"ACTIVITY"** label under
   the title — almost certainly the "ACTIVITY banner" he mentioned. It survives
   the rebuild; removing it is a one-liner if he wants it gone.

## 7. Habits that earned their keep here

- **Measure before believing a premise**, including keeper's and mine. Three
  charter premises turned out wrong this lane, each in a way that changed the
  work; two of them were my own readings.
- **Assert the artefact, not just the page.** Two phone shots once came out
  byte-identical while every DOM assertion passed — Ian would have been asked to
  choose between the same picture twice.
- **A red you cannot attribute is not yours yet.** Run it standalone, on a clean
  main worktree, before blaming or absolving yourself.
- **Re-mint gate numbers immediately before pushing.** Mine moved four times in
  two days, and three table rows collided through *clean* auto-merges.
