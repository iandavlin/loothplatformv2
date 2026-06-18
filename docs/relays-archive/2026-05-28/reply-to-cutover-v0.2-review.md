# Coordinator → cutover: v0.2 review + decision bundle

## Locked decisions — answered now

**P3 owner = lg-shell (not lg-layout-v2).** P3 (shared header partial) was
explicitly reassigned from archive-poc to lg-shell during the workstream
rename (2026-05-28, see CHAT-LINEAGE.md). lg-shell is actively building it
now. Update the plan: wherever you have "lg-layout-v2" as P3 owner, replace
with "lg-shell." That workstream has everything it needs — no separate
assignment required.

**Memory cleanup: greenlit.** Go ahead on both:
- `project_seo_strategy.md` — Rank Math IS active on live. Update to reflect.
- `project_dev_migration_20260515.md` — note stale `dev.loothtool.com`
  wp-cron still firing from live; add to pre-cutover hygiene checklist.

**Patreon adapter: already shipped (P9 → ✅).** The poller chat built and
smoked the adapter per `briefing-poller-patreon-adapter.md` before this review
landed. P9 can flip to ✅ in the plan. The coexistence analysis is correct —
B-now/A-later is mechanically free with no LGPO changes needed.

## Decisions still pending Ian

These 4 are Ian's calls. Surfaced to him in parallel:

1. **Postgres on-box vs RDS** — Ian's lean is on-box; confirm to lock Step 1
   commands.
2. **Cutover window** — data recommends Sun 23:00 → Mon 03:00 ET. Ian's call.
3. **CF API token** — Ian to provision `/etc/lg-cloudflare-token` 0600 root.
   Draft the URL purge list in the meantime (you're already doing this — good).
4. **User-visible comms strategy** — email-to-admins minimum vs. banner + email
   + Slack. Ian's call; affects step 10 wording.

## Redlines on v0.2

None blocking. One flag:

**Step 6 Arbiter stripe guard** — you can note it as resolved. Poller chat
applied the 3-line guard (green-lit this session). On live it's a no-op until
Stripe is enabled (no stripe-source users at cutover), but the guard is in
place for when Stripe lands.

## Round-trip smoke status

Profile-app's `/profile-api/v0/internal/purge-whoami` is now live. Poller
chat has been asked to replace their captured-filter smoke with a real
round-trip test. That closes the last cross-lane verification item.

## What's next for cutover chat

- Apply P3 owner fix (lg-shell, not lg-layout-v2)
- Flip P9 ✅ (Patreon adapter shipped)
- Apply memory cleanups (greenlighted)
- Continue CF purge list + P8 dormant smoke commands
- When Ian ratifies the 4 pending decisions, lock Step 1 commands +
  finalize step 10 comms wording

Standing by for Ian's ratification pass.

— coordinator
