# LANE — mentions-mobile: finish @username mentions on the phone surfaces (browser-harness verified)

You are a keeper-spawned lane on dev2 as `ubuntu`, worktree ~/worktrees/mentions, branch
`username-mentions-finish` (@e771ad5, pushed; base = current main + the mentions feature).
Monorepo law: everything committed, nothing live-only. No push→merge without keeper/Ian
sign-off. Board: `msg send ubuntu "mentions: <update> -- mentions"`; read `msg inbox`.

## DONE and PROVEN on desktop (2026-07-23, real writes — do not redo, do not regress)
- /profile-api/v0/mention-suggest 200s ranked (PDO ESCAPE bug fixed — keep the '!' escape).
- reply.php post-insert kses-off re-mint: stored replies carry
  <a class="bp-suggestions-mention" href="{{mention_user_id_N}}">@slug</a>
  (BB REST strips pre-mint anchors; data-lg-uuid dropped by a 2nd filter — acceptable).
- notify-bridge parses {{mention_user_id_N}} → profile-app bell. Evidence: PG db
  profile_app, table notifications, rows 550/551 from probe reply 72163.
- Handles are READ-ONLY (Ian ruling 2026-07-19). Never reintroduce editing.

## BROKEN — your job
After e771ad5 retargeted the mobile sheet from BB-REST direct to /bb-mirror-api/v0/reply,
Ian's iPhone shows: (1) a ghost modal behind the accessible one; (2) still NO autocomplete
dropdown in the sheet; (3) reply tray disappears after posting + background hub scrolls
(scroll-lock lost). Suspects: response-shape mismatch in the retargeted submit handlers in
webroot/hub-polish.js (~3678, ~4072 — our API returns {ok, reply_id, content_html…}, 202 on
moderation); PWA service-worker stale-mix; composer selector coverage (matcher now includes
textarea.lcp-input, #lrs-comp-input, textarea.lg-fb-replyinput). Ian's mobile specimen:
reply 72164 (plain text, no bell — pre-retarget path). ALSO: a NEW DISCUSSION (topic
create) never mints nor rings — find the topic-create endpoint, wire the same post-insert
mint + notify-bridge mention leg.

## MANDATORY: real-browser harness, not curl smoke
Your verification standard is a REAL headless-Chrome session driving the REAL UI. Use the
chrome-dev-login skill; working scaffolding from a prior session sits in
/tmp/mentions-verify/ (launch-chrome.sh — /opt/lg-chrome, --headless=new,
--remote-debugging-port=9222, --host-resolver-rules="MAP dev2.loothgroup.com 127.0.0.1";
cdp.py; wp-cookies.env with a logged-in admin cookie). Kill stale chrome first
(pgrep -af chrome-profile).
- MOBILE runs: Emulation.setDeviceMetricsOverride width=390 height=844 dpr=3 mobile=true
  + Emulation.setTouchEmulationEnabled — and interact with Input.dispatchTouchEvent taps,
  not element.click(), so you exercise the same paths a finger does. DESKTOP runs: 1280px.
- Type with real input events (Input.insertText / dispatchKeyEvent) — setting .value
  does NOT fire the autocomplete's listeners. Never call form.submit(); real button
  taps/requestSubmit only.
- Capture EVIDENCE for every leg into /tmp/mentions-verify/run-<n>/: screenshot per step,
  the Network log proving /profile-api/v0/mention-suggest fired and its response, DOM dump
  of the dropdown, DOM dump of the sheet stack (to catch the ghost-modal), the posted
  reply's stored row (wp db query), and the profile_app.notifications rows after each post.
- Regression sweep for Ian's three symptoms specifically: (a) exactly ONE sheet in the DOM
  when the composer is open; (b) dropdown appears over the sheet at 390 with the keyboard
  math applied; (c) after Post, the sheet closes cleanly AND body scroll-lock releases
  correctly — assert document/body overflow state before, during, after.
- Known CDP traps (hard-won): silent exit-0 usually means navigation killed the socket;
  screenshots are viewport-only; re-verify after any overlay closes.
- A leg without its artifact is NOT passed. Report failures plainly with the artifact.
- Boundary: headless Chrome ≠ iOS Safari/PWA. When your harness is fully green at 390+1280,
  post a report and request Ian's real-phone pass as the final gate (his click outranks
  your green harness). PWA staleness on his device: recommend private-tab first.

## Serve etiquette
The dev2 serve runs MAIN. To test your branch: announce on the board, record prestate,
`git -C ~/loothplatformv2-clean checkout --detach <sha>` + `sudo systemctl reload
php8.3-fpm`, test, then RESTORE `git checkout main` + reload. Never leave the serve
flipped. Loopback curls need -H "Host: dev2.loothgroup.com". wp_rest nonce from
/bb-mirror-api/v0/auth. Reply throttle ~10s (admin bypasses). Mikelle = two identities
(mikelle-davlin2 resolves, wp 1953; mikelle-davlin doesn't) — do NOT merge them.

## Deliverable
Harness-green mobile mentions (dropdown + minted storage + bell + clean sheet lifecycle),
topic-create mentions wired, desktop intact, serve on main, test replies
72162/72163/72164 + yours deleted with their notification rows. Evidence-backed report,
then HOLD for keeper review + Ian's phone pass before merge.
