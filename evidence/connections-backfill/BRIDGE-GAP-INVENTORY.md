# What else the cutover skipped — inventory of the late-bridged cohort

**Lane:** connections-backfill · **First written 2026-07-27 (dev2) · Re-measured against LIVE 2026-07-28**
**INVENTORY ONLY — nothing here is fixed. Every measurement is a read.**

Ian approved this after the connections restore: members bridged *after* the cutover backfill
ran were invisible to **anything** keyed on `wp_user_bridge`. Connections is just the table he
happened to notice. This enumerates the rest.

## Provenance — which box each number came from

**Every figure in sections A–F is from LIVE** (`ssh live-ro`, `psql -h 127.0.0.1 -U looth_ro -d
profile_app`, plus live MySQL `looth_import` for the BuddyBoss controls). Live read-only was
re-proved on 2026-07-28: `CREATE TABLE` as `looth_ro` returns *permission denied for schema
public*.

The first version of this document was measured on **dev2** because dev1 was down. **One
headline finding did not survive contact with live and has been retracted — see section A.**
Where dev2 and live disagree, both numbers are shown, because the disagreement is the lesson.

Cohort boundary: `wp_user_bridge.synced_at < 2026-06-03` = "at cutover".

| | live | dev2 |
| --- | --- | --- |
| at cutover | **1606** | 1610 |
| late | **241** | 225 |
| bridged on the 2026-06-11 spike | **181** | 191 |
| `profile_app` tables | **33** | 36 (`chapter`, `chapter_member`, `chapter_post` are dev2-only) |

## The one-line answer

On live, the cutover left **three real gaps** — connections (already fixed and awaiting Ian),
messages (16 members), location (11 members) — plus **one latent trap** (`email_aliases`). Three
things that looked like gaps are **not**. One claimed defect was a **dev2 artifact and is
retracted**. One item is **unmeasurable** and one is **ungranted**. The last uncontrolled item,
`profiles`, was **controlled on 2026-07-28 and is not a gap** (F.1) — so every surface in this
sweep now carries a verdict, and the only thing still owed is one read grant (F.2).

---

## A. RETRACTED — "8 members have the wrong profile handle"

**This finding was wrong. On live the count is ZERO.** It was measured on dev2 and is a dev2-only
artifact. It should not have been promoted to a headline, and it was briefly circulated to the
slug-backfill lane as a real population before this correction.

**What dev2 showed.** Each of 8 members had *two* `profile_app` rows — an unbridged "ghost"
holding the human-readable handle, and a bridged duplicate carrying a `-<wp_user_id>` suffix.
With `web/u.php` ghost containment 404ing the unbridged holder, that read as: the good handle
404s while the member is served from a mangled one.

**What live shows.** One row per member, bridged, handle clean:

| WP | profile_app id | live slug | bridged |
| --- | --- | --- | --- |
| 224 | 201 | `patreon_19682448` | 2026-07-14 |
| 295 | 1724 | `patreon_55486970` | 2026-07-14 |
| 560 | 491 | `patreon_104272702` | 2026-07-14 |
| 688 | 1763 | `patreon_113262305` | 2026-07-14 |
| 1198 | 1798 | `patreon_156820476` | 2026-07-14 |
| 1431 | 1265 | `patreon_178784349` | 2026-07-14 |
| 1768 | 1585 | `tmcdonough8` | 2026-07-14 |

In every case the `profile_app` id that was the *ghost* on dev2 is the *real bridged member* on
live. WP 894 (`ianloothgroup-com`) does not exist on live at all — that was Ian's dev account,
dev2 noise that reached a live-sounding claim.

The detector returns zero rows on live:

```sql
SELECT b.wp_user_id, u.slug FROM users u
JOIN wp_user_bridge b ON b.user_id = u.id
WHERE u.slug ~ ('-' || b.wp_user_id || '$');
```

*Practical note:* pipe SQL to `psql -f -` over ssh via **stdin**. Passing it with `-c` lets ssh's
own quoting eat the regex `$`, which produced a false empty result before it was checked properly.

**Ghosts are real on live but smaller, and block nobody:**

| | live | dev2 |
| --- | --- | --- |
| unbridged ghost rows | **29** | 90 |
| ghosts holding a slug | **26** | 87 |
| `looth-<n>@invalid` placeholder ghosts | **1** | 4 |
| duplicate `wp_user_id` in bridge | **0** | — |

Those 26 handles are permanently unclaimable (`uq_slug_history_lower` makes it a database
invariant), so they remain a legitimate design input for the slug-backfill lane. But **no live
member is currently sitting on a mangled URL**, and this is a stranded-handle design question,
not a member-facing defect.

**Why it was wrong, recorded so it is not repeated:** dev2 is the box that gets reprovisioned, so
duplicate-identity rows accumulate there and nowhere else. A defect that appears *only* on dev2
should be treated as suspect before it is promoted. A caveat in the provenance section does not
cancel a headline.

## B. REAL — messages: bigger and a different shape than this section first said

**Scoped in full on 2026-07-28: see `MESSAGES-GAP-INVENTORY.md`.** Two corrections to what this
section used to claim.

**It is 17, not 16.** All 17 late members with BuddyBoss message history have **none** of it in
the app. The "1" that looked intact was a member who has used the app *since* cutover — their rows
carry `bp_message_id IS NULL`, so it is post-cutover activity, not surviving history. Migrated
messages for late members: **0**.

**And it is not only their loss.** Unlike connections, this defect leaked outside the cohort. The
migration inserts `message_threads` with **no uuid lookup**, but `messages` and
`message_recipients` both resolve through the bridge — so the thread shell landed for everyone
while its contents did not. **24 threads are affected, 9 render completely empty and 11 partial,
and 13 members who were never in the late cohort can open them and see 73% of the conversation
missing.** A visibly wrong artifact rather than an absence.

Controls: zero `is_deleted` messages, so nothing was legitimately skipped. Unfixed, and a fix
would need an Ian ruling — 2 members would get an unread badge unless it is applied silently.

## C. REAL — location: 11 members

**Live: 58 of the 241 have a BuddyBoss location pin (xprofile field 96); 11 of those have no
location in the app.** Exact match to the original figure. Unfixed.

## D. LATENT — `email_aliases` is 100% at cutover and 19.5% late, and nothing reads it

**Live: 1606/1606 cutover vs 47/241 late.** The largest ratio in the sweep. (dev2 read 11%, so
live is *less* severe.) Written by `bin/backfill.php` (one-shot, bridge-keyed) and by
`src/Provision.php` at runtime — same bug shape as connections.

**No functional consequence today.** Every reference across the repo in `.php`, `.sql` and `.sh`
is a write (`Provision.php` ×2, `bin/backfill.php`), a delete (`EraseUser.php`'s teardown list),
or the `CREATE TABLE`. **Nothing SELECTs from it.**

Do not rush it; do not forget it. The day anything resolves a member by email — account merge,
email change, support lookup — the late cohort is invisible and it will present as "this member
does not exist."

## E. NOT GAPS — measured on live, with controls

**`profile_socials` — false alarm, control re-confirmed on live.** The late cohort's 97.5%
zero-socials rate looks damning until you check the other cohort: **1463 of 1606 cutover members
(91.1%) also have zero socials**, despite all of them having BuddyBoss xprofile data. Socials
come from a narrow subset of fields; the crude rate proves nothing either way.

**`notifications` — real at the time, moot now, unmeasurable in principle.**
`seedNotifications()` reads `connections` and `message_recipients`, so it necessarily skipped the
cohort at cutover. But `Notifications::prune()` enforces 30-day retention and **live's oldest
surviving row is 2026-06-27** (604 rows total) — six weeks after cutover. Every seeded bell, for
*both* cohorts, has aged out. The split visible today (cutover 7.9% vs late 92.5%, inverted) is
current activity, not migration residue. Nothing to restore.

**`profile_genres` — dev2 was wrong, live is clean.** dev2 flagged it (5/1610 vs 0/225); on live
the late cohort is **higher** (1.2% vs 0.4%).

**Clean on live — late cohort equal or higher:**

| Surface | cutover | late |
| --- | --- | --- |
| `users.avatar_url` | 100.0% | **100.0%** |
| `users.profile_layout` | 5.0% | **10.0%** |
| `profile_sections` | 1.8% | **3.7%** |
| `profile_skills` | 0.9% | **2.5%** |
| `profile_instruments` | 0.9% | **2.5%** |
| `users.at_a_glance` | 2.4% | **2.9%** |
| `users.resume_url` | 0.1% | **0.4%** |
| `connections` | 99.4% | 95.9% |

Tables near-empty for everyone — `profile_highlights`, `profile_scenes`, `practice_members`,
`message_reactions`, `user_mutes` — carry 0–3 rows per cohort. **No signal**, not a pass.
`users.banner_url` (16 vs 1) is too small to read either way.

## F. OPEN ITEMS — do not let these be quoted as settled

**1. `profiles` — CONTROLLED 2026-07-28. NOT A GAP. Closed.**

The control has now been run on live, and it kills this the same way it killed the socials scare.

`profiles` is **not a bridge-keyed migration table at all** — it records a *profile claim*. Two
things write it: the one-shot `sql/0004_active_semantics_and_backfill.sql`, whose predicate is
`users.location_text <> ''` (**not** `wp_user_bridge`), and `Profile::claim()` at runtime. So the
cohort split is not evidence of a skipped backfill; it is evidence of who got a free claim.

Controlled for 0004's actual predicate:

| cohort | has `location_text` | members | with `profiles` row | |
| --- | --- | --- | --- | --- |
| cutover | no | 927 | **0** | 0.0% |
| cutover | yes | 679 | 659 | 97.1% |
| late | no | 185 | 31 | 16.8% |
| late | yes | 56 | 10 | 17.9% |

**Cutover members without a location got 0.0%** — the backfill is the entire mechanism behind
their headline number, and `claimed_via` proves it: cutover is **655 `backfill_location`** + 4
real claims; late is **36 `onboard`** + 4 + 1 null. The late cohort's claims are overwhelmingly
*genuine member actions*; the cutover cohort's are overwhelmingly synthetic. The 41% was never a
health baseline — 58% of the cutover cohort has never claimed a profile either.

**Nothing is recoverable because nothing is lost.** `profiles` carries no member content:
`user_id`, `claimed_at`, `updated_at`, `section_order` (defaults `{}`), `claimed_via`. A missing
row means "has not claimed yet", not "lost their profile".

**The one real residue, and it is cosmetic:** 46 late members have a `location_text` but no claim,
so they will see a first-visit interstitial that an identically-placed cutover member was spared
by 0004. That is an inconsistency in a courtesy, not a defect — and arguably correct, since they
are newer members who *should* onboard. **No action recommended.**

**2. `slug_history` cannot be read on live.** `looth_ro` has SELECT on 32 of live's 33
`profile_app` tables; `slug_history` is the exception — it was created 2026-07-12, after the RO
grants were set. So the dev2-era analysis of it (headline 94.2% vs 49.8%, real gap 9, cause =
handle collision not backfill) is **unverified on live** and, given section A, should be assumed
dev2-specific until re-measured. If it matters, the ask for Ian is one read grant:

```sql
GRANT SELECT ON public.slug_history TO looth_ro;
```

## G. Already fixed / in flight

| Domain | Status |
| --- | --- |
| **connections** — 746 rows / 302 members | Restore written, rehearsed, Ian's to run. `CANARY-RUNBOOK.md`. |
| **11 members not in `wp_users` at all** | profile-app-native (Patreon-provisioned). Nothing to migrate; cannot be audited against BuddyBoss either. |

## H. Recommended order, if Ian wants any of it

1. **connections** — written and rehearsed, only needs running.
2. **messages (16)** — a total loss of history for those members.
3. **location (11)** — cosmetic by comparison.
4. **`email_aliases`** — no rush, but gate any future email-resolution feature on it.
5. ~~control `profiles`~~ — **done 2026-07-28, not a gap.** Nothing to do.

The 26 stranded ghost handles belong to the **slug-backfill** lane as a design input, not to this
one.

## Re-running this sweep

`cohort-coverage.sql` in this directory. Against live, drop the three dev2-only tables first:

```bash
grep -v "chapter_member\|chapter_post\|slug_history" cohort-coverage.sql \
  | ssh live-ro "psql -h 127.0.0.1 -U looth_ro -d profile_app -f -"
```

Nothing in this document has been changed on live or on dev2.
