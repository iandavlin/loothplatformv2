# Connections restore — the June cutover gap

**Lane:** connections-backfill · **Date:** 2026-07-27 · **Branch:** `connections-backfill`
**Live was READ-ONLY throughout.** No row on live was created, altered or deleted by this lane.
Every rehearsal ran against a throwaway Postgres replica on dev2, which was dropped afterwards.

---

## A. Ian's question, answered

**Ian is missing 136 connections. 135 are being restored — 83 accepted and 52 pending.**

His hypothesis was *nearly* right, and the difference decides the fix. The connections **were**
backfilled from BuddyBoss: 10,609 of the 11,363 legacy friendships are present in
`public.connections`, carrying original timestamps back to 2023-06-15. The migration ran and
mostly worked.

What failed is narrower. The backfill could only import a friendship if **both** people already
had a `wp_user_bridge` row when it ran. **241 members were bridged after that point** — 181 of
them on 2026-06-11, nine days post-cutover — and every friendship touching one of them was
skipped. **All 136 of Ian's are in that cohort.** He lost precisely the people the backfill
could not yet see.

## B. The whole membership

**750 friendships are absent. 746 are restored; 4 are deliberately held back.**
The restore is **355 accepted / 391 pending** across **302 members**.

748 of the 750 involve a late-bridged member. Alternatives were tested and ruled out by query:

| Hypothesis | Verdict |
| --- | --- |
| Migration never ran for this table | **No** — 10,609 of 11,359 mappable rows present with original dates |
| Unmappable users (no bridge row) | **No** — only 4 legacy rows have an unmappable endpoint |
| Status/state mismatch | **No** — `is_confirmed=1→accepted` holds 7,169:73 |
| Direction flipped on import | **No** — 10,598 same-direction vs 11 reversed |
| Self-pairs / duplicate pairs collapsing | **No** — 0 self-friendships, 11,358 distinct pairs of 11,359 |
| **Users bridged after the backfill ran** | **YES — 748 of 750** |

Two classes of victim, unequally hurt:

- **70 late-bridged members lost more than 80% of their connections.** The platform reads as
  empty for them. None have complained.
- **Long-established members lost 5–12%** — Ian 136/1762, Grace Da Maren 101, Michael Bashkin 86,
  Thom Abell 59. Less visible per person, but it is why Ian noticed.

### Root cause

`tools/backfill-bb-connections.sh` predicted this in its own header:

```
# Run on dev 2026-06-11 (10,377 imported); RE-RUN AT CUTOVER against live data —
# rows skipped today (~1,018) are users not yet in wp_user_bridge (unprovisioned),
# self-pairs, and reverse-duplicate pairs; the ON CONFLICT guard makes re-runs safe.
```

The re-run never happened. Not a regression — a documented follow-up that fell off the list.

## C. What members will see

**391 of the 746 rows are pending requests** (52 of Ian's 135). Restoring them makes old,
unanswered requests appear in members' pending UI, dated when they were originally sent. A
member may open the site tomorrow to find requests they have never seen. **That is a product
decision and Ian should make it deliberately.** If unwanted, restore the 355 accepted rows now
and hold the 391 pending — accepted rows carry no call to action.

**Which side the pending lands on — measured, and it is not symmetric.** All **52** of Ian's
pending rows have Ian as the **requester**; he has **0** inbound. So restoring Ian's set does
**not** put 52 new requests in front of Ian. It puts **one new incoming request in front of each
of 52 other members**, attributed to Ian, dated 2023-06-21 → 2026-04-18. Ian sees them only in
his *outgoing / awaiting* list.

The badge those 52 see is real but silent: `Connections::pendingCount()`
(`profile-app/src/Connections.php`) counts straight off `connections` where
`addressee_uuid = me AND status = 'pending'`, and feeds `requests_pending` in
`api/v0/me-social-counts.php`. So the header friends badge increments with **no notification row
and no email behind it** (see below). Across the full 746 the same effect reaches **142 distinct
members**.

**One coupling to respect:** `profile-app/bin/migrate-social-from-bb.php` → `seedNotifications()`
seeds one `connection_request` bell for *every* pending row it finds. It is a one-shot migration
script, not a cron, so it will not fire on its own — but re-running it after this restore would
convert these silent badges into real bells. Do not re-run it.

**No notification or email will be sent.** Verified on live: `public.connections` has exactly one
trigger, `connections_touch`, which is `BEFORE UPDATE` only. No INSERT trigger, no rules.
Notifications are written by the application layer. A direct SQL insert mints nothing.

**The 10 pre-existing bidirectional duplicates are untouched and not made worse.** They are an
older defect from the original script, whose `ON CONFLICT (requester_uuid, addressee_uuid)` is
directional and cannot see a reverse row. A naive re-run would have added **11 more**; the
delivered script's `NOT EXISTS` reverse guard adds none. Rehearsed: the count stays at 10.
Fixing the existing 10 is a separate job — deleting live connection rows is a different risk
conversation.

### The 4 deliberately excluded (all pending, all enumerated in `excluded-4-rows.tsv`)

- **2 involve a suspended account** (WP 1535, `patreon_186249137`, `hide_sitewide=1`) — one is
  Ian's own. Restoring a pending request to a sitewide-hidden account has no upside.
- **2 are not explained by the cohort** (both to Magnús Gunnar, WP 408). Both endpoints were
  bridged at cutover, so the backfill should have imported them. The likeliest explanation is
  that it **did**, and the member later declined them: `Connections::decline()` performs a hard
  `DELETE` rather than setting a declined status, so a declined request and a never-imported one
  are **indistinguishable** in the data. Restoring them risks resurrecting something a member
  deliberately dismissed.

**No archived accounts are involved** — all 38 archived profiles are test accounts, none in the
affected set. **No deleted or spam WP accounts** — all 306 exist with `user_status = 0`.

## D. Verification performed

Against a throwaway replica loaded from the live snapshot, including tonight's organic drift:

- dry run writes **nothing** (row count unchanged, no tag table created)
- apply inserts **745** of 746 — it correctly skips the one pair that a member created
  organically at 22:31 tonight, proving the guards work against live drift
- Ian lands on **1334 accepted / 479 pending** (+83 / +52 exactly)
- second apply inserts **0** — idempotent
- bidirectional duplicates stay at **10**
- rollback restores a **byte-identical** table (matching MD5)
- rollback still works after a member deletes restored rows: `ON DELETE CASCADE` drops the tags,
  rollback removes the remainder without error

Live's `connections_id_seq` was checked healthy (21301, next 21302 — and 21302 is exactly what
tonight's organic request received).

---

# The bigger question: what else skipped the same 241?

Connections is the table Ian happened to notice. The same 241 members were invisible to
**anything** keyed on `wp_user_bridge` at cutover. Measured read-only, comparing the 241
late-bridged against the 1,606 bridged at cutover, and — critically — checking whether the
**source data exists in BuddyBoss**, because a lower rate alone proves nothing when the cohorts
differ in character.

| Domain | Real gap? | Evidence |
| --- | --- | --- |
| **connections** | **YES — 746 rows, 302 members** | this report |
| **messages** | **YES — 16 members** | 17 of the 241 have BuddyBoss message history; **16 of those 17 have none in the app**. The cutover cohort migrated cleanly (254 in BB → 259 in app). Same root cause, same shape: `migrate-social-from-bb.php` seeds "connections + messaging" in one pass. Small in absolute terms (26 BB recipient rows) but a **total loss** for those 16 people. |
| **location** | **YES — 11 members** | 58 of the 241 have a BuddyBoss location pin (xprofile field 96); 11 have no location in the app. The cutover cohort is complete (668 in BB → ~679 in app). |
| **socials** | **NO** | The raw rate looks 3.5× worse (2.5% vs 8.9%), but the control disproves it: **91% of the cutover cohort also has zero socials** despite all 1,606 having BB xprofile data. Socials come from a narrow subset of fields; the crude metric proves nothing. No gap claimed. |
| **avatars** | **NO** | 100% populated in both cohorts. |
| **about / at_a_glance** | **NO** | 2.9% late vs 2.4% cutover — the late cohort is marginally *higher*. |
| **instruments** | **NO** | 2.5% late vs 0.9% cutover — higher, consistent with newer members filling profiles organically. |

**Also worth knowing: 11 of the 241 do not exist in `wp_users` at all.** These are
profile-app-native accounts (Patreon-provisioned). There is nothing to migrate for them, but
they cannot be audited against BuddyBoss either.

### Scripts that key on the bridge and ran once at cutover

These are the candidates for the same class of miss. Runtime code that resolves the bridge live
(`api/v0/*.php`, `src/Whoami.php`, `src/Mint.php`, `src/Provision.php`) is **not** affected —
it looks up whatever is there at request time.

`tools/backfill-bb-connections.sh` · `tools/backfill-bb-avatars.sh` ·
`profile-app/bin/migrate-social-from-bb.php` · `migrate-from-xprofile.php` ·
`migrate-socials.php` · `migrate-crib-slice4.php` · `snapshot-location-from-bb.php` ·
`regeocode-from-bb.php` · `backfill-avatars.php` · `backfill-author-about.php` ·
`backfill.php` · `consolidate-avatars.php` · `fix-divergent-locations.php`

Of these, the measurements above show only the **social/messaging** and **location** passes
actually left a gap. `profile-app/bin/reconcile-bridge.php` exists and may be the intended
remedy for exactly this situation — worth reading before writing anything new.

**Recommendation:** messages first (16 members with a total loss of their history), location
second (11 members). Both are far smaller than connections and neither is urgent, but both are
the same bug and should be closed in the same sweep rather than rediscovered later.
