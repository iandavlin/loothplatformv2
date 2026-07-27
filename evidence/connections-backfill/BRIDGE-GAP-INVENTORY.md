# What else the cutover skipped — inventory of the late-bridged cohort

**Lane:** connections-backfill · **Date:** 2026-07-27 · **INVENTORY ONLY — nothing here is fixed.**

Ian approved this after the connections restore: the members who were bridged *after* the
cutover backfill ran were invisible to **anything** keyed on `wp_user_bridge`. Connections is
just the table he happened to notice. This enumerates the rest.

## Measurement caveat — read this before quoting a number

**Everything below was measured on dev2's `profile_app`, not on live.** `ssh live-ro` jumps
through dev1, and dev1 stopped answering partway through this work. dev2 is a close proxy but
not identical: it splits **1610 at-cutover / 225 late**, where live splits **1606 / 241**.

- **Structural findings transfer** — which table, which script, which code path. Those are facts
  about the repo.
- **Counts do not.** Every number here needs re-measuring on live before anyone acts on it.

Cohort boundary used throughout: `wp_user_bridge.synced_at < 2026-06-03` = "at cutover".
The late cohort is dominated by a single spike — **191 of 225 bridged on 2026-06-11**.

## The one-line answer

Three domains were already known (connections, messages, location). This pass adds **one new
real defect** — 8 members carrying a mangled profile handle — plus **one latent trap** and
**one thing that cannot be measured at all**. The rest of the profile surface is clean, and
two scary-looking numbers are proven false alarms.

---

## A. NEW — 8 members have the wrong profile handle, and a ghost holds the right one

**This is the finding worth acting on.** All 8 are in the late cohort. **All 8 bridged on
2026-06-11** — the spike day. Zero in the cutover cohort.

| WP | Handle they have | Handle they should have | Display name |
| --- | --- | --- | --- |
| 224 | `patreon_19682448-224` | `franklin-linker-linker-guitars` | Franklin Linker, Linker Guitars LLC |
| 295 | `patreon_55486970-295` | `dave-thurston` | Dave Thurston |
| 560 | `patreon_104272702-560` | `larry-jones` | Larry Jones |
| 688 | `patreon_113262305-688` | `georgios-gerogiannis-rupicapra` | Georgios Gerogiannis, Wood & Voltage |
| 894 | `ianloothgroup-com-894` | `ianloothgroup-com` | Ian Davlin |
| 1198 | `patreon_156820476-1198` | `russell-olmsted-north-coast` | Russell Olmsted, North Coast Guitar Co. |
| 1431 | `patreon_178784349-1431` | `bryan-hutchinson-hobbiest` | Bryan Hutchinson, Hobbiest guitar tech |
| 1768 | `tmcdonough8-1768` | `tmcdonough8` | Tom McDonough |

**What happened.** Each of these members already had a profile row in `profile_app` before they
were bridged. When the real, bridgeable identity was provisioned on 2026-06-11 it collided with
that pre-existing row on the handle, and the provisioner disambiguated by appending the member's
own WP id. The pre-existing row was **never bridged**.

**Why that is user-visible, not cosmetic.** `web/u.php` ghost containment (Ian, 2026-07-13): an
unbridged identity is not a member, so its `/u/<slug>` **404s**. So the good handle 404s while
the real member is served from the ugly one:

- `/u/franklin-linker-linker-guitars` → **404** (held by unbridged row, profile_app user 201)
- `/u/patreon_19682448-224` → renders Franklin's real profile

**Three of the eight are provable duplicates of the very member they block** — the unbridged row
carries a placeholder email `looth-<wp_id>@invalid` naming a WP id that is bridged to a
*different* profile row: pa 1724 → wp 295 (Dave Thurston), pa 1763 → wp 688 (Georgios
Gerogiannis), pa 1798 → wp 1198 (Russell Olmsted). The other five match on human identity rather
than on a placeholder — e.g. profile_app 201 `linkerguitars@gmail.com` vs WP 224
`franklin.linker@gmail.com` — which is strong but is inference, not proof.

There is a **fourth** placeholder-email ghost, pa 1820 `ian-davlin_2` → wp 1886 (bridged to pa
1937). It is the same duplicate-row defect but it is not blocking a suffixed handle, so it is not
in the table above.

**Ghost population overall:** 90 of 1925 `profile_app` rows are unbridged. 87 of the 90 hold a
handle (live slug or parked in `slug_history`). Only these 8 provably block a real member today,
but every one of the 87 is a handle no member can ever claim — `uq_slug_history_lower` makes the
reservation permanent and database-enforced.

**Not fixed, deliberately.** Reassigning a handle means writing to `users.slug` and
`slug_history` on live, and `slug_history` is explicitly designed so a retired handle is *never*
re-issued. Unpicking that safely is its own job with its own rollback, and two of the eight are
test/dev accounts (`ianloothgroup-com` is Ian's dev account, not a member). Ian's call.

## B. LATENT — `email_aliases` is 100% at cutover and 11% late, but nothing reads it

The single largest ratio in the whole sweep: **1610/1610 cutover vs 25/225 late.** Written by
`bin/backfill.php` (one-shot, keyed on the bridge) and by `src/Provision.php` at runtime. Same
bug shape as connections, and a clean total signal.

**It has no functional consequence today.** I grepped the entire repo for `email_aliases` across
`.php`, `.sql` and `.sh`: every hit is a **write** (`Provision.php` ×2, `bin/backfill.php`) or a
delete (`EraseUser.php`'s teardown list) or the `CREATE TABLE`. **Nothing SELECTs from it.** It
is a write-only identity table.

So: do not rush it, but do not forget it. The day anything starts resolving a member by email —
an account-merge, an email-change flow, a support lookup — the late cohort is invisible to it,
and the failure will look like "this member does not exist."

## C. CANNOT BE MEASURED — notifications

`migrate-social-from-bb.php` → `seedNotifications()` seeds one bell per unread DM thread and one
per pending connection. It reads `connections` and `message_recipients`, so it necessarily
skipped the late cohort at cutover, exactly like everything else.

**But there is nothing left to see, and nothing to restore.** `Notifications::prune()` enforces
30-day retention; the oldest surviving row on dev2 is **2026-06-27**, six weeks after cutover.
Every seeded bell — for both cohorts — has long since aged out. The cohort split visible today
(cutover 2.4% vs late 90.7%) is **live dev activity, not migration residue**, and reading it as a
gap would be exactly the "verify the thing, not the thing next to it" trap.

**Conclusion: real at the time, moot now.** No action, and no restore is possible or wanted.

## D. FALSE ALARMS — two numbers that look bad and are not

**`slug_history`: headline 94.2% vs 49.8%, real gap 9 members.** The raw rate is cohort
character. `slug_history` only holds a handle a member has *released*, so it can only have a row
if the member's legacy WP nicename **differs** from their current slug. Controlling for that:

| Cohort | Have a differing legacy nicename | Of those, parked in `slug_history` |
| --- | --- | --- |
| cutover | 1517 | **1516 (99.9%)** |
| late | 120 | **111 (92.5%)** |

93 of the 225 late members have a nicename identical to their slug — nothing to park, so their
absence is correct, not a miss. And the seeding ran **2026-07-17**, weeks after the last late
bridge (2026-06-29), so bridge timing did not exclude anyone. The 9 residual misses are the
handle collisions in section A, not a backfill gap. Different bug, already counted.

**`profile_socials`: still no gap.** Re-confirmed the earlier control. The rate looks ~4.7×
worse (8.5% vs 1.8%), but **91% of the cutover cohort also has zero socials** despite all of them
having BuddyBoss xprofile data. Socials come from a narrow subset of fields; the crude metric
proves nothing in either direction.

## E. CLEAN — measured, no cohort gap

Coverage of the late cohort is at or above the cutover cohort in every one of these:

| Surface | cutover | late |
| --- | --- | --- |
| `users.avatar_url` | 100.0% | **100.0%** |
| `users.at_a_glance` | 1.9% | **2.2%** |
| `profile_skills` | 0.6% | **0.9%** |
| `profile_sections` | 1.0% | **3.1%** |
| `users.profile_layout` | 3.3% | **6.2%** |
| `users.resume_url` | 0.1% | **0.4%** |
| `profile_instruments` | 0.6% | 0.4% |
| `users.banner_url` | 0.7% | 0.4% |

The tables that are near-empty for everyone — `profile_genres`, `profile_scenes`,
`profile_highlights`, `practice_members`, `message_reactions`, `user_mutes`, `chapter_member`,
`chapter_post` — carry 0–5 rows per cohort. **No signal either way**; too sparse to conclude
anything, and no BuddyBoss source exists for most of them.

## F. Already known, restated for completeness

| Domain | Gap | Status |
| --- | --- | --- |
| **connections** | 746 rows / 302 members | Restore written, rehearsed, Ian's to run. See `CANARY-RUNBOOK.md`. |
| **messages** | 16 members | 17 of the late cohort have BuddyBoss message history; 16 have none in the app. Total loss for those 16. Unfixed. |
| **location** | 11 members | 58 have a BuddyBoss pin (xprofile field 96); 11 have no location in the app. Unfixed. |
| **11 members not in `wp_users` at all** | — | profile-app-native (Patreon-provisioned). Nothing to migrate; cannot be audited against BuddyBoss either. |

## G. Recommended order, if Ian wants any of it

1. **connections** — written and rehearsed, only needs running.
2. **the 8 handles (section A)** — smallest, most visible, and the only one a member could
   notice unaided. Needs its own design decision because `slug_history` is deliberately
   write-once.
3. **messages (16)** — a total loss of history for those members, but small.
4. **location (11)** — cosmetic by comparison.
5. **`email_aliases`** — no rush, but block any future email-resolution feature on it.

Nothing in this document has been changed on live or on dev2. Every measurement was a read.
