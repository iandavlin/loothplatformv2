# Backlog 22 — Guitardle fairness: PHASE 1 INVESTIGATION

Lane: guitardle-fairness. Ian 8/14: *"fixing the guitardle giving more chances on
different devices"*. **No code written — this is the report before building.**

## 1. Where the game lives

| Piece | Path | Notes |
|---|---|---|
| Game (served) | `archive-poc/web/guitardle/` | vanilla HTML/CSS/JS, ~1035 lines |
| Front-page block | `archive-poc/web/_gdle-promo.php` | iframes the game, renders the Top 5 |
| Score write/read | `archive-poc/api/v0/guitardle-score.php` | boots WP on the **looth-dev** pool; gate = WP login cookie + nonce |
| Board read | `archive-poc/api/v0/guitardle-board.php` | archive-poc pool, no WP boot |
| Store | Postgres `discovery.guitardle_results` | `UNIQUE (wp_user_id, play_date)` |
| Route | `platform/nginx/strangler-archive-poc.conf:300,397,444` | |

**`/guitardle/` at the repo root is the ORIGINAL PROTOTYPE and is not served.** It has
no score, board, hardcore or save code. Do not edit it — every change goes in
`archive-poc/web/guitardle/`.

**The "claimable spots"** are `_gdle-promo.php:33-43`: the card always renders exactly
five slots; unfilled ones read **"Open spot / play to claim"**.

## 2. How a daily attempt is stored TODAY

The charter's premise is **half wrong, and the half that's right is the dangerous half.**

Attempts are *not* purely device-local for members. Two server-side protections already exist:

1. `UNIQUE (wp_user_id, play_date)` + `ON CONFLICT DO NOTHING` — one row per member per
   day, **first result wins**. A second *finished* game can neither overwrite nor add.
2. `game.js:1049-1065` — on load, if the API reports a row for today whose `phrase_id`
   matches the puzzle on screen, the board locks to "Already played today".

So **device-hopping after you FINISH a game is already blocked.** Measured over 7 days:
93 successful POSTs, 93 rows created — zero duplicate-day writes.

### The actual hole: don't finish. Abandon.

Nothing is written until the game ends — `handleWin()`/`handleLoss()` are the only
callers of `postScore()`. There is **no forfeit and no `beforeunload`/`pagehide`
handler**; the forfeit rule was deliberately retired (Ian 6/12) in favour of a
mid-game localStorage snapshot. That snapshot is per-device. Therefore:

1. Play on device A. Reveal letters until you can read the phrase. **Close the tab.**
   Nothing recorded — no row, no lock, no trace.
2. Open incognito / device B. Same `aud` track ⇒ **the same puzzle** (`loadPhrase()` is
   a pure function of the date and the audience track).
3. Guess it in **1 move**. 10 points — **doubled to 20** if hardcore is toggled on.

This emits exactly one POST and creates exactly one row. It is **indistinguishable from
honest play in the data.** The unique constraint never fires, so nothing looks wrong.

### Evidence it is being used

Winning-move histogram, all time (live):

| moves | WP 197 | everyone else |
|---|---|---|
| 1 | **7** | 16 |
| 2 | **10** | 42 |
| 3 | **5** | 42 |
| 4 | **4** | 63 |
| 5–12+ | **0** | 361 |

WP user 197: **27 plays, 27 wins (100%), every win ≤4 moves, 7 of them in ONE move** —
i.e. guessed the phrase cold with zero letters revealed, seven times. The next-best
average in the field is 4.1 moves; 197 is at 2.7. Nobody else has a shape remotely
like this. 197 is *not* in this week's top 8, so the live board isn't currently
distorted — but the hole is reachable and has been walked through.

(Other perfect records — 206 at 34/34, 728 at 17/17 — sit at 6.5 and 7.1 average moves,
which is a normal shape. I'd not flag them.)

## 3. Numbers (live, 7 days 08-08 → 08-14)

- **266 game loads** — 239 member track (`aud=m`), **27 anon** (`aud=p`) ⇒ **anon ≈ 10%**
- **Zero** non-embed loads — the game is *only* ever reached through the front-page iframe
- Anon hit the score API 17× from **16 distinct IPs** — ~2/day, essentially all one-shot
- **101 finished games → 93 recorded**
- ~13–16 recorded results/day; 19–24 distinct members/week
- All time: **724 rows, 53 members**, since 2026-06-11; weekly volume flat at 70–97 plays

This is a real, used feature with a stable ~20-member weekly core — worth fixing properly.

## 4. Two further findings (not in the charter, both real)

**(a) ~8% of honest results are silently thrown away.** 8 of the 101 finished games
POSTed and got **403** (expired WP nonce on a tab left open past its 12h life) — 8
distinct IPs across 6 days. `postScore()` ends in `.catch(() => {})`, so the player sees
their win card, believes it counted, and it never reached the board. **1 in 12 games.**
This is a fairness bug pointing the *opposite* way and it hurts the members who play most.

**(b) The score is self-reported.** `moves`, `won` and `hardcore` all come from the
client body, and `hardcore` **doubles** the points. Anyone holding their own nonce can
POST a 1-move hardcore win for the daily maximum of 20. This is the ceiling on how fair
the board can ever be; closing the device hole does not touch it.

## 5. Proposed fix shape (for Phase 2 — needs keeper's go)

Claim the attempt on **start**, not on finish:

1. `guitardle-score.php` gains a **`start`** action: insert `(wp_user_id, play_date,
   phrase_id)` with a NULL result. The existing `UNIQUE` + `ON CONFLICT DO NOTHING`
   delivers the one-allowance-per-member guarantee for free — no new constraint.
2. Game end **UPDATEs that row only while the result is still NULL**
   (`WHERE wp_user_id=? AND play_date=? AND moves IS NULL`). First *finished* result
   still wins; an abandoned game is already claimed, so device B gets
   "Already played today" instead of a fresh board.
3. The init server lock already exists — it needs to fire on a claimed-but-unfinished
   row too. **Minimum**: lock it. **Better**: sync the mid-game snapshot server-side so
   switching devices *resumes* rather than blocks — otherwise an honest player whose
   phone dies mid-game is locked out of the day, which will read as a bug to Ian.
   I recommend building resume, not just lock.
4. Fix (4a) while in here: on 403, re-fetch the nonce and retry once.

Schema change needed: `moves`/`won` must become nullable (they are `NOT NULL` today,
`moves` carries `CHECK (moves BETWEEN 1 AND 99)`). Migration must be written so flag-OFF
is byte-identical — see below.

Flag: OFF-default, per house rules. Gate number **from keeper — not minted here.**

## 6. THE ONE DECISION FOR IAN — the anon story

**Anon already cannot claim a spot.** `guitardle-score.php:116` rejects `uid <= 0` with
401, and the board reads only `guitardle_results`. So no anon play has ever produced a
row, and the Top 5 is members-only by construction. Nothing to build to make that true.

**Recommendation: leave anon device-tracked and ineligible — and SAY so on the card.**
For logged-out visitors, change one "Open spot / play to claim" line to
**"Sign in to claim a spot"**. That turns the fairness constraint into a join prompt
aimed at exactly the ~27 anon loads/week already looking at it.

**Do not build server-side anon attempt tracking.** It would need device/IP
fingerprinting, it covers 10% of traffic, and it buys nothing — anon cannot score.

Ian's call, not mine. The numbers above are what he needs to make it.
