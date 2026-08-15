# New members arrive alive (backlog 19) — phase 1: the finding, the numbers, the decision

Lane: `profiles-alive`, branch `profiles-alive`. Ian 8/12, from the empty-directory
screenshot. **Phase 1 is mocks only. Nothing below is built.**

Mocks (behind the dev gate): `/footer-mockups/profiles-alive/`
Source (in-repo): `footer-mockups/profiles-alive/`, symlinked into
`~/projects/footer-mockups/profiles-alive` so nothing is written to `/var/www/dev`.

---

## 1. The headline finding: there is no sign-up form

The backlog line offers "a sign-up step **or** a first-login prompt". **The sign-up
step cannot exist**, and that is not a judgement call — it is what the code does:

| Fact | Where |
|---|---|
| WordPress self-registration is OFF | live `wp_options.users_can_register = 0` |
| The BuddyBoss signup table is empty | live `wp_signups` — 0 rows |
| Accounts are built by the Patreon OAuth callback | `lg-patreon-stripe-poller/lg-patreon-onboard.php:1570-1601` |
| The member is logged straight in, no password | `lgpo_login_user()`, same file |
| …then redirected to the welcome page | `wp_safe_redirect( home_url('/patreon-password/') )` |
| That page is the set-password screen, and it is skippable | `platform/mu-plugins/lgpo-set-password.php` |

A new member **types nothing**. Name, email and tier come from Patreon; the WP user,
the usermeta, the membership snapshot and (via `user_register` →
`platform/mu-plugins/profile-sync.php`) the profile-app profile are all created for
them server-side.

**So the platform gets exactly one screen with a brand-new member's attention**, and
it already exists: `/patreon-password/`. Today we spend all of it asking for a
password. That screen is the whole opportunity, and it is what the mocks build on.

### ⚠️ Standing ruling: DUAL RAIL (Ian, via keeper, 2026-08-15)

> *"Everything needs to fire for both for the foreseeable future."*
> *"We are dual wielding patreon and stripe for a while."*

The arrive-alive step triggers on **membership activation, whatever the rail**. A
Patreon joiner and a Stripe joiner get the **identical** screen. It must **never**
be keyed to one source's webhook or poller event.

The seam that already exists for this is **`lg_role_sources`** — both rails record
their role opinion there with a `source` of `patreon` or `stripe`, and an arbiter
merges them (`lgpo_apply_role_via_arbiter`; Stripe's grant path is
`LGSB\Core\EntitlementManager::grant*`). That, not a callback, is the hook point.

Practical consequence for the design: **`/patreon-password/` alone is the wrong
home for the step**, because a Stripe joiner never sees it — their equivalent
"you're in" moment is `membership-pages/web/welcome.php` (`/welcome/`). The step is
therefore its own screen that **both** rails hand off to, which is how the mock now
draws it.

The real flow, end to end:

```
PATREON RAIL
/patreon-connect → Patreon OAuth → /patreon-callback/
   → account created (WP user + profile-app profile via user_register)
   → lgpo_login_user()  — logged in, no password yet
   → /patreon-password/  — "Welcome, <first>!", set a password, SKIPPABLE
   → front page  (skip link goes to /u/<slug> instead)

STRIPE RAIL
/join → lgjoin.php → Stripe checkout
   → EntitlementManager::grant*() — membership granted
   → ReturnHandler 302 → /welcome/  — "You're in!", receipt, CTA to the community
   → front page

BOTH → [ the new arrive-alive step ] → front page
```

## 2. The numbers (live, read-only, 2026-08-15)

⚠️ **dev2 is not evidence here** — its newest rows are test fixtures. Everything
below is measured on live, the same rule
`tools/profile-completeness-report.sql` states.

**The backfills are the reason old members look fuller, and they stopped.** The
BuddyBoss avatar import (`avatar_version = 3`, 1,825 rows) covered everyone created
up to **2026-06-30**. From 1 July: zero. May's `backfill_location` is the same story
for location.

Of the **33 members who joined since 2026-07-01**:

| | count | share |
|---|---|---|
| no photo — a grey placeholder face | 28 | 85% |
| no location at all | 23 | 70% |
| never said what they do | 30 | 91% |

Widen to the **228 who joined since 2026-05-26** (i.e. everyone after the last
`member_since` was stamped): **215 sit at two of eight completeness items or fewer**;
25 have nothing filled in at all.

### Two measurement traps this lane hit, recorded so the next one doesn't

1. **`member_since` is a backfilled column, not a live one.** It stops at
   2026-05-25 and is NULL for 228 rows. Bucketing by it with `>=` / `<` puts the
   entire new-joiner cohort in *neither* bucket — the first cohort query reported
   "0 joined since June", which is the opposite of the truth. Use `created_at`.
2. **`avatar_version > 0` is honest, but only just.** 1,825 rows carry version 3
   from the bulk avatar import — I suspected these were placeholder stamps, which
   would have made the whole completeness meter dishonest. They are not: 1,823 of
   them point at real `/profile-media/avatars/…` uploads. The placeholder is
   `avatar_version = 0`, whose `avatar_url` is
   `/wp-content/uploads/2024/11/Optimum.png` — the grey face in Ian's screenshot.
   **The featured-members meter's `photo` item is correct; no change needed there.**

## 3. The decision put to Ian

Both options ask for the same three things — photo, city, one line about what you do
— and both are skippable. Only the timing differs.

- **Option A (recommended): one more screen in the welcome flow.** After the
  password, ask the three questions. It is the only moment they are already stopped
  and answering things; it adds no new surface; the skip path already exists.
- **Option B: leave the welcome alone, nudge on the front page.** A dismissible
  "finish your profile" card using the completeness meter. Asks at the moment they
  are least interested, needs a new dismiss-and-remember rule, and the front page
  actively wipes its own query string on load (`trap-front-page-wipes-query-params`),
  which makes it a poor host for a "you just joined" state.

A does not rule out B — B is the natural follow-up for people who skip A, and reuses
the same meter.

## 4. Reuse, not reinvention

The eight completeness items were **locked by Ian on 2026-08-14** (rulings doc item
6) and are implemented in `profile-app/src/Completeness.php`, with
`tools/profile-completeness-report.sql` as the matching read-only report. Ruling 6
also scopes the meter to the featured-members feature and leaves "site-wide push" to
**this** item. So this lane **uses** those definitions and must not redefine them —
the three fields it asks for are items 1, 2 and 3 of the existing eight
(`photo`, `location`, `what_you_do`), which are also exactly the three the public
card renders (`Completeness::CARD_ITEMS`).

## 5. Write paths — VERIFIED, and they exist for all three fields

The mock tells Ian "everything it needs already exists somewhere in the
platform". That claim was checked, not assumed — and it is **needed by both
options**, so it is worth having settled before the ruling:

| Field | Endpoint | Writes | Meter item |
|---|---|---|---|
| photo | `profile-app/api/v0/me-avatar.php` (POST, DELETE) | bumps `users.avatar_version`, sets versioned `avatar_url` | `photo` |
| city | `profile-app/api/v0/me-location.php` | `location_city`, `location_region` | `location` |
| — its autocomplete | `profile-app/api/v0/me-location-search.php` (GET) | — | — |
| what you do | `profile-app/api/v0/me-header.php` (PATCH `at_a_glance`, ≤500 chars) | `users.at_a_glance` | `what_you_do` |

Each writes **exactly** the column `Completeness::forUser()` reads, so filling
the step moves the meter with no new plumbing and no second definition.

⚠️ **`at_a_glance` is not a private field.** `me-header.php` mirrors it to the WP
user `description`, i.e. it is the single-source author bio and shows up as a
byline. That is desirable here, but it means the step's wording must suit a
public one-liner — which is why the mock asks "What do you do? One line." and
not something diary-shaped. It is also distinct from the meter's `bio` item,
which is the longer `profile_sections.about` text; the two do not collide.

Also re-verified rather than trusted from memory: the front page really does
drop its own query string on load — `archive-poc/web/archive.js:582`,
`history.replaceState(null, '', location.pathname)` inside `enterDiscover()`.
That is the basis of the note against Option B in the mock.

## 6. The hook already exists — `_lg_pending_welcome` (found 2026-08-15)

**Do not build a new activation trigger.** There is already a one-shot,
rail-agnostic, member-facing welcome moment, and it is exactly the seam this
feature needs:

| Piece | Where |
|---|---|
| Stamped on upgrade-to-paid, off the **winning tier across all sources** | `lg-patreon-stripe-poller/src/Arbiter.php:113` — `update_user_meta($wpUserId, '_lg_pending_welcome', $winning)` inside `if (self::isUpgradeToPaid(...))` |
| Rendered once, on the next front-end page load | `src/Plugin.php:657` `maybePrintWelcomeModal()` at `wp_footer` (bails on admin, ajax, logged-out) |
| Consumed / dismissed | `src/Wp/RestController.php:1686` → `delete_user_meta(..., '_lg_pending_welcome')` |
| Idempotent | re-running the Arbiter on a stable tier does not re-set it (`oldTier === winning`) |

Because it is stamped by the **arbiter** off the winning tier — not by a
callback or a webhook — it is dual-rail *by construction*, which is precisely
what Ian's standing ruling requires. The `stripe-membership` lane independently
audited the same property from the other direction on 2026-08-15 and reached the
same conclusion ("the arbiter is what announces activation … off the winning tier
across ALL sources"), and their **gate 34d** now actively forbids the Stripe leg
from mailing, firing hooks, or stamping member data.

⚠️ **That gate constrains this build.** The arrive-alive step must hang off the
arbiter's announcement, **never** off the Stripe leg — wiring it into the Stripe
leg would both break Ian's ruling and trip gate 34d.

Today that moment renders a celebration with two links (Member Guide / See
What's New). The three questions belong there, which makes Option A largely
"change what the welcome moment asks for" rather than "build a new screen".

### ⛔ …but it does NOT fire for new members. Measured, with the root cause.

**Presence is not reachability** — the mechanism exists and is still never
reached by the population this charter is about.

Measured on live 2026-08-15:

| | |
|---|---|
| `_lg_welcome_email_sent_at` rows (durable, never deleted) | **16 ever**, newest **2026-06-21** |
| `_lg_pending_welcome` still pending | 118 — but 115 of them have not been active since before July, i.e. dormant upgraders, **not** a rendering defect |
| Of the 33 members who joined since 1 July | **0 have either meta** |

`_lg_welcome_email_sent_at` is the load-bearing evidence: `WelcomeMailer` sets it
and never clears it (the dismiss endpoint only deletes `_lg_pending_welcome`), so
its absence proves the welcome path never ran — it is not "fired and dismissed".

**Root cause.** `lg-patreon-onboard.php:1553` creates the account with the paid
role already applied:

```php
$user_id = wp_insert_user([ ..., 'role' => $wp_role ]);   // looth2/3 set HERE
```

`lgpo_apply_role_via_arbiter()` then runs `Arbiter::sync()`, which derives
`$oldTier = self::currentTier((array) $user->roles)` (`Arbiter.php:56`) — already
`looth2`. With `$winning` also `looth2`, `isUpgradeToPaid('looth2','looth2')` is
`strcmp(...) > 0` → **false** (`Arbiter.php:157-169`), so line 113 never stamps.
Note `isUpgradeToPaid(null, 'looth2')` *would* return true — the transition is
real, it is just already over by the time anything looks.

So the welcome fires **only for existing members who upgrade**, which is exactly
what the 16/118 numbers show.

**Consequence for this build:** the hook cannot simply be reused as-is. Either
the arbiter must see the transition (capture `oldTier` before the role is
applied, or create the user role-less and let the arbiter apply it), or the step
hangs off its own activation signal. This is a **live member-facing gap in its
own right** — new members currently get no welcome pop-up and no welcome email —
and it is worth raising separately from backlog 19, because fixing it benefits
both options and every future rail.

## 7. Phase 2 (only after Ian rules) — build shape

- Member-facing → **flag, defaulted OFF**, copying `LG_AUTHOR_SOCIALS_ALL_MEMBERS`
  (`platform/mu-plugins/lg-author-socials.php`). OFF must be a proven byte-identical
  no-op **and the OFF state must be gated** — that missing assertion is the standing
  failure class.
- Gate number **comes from keeper**, never minted here, and is numbered from `main`.
- The gate must **read the flag** and assert per state (absent / OFF / ON) so
  flipping the default later needs no gate edit.
- Red-first: break every new assertion before trusting it once.
