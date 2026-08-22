# 196 — a Patreon payer sees SWITCH, never JOIN

> ⚠️ **AMENDED MID-BUILD, 2026-08-22 — read this before §2(b) or §4.**
> §2(b) proposed relabelling all THREE renderings of the Join door, and §4 asked
> whether to. Ian answered *all three* — and then, on seeing the built result,
> ruled the signed-in pill out altogether: *"Why is there a superfluous join
> button for logged in users now."* So `$join_pill_authed` is **gone**, not
> relabelled, and the PWA account-sheet row is re-sourced from the account-menu
> entry — because the account menu is `display:none` below 641 on every surface,
> so deleting both would have left a signed-in tester no door on a phone. Keeper
> confirmed that reading before I built it. §4's other answers: cut the reminder
> sentence, slug `/switch-billing/`. Everything else shipped as written.
> A stale artifact is confidently wrong, so this note lives here and not only in
> the handoff — see `docs/domains/MEMBERSHIP.md`, State (8/22, #196).

Lane `196-switch-menu`, issue #196. Ian, 8/22, verbatim:

---

## 1. What I measured before planning

### The defect is real, and there is a real person in it on dev2 today

`lg_shared_render_site_header()` rendered with a tester ctx
(`stripe_testgroup => true`), from this worktree:

```
/profile/edit          My Profile
/manage-subscription/  Manage Account
/lgjoin/               Join          ← offered to every tester, Patreon-blind
/lggift-buy/           Gift Memberships
/lggift/               Redeem a Gift
/my-gifts/             My Gifts
/request-refund/       Request a Refund
```

The dev2 cohort, each asked `LGMS\Membership\PatreonStanding::forUser()`:

| uid | login | Patreon | reason | tier | next charge |
|---|---|---|---|---|---|
| 854 | GerryHayesTest | no | no_patreon_link | — | — |
| 1887 | qa-disposable | no | no_patreon_link | — | — |
| 1938 | qa-gift-rcpt | no | no_patreon_link | — | — |
| **1953** | **mikelle.davlin** | **YES** | **active_paid_patron** | **looth2** | **2026-09-02** |
| 2047 | gdle_gate_probe | no | no_patreon_link | — | — |
| 1 | iandavlin | no | no_patreon_link | — | — |

**User 1953 is a listed tester who is actively paying Patreon** (Looth Legacy
Member, next charge 2 Sep). She is offered Join today. That is the issue, on
dev2, with a name on it — not a hypothetical.

⚠️ **And the guard is NOT armed on dev2.** `lgms_double_pay_block` is
**ABSENT ⇒ OFF**. So today the menu offers her a door and nothing at the far end
stops the second charge. (`lgms_checkout_audience` is absent ⇒ `allowlist`, so
she is *in* the cohort and passes that fence.) The issue calls the menu "the
FRONT half of the dual-rail law"; on dev2 it is currently the **only** half.

### She gets the SAME door twice, not once

dev2's `header-join-stripe.local.php` is **`state => 'allowlist'`** (keeper set it
8/21, not `'on'`). In that state `$join_pill_authed` is TRUE, so a signed-in
tester ALSO gets a `.lg-chrome__join` **pill** reading "Join" beside the chip
(#170), and `webroot/bottom-nav.js` (line 949) **mirrors that pill into the PWA
account sheet** — which at ≤640 on the hub is the *only* one she has, because
`forums.css` hides the whole aside there.

So "the Join door offered to a Patreon payer" is **three renderings of one
control**, all signed-in, all live on dev2. The issue names the menu item; I am
taking all three, because leaving a "Join" pill six pixels from a "Switch" menu
item is incoherent, and the PWA copy is the one a phone actually sees. **Flagging
it as a scope call — say the word and I will do the menu item alone.**

The **anonymous** pill is untouched, per issue item 3.

### The capability channel already exists and is already gated

`stripe_testgroup` reaches the header as a **capability**:

```
poller InternalRestController::capabilities()      ← computes it
  → profile-app Whoami::capabilitiesFor()          ← NAMED pass-through list
    → each app's $ctx['capabilities']              ← 5 apps pass it straight through
      → lg-shared/site-header.php                  ← reads $caps['...']
```

That is the answer to the charter's "the header has no database" constraint, and
it is the pattern I will reuse rather than inventing a store. The tester-unlock
JSON store exists for the **anonymous** case, where there is no per-viewer
channel; this viewer is signed in and the channel is right there.

⚠️ **Gate 34b already cross-checks it**: it greps the header for every
`caps['x']` it keys on and fails if profile-app's named pass-through drops one.
So forgetting the other end is a RED, not a silent false. (That is the Mikelle
bug of 8/16 — same user, as it happens.)

`PatreonStanding::forUser()` is a **PRIMARY KEY lookup**: `wp_user_id` is the PK
of `lg_patreon_members` (1,704 rows on dev2). Computing it unconditionally on the
whoami cache-miss path costs one PK read on a path that already makes a loopback
HTTP call. I will not make it conditional on `stripe_testgroup` — a capability
whose `false` means two different things is a trap.

### The new page needs an nginx edit, and a pull will NOT deliver it

`platform/nginx/strangler-membership.conf` has ONE location whose regex
**enumerates every membership slug**. There is no catch-all. And the running copy
is a **root-owned real file**, not a symlink:

```
/etc/nginx/snippets/strangler-membership.conf   -rw-rw-r-- ubuntu ubuntu (a COPY)
deploy = sudo cp platform/nginx/strangler-membership.conf /etc/nginx/snippets/ \
         && sudo nginx -t && sudo systemctl reload nginx
```

I also found the box copy is **already behind the repo** — it is missing
`fastcgi_param LG_FOLLOWING_CADENCE 1;` which main has tracked. Reported, not
touched.

**This is the "wired perfectly and lands nowhere" coupling, declared up front.**
It is the same shape as #165's two-switch flip, and I will gate it the same way
(§E below).

---

## 2. What I will build

### (a) Patreon standing becomes a capability — `patreon_paying`

`InternalRestController::capabilities()`, one block beside `stripe_testgroup`:

```php
$caps['patreon_paying'] = false;
try {
    $caps['patreon_paying'] = \LGMS\Membership\PatreonStanding::forUser( $wpUserId )['active'] === true;
} catch ( \Throwable $e ) { /* unknown is not a payment; log */ }
```

- **Fails closed to `false` = today's behaviour** (Join), never to Switch. An
  unknown must not send a non-Patreon member to a page about cancelling Patreon.
- **No new detection.** `PatreonStanding` is the one definition #150's guard uses;
  I add no second one.
- `profile-app/src/Whoami.php` — add `'patreon_paying'` to the named
  pass-through list, with the comment that list already carries.

### (b) The header swaps the control — three renderings, one rule

`lg-shared/site-header.php`, inside the existing `if ($stripe_tester)` block only:

```php
$patreon_paying = ($caps['patreon_paying'] ?? false) === true;
$join_label = $patreon_paying ? 'Switch' : 'Join';
$join_menu_href = $patreon_paying ? '/switch-billing/' : '/lgjoin/';
```

1. **account menu item** — `Join → /lgjoin/` becomes `Switch → /switch-billing/`.
2. **the authed tester pill** (`$join_pill_authed`, 'allowlist' only) — same swap.
3. **`webroot/bottom-nav.js`** — its tester row hardcodes `<span>Join</span>` and
   reads only the href. It will read the **label** from the header too, so the
   two copies cannot disagree — the same rule #165 applied to `target="_blank"`.

⚠️ **The `<?php if ?>` tags stay at column 0.** #170's 9-byte whitespace leak was
caught by gate 79 §C's byte comparison; I will not re-indent them.

**NO NEW FLAG** (issue item 4). Every byte of this change lives inside
`if ($stripe_tester)`, so the render differs only for members already inside the
soft-launch narrowing. The gate proves it: a non-tester render is byte-identical
to `origin/main` with the capability present-and-true, present-and-false, and
absent.

### (c) `/switch-billing/` — the instructions page

New `membership-pages/web/switch-billing.php` + `switch-billing.css`, standalone,
copying `manage-subscription.php`'s shape (no WP boot, `config.php` +
`lib/whoami.php` + shared header/footer, own `<main>`).

Data: `lg_membership_patreon_standing($uid)` and
`lg_membership_load_patreon_membership($uid)` — **both already exist** in this app
and are already kept honest against `PatreonStanding` by gate 75. No new reads.

Router: `'switch-billing' => ['switch-billing.php', 'testgroup', 'member']`.
Prelaunch = testgroup (mirrors who gets the menu item — a menu must not offer a
door the gate shuts). Live = member (once Stripe is GA, any Patreon payer should
be able to read it).

nginx: `switch-billing` added to the slug regex in all three
`platform/nginx/strangler-membership*.conf`. **Declared config change.**

**The words — Ian's eyes, his words outrank mine.** Draft, with her real numbers
substituted (`{TIER}` = "Looth (Legacy Member)", `{DATE}` = "2 September 2026"):

> # Switching from Patreon to card billing
>
> You are currently a member through **Patreon** — {TIER}. Your next Patreon
> payment is due on **{DATE}**.
>
> You can move your membership so you pay us directly by card instead. Nothing
> about your membership changes: same tier, same access, same account. Only where
> the money comes from changes.
>
> **Do it in this order, so you are never charged twice and never left short.**
>
> **1. Cancel your pledge on Patreon.**
> [Manage your pledge on Patreon →]
> Cancelling does **not** cut you off. Patreon keeps your membership running until
> **{DATE}** — the end of the period you have already paid for. Nothing changes
> here until then.
>
> **2. Come back on {DATE} and join with a card.**
> [Join with a card →]
> That is the day your Patreon membership lapses. Join on that day and your access
> carries straight on. Put it in your calendar — we will email you a reminder too.
>
> **What happens if I am a few days late?**
> Nothing is deleted. Your account stays exactly as it is; you will just see the
> free member view until you join. Come back whenever and you pick up where you
> left off.
>
> **Why can I not just do both and cancel later?**
> Because you would be paying twice for the same month, and we would rather you
> did not. If you have already ended up paying both, tell us and we will sort out
> the refund.
>
> **Changed your mind?** Do nothing at all. Your Patreon pledge carries on as
> normal — cancelling it is the only step that changes anything.

⚠️ **Two honest things in that copy I want Ian to rule on:**
- **The gap is real.** Ian's own ruling blocks a member from paying both rails, so
  "cancel then rejoin" necessarily has a seam at {DATE}. I have written it as *put
  it in your calendar* + *nothing is deleted if you are late* rather than pretend
  there is no seam. The alternative — let them overlap by one month and eat the
  double charge — contradicts the 8/19 ruling, so I have not proposed it.
- **"we will email you a reminder too"** describes a thing that DOES NOT EXIST.
  I will either build it or cut the sentence. **Recommend: cut it for now**, note
  it as a follow-up issue. Building a scheduled member email is its own lane, and
  `trap-dev2-mailpit-cannot-prove-a-send` says it could not be verified here
  anyway. Ian's call.

Non-Patreon testers see today's page and today's menu, untouched.

### (d) Gate 93 — red-first

`tools/gates/switch-menu-gate.py` + `switch-menu-redfirst.py`. Number minted from
**main** (highest there is 92). ⚠️ Lanes 193 and 194 are in flight and will mint
93 too — keeper keeps all three on merge; I will flag it in DONE.

- **§A SOURCE**, comment-stripped via PHP's tokenizer (prose must not satisfy an
  assertion): the header keys on `patreon_paying`; the label and both hrefs are
  resolved from it, not written down twice; the capability is computed centrally
  in the poller from `PatreonStanding` and nowhere re-derived; profile-app
  forwards it.
- **§B RENDER**, executed, five viewers × the capability present-true /
  present-false / absent: a **Patreon-paying tester sees Switch and NOT
  `/lgjoin/`**; a **non-Patreon tester sees Join and NOT `/switch-billing/`**; a
  **Patreon-paying NON-tester sees neither** (no leak outside the narrowing); an
  **anon ctx** is unchanged; and the pill + menu **agree with each other** in
  every case.
- **§C BYTE-IDENTITY vs `origin/main`**: anon in every state; and the
  non-tester authed render with the capability true, false and absent. The
  "OFF is a no-op" claim held as a fact, not a docblock.
- **§D THE PWA COPY**, run rather than read: `bottom-nav.js`'s label+href rule
  lifted out and executed in node against both destinations, because the real
  origin serves **main** and cannot exercise this branch
  (`trap-harness-and-serve-answer-from-main`).
- **§E REACHABILITY — the coupling.** The router registers exactly the slug the
  header points at (a static cross-check, so they cannot drift), and the nginx
  slug regex contains it. Then, in a real browser at 1440/821/640/390 in both
  themes, signed in as **1953**: the control is hit-testable at its own centre
  (`elementFromPoint`, not presence — `trap-blind-cdp-click-lands-on-fixed-furniture`)
  and `/switch-billing/` answers 200 with real content, not the pre-launch stub.
  While the box's nginx has not been updated this leg **REPORTS**; once it has, it
  ASSERTS — and it asserts unconditionally the moment the header can emit the
  href, so the half-done state cannot go quiet
  (`feedback-gate-reads-the-flag-not-a-hardcoded-state`).
- **Liveness beside every absence** — an absence assertion is vacuous without one
  (`feedback-absence-assertion-needs-liveness`), and a locked-out browser goes
  vacuously green (`trap-locked-out-browser-goes-vacuously-green`).

I will also run gates **79, 85, 87 and 34b individually** and prove anon and
non-tester renders unmoved.

---

## 3. Files I expect to touch

**Code**
- `lg-patreon-stripe-poller/src/Wp/InternalRestController.php`
- `profile-app/src/Whoami.php`
- `lg-shared/site-header.php`
- `webroot/bottom-nav.js`
- `membership-pages/web/switch-billing.php` *(new)*
- `membership-pages/web/switch-billing.css` *(new)*
- `membership-pages/web/router.php`
- `membership-pages/config.php` *(only if a helper is genuinely missing — I expect not)*

**⚠️ CONFIG — declared per LANE-RULES**
- `platform/nginx/strangler-membership.conf`
- `platform/nginx/strangler-membership-buck.conf`
- `platform/nginx/strangler-membership-preview-a.conf`

**Gates / docs**
- `tools/gates/switch-menu-gate.py` *(new)*, `tools/gates/switch-menu-redfirst.py` *(new)*
- `tools/gates/run-all.sh`, `docs/CRAFT-STANDARD.md`
- `docs/domains/MEMBERSHIP.md` *(same commit as the close, per LANE-RULES)*
- `handoffs/plans/196-switch-menu-PLAN.md`, `handoffs/2026-08-22-196-switch-menu.md`

**Deliberately NOT touched** (guessing wide, per LANE-RULES):
`lg-patreon-stripe-poller/src/Wp/Admin.php` (lanes 193 + 194 own it — I need no
admin surface), the anonymous header branch, `lgjoin.php`, and the three
Patreon-labelled join CTAs #165 left open.

---

## 4. Open questions for Ian

1. **Scope** — the menu item alone, or the menu item + the tester pill + the PWA
   sheet row? (Recommend all three; they are one control rendered three times,
   and dev2's `allowlist` state means all three are live for user 1953 today.)
2. **The copy** — his words outrank mine. Draft is in §2(c).
3. **The reminder email sentence** — cut it (recommended) or build it as a
   follow-up?
4. **Slug** — `/switch-billing/`. "Stripe" is our word, not a member's.

Nothing else is blocked on these; I will build against the recommendation and
change the words on his say-so.
