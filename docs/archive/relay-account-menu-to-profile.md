# Account menu → user's new /u/ profile page (two coupled tickets)

Ian wants the header user/account menu to drop the user on their **new profile page**
(`/u/<slug>` — the profile-2.0 page with blocks/location/connections), not just the editor.
The header has no slug to build that URL, so we use a stable **`/u/me`** self-link that
profile-app resolves. Bonus: this also fixes the original `/u/me` → "not found" bug.

---
## → lg-shell  (header, shell-only)
In `/srv/lg-shared/site-header.php`, the account dropdown (`lg-chrome__account-menu`)
currently has only **"Edit Profile" → `profile_url`** (`:302`). Add a **"My Profile"**
item above it pointing at the static **`/u/me`** (no slug needed — profile-app resolves it).
Keep "Edit Profile" → `/profile/edit`. No ctx change, no JS.
- Done: in-browser smoke (My Profile lands on your /u/ page) + mirror to `lg-shell/lg-shared/` + commit by pathspec.

## → profile-2.0 / profile-app  (resolve `/u/me`)
In `web/u.php`, special-case **`slug === 'me'`**: resolve the viewer via
`Auth::currentUser()` (with the same WP-session→`/wp-json/looth/auth/issue` mint hop
`edit.php` uses, so a cookie-only user still resolves), then render **that user's own
profile page in view mode** (the View-as owner default) — i.e. land on `/u/<their-slug>`,
NOT bounce to the editor. If anonymous → login interstitial.
- ⚠️ If u.php currently bounces owner self-view straight to `/profile/edit`, change that so
  `/u/me` shows the **profile page** (the whole point) — the Edit affordance + View-as
  toggle stay on that page. The "Edit Profile" menu item already covers the editor.
- Done: `/u/me` logged-in → your profile page; anon → login.

---
Order: independent + additive. Shell can ship the `/u/me` link immediately; it 404s only
until profile-app lands the resolve, then lights up. No contract/shape change either side.

— coordinator (relaying Ian)
