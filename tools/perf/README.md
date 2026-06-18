# perf-czar harness

Server + browser perf measurement for dev.loothgroup.com. Results live in ../../docs/PERF-BASELINE.md.

## Login (both scripts need it)
Mint cookies into ~/.perfck/ (gate.txt, wpcookies.tsv, jwt.txt) per the chrome-dev-login skill.
The archive front calls /profile-api/v0/whoami directly, so a looth_id JWT is required or the viewer
reads as anonymous. NOTE: /tmp is wiped between sessions — keep cookies + scripts out of /tmp.

## ttfb-sweep.py — server response time (curl, isolates server from front-end)
`python3 ttfb-sweep.py` → median/min/max TTFB of 9 samples per surface, anon vs logged-in, + backend APIs.
Routing gotchas: `/` 302→/archive-poc/ ; `/members/<slug>/` 302→/u/<slug> ; cards API base = /archive-api/v0.

## perf-capture.py — browser Core Web Vitals (CDP)
`python3 perf-capture.py "<label>" "<url>" 5` → LCP/TBT/CLS/transfer/requests. Take MEDIAN of 3 (single
runs swing 2–4× on client-rendered pages).
