# dark-anon-sweep — state note (2026-08-15, re-chartered seat)

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
