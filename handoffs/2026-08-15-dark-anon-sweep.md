# dark-anon-sweep — state note (2026-08-15)

**RESOLVED, not parked.** Fleet-quiet order (Ian browsing dev2) came and went;
resumed by keeper once his look was done. Gate 36 ratchet reshape is complete
and self-consistent; committing next.

## What changed since the last note
- Live verification runs (post-resume) surfaced a REAL finding: two clean
  live-injection captures of the identical post-merge state, ~40 min apart,
  disagreed by 2–8x on several surfaces (box load swung 0.4–7.1 with other
  lanes' gates running concurrently). Traced one contributing bug — `measure()`
  only cleared localStorage on the os-dark path, so `lg-set-boot` (written by
  app-settings.js after any dark resolution) could leak into the next
  app-dark test and flip it onto a different render path — and fixed it, but
  the swings persisted after the fix, so the dominant cause is CPU-contention
  timing (wall-clock settle delays, not event-based) rather than harness
  state leakage alone.
- Given that, `BASELINE` is now the per-surface **max of two independent
  captures** (146 findings total, not a single run's number) — verified by
  direct computation that both source datasets read at-or-under the new
  BASELINE on every surface (zero violations). Documented in both the gate's
  module docstring and `docs/CRAFT-STANDARD.md` row 36, including why a
  single-run baseline would have been worse than no gate at all (flaps red
  on noise, teaches people to ignore it).
- `_ratchet_selftest()` still passes (5/5) after the BASELINE update.
- Buck-fence guard clean.

## Next — DONE (commit `d006718`, rebased, pushed, identical to origin,
reported to keeper on the board). Blocked on keeper's merge action only;
queued behind the featured-fix train.

## Next-wave scoping (analysis only, no code touched — from already-captured
sweep.json, no new browser load)

gate 36 covers 6 of the 12 swept surfaces (signin/lostpassword/bpnoaccess/
join/lgjoin/front). The other 6 — hub-door (60), hub (28), events (20),
directory (14), shop (12), sponsors (4) = **176 findings** — are NOT gated
yet. Clustering them by normalized selector shows this is NOT 176 separate
bugs. Three SHARED COMPONENTS account for most of it, appearing near-
identically on almost every one of the 6 surfaces:

1. **`#looth-tabbar .lt-post-ico` icon (1.85:1)** — on hub-door, events,
   directory, shop, sponsors. This is the SAME "+" compose icon
   `LG_DARK_POST_ICON_FIX` already fixes (built, flagged OFF, gate-36-
   verified). It's a shared tabbar component — once that flag flips ON,
   every one of these instances resolves simultaneously. **Zero new work.**
2. **`.lpw-install` PWA banner button (2.29:1, `#ffffff` on `#9cb37d`)** —
   on hub-door, events, directory, shop, sponsors. NOT yet fixed anywhere.
   Same light-repoint pattern as the icon bug (a sage token flips too light
   under dark, foreground doesn't follow) but on a different shared
   component (the install banner, not the tabbar). One fix, every surface.
3. **`.avatar-init` avatar initials (3.12:1, `#ffffff` on `#87986a`)** — on
   hub, hub-door (5-7x per page, one per visible member). Same light-repoint
   family again, third shared component.
4. **Borderless search fields** (`.hub-tsearch__in`, `.lgev-input`,
   `#dir-loc`, `#q` — 1.0-1.07:1) — same `field-borderless` class the
   existing `LG_DARK_SEARCH_WRAPPER_FIX`/`DARK_BORDER` pattern already
   targets elsewhere; likely extends rather than needs a new mechanism.
5. **`.reply-stub__time` timestamps** (11x on hub-door alone, 4.33:1 vs
   4.5:1 needed — barely under) — one token nudge fixes all 11.
6. A few genuine one-offs: front page's Guitardle leaderboard (10, explicitly
   out of scope per CRAFT-STANDARD row 36 — different lane's surface),
   directory's Leaflet map attribution (3:1 vs need 4.5, `©`/`|`), events
   landing copy (2 near-miss paragraphs at 3.51:1).

**Estimate revision**: "several more waves to clear 176 findings" (the
CRAFT-STANDARD row 36 language) is pessimistic once root-caused — this
looks like 3-4 shared-component fixes (icon flip, install-banner token,
avatar-init token, maybe extend the search-border flag) plus a handful of
one-offs, not dozens of scattered patches. Not started — no go-ahead yet for
a next wave, and didn't want to hand keeper a bigger uncommitted diff right
as gate 36's merge is queued. Flagged to keeper on the board as an FYI, not
a request to proceed.

Not blocked on anything for gate 36. For a next wave: blocked on keeper's
go-ahead (same pattern as the icon/token/wrapper wave).
