# Retiring `bb-mirror/web/forums/_single-topic.php`

**Status: DONE — 2026-08-09.** Phase 1 shipped the landing behind an OFF-default
flag; Ian approved the running thing (*"I like it"*), the default flipped ON, and
Phase 2 (this) deleted the flag and the file. Kept as the record of what moved
off `_single-topic.php` rather than being lost with it — if any of the
behaviours in the table below regress, that table is where to look.

Ian, 2026-08-09: *"can we get rid of those legacy layout pages?"* — yes. This is
how, and why it is two merges instead of one.

---

## Why this is two merges and not one

Two standing rules meet head-on here:

1. **Every member-facing feature merges behind a flag, defaulted OFF, and OFF
   must be a proven byte-identical no-op.**
2. **Delete the legacy page.**

They contradict *within a single commit*: with the flag OFF, `case 2` has to
render **something**, and the only thing it can render byte-identically is the
file we are deleting. Collapsing them means either shipping the new layout
unflagged — straight into the failure mode the flag rule exists to prevent — or
shipping a flag whose OFF state is a 404.

So: Phase 1 makes the file **unreferenced except by the OFF branch**. Phase 2
removes the OFF branch and the file together, once the ON state is the only one
anybody is running.

## Preconditions for Phase 2

- [x] **Ian has looked at the running thing and approved it.** Not the gates —
      him. `https://dev2.loothgroup.com/footer-mockups/hub-seo-landing/` is the
      before/after; the live pair is in it.
- [x] `LG_HUB_TOPIC_LANDING` **default flipped to ON** in `bb-mirror/config.php`,
      merged, and serving on dev2's real `/hub/` mount.
- [x] Gate 20 green **with the ON requirement demanded**, against the real mount:
      `LG_TL_REQUIRE_ON=1 python3 tools/gates/hub-topic-landing-gate.py`
- [x] The three sampled sitemap URLs still 200 (gate 20 asserts this).

All four verified independently before executing, not taken on report.

## What Phase 2 actually changes

**Exactly one executable reference exists.** Re-verified on this branch:

```
bb-mirror/web/index.php:121   require __DIR__ . '/forums/_single-topic.php';
```

Everything else that mentions the file is prose — comments in `config.php`,
`forums.js`, `_topic-modal.php`, `api/v0/topic.php`, two gates, and
`lg-follow-digest.php`. Those are *explanations*, not links, but several are
now-historical and should be reworded in the same commit so nobody later reads
them as a pointer to a file that no longer exists. A stale citation reads as a
finding.

1. `bb-mirror/web/index.php` — drop the `if (lg_hub_topic_landing_enabled())`
   branch and the `require` beneath it; `case 2` becomes unconditional. Update
   the file's header block (line 13 still describes the old routing).
2. `bb-mirror/config.php` — remove the `LG_HUB_TOPIC_LANDING` define and its
   comment block.
3. `bb-mirror/web/forums/_topic-modal.php` — remove
   `lg_hub_topic_landing_enabled()`; nothing else reads the constant (it was
   written as the single read point precisely so this is a one-line removal).
4. `git rm bb-mirror/web/forums/_single-topic.php`.
5. `tools/gates/hub-topic-landing-gate.py` — the OFF branch of its state machine
   becomes unreachable. **Leave it in and set `LG_TL_REQUIRE_ON=1` as the default
   instead.** A gate that can still recognise the legacy layout is what will tell
   you if a bad deploy ever resurrects it; deleting that arm would blind it.

## What was already moved off it, and must not be lost again

These behaviours lived in `_single-topic.php` and were deliberately migrated
during Phase 1. Deleting the file does **not** delete them — but if any of these
regress after Phase 2, this list is where to look:

| Behaviour | Now lives in |
|---|---|
| forum+topic lookup, JOIN on **both** slugs (two forums share `slug='finish'`, so a forum-first lookup is non-deterministic) | `lg_topic_modal_lookup()` |
| **301 stale-deep-link rescue** (HK-017 / GH #48) — one live match by topic slug alone redirects; ambiguous stays 404 | `lg_topic_modal_rescue_slug()` |
| **404 page**, with the status set *before* any output (it once shipped HTTP 200 for a missing topic because chrome was emitted first) | `lg_topic_modal_route()` |
| Author-identity masks — `is_anon` → "Anonymous", member-only → "Private member" | `lg_topic_modal_lookup()`, via the shared maskers |
| OP `.fc-actions .fcr` reaction bar, which the cold deep-link path used to scrape | `_topic-modal.php` server-side, and `api/v0/topic.php?withrx=1` |
| Cold deep-link data source for the modal (`fetchStandalone`) | `/bb-mirror-api/v0/topic` — repointed in Phase 1 |

## Verification after Phase 2

```
bash tools/gates/run-all.sh                                   # all gates green
LG_TL_REQUIRE_ON=1 python3 tools/gates/hub-topic-landing-gate.py
python3 tools/preview/hub-landing-shots.py <outdir>            # 44 checks, anon
```

Plus, by hand, the paths that only the deleted file used to serve:

- a **re-categorised** topic URL still 301s to its canonical path;
- a **deleted** topic URL still 404s (and is still an HTTP 404, not a 200 page
  that says "not found");
- a **hidden-forum** topic still 404s through the landing route *and* the
  fragment API (gate 20 asserts both).

## What Phase 2 also removed, and why

Three things existed only to serve the flag, and outlived it:

- `tools/gates/hub-topic-landing-noop.sh` — proved the OFF path byte-identical.
  There is no OFF path now, so it has nothing to measure. It did its job twice
  (a stray newline, and its own empty-render false green) and is deleted rather
  than left to rot into a green that means nothing.
- `platform/nginx/lane-preview-hub-seo-landing.conf` — armed the flag at
  `/hub/preview-seo/`. The real mount now does exactly what the preview did.
  Torn down and deleted.
- The `LG_HUB_TOPIC_LANDING` constant and `lg_hub_topic_landing_enabled()`, its
  single read point. Written as one read point precisely so this was a one-line
  removal.

**Gate 20's OFF-recognising arm was deliberately NOT removed**, and
`LG_TL_REQUIRE_ON` now defaults to `1`. Nothing can serve the legacy layout
today — which is exactly why that arm is worth keeping. It is what would NAME a
bad deploy that somehow resurrected it, instead of leaving a generic failure to
be diagnosed from scratch. It costs one string compare.
