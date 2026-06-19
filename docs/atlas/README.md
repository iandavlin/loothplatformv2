# docs/atlas — the sacred / canonical reference docs

Version-controlled, deploy-with-the-code reference for the loothplatformv2 build.
One keeper chat maintains these; other chats consult and update through the keeper.

- **SYSTEM-MAP.md** — how the live system actually works, end to end (audited from dev2,
  the canonical box). The index of truth for "how it runs."
- **GIT-PROTOCOL.md** — how we use git for this repo (branch-per-task + Ian approves merge).

Rule: when you change infrastructure, update the matching section of SYSTEM-MAP.md in the
same task. Audit the box; don't take prose as gospel.
