# Dark-mode bucket-C sweep — page-owned contrast fixes

**Branch:** `dark-c-sweep` off `origin/main` (`1075835`). **Status:** code-first
complete for the keeper-named C list; **after-shots deferred** until the serve
seat frees (behind mentions + reactions + map-infinite). Every fix is keyed off
the real dark signal `html[data-lguser-theme="dark"]` and lives in the **owning
stylesheet** — LIGHT mode is byte-identical. Coordinated nothing with WP.

Ratios are computed from **my own baseline audit data** (`/tmp/dark-mode/run-baseline/`,
the KEEPER-ADDENDUM WCAG sweep, computed-style fg/bg per component) and verified
mathematically (relative-luminance / alpha-blend, AA thresholds 4.5 normal / 3 large).

## The real dark signal
`html[data-lguser-theme="dark"]` set by `webroot/app-settings.js` from
`localStorage['lg-set-theme']`. app-settings.js redefines the brand tokens LIGHTER
for the dark bg (`--lg-sage-d` #6b7c52 → **#b0c693**, `--lg-sage` → #9cb37d), which
is *why* sage-bg + white-ink chrome flips to ~1.8:1 in dark.

## Fixed — worst-first (all AA-clear after)

| # | component | surface | theme | before | after | owning file · commit |
|--:|-----------|---------|:-----:|------:|------:|----------------------|
| P1 | `.feed-sort-bar .zL a.active` (hub sort ACTIVE pill) | hub (zones) | dark | **1.41** | 7.83 | `forums.css` · d478e37 |
| P1 | `.feed-sort-bar .zL a` (base sort pills) | hub (zones) | dark | 1.56 | 10.1 | `forums.css` · d478e37 |
| P2 | `.hub-rail__secbody .hub-rail__row` Type count-chips | adv-search modal / rail | dark | white-on-white | 12.98 | `forums.css` · d478e37 |
| C1 | `.fc-gate__cta` unlock CTA | hub feed (gated cover) | dark | 2.29 | 7.83 / 9.59 hover | `forums.css` · 6feceff |
| C3 | `.dir-viewtoggle button` inactive label | directory | dark | 2.33 | 10.4 | `directory.css` · b249e8f |
| C3 | `.dir-viewtoggle button.on` active | directory | dark | 1.85 | 9.7 | `directory.css` · b249e8f |
| C3 | `.dir-connect` Connect button (+ outline states) | directory | dark | 1.85 | 9.7 / 8.7 | `directory.css` · b249e8f |
| C3 | `.lgdm-vt` mobile view-toggle (390w) | directory (mobile sheet) | dark | 1.85 / 2.33 | 9.7 / 10.4 | `directory-mobile.js` · f29f305 |
| C4 | `.marker-cluster` count text (medium/large/small) | directory map | dark | **1.29** | 7.4–11.2 | `directory.css` · b249e8f |

**Root cause of P1** (the one Ian hit): the dark override in app-settings.js targets
the active pill with a **child combinator** — `.feed-sort-bar > a.active` — but the
hub front door nests pills one level down (`.feed-sort-bar--zones > .zL > a.active`),
so the active rule **misses** while line-155's **descendant** color rule still lights
the text `#cdd0ca` → washed light-on-white. (Same `>`-vs-descendant defect class as
my earlier 8f76dd1, which only fixed the *non-active* bg rule.) forums.css now owns
the whole pill row with **descendant** selectors → correct in flat **and** zones,
regardless of what app-settings.js version is deployed.

**Root cause of P2**: `grep -c hub-rail` in the deployed app-settings.js = **0** — the
rail rows were **never** dark-themed. `.hub-rail__secbody .hub-rail__row` hardcodes
`background:#fff`; `.hub-rail__nm` uses `var(--lg-ink)` (→ near-white in dark).

## Not fixed — evidence-based rulings

### Sponsors card name (keeper C #2, "1.14") — **FALSE POSITIVE**, no fix
The audit reported `.sponsor-card__name` `#fff` on `#eef2e3` = 1.14 (**light** mode;
there is *no* dark failure). But the name is `position:absolute` in `.sponsor-card__body`
sitting over `.sponsor-card__veil` — a `linear-gradient(rgba(20,22,18,.82) → 0)` **dark
overlay** — plus a `text-shadow`. The audit's bg-walk only climbs **ancestor**
backgrounds, so it missed the **sibling** veil and fell back to the card bg. Real
contrast is white-on-dark-veil ≈ **10:1** (verified). The `--plain` variant (no image)
already switches the name to `var(--lg-ink)`. **Recommend: mark resolved / harden the
audit's over-overlay detection.** No code change (a "fix" would break the design).

### Sponsor hero "Visit Website" CTA — content-owned, for Ian
`.lg-brand-hero__cta-btn` = `#fff` on `#f00` (**4.00**, 0.5 under). The red is the
*sponsor's own brand accent* (per-sponsor, theme-independent, layout-v2 brand-hero
block). Not a chrome/dark bug. **Ruling for Ian:** enforce a min-contrast on sponsor
accent CTAs (auto-darken or switch ink) vs. accept as the sponsor's brand.

## Backlog — other-surface dark-C failures (ratio'd, for keeper to assign)
Genuine dark failures **outside** the four named C surfaces; each owned by a different
app surface. Listed worst-first so they can be batched to the right lane/stylesheet:

| ratio | component | surface | owner |
|------:|-----------|---------|-------|
| 1.25 | `.lg-join__lede` washed | join / login | join page CSS |
| 2.29 | `.lg-viewas .lg-vchip` "view as" chip (sage+white) | profile `/u/` | profile app CSS |
| 2.29 | `.lg-social-btn` Connect (sage+white) | profile `/u/` | profile app CSS |
| 3.51 | `.lg-evland__sub` / `__empty` | events landing | events CSS |

**Systemic note (unchanged from the parent audit):** brand **sage + white text** recurs
across page CTAs (fc-gate, dir-connect, profile chips) — each cleared here by putting
**dark ink on the lightened dark-mode sage**, matching the hub active-pill treatment.
A palette-level ruling (dark-ink-on-sage, or a darker sage-for-white-text) would prevent
the next one.

## After-shots (deferred)
When the seat frees: baseline needs no flip (live already reproduces); after-shots
repoint `/srv/lg-bb-mirror` → this worktree (forums.css static, no fpm reload) and
serve profile-app/webroot for directory.css + directory-mobile.js, re-run the sweep
dark states (hub 1280 + 390 with sort active + adv-search modal open; directory 1280 +
390 list/map + toggles; hub gated cover), attach before/after PNGs + delta table here.
