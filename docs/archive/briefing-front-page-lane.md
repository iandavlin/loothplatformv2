# Lane briefing — FRONT PAGE (/front-page/ on archive-poc), 2026-06-11

**You are a lane chat on the dev box** (`curl ifconfig.me` → 50.19.198.38, you are `ubuntu`, full sudo — act locally, never SSH out). Read `~/.claude/CLAUDE.md` first. **Load the `archive-poc` skill before editing rows/config** — it covers the row system, config.json, sponsors, gating, deploy.

## Scope / ownership
- **Yours:** `archive-poc/web/**` as it concerns `/front-page/` (the `view-discover` surface): `index.php` head, `archive.css` (the appended FRONT-PAGE REDESIGN block + row styling), `config.json` rows, `_render-main-row.php`.
- **NOT yours:** the Hub, the standalone article renderer, Buck's overlay JS, nginx.
- **Serving truth:** `/srv/archive-poc` → `/home/ubuntu/projects/archive-poc` (the MAIN tree) — your edits are LIVE on save. Some files are `archive-poc:www-data`-owned: `sudo chown ubuntu` → edit → `php -l` → `chown` back.

## Where it stands (2026-06-11)
- **Classic Landing merged + live** (`2632f93`, from `buck/front-page-classic-landing` @1e7119d): Ian picked Classic Landing + Minimal theme + DM Serif/DM Sans. The centered welcome hero (Dan Erlewine video `2IBxue3zPxE`) is scoped to the **logged-out** public welcome row (`[data-row-id=video-promo-public]`); the member What's-New row keeps two-column and inherits tokens/fonts.
- Buck's concept deck (reference, 10 concepts incl. the picked one): `/home/buck/Sharing/Looth-Group-Front-Page-Concepts.html`.
- The redesign **hides the 4-bullet membership checklist** in the hero — a clearly-marked, trivially-reversible CSS rule. Ian hasn't ruled; ask before restoring or deleting for good.

## Work queue (in order)
1. **Dark mode on /front-page/** — Buck's audit: the vpromo row + event cards (ecards) are unreadable in dark mode. Make the redesign block token-aware (`--lguser-*` + OS `prefers-color-scheme`) so Minimal has a coherent dark counterpart. Verify light+dark, 1280+390, logged-in AND logged-out (the two views differ!).
2. **Logged-in member view pass** — the redesign barely touches the member view (deliberate scoping). Propose to Ian what the member front page should inherit from Classic Landing (typography only? centered hero too?) — mock first, don't ship a member-view reshape without his eyes on it.
3. **Row/rail polish** — events row, Frets / Most-liked / From-Dan rails: align card chrome with the Minimal tokens; fix any spacing seams the redesign exposed.
4. **Checklist decision** — get Ian's call on the hidden membership bullets (restore styled vs delete the rule).

## Protocol
- Commit your own increments promptly in clean, logical, TESTED steps (a git-tsar auto-sweeps the tree — don't leave work uncommitted). **Commit ≠ push. NEVER push** — Ian reviews first.
- archive.css is shared with /archive/ and article pages — keep front-page rules scoped to `body.view-discover` exactly like the existing block. Regression-check /archive/ after CSS edits.
- Verify over HTTP with the gate cookie (`/claim?t=<token>`) + the `chrome-dev-login` skill; screenshot to `/var/www/dev/mockups/` for Ian.
- Cross-lane: Buck's overlay injects site-wide dark passes — if a dark fix looks "already half-done," check `/var/www/dev/app-settings.js` before fighting it; canonical fixes belong in archive.css, then msg buck-COORD so he can drop the band-aid.

## ⚖ PARITY GATE (Ian 2026-06-11 — standing rule, all lanes)
No new user-facing control or section ships on ONE surface without its
counterpart on the other (mobile <=640 / desktop >=641) **in the same change**,
or a written "tabled: <surface>, <why>" note in the commit + report-back.
Generalizes the 6/10 card-chip complement rule. Read-side profile markup is
ONE server render — keep it that way; never viewport-hide a section to fake
parity. Current ruling: profile privacy UI converges on the SLIDER panel
(canonical, both surfaces) — the chip rows retire when it lands.

## Report-back (end of session, verbatim format)
```
FRONT-PAGE LANE report — <date>
SHIPPED: <file(s) + commit SHA(s), one line each>
VERIFIED: <viewports, light/dark, logged-in/out, /archive/ regression check>
OPEN: <queue remainder + new finds>
ASKS: <Ian decisions needed (member-view design, checklist), buck-COORD pings>
```
