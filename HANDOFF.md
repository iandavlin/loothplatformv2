# LANE: social-poster-meta — hook the event script to the FB/IG (Meta) API
(spun 2026-07-12 by keeper, Ian ask: "hooking that event script up to the IG/FB api")

## CONNECTION (you run ON dev2)
Worktree = HERE (~/worktrees/social-poster-meta, off main @155ce7c). ~/loothplatformv2-clean
is KEEPER-ONLY. Never push main. Board = `msg send ubuntu`. Plan-first per THE LOOP.

## TASK
Extend the Showrunner event pipeline so events auto-post to Facebook + Instagram:
- 3 days before air date @ 11:00 ET: FB Page post (horizontal featured.*) + IG post
  (vertical featured_V.*), AI-generated captions, and the post URLs written back to the
  Episodes sheet. (Spec from Ians earlier direction — CONFIRM details in your PLAN:
  timing, caption style/approval, which event types post.)

## ENTRY POINTS (repo-tracked — extend these, monorepo law)
- tools/showrunner-appscript/  (the Sheet Apps Script, repo copy + README)
- platform/mu-plugins/loothdev-sheets-bridge.php (+ .apps-script.gs.txt) — Sheet->WP bridge
- docs/EVENT-SHEET-BRIDGE.md, docs/showrunner-wp-bridge-CUTOVER.md
- Bridge auth = sheets-bot (WP user 1938) App Password; a DB reload wipes it (known).

## ESTABLISHED RULINGS + GOTCHAS (verified vs Meta docs 2026-07-12 — do not re-litigate)
- Meta app EXISTS: "Looth Social Poster", App ID 2452613918567498, Business type, under
  Maxs developers.facebook.com account.
- STANDARD ACCESS SUFFICES — no Business Verification, no App Review, for posting to a
  Page/IG whose admin holds a role on the app. (Advanced Access = other peoples pages =
  not us.) Escape hatch if FB-link friction: "Instagram API with Instagram Login" (2024).
- Token path: app admin role for Ian/Max -> @theloothgroup must be IG BUSINESS account
  linked to the Looth Group Page -> Graph API Explorer long-lived user token -> Page
  tokens from it do not expire. Scopes: pages_manage_posts, pages_read_engagement,
  instagram_basic, instagram_content_publish.
- IG API has NO scheduled_publish_time — the script itself must fire at 11am ET
  (Apps Script trigger). FB DOES support native scheduling.
- IG image_url: PUBLIC direct JPEG, aspect 4:5..1.91:1, <=8MB. Event featured images are
  PNG -> conversion step needed. Vertical featured_V.* is Drive-only today — must become
  publicly hosted (WP media sideload or R2). Meta must fetch from LIVE
  (loothgroup.com/wp-content/uploads/ PROVEN fetchable through CF); dev2 URLs 403 (gate).

## HARD SAFETY RAILS
- NEVER post to the real Page/IG without Ians explicit go in writing on the board.
  Build DRY-RUN FIRST: log the exact Graph API calls (endpoint, payload, image URL)
  without sending. This is the social equivalent of the mail lock.
- NEVER mutate the production Episodes sheet during dev — work against a COPY.
- Tokens/secrets NEVER in the repo, never in sheet cells. Propose storage in your PLAN
  (Apps Script Script Properties vs server-side) before wiring anything.

## HUMAN STEPS (prepare exact click-paths; these need Ian/Max, not you)
App admin role grant, IG business-account link confirm, long-lived token mint. Deliver
these as a numbered 2-minute recipe with your PLAN so Ian can execute them while you build.

## COORDINATION
Maxs dev (Massimiliano) was independently building this from the Tracker; Ian has now
laned it HERE. Do not assume his code is visible to you. Note in your report anything
that needs Max (app roles live under his account). Ian owns telling Max.

## VERIFY (before preview request)
Dry-run evidence: exact Graph payloads for a real upcoming event (FB horizontal + IG
vertical incl. JPEG conversion + public URL), trigger timing proof (11am ET), write-back
to the TEST sheet copy, token flow documented end-to-end. Real-post smoke happens only
after Ians explicit go, ideally to a test/hidden Page first.

## ── SESSION 2 (2026-07-12, fresh box after crash) — APPS SCRIPT SIDE BUILT ──
PLAN partially ACKed by keeper 19:23: (a) Arch B (b) STILL IAN'S: all-tiers vs Public-only
(c) hard Social-Approved gate YES (d) captions default claude-haiku-4-5 as CONFIG value
(e) 11-12 ET window fine (f) verify featured_V vs 3 real Drive assets. Instruction: build
AS side + dry-run scaffolding now; do NOT touch the mu-plugin until (b) lands.

BUILT (tools/showrunner-appscript/Code.gs, +~330 lines, all in one new SOCIAL section):
- Columns 24-29 additive (Social Approved checkbox / FB Caption / IG Caption / Social
  Posted / FB Post URL / IG Post URL) + HEADERS + setupSheet checkbox+widths + admin-protect
  on the 3 back-written cols. Additive at END = existing sheets safe (re-run "Setup Headers").
- CONFIG.SOCIAL block: CAPTION_MODEL='claude-haiku-4-5' (flip point), POST_HOUR=11,
  DAYS_OUT=3, TIERS=null (**the ONE line to flip to ['Public'] when (b) lands**), bridge paths.
- socialRowVerdict_ (eligibility), socialPostRun_(dryRun) (modeled on sendReminders_),
  socialPostDryRun/socialPostLive, socialPostTriggerHandler (dry-run unless
  SOCIAL_LIVE_ENABLED='true' script prop), install/removeSocialPostTrigger (atHour 11),
  draftSocialCaptionsForSelectedRow + buildCaptionPrompt_, testMetaConnection,
  pickVerticalImageFromFolder_ (featured_V.*, cleanly disjoint from horizontal picker),
  parseWpPostId_, modelGraphCalls_.
- TRIPLE gate on LIVE: per-row Social Approved TRUE + global SOCIAL_LIVE_ENABLED + bridge
  endpoint not built yet. Nothing can reach Meta this session.

VERIFIED off-box (node vm + Apps Script stubs): social_test.js 28/28 — gate matrix
(unapproved/posted/too-far/past-air/no-url/missing-caption all BLOCK, GOOD row eligible),
tier flip both ways, image separability, Graph modeling, caption prompt. Dry-run evidence
captured (exact bridge request + FB/IG Graph calls for a real-shaped event) in
~/projects/social-poster-meta-evidence/dryrun-evidence.txt. Code.gs node --check clean.

DEFERRED to next session (needs (b) first): the mu-plugin bridge endpoints
(/social-post, /social-caption, /social-token-status) — PNG→JPEG convert, Graph calls,
token+key server-side. Contract documented in README.

BLOCKED: (f) featured_V aspect — Google Drive MCP token expired on dev2; couldn't measure
the 3 real assets. Crop path is one-line-parameterized so it didn't block the build. Need
Drive re-auth OR the 3 assets dropped somewhere reachable.

Branch social-poster-meta. NOT merged, NOT deployed. Live script must be pasted into the
bound Apps Script by a human (Extensions -> Apps Script) — Code.gs here is the ref copy.
