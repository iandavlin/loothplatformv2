# Briefing — profile-app chat

Coordination doc just landed: `/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md`.
Read it end-to-end before next slice work. Key shifts that affect you:

1. **profile-app cutover is now the unifying event** for the whole
   strangler — not just a slice in your backlog. The reason: templating
   fragmentation between BB pages, lg-layout-v2, and archive-poc has
   pushed it to all-or-nothing. Plan accordingly.

2. **profile-app hosts `/whoami`** from day one. Endpoint
   `GET /api/v0/whoami` — see §2 of the doc for response shape.
   Identity comes from your Postgres post-cutover; tier comes from a
   lookup against WP roles (poller chat will expose a small internal
   endpoint for you to read). 30s Redis cache. Born here — no
   "build in WP first, migrate later" intermediate.

3. **profile-app does NOT store tier locally.** Always reads through
   the WP-side tier lookup (or accepts a JWT claim refreshed every N
   min). Tier authority stays in the poller.

4. **Cutover sequencing constraint** — see §4. `/whoami` must ship on
   dev → archive-poc switches to it → shared header partial → THEN
   `bin/migrate-from-xprofile.php`. BB-mirror reads only after.

5. **looth1 is plumbing, not a tier.** Gate as `public`. Identity-aware
   features check `authenticated`. See §1 + §3c.

Open questions to answer back:
- Confirm `/whoami` shape works for your needs (any fields missing?)
- Confirm cutover ordering is feasible given current slice state
- Flag any dependency on BB-mirror state we don't already know about

Report back to the coordinator chat (Ian routes) when you've read and
have positions on the above.
