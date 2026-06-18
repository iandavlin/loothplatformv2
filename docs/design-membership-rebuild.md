# Design — Membership Infrastructure Rebuild

> **Status:** v0.1 — APPROVED to execute, 2026-05-30 (Ian: "pull the trigger").
> North star (Ian): **"we're so close to just using WordPress as an admin interface."**
> This doc is the plan to finish that for the billing/tier side.
> **POST-CUT project** — does NOT ride the big cut (auth/tier invariant: no
> first-time authority changes on cut day). Dual-wield Patreon continues
> throughout. Pairs with the login-into-profile-app project (same endgame:
> WP becomes a data/admin store, off the hot path).

> ## ⭐ THE KEYSTONE THIS PROJECT EXISTS TO KILL
> **WordPress is still the entitlement authority for the entire off-WP stack** —
> via ONE coupling, two halves:
> 1. **WP decides tier** — the `Arbiter` runs *inside* WP
>    (`lg-patreon-stripe-poller/src/Arbiter.php`); even Stripe entitlements get
>    arbitrated by WP-resident code writing `wp_capabilities`.
> 2. **WP serves tier** — profile-app `/whoami` reads it back via loopback to
>    `/wp-json/looth-internal/v1/user-context/{id}` (a WP endpoint).
>
> Every standalone surface (archive, forum, events, profile, directory) gates on
> tier through that one thread. Nothing outside WP grants entitlement today. This
> project relocates the Arbiter (step 1) and moves the tier store into profile-app
> (step 2) so the loopback dies and **WP is no longer load-bearing for entitlement.**

> ## Scope boundary — what stays in WP, by design (Ian, 2026-05-30, accepted)
> After this + the login project, WP's residual user-facing footprint is:
> - **Admin / content authoring console** — INTENDED (the north star).
> - **Forum server (BB) write path** — ACCEPTED to stay for now. bb-mirror reads
>   forums standalone+fast; only *posting* round-trips BB REST → WP boot. Ian:
>   "and the forum server. that's fine." De-BB-ing the write path is a separate,
>   later project — explicitly NOT in this plan's scope.

---

## 0. Where billing actually lives TODAY (grounded, not from memory)

The coord-doc §3e fear ("WP RCE → Stripe key exfil") is **already mostly
mitigated** — the money engine is standalone. The split today:

| Piece | Home | Holds Stripe keys? | Responsibility |
|---|---|---|---|
| **Money engine** | `/srv/lg-stripe-billing/` (standalone, own FPM/systemd) | **Yes** (its own env, `EnvSettingsStore`) | Stripe checkout, webhook reception, `EntitlementManager`, `SubscriptionWebhookHandler`, `GiftCode` |
| **Poller plugin** | `lg-patreon-stripe-poller` (inside WP) | **No** (wp-config confirmed clean) | `PatreonSourceReader` (polls Patreon), `Arbiter` (picks winning tier), `RoleSourceWriter` (writes `wp_capabilities`), `PurgeNotifier`, the internal `user-context` tier endpoint |
| **Tier reader** | profile-app `Whoami.php` | No | loopback → `/wp-json/looth-internal/v1/user-context/{id}` → poller's source rows. **profile-app never touches Patreon/Stripe.** |

**So the ONLY billing-ish thing still trapped in WP is the poller plugin:**
Patreon polling + Arbiter + role-writing + tier-serving. That's the surface
to extract. The money engine is already where we want it.

---

## 1. The architectural question: "is billing a subset of profile?"

Ian's intuition: connections, messaging, identity all live in profile-app now;
tier is already in the JWT + `/whoami`; membership *feels* like part of the
profile. **He's right — but only for two of the three layers.** The dividing
line is NOT "billing vs profile." It's **"touches payment credentials vs
doesn't."**

| Layer | Fold into profile? | Why |
|---|---|---|
| **UX / IA** (account dropdown, tier badge on profile, "Manage Subscription") | **YES** | The unified header already does this. Membership belongs in the account surface. |
| **Tier as a DATA attribute** (what tier am I, provenance) | **YES** | Tier is an identity attribute. It's already in `/whoami` + the `lg_tier` cookie. Moving the *store* into profile-app kills the per-render user-context loopback. |
| **Payment-credential handling** (Stripe keys, webhook secret, card flows) | **NO — keep isolated** | profile-app is becoming a *fat internet-facing target*: profiles, directory, messaging, file uploads, galleries. You do NOT want the Stripe keys + the JWT signing key + member PII + DM history all behind one RCE. Payment secrets want the **smallest, most boring, least-dependency** service possible (PCI-adjacent hygiene). |

**Verdict:** fold the **data + UX** of membership into the profile world; keep
the **money engine isolated** exactly where it already is. The rebuild moves the
*tier-decision* logic (Arbiter) out of WP and lets profile-app *own the tier
attribute* — without ever pulling Stripe credentials into the fat app.

---

## 2. Target architecture (post-rebuild)

```
   Patreon API          Stripe API + webhooks
        │                       │
        ▼                       ▼
  ┌──────────────────────────────────────────┐
  │  billing-svc  (standalone systemd, own    │   ← the ONLY service holding
  │  user, NO wp-config read, own pg schema)  │     payment credentials.
  │  • Patreon poll  • Stripe poll/webhook    │     Small. Boring. Audited.
  │  • Arbiter (pick winning tier)            │
  │  • GiftCode  • EntitlementManager         │
  │  • emits tier-changed events              │
  └───────────────────┬──────────────────────┘
                      │ writes tier (sole writer) via internal channel
                      ▼
  ┌──────────────────────────────────────────┐
  │  profile-app  — OWNS tier as an attribute │   ← /whoami reads tier LOCALLY.
  │  member_tier(user_uuid, tier, provenance, │     No more user-context loopback.
  │              source, updated_at)          │     Tier in the JWT/cookie as today.
  └───────────────────┬──────────────────────┘
                      │ read
                      ▼
        every surface (header, /u/, archive, forum…)

  ┌──────────────────────────────────────────┐
  │  WordPress — ADMIN INTERFACE ONLY         │   ← wp_capabilities tier roles
  │  • wp-admin member management             │     become a MIRROR (for admin
  │  • content authoring (lg-layout-v2)       │     filtering) or drop entirely.
  └──────────────────────────────────────────┘
```

**Key shift:** today WordPress (`wp_capabilities`) is the tier authority and
profile-app reads it via loopback. After: **billing-svc writes tier straight
into profile-app**, profile-app is the tier store, WP roles become a downstream
mirror (or go away). That single move takes WP off the tier hot path — the same
shape as the shim taking WP off the identity hot path.

**billing-svc** = the extracted poller merged with / sitting beside the existing
`/srv/lg-stripe-billing/` money engine. They already share a domain (entitlement
→ tier); unifying them is natural. Own pg schema `billing` (joins the
one-postgres-N-schemas model).

---

## 3. Dual-wield Patreon — unchanged by all of this

The B-now/A-later model is preserved end to end:

- **Patreon adapter + Stripe** both write **source rows** into billing-svc.
- The **Arbiter picks the winner** (highest active entitlement) — same logic,
  just relocated from the WP plugin into billing-svc.
- Stripe ships **dormant** (no creds = no source rows) until Ian flips it on.
- No change to dual-wield *behaviour* — it just runs in a standalone process
  instead of a WP plugin. Patreon stays the live writer "for a while" exactly
  as Ian wants.

---

## 4. Sequencing (each step independently shippable + soakable)

Ordered by risk, lowest first. Nothing here is cut-day; all post-cut.

**Step 1 — Relocate the poller (behaviour-identical lift).**
Move `PatreonSourceReader` + `Arbiter` + `RoleSourceWriter` + the user-context
endpoint out of the WP plugin into billing-svc. Tier still written to
`wp_capabilities` (Arbiter output unchanged); profile-app's loopback URL just
repoints to billing-svc. **Lowest risk — same behaviour, new address.** Proves
the extraction without changing the authority model.

**Step 2 — Invert tier authority into profile-app.**
billing-svc writes tier to a new profile-app `member_tier` table (sole writer,
internal channel — mirrors today's `looth_tier_changed` purge wiring). `/whoami`
reads tier **locally** (loopback retires — a second hot-path win after the
shim). `wp_capabilities` becomes a mirror billing-svc also writes *for wp-admin's
benefit*, or drops if admin doesn't need it. **This is the "WP stops being tier
authority" moment** — its own dev soak, like the shim.

**Step 3 — Standalone checkout + account pages.**
`/lgjoin/`, manage-subscription, gifts, refunds move from WP shortcode pages to
standalone pages calling billing-svc directly (the money engine already has the
APIs). The membership-chrome mu-plugin (this session's work) retires as each
page goes standalone. WP billing pages drop.

**Step 4 — WP = admin only.**
With tier authority (this project) + login authority (the login-into-profile-app
project) both out of WP, and content already standalone, WordPress is reduced to:
wp-admin member management + content authoring. The north star.

---

## 5. Open questions for Ian

1. **wp-admin member tooling** — when tier truth lives in profile-app, does
   wp-admin still need a tier mirror for the member-management screens, or do we
   build a thin admin view in profile-app and drop the WP roles? (Leaning: keep a
   cheap mirror through cut+1, drop later — admin convenience isn't worth a forced
   migration.)
2. **billing-svc unification** — merge the extracted poller INTO
   `/srv/lg-stripe-billing/` (one billing service), or keep two standalone units
   (money-engine + tier-arbiter) talking over an internal channel? (Leaning:
   merge — they already share the entitlement→tier domain.)
3. **Timing vs login project** — run tier-authority-inversion (step 2 here) and
   login-authority-inversion as one "WP demotion" push, or stagger? Both want
   their own soak; both end at the same place.

---

## 5b. Security review — stern pass (Ian asked, 2026-05-30)

This project **moves who can grant entitlement** and **where the new tier-write
path lives.** That makes entitlement-granting itself the attack target. Treat the
tier-write path as security-critical as the payment path — a forged tier grant is
free Pro; at scale it's revenue loss + a fraud vector. The five hard rules:

**A. Entitlement-grant integrity — the new keystone is the new target.**
After step 2, *writing a row to `profile_app.member_tier` IS granting paid access.*
- ONE writer: billing-svc only. Enforce at the DB-grant level (§3i) — billing-svc
  role gets `INSERT/UPDATE` on `member_tier` and NOTHING else gains it. profile-app's
  own web role gets `SELECT` only on that table; it must not be able to write its
  own tier (a profile-app RCE must not self-grant Pro).
- Internal channel auth: shared secret in `/etc/lg-internal-secret`, `hash_equals()`
  (constant-time), same as §2. The endpoint must be **loopback/internal-only** —
  nginx exempt-path discipline; never reachable from the public internet. Verify
  with an external curl that it 403s/refuses off-box.
- **Idempotent + replay-safe:** every tier-change carries an event id; re-applying
  the same event is a no-op. A captured-and-replayed grant must not re-grant or
  extend.

**B. Credential isolation — the whole §1 thesis, enforced not just stated.**
- billing-svc holds Stripe keys + webhook secret. It must have **zero** access to:
  the JWT signing key (`/etc/looth/jwt-private.pem`, now `root:profile-app`),
  profile-app identity tables, DM content, or media. Separate OS user, separate DB
  role. A billing-svc compromise = attacker can change tiers (bounded, auditable),
  NOT forge identity, mint tokens, or read DMs.
- Inverse: the fat app (profile-app) **never** receives Stripe credentials. If you
  ever feel the urge to "just read the Stripe key in profile-app," stop — that
  collapses the entire boundary this project is built on.
- Stripe webhook signature verification (`whsec_`) stays mandatory; the relocation
  must not weaken it. Patreon poll over TLS, token in a mode-600 secret file.

**C. Fail-closed, always — down must never mean free.**
- billing-svc unreachable / erroring → consumers see **least privilege** (`public`
  + `tier_unavailable: true`), exactly as `/whoami` does today. Preserve that. An
  error, timeout, or empty source set must NEVER resolve to a paid tier.
- The Arbiter's "winning tier" defaults to `looth1`/public on null/empty/ambiguous
  sources — keep that default; assert it in the port's unit tests (B is meaningless
  if a parse error silently yields Pro).

**D. Surface exposure — moving the endpoint must not widen it.**
- The relocated `user-context` (and any new tier-write) endpoints are internal
  only. New service = new socket, new pool, new nginx route — each is a new way in.
  Inventory them; none gets a public location block. Smoke from off-box: must fail.
- billing-svc admin/debug routes (if any) gated; no tier-mutation reachable without
  the internal secret.

**E. Auditability + revocation (sharpened by dual-wield Patreon).**
- Every tier change writes an immutable audit row: `(user_uuid, old, new, source,
  event_id, actor, ts)`. A fraudulent or buggy grant must be *detectable after the
  fact* and reversible.
- **Dual-wield staleness = a revocation hole.** Two writers (Patreon + Stripe) feed
  source rows; a cancelled Patreon that leaves a stale source row = entitlement that
  should be gone but isn't. Source rows need freshness/expiry; revocation must
  propagate to `member_tier` promptly (the existing `looth_tier_changed` purge wiring
  is the mechanism — keep it firing on downgrades, not just upgrades).
- Comp/admin grants (`looth4`) are the highest-value forge target — route them
  through the same audited writer; no out-of-band role pokes.

**Pre-ship gate for each step:** off-box curl proves internal endpoints refuse;
DB grants prove single-writer; unit tests prove fail-closed defaults; an audit row
exists for every grant. No step is "done" until all four hold on dev.

## 6. What this is NOT

- **Not cut-blocking.** Cut ships the current model (poller dormant, Patreon
  adapter, profile-app loopback). This is the post-cut cleanup.
- **Not a payments rewrite.** The money engine (`/srv/lg-stripe-billing/`) stays.
  We're relocating the *tier-decision* logic and *moving the tier store*, not
  re-implementing Stripe.
- **Not pulling Stripe keys into profile-app.** The whole point of §1 is that the
  fat app never holds payment credentials.

— coordinator (Opus scoping pass, 2026-05-30)
