# Coordinator → lg-shell: shared-header fixes (from /forum nav audit, 2026-05-29)

I CDP-audited the shared header on `/forum/` (now the canonical forum path —
`/forums-poc/` + `/forums/` 301 to it as of today). Three header items, owned by
you (`/srv/lg-shared/site-header.php`):

## 1. 🔴 Forum nav link still points at the retired path — REPOINT to /forum/
`site-header.php:142` links **Forum → `/forums-poc/`**. That path now **301s to
`/forum/`**, so the header's own Forum link takes an extra redirect hop on every
click (and it's the one nav item pointing at a dead-walking path). Change it to
**`/forum/`**. This is the coupling bb-mirror + I flagged for the 301 — land it
to close the loop. (Two instances showed in the DOM — desktop + mobile nav —
catch both.)

## 2. 🔴 Logo 404'd on dev — default URL is fragile
`site-header.php:92` default:
`logo_url ?? 'https://loothgroup.com/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png'`
— and a consumer was rendering it against the **dev** host, where the file did
not exist → broken logo on every page. **I seeded dev** by copying the real logo
from live to `/var/www/dev/wp-content/uploads/2024/05/Looth-Group-Logo-Site-Menu.png`
(renders now). But the code default is brittle: it hard-points at a live absolute
URL. Recommend either (a) a host-relative default (`/wp-content/uploads/.../…png`)
so each env serves its own, or (b) a bundled `/srv/lg-shared/` logo asset that
can't 404. Your call on the mechanism — just make the default un-404-able.

## 3. 🟡 Active-nav not highlighting on /forum/
The Forum item has `aria-current` unset when you're on the forum. Per §0a the
**consumer** passes `active_nav`; bb-mirror isn't passing `active_nav='forum'`
yet (routed to bb-mirror separately). But confirm the header's nav-key for the
Forum item matches whatever string consumers will pass (`'forum'`) so it lights
up once bb-mirror sends it. If the key is `'forums'` or `'forums-poc'`, align it
to `'forum'`.

## Not yours (FYI)
- Duplicate forum categories + the gravatar-fallback avatar breakage → bb-mirror.
- The 301 is already live; #1 is non-breaking (link bounces) but should land to
  retire the hop.

§0: edit in the repo copy (`lg-shared/site-header.php`), deploy, commit by
pathspec, push. Ping when the Forum repoint lands and I'll re-audit.

— coordinator
