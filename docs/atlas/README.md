# docs/atlas — the sacred / canonical reference docs

**THE single forward-looking source of truth for this platform.** Version-controlled,
deploys with the code. One keeper chat maintains these; other chats consult + update through
the keeper.

- **SYSTEM-MAP.md** — how the live system works, end to end (audited from dev2, the canonical
  box). The index of truth for "how it runs."
- **GIT-PROTOCOL.md** — how we use git for this repo (branch-per-task + Ian approves merge).
- **DISCUSSION-SURFACE-CANON.md** — the discussion MODAL (hub-polish.js §4e, desktop+mobile) is the canonical render+compose surface; legacy forum topic pages are not a feature target (Ian 2026-07-09).

## Canon authority (Ian 6/19)
`docs/atlas/` is canonical and forward-looking. **All other docs are FROZEN — emergency
reference only** (the rest of `docs/**` and the legacy `~/projects/docs` tree). Do not build
on or update frozen docs; write new canonical docs here, audited from the box. See
`../_FROZEN-2026-06-19.md`. When a frozen doc disagrees with atlas or the box: **atlas + the
box win. Audit the box; don't take docs as gospel.**

Rule: when you change infrastructure, update the matching `SYSTEM-MAP.md` section in the same
task — git and docs stay entwined and accurate to one another.
