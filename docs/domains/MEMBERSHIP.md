# MEMBERSHIP — roles, tiers, content gating, and both payment rails

## Laws (Ian's rulings, quotable)
- **THE FOUNDING LAW (Ian 8/19, verbatim):** *"We have two ways to become a
  member. Patreon and Stripe. And they both need to work together to produce
  a logical result."* Every membership behavior is judged against this: when
  both rails have an opinion about one person, the outcome must be coherent —
  never an accident of which rail spoke last.
- **Dual-rail (8/15, permanent):** every member-facing flow fires for BOTH
  Patreon and Stripe; hooks key on membership activation, never one rail's
  events. Gate 34d asserts there is NO THIRD ROAD to a grant.
- **MULTIPLE TIERS ON THE STRIPE RAIL (Ian 8/19, verbatim):** *"I've decided
  I want to be able to have multiple tiers."* This **SUPERSEDES** his 8/08
  ruling — *"move to ONE tier for the stripe memberships and have ALL tiered
  content open to the one tier through stripe"* — which is still quoted
  verbatim in `StripeLifecycle`'s docblock and was implemented faithfully as
  a constant. **Tier CREATION stays the catalogue file + import command**
  (scope ruling, same day): the dash gains PER-TIER PRICING only, no
  tier-builder form. Behind `lgms_multi_tier`, default OFF; gate 76.
- **THE JOIN BUTTON IS AN AUDIENCE, NOT A SWITCH (Ian 8/20, verbatim on #170):**
  *"We need the join button in the header to still go to patreon unless a test
  user is there on live."* This **refines** the ruling directly below rather
  than reversing it: the destination still offers both rails, but WHO is sent
  there is now a three-way answer — `off` (nobody), `allowlist` (the Stripe
  soft-launch cohort, signed in), `on` (everybody). The middle state is what
  lets live arm the Stripe join journey for hand-picked testers without
  announcing it, and it is safe on a cached site because it rides a per-viewer
  capability an anonymous ctx never carries. Behind `header-join-stripe`,
  default `'off'`; gate 79.
- **THE ANON FRONT DOOR IS A MEMBERSHIP SURFACE (Ian 8/20, verbatim):**
  *"can you Wire the header on Dev2 to have the stripe menuing that a logged
  out user would see?"* This **narrows** his 2026-06-12 ruling — *Join goes
  STRAIGHT to Patreon* — rather than reversing it: that ruling was correct
  while Patreon was the only rail, and the dual-rail founding law is now served
  by the DESTINATION offering both worlds instead of by the button choosing one.
  Behind `header-join-stripe`, default OFF; gate 79. **Connect Patreon is
  untouched at every width** — a patron linking an existing pledge is not
  joining, and that door stays exactly where it was.
- **One payment source per member (8/19, verbatim):** *"We should disallow
  double payment source for the same user."* Block at checkout, not warn.
  Enforcement keys on ACTUAL Patreon standing (lgpo_patreon_user_id +
  lgpo_tier_map), never the one-slot `payment_source` meta — that field is
  descriptive only ("where to manage billing"), a vestige of the
  single-source world. The reverse direction is unblockable (nothing of ours
  runs at patreon.com): site-payers who also pledge get SURFACED to Ian.

## The map (verified 8/19 against main)
- Tiers: docs/TIER-TAXONOMY.md is authoritative (looth1–4 roles; content
  gates public/lite/pro; WP roles are the system of record; profile-app
  deliberately dropped its tier column). Catalogue products carry ref
  looth2/looth3; lg-stripe-billing EntitlementManager::activeTier() resolves
  a customer's ref (highest wins). Deployed multi-tier gating runs TODAY on
  the Patreon rail.
- **THE STRIPE GRANT WAS A CONSTANT, AND IT OVER-GRANTED (#148, traced
  8/20).** Two Stripe grant paths exist and only one was tier-aware:
  **(a)** the path that actually runs today — Slim `ReturnHandler` →
  `tierForPrice($priceId)` → entitlement → `Sync::customer` →
  `RoleSourceWriter` → `Arbiter` — is **tier-correct end to end**; **(b)**
  `StripeLifecycle::applyConfirmed()`, the designated production replacement
  (flag `lgms_stripe_lifecycle`, OFF everywhere), read `$priceId` for the
  subscription upsert and then **ignored it**, applying `self::TIER`.
  ⚠️ **The direction is the opposite of the obvious guess: nobody was
  under-granted.** `TIER` is `'looth3'`, so a member buying **Looth LITE at
  $5 was granted Pro**. And it is not additive —
  `grantMembershipFromSubscription` revokes by source and re-inserts on any
  ref change, so the constant **OVERWRITES** path (a)'s correctly-resolved
  looth2 entitlement. Fixed behind `lgms_multi_tier`: `tierFor()` resolves
  the price, and an **unmapped price falls back to the constant and logs
  loudly — never to null**, because null reaches `applyOpinion` as a
  RETRACTION (the cancellation shape) and demotes somebody who just paid.
- **The price options are now per-tier**: `lgms_stripe_price_<tier>_<cadence>`,
  with a fallback chain to the legacy per-cadence option and then the original
  single option — **closed to non-default tiers on purpose**, since both legacy
  options were written when there was one tier and letting looth2 read them
  would sell Looth LITE at the Pro price, silently, on a box that looked
  correctly configured. `StripePrice::tiers()` derives the offered tiers from
  the catalogue (active, non-regional, `kind='membership'`).
- **A dash-set price now retires competing prices by RHYTHM, not by pointer.**
  `lgjoin`'s `renderTiers` draws one button per active recurring price with no
  dedup by cadence, so dev2's Looth PRO card was **photographed showing four
  buttons — $11/mo AND $9/mo, $132/yr AND $99/yr**. Retiring only the price the
  option names is not enough: dev2's options named prices 30/31 while 11/12 sat
  active and unpointed. Safe for members still billing on a retired price, and
  checked rather than assumed — `lg_ms_lookup_active_sub` joins `prices` with
  **no active filter**, and both `tierForPrice` implementations filter on the
  **product's** active flag, never the price row's.
- **The join page needed NO change** — `renderTiers` already keys off
  `products.length` and `singleTier` is a fact about the catalogue, not a mode.
  Verified in-browser as an authenticated admin: two cards, Looth LITE and
  Looth PRO, from the real catalogue.
- Dual-holder trap (war-gamed in docs/atlas/STRIPE-IDENTITY-AND-LIFECYCLE-
  DESIGN.md §2, re-verified 8/19): sweep skip (class-lgpo-sync-engine.php
  :581) + reader skip (PatreonSourceReader.php:36) freeze a dual payer's
  Patreon opinion; cancel Stripe ⇒ still-paying patron falls to looth1.
  Full multi-voice redesign stays PARKED under the 8/19 ruling (door closed
  instead). ⚠️ The "four live members in that shape" is NOT REPRODUCIBLE as
  of 8/19: live has zero `payment_source='stripe'` (all 1,214 that carry it
  say 'patreon') and zero stripe rows in `lg_role_sources`. Repaired since,
  or measured on dev2 — unknown from here. Do not requote the four as
  current.
- **THE JOIN BUTTON AND THE JOIN PAGE ARE TWO SWITCHES, AND ONLY ONE IS A
  FLAG (#165, measured 8/20).** `lg-shared/site-header.php` renders on EVERY
  page of SEVEN independent apps (bb-mirror, archive-poc, events, profile-app,
  membership-pages, lg-layout-v2, a docroot script) — verified by cookieless
  fetch of `/`, `/hub/`, `/events/`, `/sponsors/`, `/connect-your-patreon/`,
  `/shop-layout-planner/`, `/directory/members/` and `/lgjoin/`, all 200, all
  emitting the one anchor. So there is exactly ONE place to change it and no
  page it does not reach. ⚠️ **But `/lgjoin/` decides its own audience.**
  `router.php` lists it `['lgjoin.php','testgroup','public']` and the wp_option
  **`lgms_stripe_pages_live`** picks the column; while that is off the
  PRE-LAUNCH column applies and an anonymous visitor gets
  `lg_membership_admin_gate_or_exit()`'s *"This page isn't available yet"*
  stub. **Turning the header flag on alone produces a Join button that is wired
  correctly and lands nowhere.** Gate 79 §E asserts the destination admits anon
  whenever the header flag is ON, and reports rather than asserts while it is
  OFF.
- **The anon Join has a SECOND home, and at phone widths it is the ONLY one.**
  `bb-mirror/web/forums.css` hides `.lg-chrome__aside` with
  `display:none!important` at ≤640 on the hub — deliberate, the hub swaps it for
  its search bar — so the header's Join, Sign in and Connect Patreon are all
  gone there and `webroot/bottom-nav.js`'s PWA account sheet carries Join
  instead. It reads the header's href (`hdrHref`) so it cannot drift, but its
  `target="_blank"` was **unconditional**: harmless while Join could only be
  patreon.com, and an eject from the installed PWA (`display:standalone`) the
  moment it can be `/lgjoin/`. Both copies now derive the new tab from the href.
  The front page is different — its aside IS visible at 640.
- ⚠️ **A DEAD BAND AT 821–904px, PRE-EXISTING, OPEN FOR IAN.** Measured on main
  8/20 on `/hub/`: the anon Join pill sits at x=845 w=59 in an 821px viewport —
  entirely past the right edge, `document.scrollWidth` 905. The nav collapses
  into the hamburger at ≤820, so between 821 and roughly 904 the full nav and
  the anon cluster cannot share one row and Join is pushed out of view; Connect
  Patreon (x=649..797) still fits, and Join is last so Join is what goes. The
  front page is milder — clipped by ~23px, centre still reachable. **This is the
  same class as gate 12's 641–820 sign-in dead band, one band over**, and it is
  the third time a control Ian could not use was in the DOM the whole time.
  Held in gate 79's `KNOWN_MAIN_GAPS` (reported, not scored, self-expiring) so
  it blocks no lane while it stays visible. Not fixed by #165 — a one-href
  change cannot move a layout.
- **THREE purchase doors, not two.** The two in #150 were the Slim API
  (CheckoutController.php:124) and lgjoin's active-sub redirect
  (lgjoin.php:86). A THIRD was found 8/19 while building #150:
  `POST /wp-json/lg-member-sync/v1/me/checkout-session`
  (Wp/CheckoutRestController.php) mints a subscription session for any
  logged-in member. All three were Patreon-blind; all three now ask
  LGMS\Membership\PatreonStanding. Gate 75 asserts all three, so the
  third cannot go back to being the unwatched one.
- **The Slim app cannot read WordPress. Measured, not assumed (8/19):** its
  DB user holds `ALL ON lg_membership` + `USAGE ON *.*` only, so wp_users
  and wp_options are closed to it. The `WP_DB_NAME` / `WP_TABLE_PREFIX`
  keys in its `.env` are VESTIGIAL — no code in src/, config/, bin/ or
  public/ reads either. Anything the billing app needs from WordPress goes
  over the X-LGMS-Token REST channel (WpSync's pattern), never a query.
- `lg_patreon_members` lives in `lg_membership` — the billing app's OWN
  database — which makes a direct read tempting and wrong: it would be a
  SECOND definition of "paying Patreon", keyed on email rather than on the
  member. One definition, owned by the poller.

## State (8/20)
- **PRICES RULED (Ian 8/20, verbatim: "We are going to go with five and 11"):**
  FINAL 8/20 pm (Ian, funnel walk): LITE $5/mo + $55/yr, PRO $11/mo +
  $120/yr — SET on dev2 by keeper through StripePrice::setPrice (the dash
  code path, test-mode asserted), four-button dupe swept by the retire-by-
  rhythm sweep, catalogue verified clean (exactly 2 recurring prices per
  tier). Go-live: live catalogue registration + live dash session -> flip.
- **#148 BUILT** on `148-multi-tier`, flag `lgms_multi_tier` defaulted OFF,
  gate 76 (40 assertions, 10 mutations + 2 no-ops). Grant follows the price;
  dash Stripe Price tab is tier × cadence; WP checkout door accepts a
  validated `tier` and still refuses a price id from the body. Dash-only
  pieces carry no flag, as stated in the charter.
- ⚠️ **THE OBVIOUS GATE ASSERTION IS A VACUOUS GREEN** and cost a rewrite:
  *"a PRO purchase grants looth3"* passed on the defect, because the constant
  already WAS looth3 — it cannot distinguish a resolved value from a constant
  one. The assertion that bites is *"a LITE purchase grants looth2"*.
- **dev2 data cleanup still owed (not code):** price rows 11 ($11/mo) and 12
  ($132/yr) under product 9 are active and unpointed. The new sweep clears
  them the next time that tier+cadence is priced from the dash; nothing
  cleans them up on its own. Live is unaffected — its catalogue is empty.
- `test-checkout-session-metadata.php` was **dead on main** (fatal, exit 255,
  no FAIL line) because the door gained `StripePrice` and then
  `PatreonStanding` without either being added to its require list. Revived:
  20 assertions, including the *"body chooses NOTHING"* section.

## State (8/21, #180 — the anonymous tester's unlock link)
- **#180 BUILT** on `180-tester-token-url`, flag `tester-unlock` defaulted OFF
  twice over (`enabled => false` AND an empty hash), gate **85** (116
  assertions; red-first **25/25**). One shareable URL marks ONE browser with
  the cookie `lg_join_unlock`; for that browser only, the header's Join points
  at `/lgjoin/` and the join-flow door admits it.
- **THE LAW THIS CLOSES A HOLE IN, WITHOUT BREAKING IT.** #170's `allowlist`
  recognises a tester by `$caps['stripe_testgroup']`, a per-viewer capability an
  anonymous ctx never carries — the caching law working as designed, and also
  the whole gap: there was no way to hand ONE anonymous browser the Stripe door
  without handing it to everybody. The unlock **widens `allowlist` and adds no
  state**: in `'off'` the cookie is not consulted at all, so **'off' still means
  NOBODY** (#170's ruling, and live's tracked default), and in `'on'` it is
  redundant. Verified on the serving checkout 8/21: dev2 resolves `allowlist`
  and an anon on `/hub/` correctly gets patreon.com — the exact baseline Ian
  described.
- ⚠️ **IAN'S SAFETY NET IS REAL TODAY BUT IT IS NOT A WHITELIST — measured on
  BOTH boxes 8/21, and this supersedes any reading of "no one can sign up
  unless they are white listed" as a code fact.** Nothing in the signup or
  checkout path consults the cohort list; there are **zero** references to it in
  the poller's REST controller or the Slim billing app. Three unrelated
  accidents do the refusing: **(1)** page gating, which the unlock deliberately
  opens; **(2)** BuddyBoss's global **`bb-enable-private-rest-apis = 1`** (dev2
  AND live) making `POST /wp-json/lg-member-sync/v1/auth` answer anon **401
  `bb_rest_authorization_required`** — a setting re-armed by every DB reload,
  not a membership control; **(3)** LIVE ONLY, an **EMPTY Stripe catalogue** (0
  active products, 0 prices, 0 customers), so every checkout call refuses
  *"not mapped to a membership tier"* — **and that prop is removed on purpose at
  go-live.**
- ⚠️ **IN THE BROWSER THE REFUSAL HOLDS; AT THE API IT DOES NOT.** lgjoin's JS
  requires the auth call to return ok before it calls checkout, so a marked anon
  reaches the tier picker and dead-ends at *"Sign-in failed"* — **that is the
  safety net in practice, and it is what a tester will hit; it is not a defect.**
  But `POST /billing/v1/checkout` answered a bogus price **400 about price
  mapping, not 401**, and `/billing/v1/products` is **200 to anon**, so the real
  price ids are public. A real price id mints a Stripe session with **no account
  and no whitelist**; paying it runs `Sync::customer` →
  `UserProvisioner::findOrProvision`, which **creates a WP user by email** and
  grants the tier. **This is true with or without the unlock** — the unlock
  changes page visibility only and touches no checkout or signup path, which is
  why #180 shipped on that structural argument rather than on the whitelist
  premise. **The API gap is now issue #181, and Ian ruled FIX BEFORE GO-LIVE
  (8/21).** Do not treat it as closed by #180, and do not re-derive it from
  scratch — the probe evidence is the four bullets above.
- **NO SECOND SWITCH, and that is the deliberate difference from #165 and #170.**
  The admission lives in `lg_membership_testgroup_gate_or_exit` — the ONE gate
  both doors delegate to, exactly where invites plug in — so a marked browser is
  admitted regardless of `lgms_stripe_testgroup_pages`, and there is no state
  where Join is wired perfectly and lands on *"This page isn't available yet"*.
- ⚠️ **THE CLAIM RUNS BEFORE THAT GATE'S `manage_options` EARLY-RETURN.** An
  admin returns on the next line, so a claim handled below it would silently
  mark **nobody** for the one person most likely to click the link to check it —
  and Ian is an administrator. He would see the join page he can always see,
  conclude it worked, and hand out a dead URL. Gate 85 §A5.
- **The phone door needed no work**: `webroot/bottom-nav.js` reads
  `.lg-chrome__join` at runtime (`hdrHref`), so the PWA account sheet follows
  the header and cannot drift.
- **CONFIG COUPLING (declared)**: `platform/nginx/lg-microcache.conf` bypasses
  the anon microcache for `lg_join_unlock`. A marked browser **is** anonymous, so
  without it `/hub/` serves a 60s-cached header still pointing at patreon.com —
  the feature silently doing nothing on the surface most likely to be looked at
  first. Inert until the flag is armed.
- **The token never enters the repo.** The config stores sha256 and compares with
  `hash_equals`; the raw token lives only in the URL and the gitignored
  `.local.php`. Gate 85 asserts no tracked file pairs `token_sha256` with a
  64-hex value, and that the override really is gitignored.
- **Owed:** keeper places `platform/config/tester-unlock.local.php` on dev2 after
  the merge (`php -l` it first — a parse error there is a site-wide 500, since
  this partial renders on every page of seven apps), then hands Ian the
  TEST-URL. The 821–904px dead band from #165 is still open and still Ian's call.

## State (8/20, #170 — three states for the header Join)
- **#170 BUILT** on `170-live-header-allowlist`, flag `header-join-stripe`
  three-state and defaulted `'off'`, gate **79** extended (157 assertions with
  the browser leg, 104 without; red-first **23/23** — 21 mutations, 2 no-ops).
- ⚠️ **THE HEADER'S JOIN PILL IS ANON-ONLY, AND THAT NEARLY MADE THIS ISSUE A
  NO-OP.** Measured on main before designing, rendering the partial four ways:
  `.lg-chrome__join` sits in the ANON branch of `.lg-chrome__aside`, so a
  signed-in viewer gets **no Join pill at any width** — 1 for anon, **0 for a
  member, 0 for a tester, 0 for an admin**. A signed-in tester's only
  `/lgjoin/` door on main is the account-menu entry gated on the same
  `$stripe_tester` capability. So "Patreon for anon and unlisted members,
  /lgjoin/ for a listed member" — the obvious reading — would have rendered
  **byte-identically to `off` for every viewer**, and its gate would have been
  green having measured nothing. Ian chose to give the tester a real pill;
  `allowlist` therefore ADDS one control to the signed-in aside, and only in
  that state.
- **REUSES THE ONE COHORT LIST — there is no second list, and there must never
  be.** `lgms_stripe_lifecycle_allowlist` (WP user ids, written by
  `LGMS\CohortAllowlist` in the poller dash) reaches the header already, as
  `$caps['stripe_testgroup'] = manage_options || inCohort($uid)` computed in
  `InternalRestController` and passed through `Whoami::capabilitiesFor()`. The
  header needs no user id, no DB call and no option name; gate 79 asserts it
  contains none of them. **Admins are in the cohort by construction**, so Ian
  clicks the real button on live without adding himself to a list.
- **THE CACHING LAW IS STRUCTURAL, NOT CAREFUL.** The `allowlist` branch keys on
  a per-viewer capability an anonymous ctx never carries and which fails closed,
  so the logged-out page **cannot** differ between `off` and `allowlist` — not
  by intent, but because no anonymous input reaches the branch. cmp'd at 28,028
  bytes, the same number #165 proved. A corollary worth knowing: **an anonymous
  observer cannot tell `off` from `allowlist` from outside**, so gate 79's
  served-state probe reads `allowlist` as `off` — that is the law working, not a
  gap.
- ⚠️ **`enabled => true` IS STILL READ AND STILL MEANS `'on'`.** dev2's
  hand-placed `header-join-stripe.local.php` says exactly that and lives in the
  **serving checkout**, which no lane may edit. A tidy-up dropping the legacy key
  would revert dev2's header to patreon.com on the next `pull --ff-only`, with
  nobody having flipped anything and nothing in any diff to explain it. Gated
  against dev2's byte-exact on-box shape.
- ⚠️ **THE SECOND COUPLING: `allowlist` NEEDS A DIFFERENT PARTNER FROM `on`.**
  `on` pairs with `lgms_stripe_pages_live` (#165). `allowlist` pairs with
  **`lgms_stripe_testgroup_pages`**, and the two predicates are **not the same
  shape**: the header PILL has ONE lock (the cohort list, via the capability),
  while the DOOR — `lg_membership_in_stripe_test_group()` — has TWO (that flag
  AND the list). So a **listed non-admin** can be handed a pill and refused at
  the door, while an **admin passes both and sees nothing wrong** — the person
  most likely to check is the one person who cannot see the failure. Verify a
  flip by clicking Join **signed in as a listed NON-ADMIN member**.
- **The phone door is load-bearing.** At ≤640 on the hub `forums.css` hides the
  entire aside — account menu included — and the authed "You" sheet carried no
  destination links (Ian 6/24), so a signed-in tester had **no path to
  `/lgjoin/` at a phone width at all**. `webroot/bottom-nav.js`'s You sheet now
  mirrors the pill by reading the header, so it exists only when the header drew
  one and cannot drift from it.
- **A 9-byte whitespace leak was found in this lane's own work by gate 79 §C.**
  An indented `<?php if ?>` tag emits its own leading spaces whether or not the
  branch is taken, so the first draft of the tester block added 8 spaces and a
  newline to EVERY signed-in render in EVERY state including `off` — invisible
  on screen and to every href assertion. Kept as red-first mutation 21.
- **Owed / open:** the 821–904px dead band below is unchanged and still Ian's
  call; the three anon Patreon join CTAs #165 listed are still untouched; and
  the dev2 `.local.php` needs no edit to keep working, though keeper may rewrite
  it to `'state' => 'on'` for clarity once this merges.

## State (8/20, #165 — the go-live header)
- **#165 BUILT** on `165-header-join`, flag `header-join-stripe` defaulted OFF,
  gate **79** (94 assertions with the browser leg, 41 without; red-first 14/14).
  The anon header's Join follows the flag; the PWA account sheet follows the
  header; both derive `target="_blank"` from the destination rather than from
  the flag. **OFF is byte-proven, not argued** — the partial rendered from the
  branch and from `origin/main` with the same anon ctx is 28,028 bytes both
  ways, cmp clean, for the ABSENT config too; an AUTHED ctx is byte-identical in
  every state including ON, so the flag cannot reach a signed-in member at all.
  ON differs from main by exactly one line.
- **THE FLIP IS TWO SWITCHES, NOT ONE.** `header-join-stripe.local.php` on the
  box **and** `lgms_stripe_pages_live` in WP admin (Settings → LG Member Sync),
  in the same window, or Ian's logged-out Join lands on the pre-launch stub.
  Gate 79 §E turns from a report into a hard assertion the moment the header
  flag goes ON, so the pairing cannot be half-done quietly.
- ✅ **THE dev2 membership-pages 404 IS FIXED** — re-measured 8/20, anon and
  cookieless: `/lgjoin/`, `/join/`, `/connect-your-patreon/` and
  `/manage-subscription/` all answer **200** with the real shared header. The
  `fastcgi_param LG_MS_SLUG` defect attributed on #150 is gone, so the three
  gates lane 150 identified as reddening on it (34b, 36 anon-dark-contrast, and
  digest-forum-images) should be re-attributed rather than re-reported. **This
  supersedes the ⚠️ line in State (8/19) below.**
- **Owed / open:** the 821–904px dead band above (Ian's call); three other anon
  Patreon join CTAs found while sweeping and deliberately NOT changed —
  `profile-app/web/directory-members.php:154` (*"Join on Patreon →"* on the
  members directory), `archive-poc/web/defaults.php:88` (*"Join Looth Group"*
  footer nav) and `archive-poc/web/_chrome-footer.php:40` (*"Membership"*). All
  three are explicitly Patreon-labelled so none is wrong today, but they are
  where this same question lands next and keeper is taking them to Ian as a
  separate ruling.
- Note for whoever hand-places the `.local.php`: **`php -l` it first.** The
  reader is defensive about empty / non-array / missing-key / unreadable (all
  gated), but `@` suppresses warnings and not parse errors, and this partial
  renders on every page — a typo there is a site-wide 500, not one quiet
  feature.

## State (8/20, #169 + #171 — the front polish train)
- **THE ANON JOIN FUNNEL HAD NO DARK MODE AT ALL, AND IT WAS THE PAGE #165 POINTS
  AT.** `membership-pages/web/lg-shortcodes.css` — the whole stylesheet `/lgjoin`
  loads — contained **zero** `data-lguser-theme` rules, measured. All four
  Subscribe buttons rendered `#e5e7e1` on `#ffffff` = **1.25:1, invisible**;
  "Most popular" 1.51:1; "Secure checkout" 3.71:1. Fixed on `169-front-polish`,
  unflagged (every rule is NEW under the dark selector, so light is untouched by
  construction), gate **80**.
- **THE MECHANISM IS NOT THE OBVIOUS ONE, and it will bite the next member page
  the same way.** `:root` in that file sets `--lg-ink:#292929`, but
  `webroot/app-settings.js` sets `--lg-ink:#e5e7e1` as an **INLINE STYLE ON
  `<html>`** — and an inline style on an element beats a stylesheet rule
  targeting that same element. So in dark the ink flips near-white while every
  hardcoded `background:#fff` beside it stays put. ⚠️ Note also `--lg-muted`
  (this stylesheet's own token, `#5b6066`) is **never repointed** by app-settings:
  the app's dark ink is `--lg-mute`, a different name one letter apart. "It uses a
  token, so it must follow the theme" is false here.
- **TWO STYLESHEETS RENDER THE SAME `.lg-join__*` CLASS NAMES**, and that is why
  this bug survived a fix. `/connect-your-patreon/` loads `join.css`; `/lgjoin`
  loads `lg-shortcodes.css`. join.css's dark block already described this exact
  defect in its own comment and fixed it — for its page only. **Anything changed
  on one of these pages must be checked on the other**; gate 80 §C asserts both,
  plus the second (poller `assets/`) copy of lg-shortcodes.css, which is enqueued
  by `Plugin.php` and had also gone dark-blind.
- ⚠️ **GATE 36 WAS RED ON MAIN BECAUSE OF THIS, AND NO LANE CAUSED IT.** Its
  `lgjoin` baseline of 0 was captured while every membership-pages surface 404'd
  on dev2; that infra defect was fixed 8/20, `/lgjoin` began serving for the first
  time, and the real page's debt appeared against a baseline recorded from a dead
  page. Fixed rather than absorbed — lgjoin returns to 0 findings, so the baseline
  is honoured and **not raised**. If a future lane sees gate 36 red on lgjoin,
  suspect the page started serving something new, not that someone regressed it.
- **THE ISSUE NAMED THE WRONG CONTROL, recorded so nobody re-chases it.** #171
  named the header Connect Patreon pill *in dark*; measured, it is **11.34:1 in
  dark** with a ~9:1 outline and has no dark defect. Its outline **did** fail — at
  **2.72:1 in LIGHT**, under WCAG 1.4.11's 3:1, where the outline IS the control
  because the pill has no fill. Darkened to `#6b7c52` (3.95:1) with a dark restore
  so dark is byte-identical to before. Ian's call, 8/20.
- **Three more defects found while in there**, all fixed: `.lg-join__buy.is-selected`
  was `#fff` on `#ECB351` = **1.89:1 in LIGHT TOO** (no anon gate can ever see it —
  the class only lands after a click); `.lg-join__cta`, the primary button on the
  literal Patreon page, sat at a **1.05:1 BOUNDARY** against its own dark card
  (its old comment said the pairing was fine, which was true of the TEXT and never
  asked what the fill sits on — **gate 36 grades text; 1.4.11 grades boundaries**);
  and `.lg-join__feature` / `.lg-join__tier-tagline`, which became defects **only
  because darkening the card exposed them**.
- **TWO OF THE EIGHT FINDINGS WERE PHANTOMS OF THE INSTRUMENT.** The AMEX and
  Google Pay marks carry explicit `fill=` attributes in `lgjoin.php`, so what they
  PAINT is brand-correct (5.03:1 and 6.05:1); only the inherited `color` was
  near-white, and `color` is what a contrast reader sees. Their `color` is now
  pinned to the value `fill` already uses — the DOM reports what it paints, and
  rendering is unchanged. **Do not "fix" those marks' fills.**
- **#169: the secondary front-page Join is retired** behind
  `platform/config/front-signup-banner-retire.php`, default OFF. At 1440 logged
  out there were THREE join doors above the fold — header pill, this strip, hero
  button. Blast radius is exactly two URLs (`location = /` and `/front-page/` →
  `archive-poc/web/index.php`), unlike the header partial's seven apps. **OFF is
  byte-proven: 72,054 bytes both ways against `origin/main`.** ⚠️ The banner is
  the `if` half of an if/elseif whose `elseif ($is_member)` renders the "Welcome
  back" greeting — gating the wrong half deletes a member's greeting, so gate 80
  asserts the authed render is byte-identical across flag states.
- **Owed:** Ian's flip of `front-signup-banner-retire.local.php` on dev2 once he
  has seen the front page without the strip. The 821–904px dead band from #165 is
  still open and still his call — a contrast fix cannot move a layout.


## State (8/19)
- **#150 + #149 BUILT** on 150-double-pay-block, flag `lgms_double_pay_block`
  defaulted OFF, gate 75. One wp_option row read three ways; the Slim app's
  OFF state is the WordPress route not existing (404 ⇒ unknown ⇒ proceed),
  so there is no second switch. Fail-open by design: an unknown answer never
  blocks a sale. The unblockable reverse direction is surfaced BOTH ways: a
  read-only "Dual Payers" tab on Settings → LG Member Sync for Ian, and a
  notice on /manage-subscription/ for the member — so the first person to
  notice a double charge is the one paying it. Never reconciled silently.
- **Dual-payer census (read-only, 8/19). LIVE: ZERO.** 1,737 Patreon rows /
  1,221 paying patrons; 0 Stripe customers, 0 live subs, 0 bridge rows. The
  Stripe rail has never granted a live member, so enforcement can arm with
  nobody to reconcile first. dev2: 11, all seeded test accounts, and ALL of
  them found via `wp_user_bridge` — an email-only probe returns zero on the
  same data, so a census that matches on email alone under-reports.
  Re-run: `deploy/remediation/report-dual-payers.sql` (pure SELECT; the
  cross-database COLLATE is load-bearing — the two DBs differ, so removing
  it raises ERROR 1267 rather than returning nothing).
- Phase 0 merged + rehearsed (8/9): test runs go through EXISTING member
  pages. Billing endpoint live (/billing/v1/products = 200, repaired 8/17).
- ⚠️ **Every membership-pages surface 404s on dev2** (join, lgjoin,
  manage-subscription, gift, my-gifts, refund, welcome, membership-guide,
  connect-your-patreon) — `fastcgi_param LG_MS_SLUG $1` delivers a wrong
  value, so the router never reaches its REQUEST_URI fallback. Code is
  byte-identical to main and renders correctly when driven straight at the
  membership FPM socket. Infra, not code; it is the whole cause of gate 34b
  being RED on main, and it means NO Stripe member page can be verified over
  HTTP on dev2 until it is fixed. Attributed on issue #150.
- Live catalogue EMPTY (planned 8/11 reset; zz_truncsnap_20260811 snapshots
  hold the old test rows). Dev2 registers Looth LITE ($5/$60) + Looth PRO
  (placeholder $11/$132). Registration at go-live gates on IAN'S PRICE
  DECISION (dash: Settings → LG Member Sync). Per-tier control is BUILT (#148):
  the options are now lgms_stripe_price_<tier>_<cadence>, and the old
  lgms_stripe_price_month/_year pair is a read-only fallback for the DEFAULT
  tier only. Dev2's pair currently names PRO's $9/$99 — worth knowing when
  reading "Dev2 registers ... PRO (placeholder $11/$132)" above: BOTH pairs
  are active price rows on product 9, which is the duplicate-button defect.
- Owed: over-tiered 3 held; card-retry grace follow-up (Aron); the dev2 price-row
  cleanup noted in State (8/20); the `lgms_*` runtime-option flag
  family is INVISIBLE to gate 62 (it only scans `LG_*` symbols and
  `platform/config/*.php`), so registering those flags is a discipline here
  and not an enforced one.
