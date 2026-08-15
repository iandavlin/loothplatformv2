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

## 5. Phase 2 (only after Ian rules) — build shape

- Member-facing → **flag, defaulted OFF**, copying `LG_AUTHOR_SOCIALS_ALL_MEMBERS`
  (`platform/mu-plugins/lg-author-socials.php`). OFF must be a proven byte-identical
  no-op **and the OFF state must be gated** — that missing assertion is the standing
  failure class.
- Gate number **comes from keeper**, never minted here, and is numbered from `main`.
- The gate must **read the flag** and assert per state (absent / OFF / ON) so
  flipping the default later needs no gate edit.
- Red-first: break every new assertion before trusting it once.
