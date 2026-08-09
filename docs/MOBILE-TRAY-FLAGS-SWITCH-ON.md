# Switching the mobile-tray flags ON — dev2 first, live on Ian's word

Merged flag-OFF at `c235225`. This is how the two flags get turned on, and the
argument for why dev2 goes first.

Both flags default `false` in tracked config, so **live stays off until someone
changes a committed file**. dev2 turns them on with a pool `env[]` entry, which is
box-local and never reaches live — the same mechanism, and the same shape, as
`env[LG_BB_MIRROR_FOLLOW] = "1"` already in `bb-mirror.conf`.

| flag | config | read by | pool |
|---|---|---|---|
| `LG_SOCIAL_ACTIONS_SRC` | `platform/config/social-actions.php` | `profile-app/src/Social.php` (renders `/u/`) | `profile-app.conf` |
| `LG_SHEET_EMBEDS` | `platform/config/sheet-embeds.php` | `bb-mirror/web/_chrome.php` (renders `/hub/`) | `bb-mirror.conf` |

## Why the pool and not a preview path

A lane preview gives a branch a URL under `/preview/<lane>/`. That works for a
**page**. It does not work for these two, and the reason is worth stating so nobody
spends an afternoon on it:

- The profile tray does not navigate to `/u/` — `webroot/profile-sheet.js` **fetches**
  the absolute path `/u/<slug>?view=member` from whatever page you are on. It would
  never request a `/preview/...` prefix without changing the client, and the client is
  a document-root file the whole box shares.
- Same for the reader sheet: `hub-polish.js` is served from the document root and
  injected by `pwa.js` into every page. A preview must not repoint a root path
  shared by every other lane. (`hub-seo-landing` hit the identical wall on 8/9.)

So the honest options were "turn it on for dev2" or "show Ian pictures". Pictures
exist (below) but they are not the thing Ian asked for, which is to tap it.

## The flip (keeper runs this; reversible)

```bash
sudo cp /etc/php/8.3/fpm/pool.d/profile-app.conf \
        /etc/php/8.3/fpm/pool.d/profile-app.conf.bak-trayflags-$(date +%Y%m%d-%H%M%S)
sudo cp /etc/php/8.3/fpm/pool.d/bb-mirror.conf \
        /etc/php/8.3/fpm/pool.d/bb-mirror.conf.bak-trayflags-$(date +%Y%m%d-%H%M%S)

printf 'env[LG_SOCIAL_ACTIONS_SRC] = "1"\n' | sudo tee -a /etc/php/8.3/fpm/pool.d/profile-app.conf
printf 'env[LG_SHEET_EMBEDS] = "1"\n'       | sudo tee -a /etc/php/8.3/fpm/pool.d/bb-mirror.conf

sudo php-fpm8.3 -t && sudo systemctl reload php8.3-fpm
```

**Prove it took** — a reload that silently did not restart the workers is the
failure mode that reads as "the flag does not work":

```bash
CK="wordpress_logged_in_...=...;looth_id=..."      # any member session
curl -s -b "$CK" --resolve dev2.loothgroup.com:443:127.0.0.1 \
  https://dev2.loothgroup.com/u/the-guitar-specialist | grep -c data-lg-social-src   # expect 1
curl -s -b "$CK" --resolve dev2.loothgroup.com:443:127.0.0.1 \
  'https://dev2.loothgroup.com/hub/?type=discussions' | grep -c LG_SHEET_EMBEDS      # expect 1
curl -s -o /dev/null -w '%{http_code}\n' --resolve dev2.loothgroup.com:443:127.0.0.1 \
  https://dev2.loothgroup.com/lg-social-actions.js                                   # expect 200
```

**Revert** = delete the two appended lines (or restore the `.bak-trayflags-*` files)
and reload again.

## What Ian should try, on a phone

1. Hub → tap a member's name/avatar → the profile tray opens.
   Tap **···** → a menu appears (Mute / Remove connection).
   Tap **Message** → the conversation opens.
   Only members you are **connected** to show Message; everyone shows **···**.
2. Hub → tap a discussion whose body is a YouTube/Vimeo/Instagram link → the
   reader sheet shows the **player**, not a bare URL. Fixture on dev2:
   `/hub/touring-tech/test-3/`.

⚠️ **Waking these also wakes "Remove connection"**, which deletes a connection with
no undo (it has a confirm step). That is the one thing to be deliberate about, and
the reason this shipped off rather than simply on.

## Going to live

Live has no such pool entry, so live is off. Turning it on there is a one-line
change to each config's `'enabled' => false` — **Ian's call, after he has tapped
dev2**, and it deploys by pull like anything else.

Note the deploy coupling on the first live pull: `webroot/lg-social-actions.js` is a
new webroot file, and `lg-deploy` runs `install-symlinks.sh --new-only`, which
handles it. A hand-run pull that skips that step leaves the flag-ON path 404ing.

## Evidence behind this

- Frames: https://dev2.loothgroup.com/footer-mockups/mobile-profile-tray/
- Merged flag-OFF is byte-identical to pre-merge main `39e659c`, re-proven after the
  merge in the live DB state: both render 9477 bytes, md5
  `b27c3cce9a41f4cc046ea43d1eeef961`.
- Gate 19 (`tools/gates/social-actions-wired-gate.py`), 14 assertions, asserts every
  flag state so switching the default needs no gate edit.
- Repro/verify drivers: `tools/exercise-harness/browser-profile-tray-repro.py`,
  `browser-sheet-embed-repro.py`.
