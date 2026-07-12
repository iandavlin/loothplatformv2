# LANE: loothalong-newtab (small) — spun 2026-07-12 by keeper

## CONNECTION (you run ON dev2)
Worktree = HERE (~/worktrees/loothalong-newtab, branch loothalong-newtab off main @155ce7c).
~/loothplatformv2-clean is KEEPER-ONLY. Never push main. Board = `msg send ubuntu`.
Origin-direct test: curl -sk --resolve dev2.loothgroup.com:443:127.0.0.1 https://dev2.loothgroup.com/<path>

## TASK (Ian, verbatim intent)
"I would like the loothalong links on event page and in events to open in new tab,
not close loothgroup.com tab." I.e., clicking Loothalong must NOT navigate the
loothgroup.com tab away to Zoom — open in a new tab, original tab stays put.

## RECON (keeper)
- webroot/loothalong.php = gated join redirect (302 to the Zoom room; room URL must
  NEVER ship to the browser — do not change the gating, only how links TARGET it).
- webroot/loothalong.js = pinned CTA banner at the top of the Events landing
  (injected via pwa.js, pinned ?v=4 — a change needs a v-bump in webroot/pwa.js AND
  it is a WEBROOT COPY: keeper does the docroot cp at deploy; note in runbook).
- Also matches in: events/ (landing), archive-poc/web/index.php, membership-pages
  (guide), poller WelcomeMailer. SCOPE = the events surfaces (landing CTA + links in
  event pages/content). List the others in your report as follow-up candidates; do
  not change mail templates.
- Event body content may carry loothalong links from the Sheet bridge — if so, fix at
  RENDER layer so existing rows are covered, not at import.

## CONSTRAINTS
- Prefer <a target="_blank" rel="noopener"> over window.open (popup blockers).
- BOTH form factors get the same behavior (the banner is one file; verify 390px + 1280px).
- Plan-first (lightweight): post a short PLAN to the board (files + approach), keeper
  acks fast — this is a small lane, do not gold-plate it.

## VERIFY (before preview request)
CDP: click the CTA and an in-event loothalong link → NEW tab opens (target confirmed),
original tab still on the events page, /loothalong.php still 302s anon → login and
member → Zoom (do NOT follow/print the Zoom URL in evidence). No console errors.

---

# LANE REPORT — loothalong-newtab (build complete 2026-07-12)

## WHAT CHANGED (4 files, +29/-3)
1. **webroot/loothalong.js** — the pinned Events-landing CTA. The join anchor now
   sets `a.target='_blank'` + `a.rel='noopener'` (aria-label notes "Opens in a new
   tab"). Same server-gated `/loothalong.php` href — no room URL added.
2. **webroot/pwa.js** — cache-bust `loothalong.js?v=4 → v5`.
3. **lg-layout-v2/blocks/wysiwyg/render.php** — RENDER-layer rewrite: any
   `<a href="…loothalong.php…">` in body content gets `target="_blank" rel="noopener"`
   when it doesn't already declare a target. Guarded by `stripos($html,'loothalong.php')`
   so it is a strict no-op for all other content. Covers existing Sheet-bridge rows
   without an import pass; trusted-HTML block, matches the "trust wp_kses_post" note.
4. **archive-poc/web/index.php** (:777) — member-only bento loothalong link gets
   `target="_blank" rel="noopener"` (keeper's call: include, "in events" intent).

No gating touched: `/loothalong.php` still resolves + 302s the Zoom URL server-side.

## VERIFICATION DONE (from this lane)
- `php -l` clean (both PHP files); `node --check` clean (both JS files).
- **loothalong.js executed through a DOM shim** (real file, not a mock): built anchor
  = href `/loothalong.php`, target `_blank`, rel `noopener`. Runs error-free.
- **wysiwyg regex unit-tested in real PHP, 8 cases**: bare anchor, full URL, single-
  quote+query, extra attrs → all get target+rel; already-`target`, unrelated href,
  and text-only mention → all left untouched; existing `rel` not duplicated.
- **Live curl (origin-direct dev2)**: `/loothalong.php` anon → 302 to wp-login
  (gating intact); `/events/` → 200. Baseline confirms docroot still serves v4 /
  no-target (my change not yet deployed).

## COULD NOT DO FROM THIS LANE — needs keeper
- **Live CDP click-verify**: the shared chromium (9222) is in a container this lane
  can't reach (no docker / no bridge in my netns). It is also moot until deploy —
  loothalong.js/pwa.js are WEBROOT COPIES, so the new behavior isn't on the live
  docroot until the cp below. Please run the CDP click-verify (or eyeball in preview)
  after the cp: CTA + an in-event loothalong link → new tab, original stays on
  /events/, /loothalong.php still 302s (don't print the Zoom URL).
- **run-all.sh gates**: gates 1/2/3/5 read `dev.loothgroup.com.conf` + fetch
  `https://dev.loothgroup.com` — this box is **dev2**, so they error on host/conf
  mismatch (pre-existing, not from this diff). Gate 4 (paragraph-collapse, repo-only)
  is GREEN. The craft-gate dimension (images / weight / eager scripts) is not
  implicated: no new images, no new `<script>`, no eager loads — just two anchor
  attrs + a guarded render-time regex. Please run the full suite from the clean
  checkout at deploy per usual.

## DEPLOY NOTES (for the cut runbook)
- **Docroot cp (webroot copies):** `webroot/loothalong.js` and `webroot/pwa.js` →
  live docroot. The `v=5` bump in pwa.js is the cache-bust so returning browsers pull
  the new loothalong.js.
- **lg-layout-v2** wysiwyg render + **archive-poc** index deploy by their normal
  plugin/app path.

## OUT OF SCOPE — follow-up candidates (report only, not touched)
- membership-pages guide (loothalong reference) and poller **WelcomeMailer** (mail
  template) — left alone per scope (do-not-touch mail).
