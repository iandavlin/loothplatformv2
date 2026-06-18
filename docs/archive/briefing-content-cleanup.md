# Lane briefing — CONTENT CLEANUP (conversions + discovery hygiene), 2026-06-11

**You are a lane chat on the dev box** (`curl ifconfig.me` → 50.19.198.38, you are `ubuntu`, full sudo — act locally, never SSH out). Read `~/.claude/CLAUDE.md` first.

## Scope / ownership
- **Yours:** legacy-post conversion artifacts (WP managed-CPT posts + their `_lg_layout_v2` meta), the `discovery` Postgres store (`article_blobs`, `content_item`) **hygiene**, `posts/conversions/**` working folders.
- **NOT yours:** the Hub (`bb-mirror/**`), the standalone renderer code (`archive-poc/standalone/**` — fix DATA, not the engine; flag engine bugs to Ian), Buck's overlay, the front page.

## Render-routing facts (read before "testing a fix")
- Managed-CPT articles are served by the **archive-poc standalone renderer** from baked blobs, NOT the WP template. `?lg_edit=1` forces the WP path.
- Viewing a tier-gated post as anonymous hides member content (gated chapter links/video) — **that's the gate working, not a bug**. Check `post_tier` before "fixing."
- `_lg_layout_v2` meta is a JSON string (FE editor) or PHP array (CLI import) — `wp eval` code must `if (is_string($L)) $L = json_decode($L, true)` or it fatals. Patch live posts via `wp eval` + `update_post_meta`, never export-to-/tmp + python.

## Work queue (in order)
1. **Duplicate-post audit + dedupe.** Conversion re-runs CREATED duplicate WP posts (same slug) instead of reusing; the slug-keyed blob lookup (`LIMIT 1`, no tiebreaker) can then serve the stale/placeholder render. Inventory dupes (same slug, same CPT), pick the survivor (the imported/stamped one with the real layout), trash the placeholders, and add a deterministic tiebreaker to the slug-keyed blob lookup so it can't regress.
2. **Placeholder-video pollution.** Conversions were meant for VIDEOS only — image articles wrongly got placeholder videos baked in. Sweep image-article conversions for placeholder video blocks and strip them; re-materialize the blob.
3. **Backup-survivor video layouts** (no importer stamp): they just need **materializing** (bake the blob), not re-parsing. Don't re-run the parser on them.
4. **Tier-vs-badge consistency sweep.** ≥1 video renders a "public" badge but its stored tier is lite/pro, so anon gets gated (Buck finding). Sweep `discovery.content_item.tier` vs the WP post's tier source; fix the mislabeled rows. Get the specific URL from Ian/Buck if needed.
5. **Sponsor-post figure numbers.** Sponsor-post conversions must NOT carry image figure numbers (ads aren't tutorials). Sweep + strip `number` from sponsor-post image blocks.
6. **Document the discovery split** (read-only unless Ian says otherwise): `article_blobs` is LIVE (serves CPT standalone); `content_item` duplicates the SQLite search index; both run in parallel mid-migration. **SQLite retirement is HELD pending a real soak — do not delete or "finish" the migration without Ian's explicit call.** Your deliverable: a short current-state doc + what a finish would take.

## Protocol
- Dev = small test fixtures only. **Bulk re-materializes/backfills run at cutover, not on dev** — prove the fix on a handful of posts, script the rest for the cut playbook.
- Commit your own increments promptly in clean, logical, TESTED steps (a git-tsar auto-sweeps the tree). Scripts go in `tools/` or the post's `posts/conversions/post-<id>/` folder. **Commit ≠ push. NEVER push.**
- Quality reference for image-article conversions: post 71106. Ian's image rule: portrait → side-by-side, square/wide → single.
- Verify renders over HTTP with the gate cookie + `chrome-dev-login` for browser checks; check BOTH the standalone path and `?lg_edit=1`.

## ⚖ PARITY GATE (Ian 2026-06-11 — standing rule, all lanes)
No new user-facing control or section ships on ONE surface without its
counterpart on the other (mobile <=640 / desktop >=641) **in the same change**,
or a written "tabled: <surface>, <why>" note in the commit + report-back.
Generalizes the 6/10 card-chip complement rule. Read-side profile markup is
ONE server render — keep it that way; never viewport-hide a section to fake
parity. Current ruling: profile privacy UI converges on the SLIDER panel
(canonical, both surfaces) — the chip rows retire when it lands.

## Report-back (end of session, verbatim format)
```
CONTENT-CLEANUP LANE report — <date>
SHIPPED: <fix + script + commit SHA(s); counts: N dupes removed, M blobs rebaked…>
VERIFIED: <how — which posts, which render path, anon + member>
OPEN: <queue remainder + new finds>
ASKS: <Ian decisions needed (e.g. discovery-migration call), cut-playbook items added>
```
