# Lane briefing — PERFORMANCE (page weight + delivery), 2026-06-11

**You are a lane chat on the dev box** (`curl ifconfig.me` → 50.19.198.38, you are `ubuntu`, full sudo — act locally, never SSH out). Read `~/.claude/CLAUDE.md` first.

## Mission
Lighthouse mobile on the Hub is **Performance 45** — Ian: "we can't launch with a 45."
Get Hub, front page, and a representative article to **≥80 mobile** without changing
how anything looks or behaves. Baseline report: `/var/www/dev/mockups/lighthouse-hub.html`
(FCP 3.0s · LCP 9.1s · TBT 1,010ms · CLS 0 · SI 4.9s).

## Measure first, every time
Headless Chrome runs at `127.0.0.1:9222` (chrome-dev.service) with the dev-gate +
admin cookies already set (re-mint via the `chrome-dev-login` skill if auth drops):
```
npx -y lighthouse https://dev.loothgroup.com/hub/ --port=9222 \
  --output=json --output=html --output-path=/tmp/lh --quiet
```
Re-run after EACH change; keep a small table of score deltas in your report-back.
No change ships without a before/after number.

## Already done — do not redo
- gzip for CSS/JS/JSON/SVG (nginx.conf, 2026-06-11; CSS −75%). HTML was always gzipped.
- CLS = 0 (scrollbar-gutter fix + prior work). Don't re-litigate layout shifts; the one
  open shift bug (header nav ~83px font race) is filed in docs/SESSION-HANDOFF.md.
- Browser caching of HTML restored after a no-cache experiment — do NOT re-add
  no-cache to HTML.

## Work queue (in payoff order, from the Lighthouse run)
1. **Static-asset cache lifetimes.** Versioned assets (`?v=<filemtime>` busting is
   already universal) can take `Cache-Control: public, max-age=31536000, immutable`.
   Add at nginx for css/js/woff2/png/jpg/webp/svg locations. HTML stays as-is.
2. **Render-blocking CSS (~1.6s).** Audit the `<head>` chains (site-header.css +
   per-surface css). Options: preload hints, merge tiny files, defer non-critical.
   ⚠ Do NOT reorder the cascade — overlay/canonical CSS depend on load order;
   visually regression-check (CDP screenshots light+dark, 390+1440) after every move.
3. **JS weight (407 KiB unused + 295 KiB unminified).** The overlay stack
   (`/var/www/dev/*.js`, ~600 KiB raw) ships to every page unminified.
   - Quick win: a `tools/minify-overlay.sh` (e.g. esbuild/terser via npx) producing
     `.min.js` siblings + repointing pwa.js refs. ⚠ Buck hot-edits these files —
     the pipeline must regenerate on his edits (filemtime watch or a deploy step)
     and MUST be agreed with buck-COORD over `msg` BEFORE shipping. If friction is
     high, park minification and document; do NOT minify in place.
   - Unused-JS elimination is the coordinator's absorb-the-overlay track — measure
     and report per-file cost, don't restructure code yourself.
4. **Image sizing (224 KiB).** A cover-resizer endpoint (`img.php`, commit f26ac06,
   nginx route + /var/cache/lg-img) was built 6/10 — wire feed cover/thumb URLs
   through it where they aren't already. Verify the cache dir fills and hit-rate.
5. **LCP 9.1s.** After 1–4, re-profile: identify the LCP element on Hub (likely a
   card cover) and ensure it's compressed, properly sized, not lazy-loaded, and
   early-discovered (preload if needed).
6. **Best Practices 79 / Accessibility 87:** fix console errors + the `[aria-*]`
   role mismatches the report lists (cheap, mechanical).
7. **SEO 58 — mostly IGNORE on dev:** the score is tanked by the intentional
   `X-Robots-Tag: noindex` dev gate. Note real items (meta descriptions) for the
   live cut; don't chase the score number on dev.

## Boundaries
- Visual/behavioral NO-OPs only. Anything that changes pixels goes to the owning lane.
- nginx edits: backup the conf first (`.bak-<task>-<ts>`), `nginx -t` before reload —
  the same conf carries the cookie gate, security headers, and deny rules; touch only
  cache/compression blocks.
- Overlay JS files are Buck's mobile lane + coordinator's desktop scope — you may add
  a minify/serve pipeline around them, never edit their logic.
- forums.css / archive.css content changes → coordinate with the Hub coordinator first.
- Every nginx perf setting you add is a CUT-PLAYBOOK item (live's nginx needs the same)
  — log each one in your report-back under "for the cut playbook."

## Protocol
Commit your own increments promptly in clean, logical, TESTED steps (a git-tsar sweeps
the tree). **Commit ≠ push. NEVER push.** Dev = fixtures only.

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
PERFORMANCE LANE report — <date>
SCORES: <surface: before -> after, per change>
SHIPPED: <change + file/conf + commit SHA, one line each>
FOR THE CUT PLAYBOOK: <nginx/server settings live must replicate>
OPEN: <queue remainder + new finds>
ASKS: <buck-COORD pings sent, coord decisions needed>
```
