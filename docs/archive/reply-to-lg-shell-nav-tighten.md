# Coordinator → lg-shell: tighten the shell nav — wrong/missing/broken links

The shell's structurally good (active_nav suppression works, account dropdown
built with the §3k items, header consumed params are right). But the **nav
links are wrong, broken, or missing** across surfaces — "menus aren't there or
go to the wrong spot." Audit findings + fixes:

## Confirmed bugs (fix regardless of any URL decision)

1. **Events → `/calendar/` is the OLD WP page.** Repoint to **`/events/`** — the
   new standalone landing the events lane just shipped. `/calendar/` is page 2773;
   `/events/` is the real surface now.
2. **About → `/about/` bounces to `wp-login`** (`bp-auth … bpnoaccess` — it's
   BuddyBoss-gated). So a logged-out visitor clicking "About" lands on a login
   wall — broken first experience. Either point About at a **real public page**,
   or drop it from the nav until one exists. Decide + fix.
3. **Directory/Members is MISSING from the nav entirely.** The member directory
   (`/directory/members`, 200, profile-app) is a major surface with no nav entry.
   **Add it** (label "Members" or "Directory").
4. **Home/logo double-redirects.** The logo links `/` → which chains
   `/` → `/archive-poc/` → `/front-page/` (two 302s). Collapse to **one** canonical
   front URL (point the logo straight at the final destination).

## Launch-URL reconciliation (needs a coordinator/Ian decision — flag, don't guess)

The nav mixes POC and launch URLs. Current resolution:
| Nav item | Points at | Status |
|---|---|---|
| Archive | `/archive/` | ✅ 200 (canonical; `/archive-poc/`→301→`/front-page/`) |
| Forum | `/forums-poc/` | 200 but **POC URL** (`/forum/` is old-BB→login) |
| Events | `/calendar/` | ❌ old page → `/events/` |
| About | `/about/` | ❌ →login |

**Open: what are the canonical launch URLs?** `/forums-poc/` and `/archive-poc/`
are dev/POC names. At launch should the forum be `/forum/`, archive `/archive/`,
etc.? This affects nginx routes at cutover, so **surface it to coordinator** —
don't hardcode POC URLs into the launch nav. (Archive already uses the clean
`/archive/`; forum still on `/forums-poc/`.)

## Verify (likely fine, confirm)

- **active_nav** — suppression model works (hides the current page's item). Confirm
  every surface passes the right key (`archive`/`forum`/`events`/`about`/…) and
  that "suppress the current item" is the intended behavior vs. highlight. events
  passes `active_nav:'events'`; archive-poc/bb-mirror should pass theirs.
- **Account dropdown** — §3k items are wired (Edit Profile, Manage Subscription,
  Membership Guide, My Gifts, Gift Memberships, Redeem, Refund, Affiliate, Sign
  out). Confirm the dropdown JS toggles, `logout_url` resolves, and the
  affiliate-conditional (new-1 lean: always-show) is final.
- **Cross-surface consistency** — every surface (archive-poc, bb-mirror, events,
  profile-app, membership) renders the nav + passes `active_nav` + `logout_url`.
  Spot-check each renders the same chrome.

## §0
All in looth-platform; `/srv/lg-shared/site-header.php` is your file (source =
`lg-shared/`). Edit in repo, commit at end + push, deploy to `/srv/lg-shared/`.

— coordinator
