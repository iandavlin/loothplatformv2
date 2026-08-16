# dark-anon-sweep — state note (2026-08-15, re-chartered seat)

> ## ⚠ CORRECTION (later the same session) — THE ACCORDION DEFECT IS REAL
>
> The section below says the charter's accordion defect does not exist. **That
> was wrong**, and it is left in place rather than deleted because the reasoning
> error is the useful part.
>
> Every measurement below is of the **settled** page, and the settled page really
> is correct (~11:1). What none of them measured is the **quarter second after
> the theme flips**. `.lg-acc` carried `transition: all 0.25s ease` while the
> label colour has no transition, so on a theme flip the ink snaps to `#e5e7e1`
> instantly while the card background *animates* `#fbfcf8 → #1b1e21`. Sampled
> frame by frame: **t=0 is 1.21:1** — the charter's exact number — under AA to
> ~t=100ms, settling at t=272ms.
>
> **The defect is a frame, not a state, so a settled probe cannot see it by
> construction.** That is also why gate 36 (which sleeps 400ms) read 1 finding
> while the sweep (which probes sooner) read 7. I read that disagreement as
> sweep contamination when it was the sweep being right.
>
> Fixed at source behind `LG_DARK_ACC_FLASH_FIX` (default ON): the transition is
> narrowed to `border-color, box-shadow` — the hover polish it was written for —
> so a theme flip no longer animates the background. Measured: 3 light-card
> frames become 0.
>
> Still true from below: the **join footer** addendum genuinely does not
> reproduce, and the `.lgpo-subtext` fix and all instrument work are unaffected.


**The charter's headline defect was a phantom. The surface had a real defect
anyway, it was a different element in a different plugin, and it is fixed,
committed and pushed (`bc52b29`, branch `dark-anon-sweep`).**

## The correction, because it changes what the next seat should believe

The charter said: bpnoaccess / os-dark / mobile, the `.lg-acc` accordion summary
labels are INVISIBLE at 1.21:1 — dark ink `#e5e7e1` stranded on light cards
`#fbfcf8` / `#fefcf5`, same shape as the gold-card fix, live now, every
locked-out mobile dark visitor sees blank labels.

Measured on the real served page with the gate's own CDP harness and the gate's
own contrast probe, in all four states (os-dark/app-dark × mobile/desktop):

| element | ink | card | ratio |
|---|---|---|---|
| `.lg-acc summary` | `#e5e7e1` | `#1b1e21` | ~11:1 ✅ |
| `.lg-acc--gold summary` | `#e5e7e1` | `#2a2418` | ✅ |
| `.lg-acc--coral summary` | `#e2895f` | `#2a1c18` | ✅ |

86.php's dark block moves the summary ink **and** the `.lg-acc` background
together, so the described shape (ink follows the theme, card does not) cannot
occur. **There is no accordion fix to make.**

Why the page is themed at all, since the served HTML argues the opposite and I
got this wrong once before the browser corrected me: `/wp-login.php` contains no
`app-settings.js` in its markup, which reads as "nothing can ever set
`data-lguser-theme` here". But nginx injects `/pwa.js`, and pwa.js loads
app-settings.js at runtime — so the attribute **is** set and the existing dark
block matches. A `curl` cannot see this. (The nginx boot script is a third,
separate path: it reads `lg-set-boot` from localStorage and no-ops for a cold
visitor.)

## What was actually broken, and is now fixed

`p.lgpo-subtext` — hardcoded `color: #666` — on the dark card `#1b1e21` =
**2.92:1**, under the 4.5:1 AA text bar. Source:
`lg-patreon-stripe-poller/lg-patreon-onboard.php`.

It is the **mirror image** of the charter's story: the CARD follows the theme
(86.php owns it) and this one piece of ink does not, because it lives in a
different plugin that 86.php's block never reaches. That is the practical reason
the mis-scoping mattered rather than being a naming quibble — **a fix in 86.php
was structurally incapable of touching it.**

Measured, flag OFF then ON, all four states, `theme=dark` confirmed on each:

```
OFF   os-dark mobile / desktop, app-dark mobile / desktop   ->  1 finding, 2.92:1
ON    same four                                             ->  0 findings
```

Fix pins the dark value to `#a8ada6` — the same `--lg-dark-mute` already used
for muted note text on this exact card (86.php's `.lg-card-note`) — rather than
inventing a shade: 7.33:1 on `#1b1e21`, 6.74:1 on the gold card, 7.86:1 on the
page background. **Light is untouched**; `#666` there is 5.57:1 and correct.

`LG_DARK_ONBOARD_SUBTEXT_FIX` **defaults OFF**, per wave discipline. Merging it
changes nothing served. Flipping it is one constant.

OFF is a **proven** byte-identical no-op, not an assumed one: the template
region was rendered under both flag states and diffed against the same region
rendered from HEAD. That test earned its keep immediately — the first placement
closed the PHP tag at the end of the `.lgpo-error` line, and **PHP swallows one
newline after `?>`**, so OFF silently lost that line's newline. The tag now
opens at column 0 and closes right before `</style>`.

## Not shipped, deliberately

The predecessor left an **uncommitted 167-line `@media (prefers-color-scheme:
dark)` block in 86.php** chasing the phantom. Its stated root cause — "the boot
script paints ink before the theme attribute is set" — is disproven by the
measurement above (the attribute *is* set, and the cards *do* go dark). It fixes
nothing that is broken, and it would give dark card backgrounds in the pre-JS
window to a visitor who explicitly chose **Light** on an OS-dark machine.

Reverted from the tree; preserved verbatim at
`~/projects/docs/predecessor-86php-prefers-color-scheme.patch` so the reasoning
is not lost. **Do not re-apply it without first reproducing a defect it fixes.**

## Instrument notes for whoever runs gate 36 next

- **The app-dark path can settle `theme=default` and go vacuously green.** It
  needs 3 navigations to get `lg-set-boot` written; under load it sometimes
  comes back LIGHT. Two of four ON-runs did this, and one app-dark/desktop
  needed **4 attempts** to resolve dark. A "0 findings, CLEARED" that did not
  resolve dark has measured the light page and proves nothing. **Assert
  `theme == 'dark'` and re-run until it holds.** This is very likely the
  residual run-to-run noise the previous seat attributed to CPU contention and
  papered over with max-of-two-runs.
- Verify a branch fix by injecting the rule via `measure(..., extra_css=...)`.
  The gate reads the **serve**, which is main.
- Measured `bpnoaccess` cleanly at **1 finding on both widths**, repeatedly.
  The committed BASELINE says `os-dark/desktop: 10`. Worth re-checking whether
  that 10 is a real surface reading or captured contamination — I saw one run
  return front-page Guitardle/`vpromo` findings against the bpnoaccess key while
  `#guitardle` was **never** in that page's DOM (`frames: 0`, href correct).
  Unexplained; it did not reproduce in three clean back-to-back trials.

## Round 2 — the SECOND phantom, and the instrument fix that explains both

Keeper sent an addendum naming another live-facing defect: *join, app-dark,
footer legal text 1.26:1 (`#dfdacb` on `#f2f4ee`), 21 findings*, to be fixed as
one family with the accordion. **It does not reproduce either.**

Probed the footer directly on `/join` **and** `/`, both dark paths, both widths
— eight runs:

```
footer background = #101214  (dark)
sub-AA text items = 0 of 15 on join, 0 of 17 on front, every state
```

Gate 36's full run agrees independently: `join/app-dark` = **0 findings** on
both widths, baseline 0, `theme=dark` on every row.

**The tell, and it is worth knowing by sight:** `#dfdacb` on `#f2f4ee` are
*both light colours*. A real dark-mode defect goes light-on-light only where a
token flips underneath. A page caught **pre-resolve** is light-on-light
*everywhere*, because it is still wearing its light paint. That is why these
arrive in **bursts of 21** rather than in ones and twos — it is not 21 defects,
it is one unresolved page. The charter's accordion (1.21:1 on `#fbfcf8`) has
the identical signature.

### Gate 36 hardened — resolution is now a PRECONDITION

Keeper's read was right, and this is the fix for the whole false-red class:

- `measure()` now polls for `<html data-lguser-theme='dark'>` and only then
  probes — **before** anything is measured, rather than noticing afterwards.
- It covers **both** dark paths. The old check guarded `app-dark` only, so the
  entire `os-dark` half was unprotected.
- It re-navigates once if unresolved. If it *still* will not resolve, the
  surface's findings are **DISCARDED, not ratcheted**, and it is named in the
  output — a zero from an unresolved page is unearned and a non-zero is a
  phantom, and ratcheting either is how the false reds were manufactured.
- Polling is also **faster** than the old wall-clock settle on the common case:
  it returns the moment the page is ready instead of always sleeping.

CRAFT-STANDARD row 36 previously said *"re-baseline tighter once the probe waits
on an explicit signal instead of a timer"* — that pointer is now true and the
row says so.

### Gate 53

`tools/gates/dark-onboard-subtext-gate.py`, number from keeper. Static render,
no browser, cannot flake. Asserts **both** flag states independently and only
**reports** the shipped default — a gate asserting "the rule is present" would
have reddened the moment the fix shipped OFF. Red-first breaks all five
assertions; all five redden. The flag is now **ON** per keeper's call.

### Still open / worth a look

- Several gate-36 baselines are now provably too high and should come down on a
  quiet box: `signin/os-dark/*` measured 1 against baseline 7,
  `lostpassword/os-dark/mobile` 0 against 3, `bpnoaccess/os-dark/desktop` 7
  against 10. The ratchet only tightens — lower them in the same commit as the
  measurement, never leave them stale.
- The `front/*` 10 findings are the Guitardle leaderboard, explicitly another
  lane's surface.

### Re-baseline, and the sweep had it too

Two hardened runs, back to back, agreed on **all 24 surfaces — zero
disagreement** (old method: 13/24, then 4/24). Baselines lowered to the measured
values:

```
signin/os-dark/*             7  ->  1
lostpassword/os-dark/*       3  ->  0
lostpassword/app-dark/*      1  ->  0
bpnoaccess/os-dark/desktop  10  ->  1
```

**Nobody fixed those pages.** Nothing on them changed — the old numbers were
inflated by pre-resolve phantoms. This is the instrument getting honest, not the
product improving, and the file's comment says so, because a future reader would
otherwise credit a fix that never happened.

`bpnoaccess` still reads **1** everywhere and that one is real: the
`.lgpo-subtext` defect. Fixed on this branch, but the gate reads the SERVE
(main), so it should drop to 0 after merge + deploy. **Lower it then, in the same
commit as the measurement.**

`tools/preview/dark-anon-sweep.py` had the identical defect and it matters more
there, because that tool RANKS the wave. It read `resolvedTheme` but only
*reported* it — findings were counted and ranked regardless. Since a pre-resolve
reading is light-on-light (1.0–1.2:1), the phantoms sort to the **top** of a
badness order. **The previous seat's ranked list has "borderless search fields
1.0–1.07:1" at the top; that is the phantom signature and should be re-measured
before anyone fixes it.** Same precondition applied, plus a disclosure block
naming unresolved rows and their phantom counts.

Honest limit: the gate's precondition is red-first proven both directions and
its baseline verified by two full runs. The sweep's copy is the same logic and
passes syntax, but the full 48-run sweep has **not** been re-run under it (box
over the load threshold). The disclosure block is the safety net until it is.

## THE WAVE'S REAL WORKLIST (frozen instrument, 48/48 resolved, phantom-free)

Re-run after the transition freeze. **Every run resolved dark; zero phantoms.**
app-dark and os-dark now agree column-for-column (hub 7/7, hub-door 9/9) where
before they disagreed 7-vs-3 — that symmetry is the instrument being stable.

**Two corrections to what I reported earlier, both against my own predictions:**

1. **The 1.0–1.07:1 items are REAL.** I twice flagged them as carrying the
   phantom signature and said not to fix them until re-measured. Re-measured,
   they survive the freeze on a `#15171a` body. The predecessor's original
   "borderless search fields" item was right all along. Checking was correct
   process; my prediction was wrong.
2. **Freezing transitions made the total go UP, 133 → 154, not down.** I
   expected it to shrink as phantoms fell away. Only 9 findings were phantoms
   (2 unresolved runs); the freeze *un-hid* ~30 real ones that mid-fade frames
   had been masking — `hub-door` mobile went 1 → 19. An early probe does not
   only invent defects, it also **conceals** them.

| worst | hits | element | fg on bg | surfaces |
|---|---|---|---|---|
| **1.00** | 8 | `input#q` | `#0b2528` on `#0b2528` | shop |
| **1.00** | 6 | `input#dir-loc` | `#1e2124`/`#fff` on itself | directory |
| **1.06** | 4 | `input.lgev-input` | `#222629` on `#1e2124` | events |
| **1.07** | 8 | `input.hub-tsearch__in` | `#222629` on `#262b30` | hub, hub-door |
| 2.17 | 4 | `a.feed-more__btn` | `#524e48` on `#15171a` | hub, hub-door |
| 2.83 | 20 | `span.gdle-side-row__pts` | `#657154` on `#242a20` | front *(other lane)* |
| 2.86 | 6 | `span` | `#f8f5ef` on `#b98a3e` | directory, events |
| 3.00 | 2 | `.leaflet-control-attribution` | `#80867d` on `#333d41` | directory |
| 3.12 | 38 | `span.avatar-init` | `#ffffff` on `#87986a` | hub, hub-door |
| 3.36 | 20 | `span.gdle-side-row__rank` | `#737c66` on `#242a20` | front *(other lane)* |
| 3.51 | 8 | `.lg-evland__sub` / `__empty` | `#6b6f68` on `#15171a` | events |
| 4.33 | 30 | `time.reply-stub__time` | `#80867d` on `#1e2124` | hub, hub-door |

**Start here.** The four worst are ONE defect class — search/text inputs whose
ink equals or nearly equals their own fill. `input#q` is literally `#0b2528` on
`#0b2528`: the text is not low-contrast, it is *invisible*. 26 findings across
shop, directory, events, hub and hub-door, plausibly one shared root in the
borderless-field styling. That is the highest-value fix in the list by a wide
margin.

Then by volume rather than badness: `.avatar-init` (38) and
`.reply-stub__time` (30) are single-token fixes hitting many instances.

`front/*` (40 findings, both Guitardle rows) is explicitly another lane's
surface — excluded, leaving ~114 for this wave.

**The login family is now clear**: signin, lostpassword, bpnoaccess, join and
lgjoin all read 0 in all four states.

### Root-cause diagnosis: the invisible search inputs (top of the worklist)

Traced all five to source. **It is ONE PATTERN, not one root** — correcting my
own earlier "plausibly one shared root", which was a guess. Five sites, five
files, same shape:

| surface | element | source | measured |
|---|---|---|---|
| shop | `input#q` | `fast-follow/loothtool-shop/shop-page-index.html:50` | `#0b2528` on `#0b2528` |
| directory (desktop) | `#dir-loc` | `webroot/directory-desktop.js:130` | `#ffffff` on `#ffffff` |
| directory (mobile) | `.lgdm-loc-input` | `webroot/directory-mobile.js:623` | `#1e2124` on `#1e2124` |
| events | `.lgev-input` | `webroot/events-mobile.js:56` | `#222629` on `#1e2124` |
| hub, hub-door | `.hub-tsearch__in` | `bb-mirror/web/forums.css:4141,4848` | `#222629` on `#262b30` |

**The shape:** every one of these inputs sets `background: transparent` (or
`none`) — often `!important` — and pairs it with an ink value chosen for the
LIGHT theme (`color:#1a1d1a!important`, `color:var(--lg-ink,#323532)`,
`color:var(--t-ink)!important`). The input therefore shows the *wrapper's* dark
background through it while its own ink stays the light-theme value. Dark ink,
dark fill, ratio ~1.

`input#q` is the extreme case: foreground and background measure the **identical
hex**, `#0b2528`. That is not low contrast, it is invisible — the wrapper's
`var(--card)` and the ink's `var(--t-ink)` resolve to the same colour on that
page.

**Why a transparent background is the trap:** it decouples the ink from the
surface it actually lands on. A normal input with its own `background` would
have been caught by any "does this pair clear AA" check on the element itself;
these only fail once you resolve the *effective* background by walking
ancestors, which is exactly what the sweep's probe does and what a hand review
does not.

**Fix shape:** each site needs a dark-theme ink, scoped the way that file
already scopes its dark rules (`html[data-lguser-theme="dark"]` in the webroot
JS files; the shop page needs its own token to become theme-aware). Do NOT
"fix" it by giving the inputs opaque backgrounds — the transparency is
deliberate design (the wrapper draws the field), and changing it would alter the
look on both themes.

**Not started.** The diagnosis is complete and measured; the edit is five files
and wants its own flag + per-state proof, same discipline as
`LG_DARK_ONBOARD_SUBTEXT_FIX`.

#### Refinement (2026-08-16, from source): the global dark input rule already exists

`webroot/app-settings.js:270` already ships, for every input under dark:

```
html[data-lguser-theme="dark"] input, … { background:#222629!important;
                                          color:#e5e7e1!important; … }
```

and `:259` does the same for the `.lgdm-ubar` / `.lgev-ubar` search bars
(`#1e2124` fill, `#e5e7e1` ink). **So the platform's dark treatment for fields is
correct and already present.** These five sites are invisible because each one
overrides it with a MORE SPECIFIC `!important` of its own —
`html.lgdd .gmaps-search #dir-loc{background:transparent!important;color:#1a1d1a!important}`
and siblings. An id-bearing selector outranks `html[data-lguser-theme="dark"] input`,
so the site's transparent background AND its light-theme ink both win.

That makes the fix smaller than the diagnosis implied: **no new mechanism, no new
palette.** Each site needs a dark-scoped ink at specificity ≥ its own override,
reusing the value that file (or app-settings) already uses. Pre-computed, all
clearing AA on the measured wrappers:

| site | wrapper | ink | ratio |
|---|---|---|---|
| hub / hub-door `.hub-tsearch__in` | `#262b30` | `#e5e7e1` (forums.css's own) | 11.46:1 |
| events `.lgev-input` | `#1e2124` | `#e5e7e1` | 12.98:1 |
| directory-mobile `.lgdm-loc-input` | `#1e2124` | `#cdd0ca` (that file's own) | 10.38:1 |
| shop `input#q` | `#0b2528` | `#e5e7e1` | 12.87:1 |

**`directory-desktop.js` is the odd one and is NOT yet solved.** That file contains
**zero** `data-lguser-theme` rules — no dark handling at all — and it already sets
`color:#1a1d1a!important`, yet the sweep measured `#ffffff` on `#ffffff`. Neither
its own value nor app-settings' `#e5e7e1` is white, so a third rule is winning
that I have not identified. Re-setting `#1a1d1a` would be a no-op "fix".
**Do not patch that site until a matched-rules probe names the winning rule** —
and note the white *wrapper* under dark may be the real bug rather than the ink.

## Next

The ranked wave list (110+ remaining) is the standing charter — badness order,
slices behind the wave flag. The previous seat's scoping (shared components:
`.lpw-install` 2.29:1, `.avatar-init` 3.12:1, borderless search fields
1.0–1.07:1, `.reply-stub__time` 4.33:1) is analysis from captured sweep.json,
**not** re-measured. Given how this session went: **measure each one before
fixing it.**

Open for keeper: a gate number if this defect should be encoded (52 is taken by
frontend-compose, so none was minted here), and whether to flip the flag ON in
the same train.
