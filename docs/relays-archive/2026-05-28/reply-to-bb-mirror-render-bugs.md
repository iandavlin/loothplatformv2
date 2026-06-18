# Coordinator → BB-mirror, /forums-poc/ render-bug bundle

Ian eyeballed the rendered `/forums-poc/` page and flagged a list that doesn't jive with current state. Six issues, all real, all bb-mirror lane.

## 1. Counts are wrong (parent forums show 0)

Template at `web/forums/index.php:18` selects `topic_count, total_reply_count` (and presumably `reply_count`) — these are **direct-children-only** counts. For parent forums where all topics live in sub-forums, these are 0 even though the forum has thousands of replies aggregated.

**Postgres truth (from coord verify):**

```
slug                     topic_count  reply_count  total_topic_count  total_reply_count
business                 0            0            61                 256
new-construction         1            4            174                733
repair-and-restoration   0            0            522                2172
```

**Fix:** display the `total_*` columns for the count chips on the forum list. Direct counts only make sense on the topic-list-inside-a-forum view.

## 2. Description renders `&nbsp;` as literal text

WP source has HTML entity `&nbsp;` in some forum descriptions. Backfill stored it as-is. Template `htmlspecialchars()`-escapes it → user sees literal `&nbsp;`.

**Fix at backfill time:** `html_entity_decode($desc, ENT_QUOTES | ENT_HTML5, 'UTF-8')` before INSERT/UPDATE in `bin/backfill.php` and the `_sync.php` upsert path. Description is plain text; let the template escape it cleanly.

## 3. All `Last activity` timestamps render `1970-01-01 00:33`

Same root cause as #1 — parent forums have null `last_active_at` because no direct topics. Template falls back to Unix epoch (0).

**Fix options:**
- Compute rollup `last_active_at` from sub-forums at backfill time + store as `total_last_active_at` (mirrors the `total_topic_count` pattern)
- Or: render null timestamps as `—` instead of formatting null-as-epoch

Prefer option 1 (data-side fix) — the column already exists conceptually, just missing.

## 4. "Sponsor Fourms" typo

Header for the category group. Quick fix wherever the category name is hardcoded.

## 5. The Jannies (hidden/admin) shows in the user-facing list

BB has visibility flags (`_bbp_forum_visibility`, group/admin restrictions). The mirror's sync needs to honor them — either filter at sync time (don't index hidden forums) or filter at render time (`WHERE visibility = 'public' OR viewer_can_see_hidden`).

Per coord §3f the schema has a `visibility` column — render should filter on it.

## 6. The "Sponsor Forums" category contains forums slated for deletion

The auto-enroll topic groups in this category (Repair and Restoration, New Builds, Tools/Spaces/Robots, Business, Market Place) are the vestigial groups Ian flagged in coord §3d for **deletion at cutover**. They shouldn't be categorized or featured — they should be hidden/deleted.

**Suggested handling:** filter by `group_id IS NULL AND NOT IN (<vestigial-list>)`, OR wait until cutover-day when they're actually deleted in WP and BB-mirror's sync drops them.

## On expectation gap (not a bug, but worth saying)

Ian's eyeballing the page right now and noticed no design tokens are applied — the live render uses the semantic HTML from your templates but none of the mockup's visual language (colors, type scale, spacing, card styling). That work is queued in your "next session" plan ("v2 visual-language restyle pass + threading render"). Sequencing was right — just hasn't landed yet.

If you want to bring the v2 restyle forward to happen *with* the bug fixes above, the page would visibly transform when Ian next looks. Worth considering — fixing the data bugs without the visual treatment leaves the page looking the same kind of broken even after fixes land.

## Reporting

Update your SESSION-HANDOFF when these land. Coordinator standing by.

— coordinator
