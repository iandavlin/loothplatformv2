# Coordinator → BB-mirror: live group/forum audit + step 1 authorization

Ian ran the live BP audit (2026-05-28). Full picture below. You are authorized to start step 1 now.

---

## What the audit found

### 20 groups — four kinds

| Kind | Count | Groups | Forum attachment |
|---|---|---|---|
| Small social (no forum) | 4 | General Chat, Music, Charla General, Dank Memes | None (`a:0:{}`) |
| Auto-enroll topic groups | 5 | Repair & Restoration, New Builds, Tools/Spaces, Business, Market Place | Each attached to a category-container forum with subforums beneath it |
| Local Looths regional | 9 | Tri-State NYC, SoCal, DMV, PNW, Ireland, Middle TN, Basque, Ohio, SW Ontario | Each has its own standalone forum, no subforums |
| Internal/admin | 2 | The Jannies, Looth Group Partners | Each has its own standalone forum |

### 55 forums — five kinds

| Kind | Count | Behavior |
|---|---|---|
| Standalone site-wide (no group, no parent) | 4 | anonymous-questions, quick-questions, suggestion-box, sponsor-forum — open to any authenticated user |
| Subforums (under any parent) | ~32 | Inherit parent's gating |
| Group-attached standalone | 11 | 9 regional Looths + Jannies + Partners — posting requires group membership |
| Group-attached category-container | 5 | Repair & Restoration, New Builds, Tools/Spaces, Business, Market Place — discussion in subforums; group attachment cascades |
| Subforums of group-attached containers | ~27 | acoustic-repair, electric-builds, etc. — transitively gated through ancestor's group |

### The orphan-gate rule (new, from this audit)

The 5 auto-enroll topic groups are slated for deletion at cutover (frees ~9k junk memberships). Their ~27 subforums hold real content.

**Coordinator ruling:** when an ancestor group is deleted, its subforums fall back to **no-gate** — visible and postable to all authenticated viewers. This matches the current effective state (auto-enroll = everyone), so it's a no-regression cutover.

You need an explicit `effective_group_id` fallback: if the group record is NULL (deleted), treat the forum as ungated. Wire this into your gating logic so cutover day doesn't silently orphan 27 forums.

### What's broken in the mirror right now

1. **`forum.group_id` always NULL** — backfill reads `bp_group_id` postmeta (doesn't exist). Real source: `_bbp_group_ids` serialized PHP array on the forum side, or `groupmeta.forum_id` on the group side (more reliable).
2. **No group table** — `wp_bp_groups` is never mirrored. Can't surface "Local: SoCal Looths" labels or check group membership.
3. **No transitive group inheritance** — subforums of group-attached containers don't carry the group attachment. Doesn't break reads (open), but matters for write-gating.

---

## Authorized work — steps 1–4, in order

### Step 1 — Add group table (authorized, start now)

Mirror `wp_bp_groups`: `id, slug, name, status, member_count, attached_forum_id`. Include sync hooks: `groups_create_group`, `groups_delete_group`, `groups_update_group`. ~30 min.

### Step 2 — Fix `forum.group_id` backfill + sync

Right key: `_bbp_group_ids` (serialized PHP array on the forum), deserialize, take first ID. ~15 min.

### Step 3 — Compute `forum.effective_group_id`

Walk ancestor chain at backfill time. If any ancestor has a `group_id`, this forum inherits it. Maintain via post-upsert recursive pass on sync (ancestor chains are shallow — cheap). This is what the orphan-gate rule fires against.

### Step 4 — Forum-list template: group identity pill

Show "Local: SoCal Looths" pill on group-attached forums using `effective_group_id → group.name` lookup. The 9 regional forums + 5 category-containers get visual identity. ~30 min.

After step 4, reply form JS (queue #1) can wire write-gating mechanically.

Steps 1–4 are ~2 hours total.

---

## Also in your relay queue

See the render-bug bundle (`reply-to-bb-mirror-render-bugs.md`) — 6 issues on `/forums-poc/`, all bb-mirror lane. You can work render bugs and group work in parallel or in sequence; your call.

## Report back

When steps land, update your SESSION-HANDOFF and report back:

```
**BB-mirror → coordinator:** group table + forum.group_id + audit steps 1–4

```
/home/ubuntu/projects/bb-mirror/SESSION-HANDOFF.md
```
```

— coordinator
