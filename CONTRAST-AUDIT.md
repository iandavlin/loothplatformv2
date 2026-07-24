# Dark-mode contrast audit — chrome dropdowns + full WCAG sweep

**Lane:** dark-mode · **Branch:** `dark-mode` (off `main`) · **Started:** 2026-07-23
**Status:** _COMPLETE — baseline + after runs captured. All 4 chrome-owned dark
clusters FIXED (submenu/skip-link/account-menu/sort-pills), 0 regressions, light
byte-identical. Chrome dark surfaces audit-clean. Residual FAILs are page-owned or
Ian-judgment (§8). HOLDING for keeper + Ian review before merge._

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

Run `run-baseline` (main serve `7ff040f`, 82 states). **61 FAIL + 14 WATCH**
components, worst-first (`report.py --run run-baseline`):

| # | ratio | thr | verdict | theme | component (sel) | sample | fg | bg | surfaces |
|--:|------:|----:|:-------:|:-----:|-----------------|--------|----|----|----------|
| 1 | 1.13 | 3 | FAIL | light | `a.fc-cover.feed-card__cover.fc-cover--gated > span.fc-gate > span.fc-gate__lock` | 🔒 | rgb(255, 255, 255) | rgb(244,241,234) | hub/anon/1280w |
| 2 | 1.13 | 4.5 | FAIL | light | `a.fc-cover.feed-card__cover.fc-cover--gated > span.fc-gate > span.fc-gate__t` | Lite members only | rgb(255, 255, 255) | rgb(244,241,234) | hub/anon/1280w |
| 3 | 1.14 | 3 | FAIL | light | `a.sponsor-card > span.sponsor-card__body > span.sponsor-card__name` | Gluboost | rgb(255, 255, 255) | rgb(238,242,227) | sponsors/anon/1280w, sponsors/anon/390w, sponsors/auth/1280w, sponsors/auth/390w |
| 4 | 1.25 | 4.5 | FAIL | dark | `html > body.view-discover.has-looth-tabbar > a.skip-link` | Skip to content | rgb(255, 255, 255) | rgb(229,231,225) | front/anon/1280w, front/anon/390w |
| 5 | 1.25 | 4.5 | FAIL | dark | `html > body.view-discover.is-member.has-looth-tabbar > a.skip-link` | Skip to content | rgb(255, 255, 255) | rgb(229,231,225) | front/auth/1280w, front/auth/390w |
| 6 | 1.25 | 4.5 | FAIL | dark | `html > body.bb-mirror.hub-fmodal-page.has-looth-tabbar > a.skip-link` | Skip to content | rgb(255, 255, 255) | rgb(229,231,225) | discussion/auth/1280w, discussion/auth/390w, hub-acctmenu/auth/1280w, hub-acctmenu/auth/390w, hub-mobiledrawer/auth/390w, hub-typemenu/auth/1280w, hub-typemenu/auth/390w, hub/anon/1280w, hub/anon/390w, hub/auth/1280w, hub/auth/390w, notifications/auth/1280w, notifications/auth/390w |
| 7 | 1.25 | 4.5 | FAIL | dark | `ul.lg-chrome__submenu > li > a` | Everything | rgb(229, 231, 225) | rgb(255,255,255) | hub-typemenu/auth/1280w |
| 8 | 1.25 | 4.5 | FAIL | dark | `html > body.mode-view.has-looth-tabbar > a.skip-link` | Skip to content | rgb(255, 255, 255) | rgb(229,231,225) | profile/anon/1280w, profile/anon/390w, profile/auth/1280w, profile/auth/390w |
| 9 | 1.25 | 4.5 | FAIL | dark | `html.lgdd > body.dir--map.has-looth-tabbar > a.skip-link` | Skip to content | rgb(255, 255, 255) | rgb(229,231,225) | directory/auth/1280w |
| 10 | 1.25 | 4.5 | FAIL | dark | `html.lgdm > body.dir--map.has-looth-tabbar > a.skip-link` | Skip to content | rgb(255, 255, 255) | rgb(229,231,225) | directory/auth/390w |
| 11 | 1.25 | 4.5 | FAIL | dark | `html > body.lg-events-landing-page.has-looth-tabbar > a.skip-link` | Skip to content | rgb(255, 255, 255) | rgb(229,231,225) | events/anon/1280w, events/auth/1280w |
| 12 | 1.25 | 4.5 | FAIL | dark | `html.lgev > body.lg-events-landing-page.has-looth-tabbar > a.skip-link` | Skip to content | rgb(255, 255, 255) | rgb(229,231,225) | events/anon/390w, events/auth/390w |
| 13 | 1.25 | 4.5 | FAIL | dark | `html > body.view-content.arc-calendar-page.has-looth-tabbar > a.skip-link` | Skip to content | rgb(255, 255, 255) | rgb(229,231,225) | calendar/anon/1280w, calendar/anon/390w, calendar/auth/1280w, calendar/auth/390w |
| 14 | 1.25 | 4.5 | FAIL | dark | `html > body.view-content.arc-sponsors-page.has-looth-tabbar > a.skip-link` | Skip to content | rgb(255, 255, 255) | rgb(229,231,225) | sponsors/anon/1280w, sponsors/anon/390w, sponsors/auth/1280w, sponsors/auth/390w |
| 15 | 1.25 | 4.5 | FAIL | dark | `html > body.has-looth-tabbar > a.skip-link` | Skip to content | rgb(255, 255, 255) | rgb(229,231,225) | sponsor/anon/1280w, sponsor/anon/390w, sponsor/auth/1280w, sponsor/auth/390w |
| 16 | 1.25 | 4.5 | FAIL | dark | `html > body.lg-membership-page.lg-join.lg-join--start > a.skip-link` | Skip to content | rgb(255, 255, 255) | rgb(229,231,225) | login/anon/1280w, login/anon/390w |
| 17 | 1.25 | 4.5 | FAIL | dark | `main.lg-join__main > section.lg-join__card.lg-join__card--start > p.lg-join__lede` | The Looth Group is a member-supported co | rgb(229, 231, 225) | rgb(255,255,255) | login/anon/1280w, login/anon/390w |
| 18 | 1.29 | 4.5 | FAIL | dark | `div.leaflet-marker-icon.marker-cluster.marker-cluster-medium > div > span` | 13 | rgb(229, 231, 225) | rgb(240,201,42) | directory/auth/1280w |
| 19 | 1.41 | 4.5 | FAIL | dark | `nav.feed-sort-bar.feed-sort-bar--zones > div.zL > a.active` | Newest | rgb(205, 208, 202) | rgb(242,244,238) | discussion/auth/1280w, discussion/auth/390w, hub-acctmenu/auth/1280w, hub-acctmenu/auth/390w, hub-mobiledrawer/auth/390w, hub-typemenu/auth/1280w, hub-typemenu/auth/390w, hub/anon/1280w, hub/anon/390w, hub/auth/1280w, hub/auth/390w, notifications/auth/1280w, notifications/auth/390w |
| 20 | 1.56 | 4.5 | FAIL | dark | `nav.feed-sort-bar.feed-sort-bar--zones > div.zL > a.lg-random-tab` | Random | rgb(205, 208, 202) | rgb(255,255,255) | discussion/auth/1280w, discussion/auth/390w, hub-acctmenu/auth/1280w, hub-acctmenu/auth/390w, hub-mobiledrawer/auth/390w, hub-typemenu/auth/1280w, hub-typemenu/auth/390w, hub/anon/1280w, hub/anon/390w, hub/auth/1280w, hub/auth/390w, notifications/auth/1280w, notifications/auth/390w |
| 21 | 1.56 | 4.5 | FAIL | dark | `nav.feed-sort-bar.feed-sort-bar--zones > div.zL > a` | Trending | rgb(205, 208, 202) | rgb(255,255,255) | discussion/auth/1280w, discussion/auth/390w, hub-acctmenu/auth/1280w, hub-acctmenu/auth/390w, hub-mobiledrawer/auth/390w, hub-typemenu/auth/1280w, hub-typemenu/auth/390w, hub/anon/1280w, hub/anon/390w, hub/auth/1280w, hub/auth/390w, notifications/auth/1280w, notifications/auth/390w |
| 22 | 1.56 | 4.5 | FAIL | dark | `nav.feed-sort-bar.feed-sort-bar--zones > div.zL > a.lg-saved-pill` | Saved | rgb(205, 208, 202) | rgb(255,255,255) | discussion/auth/1280w, hub-acctmenu/auth/1280w, hub-typemenu/auth/1280w, hub/auth/1280w, notifications/auth/1280w |
| 23 | 1.85 | 4.5 | FAIL | dark | `div.dir-header > div.dir-viewtoggle > button.on` | Map | rgb(255, 255, 255) | rgb(176,198,147) | directory/auth/1280w |
| 24 | 1.85 | 4.5 | FAIL | dark | `div.dir-card__foot > div.dir-card__actions > button.dir-connect.dir-connect--none` | Connect | rgb(255, 255, 255) | rgb(176,198,147) | directory/auth/1280w, directory/auth/390w |
| 25 | 1.85 | 4.5 | FAIL | dark | `div.lgdm-shd > div.lgdm-vt > button.on` | Map | rgb(255, 255, 255) | rgb(176,198,147) | directory/auth/390w |
| 26 | 1.89 | 4.5 | FAIL | light | `section.row.row--featured-member > div.lg-fm > span.lg-fm__badge` | Featured member | rgb(236, 179, 81) | rgb(255,255,255) | front/anon/1280w, front/auth/1280w |
| 27 | 1.94 | 4.5 | FAIL | dark | `div.leaflet-marker-icon.marker-cluster.marker-cluster-large > div > span` | 128 | rgb(229, 231, 225) | rgb(246,139,60) | directory/auth/1280w |
| 28 | 2.29 | 4.5 | FAIL | dark | `a.fc-cover.feed-card__cover.fc-cover--gated > span.fc-gate > span.fc-gate__cta` | Unlock | rgb(255, 255, 255) | rgb(156,179,125) | hub/anon/1280w |
| 29 | 2.29 | 4.5 | FAIL | dark | `div.lg-viewas > div.lg-viewas__row > a.lg-vchip` | Edit profile (admin) | rgb(255, 255, 255) | rgb(156,179,125) | profile/auth/1280w, profile/auth/390w |
| 30 | 2.29 | 4.5 | FAIL | dark | `section.block.lg-block.lg-block--header > div.lg-social-actions > button.lg-btn.lg-social-btn` | Connect | rgb(255, 255, 255) | rgb(156,179,125) | profile/auth/1280w, profile/auth/390w |
| 31 | 2.33 | 4.5 | FAIL | dark | `div.dir-viewtoggle > button > span.vt-dt` | Cards | rgb(166, 172, 159) | rgb(255,255,255) | directory/auth/1280w |
| 32 | 2.33 | 4.5 | FAIL | dark | `div.lgdm-shd > div.lgdm-vt > button` | List | rgb(166, 172, 159) | rgb(255,255,255) | directory/auth/390w |
| 33 | 3.10 | 4.5 | FAIL | light | `div.lg-chrome__inner > a.lg-chrome__logo > span.lg-chrome__wordmark` | Looth Group | rgb(135, 152, 106) | rgb(254,254,254) | calendar/anon/1280w, calendar/auth/1280w, directory/auth/1280w, discussion/auth/1280w, events/anon/1280w, events/auth/1280w, front/anon/1280w, front/auth/1280w, hub-acctmenu/auth/1280w, hub-typemenu/auth/1280w, hub/anon/1280w, hub/auth/1280w, login/anon/1280w, notifications/auth/1280w, profile/anon/1280w, profile/auth/1280w, sponsor/anon/1280w, sponsor/auth/1280w, sponsors/anon/1280w, sponsors/auth/1280w |
| 34 | 3.12 | 4.5 | FAIL | light | `a.fc-cover.feed-card__cover.fc-cover--gated > span.fc-gate > span.fc-gate__cta` | Unlock | rgb(255, 255, 255) | rgb(135,152,106) | hub/anon/1280w |
| 35 | 3.12 | 4.5 | FAIL | light | `nav.feed-sort-bar.feed-sort-bar--zones > div.zM > button.forum-header__new-post.lg-newpost` | + New post | rgb(255, 255, 255) | rgb(135,152,106) | discussion/auth/1280w, hub-acctmenu/auth/1280w, hub-typemenu/auth/1280w, hub/auth/1280w, notifications/auth/1280w |
| 36 | 3.12 | 4.5 | FAIL | light | `div.lg-viewas > div.lg-viewas__row > a.lg-vchip` | Edit profile (admin) | rgb(255, 255, 255) | rgb(135,152,106) | profile/auth/1280w, profile/auth/390w |
| 37 | 3.12 | 4.5 | FAIL | light | `section.block.lg-block.lg-block--header > div.lg-social-actions > button.lg-btn.lg-social-btn` | Connect | rgb(255, 255, 255) | rgb(135,152,106) | profile/auth/1280w, profile/auth/390w |
| 38 | 3.29 | 4.5 | FAIL | light | `main.lg-evland > section.lg-evland__section > h2.lg-evland__section-h` | Upcoming | rgb(184, 132, 43) | rgb(255,255,255) | events/anon/1280w, events/anon/390w, events/auth/1280w, events/auth/390w |
| 39 | 3.49 | 4.5 | FAIL | light | `ol.gdle-side-board > li.gdle-side-row.gdle-side-row--open > span.gdle-side-row__pts` | play to claim | rgb(120, 137, 91) | rgb(243,247,233) | front/anon/1280w, front/anon/390w, front/auth/1280w |
| 40 | 3.51 | 4.5 | FAIL | dark | `body.lg-events-landing-page.has-looth-tabbar > main.lg-evland > p.lg-evland__sub` | Live builds, clinics, and community call | rgb(107, 111, 104) | rgb(21,23,26) | events/anon/1280w, events/anon/390w, events/auth/1280w, events/auth/390w |
| 41 | 3.51 | 4.5 | FAIL | dark | `main.lg-evland > section.lg-evland__section > p.lg-evland__empty` | No upcoming events scheduled — check bac | rgb(107, 111, 104) | rgb(21,23,26) | events/anon/1280w, events/anon/390w, events/auth/1280w, events/auth/390w |
| 42 | 3.84 | 4.5 | FAIL | light | `ul.lg-chrome__account-menu > li > a.lg-chrome__account-menu-signout` | Sign out | rgb(198, 104, 69) | rgb(255,255,255) | hub-acctmenu/auth/1280w |
| 43 | 3.84 | 4.5 | FAIL | dark | `ul.lg-chrome__account-menu > li > a.lg-chrome__account-menu-signout` | Sign out | rgb(198, 104, 69) | rgb(255,255,255) | hub-acctmenu/auth/1280w |
| 44 | 3.90 | 4.5 | FAIL | light | `div.vpromo > div.vpromo__video > p.vpromo__label` | Featured video | rgb(111, 124, 84) | rgb(243,239,230) | front/anon/1280w, front/anon/390w, front/auth/1280w, front/auth/390w |
| 45 | 3.90 | 4.5 | FAIL | light | `div.vpromo > div.vpromo__copy > p.vp-eyebrow` | Welcome | rgb(111, 124, 84) | rgb(243,239,230) | front/anon/1280w, front/anon/390w, front/auth/1280w, front/auth/390w |
| 46 | 3.90 | 4.5 | FAIL | light | `div.vpromo__copy > p > a.vp-cta.vp-cta--secondary` | Weekly email | rgb(111, 124, 84) | rgb(243,239,230) | front/anon/1280w, front/anon/390w, front/auth/1280w, front/auth/390w |
| 47 | 3.98 | 4.5 | FAIL | light | `ul.lg-chrome__account-menu > li > a` | My Profile | rgb(107, 124, 82) | rgb(238,242,227) | hub-acctmenu/auth/1280w |
| 48 | 3.98 | 4.5 | FAIL | dark | `ul.lg-chrome__account-menu > li > a` | My Profile | rgb(107, 124, 82) | rgb(238,242,227) | hub-acctmenu/auth/1280w |
| 49 | 3.98 | 4.5 | FAIL | light | `a.dir-card__main > div.hl-chips > span.hl` | Finish touch-up | rgb(107, 124, 82) | rgb(238,242,227) | directory/auth/1280w |
| 50 | 4.00 | 4.5 | FAIL | light | `div.lg-brand-hero__cta > a.lg-brand-hero__cta-btn > span` | Visit Website | rgb(255, 255, 255) | rgb(255,0,0) | sponsor/anon/1280w, sponsor/anon/390w, sponsor/auth/1280w, sponsor/auth/390w |
| 51 | 4.00 | 4.5 | FAIL | dark | `div.lg-brand-hero__cta > a.lg-brand-hero__cta-btn > span` | Visit Website | rgb(255, 255, 255) | rgb(255,0,0) | sponsor/anon/1280w, sponsor/anon/390w, sponsor/auth/1280w, sponsor/auth/390w |
| 52 | 4.14 | 4.5 | FAIL | light | `footer.lg-chrome-foot > div.lg-chrome-foot__legal > span` | © 2026 The Looth Group. All rights reser | rgba(212, 204, 184, 0.55) | rgb(26,29,26) | calendar/anon/1280w, calendar/anon/390w, calendar/auth/1280w, calendar/auth/390w, directory/auth/390w, events/anon/1280w, events/auth/1280w, login/anon/1280w, login/anon/390w, profile/anon/390w, profile/auth/390w, sponsors/anon/1280w, sponsors/auth/1280w |
| 53 | 4.27 | 4.5 | FAIL | dark | `footer.lg-chrome-foot > div.lg-chrome-foot__legal > span` | © 2026 The Looth Group. All rights reser | rgba(212, 204, 184, 0.55) | rgb(16,18,20) | calendar/anon/1280w, calendar/anon/390w, calendar/auth/1280w, calendar/auth/390w, directory/auth/390w, events/anon/1280w, events/auth/1280w, login/anon/1280w, login/anon/390w, profile/anon/390w, profile/auth/390w, sponsors/anon/1280w, sponsors/auth/1280w |
| 54 | 4.33 | 4.5 | FAIL | light | `body.view-content.arc-calendar-page.has-looth-tabbar > main.arc-page.lg-content-page > p.lg-page-sub` | Upcoming and past Looth Group events. | rgb(107, 111, 107) | rgb(239,236,230) | calendar/anon/1280w, calendar/anon/390w, calendar/auth/1280w, calendar/auth/390w |
| 55 | 4.33 | 4.5 | FAIL | light | `body.view-content.arc-sponsors-page.has-looth-tabbar > main.arc-page.lg-content-page > p.lg-page-sub` | The companies that support the Looth Gro | rgb(107, 111, 107) | rgb(239,236,230) | sponsors/anon/1280w, sponsors/anon/390w, sponsors/auth/1280w, sponsors/auth/390w |
| 56 | 4.37 | 4.5 | FAIL | light | `nav > a.is-active > span.lt-lb` | You | rgb(107, 124, 82) | rgb(251,251,248) | profile/anon/390w, profile/auth/390w |
| 57 | 4.37 | 4.5 | FAIL | light | `a > span.ll-foot > span.ll-btn` | Pull up a bench | rgb(107, 124, 82) | rgb(251,251,248) | events/anon/1280w, events/anon/390w, events/auth/1280w, events/auth/390w |
| 58 | 4.46 | 4.5 | FAIL | light | `div.lg-chrome__inner > div.lg-chrome__aside > a.lg-chrome__connect` | Connect Patreon | rgb(111, 124, 84) | rgb(255,254,254) | calendar/anon/1280w, calendar/anon/390w, calendar/auth/1280w, calendar/auth/390w, events/anon/1280w, front/anon/1280w, front/anon/390w, front/auth/1280w, front/auth/390w, login/anon/1280w, login/anon/390w, profile/anon/1280w, profile/anon/390w, sponsor/anon/1280w, sponsor/anon/390w, sponsors/anon/1280w, sponsors/anon/390w, sponsors/auth/1280w, sponsors/auth/390w |
| 59 | 4.48 | 4.5 | FAIL | light | `div.vpromo__copy > p > a.vp-cta` | Join Looth Group → | rgb(255, 255, 255) | rgb(111,124,84) | front/anon/1280w, front/anon/390w, front/auth/1280w, front/auth/390w |
| 60 | 4.48 | 4.5 | FAIL | light | `div.lg-fm > div.lg-fm__body > div.lg-fm__role` | Brooklyn Fretworks | rgb(111, 124, 84) | rgb(255,255,255) | front/anon/1280w, front/auth/1280w |
| 61 | 4.48 | 4.5 | FAIL | light | `div.lg-fm > div.lg-fm__act > a.lg-fm__cta` | Visit Chip's profile | rgb(255, 255, 255) | rgb(111,124,84) | front/anon/1280w, front/auth/1280w |
| 62 | 4.52 | 4.5 | WATCH | light | `div.leaflet-bottom.leaflet-right > div.leaflet-control-attribution.leaflet-control > a` | Leaflet | rgb(0, 120, 168) | rgb(238,246,249) | directory/auth/1280w, directory/auth/390w, profile/anon/1280w, profile/anon/390w, profile/auth/1280w, profile/auth/390w |
| 63 | 4.53 | 4.5 | WATCH | light | `ul.lg-chrome__menu > li > a.is-active` | The Map | rgb(107, 124, 82) | rgb(255,255,255) | directory/auth/1280w, events/anon/1280w, events/auth/1280w |
| 64 | 4.54 | 4.5 | WATCH | light | `div.dir-header > div.dir-viewtoggle > button.on` | Map | rgb(255, 255, 255) | rgb(107,124,82) | directory/auth/1280w |
| 65 | 4.54 | 4.5 | WATCH | light | `div.dir-card__foot > div.dir-card__actions > button.dir-connect.dir-connect--none` | Connect | rgb(255, 255, 255) | rgb(107,124,82) | directory/auth/1280w, directory/auth/390w |
| 66 | 4.54 | 4.5 | WATCH | light | `div.gmaps-search > button.gmaps-search__filt > span` | Filters | rgb(107, 124, 82) | rgb(255,255,255) | directory/auth/1280w |
| 67 | 4.54 | 4.5 | WATCH | light | `div.lgdm-shd > div.lgdm-vt > button.on` | Map | rgb(255, 255, 255) | rgb(107,124,82) | directory/auth/390w |
| 68 | 4.54 | 4.5 | WATCH | dark | `div.gmaps-search > button.gmaps-search__filt > span` | Filters | rgb(107, 124, 82) | rgb(255,255,255) | directory/auth/1280w |
| 69 | 4.81 | 4.5 | WATCH | dark | `div.leaflet-control-container > div.leaflet-bottom.leaflet-right > div.leaflet-control-attribution.leaflet-control` | © OpenStreetMap | rgb(128, 134, 125) | rgb(21,23,26) | profile/anon/1280w, profile/anon/390w, profile/auth/1280w, profile/auth/390w |
| 70 | 4.88 | 4.5 | WATCH | light | `nav > button > span.lt-lb` | Nav | rgb(107, 111, 107) | rgb(250,250,247) | calendar/anon/390w, calendar/auth/390w, directory/auth/390w, front/anon/390w, front/auth/390w, profile/anon/390w, profile/auth/390w, sponsor/anon/390w, sponsor/auth/390w, sponsors/anon/390w, sponsors/auth/390w |
| 71 | 4.88 | 4.5 | WATCH | light | `nav > a > span.lt-lb` | You | rgb(107, 111, 107) | rgb(250,250,247) | calendar/anon/390w, calendar/auth/390w, directory/auth/390w, front/anon/390w, front/auth/390w, sponsor/anon/390w, sponsor/auth/390w, sponsors/anon/390w, sponsors/auth/390w |
| 72 | 4.88 | 4.5 | WATCH | dark | `div.leaflet-bottom.leaflet-right > div.leaflet-control-attribution.leaflet-control > a` | Leaflet | rgb(156, 179, 125) | rgb(51,61,65) | directory/auth/390w |
| 73 | 4.93 | 4.5 | WATCH | light | `div.lg-shell > div.lg-profile > a.lg-report` | Report this profile | rgb(107, 111, 107) | rgb(251,251,248) | profile/anon/390w, profile/auth/390w |
| 74 | 4.93 | 4.5 | WATCH | light | `div.dir-pane-left > div.dir-header > span.dir-meta` | 133 members in view | rgb(107, 111, 107) | rgb(251,251,248) | directory/auth/1280w |
| 75 | 4.93 | 4.5 | WATCH | light | `div.lgdm-shd > span.lgdm-shd__ttl > span.lgdm-shd__meta` | 6 members in view | rgb(107, 111, 107) | rgb(251,251,248) | directory/auth/390w |

**61 FAIL, 14 WATCH.**

## 7. After audit — branch `dark-mode`

Run `run-2` (branch serve — `/srv/lg-shared`→worktree + docroot `app-settings.js`
swap, restored byte-identical after). 82 states.

**Result: 234 → 146 total FAIL instances; 61 → 42 FAIL components; 19 resolved,
0 regressions.** Every one of the 4 chrome-owned dark clusters is cleared:

| cluster (dark) | before | after | fix |
|----------------|-------:|------:|-----|
| `ul.lg-chrome__submenu > li > a` — **the reported bug** | 1.25 | **PASS** | `2fb5346` |
| `a.skip-link` (×13 body-class variants, 41 instances) | 1.25 | **PASS** | `67a6f25` |
| `ul.lg-chrome__account-menu` Sign out / My Profile (dark) | 3.84 / 3.98 | **PASS** | `2fb5346` |
| `feed-sort-bar--zones … a` Newest/Trending/Random/Saved (dark) | 1.41–1.56 | **PASS** | `8f76dd1` |

Regression scan (components FAILing in after but not before): **none**. Light mode
byte-identical confirmed empirically — every light-mode component keeps its exact
baseline ratio (e.g. account-menu light Sign out 3.84 → 3.84, "+ New post" light
3.12 → 3.12; these are bucket-B/C items for Ian, untouched by the dark-only fixes).

The 42 residual FAIL components are entirely bucket B (chrome light/brand
near-miss) + bucket C (page-owned) from §8 — no chrome-owned **dark** FAIL remains.
**Merge gate: chrome dark surfaces are audit-clean.**

Before/after per component (`report.py --before run-baseline --after run-2`),
fixed clusters first:

| component (sel) | theme | before | after | delta | status |
|-----------------|:-----:|-------:|------:|------:|--------|
| `html > body.view-discover.has-looth-tabbar > a.skip-link` | dark | 1.25 | gone | resolved | FIXED |
| `html > body.view-discover.is-member.has-looth-tabbar > a.skip-link` | dark | 1.25 | gone | resolved | FIXED |
| `html > body.bb-mirror.hub-fmodal-page.has-looth-tabbar > a.skip-link` | dark | 1.25 | gone | resolved | FIXED |
| `ul.lg-chrome__submenu > li > a` | dark | 1.25 | gone | resolved | FIXED |
| `html > body.mode-view.has-looth-tabbar > a.skip-link` | dark | 1.25 | gone | resolved | FIXED |
| `html.lgdd > body.dir--map.has-looth-tabbar > a.skip-link` | dark | 1.25 | gone | resolved | FIXED |
| `html.lgdm > body.dir--map.has-looth-tabbar > a.skip-link` | dark | 1.25 | gone | resolved | FIXED |
| `html > body.lg-events-landing-page.has-looth-tabbar > a.skip-link` | dark | 1.25 | gone | resolved | FIXED |
| `html.lgev > body.lg-events-landing-page.has-looth-tabbar > a.skip-link` | dark | 1.25 | gone | resolved | FIXED |
| `html > body.view-content.arc-calendar-page.has-looth-tabbar > a.skip-link` | dark | 1.25 | gone | resolved | FIXED |
| `html > body.view-content.arc-sponsors-page.has-looth-tabbar > a.skip-link` | dark | 1.25 | gone | resolved | FIXED |
| `html > body.has-looth-tabbar > a.skip-link` | dark | 1.25 | gone | resolved | FIXED |
| `html > body.lg-membership-page.lg-join.lg-join--start > a.skip-link` | dark | 1.25 | gone | resolved | FIXED |
| `nav.feed-sort-bar.feed-sort-bar--zones > div.zL > a.active` | dark | 1.41 | gone | resolved | FIXED |
| `nav.feed-sort-bar.feed-sort-bar--zones > div.zL > a.lg-random-tab` | dark | 1.56 | gone | resolved | FIXED |
| `nav.feed-sort-bar.feed-sort-bar--zones > div.zL > a` | dark | 1.56 | gone | resolved | FIXED |
| `nav.feed-sort-bar.feed-sort-bar--zones > div.zL > a.lg-saved-pill` | dark | 1.56 | gone | resolved | FIXED |
| `ul.lg-chrome__account-menu > li > a.lg-chrome__account-menu-signout` | dark | 3.84 | gone | resolved | FIXED |
| `ul.lg-chrome__account-menu > li > a` | dark | 3.98 | gone | resolved | FIXED |
| `div.leaflet-bottom.leaflet-right > div.leaflet-control-attribution.leaflet-control > a` | light | 4.52 | 4.52 | 0.00 | FIXED |
| `ul.lg-chrome__menu > li > a.is-active` | light | 4.53 | 4.53 | 0.00 | FIXED |
| `div.dir-header > div.dir-viewtoggle > button.on` | light | 4.54 | 4.54 | 0.00 | FIXED |
| … | | | | | |
| `a.fc-cover.feed-card__cover.fc-cover--gated > span.fc-gate > span.fc-gate__lock` | light | 1.13 | 1.13 | 0.00 | still FAIL |
| `a.fc-cover.feed-card__cover.fc-cover--gated > span.fc-gate > span.fc-gate__t` | light | 1.13 | 1.13 | 0.00 | still FAIL |
| `a.sponsor-card > span.sponsor-card__body > span.sponsor-card__name` | light | 1.14 | 1.14 | 0.00 | still FAIL |
| `main.lg-join__main > section.lg-join__card.lg-join__card--start > p.lg-join__lede` | dark | 1.25 | 1.25 | 0.00 | still FAIL |
| `div.leaflet-marker-icon.marker-cluster.marker-cluster-medium > div > span` | dark | 1.29 | 1.29 | 0.00 | still FAIL |
| `div.dir-header > div.dir-viewtoggle > button.on` | dark | 1.85 | 1.85 | 0.00 | still FAIL |
| `div.dir-card__foot > div.dir-card__actions > button.dir-connect.dir-connect--none` | dark | 1.85 | 1.85 | 0.00 | still FAIL |
| `div.lgdm-shd > div.lgdm-vt > button.on` | dark | 1.85 | 1.85 | 0.00 | still FAIL |

## 8. Findings classification (61 FAIL + 14 WATCH → three buckets)

### A · Chrome-owned, FIXED on this branch — validated in the after-run (§7)

| baseline # | component | theme | before | fix commit |
|-----------:|-----------|:-----:|-------:|-----------|
| 7 | `ul.lg-chrome__submenu > li > a` (Hub type dropdown — **the reported bug**) | dark | 1.25 | `2fb5346` panel tokens |
| 42–48 | `ul.lg-chrome__account-menu` items (Sign out / My Profile) — **dark** side | dark | 3.84–3.98 | `2fb5346` panel tokens |
| 4–16 | `a.skip-link` (focus chip) | dark | 1.25 | `67a6f25` dark chip |
| 19–22 | `nav.feed-sort-bar--zones … a` (Newest/Trending/Random/Saved) | dark | 1.41–1.56 | `8f76dd1` `>`→descendant (credit Buck #64) |

### B · Chrome-owned LIGHT / brand near-misses — **APPLIED** (Ian accepted all five, 2026-07-24)

These were pre-existing in **light** mode (or both themes) — brand-palette / deliberate-mute
decisions, not dark regressions. Ian **ACCEPTED ALL FIVE** recommendations; applied on
branch `dark-brand-nudges` off `origin/main`. Each is a **light-only** change (the darkened
color is either a plain literal in a light-only rule, or a `var()` fallback whose token is
redefined in the dark block) — **dark mode is preserved byte-identical**: the three sage
items carry a `html[data-lguser-theme="dark"] …` restore to the lighter dark token, and the
two account inks live behind `--lg-panel-*` vars that the dark block already defines. Ratios
verified mathematically (relative-luminance / alpha-blend); light header bg is
`rgba(255,255,255,.96)` ≈ white, footer is charcoal `#1a1d1a` both themes.

| # | component | theme | before | applied color | after | status |
|--:|-----------|:-----:|------:|---------------|------:|--------|
| 33 | `.lg-chrome__wordmark` "Looth Group" | light | 3.10 | `#87986a`→`#6b7850` (deeper sage; `~#6f7c54` was 4.48, 0.02 short — went one step darker) | **4.74** white / 4.58 cream | ✅ APPLIED |
| 42 | account-menu "Sign out" (danger) | light | 3.84 | fallback `#c66845`→`#ad5330` | **5.18** white / 4.65 pink-hover | ✅ APPLIED |
| 47 | account-menu "My Profile" hover/focus ink | light | 3.98 | fallback `#6b7c52`→`#586b3f` | **5.14** on sage-tint | ✅ APPLIED |
| 52 / 53 | `.lg-chrome-foot__legal` © | both | 4.14 / 4.27 | alpha `0.55`→`0.65` | **5.24** on charcoal | ✅ APPLIED |
| 58 | `.lg-chrome__connect` "Connect Patreon" | light | 4.46 | `var(--lg-sage-d)`→`#586b3f` | **5.64** cream / 5.85 white | ✅ APPLIED |
| 63 | `.lg-chrome__menu a.is-active` "The Map" | light | 4.53 (WATCH) | `var(--lg-sage-d)`→`#586b3f` | **5.64** cream / 5.85 white | ✅ APPLIED |

**Dark restores** (`lg-shared/site-header.css`, grouped with the skip-link dark rule):
`html[data-lguser-theme="dark"] .lg-chrome__wordmark { color: var(--lg-sage); }` (→ `#9cb37d`),
`… .lg-chrome__connect { color: var(--lg-sage-d); }` and `… .lg-chrome__menu a.is-active { color: var(--lg-sage-d); }` (→ `#b0c693`).
Account inks in `lg-shared/site-header.php` need no restore — dark defines `--lg-panel-danger`/`--lg-panel-hover-ink`.

*Out of scope (not in Ian's five):* the mobile hub-picker `.lg-hubmenu__item` active/hover
(`--lg-sage-d` on sage-tint, ~3.98 light) was never a bucket-B row — left untouched.

### C · Page-theme-owned — for Ian / the owning surface (chrome does not style these)

Not restyled — outside chrome. Listed worst-first by owner so each surface team can
act. Full rows in §6.

- **Hub feed** (`fc-gate` lock/text/CTA): 1.13–3.12 — gated-cover overlay text over a
  cream slab (some instances sit over the cover image → manual-check). Owner: bb-mirror feed.
- **Sponsors**: `sponsor-card__name` white-on-pale **1.14**; sponsor hero "Visit Website"
  white on pure-red `#f00` **4.00**. Owner: sponsors surface.
- **Directory**: view-toggle Map/List/Connect sage buttons (1.85–2.33 dark), leaflet
  marker clusters (1.29–1.94 dark over marker fill → manual-ish), attribution link.
- **Events landing**: `h2` "Upcoming" 3.29 light; sub/empty copy 3.51 dark.
- **Profile**: view-as chip + social "Connect" button (sage bg, white text — 2.29 dark /
  3.12 light), tabbar "You"/"Nav" 4.37–4.88, "Report this profile" 4.93.
- **Join / login**: lede copy 1.25 dark.
- **Front page**: featured-member badge 1.89, vpromo eyebrow/label 3.90, leaderboard pts
  3.49, fm role/CTA + join CTA ~4.48.
- **Calendar / sponsors** page-sub 4.33.

**Systemic pattern for Ian:** the brand **sage** (`#87986a` light / `#9cb37d` dark) with
**white** text recurs across page CTAs and buttons at ~3.1:1 — a palette-level decision,
not a per-element bug. One ruling (darken sage-for-white-text, or use dark text on sage)
would clear a whole cluster of C-bucket items at once.


## 9. Run log

- `2026-07-23` — after run (82 states, branch serve window; /srv+docroot swap,
  restored byte-identical, /hub 200 verified, chrome killed). 234→146 FAIL
  instances, 19 components resolved, 0 regressions. Window OPEN 21:30 → CLOSED.
- `2026-07-23` — baseline run (82 states, main). Confirmed the reported bug
  (submenu 1.25 dark) + caught the skip-link (1.25 dark) and Buck's under-scoped
  sort-pill combinator (already-served yet still 1.41–1.56 dark). Fixes committed
  `67a6f25`, `8f76dd1`. After-run pending a serve-flip window.
- `2026-07-23` — scaffold committed. Baseline/after runs held pending a RAM/serve
  window (keeper: one Chrome at a time on this box; hold until mentions-mobile
  posts CLOSED).
