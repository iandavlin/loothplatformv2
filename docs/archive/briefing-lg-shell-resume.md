# Lane briefing — lg-shell (resume, 2026-06-07)

You're the **lg-shell** lane — **keeper of the ONE canonical header/footer**. Fresh chat resuming a
stable lane. Successor to `dc066cf4`.

Sanity-check the box: `curl -s ifconfig.me` → `50.19.198.38` = act locally, do NOT SSH. You work in
**`/srv/lg-shared/`** (note: `www-data`-owned — `sudo` to edit, chown back). Commit by pathspec;
coordinator reviews, **git-tsar pushes — no silent pushes**.

## Governance — you own the header (Ian 6/3) [[project_lg_shell_header_keeper]]
- Canonical header = **`/srv/lg-shared/site-header.php`** → `lg_shared_render_site_header($ctx)`
  (`class="lg-chrome"`). WP-independent; works standalone (archive-poc/bb-mirror/events/profile-app/
  membership-pages) AND inside WP (`lg-membership-chrome` mu-plugin requires it).
- **All `/srv/lg-shared/*` changes go through you.** No lane forks its own header — consumers only
  *populate `$ctx`* (e.g. `active_nav` from /whoami), never restyle/re-markup.
- Convergence relay + the finite 3-headers→1 migration: `docs/relay-header-convergence.md`.

## DONE (don't redo)
- **Nav "you are here" active-state** — shipped. `site-header.php` always renders all 6 items + marks the
  current one (`is-active` + `aria-current="page"`, lines ~196–208). Old briefing
  (`briefing-lg-shell-nav-active.md`) is complete.

## Likely focus (confirm with Ian/coordinator before starting)
- **Header at the 640 mobile/desktop split.** The whole site is standardizing on a **640px** mobile
  cutoff — Hub (`hub-mobile-desktop-split.md`) and now profile+map (`profile-map-mobile-desktop-split.md`)
  both split desktop ≥641 / mobile ≤640. The canonical header should follow the **same discipline**:
  a mobile header layer (hamburger/condensed nav) gated at ≤640, **media-gated `<link>` in `<head>`**
  (no-flash, never JS-injected), CSS-arrange not JS-reshape. Confirm scope — this is the probable reason
  for the fresh chat.
- **Header convergence migration** — retire the residual non-`lg-chrome` headers per the relay.

## Boundaries
- You own `/srv/lg-shared/*` solo. Consumers populate `$ctx` only — if a surface needs new chrome, it
  asks you (extend `$ctx`), it doesn't fork. Route contract changes via coordinator.

## Report back (to coordinator)
`DONE · FILES · VERIFIED · BLOCKED`. Report your session ID + outliner title for CHATS-MENU + lineage.
