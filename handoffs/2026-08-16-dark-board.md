# dark-board — work board dark pass + the paired-token palette (2026-08-16)

Branch `dark-board`, cut off current main (my other branch, `dark-anon-sweep`, is
88 commits behind and queued for a merge train — kept untouched on purpose).

## Done and pushed (5 commits)

**The board's dark palette: 282 findings → 0**, measured with collapsed panels
expanded. Ian: *"workboard is now in darkmode and needs contrast love."*

The board shipped a **light-only `:root` and no dark rules at all**. In dark the
boot script paints `body` `#15171a` and forces ink `#e5e7e1` while every panel
stayed white, so it failed in **both directions at once**: forced-light ink on
white panels (1.08–1.25:1) *and* the board's own light-theme dark ink stranded on
the dark body (1.91:1).

**Scope fence held** — style block only, zero PHP/markup, verified by diff. The
stripe seat owns this file's PHP for the relay work.

## What is verified, at the precision it was measured

| state | result |
|---|---|
| os-dark / desktop, **expanded** | 282 → **0** |
| os-dark / mobile, app-dark / mobile | 0 (**un-expanded** run) |
| app-dark / desktop | **NO VERDICT** — theme never resolved under load |
| light, both widths | 192 with fix, 192 without — **identical** |

### The owed expanded run — DONE, 3 of 4 (2026-08-16)

| state | expanded | result |
|---|---|---|
| os-dark / desktop | 52/58 | **0** |
| os-dark / mobile | 52/58 | **0** |
| app-dark / mobile | 52/58 | **0** |
| app-dark / desktop | — | **NO VERDICT**, third time running |
| light, both widths | 52/58 | 198 with fix, 198 without — identical |

(Light rises 192 → 198 with expansion for the same reason dark did: six findings
the first-render probe never saw. Still *identical* with and without the fix, so
light remains provably untouched.)

**`app-dark/desktop` has now failed to resolve three times in a row.** That is a
pattern, not a flake, and I could not separate "box under load" from "something
real about app-dark on this page at desktop width" — every attempt was made at
load 5–9. **Do not read the three zeros as covering it.** Re-run that ONE state
on a genuinely quiet box; if it still will not resolve, it is a finding about the
page, not the instrument.

**Still owed:** Three of
the four cells above are weaker than the headline number suggests, and rounding
that up is exactly what produced my first (wrong) "276 → 0".

## Three corrections I made to my own work here

1. **"276 → 0" was measured on the page as it first renders.** The board hides
   most of itself behind `<details>`. Expanding 52/58 raised BEFORE to 282 — six
   defects never before seen — and turned my "complete" fix into 198 remaining.
2. **The surface generator skipped `@media` blocks entirely** (anything starting
   `@`), so every rule nested in a media query was invisible to it. Media blocks
   are now flattened one level before extraction.
3. **Three fades are `opacity`, not colour** — `.grip{.35}`, `.thrbox__no{.6}`,
   `.row--done{.62}`. No colour override can fix an opacity. Each got a dark-only
   raise, and the DONE fade was **kept as meaning** (completed rows recede),
   raised only enough to clear AA.

## The gate — `tools/gates/board-dark-contrast-gate.py`

**UNREGISTERED on purpose; number still owed.** It reads the SERVE (main), so it
is RED until this palette merges *and* deploys — and a red gate in `run-all.sh`
blocks every lane. Registration line is at the bottom of the file.

Red-fired against the **real live defect**, not a synthetic mutation: 282
findings, exit 1, full selector paths. Carries every precondition this lane
learned the hard way — liveness, markup present, theme resolved, `#lg-dark-style`
injected, transitions frozen, `<details>` expanded — and treats any precondition
failure as **CANNOT RUN (exit 2)**, never red.

## Next: backlog 37 — the paired-token swatch sheet

Ian adopted the paired-token palette as standard, **mock-first**. Plan:

- **The pair inventory falls out of the measurements already taken.** Every
  finding carries `kind`, `fg`, `bg` and its surface, across twelve swept
  surfaces plus the board — so label-on-card, muted-on-page, chip-on-chip and
  link-on-surface derive from data, not from taste.
- Publish dev-gated, both themes side by side, **fails marked red**.
- **Print the MEASURED ratio, never the intended one.** Every wrong call this
  lane made today came from trusting an intended value over a measured one; a
  swatch sheet that inherits that flaw scales the mistake instead of fixing it.
- Publish path: `/home/ubuntu/projects/footer-mockups` symlinks behind the dev
  gate, so a lane can publish without writing to `/var/www/dev`.

## Open, none of it mine to decide

- **gate number** (keeper) — and whether these contrast assertions live inside
  the stripe seat's gate 50 or take their own.
- **stripe seat**: is any board state colour load-bearing for the relay? I
  changed the DECIDE badge fill to `#8a3f1d` because white on the old accent
  measured **2.16:1**. If that red carries meaning, say so and I re-solve rather
  than weaken a signal.
- **Ian**: the board also fails AA in **light** — 192 findings. Deliberately not
  fixed; he asked about dark, and light is a separate decision with its own risk.
