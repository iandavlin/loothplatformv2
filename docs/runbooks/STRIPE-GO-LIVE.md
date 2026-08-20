# STRIPE GO-LIVE — the whole road from tonight's dev2 to a live soft launch

Assembled 8/20 night from the merged work + flip kits. Every LIVE write is
Ian's (standing law) — keeper hands commands, verifies after. Steps are IN
ORDER; each has its verify; skip nothing. Live facts pre-resolved 8/20:
**live has NO Discussions twin forum and an EMPTY catalogue** — both are
steps below, not surprises.

## 0. Preconditions (keeper verifies, before Ian touches anything)
- [ ] live == main: `lg-deploy` run recently; `/billing/v1/products` answers
      200 on live (loopback + `--resolve`, never a bare public curl).
- [ ] Fresh backup image exists (#152 — mint it BEFORE go-live day).
- [ ] All these read OFF/absent on live: `lgms_multi_tier`,
      `lgms_double_pay_block`, header-join flag, composer flag, banner flag.
- [ ] Dual-payer census re-run on live = still ZERO
      (`deploy/remediation/report-dual-payers.sql`, pure SELECT).

## 1. Live catalogue (Ian, one command + one dash session)
- [ ] `php bin/stripe-import-catalog.php db/catalog.json` with the LIVE key
      (idempotent; registers Looth LITE + Looth PRO products).
- [ ] Live dash → Settings → LG Member Sync → Stripe Price (tier × cadence):
      **LITE $5/mo + $55/yr, PRO $11/mo + $120/yr** (the 8/20 ruling). This
      creates the live Stripe prices and retires any strays by rhythm.
- [ ] Verify: live `/billing/v1/products` shows exactly 2 recurring prices
      per tier, the ruled numbers, nothing else.

## 2. Twin Discussions forum (composer prerequisite — ids differ per box)
- [ ] Create the live Discussions forum (the guarded wp-cli paste in
      FLAGS.md's composer row). Record the NEW id — it is NOT 73564.
- [ ] Set it in live's `composer-categorize-last` config (`default_forum_id`).
- [ ] Mirror row: `bb_mirror_sync_dispatch('forum', <id>, 'upsert')`, then
      verify `lg_ccl_default_forum_ok()` true AS THE POOL USER (a shell-user
      false is the recorded trap).

## 3. Flags, strictly in this order (flip → verify → next)
1. [ ] `lgms_stripe_pages_live = 1` — join/manage pages go public.
       Verify: anon live `/lgjoin/` = 200 with the two cards.
2. [ ] `lgms_multi_tier = 1` — the grant follows the price paid.
       Verify: gate 76 green run against live config reads.
3. [ ] `lgms_double_pay_block = 1` — one payment source per member.
       Verify: census still zero; a Patreon-active test account is refused
       at checkout with the plain message (gate 75's probe).
4. [ ] Header-join flag → **allowlist** state + populate the soft-launch
       allowlist (IAN PICKS THE TESTERS — the one human input this file
       can't supply). Verify: anon live header still says Patreon
       (byte-identical page), an allowlisted login sees the tester pill.
5. [ ] Composer flag ON (needs step 2 done). Verify: lane 129's phone-check
       script — post lands in the live Discussions forum.
6. [ ] (Optional cosmetic) banner-retire ON — the front-page strip goes.

## 4. Soft launch proof (allowlisted testers only)
- [ ] A tester walks header → /lgjoin/ → subscribes LITE (live mode: use a
      100%-off promotion code, or a real card refunded — Ian's preference).
- [ ] Verify the whole chain: Stripe sub → entitlement → `lg_role_sources`
      stripe row → Arbiter → WP role looth2 → member sees member content →
      /manage-subscription/ renders their sub.
- [ ] Repeat once for PRO → looth3.
- [ ] Their Patreon-active friend CANNOT buy (double-pay refusal, live).

## 5. Full go-live (a later, separate day — Ian's word)
- [ ] Header flag → **on** (everyone sees /lgjoin/).
- [ ] The three other Patreon CTAs per Ian's pending ruling
      ("flip them too" / "keep them patreon" — #165's comment thread).
- [ ] Search Console re-crawl nudge (pairs with #81's flip if not yet done).

## Rollbacks
Every step above is a flag or config value — OFF restores the prior world
byte-identically (each was gated OFF-proven before merge). The catalogue and
forum persist harmlessly when flags are off. There is no step here that
cannot be undone in under a minute except a real tester charge — hence the
promo-code option in step 4.
