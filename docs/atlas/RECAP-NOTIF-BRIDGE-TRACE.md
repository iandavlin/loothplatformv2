# Recap under-reporting — the trace (recap-notif-bridge lane, 2026-08-01)

> ## ⏸ STATUS: PARKED 2026-08-01 (keeper). Trace complete, NOTHING BUILT.
>
> Parked by keeper: this is **WEEKLY DIGEST** territory — the `weekly-recap` lane in
> `BACKLOG.md` owns that project — and Ian was at 95% budget closing out follow-digest.
> The trace below is finished and needs no rework. **Two findings survive it, and they are
> NOT the same kind of thing:**
>
> **LEAK A — the bell hard-DELETEs the recap's only source data. A bug under ANY reading;
> does NOT depend on Ian's ruling.**
> `me-notifications.php:75` → `Notifications::delete()` `:262` (dismiss one) and
> `me-notifications.php:71` → `deleteAll()` `:277` (Clear all) issue a real `DELETE`.
> The recap reads that same table, so clearing your bell erases your week — `deleteAll`
> nukes it in one click. Measured: **on 2026-07-31, 17 hub notifications were raised and
> only 12 survive.** Fix shape (not built): soft-delete, or an immutable `occurred` record
> so the inbox stays clearable without costing the digest its history.
>
> **LEAK B — the recap is unread-only. BLOCKED ON IAN, and the two sources of truth
> disagree.**
> `Recap.php:120-123` (`OUTSTANDING`, spliced at `:209`): hub rows have no `connection_id`,
> so the filter is `n.is_read = false`. `Recap.php:15` records this as Ian's own 2026-07-27
> ruling — *"THE SECTION IS 'WHAT YOU MISSED', NOT 'YOUR WEEK'"*. **This lane's charter
> asserts the opposite** ("a 'what happened this week' list, not 'what you haven't read'").
> Those are two different products. **Do not resolve this by reading the code — the code is
> one side of the disagreement.** Ian breaks the tie.
>
> **BLOCKED BY:** Ian. **UNBLOCKED BY:** one ruling — *is the recap "what you missed"
> (keep `is_read=false`) or "what happened this week" (drop it)?* Leak A can proceed
> without that answer.
>
> **RESUME NOTES**
> - Branch `recap-notif-bridge` @ `ba57ad4`, pushed, tree clean. Doc-only — **zero code
>   changed**, so there is nothing to unwind and nothing to flag.
> - **The charter's gate as written would PASS TODAY** and must not be built as specified:
>   the bridge already raises the row, so "member gets a `forum.reply_to_topic` row" is
>   green over a live defect. It has to assert against `Recap::forMembers()` output *after*
>   a dismiss and *after* a mark-read. See §4.
> - Anything member-facing out of this merges behind a flag, defaulted OFF, with the OFF
>   state itself gated.
> - Stale memory corrected in passing: `forums.topic_follow` **is** present on live (3 rows).
>   The "MISSING ON LIVE" memory is out of date.


Ian, 2026-08-01: the weekly-digest RECAP under-reports. Measured on LIVE (read-only):
`profile_app.notifications` holds exactly ONE row for him (`forum.mention`, `is_read=TRUE`)
while `wp_bp_notifications` holds 5 unread `bbp_new_reply` rows.

**The bridge is not the fault. It fired for every one of those five.** The rows it raised
were raised and then DELETED, by the bell's own dismiss / Clear-all. What survives is then
filtered out a second time by the recap's unread-only rule.

Everything below is measured. Live's `profile-app` files are byte-identical to `origin/main`
(md5 `ab56f8b4…` me-notifications, `d5eb37ab…` internal-notify, `27ed672e…` Notifications,
`ca315aad…` Recap), so the code read here is the code running.

---

## 0. What the five notifications actually ARE

Every one is a **reply to a reply Ian wrote** — not a reply to a topic he started, and not a
topic he follows. `wp_posts` on live:

| BB notif | reply | reply author | topic | topic author | `_bbp_reply_to` | parent author |
|---|---|---|---|---|---|---|
| 186058 | 72461 | 1953 | 72077 appraisals | 1185 | 72097 | **1 (Ian)** |
| 186055 | 72459 | 118  | 72455 off-gassing | 118 | 72456 | **1 (Ian)** |
| 186045 | 72451 | 621  | 72447 modifying-waverly-tuners | 621 | 72450 | **1 (Ian)** |
| 186041 | 72439 | 646  | 72436 discoloration-inside… | 646 | 72437 | **1 (Ian)** |
| 186032 | 72433 | 476  | 72396 epoxy-pore-fill-question | 1355 | 72425 | **1 (Ian)** |

So this is **leg 2** territory — `forum.reply_to_reply`, `notify-bridge.php:195-209`. Legs 3
and 4 correctly do not concern him: he authored none of those topics, and `forums.topic_follow`
on live holds only `(1, 72447)`, `(627, 46614)`, `(1953, 72447)` — the first created
2026-07-31 04:06:39, i.e. AFTER four of the five replies.

(The migration memory said `topic_follow` was missing on live. It is present now, 3 rows.
That memory is stale.)

---

## 1. THE BRIDGE — none of (a), (b) or (c)

### (a) Path — NO. Every reply went through `reply.php`, which calls the bridge.

`bb-mirror/api/v0/reply.php:551-553` requires the bridge and calls `lg_notify_on_reply()`.
Live's nginx access log shows each of these replies arriving as
`POST /bb-mirror-api/v0/reply` → 200, from the member's own browser:

```
[31/Jul/2026:03:14:23] "POST /bb-mirror-api/v0/reply" 200   ref …?topic=general%2Fmodifying-waverly-tuners   → reply 72451
[31/Jul/2026:21:46:13] "POST /bb-mirror-api/v0/reply" 200   ref …?topic=general%2Foff-gassing                → reply 72459
[31/Jul/2026:22:37:42] "POST /bb-mirror-api/v0/reply" 200   ref …?topic=general-buisness%2Fappraisals        → reply 72461
```

The native-path backstop (`platform/mu-plugins/bb-mirror-sync.php:214-245`) is not needed and
correctly stands down — `reply.php:482` raises `$GLOBALS['lg_bb_mirror_reply_owned']`. Live
symlinks that same mu-plugin copy (`/var/www/dev/wp-content/mu-plugins/bb-mirror-sync.php →
loothplatformv2-clean/platform/mu-plugins/bb-mirror-sync.php`), i.e. the one carrying the bell legs.

### (b) Timing / backfill gap — NO.

Legs 2 and 3 are not new code: they landed in `34e8f89` (2026-07-12), three weeks before
these replies. And they demonstrably work for other members — live holds 6 `forum.reply_to_reply`
rows and 9 `forum.reply_to_topic` rows raised by this same code.

### (c) Deep-link skip at `:175` — NO.

`lg_notify_topic_url()` needs a topic slug and a forum slug; both resolve for every topic here
(table above). Proof rather than inference: sibling rows raised by the SAME calls carry working
URLs — e.g. Ian's surviving row is `/hub/?topic=general-buisness/appraisals&reply=72461`.

### (d) — THE ACTUAL CAUSE: the rows were raised, then deleted.

The bridge POSTs each notification over loopback to `/profile-api/v0/internal/notify`
(`notify-bridge.php:75`). Those calls are in the access log, and their **response size is a
reliable read-out of whether a row was written**:

- `internal-notify.php` ends at `profile_app_json(200, ['ok'=>true,'raised'=>$raised])`.
- `{"ok":true,"raised":true}` = **25** bytes; `…"raised":false}` = 26; `"skipped":"self"` = 43;
  `"skipped":"unbridged"` = 48.
- The bridge calls it with **curl, which sends no `Accept-Encoding`**, so nginx does not gzip it.
  (Browser traffic to the same app IS gzipped — which is why browser `{"ok":true}` (11 raw) logs
  as 31 and `{"ok":true,"deleted":N}` (23 raw) logs as 43. Both match `gzencode` exactly, so the
  gzip/no-gzip split is confirmed, not assumed.)

**On 2026-07-31 there were 17 calls to `/profile-api/v0/internal/notify`. All 17 returned
`200 25` — every one raised a row. Only 12 hub rows created that day still exist.
Five of the day's notifications were destroyed within hours.**

Two of those five are Ian's, and for these two the push count reconciles EXACTLY, leaving no
other candidate recipient:

**Reply 72451** (7/31 03:14:22 UTC) — author 621, topic 72447 authored by 621, parent 72450 by Ian.
- leg 1 mention: content contains no `@` and no `{{mention_user_id_` → 0 pushes
- leg 2 → **Ian** → 1 push
- leg 3 → topic author 621 == reply author → self, skipped (`notify-bridge.php:213`)
- leg 4 → no `topic_follow` row on 72447 existed until 04:06:39, 52 min later → 0 pushes

Expected: exactly 1 push, for Ian. Observed: exactly one `internal/notify` at `03:14:23`,
`200 25` — **raised**. Ian has no such row today.

**Reply 72459** (7/31 21:46:12 UTC) — author 118, topic 72455 authored by 118, parent 72456 by Ian.
- leg 1: no mention → 0; leg 2 → **Ian** → 1; leg 3: self, skipped; leg 4: no follows on 72455 → 0

Expected: exactly 1 push, for Ian. Observed: exactly one at `21:46:12`, `200 25` — **raised**.
No such row today.

**Reply 72433** (7/30 16:00:04 UTC) — corroborated by a sibling: leg 3 raised row **932**
(`forum.reply_to_topic`, target 72396, recipient 1355) at `2026-07-30 16:00:05`. Leg 2 runs
*before* leg 3 in the same function, so it ran too. Ian's row from it is gone.
(7/30 access log has rotated out, so this one is corroborated rather than push-counted.)

**Reply 72461** (7/31 22:37:41) — **worked exactly as designed.** Two pushes at `22:37:42`, two
rows: `981` `forum.mention` → Ian, and `982` `forum.reply_to_topic` → 1185. Leg 2 was suppressed
for Ian by the `$notified` dedup (`notify-bridge.php:197`) because the mention already claimed
him — "the mention wins". That row 981 IS his notification for this event. It survives, and is
`is_read = TRUE` — see §2.

**Reply 72439** (7/30 23:36) — same shape as 72451/72459 (self-authored topic, Ian the parent
author, no mention, no follows), so leg 2 is the only leg that fires. Inferred, not push-counted:
the 7/30 log has rotated.

#### What deleted them — by elimination

Every code path that removes a `notifications` row:

| path | verdict |
|---|---|
| `Notifications::prune()` `:291` — `created_at < now() - 30 days`, cron `bin/prune-notifications.php` | **impossible** — rows were hours old |
| `EraseUser.php:113` — all rows for a user | **impossible** — would have taken row 981 too |
| `tools/dupe-merge/merge-dupes.php:716` | **impossible** — ran 7/29, before these; Ian not a merge subject |
| `Notifications::delete()` `:262` ← `me-notifications.php:75` (dismiss one) | **possible** |
| `Notifications::deleteAll()` `:277` ← `me-notifications.php:71` (Clear all) | **possible** |

Only the bell UI remains. And it is in heavy live use — on 7/31 alone the log shows three
`DELETE /profile-api/v0/me/notifications/?all=1` and several `?id=N`:

```
[31/Jul/2026:18:12:15] "DELETE /profile-api/v0/me/notifications/?all=1" 200
[31/Jul/2026:19:21:19] "DELETE /profile-api/v0/me/notifications/?all=1" 200
[31/Jul/2026:20:25:03] "DELETE /profile-api/v0/me/notifications/?all=1" 200
[31/Jul/2026:22:20:28] "DELETE /profile-api/v0/me/notifications/?id=976" 200
```

> Attribution caveat, stated rather than smoothed over: I could not pin these specific deletes
> to Ian's session. His IP (153.54.144.158, identified from his own reply POSTs) is shared with
> at least one other member (user 1953 posts from it too), so IP is not identity here. The
> elimination argument above does not depend on which finger pressed the button — no other code
> path can have removed those rows.

**LEAK A — dismissing or clearing your bell permanently destroys the recap's source data.**
`notifications` is simultaneously (i) a transient UI inbox the member is invited to empty and
(ii) the digest's only record of the week. Those two jobs are incompatible in one table.

---

## 2. THE RECAP FILTER — unread-only, and Ian's one surviving row is READ

`profile-app/src/Recap.php:120-123`:

```php
private const OUTSTANDING = "
          (n.type = 'connection_request' AND c.status = 'pending')
       OR (n.type = 'connection_accept'  AND c.status = 'accepted' AND n.is_read = false)
       OR (n.connection_id IS NULL AND n.is_read = false)";
```

spliced into the named-register query at `Recap.php:209` (`AND (" . self::OUTSTANDING . ")`).

Hub rows (`forum.*`, `reaction.*`) carry no `connection_id`, so they fall in the third clause:
**`is_read = false` — unread only.** Stated in the file's own words at `Recap.php:117-118`:
*"hub rows — no edge exists, so `is_read` is the only resolution signal they have."*

Ian's one surviving row (`981`, `forum.mention`) is `is_read = TRUE`. **It is excluded.** His
recap is empty on both counts: four rows deleted, the fifth filtered.

**LEAK B — a member who opens their bell loses that item from the digest.** Note
`Recap.php:111-113` already records that `bottom-nav.js:1128` auto-marks every row read 700 ms
after the mobile notification sheet opens. So on mobile, *glancing* at the bell is enough. That
is the §9.1b "empty for the engaged member" class, and it is worse than it looks: the more
engaged the member, the emptier their digest.

---

## 3. The ruling this needs from Ian — the two leaks are not the same kind of thing

**Leak A is a bug under any reading.** A dismissed bell item should not erase the historical fact
that the event happened. `deleteAll` in particular nukes a member's entire week in one click.

**Leak B is a deliberate, Ian-ruled behaviour, and the lane charter contradicts it.**
`Recap.php:15` records: *"THE SECTION IS 'WHAT YOU MISSED', NOT 'YOUR WEEK' (Ian, refined
2026-07-27)"*, and `Recap.php:79-81` states the empty section is the intended common case for
anyone who keeps up. The charter for this lane says the digest is *"a 'what happened this week'
list, not 'what you haven't read'"*. **Those are two different products** and I am not going to
quietly swap one for the other. Ian breaks this tie.

The deeper point either way: **`notifications` is the wrong source for "what happened this week."**
It is member-deletable and 30-day-pruned by design — it is an inbox, not a ledger. A
"what happened" recap should be derived from the forum data itself (`forums.reply` in the `looth`
DB / `wp_posts`), or the bell rows need a soft-delete + an immutable `occurred` record so the
inbox stays clearable without costing the digest its history.

No fix is proposed here beyond the shape above — per the lane charter, the trace comes to keeper
first.

---

## 4. Reproduction / gate (red-first, not yet built)

Charter gate: *a member with an unread BB reply notif gets a `forum.reply_to_topic` row AND the
recap surfaces it.* Given the trace, that gate must be widened or it will pass while the defect
stands — the bridge already raises the row, so a gate that stops at "row exists" is green today.
It needs the two legs that actually fail:

1. raise a `forum.reply_to_reply` via the real `reply.php` path → assert the row exists
   *(green today — this is the assertion that would have lied)*;
2. `DELETE /profile-api/v0/me/notifications/?id=N` → assert the recap **still** reports the event
   *(red today — Leak A)*;
3. `POST {action:'read'}` → assert the recap **still** reports the event
   *(red today — Leak B, pending Ian's §3 ruling on whether it should be)*.

Note for whoever builds it: assert against `Recap::forMembers()` output, not against a row count —
a row count cannot see the `OUTSTANDING` filter, which is exactly the blind spot that let this
ship.
