# Member-map Connect buttons — ready to deploy (blocked on root)

**Status:** built + `php -l` clean, STAGED. Blocked only by the clobbered root key
(can't write canonical `/home/ubuntu/projects`). One-shot deploy below.

## What it does
Adds a **Connect** button to each member card in the directory/member-map. The button
reflects the viewer's relationship and is wired to the existing connections API
(same backend the `/u/` profile Connect widget uses):
- `none` → **Connect** → `POST /profile-api/v0/connections {addressee_uuid}`
- `pending_in` → **Accept** → `PATCH /profile-api/v0/connections/<id> {action:accept}`
- `pending_out` → **Requested** (disabled), `accepted` → **Connected** (disabled)
- `blocked` / own card / logged-out viewer → no button
Optimistic UI; click is `stopPropagation`'d so it never triggers the card's map-zoom/nav.

## Files (2) — both edited from canonical, lint-clean
Staged at `/srv/lg-push/staged/connect-buttons/`:
- `api-directory-members.php` → `/home/ubuntu/projects/profile-app/api/v0/directory-members.php`
  - Adds a batched, viewer-relative `connect:{state,id}` to each `items[]` entry (logged-in only).
- `web-directory-members.php` → `/home/ubuntu/projects/profile-app/web/directory-members.php`
  - Renders the button in a new `.dir-card__foot`, injects scoped brand-token CSS, adds the click handler.

## Deploy (when root is restored)
```
cd /home/ubuntu/projects
cp profile-app/api/v0/directory-members.php profile-app/api/v0/directory-members.php.bak-pre-connect
cp profile-app/web/directory-members.php     profile-app/web/directory-members.php.bak-pre-connect
cp /srv/lg-push/staged/connect-buttons/api-directory-members.php profile-app/api/v0/directory-members.php
cp /srv/lg-push/staged/connect-buttons/web-directory-members.php profile-app/web/directory-members.php
php -l profile-app/api/v0/directory-members.php && php -l profile-app/web/directory-members.php
git add profile-app/api/v0/directory-members.php profile-app/web/directory-members.php
git commit -m "directory: viewer-relative Connect buttons on member-map cards"
```
No nginx change needed (connections routes + the directory API are already live).

## Verify (logged-in, mobile)
Open `/directory/members`, confirm a **Connect** button on other members' cards (none on your own),
tap it → becomes **Requested**; a member who already requested you shows **Accept** → **Connected**.
Note JS is embedded in the PHP file so `php -l` validates PHP only — eyeball the rendered cards.
