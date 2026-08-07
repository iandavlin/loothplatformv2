# The notification read timer, and the recap it was emptying

Backlog 4.1. Ian said "fix" on 2026-08-05. Lane `recap-read-timer`, dev2.

## The ruling this serves — not up for re-litigation

`docs/IAN-RULINGS-2026-08-03.md` §1: the weekly recap stays **"what you missed" —
UNREAD ONLY**. Ian was shown the argument for time-windowing it and chose
unread-only anyway. It is an editorial call about what "recap" means and it is his.

Nothing here re-opens it. There is no time window, no 7-day fallback, no second
store. The change makes his chosen framing behave the way its label promises: a
recap of what you missed should contain what you actually missed.

## The defect

`webroot/bottom-nav.js` fired `markAllNotifsRead()` 700ms after the mobile
notification sheet rendered, POSTing `{action:'read_all'}` — **every row the member
holds**, when the sheet had rendered **eight**. Under unread-only that empties the
recap, and under "empty means send no email" it cancels the member's digest
outright. The member most engaged with the bell was the member most reliably
unmailed: a weekly recap inversely correlated with engagement.

The protection already existed one arm over. `Recap::OUTSTANDING`
(`profile-app/src/Recap.php`) refuses to consult `is_read` for `connection_request`
for exactly this reason, and names this timer in its comment. It was written on
2026-07-29 after the same class cost a member their digest, and never extended to
hub rows — which have no edge to consult, so `is_read` is the only resolution signal
they have.

`read_all` had exactly one live caller: this timer. The desktop bell modal never
called it (`lg-shared/social-modals.js` has no `read_all`; the copy under
`lg-shell/lg-shared/` is stale and serves nowhere — nothing under `/srv` or
`/var/www/dev` resolves to it).

## Boundary — where "saw" is drawn

> **SEEN = the row was rendered into the open sheet, AND the sheet was still open
> when the dwell elapsed.**

NOT seen, and therefore still unread:

| case | why |
|---|---|
| rows past the rendered slice (9th onward of the top-8 page) | never entered the DOM |
| rows outside the fetch window entirely | never left the database |
| rows in the DOM when the member dismissed the sheet before the dwell | they were taken away again |

### Why rendered-into-the-sheet, and not something narrower or wider

**Wider — "returned by the API" — is the defect.** That is what `read_all` did in
substance, and it marked rows read that the member had no way to perceive.

**Narrower — "intersected the viewport"** — is arguable and was considered. The
sheet is 565px tall and holds eight rows, so most of a page is on screen at once;
IntersectionObserver would add a second timing race on top of the dwell, and per
`trap-synthetic-click-cannot-long-press` a threshold that automation cannot cross
honestly is a threshold no gate can defend. It would trade a provable boundary for
an unprovable one.

**Narrower still — "the member tapped it"** — is already implemented and untouched:
`markNotifReadOnNav` marks that ONE row read on click-through. It is not a
substitute, because a member who reads the bell without tapping anything has still
seen what it said.

**The dwell is kept at 700ms**, Buck's original number. The argument here is about
scope, not speed, and moving both at once would make the change harder to judge.

## What depended on the badge reaching zero — checked before touching the timer

The old comment justified the wide scope explicitly, and it was right to worry:

> Fire when the badge shows ANY unread (social-counts), not just when one of the
> visible top-8 is unread — otherwise unread items older than the 8 shown never get
> marked and the badge "comes back" after an app reset.

Every badge reads the same number: `Notifications::unreadCount()` via
`me-social-counts` — the You-tab `.lt-badge`, the You-sheet `.lt-notif-rowbadge`,
and the desktop header bell. So under a narrower scope they all show a true
remainder rather than zero, which is correct but only tolerable if the member can
still drive it to zero.

They can, by seeing the rest: **"See all notifications" now fetches `?limit=200`**
and renders every remaining unread row. Reading can still take the badge to zero —
it just can no longer get there without the member seeing anything.

`markNotifsSeenRead` also deliberately does **not** blank the badge optimistically
the way `markAllNotifsRead` does. Under this scope the truthful count after the call
is usually not zero, and guessing zero would show a cleared bell that the next
refresh contradicts.

`read_all` is **kept** as the explicit verb — "I mean all of them". Nothing
schedules it on a timer any more, which the gate asserts.

### The residual, stated rather than hidden

A member holding **more than 200 notifications inside the 30-day retention window**
would have an unread tail the sheet cannot render, so their badge could not reach
zero by reading alone. The existing "Clear" button (a real server-side DELETE,
member-initiated) is the escape.

This is a bounded worry, not a theoretical one: `prune-notifications.timer` is live
on this box (last run verified 2026-08-07) and enforces the 30-day retention, and
the largest holding measured on dev2 was **4 rows**. Say so plainly rather than
claiming the tail cannot exist.

## Flag — `read_seen_only`, defaulted OFF

`profile-app/config/notifications.php`. A tracked PHP file read via `__DIR__`, not
an env var, for the three measured reasons `platform/config/follow-digest.php`
records: `fastcgi_param` never reaches `getenv()`; cron carries no environment at
all; and live's `/etc/looth/env` says `LG_ENV=dev2`, so nothing may branch on the
environment name.

The flag is read in ONE place (`Notifications::readSeenOnly`) and branched in ONE
place (`Notifications::applySeenRead`, next to the reader — the endpoint is pure
transport). Under OFF, `applySeenRead` runs the same `markAllRead()` SQL `read_all`
has always run, so **OFF is a no-op on the store whatever a client sends**. A cached
older `bottom-nav.js` that still posts `read_all` is also the OFF path.

The policy travels to the client as `read_policy` in the GET response that carries
the rows it governs — no extra round-trip, no flag transport of its own, and a
client predating the key sees `undefined` and keeps its old behaviour.

## Evidence

All runs on dev2, 2026-08-07, against the **real origin** via
`tools/exercise-harness/real-origin-proxy.py` (red) and `endpoint-swap-proxy.py`
(green): real nginx, real `sub_filter`, real `pwa.js` → `/bottom-nav.js`, real FPM
pools, real Postgres. Member wp:1912 `claude-admin-qa`, 12 unread
`forum.reply_to_topic` rows (ids 607–618, 607 newest). Viewport 390×844,
`mobile:true`, touch emulation. Store read straight out of Postgres — a UI that
sanitises on read cannot audit the store.

| run | code | rows rendered | store after |
|---|---|---|---|
| **RED-A** open, dwell 2500ms | main | 8 (607–614) | **0 unread / 12** — all read, incl. 4 never in the DOM |
| **RED-B** open, dismiss at 300ms | main | 8 | **0 unread / 12** — fired 700ms after the sheet was gone |
| **GREEN-OFF** flag as shipped | branch | 8 | **0 unread / 12** — identical to RED-A |
| **GREEN-ON** flag armed | branch | 8 | **4 unread / 12** — 615–618 still unread; badge "9+" → "4" |
| **GREEN-ON-B** armed, dismiss at 300ms | branch | 8 | **12 unread / 12** — nothing was seen long enough |
| **GREEN-ON-C** armed, tap "See all" | branch | 8, then the remaining 4 | **0 unread / 12** — badge reaches zero, after the member saw all 12 |

And the consequence, on the same fixture:

```
read_seen(top 8)  ->  4 unread  ->  recap 4 rows   NON-EMPTY, digest sends
read_all          ->  0 unread  ->  recap 0 rows   EMPTY, no email at all
```

Harness limits, stated: both proxies rewrite `Origin`/`Referer` to the public host,
because profile-app's CSRF guard rejects a foreign Origin and a browser parked on
`127.0.0.1` would otherwise 403 on every POST, red and green alike. The guard still
runs — it is fed its real-world input, not bypassed. Requests reach nginx from
loopback; that does not change which member acts (identity is the `looth_id` JWT),
and the `X-LG-WP-User-Id` loopback bypass is `/whoami`-only and needs a secret the
proxy does not send. No service worker, and not Ian's browser profile.

## Gate

`tools/gates/notif-read-seen-gate.py`, gate 16/16. 35 assertions: the store contract
(including **the absent half** — unseen rows STILL UNREAD), owner scoping, the
`markAllRead` sweep still sweeping, the recap consequence in both directions,
`applySeenRead` under BOTH flag values, the `max_ids` cap measured, the OFF default,
and structural tripwires on `bottom-nav.js` labelled as structural.

Red-firsted with `tools/gates/lib/notif-read-seen-redfirst.sh` — ten inversions,
each going red for the reason it claims. That pass caught the gate asserting on its
own comment prose (`limit=200` appears in both the fetch and the comment explaining
it), which would have shipped as green noise.

## Status

- Flag **OFF**. Not yet verified on the dev2 serve, which serves `main` — that is
  what the flag is for: it lands harmlessly, gets verified on the running thing, and
  is switched on after Ian has looked at it.
- **Not** deployed to live. Live deploys are Ian's.

### What this does NOT promise

Fixing the timer does not guarantee Ian's own recap will have content. Under his
ruling a member who reads everything on their phone legitimately has nothing they
missed, and if he opens the bell sheet daily with fewer than eight rows waiting, his
recap will still be empty — correctly. What is fixed is that the recap now contains
exactly what he did not see, instead of nothing because a sheet was open for 700ms.

## Arming the flag — no decision recorded yet

⚠️ **This heading is deliberately NOT the one the gate looks for.** Gate 16 searches
for a heading that reads exactly `Decision to arm`, and if this placeholder had used
that wording the tripwire would have been satisfied by the very text saying no
decision exists — a false green in the check written to prevent one.

To arm `read_seen_only`, add a section headed **`## Decision to arm`** here naming
who decided and when, then flip the value. Arming it without that section turns gate
16 red, and the correct response to that red is to record the decision — **not** to
delete the check. Same shape `IAN-RULINGS-2026-08-03.md` §4 asks for on the
follow-digest allowlist.
