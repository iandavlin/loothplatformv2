# docs/atlas — the sacred / canonical reference docs

**THE single forward-looking source of truth for this platform.** Version-controlled,
deploys with the code. One keeper chat maintains these; other chats consult + update through
the keeper.

- **SYSTEM-MAP.md** — how the live system works, end to end (audited from dev2, the canonical
  box). The index of truth for "how it runs."
- **GIT-PROTOCOL.md** — how we use git for this repo (branch-per-task + Ian approves merge).
- **DISCUSSION-SURFACE-CANON.md** — the discussion MODAL (hub-polish.js §4e, desktop+mobile) is the canonical render+compose surface; legacy forum topic pages are not a feature target (Ian 2026-07-09).
- **RECAP-SUPPRESSION-PROPOSAL.md** — measured (on LIVE) proposal for not re-listing things the member has already seen. Three axes, and the measurements decide two of them: read-on-site (**shipped**, 15.8%); already-per-event-emailed (**do not build** — connections/DMs/reactions have no per-event email sender at all, and only 3 `forum.*` bell rows have ever existed on live; the overlap rate is *untested*, not zero); previous-digest (**build** — window start = the last digest that member actually *received*, one batched query over existing FluentCRM tables, no new schema; needs a floor or 348 aged-out items land on 272 members). **Amends THREAD-FOLLOW-SPEC §3.7 — see its §4.**
- **WEEKLY-DIGEST-RECAP.md** — the per-member "Your week" section of the weekly digest: counts + senders + deep links, never content; unread, last 7 days; empty means absent. **§2 is the current truth about what the notification store knows — NOTIFICATIONS-AUDIT.md is stale on that point.**
- **REPLY-IMAGE-COUNT-CEILING.md** — how many images a reply may carry, and where that is actually enforced. Short answer: nowhere on the way in; `_topic-replies.php:53` `LIMIT 1` on the way out. Includes the per-layer ceiling table, every surface that renders a reply, and measured height/weight at 2–20 images.

## Canon authority (Ian 6/19)
`docs/atlas/` is canonical and forward-looking. **All other docs are FROZEN — emergency
reference only** (the rest of `docs/**` and the legacy `~/projects/docs` tree). Do not build
on or update frozen docs; write new canonical docs here, audited from the box. See
`../_FROZEN-2026-06-19.md`. When a frozen doc disagrees with atlas or the box: **atlas + the
box win. Audit the box; don't take docs as gospel.**

Rule: when you change infrastructure, update the matching `SYSTEM-MAP.md` section in the same
task — git and docs stay entwined and accurate to one another.
