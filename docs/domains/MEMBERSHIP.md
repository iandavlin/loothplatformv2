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

## State (8/22, #194 — a product's tier stops being a SQL statement)

- **WHAT THIS ADDS:** a **Products** tab on the LG Member Sync dash (third, after
  Health — the default tab is still Settings, so every bookmark and every #190
  redirect lands where it did). It lists every product the webhook has synced —
  name, Stripe id, active/archived, `region_tag`, and its prices with their
  intervals — and **sets each product's tier and region**. Ian, 8/22: *"Do we
  have a spot in the dash where we register the stripe products. Like
  looth-lite regional A ?"* Measured answer at the time: no.
- ⚠️ **THE WRITER LIVES IN THE POLLER, AND THE OBVIOUS HOME IS THE WRONG ONE.**
  `lg-stripe-billing` owns this table and already has `PdoProductRepository`, so
  that is where a shared writer belongs — until you measure the boxes. **On dev2
  `/srv/lg-stripe-billing` is a symlink into the serving checkout; on LIVE it is
  a REAL DIRECTORY with its OWN `.git`**, and #192's
  `src/Core/WebhookReceipts.php` **is not there at all**. That app is deployed on
  a different schedule from the monorepo and is behind it. WordPress requiring
  code out of that path would refuse to write **on live** — the exact box, and
  the exact night, this tab exists for. `Membership\ProductCatalog` therefore
  sits beside `Db::pdo()`, which is how `Health`, `StripePrice` and
  `Repos\ProductRepo` already reach this database. (Keeper is filing the
  billing-app deploy drift separately; it is bigger than this issue.)
- ⚠️ **`StripePrice::assertTier()` IS NOT REUSABLE HERE, and reusing it would
  have made the tab useless on the one box that needs it.** It validates against
  the tiers **already present in the catalogue**, so on a box where nothing is
  mapped yet it throws *"the catalogue has not been imported"* — and this tab
  could never make the FIRST mapping. That box is live, and that moment is
  go-live. Validation is against **WP roles** instead, which
  `docs/TIER-TAXONOMY.md` names the system of record, narrowed by a **SELLABLE**
  list. **Two independent locks**, and the distinction is real: removing the
  sellable list still leaves `looth9` refused by the role check, which is how
  red-first M8 was re-targeted rather than the gate weakened.
- **looth1 AND looth4 ARE REFUSED THOUGH BOTH ARE REAL ROLES.** looth1 is the
  unpaid resting tier every account falls back to and looth4 is the permanent
  comp bypass the Arbiter short-circuits on. Neither is something a card payment
  may buy, so neither is offered and both are refused if posted.
- ⚠️ **REGION IS IN THE SAME CONTROL, AND THAT WAS A RULING, NOT A PREFERENCE.**
  `products.region_tag` is written by **nothing** but the hand-run SQL the import
  script prints — `ProductSyncHandler::handleProductEvent` does not pass it — so
  a tier-only tab would still have sent Ian to a SQL prompt to finish
  *"looth-lite regional A"*, **his own example**. Keeper ruled it in, 8/22.
  It also makes the dash's column set **exactly** the import script's
  (`ref`, `kind`, `region_tag`), which is what "one definition" means here.
- **THE AUDIT LINE SHARES THE WRITE'S TRANSACTION, deliberately the opposite of
  `WebhookReceipts`.** There, every `Throwable` is swallowed because bookkeeping
  must never turn a delivered webhook into a three-day Stripe retry. Here the
  change IS the point: a money-adjacent mapping nobody can account for is worse
  than no mapping, so **a receipt that cannot be written rolls the mapping
  back**. `audit_log`, not `admin_action_log` — the latter has
  `customer_id NOT NULL` with an FK to `customers`, and a product has no
  customer. One line per **CHANGE**, never per press of Save.
- **STRIPE CANNOT UNDO IT, VERIFIED NOT TRUSTED.** `upsertProduct`'s
  `ON DUPLICATE KEY UPDATE` names only `name` and `active`; the handler passes
  `ref` as null and it is used on first INSERT only. The tab **says so on
  screen**, because otherwise people go looking in the Stripe dashboard for a
  setting that lives here.
- **#194 BUILT** on `194-products-tab`, gate **93** (63 assertions; red-first
  **41/41** — 39 mutations each reddening its own named assertion, 2 no-op
  controls proven inert). **Dash-only, so no flag**, matching #190, #148, #183
  and #192.
- ⚠️ **THE RED-FIRST FOUND THREE HOLES IN THE GATE ITSELF**, each a shape worth
  recognising: **(1)** the `kind` assertion ran against a row **already seeded**
  as `membership`, so it passed whether or not the UPDATE wrote that column —
  #148's vacuous green, one section over; **(2)** *"a refusal writes no audit
  line"* stayed GREEN against a validator that silently fell back to the DEFAULT
  tier, because the fixture already held that tier and the fallback was a
  **no-op** — the fixture now starts on the other tier; **(3)** two mutations
  were **BROKEN rather than WRONG** (a parse error and an `execute()` arity
  mismatch) and killed the run at **exit 255 with no FAIL line** — this plugin's
  test files have died that way three times, so the gate now installs an
  exception handler that reports a fatal **as a finding**.

### Verified on dev2 against the real catalogue (2026-08-22)

- The panel renders dev2's **11 products** (6 active) through the real code and
  real MySQL: prices with intervals, region chips, archived rows dimmed.
  **The tab and Health both say 0 unmapped active**, on the real box.
- The real write was exercised end to end **against MySQL, which SQLite cannot
  prove**: an archived, unmapped product was mapped to looth2/regional_b, the
  repeat press was correctly a no-op, `looth4` was refused, and it was restored
  to its exact original state. **Two `audit_log` rows** with real from→to — the
  **first rows either box has ever held** — and `subject_type='product'`, so
  Health's webhook question still counts **zero**, as gate 93 §C4 requires.
- **PICTURE for Ian:** `/mockups/lanes/194-products-tab.html` — the real screen,
  really rendered, with the Save buttons made inert.
- ⚠️ **Noticed, deliberately not changed:** a product created in Stripe that is
  NOT a membership arrives as `kind='membership'` anyway (the webhook hardcodes
  it), so it would sit red forever with no way to say "this is not a
  membership". **Unreachable today** — nothing creates one — and out of scope
  here; it is the next thing this tab will want. Also visible now for the first
  time: dev2 carries **active one-time prices** on both LITE ($66) and PRO
  ($145), which nothing in the join flow offers.
- **Owed:** Ian looks at the tab on dev2 after the merge. Live's catalogue is
  still empty, which is what makes this the tool he needs at the moment it is
  not.
## State (8/22, #193 rider — the double-pay guard was ON and BLIND)

- **WHAT HAPPENED:** Ian flipped `lgms_double_pay_block` ON on dev2 (#150's
  pending flip). Measured immediately after, from 127.0.0.1, **with the correct
  shared secret**: `POST /wp-json/lg-member-sync/v1/patreon-standing` still
  answered **401 `bb_rest_authorization_required`**.
- ⚠️ **THE GUARD'S BEST QUALITY IS WHAT MADE THAT FATAL, and this is the shape
  worth remembering.** It is **fail-open by design** — a WordPress blip must
  never stop a legitimate sale — so a route that cannot answer produces UNKNOWN,
  and UNKNOWN waves **every** checkout through. The guard read as armed on the
  dash and refused nobody, **including the listed tester who actively pays
  Patreon: the exact person it exists to stop.** #181 measured this 401 and
  REPORTED it rather than opening it, which was right while the flag was off
  everywhere. **The flip is what turned a report into a live defect** — a
  reported-not-fixed finding can become urgent without anybody touching the
  code.
- **EXEMPTED on keeper's ruling**, same three conditions as #193's `/auth`:
  the route's own secret check untouched (it is a membership **oracle** — it
  says whether a named address pays us — so an open one is worse than a closed
  one); surgical, its own filter on its own controller; and **gate 86's
  still-restricted list shrank DELIBERATELY** — `/sync-customer` and
  `/send-gift-codes` stay shut, the sweep covers the first, nothing waits on the
  second. ✅ **BOTH WERE OPENED BY #203 (8/22)**, along with
  `/send-gift-recipient`, which #181 and #193 both missed; "nothing waits on the
  second" turned out to be a purchasable product waiting on it. Only `/run-now`
  is still shut. See State (8/22, #203) above — this line is kept because the
  *ruling* it records (one filter per route, count updated deliberately) is what
  #203 followed. **This supersedes #181's one-route-only condition**, and the gate says
  so in its own comment so the change reads as decided rather than drifted.
- ⚠️ **THE FILTER IS UNCONDITIONAL THOUGH THE ROUTE IS FLAG-GATED.** Naming a
  route that does not exist changes nothing — WordPress 404s it either way, so
  OFF is untouched (gate 75 §9d) — while a flag-conditional exemption would be a
  SECOND place the flag has to be read correctly. Gate 86 §K3k/§K3l pin both
  halves so a tidy-up cannot merge them.
- **THREE FILTERS ON THAT HOOK NOW, ONE PER CONTROLLER**, and gate 86 §K9b pins
  the count so a fourth must be a decision somebody writes down.
- **Gate 75 §9** drives the **REAL adapter over REAL HTTP** against a real 401,
  because §5 stubs the probe and **a stub always answers** — which is precisely
  why the reachability half was invisible for that gate's whole life. 9a the 401
  decodes to UNKNOWN; 9b UNKNOWN lets the buyer through (a silently disarmed
  guard); **9c\* THE MIKELLE CASE — a paying patron is REFUSED end to end**;
  9c4 a non-patron on the same reachable route still buys.

## State (8/22, Ian's ruling — the linked Patreon email on every double-pay and switch surface)

- **THE LAW (Ian 8/22, verbatim):** *"its critical to add to any double pay or
  switch surface the email associated with their patreon account and that that
  is the email to use when adjusting thier membership."*
- **WHY IT MATTERS:** the linkage between the two rails is the EMAIL. A member
  who cancels Patreon and rejoins here under a **different** address is the #149
  lost-membership class — they pay again and land on a second account that knows
  nothing about the first. Naming the address before they choose one prevents
  it, and it costs a sentence.
- ⚠️ **MEASURED ON LIVE BEFORE BUILDING, AND THE NUMBER IS ZERO.** Of **1,223**
  active paying patrons, **1,223 carry a Patreon email and 0 differ** from their
  WordPress address. So this changes nothing anybody can see today; its value is
  entirely **preventive** — it names the address before a member picks a
  different one, and it is already correct on the day one diverges. Do not quote
  it as fixing a live divergence. *(The query needs the explicit cross-database
  `COLLATE utf8mb4_unicode_ci`; without it MySQL raises ERROR 1267 — the trap
  `report-dual-payers.sql` already records.)*
- **KEEPER'S ONE RAIL: SIGNED-IN MEMBER, THEIR OWN SURFACE, ONLY.** An anonymous
  caller's refusal never carries it — `POST /billing/v1/checkout` takes an
  arbitrary email and answers a stranger, so including it there would turn the
  double-pay guard into an **address-lookup service** for anyone who types a
  member's WordPress email.
- ⚠️ **AND THE RAIL IS STRUCTURAL, NOT REMEMBERED.** `patreon_email` is
  deliberately **NOT returned by `PatreonStandingRestController`** — the only
  channel the Slim billing app has into WordPress — so **the anonymous 403
  cannot include it even by mistake, because the app never receives it.** Same
  discipline #192 used for the health panel's secrets: a property of the data,
  not a rule the renderer has to remember. Gate 75 §10g asserts the absence
  **with a fixture that HOLDS an address**, since a fixture with none leaves
  nothing for a broken build to leak.
- **WHERE IT SHOWS:** `/manage-subscription/` (the dual-payer notice),
  `/lgjoin/`'s blocked-by-Patreon block (a switch surface by definition — and
  that branch is reachable only when `$isLoggedIn && $wpUserId > 0`, so the rail
  holds structurally there too), and the WP `/me/checkout-session` 409. That
  last one is safe because it is session-authenticated **and** takes the member
  from `get_current_user_id()`, never the body — gate 75 §10i2/§10i3/§10i4
  assert all three, because 10i is a leak if any of them stops being true.
- **ONE SENTENCE, TWO APPS, WORD FOR WORD** (`PatreonStanding::
  linkedEmailSentence()` and `lg_membership_linked_email_sentence()`), compared
  by gate 75 §10k. **No linked address produces NOTHING** — never an invented or
  guessed one, because the member would act on it.
- **Gate 75 at 131**; red-first **17/17** + 1 no-op via the new
  `tools/gates/double-pay-redfirst.py`.
- ⚠️ **THREE OF MY OWN §10 ASSERTIONS WERE BLIND FIRST TIME, and the gate was
  fixed, not the mutation:** a *"renders it"* check that looked for the function
  NAME (satisfied by a call pinned to `false`); a selector regex whose `[^{}]*`
  swallowed a `-DISABLED` suffix, so a **prefix match read as a hit**; and no
  assertion at all that the standalone app SELECTs the address — its sentence
  had nothing to say while every copy check passed.
- **OBSERVED, NOT FIXED:** `.lg-join__patreon-block` — the whole
  blocked-by-Patreon refusal block on `/lgjoin/` — **has no CSS rule anywhere in
  the repo**. It renders as bare `h3` and `p`. Pre-existing from #150, and
  Ian decides from pictures, so it is worth a look; not this lane's to restyle.

## State (8/22, #193 — the tester list takes ADDRESSES, not only existing accounts)

- **THE LAW THIS ADDS (Ian 8/22, verbatim):** *"Is this an accurate test? I
  thought the whitelist would have them generating a wp-user like a normal new
  member join. Is that not possible?"* It was not, and the miss was #181's — a
  fence that could only recognise people who already existed.
- ⚠️ **THE COLLISION, IN ONE LINE:** `UserProvisioner::findOrProvision` creates a
  WP account from the checkout email — that IS the normal new-member journey —
  but `CheckoutAudience::allowsEmail()` resolved the address to a WP user and
  refused when there wasn't one. **So an address with no account was refused
  BEFORE it could be provisioned**, every tester had to pre-exist, and the one
  path a real stranger takes at GA was the one path the test could not exercise.
- **ONE STORE, TWO FORMS.** `lgms_stripe_lifecycle_allowlist` now holds positive
  ints and email strings in the same array. **Safe by construction, not by
  care:** `StripeLifecycle::allowlist()` and the standalone app's
  `lg_membership_stripe_test_group_ids()` have ALWAYS accepted only ints and
  digit-strings, so an address entry was already inert to every reader that
  predates this. New siblings `allowlistEmails()` / `inCohortEmail()`.
- ⚠️ **THE UNION IS READ-SIDE, INSIDE `inCohort()`, AND THAT CHOICE IS THE WHOLE
  DESIGN.** The tempting alternative is the shape `Invites::consumeForUser()`
  already uses — when the account appears, promote its id onto the list. It
  fails the one constraint #181 is built on: *a session minted for an address
  LATER removed must still fail to provision*. A promoted id outlives the
  address that earned it. Read-side, **striking an address shuts every door in
  the same instant**, including a checkout already minted. Gate 86 §J7 is the
  assertion that chose it.
- ⚠️ **AND IT IS REQUIRED, NOT COSMETIC.** Without the union a listed address
  would mint a session and provision an account, and then `Sync::customer`'s
  fence — asking the very same predicate with the NEW user's id — would skip the
  grant. **The tester ends with an account and no membership, and the rehearsal
  reads as passed.** This is the failure mode to remember.
- **NO FLAG, AND THE EMPTY STATE IS THE OFF STATE** (keeper ruling D2, 8/22).
  With no addresses listed every path short-circuits on the id check and the
  address half is never read — proven at the decider with a **call spy plus a
  liveness partner** (gate 34, gate 86 §J10/§J10b), not argued. A separate flag
  would have created a state where an address is listed and silently ignored:
  the "wired perfectly, lands nowhere" shape this file already carries three
  warnings about.
- ⚠️ **`CohortAllowlist::write()` WOULD HAVE EATEN EVERY ADDRESS.** It rebuilt
  the whole option from the id list with `array_map('intval')`, so the first
  dash edit of any MEMBER would have deleted every tester address — no error, no
  notice, and the testers who could no longer buy with no way to tell why.
  Union-preserving now; red-first M39 models exactly that. `addedMap()`'s
  `(int) $k > 0` had the same shape for the date column (M40).
- **THE PAGE DOOR LEARNED IT TOO, and skipping that would have bitten mid-test.**
  Without it a tester whose account was created by the join is admitted to
  CHECKOUT and refused the JOIN PAGE the moment they arrive on a browser without
  #180's unlock cookie — a second device, a cleared cookie jar, or simply
  `/manage-subscription/` after they have paid. **Lock 1 still outranks it**
  (`lgms_stripe_testgroup_pages` off refuses a listed address), anon still
  cannot become listed by carrying an address, and **a viewer whose address
  cannot be READ is refused** — a DB error must never admit.
- ⚠️ **THE DASH RE-CHECKS FOR AN ACCOUNT AT WRITE TIME and stores the MEMBER when
  one exists.** Between the lookup and the click the person may have signed up,
  and two entries for one human is a list that later disagrees with itself. The
  Testers tab shows which listed addresses have signed up and which have not.
- **#193's DEPENDENCY ON #181's `/auth` FINDING — D3, approved by keeper 8/22
  with three conditions.** Measured on dev2 over loopback BEFORE the change:
  `POST /wp-json/lg-member-sync/v1/auth` → **401 `bb_rest_authorization_required`**.
  That route is what creates the account for a logged-out visitor at `/lgjoin/`
  (`lgjoin.php`, `CONFIG.authUrl`), so a listed tester would type their address,
  press Continue and be told *"Sign-in failed"* — the whitelist landing
  correctly and the rehearsal still impossible. Exempted through BuddyBoss's own
  `bb_exclude_endpoints_from_restriction`, naming `/auth` and `/gift-auth` and
  **nothing else**; `/sync-customer`, `/patreon-standing` and `/send-gift-codes`
  stay shut exactly as #181 left them (gate 86 §K3). ⚠️ **SUPERSEDED — all three
  are open now** (`/patreon-standing` on #193's own rider, the other two plus
  `/send-gift-recipient` on #203), each by its OWN filter. What §K3 still
  asserts, and what stayed true, is that the `/auth` filter never grew to hold
  any of them. **The route's own hardening
  is untouched** — per-IP 20/hour, per-email 5 fails/15min, `is_email()`, the
  8-character minimum, `wp_check_password()` — and §K asserts each COMPARISON,
  not the key names. **The same 401 is why a gift recipient with no account
  could not redeem one**; that is repaired in the same breath.
- ⚠️ **WHAT #162 DOES AND DOES NOT COVER ON THAT DOOR — confirmed from merged
  code, not assumed (keeper condition 2), and it is HALF.** ENFORCEMENT: yes —
  `platform/nginx/lg-auto-ban-doors.conf.template` gives `/auth` and
  `/gift-auth` their own exact-match locations returning JSON to a listed
  address. DETECTION: **no**, and #162 says so in its own words: *"The stuffing
  detector has never watched it — it checks passwords with
  `wp_check_password()` and so fires no `wp_login_failed` hook."* Verified
  independently: `giftAuth()` fires `wp_login` on success and never
  `wp_login_failed`. **So a ban EARNED at wp-login.php is enforced here, and a
  stuffing run conducted only against this route earns none** — its two
  throttles are what stand there. **And neither box has #162 installed**: the
  flag defaults false and the nginx snippet exists on neither, so the
  enforcement half is absent today on both. The one-line change that would close
  detection (fire `wp_login_failed` from the wrong-password branch) is #162's
  design call and was deliberately NOT made here.
- **#193 BUILT** on `193-tester-emails`. Gates **86 §J+§K** (212 assertions
  total; red-first **42/42** + 3 no-op controls), **34** (67, the real
  normalizer and union), **90 §I** (119 total; red-first **43/43** + 3 no-ops),
  **34b** (127 total). Neighbours re-run standalone and green: 34d, 75, 76, 91,
  `test-identity-gate`, `test-checkout-session-metadata`.
- ⚠️ **THREE GATE DEFECTS FOUND BY RED-FIRST, ALL THE SAME SHAPE — asserting that
  a STRING is present rather than that a DECISION is made.** §K looked for
  `lgms_ga_ip_` and `wp_check_password`, so `if ( $ipHits >= 20 )` → `if ( false )`
  disabled the throttle with the gate green (keeper's condition 1 unguarded by
  the section written to guard it). Gate 90 §I6 looked for
  `lgms_cohort_confirm_email` anywhere in the handler, so neutering the branch
  left the string sitting there unreachable. §I8 looked for
  `CohortAllowlist::emails()`, so `foreach ( [] as $addr )` rendered nothing and
  passed. **A fourth was a fixed-target satisfied by the wrong occurrence:** §I9
  matched the count expression anywhere in the tab, so reverting the HEADING
  stayed green on the CHIP's identical copy. All four now assert the branch,
  the loop, or the pinned markup.
- ⚠️ **AND SIX "BLIND SPOTS" THAT WERE NOTHING OF THE KIND — worth knowing before
  the next lane repeats it.** Mutations to the real `StripeLifecycle` stayed
  green against gate 86, which reads as six holes. Gate 86 **stubs** that class
  on purpose (its docblock says what it measures is whether the checkout path
  ASKS). The harness now targets **a gate per mutation**; the real class is
  driven against gate 34. Pointing a mutation at the wrong gate is a false
  green.
- **Owed:** Ian looks at the Testers tab on dev2 after the merge and lists a real
  address. ⚠️ **The dev2 serve runs `main`, so nothing in this lane can be
  verified over HTTP until it is merged** — the `/auth` 401 above was measured
  against main and is the state that will change on the pull. Live writes stay
  his.
## State (8/22, #203 — the last dead server-to-server routes, and the count was wrong)

- **WHAT THIS CLOSES:** #181 measured four shared-secret routes in
  `lg-member-sync/v1` answering **401 `bb_rest_authorization_required`** to
  their own secret-bearing callers and opened one. #193 opened `/auth` +
  `/gift-auth`; its rider opened `/patreon-standing`. #203 opens the rest bar
  one. Measured on dev2 from 127.0.0.1 **with the correct 64-char
  `lgms_shared_secret`**, before and after, in the same process:

  | route | before | after |
  |---|---|---|
  | `/sync-customer` | 401 `bb_rest_authorization_required` | 400 `{"ok":false,"error":"customer_id required"}` |
  | `/send-gift-codes` | 401 `bb_rest_authorization_required` | 400 `{"ok":false,"error":"to_email and codes required"}` |
  | `/send-gift-recipient` | 401 `bb_rest_authorization_required` | 400 `{"ok":false,"error":"recipient_email missing or invalid"}` |
  | any of the three, wrong or absent secret | 401 `bb_rest_authorization_required` | **401 `rest_forbidden`** — our own check |
  | `/run-now` | 401 `bb_rest_authorization_required` | **unchanged, on purpose** |

- ⚠️ **THE ISSUE SAID TWO AND THE FILE SAID THREE.** #203 was written naming
  `/sync-customer` and `/send-gift-codes`. Enumerating `RestController` instead
  of trusting the count found **`/send-gift-recipient`** behind the same wall,
  behind the same `auth()`, **in the same flow as the second**: Slim's
  `WpGiftMailer::sendOneRecipient()` calls it for the **Send / Resend /
  Reassign** buttons on the buyer's My Gifts dashboard. Opening its twin alone
  would have made a gift **arrive when bought and vanish when resent**, and
  `WpGiftMailer::post()` is best-effort by design — errors logged, never raised
  — so the button reports success both times. Keeper ruled it in and widened the
  issue (3 → **6** filters, not 3 → 5). **A count in an issue is a starting
  point, not a measurement.**
- **`/run-now` STAYS SHUT, AND THAT IS A DECISION.** Same 401, same correct
  secret. Ops-only, nothing but a person calls it, the five-minute cron does its
  job, and what it exposes is a **whole Tick** — so it is the one route where
  #181's *"the sweep covers it"* is still the true sentence. It is also now
  **M33's wrong-decision mutation**: appending it to a neighbour's filter is
  exactly the widening keeper's condition 3 forbids.
- ⚠️ **READ THE CODE, NOT THE NUMBER — both refusals are 401.**
  `rest_forbidden` is the route's OWN secret check saying no, which is healthy.
  `bb_rest_authorization_required` is BuddyBoss pre-empting the REST stack at
  `rest_request_before_callbacks` (priority 100) before any
  `permission_callback` runs, which **cannot tell the billing app apart from an
  anonymous stranger**. An assertion phrased *"it refuses"* passes on both and
  measures nothing. The mechanism is an **exact** `in_array()` against
  `$request->get_route()` (`bb_restricate_rest_api`,
  `bp-core/bp-core-functions.php`), so the constants must be the route pattern
  to the character.
- **IT IS A REPAIR, NOT A BYPASS, and here that is easier to hold than it was
  for `/auth`.** All three carry `permission_callback => auth()`, which requires
  a **configured** secret and compares with `hash_equals`. Untouched. Only
  *which* check refuses changes. Gate 86 §K10n/§K10o pin that none of the three
  quietly became `__return_true` in the same edit — the single change that would
  turn this into the thing it is not — and §K10k/§K10l/§K10m **execute**
  `auth()` rather than reading it.
- **THREE FILTERS, ONE PER ROUTE, SHARING ONE PRIVATE APPENDER.** A single
  combined filter would be smaller and would make the next widening invisible.
  `appendExemption()` is what keeps them from drifting on the three things that
  matter: a non-array is handed back untouched, entries are appended not
  replaced, and it is idempotent. **It takes one route and cannot take a list.**
- **`RestController::SECRET_ROUTES` IS NEW, AND IT IS WHY THE PANEL CAN STOP
  LYING.** The health panel's channel line said *"checkout-audience is
  exempted"* from #181 until #203 — by which time #193 and its rider had opened
  three more, so an operator would have read the rest as shut while two were
  open. **The health panel failing its one job, quietly, with every assertion
  above it green.** It now **runs the hook** for the roll-call and subtracts it
  from `SECRET_ROUTES` for the still-shut line, so neither can be kept up to
  date by hand. Gate 86 §K11 asserts that constant against `register()` in both
  directions, so a shared-secret route added and never named is a RED.
- **#203 BUILT** on `203-route-exemptions`. **No flag** — the routes are
  unconditional, so unlike #193's rider there is no second place to read one
  wrong. Gate **86** at 261 assertions (§K10 + §K11 new, §K3 re-aimed, §K3j/§K9b
  3 → 6), gate **91** at 121 (§F10, the roll-call), red-first **58/58 + 5
  no-op controls**.
- **RE-RUNNABLE PROOF:** `tools/verify/203-route-exemptions-verify.php` loads
  the branch's filter code, asks it for the route strings, and drives them
  through BuddyBoss's **real** restriction function via `rest_do_request()` —
  filters off, then on, in one process. Side-effect free by construction: every
  request carries no body, so each route refuses at its own required-parameter
  guard. **No customer is synced and no gift mail is sent.**
- ⚠️ **FOUR GATE DEFECTS FOUND BY RED-FIRST, NONE BY REVIEW**, and three were in
  this lane's own work: **(1)** my §F10e read *"the still-shut LINE does not
  name it"* and asked `real_exemptions()` — a fact about the **filters**. The
  panel could report all four routes as shut and it stayed green (M58 proves
  it); gate 91 gained `line_value()`, because `words()` concatenates every line
  and the roll-call names them all a few characters earlier. **Ask a line, not a
  blob.** **(2)** `fn_body('auth')` **PREFIX-MATCHES** and returned
  `authLoggedInUser()`'s body, so §K10i asserted `hash_equals` about the wrong
  function — it FAILED rather than passing, which was luck: that function has a
  nonce check and no `hash_equals` at all. Pin the open paren. **(3)** gate 91's
  `apply_filters` stub returned `$value` unchanged, which made the hook
  **unmeasurable** — a stub that always answers "nothing" cannot be told apart
  from a panel that finds nothing. **(4)** M18 and M33 both used
  `/sync-customer` as their widening mutation; after this lane that is a
  *legitimate* exemption spelled differently, so both had to be re-aimed at
  `/run-now` or they would have stopped modelling a wrong decision at all.
- **Owed:** nothing member-facing to look at — this is a server-to-server
  repair. On merge, the dev2 serve picks it up on the pull with no config
  coupling and no symlink change (the poller mu-plugin is already symlinked into
  the serving checkout). **On LIVE it changes nothing yet**, because live's
  `lgms_shared_secret` is still **ABSENT** (see #192's measurements) — the
  routes will answer `rest_forbidden` to everyone until Ian sets it. That is the
  same go-live blocker #192 already recorded, not a new one.

## State (8/22, #196 — a Patreon payer is offered SWITCH, never JOIN)

- **THE LAW THIS ADDS (Ian 8/22, verbatim):** *"Can you check and see if a user
  that has a patreon would have a menu for join in the profile chip? If so we
  need to change that to switch and give people a page with instructions for
  Patreon deactivation and reactivation through stripe."* This is the FRONT half
  of the one-payment-source law (8/19) finally telling the member the truth
  before the guard has to.
- **AND THE RULING THAT ARRIVED MID-BUILD (Ian 8/22, verbatim):** *"Why is there
  a superfluous join button for logged in users now."* **#170's
  `$join_pill_authed` is DEAD** — no signed-in viewer gets a Join pill at any
  width, in any flag state. The signed-in door is the **account-menu entry and
  nothing else**. This does not touch the ANONYMOUS pill, which is what #165 and
  #170 are actually about and which stays byte-identical to main.
- ⚠️ **THE DEFECT HAD A NAME ON DEV2, and the guard behind it was not armed.**
  Asked `PatreonStanding` about all six cohort members: **user 1953
  (mikelle.davlin) is a listed tester with an ACTIVE PAID Patreon pledge**
  (looth2, "Looth (Legacy Member)", next charge 2026-09-02) and the menu offered
  her Join. The other five, Ian included, have no Patreon link and correctly see
  Join. `lgms_double_pay_block` is **ABSENT on dev2 ⇒ OFF**, so #150's refusal —
  the thing that makes offering her checkout "merely" a bad experience rather
  than a second charge — **is not running there at all**. The menu was the only
  thing in the way.
- **PATREON STANDING RIDES THE EXISTING CAPABILITY CHANNEL. There is no new
  store and no new detection.** `patreon_paying` is computed in
  `InternalRestController::capabilities()` from `PatreonStanding::forUser()` —
  the one definition #150's three doors ask — and carried to the header exactly
  as `stripe_testgroup` is: poller → `Whoami::capabilitiesFor()`'s **named
  pass-through** → each app's ctx. The shared header renders on seven apps under
  seven unix users with **no database**, so a capability is the only honest way
  for it to ask. Cost: `wp_user_id` is the **PRIMARY KEY** of
  `lg_patreon_members`, so it is one PK read on a path that already makes a
  loopback HTTP call. Computed **unconditionally**, not only for the cohort — a
  capability whose `false` means two different things is the trap that cost a day
  on 8/16. Fails closed to `false` = Join = today's behaviour, because an unknown
  must never send a member with no Patreon to a page about cancelling one.
  ⚠️ **Gate 34b already cross-checks that pass-through**, so forgetting the far
  end is a RED and not a silent false — it happened on 8/16, to
  `stripe_testgroup`, to this menu, to **this same member**.
- **NO FLAG, and the issue's own escape clause says why.** Every byte lives
  inside `if ($stripe_tester)`, so the render differs only for members already
  inside the soft-launch narrowing. Proven, not argued: anon, a non-tester (cap
  true / false / **absent**) and a no-caps ctx are **byte-identical to
  origin/main in all three flag states**; the tester and admin renders differ by
  exactly the ruled-out pill plus the swapped entry, asserted line by line.
- ⚠️ **THE ACCOUNT MENU IS DESKTOP-ONLY, AND THAT IS WHY THE PWA SHEET ROW WAS
  RE-SOURCED RATHER THAN DELETED WITH THE PILL. Presence-is-not-reachability,
  FOURTH INSTANCE.** Measured three independent ways: on `/hub/`, on the front
  page **and** on the branch preview, at 390 and 640 the account chip is
  `display:none` and `#lg-account-menu` is `display:none`. Below 641 the menu is
  in the DOM the whole time and **is not a door at all**. `bottom-nav.js`'s
  account-sheet row existed precisely BECAUSE the pill was in the DOM (it reads
  the href with `getAttribute`, which works on a hidden element), so removing the
  pill alone would have left a signed-in tester **no join or switch door on a
  phone, on any surface**. The row now mirrors the **menu entry** through a hook
  class `.lg-chrome__menu-join` — the `.lg-chrome__menu-signin` shape that file
  already uses — and reads **both** the href and the LABEL from it, because since
  #196 the word itself varies. One door, in the account menu, drawn on a phone in
  the sheet that IS the account menu on a phone.
- **`/switch-billing/` — the instructions page.** Standalone,
  `manage-subscription.php`'s shape, no WP boot. Reads
  `lg_membership_patreon_standing()` and the existing Patreon snapshot, **both
  already in that app** and already kept honest against `PatreonStanding` by gate
  75 — no third definition of "already paying". It asks the standing AGAIN rather
  than trusting the menu's cached answer, because the page can be reached by a
  bookmark or a link and instructions for cancelling a pledge shown to somebody
  with no pledge are worse than no page; that viewer gets a different body.
  Router: `['switch-billing.php', 'testgroup', 'member']` — pre-launch mirrors
  who gets the menu entry, so the menu never offers a door the gate shuts.
- ⚠️ **THE SEAM IS REAL AND THE PAGE SAYS SO.** Ian's own 8/19 ruling blocks
  holding both rails, so cancel-then-rejoin necessarily meets at the lapse date.
  The page prints that date three times (from her real row), says what happens if
  they are late, and says nothing is deleted. **The copy is drafted and is Ian's
  to overrule.** One sentence was cut before shipping on his instruction: a
  promise of a reminder email that does not exist.
- ⚠️ **DECLARED CONFIG COUPLING — A PULL DOES NOT DELIVER IT.** A new slug means
  editing the location regex in all three `platform/nginx/strangler-membership*.conf`;
  there is **no catch-all**. And the RUNNING snippet is a **root-owned COPY, not
  a symlink**: `sudo cp platform/nginx/strangler-membership.conf
  /etc/nginx/snippets/ && sudo nginx -t && sudo systemctl reload nginx`. Without
  it Switch is wired perfectly and lands on a WordPress 404. Gate 93 §E asserts
  the three-file agreement unconditionally, reports the box gap and names the
  command, and proves the page routable through the lane preview meanwhile so
  "held" never means "unmeasured".
  ⚠️ Separately: that box copy is **already behind the repo** — it is missing the
  tracked `fastcgi_param LG_FOLLOWING_CADENCE 1`. Reported, not touched.
- **#196 BUILT** on `196-switch-menu`, gate **93** (128 assertions; red-first
  **23/23** — 21 mutations + 2 no-op controls). Gates **79 and 85 had legs
  asserting the signed-in pill EXISTS and were INVERTED, not deleted** (the gate
  86 §I9 discipline): 79 now holds #165's ratchet WIDENED back to every state
  (167 passed), 85's §A3 is restated against what exists (118 assertions).
  34b and 87 green.
- ⚠️ **FOUR GATE DEFECTS FOUND, THREE IN THIS LANE'S OWN GATE AND ONE
  PRE-EXISTING NEXT DOOR** — all by red-first, none by review:
  **(1)** gate 93's stray-line check `.strip()`ed its lines, so the 12-byte
  whitespace leak it exists to catch was invisible to it;
  **(2)** its bottom-nav leg ran a hand-written **transcription** of the rule and
  asserted the file merely CONTAINED `textContent`, which occurs elsewhere in a
  1,000-line file — replacing the real line left it green. It **lifts both
  deciding lines out of the file and executes them** now;
  **(3)** deleting the tab guard made that lift fail and the gate answered
  **CANNOT RUN** — a real defect reported as a missing environment, which
  run-all reads as no-verdict rather than red;
  **(4)** gate **79's red-first had no proof a mutation applied**, and the guard
  added here immediately found that its **caching-law leg — the most important in
  the file — had been silently inert since #180** moved its target, recording
  RED-OK for a mutation that changed nothing.
- ⚠️ **AND ONE IN THE SCREENSHOT HARNESS**: it clicked the account chip at 390
  and 640 — where the chip is `display:none` — and hit-tested the menu it had
  just opened, reporting REACHABLE. A synthetic click opens a hidden element
  quite happily. The trigger is hit-tested first now.
- **THE 821–904 DEAD BAND IS STILL OPEN AND STILL IAN'S, and this narrows it.**
  Measured as a signed-in tester with the menu open: at **821** MAIN overflows
  too — `/hub/` scrollWidth **905**, `/manage-subscription/` **938**, this branch
  **871**; at **900** main still overflows and this branch fits. Held in the shot
  run as a known main gap: reported, self-expiring, scoring again the moment main
  stops overflowing.
- **Owed:** Ian looks at the two preview URLs and rules on the page's words;
  keeper deploys the nginx snippet in the same window as the merge. The `#170`
  `.local.php` on dev2 needs no change.

## State (8/22, #192 — the panel that answers the five questions nobody could)

- **WHAT THIS ADDS:** a **Health** tab on the LG Member Sync dash (second, after
  Settings — the default tab is unchanged, so every bookmark and every #190
  redirect still lands where it did). Split out of #190, which landed everything
  else and explicitly did not reach this. Read-only; **no button on it changes
  anything**, per Ian's ruling that server-file settings are read-only with a
  copy button.
- ⚠️ **QUESTION ONE HAD NO DATA SOURCE AT ALL, and that is the finding, not the
  feature.** `WebhookController` verified, dispatched and returned; **nothing
  anywhere recorded that Stripe had ever reached us**, so "are webhooks
  arriving?" could only be answered on Stripe's own dashboard. Receipts now go
  to **`audit_log`** in `lg_membership` — which already names `'webhook'` in its
  own `actor_type` comment, has **no FK on `subject_id`**, indexes
  `(action, created_at)`, and held **ZERO rows on dev2 AND live** (measured
  8/21). Reusing it rather than adding a table is what makes the whole feature
  land on a plain `git pull`: **no migration, and no live DDL for Ian to run.**
- ⚠️ **THE TWO RECEIPT KINDS ARE NOT SYMMETRICAL.** A verified event is
  Stripe-signed, unforgeable, and recorded unconditionally. A **signature
  failure arrives at an UNAUTHENTICATED endpoint** — anyone can POST rubbish at
  `/billing/v1/webhook` — so those are throttled to **one row per five
  minutes**, bounded at 288/day. The signal is the valuable half: **a rising
  failure count beside a silent success count IS a mismatched webhook secret
  showing itself**, and it is the only place that disagreement is visible from
  outside Stripe.
- **IT READS THE BILLING APP'S `.env` OFF DISK, NOT OVER HTTP, and that is the
  design.** Asking the app about itself goes UNKNOWN in the one moment the panel
  matters, and the natural way to authenticate such a call is the shared secret
  — one of the things under test. A file read has neither problem.
- ⚠️ **A SECRET CANNOT REACH THE SCREEN EVEN BY MISTAKE, structurally.**
  `Health::envFacts()` reduces every secret-shaped key to **present / length /
  sha256** at the moment it parses the file; the sha is consumed by
  `hash_equals` and never returned; even the Stripe key prefix is collapsed to
  `test` / `live` / `unknown` before it crosses the boundary. So *"never print a
  secret"* is a property of the data, not a rule the renderer has to remember.
- ⚠️ **THE BOXES ARE NOT THE SAME SHAPE — MEASURED 8/21, and this supersedes any
  reading of "/srv/lg-stripe-billing is a symlink" as true everywhere.** On
  **dev2** it is a symlink into the serving checkout with `.env` at `-rw-rw-r--`
  (world-readable). On **LIVE it is a REAL DIRECTORY owned by `www-data`** with
  `.env` at mode **0640**; live's WordPress pool user `looth-dev` is in group
  `www-data` (verified), so it *should* read — but "should" is the word this
  panel exists to remove. The four states (missing / unreadable / empty / ok)
  are never conflated, because each needs a different fix.
- **THE PROBE USES RAW CURL WITH `CURLOPT_RESOLVE`**, this plugin's documented
  convention (`lg-patreon-stripe-poller/CLAUDE.md`: *"wp_remote_post does NOT
  work for these"*). The URL keeps the real hostname so SNI, the certificate and
  nginx's `server_name` all still match; only the TCP connection is pinned to
  127.0.0.1, so Cloudflare's bot-challenge 403 — which reads exactly like an
  outage — can never happen.
- ⚠️ **THE FIRST REAL RUN FOUND A FALSE ALARM IN THE PANEL ITSELF.** Pointed at
  dev2 it said *"a payment completed with no webhook recorded"* against **109
  customers and subscriptions that ALL predated the recorder**. A panel that
  cries wolf on its own deployment day is a panel nobody reads twice. *Since
  recording started* is now the **recorder file's own mtime on that box** — a
  fact that can be read rather than assumed, and one that can only ever make the
  check quieter, never invent a failure. History is counted separately and
  labelled; with no recorder found the verdict is **`unknown`**, not a guess.
- **#192 BUILT** on `192-dash-health`, gate **91** (95 assertions; red-first
  **63/63** — 60 mutations each reddening its own named assertion, 3 no-op
  controls proven inert). **Dash-only pieces carry no flag**, matching #190,
  #148 and #183; the webhook receipt is a swallowed INSERT on a path that
  already runs, and every receipt path catches `Throwable` so bookkeeping can
  never turn a delivered webhook into a three-day Stripe retry.

### What the panel says RIGHT NOW (measured 2026-08-21/22 through the real reader)

**LIVE — nothing is configured, and one line of that is urgent.**
- `lgms_shared_secret` **ABSENT**. Still. The billing app has one; WordPress
  does not, so every server-to-server call fails closed.
- `lgms_stripe_webhook_secret` ABSENT · `lgms_stripe_secret_key` ABSENT.
- `lgms_stripe_lifecycle_allowlist` **ABSENT — the tester cohort is EMPTY on
  live**, so with the audience at its `allowlist` default nobody at all can buy.
- `lgms_checkout_audience` absent ⇒ `allowlist` (fail-closed, correct).
  `lgms_stripe_pages_live` = 0.
- Catalogue **empty**: 0 products, 0 prices, 0 customers, 0 subscriptions, 0
  bridge rows, 0 `audit_log` rows.
- The billing app **is up** — `/billing/health` answers 200 over loopback — and
  reports `env=dev`. ✅ **Checked, not guessed: `APP_ENV` only labels that
  endpoint and gates nothing** (only `APP_DEBUG` does), so it is cosmetic. Do
  not spend an hour on it.

**dev2 — two real findings, plus one this lane discovered.**
- ⚠️ `STRIPE_WEBHOOK_SECRET` is set in the billing app (38 ch) and
  `lgms_stripe_webhook_secret` is **ABSENT in WordPress**. Inert today (the WP
  webhook ingest is behind `lgms_stripe_lifecycle`, off everywhere) and a real
  disagreement the moment it is not.
- ✅ **CLOSED BY #203 (8/22).** This read: *"BuddyBoss is still eating the route
  the billing app calls after every checkout — loopback with the real secret,
  `checkout-audience` → 200, `sync-customer` → 401
  `bb_rest_authorization_required`. Reported, not opened, per #181."* Re-measured
  the same way after #203, `sync-customer` answers its own 400 and refuses a
  wrong secret with `rest_forbidden`. `/send-gift-codes` and
  `/send-gift-recipient` went with it; only `/run-now` is still behind that
  wall, deliberately. See State (8/22, #203).
- ⚠️ **`APP_DEBUG=true` in dev2's billing app** — found by the panel on its
  first real run, and not previously written down anywhere. It displays errors
  to visitors. Harmless on dev2, a real problem if that shape reaches live.
- Healthy: shared secret **AGREES** (same sha256, both 64 ch); both keys
  `sk_test_`; sync URL host matches (the dead-host bug is fixed); catalogue
  resolves cleanly (6 active membership products, looth2 + looth3, 0 unmapped);
  audience `allowlist` with 6 in the cohort and the tester page open.

- **Owed:** Ian looks at the tab on dev2 after the merge. The two live gaps that
  block go-live are **`lgms_shared_secret`** and the **empty cohort** — both are
  live writes and therefore his. Not reached by this lane: a Stripe-side check
  (asking Stripe whether a webhook endpoint is even registered for our URL),
  which would need the WP secret key that live does not have yet.

## State (8/21, #190 — one membership dash, and the tester link stops living in a chat message)

- **THE LAW THIS ADDS (Ian 8/21):** *"Can we round up all of the membership
  patreon and strip and put them in one membership dash ?"*, placed the same
  day — *"I want it in main dash, not in settings or tool"* — and scoped the
  same evening — *"Can we put the token link in there with the whitlist ?"*
  He approved the built tab from screenshots: **"That works awesome."**
- ⚠️ **#190'S OWN ISSUE TEXT MIS-MEASURED THE THING IT WAS ABOUT, so do not
  requote it.** It records `LG Member Sync` as already using `add_menu_page`
  and therefore already top-level. Measured on main 8/21, `Admin.php:35` was
  **`add_options_page`** — the dash lived under **Settings**, the one place the
  placement ruling excludes; only **Affiliates** was top-level. The
  corroboration was a link dead the whole time:
  `platform/mu-plugins/lg-admin-tools.php:67` had always pointed at
  `admin.php?page=lg-member-sync`, the top-level URL the page did not have.
  Both are now true instead of assumed, and gate 90 §G asserts them.
- ⚠️ **THE TESTER LINK COULD NOT BE READ BACK, AND THAT WAS THE REAL HOLE.**
  #180 stores `sha256(token)` on purpose — a store that can be read should not
  be a store that can be used — so the WORKING URL existed **only in a chat
  message keeper pasted**. A hash cannot be turned back into a link. The
  Testers tab is where it lives now: shown in full with a Copy button, Rotate,
  and Turn off.
- **THE STORE IS SPLIT IN TWO WITH ONE WRITER, and neither home could take both
  jobs — both constraints measured, not reasoned:** `platform/config/` in the
  serving checkout is `ubuntu:ubuntu 0755` while WordPress runs as FPM pool
  **looth-dev**, so the dash **cannot** write `tester-unlock.local.php`; and the
  hash **cannot** become a `wp_option`, because `lg-shared/tester-unlock.php` is
  required by `site-header.php`, which renders on **seven apps under seven unix
  users and has no database at all** (they share no group either — the file must
  be plain world-readable). So: **raw token in `wp_options`
  (`lgms_tester_unlock_token`)** for the dash to show, **`sha256`+`enabled` in
  `/srv/lg-shared-state/tester-unlock.json`** for the seven apps to read.
  **JSON not PHP** — a web-writable file seven apps `include` is RCE across all
  seven — and **outside the serving checkout**, which only ever pulls.
- ⚠️ **STORING THE RAW TOKEN IS A DELIBERATE REVERSAL OF #180'S PROPERTY**, at
  Ian's request, because the link's whole purpose is to be sent. It is in
  `wp_options` and nowhere else, never in the shared file (gate 90 §A15 asserts
  **no hex run of any length but 64** reaches it), never logged, and **never in
  a redirect** — the neighbouring invite panel *does* put its raw token in a
  query arg, so it lands in the admin URL, browser history and every onward
  Referer. **Observed, deliberately not changed** (different token, not this
  issue). The trust level was already set: `wp_options` here holds
  `lgms_db_pass` and `lgms_stripe_secret_key`.
- ⚠️ **TWO ORDERING DECISIONS ARE LOAD-BEARING AND LOOK LIKE DETAIL.** The
  operator store is read **AFTER** the `.local.php`, and Turn-it-off **writes
  `enabled => false` rather than deleting the file**. Both for one reason: an
  absent store applies NOTHING, and "applies nothing" on a box carrying an armed
  `tester-unlock.local.php` — **which dev2 does today** — means STILL ARMED. Get
  either wrong and the button lies. Gate 90 §B7/§B7b assert it against a real
  hand-placed box file, §B7b with the valid hash still in place so only the
  `enabled` half can refuse.
- **THE PANEL SHOWS WHAT IS TRUE AND SAYS WHY WHEN IT CANNOT SHOW SOMETHING.**
  Four states: `dash` (armed by this dash, token agrees — the link is shown),
  **`foreign`** (armed by something else — **no link is shown at all**, because
  the one it holds is dead, and it says so), `stale`, `off`. **dev2 renders
  `foreign` today.** A panel that always printed `TesterUnlock::url()` would
  show a link that looks completely live and does not work; gate 90 §D6 asserts
  the absence, with a fixture that **holds a token**, since a fixture with none
  leaves nothing for a broken panel to leak.
- **The dash is now top-level with its own icon, and BOTH old addresses still
  work.** `options-general.php?page=lg-member-sync` (this page's home for its
  whole life, and the URL written throughout this file and the handoffs) and
  `admin.php?page=lg-affiliates` both **301 to the new location**, carrying the
  tab / the row being edited / the notice. A moved page that strands its old
  address is a worse outcome than one that never moved.
- ⚠️ **THREE THINGS MOVE TOGETHER IN A MENU PROMOTION and one fails SILENTLY:**
  the registration, `PARENT_FILE` (one constant behind seven redirect targets),
  and the enqueue hook prefix — **`settings_page_` never fires again once the
  page is top-level, and the Welcome Email tab's media uploader simply stops
  loading with no error anywhere.** Nothing but a person clicking it would have
  caught that; gate 90 §G3 does now.
- **Affiliates is folded in** — it was a second top-level menu in the same file.
  **Seven links pointed at `page=lg-affiliates`**; four internal ones now build
  the tab URL through one helper, `lg-admin-tools` is updated, and the redirect
  covers the rest — **including two member-facing ones deliberately left alone**
  (`membership-pages/web/affiliate-earnings.php:119` and
  `lg-patreon-stripe-poller/src/Wp/Shortcodes.php:6082`), so this diff never
  reached into member-facing files.
- **The name is unchanged on purpose.** "LG Member Sync" is what this file,
  every handoff and both operators call it; renaming the sidebar entry would
  invalidate that vocabulary for a cosmetic gain. **Renaming is Ian's call.**
- **#190 BUILT** on `190-membership-dash`, gate **90** (93 assertions; red-first
  **35/35** — 33 mutations each reddening its own named assertion, 2 no-op
  controls proven inert). **Dash-only pieces carry no flag**, matching #148 and
  #183; the one member-facing change is the reader's third source, which is a
  proven no-op while the store is absent.
- ⚠️ **THE GATE'S OWN ASSERTIONS FAILED IN THREE INSTRUCTIVE WAYS**, all found
  by red-first rather than by review: **(1)** two of them matched **their own
  explanatory prose** (§F4 found the docblock explaining the source order, §F5
  the comment saying the panel avoids `StripeLifecycle`) — every source check
  now runs through PHP's **tokenizer**, not a regex; **(2)** §E2/§E3 read a
  **fixed-width window** that ran past one handler into its neighbour, so
  deleting rotate's `check_admin_referer` stayed green on the *neighbour's*
  guard — the body is brace-matched now; **(3)** §G asserted placement **only
  when the page already looked top-level** and reported otherwise, so a revert
  flipped it into report mode and said nothing. **A gate that stops watching the
  moment the thing it watches breaks is not a gate.**
- **Owed / not reached:** the **health panel** (webhook-secret agreement between
  `lgms_stripe_webhook_secret` and the billing app's env, the same for
  `lgms_shared_secret`, test-vs-live mode, does the catalogue resolve to tiers,
  when did a webhook last arrive) — the part of #190 with the most operational
  value, and untouched. Also untouched: `UserLifecycleAdmin`, `MembershipGuide`
  and `lg-patreon-onboard`, which are still their own `add_options_page` /
  hidden-submenu screens. Nobody needs to place a `.local.php` for any of this;
  `/srv/lg-shared-state` exists on dev2 (keeper, 8/21) and **live has no such
  directory yet**, where the tab will correctly report that it cannot store a
  link and refuse rather than half-work.

## State (8/21, #183 — the comp timer runs again, through the single writer)

- **THE LAW THIS ADDS (Ian 8/21):** *"comp timers need to work."* And the ruling
  that outranks it in the same breath: **the two already-overdue accounts are
  LEFT ALONE** — no demotion, no extension — until he decides case by case.
- ⚠️ **THE OLD PLUGIN IS GONE, NOT DEACTIVATED, AND MUST NOT BE RESURRECTED.**
  `lg-looth4-expiry 1.0.0` belonged to the pre-cutover platform. Measured both
  sides 8/21 — keeper on live's filesystem, this lane on the database: no file
  under wp-content, absent from `active_plugins`, `recently_activated` is
  `a:0:{}`, and the 13,182-byte `cron` option names no looth4 or expiry event.
  Reinstating it would make it a **second writer of `wp_capabilities`**, and the
  Arbiter's own looth4 comment is the bill for that: the old timer *"stripped
  looth4 and left looth1 behind (and a later Patreon sub then added looth3 on
  top) — the root of the double-role bug"*.
- ⚠️ **NOTHING "STILL SETS" THE META — the July dates are VALUES, not write
  times, and this is worth not re-deriving.** `umeta_id` is monotonic with
  registration on live. Row **219462** (user 1829) sits between that user's own
  `wp_capabilities` row (registered `2026-04-21 21:11`) and user 1830's
  (`2026-04-23 03:52`), so it was written **21/22 April**. Row **221169** (user
  1865) sits between `221158` (reg `2026-05-10 15:26`) and `221221` (user 1866,
  `2026-05-12 03:19`) — **10–12 May**. Both are the last row of that account's
  setup burst: the expiry was set **at grant time**, by the plugin that was
  still installed then (LIVE-INVENTORY, committed 2026-06-18, records it
  active).
- ⚠️ **SO UNTIL #183 AN ADMIN COULD NOT SET A COMP END-DATE AT ALL.** No ACF
  field (there is **no `_looth4_expires_at` companion row**, which is the tell),
  no code-snippet, no ACF field group, no wp_option names the key; the only two
  occurrences on live are the two data rows. Enforcement without a setter is
  half a feature and **Ian grants comps by hand**, so #183 ships the **Comp
  Timers tab** (LG Member Sync) alongside it. It writes the meta and
  **never a role**.
- **THE TIMEZONE IS UTC, AND THE OLD READER WAS FOUR HOURS LATE.** Two
  independent proofs: the old plugin's own source
  (`cutover/batch-output/BATCH-04-results.md:158` — *"stored as Y-m-d H:i:s
  UTC"*), and the data, which agrees without it — user 1829 registered
  `2026-04-21 21:11:27` UTC with expiry `2026-07-28 21:11:00`, the **same
  minute-of-day**; user 1865 registered `15:26:04`, expiry `15:25:00`. Two for
  two. **Both boxes run `timezone_string = America/New_York`**, so
  `CompStanding`'s site-zone read (left deliberately unsettled by #181) placed
  every expiry four hours late. Harmless while nothing enforced; a real defect
  the moment something demotes. Gate 89 asserts it against a **hostile process
  timezone** set to America/New_York.
- **WHAT AN EXPIRED COMP BECOMES: whatever their sources already say.** Not a
  flat looth1. The role comes off and `Arbiter::sync` re-arbitrates normally, so
  a comp who also pays on Patreon lands on **looth3** and a Stripe member on
  their own tier. Only when there is no paying opinion anywhere does the
  **looth1 floor** apply — never no tier at all, which is a broken account
  rather than a lapsed comp. That floor is also what the old plugin documented
  (*"Expired users are demoted to looth1"*).
- ⚠️ **TWO PLACEMENT DETAILS IN `Arbiter::sync` ARE LOAD-BEARING**, both found
  by building it and both gated. **(1)** `$oldTier` is captured as `looth4`
  *before* the role comes off, or `looth_tier_changed` fires with the wrong
  `from` and profile-app purges against a tier the member never held. **(2)**
  the stripe coexistence guard gained `! $compExpired`: a comp whose role was
  just removed holds no tier for an instant, so `empty(intersect looth1)` is
  TRUE for them and that guard would return early leaving them with **no looth
  role whatsoever**. The genuinely ambiguous version of that case —
  `payment_source=stripe` with **no** source row — is **HELD above**, before the
  role comes off, and says so in its reason: a payer is never flattened.
- **THE FENCE IS A DATE, NOT A LIST.** `platform/config/comp-expiry.php`:
  `enabled` (default **false**) and `effective_from` (default **empty**). Only a
  timer that ran out **at or after** the cutover is enforced. Chosen over a
  skip-list because it cannot be defeated by a mistyped id and it protects every
  already-overdue account on every box, including any nobody enumerated — the
  same shape as the Arbiter's own `registeredAfterCutover`. Both fences fail
  closed, so **`enabled => true` with an empty cutover is a real
  detect-and-report mode** with no third knob. Held accounts are surfaced in the
  tab and in every sweep's log, never silently reconciled.
- ⚠️ **IT MERGES OFF, UNLIKE `lgms_checkout_audience`.** #181's enforcing
  default was right because a fence nobody walks into is never exercised. This
  one **takes access away from real people** — 14 comp holders on live, staff
  among them — so the merge itself must move nobody.
- **WHY A SWEEP AND NOT JUST THE ARBITER:** `Arbiter::sync` only runs for
  members something has an opinion about, and a pure comp holder has no payment
  source at all, so nothing would ever visit them. Same shape as the defect
  `RetractionSweep` exists for. Pass 4 of the 5-minute tick, in its own
  try/catch.
- **#183 BUILT** on `183-comp-expiry`, gate **89** (96 assertions; red-first
  **32/32** — 30 mutations + 2 no-op controls). Gate **86 §I9 was INVERTED, not
  deleted**: it used to record this gap, and now asserts the OFF state with a
  liveness partner (§I9b armed ⇒ demotes, §I9c ⇒ lands on a real tier, §I9d ⇒
  pre-cutover timers held even when armed). Gate 86 also gained `CompExpiry` on
  its require list — without it the real Arbiter fatals on every looth4 case.
- **Owed:** Ian looks at the Comp Timers tab on dev2, then keeper places
  `platform/config/comp-expiry.local.php` (`php -l` it first) to arm it — start
  with `enabled => true` and an **empty** cutover so it reports without moving
  anybody. Live writes are Ian's.

## State (8/21, #181 — the cohort becomes real in the CHECKOUT path)

- **THE LAW THIS ADDS (Ian 8/21, decision box):** *"Fix before go-live."* #180
  named the gap; this closes it. **`lgms_checkout_audience`** is now the one
  answer to *"may this person buy, and may they be provisioned"* —
  `off` / `allowlist` / `on`, #170's audience shape, reading the ONE cohort list
  through `StripeLifecycle::inCohort()`.
- ⚠️ **IT DEFAULTS TO `allowlist` — ENFORCING — AND THAT IS THE ONLY FLAG ON
  THIS RAIL THAT DOES** (keeper ruling (a), 8/21). The reason is worth keeping:
  the enforcing state must be the state the boxes actually run, or it is never
  exercised before the night it has to work. Everything else here defaults dark
  so a merge lands harmlessly; this one would have shipped a fence nobody had
  ever walked into.
- **THE HOLE, REPRODUCED — do not re-derive it.** On dev2 as served, anon and
  cookieless, with a real price id from the **public** `/billing/v1/products`
  list: `POST /billing/v1/checkout` → **HTTP 200 + a live Stripe
  `clientSecret`**. After: **403**, same request, same price id. A cohort
  member still gets a real `clientSecret`. Gifts are fenced too.
- **TWO HALVES, AND ONLY ONE CAN BE ROUTED AROUND.** The MINT half refuses
  early and honestly at all three doors. The PROVISION half lives in
  `UserProvisioner::findOrProvision` and is the backstop: it reads the option
  in-process with **no network**, so it fails CLOSED where the Slim probe
  cannot, and it is what stops a session minted *before* the cohort changed —
  no user, no bridge, no grant.
- ⚠️ **THE FENCE SITS ONE LINE BELOW THE EXISTING-BRIDGE EARLY RETURN, and the
  placement is the design.** Below it, an already-bridged member is untouched in
  every state, so their sweeps keep landing — **grants AND retractions**. Above
  it, the fence would freeze real members the moment the cohort narrowed, and
  would do it silently.
- ⚠️ **`Sync::customer`'s EXISTING cohort fence was never the answer, and this
  corrects a natural misreading of it.** It is real, but it sits **after**
  `findOrProvision` and behind a **different** flag (`lgms_stripe_lifecycle`,
  off on every box). So it has only ever withheld the ROLE: the account, the
  bridge row, the welcome mail and `looth_tier_changed` all fired for a stranger
  who paid.
- ⚠️ **NO ADMIN BYPASS** (keeper ruling (b)). The header's
  `$caps['stripe_testgroup']` is `manage_options || inCohort()` — right for a
  *button*, wrong for a *fence*: an administrator who sails through cannot see
  it fail, and Ian is an administrator. He is in the dev2 cohort **by list**
  (id 1, added by keeper 8/21), not by privilege. dev2's cohort is now **6**:
  `[854, 1887, 1938, 1953, 2047, 1]`.
- ⚠️ **UNKNOWN REFUSES, with 503 and a DIFFERENT SENTENCE from the 403.** "We
  could not verify" and "not open for sale yet" need opposite fixes — go and
  find out why the loopback is failing, versus add them to the list. One shared
  sentence sends whoever is debugging down the wrong one.
- ⚠️ **THE SHARED-SECRET REST CHANNEL WAS DEAD, AND THIS IS THE FINDING THAT
  OUTLIVES THIS ISSUE.** Measured on dev2 8/21, from 127.0.0.1, **with the
  correct secret**: every server-to-server route in `lg-member-sync/v1` answers
  **401 `bb_rest_authorization_required`**, because BuddyBoss's
  `bb_restricate_rest_api` pre-empts the REST stack before any route's own
  `permission_callback` whenever `bb-enable-private-rest-apis` is `1` — **and it
  is re-armed by every DB reload**. Consequences nobody had noticed: **#150's
  double-pay probe has been answering UNKNOWN on every call**, and the Slim
  app's post-checkout sync ping is dead (the five-minute `Sync::all()` sweep has
  been covering for it). #181 exempts **exactly one route** through BuddyBoss's
  own documented `bb_exclude_endpoints_from_restriction` hook; the other three
  are reported, not opened.
- **looth4 IS RESPECTED, AND THE RESPECT IS THE ARBITER'S, NOT MINE.** Ian 8/21:
  *"looth4 is the everything bypass the stripe side of membeship needs to
  respect."* `Arbiter::sync` has an unconditional looth4 early-return and is the
  **only** writer of `wp_capabilities`; `RetractionSweep` is detection-only and
  never runs the Arbiter. Gate 86 §I proves it with the **real** Arbiter, paired
  with a liveness leg showing the same sweep DOES demote a non-comp member.
  #181's fence never calls `remove_role`/`add_role` at all.
- **UNEXPIRED looth4, not looth4** (keeper's sharpening, 8/21).
  **`LGMS\Membership\CompStanding`** is that predicate — `holdsComp`,
  `expiresAt`, `isActiveComp`, `isExpiredComp`, `describe` — read-only, enforcing
  nothing. ✅ **#183 DID INHERIT IT** rather than write a second one:
  `CompExpiry` holds the policy and the sweep, this stayed the read-only predicate.
  Today it is used to make the refusal notice name a comp member instead of
  logging them as a stranger. ✅ **ITS TIMEZONE QUESTION WAS SETTLED BY
  #183: the values are UTC**, on two independent proofs, and reading them in the
  site zone was four hours late — see State (8/21, #183) above. The
  "deliberately unsettled" note is superseded.
- ✅ **CLOSED BY #183 — an expired comp is no longer protected once enforcement
  is armed.** This line recorded the gap; gate 86 §I9 was INVERTED rather than
  deleted to match. It stays true in the SHIPPED state, because the flag merges
  OFF — and the two overdue accounts (1829, 1865) are still LEFT ALONE, now by
  the enforcement cutover rather than by nothing at all. See State (8/21, #183).
- ⚠️ **THE HONEST EDGE, boarded for Ian:** a comp member who somehow reaches
  Stripe checkout while outside the cohort **is refused like anyone else**. They
  lose nothing — no demotion, no role write, no opinion (§I6, §I10) — and the
  operator notice names their comp standing. Not silently changed; it is the
  question in the handoff.
- **DEPLOY IS ONE PULL, verified not assumed:** `/srv/lg-stripe-billing` and the
  poller mu-plugin are BOTH symlinks into the serving checkout, so both halves
  land atomically. The probe URL DERIVES from `LGMS_SYNC_URL`, so **no box needs
  an env edit**.
- **FOUR NEIGHBOURS WERE REDDENED BY THE ENFORCING DEFAULT AND ALL FOUR ARE
  FIXED IN THE SAME COMMIT** — gates 75 and 76, plus `test-identity-gate.php`
  and `test-checkout-session-metadata.php` (two of them **fataled at exit 255
  with no FAIL line**; that file's own comment already warned this had happened
  twice before — mine was the third). Each now loads the real `CheckoutAudience`
  and pins it `off` **at every `$GLOBALS['OPTS']` reset**, never once at module
  scope: a pin set once is silently gone by the first assertion.
- Gate **86** (156 assertions; red-first **23/23** + 2 no-op controls).
- **Owed:** Ian flips dev2 to the state he wants exercised (it is already
  `allowlist` by default — no action needed to enforce; `wp option update
  lgms_checkout_audience on` is the GA switch). #183 (comp expiry) is queued.

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
  box **and** `lgms_stripe_pages_live` in WP admin (LG Member Sync),
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
  read-only "Dual Payers" tab on LG Member Sync for Ian, and a
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
  DECISION (dash: LG Member Sync). Per-tier control is BUILT (#148):
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

## looth4 is the everything-bypass, and Stripe must respect it (Ian, 2026-08-21)
Verbatim: *"looth4 is the everything bypass the stripe side of membeship needs
to respect what we have built there."*

looth4 = the comp/staff pass carried over from the cutover (15 holders on live,
managed by `lg-looth4-expiry`). It is an EXISTING GRANTED MEMBERSHIP, not an
administrative privilege — which is why it outranks the Stripe machinery while
`manage_options` deliberately does NOT (see the no-admin-bypass ruling on #181:
an admin passes the money door and cannot see the failure).

Already true on the reading side — verified 8/21: `lg-viewer-tier.php` maps
`looth4 => pro` ("poller bypass — same access as looth3") and
`lg-membership-chrome.php` carries the same pairing. So content gating, and the
#179 loothprint paywall toggle that rides on the same tier resolution, honour it
already.

**The standing requirement on every Stripe-side change:** a looth4 holder is
never told to pay, never demoted, never loses access for having no Stripe
subscription, and no sweep or reconcile may strip the role. `EntitlementManager
::revokeForSubscription` revokes BY SOURCE (subscription id) so it should not
touch what it never granted — verify, do not assume. The live-harm case is 15
real people with staff among them.

**Do not confuse the two axes.** The tester allowlist (`stripe_testgroup` =
`manage_options || inCohort`) decides WHO MAY TRANSACT during the soft launch.
The looth roles decide WHAT A MEMBER SEES afterwards. Ian's 8/21 go-live ruling
on the first axis: testers **really buy** — real checkout, real cards — because
comping them leaves the money path, the receipts and the grant untested.

## Live's billing app was NEVER migrated into the monorepo (found 8/22)
`/srv/lg-stripe-billing` on live tracks the STANDALONE repo
(`lg-stripe-billing.git`), last commit 2026-05-10 — while dev2's is a symlink
into the serving checkout. So `lg-deploy` deploys NONE of the billing app on
live, and everything Slim-side since May (checkout-audience fence #181,
double-pay guard's app half #150, webhook receipts #192, catalogue importer)
exists only on dev2. Cutover is its own go-live blocker issue; until it lands,
any "deployed to live" claim about billing-side code is false by construction.
Found by lane 194's plan measurement; confirmed via live-ro.
**#197 measured it in full and built the cutover — see
`docs/LIVE-BILLING-CUTOVER-2026-08-22.md` and
`tools/infra/live-billing-cutover.sh`, and the section at the end of this file.**

## The Patreon-linked email is shown on every double-pay/switch surface (Ian 8/22)
Verbatim: *"its critical to add to any double pay or switch surface the email
associated with their patreon account and that that is the email to use when
adjusting thier membership."* Identity linkage is by email; rejoining under a
different address is the #149 lost-membership class. Keeper's privacy rail:
the Patreon email is revealed ONLY to the authenticated member on their own
signed-in surface — never in an anonymous API refusal. Applied by #193 (guard
surfaces) and #196 (switch page/menu).

## The billing cutover, measured (#197, 2026-08-22)

Read-only on live via `live-ro`. The swap itself is
`docs/LIVE-BILLING-CUTOVER-2026-08-22.md`; these are the facts behind it.

- **The old app IS serving today.** `/billing/health`, `/billing/v1/products` and
  `/billing/v1/config` all answer **200 JSON** on loopback. The `/billing/` alias
  bug from `LIVE-BILLING-PREFLIGHT-2026-08-16.md` is **fixed** — the vhost line
  ends in a slash. "Stale" described the CODE, never the availability.
- **Live's standalone repo carried an UNCOMMITTED edit** —
  `CheckoutController.php`, retiring personal one-time memberships with a 400.
  Live behaviour existing in exactly one place, which a naive `mv` destroys
  silently. **The monorepo carries the same block**, so nothing is lost — but the
  general lesson stands: *diff the working tree, not the last commit, before
  retiring a box-local checkout.*
- **`vendor/` is byte-identical** old vs monorepo (890 files, same manifest hash)
  and `composer.lock` matches, so the cutover **copies** it. No `composer install`
  on live, no packagist egress in a change window.
- **`routes.php` is byte-identical** — the swap changes no route surface.
- Only `.env` and `.env.example` exist solely in the old tree. Everything else the
  monorepo either matches or is ahead on.

### ⚠️ The swap ARMS #181, and today that means 503, not 403

The new tree runs `CheckoutAudienceGuard` as the **first** gate on
`POST /v1/checkout`; the old tree has no gate. Enumerating every `lgms%` option on
live (not querying a guessed list): **only `lgms_stripe_pages_live = 0` is set** —
`lgms_shared_secret` is **absent**. So the probe is refused by WordPress, the
probe maps non-200 to `null`, and `CheckoutAudienceGuard` maps `null` to **503
STATUS_UNKNOWN**.

Post-swap live checkout therefore refuses **everyone** with the UNKNOWN sentence.
Fail-safe-closed, and not member-visible (`pages_live = 0` keeps the purchase
pages administrator-only). Reaching the 403 state needs `lgms_shared_secret` set
WP-side to match `.env`, then the tester list.

**Assert the SENTENCE, never "it refused."** 503-unknown and 403-tester are
different findings that both look like "checkout refuses ✓".

### The two 401s are not the same 401

Anonymous, over loopback, in the `lg-member-sync/v1` namespace:

| Route | Code | Means |
|---|---|---|
| `sync-customer` | `bb_rest_authorization_required` | BuddyBoss's blanket wall — ⚠️ **fixed by #203**; post-merge this route answers `rest_forbidden` to an anonymous caller, like the row below |
| `checkout-audience` | `rest_forbidden` | **past the wall**, refused by its own token check — healthy |

`checkout-audience`, `patreon-standing` and `auth`/`gift-auth` each register a
narrow exemption; **`sync-customer` did not until #203 gave it one** (with
`/send-gift-codes` and `/send-gift-recipient`; only `/run-now` is still without).
Live's public-content list
still holds only `looth/v1`, `looth-internal/v1` and a wc URL. Reading one 401 as
proof of anything is the trap — read the CODE.
## What a Loothprint's tier actually gates (Ian 2026-08-22, #199)

Verbatim, looking at a member's live Loothprint (post 72801, *The Cleanup Stik*)
while logged OUT: *"The gating is off too. We only need to gate the file download
and it shouldn't look like the video gate. I think there is a block for that
already available."*

**The ruling, in three parts:** on a Loothprint the tier gates the **file
download only** — gallery, write-up and video are public to anon; the gated
download wears the **existing `download` block's** face and copy, never the video
gate's; and **one** member-facing sentence appears **once**.

Behind `LG_V2_LOOTHPRINT_GATING` / `lg-layout-v2/config/loothprint-gating.php`,
defaulted OFF. Gate 96.

### Why this is a membership fact and not a layout one

The tier term on a print is written by **#179's paywall toggle on the compose
form** (`lg_fc_paywall_target()`, `platform/mu-plugins/lg-frontend-compose.php`),
so the toggle's meaning is now precisely *"the DOWNLOAD is behind the paywall"* —
which is what Ian asked for originally. Members posting prints is the flow the
real-money test exercises, so what a non-member is shown on a print is a
membership surface, not a styling one.

⚠️ **`looth1` IS NOT A PAYING TIER, and the paywall looks broken if you forget
it.** `TierResolver::ROLE_TIERS` maps `looth1 => 'public'`, `looth2 =>
'looth-lite'`, `looth3`/`looth4` => `'looth-pro'`. Signing in as a looth1 member
and finding the download still gated is the feature working. Measured on dev2:
607 looth3, 423 looth2, 405+60 looth1. This pairs with #186's finding that
`member_cookies()` in `loothprint-paywall-gate.py` actually mints an
**administrator** — between the two, "test it as a member" has now been wrong in
both directions on the same surface.

### The two ways a gate can be wrong, and only one of them hides content

Ian's screenshot was **two stacked, identical "MEMBERS-ONLY VIDEO" panels**.
Nothing was leaking; both faults were on the *presentation* side of the gate, and
neither raised an error:

1. `Renderer::AUTO_GATE_TYPES` auto-gated `embed` from the post tier — right for
   a video post, wrong for a print, and the constant was global. Now post-type
   aware: the print CPTs auto-gate `download`/`file`/`attachment` only.
2. `GateCta::variantFor()` is a **dispatch table with a default**. `callout` is in
   neither of its lists, so a gated `callout variant=files` — the shape a
   synthesized print used for its ZIP — fell through to the **embed** default and
   drew the video card over a download.

**The lesson for any future gate work here:** a content gate has two halves, what
is hidden and what the card says, and only the first has ever been tested. Gate
94 asserts the RENDERED CARD and never the block type, because reading the layout
back and checking it says `download` is true of the broken build. And it asserts
the **number** of panels, because "there is a download card" was true of the
broken page too — it had a download card AND a video card.

⚠️ **A gated block that is not a "deliverable" is a second panel.** The
synthesizer also gated the OnShape CAD link, so a print with one showed three
identical cards, not two — 72801 showed two only because its CAD field was empty.
Read literally, a link to a CAD service is not the file, so ON un-gates it.
**7 loothprints on dev2 carry a CAD link.** Reversible in one line and the config
docblock names it; flagged to Ian rather than decided quietly.

### Verified, end to end, before any of it reached anybody

Over real https on the real serve path, both themes, 1280 and 390:

| viewer | gate panels | download | video | gallery tiles |
|---|---|---|---|---|
| anon, before (main) | 2 × *Members-only video* | — | hidden | 2 + **1 ghost** |
| anon, after | 1 × *Members-only download — The Cleanup Stik — ZIP, 297 B* | no href anywhere | **plays** | 2 + 0 |
| looth1 (signed in, non-payer) | 1 × download card | — | plays | 2 + 0 |
| looth2 (= looth-lite, paying) | **0** | real ZIP href, ZIP, 297 B | plays | 2 + 0 |

The anon bytes contain the file's **name, type and size and never its URL** —
naming the file is the teaser, handing over its address is the leak.

### ⚠️ The trap that would have deleted the download instead of gating it

The pre-existing `download-block` flag emits a download block with **no
`file_id`**, on the reasoning that the block resolves the post's file live at
render. That reasoning holds for the WP renderer and **not** for the page members
read: `/loothprint/` is served by the standalone renderer from a materialized
blob, whose media map is built from the `file_id`s found in the layout, and the
**vendored copy** of `blocks/download/render.php` had no live-resolve fallback at
all. Flipping that flag as it stood would have resolved no URL and returned
nothing — the download would have vanished from the page, silently, on the
member-facing path only. The synthesizer now bakes `file_id`; details in PAGE.md.

**General rule for any gated deliverable on this box:** "it resolves live at
render" is a claim about the WP renderer. The standalone path has no WP — it has
whatever the materializer baked.

---

## State (8/22, #201 — the shared secret gets a status line and a Refresh button)

- **THE LAW THIS ADDS (Ian 8/22, verbatim, reshaping his own issue):** *"Should
  just be a refresh button or something with a status check."* That superseded
  the paste-in field of the first draft, and keeper then applied it to the whole
  surface it describes.
- **WHAT IT ANSWERS.** `lgms_shared_secret` authenticates the billing app's
  server-to-server calls into WordPress. It is **ABSENT ON LIVE**, and #181's
  checkout guard is **fail-open by design** — a route that cannot answer produces
  UNKNOWN, and UNKNOWN waves every checkout through. So the guard reads as ARMED
  on the dash and refuses nobody, and **nothing on any screen said so**. A
  **Shared secret** section now sits FIRST on the Health tab: WordPress
  present/absent + length, billing app present/absent + length (or *which* of the
  four unreadable states), MATCH / DIFFER / cannot-compare, and a *Checked at*
  UTC stamp. **Refresh** re-reads both halves without a page load.
- **IT IS REPORTED ONCE, so it came OUT of `Health::checkSecrets()`.** Leaving
  it in both places puts one fact on one screen in two presentations — the #199
  two-stacked-panels shape, which this platform has already paid for. The
  comparison is now **`Health::secretPair()`**, one definition, used by the new
  section and by the webhook-secret card alike. **Gate 91 §B was RE-POINTED, not
  deleted** (the gate 86 §I9 discipline) and **gained a ratchet**: §B6 asserts
  the shared secret is NOT on that card, so re-adding it is a RED rather than a
  silent duplicate.
- ⚠️ **THE SETTINGS TAB'S SHARED-SECRET FIELD IS RETIRED, and the issue's premise
  that "setting stays a command-line act" was NOT true when it was written.**
  Measured on main: `Admin.php:1613` held a working setter whose `value=`
  attribute **printed the live secret into that page's HTML source** —
  `type="password"` hides it from the eye, not from View Source. Keeper ruled it
  out (8/22) on Ian's own shape for the surface plus the structural argument the
  issue itself makes: the billing app's half is a server file the web user cannot
  write, so a dash setter can only ever move **one** half — which is precisely
  how a pair comes to DIFFER with nobody meaning it. `lgms_db_pass` and
  `lgms_stripe_secret_key` carry the same echo and are **REPORTED, NOT TOUCHED**;
  keeper is filing that class as its own issue with #197's plaintext-`db_pass`
  finding.
- ⚠️ **RETIRING THE FIELD WITHOUT RETIRING THE REGISTRATION WOULD HAVE BLANKED
  THE SECRET ON EVERY SAVE.** Verified in the running WordPress, not recalled:
  `wp-admin/options.php:336-345` walks the registered options of the submitted
  group and calls **`update_option( $option, null )` for every one absent from
  POST**. A registered setting with no field is not "left alone" — it is emptied
  by anyone pressing Save, silently, and server-to-server auth fails closed from
  that moment. **The two must always move together**; gate 98 §I2b asserts it.
- **ONE RENDERER SERVES THE PAGE LOAD AND THE REFRESH**, and the refresh ships
  server-rendered markup rather than JSON a script re-renders — two renderers is
  two places a secret can leak and two things to keep gated. Both locks on the
  door (capability AND nonce), no `nopriv` twin, and the error path answers a
  **FIXED sentence, never `$e->getMessage()`**: a Throwable out of a file read or
  a PDO handle can carry a value, and nobody is looking at an error path.
- ⚠️ **A REFRESH THAT CAN RETURN A CACHED ANSWER IS A LIE**, and the fix needs
  BOTH cache layers. Measured on dev2: the box runs a persistent object cache
  (`wp-content/object-cache.php`, 105,926 bytes) and `lgms_shared_secret` is
  **autoloaded**, so it is served out of the `alloptions` blob and **not** from
  its own key. `refreshRead()` drops both, plus `Health::reset()` for the
  memoised settings file.
- **NO FLAG** — dash-only, matching #190, #192, #194 and #183. The one shared
  change is a pure refactor of a read-only reporter.
- **#201 BUILT** on `201-secret-status`, gate **98** (78 assertions; red-first
  **51/51** — 48 mutations each reddening its own named assertion, 3 no-op
  controls proven inert). Gate 91 is at 104 assertions, red-first 67/67.
- ⚠️ **THE GATE HAD NINE DEFECTS OF ITS OWN, and every one was found by red-first
  or by looking at the picture — none by review.** Six in the assertions: §C4
  wrote **one** settings file six times (the six fixtures are all evaluated
  before the loop runs) and rendered the same state under six names; §B6 asked
  *"both halves report their length"* of the whole render and was satisfied by
  **one** half; §H1 matched a different sentence in the same page; §G1 was
  vacuous because the cache stub let an `alloptions` delete wipe everything;
  §C3e could not fail because nothing threw mid-render; §E3 counted a **CSS
  rule** as a second renderer. Three more in the SCREEN, found by building the
  real thing and looking at it — see below.
- ⚠️ **AND ONE IN THE NEIGHBOUR: gate 91 died at exit 255 with NO FAIL LINE** the
  moment `HealthPanel` gained the new section, because the require list did not
  name it. **That is the FIFTH time a file in this plugin has died that way.**
  run-all reads a bare 255 as "red, culprit unknown". Gate 98 installs an
  exception handler and a shutdown handler so a fatal is reported **as a
  finding**.

### Verified on dev2 against both real halves (2026-08-22)

Driven as the site's own user, real code, real stores: the option out of dev2's
database and `LGMS_SHARED_SECRET` out of the real `/srv/lg-stripe-billing/.env`.
Verdict **MATCH**, both halves 64 characters. The **refresh handler** answers
200 with 3,711 bytes of markup. **No value, fragment, prefix or sha256 of either
real half appears in the markup or in the refresh response, and there are zero
`<input>` elements.**

- **PICTURE for Ian:** `/mockups/lanes/201-secret-status.html` — the real screen
  with the real reading, then every state it can be in, then the Settings tab
  before and after.
- ⚠️ **THREE DEFECTS THE PICTURE FOUND THAT 78 GREEN ASSERTIONS HAD NOT.**
  **(1)** every chip rendered as identical plain grey text — MATCH and BROKEN
  alike — because the palette lives in `HealthPanel`'s style block and this
  section only borrowed the class names; it is self-contained now (§H8).
  **(2)** PHP **swallows one newline directly after `?>`**, so the billing-app
  command rejoined into one line running off the edge of its box (§H4b).
  **(3)** the copy told the operator to reload PHP-FPM — checked rather than
  assumed, `LGSB\App::create()` calls `Dotenv::createImmutable(...)->load()` on
  **every request**, so the app re-reads that file as it stands and the reload
  was a real action on a live box for no reason (§H4c).
- **Owed:** Ian looks at the tab on dev2 after the merge. **The live gap is
  `lgms_shared_secret` itself** — still absent, still a live write and therefore
  his; this section is what will say so out loud, and what will confirm the fix
  landed on both halves the moment he presses Refresh.

### Two stale artifacts found while measuring, reported not fixed

- ⚠️ **`lg-patreon-stripe-poller/PICKUP.md:140` is confidently wrong about
  deploy.** It says the poller is *"a wp-content plugin, not a /srv git-served
  app … deployed via the self-verifying patchers in `deploy/patch-*.py` — NOT
  git pull."* Both boxes contradict it:
  `wp-content/mu-plugins/lg-patreon-stripe-poller` is a **symlink into
  `~/loothplatformv2-clean`** on **dev2 AND live**, and the plugin autoloads
  `LGMS\` PSR-4 from `src/`, so a new class file needs no require-list edit and
  no symlink. **A pull delivers it.** A lane that believes that line will
  hand-deploy over a symlink.
- **Live's `mu-plugins/lg-patreon-stripe-poller.php` loader is a REAL FILE**
  (2,193 bytes), not a symlink as it is on dev2. Its content matches the repo's
  and it only `require_once`s the folder's main file, so nothing here depends on
  it — recorded because a change to **that loader** would not reach live by pull.
