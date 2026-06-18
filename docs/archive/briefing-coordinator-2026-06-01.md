# Briefing — coordinator fresh boot (2026-06-01)

You're the coordinator for the Looth Group strangler rollout. This doc gets you
current as of 2026-06-01 evening. Read in order:

1. **This file** (already done)
2. **`docs/STRANGLER-SESSION-HANDOFF.md`** — the live state. Everything shipped this
   session, what's next, lane roster. This is your primary orient.
3. **`docs/STRANGLER-COORDINATION.md`** — the durable cross-cutting contract.
4. **`docs/CHATS-MENU.md`** — live roster of chat session IDs.

---

## Your job (one paragraph)
Hold the cross-cutting contract. Lane chats build; you keep the contract honest,
ratify cross-cutting decisions, write relays, route via Ian (the human bus), and
update docs as decisions land. You DO wire nginx + deploy (you're also box sysadmin
`ubuntu`). You do NOT touch lane files directly.

---

## What shipped this session — brief (full detail in STRANGLER-SESSION-HANDOFF.md)
- **Forum → "The Hub"** — `/hub/` canonical, 301s from `/forum/` etc. All lanes aligned.
- **`/manage-subscription/` standalone** — read-only Patreon + admin Stripe iframe. LIVE.
- **Social modals (lg-shell)** — fixed against real endpoint shapes, verified.
- **Footer cleanup** — BB-themed links removed (lgjoin, request-refund, shops).
- **Poller** — P4 + P8 done. Cutover-ready. Stripe-A-later for 8 money pages.
- **nginx CPT catch-all** — 9 redundant location blocks collapsed to one (`f6c9457`).
- **Buck onboarded** — 2nd dev on profile + member map lanes. Never two profile-app turns at once.
- **Tree: clean + pushed to origin/main**.

---

## Immediate priorities (in order)

**1. lg-shell queue — hand these to the shell chat:**
- `docs/relay-to-shell-unified-social-modal.md` — Messages + Connections → one tabbed modal
- `docs/relay-to-shell-profile-url-doc.md` — My Profile → `$profile_url` (`/u/<slug>`)
- `docs/relay-to-shell-nav-loothtool.md` — nav cleanup (loothtool.com links, /members/ redirect)

**2. profile-app queue:**
- `docs/relay-to-profile-app-location-default.md` — schema default location_visibility→members, precision→city
- `docs/relay-to-profile-app-users-wpids.md` — `?wp_ids=` endpoint for author bio (unblocks v2 + archive-poc)

**3. standalone launch batch:**
- `docs/relay-to-standalone-launch-batch.md` — calendar/sponsors/about, weekly-email archive
- Archive-poc sidebar: remove "Add Forum Post" + "Member Map"; add "Report a Bug" modal; update Weekly Email link

**4. Sponsor content conversion:**
- 12/13 sponsor-posts have no v2 layout. Use `write-article-v2` skill to convert.
- sponsor-page (5), sponsor-product (16): 0 blobs, not yet in materializer's managed CPT list.

**5. Buck lanes (profile + map):**
- Buck works in `~buck/looth-platform`, branches + commits; coordinator fetches, reviews, merges.
- `profile-app/web/directory-members.php` is currently dirty (map work in progress — leave alone).
- Editor chat (profile page gap between View-as bar + header card) may still be active.

---

## Key ops reminders
- **Commit by pathspec always** — shared tree, multiple lanes.
- **`/srv/lg-shared/*`** is www-data-owned, NOT in git → mirror to `lg-shell/lg-shared/` after every edit.
- **nginx:** always diff repo vs deployed before syncing. Backups live at `.bak.*` timestamps.
- **Never two profile-app turns at once** — buck + any active profile lane = collision risk.
- **idle-hold:** `touch /tmp/no-idle-shutdown` before a background turn, `rm` after.
- **Full UUID for `claude --resume --print`** — short id errors out.

## How Ian works
- Fast, terse, pushes back hard when something's wrong — accept corrections, revise loudly.
- Wants concrete evidence (file:line, DB rows, curl output) not assertions.
- Uses canonical relay format (per `feedback_relay_link_format` memory).
- Tree is the source of truth — memory/notes are secondary.

## Architecture state (post-audit)
- **Biggest remaining dumb thing:** 3 separate whoami implementations (archive-poc/config.php,
  membership-pages/lib/whoami.php, profile-app/src/Whoami.php) — post-cutover cleanup, not urgent.
- **Activity strip cold-start sync fetch** — partially mitigated; live with it for now.
- Everything else from the audit is LOW/ignore.

---

*Prior coordinator sessions: `c047417b` (2026-06-01 morning), `051cef47` (this session).*
*Prior handoffs: `docs/strangler-handoffs/2026-05-31-evening.md`.*
