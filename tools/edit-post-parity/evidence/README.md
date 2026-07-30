# Server-side acceptance evidence — edit-post parity

Run 2026-07-30 on **dev2**, branch code, real dev2 databases. Topic **72306**
("ZZ TEST edit-post-parity (delete me)", author claude_admin/1912).

Method: `db-snapshot.sh` either side of a real edit driven through the branch's
own endpoints on the exercise harness (`php -S` as `looth-dev`, the pool nginx
would use). Nothing mocked; the WP and Postgres databases are the live dev2 ones.

## What the edit did, in one save

| | before | after |
|---|---|---|
| forum | 3837 General | 3823 Acoustic Repair |
| tags | martin, vintage | **fender**, vintage |
| body | rich HTML | same + an appended paragraph |

Then, through `topic-media.php`: **added** a photo (attachment 72347 → bp_media
3417) and **removed** it again.

## The positive half — every control Ian asked for

- **Forum picker** — `post_parent` 3837→3823, `_bbp_forum_id` follows, and both
  forums' counters move consistently (3823 +1 topic/+2 replies, 3837 −1/−2).
- **Tags** — `martin` dropped, `fender` added, `vintage` deliberately kept, which
  is the case that proves it is a diff and not a wipe-and-rewrite.
- **Formatting** — the GET returns the **raw** stored body; bold, link, list and
  the inline `<img>` all survive the round trip. This is the data-loss bug the
  backlog did not know about: the old door scraped the rendered OP out of the DOM
  and flattened it, so merely opening the editor destroyed formatting on save.
- **Images** — add creates the bp_media row and links it; remove **deletes** the
  row (`wp_bp_media` count 0), not merely unlinks it, and the orphan attachment
  is cleaned up by BuddyBoss's own saver.

## The negative half — the clause the UI cannot show

`03-journey.diff` is the whole story. Everything absent from that diff held:

- `post_date` / `post_date_gmt` — **unchanged**. Nothing may ever rewrite a
  creation time, and this is in the snapshot precisely because nothing should.
- `post_author`, `post_status`, `post_type`, `menu_order`, `comment_count`,
  `post_name` — unchanged. In particular the **slug did not churn**, so no
  permalink broke.
- **Both replies** — byte-identical: ids, authors, `post_date_gmt`,
  `post_modified_gmt`, status and content hashes all unchanged. They moved forum
  (correctly) without being otherwise rewritten.
- **Both reactions** (9982, 9983) and the **activity row** (19490) — unchanged.
  Reactions hang off the activity id, so a re-created activity row would have
  silently orphaned them.
- **Revision count** — steady at 10. A save may add one; nothing may delete one.
- `bp_media_ids` — back to empty after add+remove, a clean round trip.

Only `post_modified` / `post_modified_gmt` moved, which is the one timestamp an
edit is *supposed* to move.

## The mirror, and the trap that nearly wrote a false result

The first run left the Postgres mirror **drifted**: `forums.topic` moved to 3823
while `forums.reply` still said 3837 — exactly the failure this branch's
`_sync.php` change exists to fix. The fix had not failed; **it had not run.**

`bb_mirror_sync_dispatch()` lives in a *mu-plugin*, and the mu-plugin the harness
loads is `/var/www/dev/wp-content/mu-plugins/bb-mirror-sync.php` →
`~/loothplatformv2-clean/...` = **main**. It fires a non-blocking `wp_remote_post`
to `BB_MIRROR_SYNC_URL`, which nginx serves out of the same main checkout. So the
sync half of any branch is invisible to the harness by default: the request leaves
the branch and is answered by main.

Posting the same payload to the **branch's** `_sync.php` healed it immediately —
replies re-pointed 3837→3823 — which also demonstrates the "self-healing" claim
for real, because it repaired drift that was *already sitting in the table*
rather than drift it had just caused.

**Consequence for deploy:** on today's live/main code a forum move leaves every
reply in the mirror pointing at the old forum, and the reconcile sweep will never
notice — `bbp_move_topic_handler` rewrites reply meta without bumping
`post_modified_gmt`, and the sweep only revisits rows whose modified time moved.
This branch is what closes it.

## Two smaller things that cost time, recorded so they cost nobody else any

- **`grep -r` does not follow symlinks during recursion.** The mu-plugin
  directory is 33 symlinks, so `grep -rln bb_mirror_sync_dispatch mu-plugins/`
  returns *nothing* and reads exactly like "this code is not loaded here".
  Use `grep -R`. (Cousin of the box's existing `grep -c` trap.)
- **Harness writes need a real session token, not just cookies.** The harness
  README's snippet mints auth cookies only, and every write then 403s on nonce:
  `wp_create_nonce()` mixes in `wp_get_session_token()`, and a CLI context has
  none. Mint one token with `WP_Session_Tokens::create()`, use it for **both**
  cookies *and* set `$_COOKIE[LOGGED_IN_COOKIE]` before creating the nonce.

## Files

- `01-before.txt` — full snapshot before any edit
- `02-after.txt` — full snapshot after the whole journey
- `03-journey.diff` — the diff; read this one
