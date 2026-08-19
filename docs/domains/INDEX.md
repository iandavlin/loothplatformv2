# Domain index — the guide (#142)

One line per domain. A worker is handed ONLY its matching domain file(s) plus
this index — never one huge combined file. Update rule: closing any issue that
wears a domain label updates that domain's file in the same commit.

- **EMAIL.md** — the weekly digest, signup funnel, FluentCRM, SES. Read for any
  issue labeled `email`.
- **PAGE.md** — the lanes status page, its timer, endpoints, and JSON. Label `page`.
- **INFRA.md** — deploy machinery, nginx front door, boxes, guards. Label `infra`.
- **MEMBERSHIP.md** — roles, tiers, content gating, and both payment rails (Patreon + Stripe). Label `membership`.
- **PROFILE.md** — profiles, directory, location model. Label `profile`.
