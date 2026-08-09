# Retiring `bb-mirror/web/forums/_single-topic.php`

**Status: STAGED, NOT DONE.** Phase 1 (this branch) makes the file unnecessary.
Phase 2 deletes it, and must not be merged until the preconditions below are met.

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

- [ ] **Ian has looked at the running thing and approved it.** Not the gates —
      him. `https://dev2.loothgroup.com/footer-mockups/hub-seo-landing/` is the
      before/after; the live pair is in it.
- [ ] `LG_HUB_TOPIC_LANDING` **default flipped to ON** in `bb-mirror/config.php`,
      merged, and serving on dev2's real `/hub/` mount.
- [ ] Gate 20 green **with the ON requirement demanded**, against the real mount:
      `LG_TL_REQUIRE_ON=1 python3 tools/gates/hub-topic-landing-gate.py`
- [ ] The three sampled sitemap URLs still 200 (gate 20 asserts this).

Until all four hold, `_single-topic.php` stays.

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

## Note on the no-op proof

`tools/gates/hub-topic-landing-noop.sh` is a **pre-merge** tool: it renders the
branch and its merge-base and diffs the bytes. After Phase 1 merges it has
nothing to compare and says so rather than printing a green. It is not part of
`run-all.sh` for that reason.
