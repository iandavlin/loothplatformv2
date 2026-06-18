# BB Theme Decommission Inventory

**Goal at cutover: zero BB-themed pages on the site.** Every URL BB currently renders needs one of: own render, modal, compose-from-existing, or deletion.

This walks the full BB surface area and categorizes each. Key insight: most of it falls out of what we're already building. The genuinely new work is small.

---

## Already covered

| BB URL | Handled by | Notes |
|---|---|---|
| `/activity/` (sitewide feed) | archive-poc front page | Already deployed on live |
| `/forums/`, `/forum/<slug>/`, topic pages | BB-mirror | Live on dev at `/forums-poc/`, flips to `/forums/` at cutover |
| `/members/` (directory) | profile-app `/directory/members` | Built |
| `/members/<slug>/` (profile) | profile-app `/u/<slug>` via BB hijack 302 | Built |
| `/members/<slug>/profile/edit/` | profile-app `/profile/edit` via 302 | Built |

---

## Modal candidates (no full page needed)

| BB URL | Pattern |
|---|---|
| `/notifications/` | Notification bell in shared header → popover with unread list. Mark-read POSTs to BB REST |
| `/members/<slug>/notifications/` | Same modal, user-scoped via `/whoami` identity |
| `/messages/` (inbox) | Message icon in shared header → conversation-list modal |
| `/messages/<thread-id>/` | Thread modal expanding from conversation list. Reply form is a small modal |
| `/members/<slug>/messages/` | Same modals |
| Friend requests, follow/unfollow | Small modal from profile page button (if BP friends is even used) |

**Build target:** lg-bp-mirror — small workstream sibling to BB-mirror. Pattern mirrors BB-mirror:
- Reads `wp_bp_notifications` / `wp_bp_messages_*` tables directly (we share the DB)
- Writes proxy through BB REST so notification side-effects + email + mention parsing keep working
- Exposes small REST API: `GET /api/v0/notifications`, `POST /:id/read`, `GET /api/v0/messages`, etc.
- JS components (bell, message icon, modals) live in the shared header partial (P3)

---

## Groups — collapsed into forums (2026-05-28)

**Updated direction:** the BB-groups primitive collapses entirely.
Each Local Looths group becomes a **forum with extra decoration**
(avatar, custom header, "join" UI relabel). Coord §3d has the full
reasoning.

What this means concretely:

- `/groups/` directory → a category in the forum index ("Local Looths")
- `/groups/<slug>/` → `/forums-poc/<group-forum-slug>/` with custom header treatment
- Group membership → forum subscription (relabeled "join")
- Group activity feed → forum activity (already what the topic list is)
- Group photos/docs → BP modals (if used per audit; mostly empty)
- Group mods → N/A, single sitewide mod (Ian)

What goes away:
- archive-poc group-landing composer (no longer needed)
- archive-poc group-directory rail (no longer needed)
- archive-poc `group_id` filter on rails (no longer needed for group views — but might still want for "show me activity from members of these forums" later)
- `/groups/` URL entirely (or redirects to forum-index)

Word "group" stays as UX label even though primitive is collapsed.

---

## Earlier "compose group landing" plan — superseded

The composition-from-existing-surfaces plan below was superseded by the
collapse above. Keeping for historical reference; do not implement.

<details><summary>Original composition plan (superseded)</summary>

This is the architectural insight you flagged. **A group landing page is a composition of stuff we're already building**, not a new render system:

| Group sub-surface | Composed from |
|---|---|
| Group header (name, avatar, description, join/leave button) | Small template fragment + BB REST for join/leave |
| Group forum | **BB-mirror, filtered by `group_id`** — already in their open-questions queue ("group-scoped forum views — pending profile-app cutover") |
| Group activity feed | **archive-poc, filtered by `group_id`** — their existing rail system, just add a `group_id` filter to the query |
| Group members roster | profile-app `/directory/members?group_id=X` — small filter addition |
| Group photos / docs / videos | **Skip.** Per your earlier note: "no one uses the group functions" beyond reading the feed + forum |

**Net:** group landing page is a thin template that composes three existing surfaces (forum link, activity rail, member fragment). No new strangler needed. Could live in archive-poc (it's discovery-shaped) or as a small standalone composer.

**Group directory** (`/groups/`) is an archive-poc rail: "all groups," each row showing avatar + name + member count + last activity. archive-poc already does avatar-cards-with-meta rails.

**Regional Local Looths groups** (the 9 real ones per coord §3d) get this treatment. The 5 vestigial auto-enroll topic groups get deleted at cutover.

</details>

---

## Composed from archive-poc's existing pattern

| BB URL | Composed from |
|---|---|
| `/members/<slug>/activity/` | archive-poc filtered by `author_id` — just a query param on the existing endpoint |
| `/groups/<slug>/activity/` | archive-poc filtered by `group_id` |
| `/activity/?type=mentions` | archive-poc filtered by mention parsing (if we want it) |
| `/search/` (sitewide) | archive-poc's FTS already does this — just a search box in the shared header |

**Insight:** archive-poc's content_item schema already has `kind`, `author_id`, optional `group_id`, `tier`, `published_at`, FTS. It's a general-purpose activity engine. The "activity feed" surface (sitewide, per-user, per-group, per-tag, search) is one engine, many filter combinations.

---

## Need own templates (not modal, not composed)

| BB URL | What it needs |
|---|---|
| `/register/` | Auth page reskin. Form submits to BB REST or wp-login. Our own template, not BB's. |
| `/wp-login.php` (login + password reset) | Same — reskin in our chrome |
| Group home pages (if composition feels thin) | Could be either composed (above) or its own thin template |

Auth pages are small but high-stakes (broken login = nobody can use the site). Worth a careful pass.

---

## Probably delete

| BB URL | Why |
|---|---|
| `/members/<slug>/friends/` | BP friends feature — unclear if used. Verify usage; if zero, kill the URL |
| `/members/<slug>/photos/` | BP photos — same. If group features are unused, BP photos almost certainly is too |
| `/members/<slug>/documents/` | BP documents — same |
| `/members/<slug>/videos/` | BP videos — same |
| `/groups/<slug>/photos/`, `/docs/`, `/videos/` | Same — BP group media |

**Verification command pattern:** for each candidate-to-delete URL, check WP for: any author-set content (`wp_bp_*` table counts > 0), any inbound links, any user complaints if removed. If all three are zero, kill it.

---

## BP usage audit — dev numbers (pending live verify)

Ran 2026-05-28 against dev WP DB. **These are dev numbers; live may differ
materially — verify with the audit batch in lg-bp-mirror's brief before
locking scope.**

| Metric | Dev count | Read |
|---|---|---|
| bp_friends (confirmed) | 7,346 | **Real** — people friend each other |
| bp_follow | 9,002 | **Real** — follow is heavily used |
| bp_messages (total) | 1,881 | Moderate |
| bp_messages (last 30d) | **0** | **DEAD on dev** — verify live |
| bp_messages (distinct threads) | 370 | Historic only |
| bp_notifications (unread) | 31,260 | **Heavy** — must support |
| bp_notifications (last 30d) | 269 | **Active** |
| bp_media (photos) | 2,598 | Real, mostly forum-post attachments cataloged back |
| bp_media_albums | 23 | Effectively dead — nobody curates albums |
| bp_document | 191 | Low |
| bp_group_documents | 1 | Dead |
| bp_activity (all) | 4,093 | Slow |
| bp_activity (last 30d) | 61 | Slow (matches Ian: "killed user-post-to-activity") |

**Scope implications for lg-bp-mirror:**

- **Notifications:** real priority, full modal + bell + REST
- **Friends + Follow:** real, surface modals
- **Messages:** if live confirms 0-recent-activity, build minimum-viable modal — maybe empty-state with "the forum is where conversation happens"
- **Photos:** modal-show only, empty-state if a profile has no direct photos (most posts cataloged via forum attachments)
- **Albums:** skip or kill
- **Documents:** skip or kill
- **Group documents:** skip

---

## Actually new work (the gap)

After all the above, the actually-new work for cutover is small:

1. **lg-bp-mirror workstream** — small REST API + JS modal components for messages + notifications
2. **Group composition template** — thin shell that includes archive-poc activity rail + BB-mirror forum link + member fragment. Could live in archive-poc.
3. **Group directory page** — archive-poc rail "all groups." Schema addition: maybe expose group as a `content_item` kind, or stand up a small `groups_directory` view
4. **Auth page reskins** — small but careful
5. **archive-poc additions** — `group_id` filter on rail queries, `author_id` filter for per-user activity. Both are query-param additions to existing endpoints.
6. **Verification + deletion** of BP friends / photos / docs / videos surfaces if usage is zero

---

## Coordination shape this implies

- **archive-poc** absorbs the composition work for groups + per-user/per-group activity. Their schema is already a fit. Add `group_id` filter; build group landing composer; build group directory rail.
- **BB-mirror** absorbs the group-scoped forum view (already in their backlog).
- **profile-app** adds the `?group_id` filter to `/directory/members` (small).
- **New lg-bp-mirror workstream** owns messages + notifications. Small scope, mirror of BB-mirror's pattern.
- **Someone owns auth page reskins** — probably whoever picks up lg-bp-mirror, or its own tiny scope.

This adds **one new workstream** (lg-bp-mirror) and expands **archive-poc's scope** by ~3 features (group filter, group landing composer, group directory rail).

---

## Cutover-eligibility addition

The cutover-eligibility checklist (P1–P7) needs P8–P10:

- P8: lg-bp-mirror modals (messages + notifications) live on dev
- P9: Group landing + directory composed surfaces live on dev
- P10: BP friends/photos/docs/videos kill list verified + URLs removed (or accepted as "ugly until later")
- P11: Auth pages reskinned

These are real but small. None block the others in P1–P7 architecturally; they just need to be on the checklist before "zero BB theme pages" is achievable.

---

## Open questions for Ian + coord

1. **lg-bp-mirror workstream — spin up now or after cutover?** Modals + small REST is maybe a week of work. Spin up now if you want zero-BB-theme at cutover; defer if you accept BB-themed messages/notifications pages for a soak window.
2. **Auth page reskins** — own templates or accept BB renders behind a maintenance-mode banner during cutover and reskin within first month after?
3. **BP friends/photos/docs/videos kill decisions** — verify usage and delete, or leave the URLs as "no longer linked from anywhere, accept ugly fallback if anyone has a bookmark"?

Resolving (1) primarily determines whether cutover-eligibility lands "soon" or "after a small additional workstream completes."
