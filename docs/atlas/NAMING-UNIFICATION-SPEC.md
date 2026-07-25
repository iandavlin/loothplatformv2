# NAMING-UNIFICATION-SPEC — one name, one handle, one URL

*mentions/naming lane, 2026-07-25. Ian ruling 7/25 (supersedes the 7/25-morning
reversal and amends 7/19): @handle + /u/ URL auto-derive from the CLEANED profile
name, always; display names become UNIQUE platform-wide (numeric suffixes at
collision); Patreon provisioning must mint human names, never `patreon_<id>` junk.
"Make it work like a human would like it to work." All counts below are REAL DATA
from dev2 (profile_app PG + looth_import WP), queried read-only 2026-07-25.*

## 1. The rules

1. **Derivation**: `handle = clean(display_name)` where `clean()` is the existing
   canonical slugify (`Provision::slugify`, profile-app/src/Provision.php:202 —
   lowercase, `[^a-z0-9]+`→`-`, trim `-`). `/u/<handle>` is the profile URL. The
   member-facing handle editor **retires** (me-slug → read-only display or removed —
   Ian's earlier read-only lean, 2026-07-19).
2. **Uniqueness**: display names unique platform-wide **on the cleaned form** (so
   name↔handle stays 1:1). Collision at generation → numeric suffix: `steve`,
   `steve1`, `steve2` (Ian's literal scheme). Uniqueness domain = active users'
   cleaned names ∪ live slugs ∪ `slug_history` (history rows 301 elsewhere and
   must not be re-issued — `uq_slug_history_lower` already enforces).
3. **Rename**: re-derives the handle under the same uniqueness (the unconditional
   `maybeSyncSlugFromName` already does this, :244); old handle parks in
   `slug_history` → `/u/` 301s (already built, u.php step 4). NEW: the display-name
   write path (`me-name`) must itself enforce name-uniqueness (suffix or reject —
   Ian gates the UX).
4. **Empty-clean fallback** (CJK etc — real cases below): when `clean(name)` is
   empty → `clean(email local-part, +tag stripped)` → literal `member` + suffix.
   Display name itself stays as the member wrote it (we never latinize the shown
   name, only the handle).

## 2. Patreon provision minting (the junk killer)

Order, per Ian: **Patreon full_name → Patreon vanity → email local-part** (strip
`+tags`), then `clean()` + uniqueness suffix.

Code truth today:
- OAuth onboard already fetches `full_name` (lg-patreon-onboard.php:1108 —
  `fields[user]=email,full_name,image_url`); **`vanity` is NOT requested** — add it
  to the fields param.
- The poller's `UserLifecycle::provision` mint (src/UserLifecycle.php:231) already
  derives `uniqueUsername(displayName ?: email)` — compliant once callers pass the
  Patreon-derived name.
- The junk source is the skeleton path (`patreon_<id>` logins). **DEPENDENCY FLAG:**
  the onboard *adopts* skeletons by `get_user_by('login', 'patreon_<id>')`
  (lg-patreon-onboard.php:1305). If new accounts stop minting junk logins, that
  adoption lookup must key on the `patreon_user_id` usermeta instead — required
  change, same commit.

## 3. Real-data counts (dev2, 2026-07-25 — the report-back Ian asked for)

Population: **1,925 users / 1,851 active / 1,820 active+bridged members.**
1,469 of 1,851 already have `slug == clean(name)` — **79% of the platform is a
no-op** under the new rule.

| Backfill | Count | Detail |
|---|---|---|
| **B1 — display-name dedupe** | **64 groups · 136 members · 72 need suffixes** (largest group 4) | e.g. Paul×4, Chris/Dave/David/Mark/Michael×3. **Nuance:** several groups are the SAME human holding multiple accounts — Ian Davlin×3 (`iandavlin`, `ianloothgroup-com`, `ianloothgroup-com-894`), Aaron Lucas×2, Alessio Guarnieri×2, Adam Pinner×2. Blind suffixing "works" but multi-account humans may deserve merge/archive instead — Ian gates per-group UX. 69 members' cleaned names currently equal ANOTHER member's slug (the same -2/-3 sets seen from the slug side). |
| **B2 — profile_app junk slugs** | **5** (4 CJK-named: 순간의미학, 祁磊, 博祥 游, ビック — `clean()` empties; 1 regex-miss: `patreon54921530`, no separator, Doug Lawrence) | Fix: email-local-part fallback rule + widen the junk regex to `patreon[_-]?\d+` (Provision::isPatreonJunk:210 already matches — the old one-time backfill's pattern missed it, not the code). |
| **B3 — WP junk nicenames** | **1,634** junk `user_nicename` (of 1,824 wp_users) | **1,630 (99.8%) have a real display name** to derive from; the 4 without are the CJK members (email fallback). Nicename drives WP author URLs + native-BB mention parsing, so re-minting materially helps the mention fallback path. Unique-per-wp_users dedup at apply. |
| **B4 — WP junk user_logins** | 1,635 | **Recommend LEAVE AS-IS**: login is an invisible auth artifact, members authenticate via Patreon OAuth/email, and the skeleton-adoption flow keys on it today (§2 dependency). Flag for Ian; revisit only after the adoption lookup moves to usermeta. |

Suffix-scheme conflict to rule on: existing dedup style is `-2/-3` (paul-2); Ian's
scheme is `steve1/steve2`. The backfill can emit either; **mixing both long-term is
the one wrong answer** — pick one (recommend Ian's `name1` for new suffixes and
migrate the ~69 existing `-N` handles in B1's pass so the platform speaks one
scheme; each migrated handle parks in slug_history so old links 301).

## 4. What already exists (don't rebuild)

Unconditional rename→handle sync + never-clobber dedup + slug_history parking
(Provision.php:244ff); the 301 read (u.php → `Slug::currentSlugForRetired`);
DB uniqueness rails (`users_slug_key`, `users_slug_lower_key`,
`uq_slug_history_lower`); junk detection (`isPatreonJunk`); mention immunity —
mentions anchor to uuid, never text (2026-07-19 keeper ruling), so ALL of this
renaming breaks zero past mentions. The 2026-07-12 slug_history migration is
**still owed on LIVE** (keeper 7/25 reminder) and is a prerequisite for any of this
touching live.

## 5. Apply plan (nothing applied yet — this doc is spec + counts only)

1. Ian rules on: suffix display style (`Steve1` as the literal shown name?),
   migrate-vs-keep existing `-N` handles, B4 login policy, per-group treatment of
   same-human dup groups (suffix vs merge candidates list).
2. Forward code (one lane window): uniqueness in `me-name` + provision; Patreon
   field order + `vanity` fetch; adoption-lookup usermeta move; retire handle
   editor UI; empty-clean fallback.
3. Backfills on dev2 with the identity-reconcile discipline: journal TSV + exact
   revert.sql per apply, post-verify baked in, Ian eyeballs the B1 rename list
   BEFORE apply (136 rows is human-reviewable).
4. Live: slug_history migration first, then repeat 2→3.

*Verification: naming changes ride the existing gates — mention pipeline e2e
(tools/e2e-webkit) + /u/ 301 spot-checks; no browser-heavy work needed for the
backfills themselves (DB + loopback curls).*
