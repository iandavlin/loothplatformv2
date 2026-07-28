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

**The sweep is complete as of 2026-07-28 — every surface carries a verdict.**

On live the cutover left **three real gaps**, and they are of very different severity:

| gap | scale | shape |
| --- | --- | --- |
| **connections** | 271 rows / 164 members, plus 81 wrong-status | fix written, rehearsed, Ian's to run |
| **messages** | **17** members, **24 threads**, **13 other members affected** | **worst — a visibly wrong artifact, not an absence** |
| **location** | 11 members | closed batch; nothing lost, only unpropagated |

Plus **one latent trap** (`email_aliases`, 202 users) whose failure mode is proven benign.
**Three things that looked like gaps are not** (`profile_socials`, `profile_genres`,
`notifications`), and a fourth — `profiles` — was **controlled on 2026-07-28 and is not a gap**
either. One claimed defect was a **dev2 artifact and is retracted**.

**Two findings recur across the sweep and are worth carrying:**

1. **Most of these are closed batches, not live bugs.** Location stops at 2026-06-13;
   `email_aliases` is 181-of-194 from the single 2026-06-11 bulk run; the connections
   wrong-status rows were five people re-requesting by hand. The runtime paths work. Only the
   messages gap is unbounded in the sense that it keeps rendering wrong every time someone opens
   one of those threads.
2. **Raw cohort ratios lied twice.** `profile_socials` and `profiles` both looked damning until
   controlled, and the messages figure of "16" was a member's *post-cutover* activity mistaken
   for surviving history. Control for what the number means before promoting it.

The only thing still owed to this document is one read grant (F.2).

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

## C. REAL — location: 11 members, and it is a CLOSED set

**Live: 58 of the 241 have a BuddyBoss location string (xprofile field 96); 47 have it in the app
and 11 do not.** Scoped in full 2026-07-28.

**Root cause, precisely.** `bin/backfill.php` is the one-shot that reads field 96; the runtime
path that provisioned the late cohort, `src/Provision.php`, inserts `users` with **no
`location_text` at all** (its INSERT is uuid / emails / display_name / avatar_url). Anyone
provisioned after the one-shot therefore starts with no location, and only a later run of
`bin/snapshot-location-from-bb.php` can give them one.

**It is not ongoing — this is the part that matters.** All 11 were bridged on **2026-06-11 (10)
and 2026-06-13 (1)**. Members bridged from 2026-06-13 through 2026-07-14 all have their location.
So whatever backfilled the others ran after that batch and has covered everyone since; **nothing
bridged after 2026-06-13 is affected.** A closed historical set, not a leak — the same
cleanup-versus-live-bug distinction that mattered for the connections re-request defect.

*Not established:* why coverage on 2026-06-11 is partial (some of that day's members have a
location, these 10 do not). The idempotent skip in `snapshot-location-from-bb.php` is the obvious
suspect but it was not proven, and it does not change the conclusion.

**Cosmetic by comparison with messages.** The source string still exists in BuddyBoss, so nothing
is *lost* — it is unpropagated. All 47 who have it are geocoded, so a fix would also want the
geocoder pass. Unfixed; no Ian ruling needed for a decision this small, but it is not mine to run
on live either.

**Related, and useful to the slug-backfill lane:** `Provision.php` leaves `slug` NULL for the same
structural reason (its own comment says only the one-time xprofile backfill seeded slugs). On live
today, however, **zero users in either cohort have a NULL slug** — so that particular consequence
has already been resolved by something, and it is one more reason the dev2-era slug findings in
section A should not be used to size work.

## D. LATENT — `email_aliases`: ONE bulk run caused it, and the failure mode is benign

**Live: 202 of 1876 users have no alias for their own primary email; 194 are late cohort, 0 are
cutover.** Scoped in full 2026-07-28. Two findings change how this should be treated.

**It is not a defect in the provisioning path — it is one batch.** `Provision.php` writes the
alias in the *same transaction* as the `wp_user_bridge` upsert, so the runtime path cannot produce
this. Coverage by bridge date proves it:

| bridged | members | with alias |
| --- | --- | --- |
| **2026-06-11 (the bulk spike)** | **181** | **0 — 0.0%** |
| 06-13 | 9 | 1 |
| 07-14 | 7 | 4 |
| every other date, 24 of them | 1 – 4 each | **100%** |

**181 of the 194 come from the single bulk bridge run on 2026-06-11**, which wrote
`wp_user_bridge` without going through `Provision.php`. Every date where members arrived by the
normal path is 100%. Same conclusion as section C: a closed historical batch, not a leak.

**The failure mode is "not found", never "wrong member found".** This was worth checking, because
a mis-pointed alias would be far worse than a missing one — it would resolve a support lookup or
an account merge to somebody else's account. Measured on live:

```
aliases whose email <> that user's primary_email            0
users whose primary_email alias is owned by ANOTHER user     0
```

Both zero. So the latent risk is bounded at invisibility, and there is no silent
mis-identification hiding in the table.

**Still nothing reads it.** Re-verified repo-wide across *all* file types, not just `.php`/`.sql`/
`.sh`: every code reference is a write (`Provision.php` ×2, `bin/backfill.php`), a delete
(`EraseUser.php` teardown), or the `CREATE TABLE`. **No SELECT exists anywhere.** The remaining
mentions are documentation.

Do not rush it; do not forget it. The day anything resolves a member by email — account merge,
email change, support lookup — those 202 are invisible and it presents as "this member does not
exist."

*If it is ever fixed:* the insert is trivial and idempotent against the unique key on
`email_normalized`. The one thing to measure **at apply time rather than assume** is collisions —
`ON CONFLICT (email_normalized) DO NOTHING` would silently leave a contested email pointing at the
other user. That count is 0 today, which makes a fix safe today, and is exactly why it should be
re-measured then rather than trusted from this document.

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

1. **connections** — written, rehearsed, dry-run against live. Only needs running.
   See `IAN-RUN-ORDER.md`.
2. **messages** — **promoted above location deliberately.** It is the only gap that is still
   actively visible to members who were never part of the late cohort: 13 of them can open one of
   24 threads and find up to 100% of the conversation gone. Needs an Ian ruling first, not code —
   restoring 18-month-old private conversations is a product and privacy decision, and the
   unread-badge question needs his answer exactly as the pending-request one did.
   See `MESSAGES-GAP-INVENTORY.md`.
3. **location (11)** — cosmetic by comparison and a closed batch. Nothing is lost; the source
   string still exists in BuddyBoss. A fix wants the geocoder pass too.
4. **`email_aliases`** — no rush, failure mode proven benign, but gate any future
   email-resolution feature on it and re-measure collisions at apply time.
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
