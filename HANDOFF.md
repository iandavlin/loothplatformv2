# LANE: messages-search — find a message, not just a chat (Ian 2026-07-13)

## CONNECTION (you run ON dev2)
Worktree = HERE. Branch messages-search off main. ~/loothplatformv2-clean is KEEPER-ONLY.
Board: `msg send ubuntu "text -- messages-search"`. 3-line plan to the board, keeper acks
fast, build. Origin-direct:
curl -sk --resolve dev2.loothgroup.com:443:127.0.0.1 https://dev2.loothgroup.com/<path>

## THE ASK
Search MESSAGES — the words inside conversations — across every thread the viewer is in
(1:1s AND groups). Both surfaces (lg-shared/social-modals.js desktop, webroot/messenger-sheet.js
mobile). Parity gate stands.

## WHAT EXISTS TODAY (do not mistake it for the feature)
The mobile sheet ALREADY has a search box (`.mg-search` / filterThreads) — but it only filters
the LOADED THREAD LIST client-side, matching peer NAMES and the last_snippet. It cannot find a
message inside a conversation, and it never asks the server. Desktop has no search at all.
So: extend the mobile box to do real search, and give desktop the same affordance.

## SERVER (the whole feature lives here — client-side filtering cannot do this)
New endpoint, viewer-scoped, on the existing collection-route pattern (avoid a new nginx
path-capture if you can — see how notif-delete did it with a query param):
  GET /profile-api/v0/me/messages/search?q=<term>
Rules, ALL server-enforced (client gating is how the last privacy bug shipped):
- Only messages in threads the viewer is a PARTICIPANT of. No exceptions, no leaks. A term that
  matches someone else's message in a thread you are not in must be invisible — prove it.
- EXCLUDE tombstones (deleted_at IS NOT NULL — the body is blanked anyway; never resurrect it).
- EXCLUDE system lines (kind='system') — membership noise, not conversation.
- Return per hit: message id, thread uuid, thread label (subject or peer names), sender name,
  a snippet with the match in context, created_at. Order newest-first; cap + paginate.
- Escape LIKE metacharacters so a literal % or _ typed in the box matches literally
  (the dir-name-search lane did exactly this — copy that treatment).
- SCALE: dev2 holds ~2.2k messages, so ILIKE is fine for v1 — but say so explicitly and
  propose the index (GIN on to_tsvector(body)) with a switch-over threshold. Do not ship an
  unindexed scan and call it done; state the cost.

## CLIENT (both surfaces, parity)
- Results are a LIST OF MESSAGES (not threads): who said it, in which chat, when, with the
  matched words visible. That is the difference from today's filter.
- Tapping a result OPENS THAT THREAD SCROLLED TO THAT MESSAGE, highlighted — reuse the anchor +
  highlight pattern the notifications lane already shipped (`&reply=` -> anchorReply()); do not
  invent a second mechanism.
- Keep the existing thread-name filter behaviour working (people use it) — search should feel
  like it grew, not like it replaced something.
- Empty/short query: fall back to today's behaviour, no server call.

## VERIFY (before preview request)
CDP 390 + 1280 as 3+ real users: find a word in a 1:1 and in a group; tap a result -> lands on
that message, highlighted; a NON-participant searching the same word gets ZERO hits (assert at
the API, not just the UI); deleted message body is unfindable; system lines never appear; a
literal "%" returns nothing rather than everything. Purge test data whole. Thread 367 and store
ed23219e are REAL DATA — never mutate.
STANDING LESSON: drive the ACTUAL search box a human types into, not the endpoint alone.
