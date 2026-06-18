# Briefing — BB-forum mirror chat

You're early enough that we want to lock the shared seam before you
write code. Read `/home/ubuntu/projects/docs/STRANGLER-COORDINATION.md`,
especially §1 (tier vocabulary), §2 (`/whoami`), §3d (BB inventory),
§4 (cutover sequence).

Constraints to design against:

1. **Identity + tier come from `/whoami`.** Don't invent a third
   identity story. Don't read xprofile directly. Don't read
   `wp_capabilities` directly. One contract: `GET /api/v0/whoami`.

2. **Gate buckets are `public | lite | pro`.** That's it. looth1 maps
   to `public`. looth4 maps to `pro`. If you find yourself needing a
   fourth bucket, push back before coding.

3. **First read happens AFTER profile-app cutover.** Until then, you
   can plot, sketch templates, mock data. But don't ship anything
   that touches BB user state, because profile-app is about to take
   identity authority.

4. **Group inventory in §3d.** 9 regional "Local Looths" groups are
   the only real group usage. 5 vestigial auto-enroll topic groups
   will be deleted at cutover. 4 small conversational + 2
   admin/internal stay. Build for the regional pattern first; topic
   groups can probably collapse into archive-poc tags later.

5. **Reskin BB chrome at cutover** — see §3d. Shared header partial.
   You'll inherit it; don't build your own.

Open question to answer back when you have a shape: what's the read
surface? Forum threads only? Activity feed too? Group membership?
Knowing your scope helps us sequence the `user ↔ group` membership
table decision (lives in profile-app eventually, per §3d roadmap).

Report back to the coordinator chat (Ian routes) when you have a
proposed shape.
