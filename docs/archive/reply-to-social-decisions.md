# Coordinator → social (e9fd24ab): decisions RULED + one schema revision

Great recon — the live-BB grounding is exactly what was needed. Ian ruled all four;
canon in `STRANGLER-COORDINATION.md` → "Social decisions RULED."

## Rulings
1. **Follow DROPPED as a user feature.** Connections = **mutual only**. If a feature
   needs a follow signal, **auto-follow on connect** (derived, not a separate graph/UI).
   **Do NOT migrate `wp_bp_follow`** — those 9,002 one-directional follows don't carry.
2. **Messaging = connections-only** — a **mutual connection gates DM** (preserve current
   BB behavior; connect first, then message). No any-member DM.
3. **Notifications: start FRESH** — don't port the 49,603 BP rows; **seed current-unread**
   DMs + pending connection requests so the bell isn't empty at cut.
4. **Counts** — badge **caps at "9+"** for display (endpoint returns the true count);
   **+ a retention job auto-deletes notifications older than 30 days** (the DM/connection
   persists; only the bell alert prunes). Header counts via dedicated **`me-social-counts`**
   (additive — confirmed, no `/whoami` change).

## One schema revision → then dev-final
**Drop the `follow` type/graph** from `2026-05-30-social-layer.sql` (connections are
mutual; auto-follow derived if ever needed). After that the social schema is **dev-final**.
The rest is build work, not schema: the **DM connection-gate** (logic), the **9+ badge**
(UI), the **30-day prune** (cron), and the still-to-scaffold `Notifications.php` +
`api/v0/me-notifications.php`.

## Sequencing
- **Serialize the `profile-app` tree** — profile-2.0 just finished spine increment 1
  (schema applied to dev). Coordinator runs one `profile-app` turn at a time; don't run
  while a profile-2.0 turn is live. Commit by pathspec (your social files only).
- Revise the schema (drop follow) + report; coordinator will apply the social schema to
  dev alongside the spine as the reviewed step. **Crib stays gated** until both schemas
  are dev-final.
- lg-shell will agree `me-notifications` + `me-social-counts` shapes through coordinator.
- profile-2.0 still owes you the one-line `Social::renderProfileActions()` slot on `/u/`.

— coordinator
