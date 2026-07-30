# Profile guide — screenshot SHOT LIST

**Purpose:** let whoever holds the browser engine shoot every frame the Membership
Guide's PROFILE entry needs **in one pass**, without re-deriving any setup. Pairs with
`PROFILE-SYSTEM-AUDIT.md` — each shot names the guide section it serves and the audit
section that says what must be true in it.

**Written by profile-audit, 2026-07-28, from a completed audit but WITHOUT an engine.**
The frame list is derived from source + store + the green visibility matrix. **Nobody
has yet confirmed these frames render as described at phone width** — that is exactly
what the pass is for. Treat mismatches as findings, not as shot-list errors.

---

## 0. Before you start

- **ONE engine on this box, and ask keeper first.** Count with `pgrep -x chrome`,
  never `-f`. Park between batches. An engine is 500-660MB on a 3.8GB box.
- **dev2 only.** dev2 and live hold different data; a slug from one 404s on the other.
- **Do not write to `/var/www/dev`.** Shots go to `/var/www/dev/mockups/<name>/` (that
  directory is a scratch publish area, not a serve symlink) or
  `~/projects/footer-mockups/<name>/`, which serves at
  `https://dev.loothgroup.com/footer-mockups/<name>/`.

## 1. Reaching the site from the box — the part that wastes an hour

`dev.loothgroup.com` resolves to **50.19.198.38, which this box cannot reach** (plain
curl times out, exit 28). You must pin.

**Pin to the box's internal IP `172.31.78.94`, NOT to `127.0.0.1`.** Loopback makes
`api/v0/users.php:18` treat you as an internal service, which changes what the app
returns (skips the anon 401, skips slug-stripping on private profiles). For a browser
engine, use a host-resolver rule:

```
--host-resolver-rules="MAP dev.loothgroup.com 172.31.78.94"
--ignore-certificate-errors        # loopback/internal cert is CN=buck-dev2.loothgroup.com
```

**Dev gate** (armed on dev2; the repo copy of the conf is the gate-free *live* posture
and carries no token — never look there):

```bash
TOK=$(grep -oP 'map \$cookie_loothdev_auth.*?"\K[^"]+' /etc/nginx/conf.d/loothdev-auth.conf)
# set cookie loothdev_auth=$TOK on dev.loothgroup.com
```

**Becoming a member / owner / admin** — mint a profile-app session:

```bash
sudo -n -u profile-app php /srv/profile-app/bin/mint-dev-token.php <wp_user_id>
# set cookie looth_id=<token>
```
Known ids from the matrix fixture: **member** wp 7, **owner** wp 1910
(profile user 1849, slug `visibility-matrix-qa`), **admin** wp 1.
Anonymous = gate cookie only, no `looth_id`.

> The `chrome-dev-login` skill already automates cookie-gate + WP auth. Prefer it if it
> still works; the above is the manual fallback and the authoritative parameter set.

## 2. Viewports

| name | size | why |
|---|---|---|
| **phone** | 390 × 844 | the guide's mobile set. Carries the fixed bottom tab bar. |
| **desktop-narrow** | 1280 × 900 | **below 1380** — picker is still a drawer here. Most laptops. |
| **desktop-wide** | 1440 × 900 | **≥1380** — picker is the permanent sidebar. Different UI. |

**Do not skip desktop-narrow.** The 1380px breakpoint means "desktop" is two different
screens, and shooting only 1440 hides the drawer behaviour from most real laptops
(audit §5).

---

## 3. The shots

Owner shots use the fixture profile `/u/visibility-matrix-qa` unless a richer profile is
preferred — if so, say which in the filename. Shoot **light and dark** where the guide
will show it; the profile honours `html[data-lguser-theme="dark"]`.

### A. Owner / editor — the spine of the guide

| # | Frame | URL | Role | Viewports | Must show (audit §) |
|---|---|---|---|---|---|
| A1 | Profile as owner, top of page | `/u/<slug>` | owner | all 3 | the dark **Profile controls** panel: View-as, Profile visibility chip, Discussion posts, hint line (§3) |
| A2 | Same, scrolled to blocks | `/u/<slug>` | owner | all 3 | block grip ⣿, ⌃ ⌄ ✕ controls, per-section privacy chip (§2.5) |
| A3 | **Sections drawer OPEN** | `/u/<slug>` then open picker | owner | phone + desktop-narrow | CORE/EXTRAS groups, **FILTERABLE** badges, **"Add gallery (N left)" counter in Extras** (§4, §6) |
| A4 | **Sections as permanent sidebar** | `/u/<slug>` | owner | desktop-wide only | 3-column grid, no toggle button (§5) |
| A5 | Avatar + banner affordances | `/u/<slug>` | owner | phone + desktop-wide | camera overlay on avatar, "+ Add banner" strip (§2.5) |
| A6 | Status light picker | `/u/<slug>` → "+ Status" | owner | phone + desktop-wide | work / collab / tour options (§4) |

> **A3 is the money shot for Ian's complaint.** The only existing captures
> (`/var/www/dev/mockups/u-burger-768-open.png`, 2026-06-15) are **stale**: they show
> `Gallery` as a palette bubble and no `Services`. Today's palette has Services in Core
> and Gallery behind the counter. If your capture still looks like June, something is
> wrong with your session, not with this list.

> ### ⚠️ SEQUENCING — READ BEFORE SHOOTING ANY OWNER-VIEW FRAME
>
> **Option A landed on branch `profile-audit` (`04113b2`) but is NOT on the serve.**
> It moves the Sections opener out of the privacy panel into a **"Your layout" row
> under the identity card** plus a **dashed "＋ Add a section" card at the end of the
> block list**.
>
> Both new affordances sit on the owner's `/u/<slug>` page, so **every owner-view
> frame changes**: A1, A2, A3, A5, A6 and B1–B5. Shooting them against the current
> serve produces images that are wrong the moment A merges.
>
> - **A-invariant, safe to shoot NOW:** B6, B7 (View-as — editor not emitted), B8
>   (anon gate), C1, C2, C3 (other people's views + directory), D1, D2 (entry points).
> - **Wait for A on the serve:** A1, A2, A3, A4, A5, A6, B1, B2, B3, B4, B5.
>
> A4 (the ≥1380 permanent sidebar) is *visually* unchanged by A, but shoot it in the
> same pass as the rest of the A-set so the whole owner set is one consistent build.

### B. Privacy — the section Ian named explicitly

| # | Frame | URL | Role | Viewports | Must show (§3) |
|---|---|---|---|---|---|
| B1 | Profile-visibility chip menu open | `/u/<slug>` | owner | phone + desktop-wide | Public / Members-only / Private (§3.2) |
| B2 | A **capped** section chip | section chip under a members-only header | owner | desktop-wide | the `⚠▾` capped state + its tooltip text (§3.2) |
| B3 | **Location: both dials** | `/u/<slug>` location block | owner | phone + desktop-wide | "Members see [City ▾]" **and** "Public sees [Private ▾]" — the two-audience model (§3.4) |
| B4 | Location precision menu open | as B3 | owner | phone | Private / State / City / Street address (§3.4) |
| B5 | Discussion-posts toggle | `/u/<slug>` | owner | phone | Public / Member-only + its note; **distinct from profile visibility** (§3.5) |
| B6 | **View as → Member** | `/u/<slug>?view=member` | owner | phone + desktop-wide | editor **gone**; members-visible sections only (§2) |
| B7 | **View as → Public** | `/u/<slug>?view=public` | owner | phone + desktop-wide | editor gone; public-only sections (§2) |
| B8 | **The members GATE screen** | `/u/<slug>` on a members-only profile | **anon** | phone + desktop-wide | `looth_render_members_gate()` join/sign-in — what a logged-out visitor really sees (§2.5) |

> B6/B7 are the ones members get wrong: **switching View-as removes the editor
> entirely.** Caption them together as a pair.

### C. What others see

| # | Frame | URL | Role | Viewports | Must show |
|---|---|---|---|---|---|
| C1 | Someone else's profile | `/u/<other>` | member | phone + desktop-wide | no edit affordances, no privacy chips |
| C2 | Directory list | the directory | member | phone + desktop-wide | **separate mobile/desktop JS — shoot both** (§4b) |
| C3 | Map pins | directory map | member vs anon | phone | named pin vs coarsened/absent, per precision (§4b) |

### D. Entry points and edge states

| # | Frame | URL | Role | Viewports | Must show (§1) |
|---|---|---|---|---|---|
| D1 | Desktop entry | any page | member | desktop-wide | header account menu → **My Profile** |
| D2 | Mobile entry | any page | member | phone | bottom tab bar **"You"** active (header bubble is hidden on mobile) (§5) |
| D3 | Claim interstitial | `/profile/edit` unclaimed | unclaimed member | phone | `looth_render_claim_interstitial()` — **NOT PROVEN, may be hard to stage** |
| D4 | Login interstitial | `/profile/edit` | anon | phone | `looth_render_login_interstitial()` |

D3/D4 need a member in an unusual auth state. **If staging one is expensive, skip and
say so** — do not mutate a real member's claim state to get a screenshot.

---

## 3b. CAPTURE PASS DONE — A-invariant frames (2026-07-28)

**8 frames captured and published (dev-gated):**
`https://dev.loothgroup.com/footer-mockups/profile-guide-shots/`

B6, B7 (View-as member/public), C1 (another member's profile), C2 (directory) —
each at phone 390×844 and desktop 1440×900. Engine held ~12 minutes, then parked
(`pgrep -x chrome` = 0 confirmed).

### Two findings from the pass

**1. `captureBeyondViewport` breaks mobile frames — use viewport-only.** Full-page
capture renders `position:fixed` chrome at its *viewport* offset, so the mobile tab
bar lands mid-page across the footer. It looks like a broken site and is purely a
capture artifact. **Every mobile guide screenshot must be captured viewport-only**
(scroll + stitch if a taller frame is needed).

**2. B7 confirms three documented claims at once** — worth keeping as the guide's
privacy anchor frame. In View-as → Public at phone width: the **editor is gone**
(no picker, no grips, no per-section chips), the **privacy panel remains**, and the
**master Profile-visibility chip is still there and still interactive**. That is the
§2 asymmetry — *whole-profile visibility stays editable in preview, per-section does
not* — visible in a single image, exactly as measured.

### Not captured, and why
- **D1 / D2** (entry points) — both `/hub/` navigations returned no screenshot data
  in this pass. Not chased; the engine was scarce. Retry next pass.
- **B8** (members gate) — still impossible with fixture 1849, which is parked
  `header=public`. Needs a `header=members` subject (audit §9 item 2b).
- **C3** (map pins) — not attempted this pass.
- **A1–A6, B1–B5** — deliberately skipped; they change when option A merges.

## 4. Naming

`<area><n>-<slug>-<viewport>[-dark].png`, e.g. `a3-sections-open-phone.png`,
`b3-location-dials-desktop-wide-dark.png`. Keep the numbers — the guide's captions will
reference them.

## 5. When you're done

- Park the engine, confirm `pgrep -x chrome` = 0, report the number.
- Anything that did **not** look as described above is a **finding** — the audit says
  what the code and store require, so a mismatch means either the audit is wrong or the
  system drifted. Either matters. Report it rather than silently reshooting.
- The fixture profile 1849 is the **visibility matrix's** permanent fixture. Do not
  delete it and do not leave it in a non-default state — the matrix parks it
  members-only and expects to find it that way.

---

_Lane: profile-audit. Companion to `PROFILE-SYSTEM-AUDIT.md`._
