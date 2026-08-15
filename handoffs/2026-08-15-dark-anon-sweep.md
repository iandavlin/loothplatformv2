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

## Wave 2 (keeper go-ahead 2026-08-15, "continue the fix wave, badness order")

Landed, both flagged OFF, both verified by extracting the real conditional from
the shipped source and eval-ing BOTH states (OFF proven byte-identical to the
pre-change lines, not assumed):

- **`LG_DARK_PWA_BANNER_FIX`** (`webroot/pwa.js`) — the Install / "Show me how"
  buttons, **2.29:1**, the worst text ratio in the whole sweep, on 5+ surfaces
  (hub-door, events, directory, shop, sponsors). Same shape as the "+" icon:
  `--lg-sage` repoints LIGHT in dark (#87986a→#9cb37d) while the text stays
  hardcoded `#fff`. Flips the ink dark instead of re-darkening the fill, so the
  sage button still looks like the sage button — #15171a on #9cb37d = 7.83:1,
  and 9.70:1 on the `:active` `--lg-sage-d`, so one rule covers both states.
- **`LG_DARK_MUTED_INK_FIX`** (`app-settings.js` + `hub-polish.js`) — `#80867d`
  is the dark theme's shared muted-meta ink, and it was the single root cause
  behind TWO findings that looked unrelated: the hub-door timestamps (4.33:1,
  11 hits on one page) and the directory's map attribution (2.98:1). Lifted to
  `#8a9087` (4.95 / 5.12 / 5.49 on the three backdrops it lands on). Applied in
  BOTH files in lockstep — a half-applied token change would leave dark mode
  with two different "muted" inks, worse than the state being fixed.
  - The map attribution is fixed a **different** way on purpose: its backdrop
    is `rgba(21,23,26,.8)` over an always-light OSM tile, so its effective
    contrast depends on which tile is underneath (measured #333d41 over water).
    Matching that with ink alone needs #a6aca3, which would drag six unrelated
    muted sites visibly brighter to satisfy one control — and still would not
    be a guarantee, because the next tile is a different colour. Making the
    control's own background opaque removes the dependency entirely.

### Two real defects found that are OUT of this charter's scope — not fixed

Recording rather than silently skipping or silently widening scope:

1. **The install banner fails in LIGHT mode too** — `#fff` on `--lg-sage`
   `#87986a` = **3.12:1**. Same button, same defect, light theme. This lane's
   charter is dark-anon, so only the dark half was fixed. Someone should take
   the light half.
2. **A PROBE LIMITATION blocks the next slice — do not just "fix" the fields.**
   The remaining `field-borderless` findings (`input.hub-tsearch__in` 1.07:1,
   `input.lgev-input` 1.06:1, `input#dir-loc` 1.0:1, shop `input#q` 1.0:1) look
   like the obvious next fix and are a **trap**. `.hub-tsearch` is a wrapper
   with `border:0` **by design** (forums.css — "a plain pill relying on its own
   fill for shape") and the flagged `input` sits INSIDE it. The existing
   `LG_DARK_SEARCH_WRAPPER_FIX` already borders the **wrapper**, which is the
   correct visual fix — but `contrast-probe.js` measures the INPUT's own edge
   and gives no credit for a bordered ancestor, so the finding will persist
   even once that flag flips ON, and bordering the input itself would draw a
   second border inside the first.
   - The right fix is in the **probe**: when a field has no border of its own,
     walk up a bounded number of ancestors for one that provides a visible edge
     around it, and only then call it borderless.
   - **NOT done now, deliberately.** Changing the probe re-measures all 24
     surfaces and would invalidate the `BASELINE` pushed minutes earlier, while
     a merge train was waiting on this branch. Sequence it: land the merge,
     tighten the baseline, THEN change the probe and re-baseline in one commit.
   - `input#dir-loc` also reads `#ffffff vs #ffffff` in one capture: that is the
     map search, which is **deliberately light-locked in every theme** (it
     floats over the always-light OSM tile — see `directory-desktop.js`). Its
     fix is a LIGHT-mode border, not a dark one. Nearly "fixed" in wave 1 and
     correctly left alone; do not regress that.
3. **The avatar initials palette fails regardless of theme.**
   `bb_mirror_avatar()` (`bb-mirror/web/forums/_reply-render.php`) picks from a
   hardcoded 8-colour palette via `crc32($slug)` and writes it as an INLINE
   style, so there is no dark variant — it renders identically in both themes.
   White text on 3 of the 8 fail AA: `#c66845` 3.84:1, `#87986a` 3.12:1,
   `#a0714f` 4.23:1. It surfaced in the dark sweep (5-7 hits per page) only
   because that is where the sweep was pointed; it is a theme-independent a11y
   defect and fixing it under a dark-mode flag would be mislabelling it. Needs
   its own decision: darken those 3 palette entries, or drop to a fixed
   AA-safe pair.
