# Maintenance write-freeze gate

A one-flag deploy gate: pauses **member writes** (forum posts, profile edits, reactions,
sign-ups) and shows a "we'll be right back" modal, while **reads stay up**. Internal/system
calls (materialize, auth, whoami) are exempt so a deploy can run behind it.

## Toggle (no nginx reload)
- ON:  `sudo touch /etc/nginx/lg-write-freeze.flag`
- OFF: `sudo rm /etc/nginx/lg-write-freeze.flag`
The flag is checked per-request (`-f`), so it flips instantly.

## Pieces
- `platform/nginx/lg-write-freeze-map.conf` — map (http): `$lg_write_target` = 1 for gated writes.
- `platform/nginx/lg-write-freeze.conf` — server snippet: returns clean 503 on gated writes when
  the flag is set, and injects `lg-maint.js` into every page.
- `lg-shared/lg-maint.js` — the modal + a `fetch` interceptor that re-shows it on any write 503.
- `lg-shared/errors/50x.html` — branded page for real 5xx (separate; the gate uses a clean 503).

## Install (idempotent, per box)
    sudo platform/bin/install-maint-gate.sh
Reproducible from the repo — a fresh box gets the gate by running the installer after a pull.

## Use during a deploy
1. `touch` the flag → modal up, member writes frozen.
2. Deploy (poller patcher, bridge install, etc.).
3. `rm` the flag → writes resume.
