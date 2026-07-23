# Dark-mode contrast audit — chrome dropdowns + full WCAG sweep

**Lane:** dark-mode · **Branch:** `dark-mode` (off `main`) · **Started:** 2026-07-23
**Status:** _scaffold committed; data tables pending the baseline + after sweep runs
(gated on a serve/RAM window — see "Run log")._

This document is the merge gate the keeper addendum requires: the branch does not
merge until this audit is **clean** (no chrome-owned FAIL) and every remaining
FAIL is either page-theme-owned or a listed design-judgment call awaiting Ian.

---

## 1. How dark mode is actually signalled (brief step 1)

Dark mode on this site is **not** a `prefers-color-scheme` media query — it is a
**resolved application theme**. `webroot/app-settings.js` `apply()` writes
`html[data-lguser-theme="dark"]` (and `data-lguser-dark="1"`) onto `<html>`,
driven by `localStorage['lg-set-theme']`. As of Buck's #64
(`buck/theme-defaults`, merged here as `d8bd550`) the OS preference is consulted
**only as the default when the user has made no explicit pick**; an explicit pick
always wins. The dark surface CSS is injected by `ensureDarkStyle()`
(`id="lg-dark-style"`, selector prefix `html[data-lguser-theme="dark"]`).

**Consequence for the fix:** the only correct trigger is that attribute. A media
query would misfire for a user who picked *Light* on a dark-OS device (and the
reverse). Every rule in this branch keys off `html[data-lguser-theme="dark"]`.

## 2. Root cause of the reported bug

`lg-shared/site-header.php` inline chrome CSS hardcoded `background:#fff` on three
panels — `.lg-chrome__account-menu`, `.lg-chrome__submenu` (the "The Hub ▾"
type dropdown), and `.lg-hubmenu__sheet` (the mobile Hub picker) — while their ink
rides `var(--lg-ink)`. Dark mode flips `--lg-ink` near-white (`#e5e7e1`), so pale
text landed on a white slab (≈1.3:1 — see baseline table). The account menu
additionally hardcoded **light** ink/border/hover literals, so it stayed a light
island inside an otherwise-dark app.

## 3. The fix (commit `2fb5346`)

Introduced chrome **panel tokens** — `--lg-panel-bg / -border / -ink / -hover-bg /
-hover-ink / -divider / -danger / -danger-bg`. The original light values remain
inline as each rule's `var()` **fallback**, so **light mode is byte-identical**
(verified: every removed literal reappears verbatim as its token's fallback). A
single dark override block re-points the tokens on the `.lg-chrome` / `.lg-hubmenu`
roots; all three panels follow. The defensive `!important` scoping — which fights
BuddyBoss / page-theme bleed — is **preserved**; only the *value* is tokenised.

Dark token values:

| token | dark value |
|-------|-----------|
| `--lg-panel-bg` | `#1b1e21` |
| `--lg-panel-border` | `#2c312d` |
| `--lg-panel-ink` | `#e5e7e1` |
| `--lg-panel-hover-bg` | `#243024` |
| `--lg-panel-hover-ink` | `#b0c693` |
| `--lg-panel-divider` | `#2c312d` |
| `--lg-panel-danger` | `#e08a63` |
| `--lg-panel-danger-bg` | `#3a2320` |

## 4. Buck's branch (`buck/theme-defaults`, merged `d8bd550`)

Reviewed and **folded in with credit** (Buck #64). Single-file addition to
`app-settings.js`: default to the OS theme when the user has no saved pick, plus
sharper dark-mode Hub sort-pill contrast. Sound and non-conflicting — merged rather
than clobbered. No disagreements to escalate.

---

## 5. Coverage matrix (keeper addendum)

Both themes × both widths (1280, 390), anon **and** logged-in where they differ.
Driver: `sweep.py` (real headless Chrome via CDP; theme set the authentic way —
`localStorage['lg-set-theme']` + reload — with `prefers-color-scheme` emulated to
match so Buck's OS-default path is exercised too).

| surface | url | auth | transient opened |
|---------|-----|------|------------------|
| front page | `/front-page/` | anon+auth | — |
| hub feed | `/hub/` | anon+auth | — |
| hub + type dropdown | `/hub/` | auth | `.lg-chrome__submenu` open |
| hub + account menu | `/hub/` | auth | `.lg-chrome__account-menu` open |
| hub mobile drawer | `/hub/` @390 | auth | Hub picker sheet open |
| notifications sheet | `/hub/` | auth | bell sheet open |
| discussion modal | `/hub/?topic=34177` | auth | `#lg-dmodal` + composer focused |
| profile | `/u/iandavlin` | anon+auth | — |
| directory (list+map) | `/directory/members/` | auth | — |
| events | `/events/` | anon+auth | — |
| calendar | `/calendar/` | anon+auth | — |
| sponsors listing | `/sponsors/` | anon+auth | — |
| one sponsor | `/sponsors/stewmac` | anon+auth | — |
| login | `/join` | anon | — |

Thresholds: normal text FAIL `<4.5:1`; large (≥24px, or ≥18.66px bold) & UI
affordances FAIL `<3:1`; within 0.5 of threshold = WATCH; text over
image/gradient/video = **manual-check**. Dedup by component (selector signature).

---

## 6. Baseline audit (before) — MAIN

_PENDING — `sweep.py --run run-baseline` on the current main serve (no flip
needed). `report.py --run run-baseline` renders the worst-first table here._

<!-- BASELINE_TABLE -->

## 7. After audit — branch `dark-mode`

_PENDING — captured in an announced short serve-flip window (detach to the branch
sha + `systemctl reload php8.3-fpm`, capture, restore to main immediately).
`report.py --before run-baseline --after run-2` renders the delta table here._

<!-- AFTER_TABLE -->
<!-- DELTA_TABLE -->

## 8. Judgment calls for Ian (not restyled beyond contrast)

_PENDING — page-theme-owned or design-judgment items surfaced by the sweep, each
with a recommendation. Candidate already flagged: the header search input has no
bg/placeholder CSS in `site-header.php`, so any dark-mode search contrast issue is
page-owned, not chrome — will confirm which stylesheet in the sweep._

## 9. Run log

- `2026-07-23` — scaffold committed. Baseline/after runs held pending a RAM/serve
  window (keeper: one Chrome at a time on this box; hold until mentions-mobile
  posts CLOSED).
