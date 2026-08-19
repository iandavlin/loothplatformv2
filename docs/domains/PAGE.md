# PAGE — the lanes status page

## The map
https://dev2.loothgroup.com/lanes/ (+lanes.json). Renderer:
tools/lanes-page.py, run by lanes-page.timer every 5 min AS ubuntu; data from
`lanes --json` (tools/lanes-status.sh) + GitHub issues (token in
/etc/looth/env, server-side only). Sections in order: capacity · deploy gap ·
AT RISK/UNBACKED/COLLISION · NEEDS YOU (plan-ready sans approved; Approve
button + copy buttons) · In motion (`investigating` label) · building ·
seats table · parked · reconciliation · cleanup · 7-day shipped strip.

## Rules that are load-bearing
- **Quiet-when-healthy**: sections are ABSENT when clean; silence only ever
  means healthy — failures render LOUD (UNKNOWN live read, GitHub unreadable).
- The page prints its generation time: an old timestamp = dead timer.
- **No token in the browser, ever** (Ian's ruling): the page reads via the
  box; acting goes through webroot/lanes-approve.php — one verb (add
  `approved`), per-day HMAC nonce derived from the token, POST only.
- Static regen only: the web user can't run git (dubious ownership) — that's
  WHY it's a timer, not on-request.

## Issue history
#133 copy buttons · #137 In-motion · #139 approve button · #140 new-tab links
(all closed 8/19). Open: #143 resource strip + refresh button (planned).
