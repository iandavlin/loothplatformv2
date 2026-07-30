# Author social links — drift audit (P0, member-reported)

**Lane:** profile-social-links · **Date:** 2026-07-30 · **Reporter:** Massimiliano
Monterosso (7/1 email, via Ian)

## The report

1. He removed his Linktree link from his profile — it **still shows on the events he
   posted**.
2. The Facebook link on his **video post** differs from his current profile.

Both reproduce. Both are the same root cause.

## Root cause — NOT a creation-time snapshot

The charter's hypothesis was that post/event surfaces bake a snapshot of the author's
links at creation. **That is not what happens.** Every WP render reads user-meta
*live*:

- `lg-layout-v2/blocks/post-header/render.php:217` — `get_user_meta($author_id, $slot['meta_key'])`
- `lg-layout-v2/blocks/post-footer/render.php:189` — same keys, editor data-attrs

The renders are live. They are live against **the wrong store**.

There are **two disjoint social-link stores**:

| | store | written by | read by |
|---|---|---|---|
| **A. legacy** | WP `wp_usermeta` ACF keys `author_website/instagram/facebook/youtube/linktree` | `lg-layout-v2/src/EditorRest.php:98` (in-post "Manage social icons" modal) | post + event bylines |
| **B. truth** | Postgres `profile_socials` (profile-app) | `profile-app/api/v0/me-socials.php` (the profile editor the member actually uses) | profile page, member directory |

`profile-app/bin/migrate-socials.php` seeded **B** from **A** once, on 2026-05-29. After
that **nothing syncs, in either direction.** `platform/mu-plugins/profile-sync.php`
forwards *email changes only* (`profile_update` hook, line 130) — it has never carried
socials.

So the member edits **B**; his posts and events render **A**; **A** has been frozen
since May.

Note the charter's suggested fix — "carry social changes on the existing
`profile_update` pipeline" — cannot work as stated: `profile_update` is a *WP* hook,
and the member's edit never touches WP. The needed direction is profile-app → WP,
which has no receiver today.

## Evidence — user 717 / profile-app 629 (maxmonte@gmail.com)

WP `wp_usermeta` (what posts and events render):

```
author_website     https://www.maxmonte.com/
author_instagram   https://www.instagram.com/maxmonte_guitars/
author_facebook    https://linktr.ee/facebook.com/maxmonteguitars/   ← corrupt
author_youtube     https://www.youtube.com/@maxmonteguitars
author_linktree    https://linktr.ee/maxmonte_guitars?               ← he removed this
```

Postgres `profile_socials` (his actual current profile — no linktree row):

```
web        https://www.maxmonte.com/
instagram  maxmonte_guitars/
facebook   facebook.com/maxmonteguitars/
youtube    maxmonteguitars
```

Confirmed in the **live rendered HTML**, anonymous view of his real video post
(`/video/tour-of-the-cremona-violin-museum-w-max-monterosso/`):

```
linktr.ee/maxmonte_guitars?                        ← the removed Linktree, still rendering
href="https://linktr.ee/facebook.com/maxmonteguitars/"  ← the wrong Facebook link
```

His events are `post_type=event`, his videos `post-type-videos`; both synthesize a
layout headed by `post-header` (`lg-layout-v2/src/Plugin.php:311` events, `:364`
video) — the same block, so one fix covers both surfaces.

## Secondary defect — the host-blind stripper (own bug, own fix)

`profile-app/api/v0/me-socials.php:73`

```php
$value = preg_replace('#^https?://[^/]+/#i', '', $value);   // strips ANY host
```

It strips whatever host was pasted, without checking it belongs to the kind being
saved. `https://linktr.ee/facebook.com/maxmonteguitars/` saved under `facebook`
becomes the handle `facebook.com/maxmonteguitars/`. `looth_social_url()`
(`profile-app/web/_render_blocks.php:552`) then re-prefixes it:

```
https://facebook.com/facebook.com/maxmonteguitars/
```

So **his Facebook link is broken on the profile too**, differently from the post. A
store-unification fix alone would still leave him a dead link — this needs its own fix
(see plan §2/§3).

## Surface audit

**Reads store A (stale) — affected**

| surface | file:line |
|---|---|
| Post byline social rail (all v2 post types) | `lg-layout-v2/blocks/post-header/render.php:217` |
| Event byline (events synthesize `post-header`) | `lg-layout-v2/src/Plugin.php:311` → same:217 |
| Post footer author card | `lg-layout-v2/blocks/post-footer/render.php:189` |
| Sponsor-variant brand rail (`brand_*` ACF, separate field set) | `post-header/render.php:296-308` |
| archive-poc standalone blob — **bakes A into a real snapshot** | `archive-poc/bin/materializer.php:57-61` |
| archive-poc sync payload `socials{}` | `platform/mu-plugins/archive-poc-sync.php:140` |

**Reads store B (truth) — correct**

| surface | file:line |
|---|---|
| Profile page links block | `profile-app/web/_render_blocks.php:571` |
| Member directory | `profile-app/api/v0/directory-members.php:421` |

Discussion/forum bylines carry no social rail — `events/lib/weekly-query.php:231` masks
author identity for anon and renders name + profile link only. Not affected.

## Drift scope across all members (live, 2026-07-30)

24 users have any `author_*` meta; 153 have profile socials; 22 are comparable.
Compared as *rendered link targets* (both sides canonicalised through
`looth_social_url()` semantics, scheme/`www.`/trailing-slash insensitive):

| class | rows | users | meaning |
|---|---|---|---|
| **A. Phantom** | 4 | 2 | renders on posts/events, **absent from the profile** — the reported bug |
| **B. Divergent** | 7 | 5 | both set, different target |
| **C. Missing** | 3 | 3 | in the profile, never reaches posts/events |
| **D. Agree** | 49 | — | — |

Phantom rows (these are live wrong links today):

```
brett@contriverguitars.com  facebook   https://www.facebook.com/ContriverGuitars/#
brett@contriverguitars.com  instagram  https://www.instagram.com/contriverguitars/
brett@contriverguitars.com  youtube    https://www.youtube.com/@ContriverGuitars
maxmonte@gmail.com          linktree   https://linktr.ee/maxmonte_guitars?
```

Divergent rows include one where **both stores are wrong** (maxmonte facebook, above)
and one member typo carried faithfully (`brandonluthier` → `https://yourube.com/...`,
his own profile entry — member data, not our defect).

## Visibility — the constraint that shapes the fix

`profile_socials` is gated by a per-member block visibility
(`profile_sections.key='socials'`). Live distribution:

```
(unset -> defaults to 'members')  150
public                              2
members                             1
```

**150 of 153 members have never set it**, so it defaults to `members`. A naive
"render live from the profile store, honouring block visibility" would therefore
**blank the author social rail for every logged-out reader** on every post and event —
a large regression on public/SEO pages, where these links render publicly today.

The byline is a *publishing* surface (links an author attaches to work they chose to
publish), not the profile page. The default-`members` value is an unset default, not a
member decision. But extending the byline to all 153 members would newly publish
handles for ~129 members who only ever entered them in the profile store — that is a
privacy expansion, and it is Ian's call, not this lane's.

**Chosen default: no new exposure.** See plan §1.

## Fix plan

**§1 — Byline reads store B live, per-user gated.**
Resolver mu-plugin maps WP user → profile-app user (via the stable `_looth_uuid`
usermeta ↔ `users.uuid`, email as fallback), reads `profile_socials`, caches briefly.
`post-header` / `post-footer` gain a filter over the resolved link set.

Authority rule — *per user, not per kind*: if the member has **any** `profile_socials`
row, that set is authoritative **and complete**; absent kinds render nothing. This is
what kills the 4 phantom rows (both phantom users have profile rows). Members with
zero profile rows keep the ACF fallback, so nothing goes dark.

Exposure gate (default): resolve only for members **who already have `author_*` meta**
— the 24 who already publish socials on their bylines today. Net new exposure: 3 rows
(class C) for members already publishing. A one-line filter flips this to all members
once Ian approves.

`email`/`phone` kinds are never rendered on a byline.

**§2 — Host-aware URL composition.** `looth_social_url()` treats a value that already
looks like `host.tld/path` as absolute (prepend scheme only), so
`facebook.com/maxmonteguitars/` → `https://facebook.com/maxmonteguitars/` instead of
doubling. Fixes the broken link for every already-corrupted row without a data write.

**§3 — Stop the corruption at the source.** `me-socials.php:73` strips a pasted host
only when it belongs to the kind being saved; otherwise the value is kept whole.

**§4 — archive-poc materializer** bakes the *resolved* links, not raw `author_*`, so
the standalone blob stops being a second-order snapshot.

## Backfill

**No backfill is required for the render path.** Once §1 lands, the byline reads the
profile store live and the 4 phantom + 7 divergent rows resolve themselves; §2 repairs
the corrupt-value class without touching data.

Optional hygiene, **Ian's to run** (live writes are his), and safe to defer
indefinitely:

- Delete the 24 users' `author_*` usermeta rows once §1 is verified, so the fallback
  path can never resurrect a stale value. Low value while the authority rule holds.
- Repair `profile_socials` for maxmonte `facebook` (`facebook.com/maxmonteguitars/` →
  `maxmonteguitars`) — cosmetic once §2 lands, since §2 makes the stored value render
  correctly either way.

Reproduction script: `tools/audit/social-links-drift.py`.
