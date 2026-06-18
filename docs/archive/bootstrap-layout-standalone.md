# Bootstrap — layout-standalone lane

You own the **layout-standalone** lane. Goal: render CPT post pages (articles +
events) **standalone — no WordPress boot** — so clicking into content is fast and
wears the unified shell, while authoring stays in WordPress.

## Read first (in order)
1. `docs/design-layout-standalone.md` — the full plan. §2 (materialize-on-save
   architecture) + §5 (gating security) are the spine.
2. **Load the `lg-layout-v2` skill** — patterns/gotchas for the render engine
   (cascade ordering, bundle/cache lifecycle, content-shape). Binding.
3. `docs/STRANGLER-COORDINATION.md` §0b (standalone launch invariant), §3i
   (mirror store + grants), the avatar/author-identity single-source section.

## The constraint that shapes everything (Ian)
"Post and manage posts from WordPress, just not render or front-end edit."
- Authoring + the layout editor STAY in wp-admin. Don't touch them.
- Rendering goes standalone, read-only. FE editor is OFF the standalone path.

## The architecture (don't reinvent — it's the strangler mirror pattern)
WP save hook resolves the layout + post/author/term/comment data ONCE (where the
WP functions exist) → writes a flat, fully-resolved artifact → standalone renderer
reads the artifact + a `/whoami` viewer array → portable render engine → shared
shell → HTML. Same shape as archive-poc/bb-mirror. The render engine is ~95%
portable already (CssBuilder, TierResolver, Renderer block dispatch = zero WP
calls); your job is to extract+host it and build the materializer.

## YOUR FIRST TURN — PoC, scoped tight (the ~3-day proof in one turn)
Do ONLY this; prove the lift before building the save-hook machinery:
- Stand up a standalone render harness (thin PHP entry, own/borrowed FPM pattern)
  that: reads a **hand-written materialized blob** for ONE real article (export
  its `_lg_layout_v2` meta + a stubbed PostContext: title/author/date/terms/avatar),
  runs the portable render engine, wraps it in `lg_shared_render_site_header()` +
  footer, emits full HTML. **No WP boot in the render path.**
- Apply gating at render from a viewer array (§5): render once as `public`, once as
  a paid tier; prove a gated block shows its gate-CTA to public and its payload to
  paid — and that the **raw blob never goes to the wire** (gated payload absent in
  the public HTML).
- Write `RENDER-STANDALONE-POC.md`: what you stubbed, what the save-hook
  materializer must produce (the exact PostContext shape), and the §6 open
  questions you hit (esp. the HOST decision — lean: extend archive-poc).

**Do NOT** in turn 1: write the WP save hook, touch the live the_content path,
touch authoring/the dash/the FE editor, or install anything. Pure dark-launch PoC.

## Security (design §5 is binding)
- Gating at RENDER, per viewer. TierResolver is portable — use it.
- Never serve the raw blob; serve rendered+gated HTML only. A gated block must not
  reach the wire for a viewer who can't see it.
- Fail-closed: absent/unknown viewer → public; gated → gate-CTA, never payload.

## Coordination / discipline
- WRITE-ONLY turns (sandbox blocks git/`php -l`/CDP). Coordinator commits by
  pathspec + lints + tests after.
- Edit in the repo; never hand-edit deployed copies (§0).
- The render engine is shared with the live WP plugin — **copy/extract, don't
  move**; the live the_content path must keep working untouched.
- HOST decision (archive-poc vs new service vs lg-layout-v2 standalone mode) is an
  §6 open question — flag the coordinator, don't unilaterally wire into another
  lane's surface.

## Report-back
```
**layout-standalone → coordinator:** <one-line status>
<changed files for pathspec commit> · <what coordinator must test> · <flags + open Qs>
```

— coordinator
