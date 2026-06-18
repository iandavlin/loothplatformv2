# Hub forum posting — anonymous + Council/Weekly tags are driven by **FluentForms Form 38 (LIVE)**

**TL;DR:** Anonymous posting and the `councilyes` / `weeklyyes` workflow tags are NOT
implemented in this repo or on dev. They live **only on the live site
(loothgroup.com)** as a **Code Snippets** snippet attached to **FluentForms Form 38**.
That's why grepping the repo / dev for `_ff_submitted_by_user_id`, "anonymous", etc.
turns up only the *render/audit* side, never the creation path. (Confirmed 2026-06-07.)

## What Form 38 does (the canonical "new topic" submission on live)

Hook: `fluentform_submission_inserted` for `form->id === 38` → `bbp_insert_topic(...)`.

- **Forum**: resolved from `forum_dest_repair|builds|tools|business|market|sponsor`
  (or forum `4052` for the Suggestion Box / Bug Reporting radio).
- **Title** = field `input_text`; **Body** = field `description` (+ up to 3 images from
  the `image-upload` field appended as `<div class="ff-topic-images">`).
- **Checkboxes → behavior:**
  - `checkbox`   → Council → adds topic tag **`councilyes`**
  - `checkbox_1` → Weekly  → adds topic tag **`weeklyyes`**
  - `checkbox_2` → **Anonymous**
- **Anonymous author model:** `$author_id = $post_anon ? 1517 : current_user`.
  User **#1517** (login/display_name = `anonymous`) is the shared anon author, so the
  topic is *authored by* #1517 from the start (the BuddyBoss activity item is also
  forced under #1517, so the real name never leaks into the feed).
- **Audit trail (mod-only):** post meta on the topic —
  `_ff_submitted_by_user_id` (real WP user id), `_ff_submitted_by_user`
  ("Name (@login, #id)"), plus `_ff_entry_id`, `_ff_topic_id`, `_ff_activity_recorded`.
  Two snippets surface it to moderators: a wp-admin "Anonymous Post Audit" meta box on
  the `topic` CPT, and a front-end `bbp_theme_before_topic_form` audit line on the
  topic-edit screen (`current_user_can('moderate')`).
- **Redirect:** `fluentform/submission_confirmation` sends the submitter to the new
  topic's permalink.

## How it shows in the Hub (bb-mirror)

The bb-mirror reconciles from WordPress, so an anon topic comes across authored by
person **#1517** → renders as **"anonymous"** via the existing
`COALESCE(author_name,'Anonymous')` in `_feed.php` / `_single-topic.php` /
`_reply-render.php`. No mirror change is needed; the audit meta is not mirrored
(it's wp-admin only).

## Relationship to the Hub composer (ntm — this repo)

The Hub "New post" composer (`bb-mirror/web/_chrome.php #ntm-form` + `forums.js §4`)
is a **separate** posting path: it POSTs to BuddyBoss REST `/topics` as the logged-in
user. It is NOT wired to Form 38.

- The composer's **`councilyes` / `weeklyyes` quick-tag buttons** (added 2026-06-07)
  produce the *same topic tags* as Form 38's checkboxes, so that half is consistent.
- The composer has **no anonymous option** — anon currently only exists through Form 38.

### Future tinkering: adding anon to the ntm composer
If anon is ever wanted in the composer, the composer can't do it alone (BB REST stamps
the real author). You'd add a small server hook on BB REST topic creation that, when an
`lg_anonymous` flag is present + user is logged in:
1. swaps `post_author` to **#1517 *pre-insert*** (so the activity item is authored by
   #1517 and the real name doesn't leak — Form 38 gets this for free by inserting as
   #1517 from the start), and
2. stamps `_ff_submitted_by_user_id` / `_ff_submitted_by_user` (reuse the existing audit
   meta keys so the live audit meta-boxes keep working).
Decision 2026-06-07 (Ian): NOT building this now — Form 38 stays the anon path; this note
exists for future reference.
