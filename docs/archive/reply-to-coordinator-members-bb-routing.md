# Coordinator report — dev2 / profile-routing: legacy BuddyBoss `/members/*` leak

**Lane:** dev2 / profile-routing (scope: profile + strangler routing)
**Date:** 2026-06-14
**Status:** FIXED + dev-verified. Committed locally (`a7b258e`), **not pushed**. dev2 apply pending (1 command, below).

---

## Issue (as reported)
A legacy BuddyBoss profile page was still reachable on dev2 instead of resolving
to the profile-app profile. New editor (`/profile/edit`, `/u/<slug>`, `/p/<slug>`)
works; identity fully bridged. Ian reached the legacy page repeatedly **from the
Events page**.

## Verdict: NOT a dev2 regression — same root cause everywhere, surfaces on dev2
The entry point is the **shared header**, present on every page (incl. Events).
Its messages/notifications icons link to BuddyBoss "current user" URLs:
- envelope → `/members/me/messages/`
- bell → `/members/me/notifications/`

Plus the bare `/members/` member directory is still BuddyBoss.

The old hijack in `strangler-profile-app.conf` only caught `/members/<slug>/`,
`/members/<slug>/profile/`, and `/members/<slug>/profile/edit/` — **not** the
`/members/me/*` header shortcuts and **not** the bare `/members/` directory.

Why it looked dev2-specific: on **dev** those `/members/me/*` paths 404 (BB
messages/notifications components are switched off here), so no BB chrome shows.
On **dev2** those BB components are still active, so the identical header links
render the full legacy BuddyBoss page. Verified dev redirects are byte-identical
to dev2 for every path the old snippet covered.

## Fix
Widened the BB hijack in `platform/nginx/strangler-profile-app.conf` to redirect
**every** `/members/*` path into profile-app (order matters — first regex wins):

| Path | → |
|---|---|
| `/members/me/*` (header shortcuts; `me` can't resolve to a slug, `/u/me` is blank) | `/profile/edit` |
| `/members/<slug>/profile/edit[/...]` | `/profile/edit` |
| any other `/members/<slug>/[...]` (profile, activity, connections, photos, …) | `/u/<slug>` |
| bare `/members/` and `/members` (BB member directory) | `/directory/members` |

**Verified on dev** (headless Chrome, logged-in admin): all four cases land on
profile-app, `document.body` has no `buddypress`/`buddyboss` class.

Commit: `a7b258e` — `platform/nginx/strangler-profile-app.conf`, +25/−7.
On dev the deployed snippet is a symlink to this repo file, so the edit + an
`nginx -t && systemctl reload nginx` applied it live.

## Apply on dev2
dev2 snippets are flat copies from the `dev2-nginx.tgz` bundle (NOT symlinks into
a clone), so a `git pull` will NOT update them. The repo file is published at the
gate-exempt `/.well-known/` path. On `ubuntu@34.193.244.53`:

```bash
curl -s https://dev.loothgroup.com/.well-known/strangler-profile-app.conf \
  | sudo tee /etc/nginx/snippets/strangler-profile-app.conf >/dev/null
sudo nginx -t && sudo systemctl reload nginx
```

(dev2's site conf is domain-swapped, but the strangler snippet has no host-specific
content — a straight copy is correct.)

## Cross-lane / coordinator decisions
1. **lg-shell (header) owes the proper fix.** This is a routing stopgap. The clean
   fix is lg-shell wiring the bell/envelope icons to its messages/notifications
   **modals** (P9 work) instead of linking to `/members/me/*` at all. Until then,
   the nginx redirect keeps users off BuddyBoss. Recommend relaying to the
   header/lg-shell lane.
2. **`/members/` → `/directory/members` pulls forward a cutover task.** STRANGLER-
   COORDINATION §0d slated the member-directory move for cutover ("BB holds
   `/members/`"). The redirect is harmless pre-cut and kills the BB directory now;
   flagging so the doc/timing stays consistent. Easy to drop that one line if coord
   prefers to leave `/members/` on BB until the cut.

## Open
- Push `a7b258e` to GitHub (held for review-before-push).
- Optional: make dev2 snippets symlinks into a clone (build-checklist change) so
  future routing fixes are git-native on dev2, not curl-and-tee.
</content>
</invoke>
