# SESSION HANDOFF — lane `guitardle-score-integrity` (phase 3)

**Written 2026-08-15. Assume you are a fresh lane with zero context.**
Phase 1 + 2 shipped and merged; see `handoffs/2026-08-15-guitardle-fairness.md`.

| | |
|---|---|
| Branch | `guitardle-score-integrity`, **pushed**, tip **`1cad6f7`** |
| Base | rebased onto `origin/main` `b9c48ba`. Phase 2 is merged and **both its flags are ON in main** |
| Backlog 24 | **DONE.** Gate **40** (keeper), registered, CRAFT-STANDARD row in |
| Backlog 25 | **DONE.** Gate **41** (keeper), registered, row in |
| Backlog 26 | **BUILT + GATED.** Gate **42** (pre-assigned), registered, row in. Stage ONE of two |
| **STATE — COMPLETE 2026-08-15** | **Parked on keeper's fleet-quiet order** (Ian on dev2, load 15 on 2 cores). All three items are BUILT, GATED, RED-FIRST PROVEN and PUSHED — **nothing is half-finished**. The only thing outstanding is the *confirming* full-suite run, which I killed mid-flight to give the box back; gates 40, 41 and 42 each passed standalone immediately before. **To resume:** run `tools/gates/run-all.sh` (needs `source tools/gates/gate-env.sh` + `export LG_GATE_COOKIE="loothdev_auth=$LG_GATE_TOKEN"`, or gate 39 reports CANNOT RUN). |
| Flags | `_SCORE_RETRY`, `_SERVER_PLAY`, `_DAY_PUZZLE` added, all OFF. `_DAILY_CLAIM` and `_HOW_TO_PLAY` are ON in main. All five independent |

---

## 1. Backlog 24 — done

A finished result was being **lost to an expired nonce**. Live, 7 days: 101
finished games POSTed, **8 came back 403** across 8 IPs and 6 days. A WP nonce
lives ~12h and the game sits in a front-page iframe people leave open, so a tab
opened last night carries a dead one. Every call ended `.catch(() => {})` — the
player saw their win card and it never reached the board. ~1 game in 12, hitting
the members who play most.

**All three** nonce-bearing calls now go through one `postWithNonce()`, not just
the finish. That mattered more than the ticket said: a **start-claim** lost to a
stale nonce means the day is never claimed, so phase 2's allowance fix silently
does not apply to that game — an invisible hole *inside* the previous fix.

On 403: fresh nonce, resend the same body, **once**. Never a loop. A failed
re-fetch or a network error is swallowed exactly as before, so offline play is
untouched.

**Proven end-to-end** through nginx → FPM → WP → Postgres in a real browser as a
real member: `POST 403 → GET 200 → POST 200`, row landed with the right score.

## 2. The gate — number NOT minted, this is the only blocker

`tools/gates/guitardle-nonce-retry-gate.py` + `guitardle-retry-harness.js`.
Both say **`NN`** where the number goes. Fill in, register in `run-all.sh`, add
the `docs/CRAFT-STANDARD.md` row, run the full suite, push.

Two halves, because neither proves anything alone:
- **Server**: a stale nonce really *is* answered `bad_csrf` and records nothing;
  the same result resent with a fresh nonce records with its real score.
- **Client**: the harness **slices the shipped `refreshNonce`/`postWithNonce`
  out of `game.js`** and evaluates that source against a stubbed network — the
  real code, not a re-implementation, so it cannot drift from what ships. If
  those functions are renamed it reports **CANNOT RUN**, not a vacuous pass.

Deliberately **not** a browser test: a browser dep would flake on 2 cores, and a
DEAD gate blocks every lane. Red-first with two valid, still-parsing mutations —
retry-without-refreshing and retry-in-a-loop — each caught by its own assertion.

## 3. THE GATE-COLLISION INCIDENT — read this before running anything

On 2026-08-15 gate 37 reported **five failures on a healthy feature** and
blocked keeper's merge train. Nothing was wrong with the code. Both gates did
all their work as **one fixed WP account** (`gdle_gate_probe`) and wiped that
user's rows between phases — so any other process touching it (a second gate
run, or me hand-testing phase 3) landed rows inside the run, and the gate
called them defects.

All five symptoms were **one stray row**: "starts with a row already present"
is direct; "OFF still records" and "claimed_at stays NULL" fail because a
pre-existing row makes `ON CONFLICT DO NOTHING` return `recorded=false`;
"position held server-side" and "device B resumes" fail because a *finished*
row makes `UPDATE ... WHERE moves IS NULL` match nothing.

**Fixed**: gates 37, 40 and the 25 gate take a **per-run probe identity** keyed
to the PID, created on demand and deleted at the end with the deletion
asserted. Proven by running 37 and 40 **simultaneously** — both green, on
accounts 2048/2049. A false red blocks every lane, which is strictly worse than
the coverage a gate buys.

Two smaller fixes rode along: `wp_insert_user` **rejects a duplicate email** so
the per-run login needed a per-run address, and it failed **silently** —
`WP_Error` in, no output, `IndexError` three frames away. `wp()` now reports an
empty wp-cli result as CANNOT RUN with the real stdout/stderr.

**Discipline**: never use a gate's probe account for ad-hoc work.

## 4. Backlog 25 — BUILT (option A). Read §6 before calling it closed

**The answer key is public.** The game is a static client-side app: it fetches
`assets/sequence.json` and `assets/guitardle_phrases.csv` from the browser and
decides the win with `guessed === PHRASE_LETTERS` **in the client**. Both files
are served on the game's own public route (live's access log: 1705 asset fetches
in 7 days). That is **285 phrases and the full fixed sequence** — today *and
every future day* computable in about ten lines. I derived both of today's
tracks from it.

So: server-derived `moves`/`won`/`hardcore` stops **API** forgery, which is worth
doing — but a player reading the key genuinely solves in one move and the server
would **honestly** score it 10 points, 20 with hardcore. Building only that and
reporting "the leaderboard inputs are unforgeable" would be **false**.

**Option A was taken by keeper and is BUILT.** What follows is what it does.

`archive-poc/api/v0/_guitardle-puzzle.php` is the server's copy of
`loadPhrase()`. Two new flag-gated actions: **`reveal`** returns the POSITIONS
of a letter and counts the move server-side (consonant 1; vowel first tap buys
for 1 with no positions, second tap places for 1 and returns them; re-reveal
refused; the **hardcore cap enforced server-side**, so claiming the 2× and then
over-revealing is not a thing), and **`guess`** is judged server-side, with
`won`/`moves`/`hardcore` being what the server watched. The phrase comes back
only once the game is over.

**Both legacy doors are shut under the flag.** Refusing the old finish path is
the obvious half. The half nearly missed: **`save`** let a client write its own
`revealed`/`purchased` sets — and those are *exactly* what moves are counted
from. Closing the front door and leaving that open achieves nothing.

**A sharper finding than the CSV story**, measured in a browser: the legacy
board put the answer in the DOM. 18 tiles, **all 18 carrying `data-letter`**,
so `"POLYURETHANEFINISH"` reads straight off the *blank* tiles. No CSV needed —
open the inspector. The server-driven board carries **zero** letters; after one
reveal exactly 2 do.

**Original option A description, kept for context** — the answer stops reaching the client. Reveals go
through the server: client asks "reveal H", server returns the *positions*,
counts the move, holds authority in `resume_state` (phase 2 already built that
column). Guess judged server-side. `moves`/`won`/`hardcore` then are not
client-supplied at all — they are what the server watched happen. Subsumes 25.

**Option B** — server-derived scoring only; smaller; write down plainly that the
answer key is still readable.

**Sequencing trap for either:** the CSV and `sequence.json` must **keep** being
served while the flag is OFF, because the old path needs them. The hole is not
closed until the flag is ON everywhere **and** the assets are then restricted —
a two-stage deploy that must be planned as one.

### The exact move accounting (needed by both options)

Read off `game.js` — get this wrong and you reject honest players:
- consonant reveal → `revealedLetters.add`, **1 move**
- vowel 1st tap → `purchasedVowels.add`, **1 move**
- vowel 2nd tap → `purchasedVowels.delete` + `revealedLetters.add`, **1 move**
- the guess, on Confirm only → **1 move** (cancel is free; floor is 1)

**`purchasedVowels` is DELETED when the vowel is placed**, so a snapshot's
`purchased` set holds only vowels bought-but-not-yet-placed. Therefore:

> `moves = 1 + (revealed consonants) + 2 × (revealed vowels) + 1 × (pending purchased)`

**VERIFIED empirically, not just read off the source** (2026-08-15): drove real
clicks on the real keyboard — 2 consonants, one vowel bought only, one vowel
bought *and* placed — giving `revealed=[P,L,U] purchased=[O]`. Formula derives
**5**; `state.moves` was **5**. Worth doing before either option is built: get
this wrong and the server rejects honest players, which is a far worse failure
than the hole being fixed.

## 4. Traps hit this phase

- **A stale CDP tab makes your run execute as another member.** My first
  end-to-end run traced perfectly and wrote the row under **1912
  (`claude_admin`, the shared automation account)** while the page reported the
  2047 probe throughout — trace and page agreed with each other and were both
  wrong about who was writing. `clearBrowserCookies` + `setCookie` in a *new*
  tab is not enough while an old tab is alive. **Close every page target first**,
  and **assert the DB row BY uid** — an existence assertion goes green on
  someone else's data. Cousin of the known duplicate-cookie trap, different
  vector, nastier because the page self-reports the identity you expect.
- Shell env does not persist between tool calls (bit me again via `$LG_GATE_TOKEN`).
- The armed dev-gate token is in box-local `/etc/nginx/conf.d/loothdev-auth.conf`,
  **not** what `gate-env.sh` exports.

## 7. Backlog 26 — BUILT, and the deploy order is the whole risk

The logged-out client no longer fetches `guitardle_phrases.csv` or
`sequence.json`; it asks `guitardle-puzzle.php` for **one day on its own
track**. Proven in a browser by resource timing: with `dp=1` it fetched
**neither** file and still rendered a playable 18-tile board with the right
phrase id, letters and cap.

**What it does NOT claim.** A logged-out board still holds its own day's
phrase — it must, it judges its own guess, and an anon result is never
recorded. What goes is the **library and the order**: no other day, no other
track, nothing to compute forward from.

**`dp=1` is emitted for logged-out visitors ONLY.** A member sent to the day
endpoint would get the *logged-out* track — a different phrase from the one
their day is scored on — which is worse than the bug.

### Keeper's two guardrails (added on review, both red-first proven)

**1. The endpoint takes NO parameter of any kind.** Not a date, not an index,
not an audience — the *server's own clock* picks the day. My draft accepted
`?local_date` with the same ±1 clamp the score API uses, justified to myself as
consistency. **It was still an oracle**: a read-only endpoint that answers for a
day you *name* rebuilds the answer key on a delay, one query at a time. There is
no window small enough to be safe, so there is no window. It reads **no
superglobal at all**, and the client stopped sending a date so the URL is
byte-identical for every visitor. Gate 42 probes thirteen shapes.

> **Cost, stated not hidden:** the logged-out day now turns over at the
> **server's** midnight, not the player's. UK-centred audience → same hour; a US
> player sees it change in the evening. If that ever matters, the fix is a
> **site-timezone constant in the endpoint** — never a parameter.

**2. No cache outlives the day.** On live this sits behind Cloudflare, and an
unauthenticated GET cached across midnight either serves yesterday's phrase to
everyone or pre-bakes today's for anyone who asks early. `no-store` rather than
an expiry pinned to midnight — an edge that mis-rounds a pinned expiry by a
minute is the same bug with more moving parts — plus `CDN-Cache-Control`, which
Cloudflare honours in preference. The gate reads the headers off a **live
response**, not the source.

### THE DEPLOY ORDER, three steps, and the last is Ian's

1. `LG_GUITARDLE_SERVER_PLAY` ON for members. Confirm.
2. `LG_GUITARDLE_DAY_PUZZLE` ON for anon. Confirm.
3. **Only then** delete `assets/guitardle_phrases.csv` and
   `assets/sequence.json`.

Gate 42 asserts those files are **still present** precisely so a future tidy-up
fails there rather than on Ian's phone: pulled early, a legacy member gets a
**blank** board, not a degraded one.

**Known limitation, flagged not buried:** the nginx route only goes live on
merge + reload, because `/etc/nginx/snippets/strangler-archive-poc.conf` is
symlinked to the **serving checkout**, not a worktree. On dev2 the route is
asserted from the conf text and the endpoint exercised over a loopback
`php -S`. It wants one real curl through the live route after merge.

## 8. The race I found in my own fix (2026-08-15, post-build)

Found by **re-reading the code**, not by a test failing — which is the only
reason it did not ship.

`reveal` was a **read-modify-write** on `resume_state`: SELECT, compute, UPDATE.
Fire four reveals simultaneously and every one reads the same state and returns
**its own positions** — the player sees four letters — while only the last write
survives and the server charges for **one move**. Four reveals for the price of
one is precisely the forgery backlog 25 exists to stop, so it would have shipped
with a hole in the middle of it, behind a gate that was green because **a
sequential test can never reproduce a race**.

Fixed with `SELECT ... FOR UPDATE` inside a transaction. Every early exit inside
the transaction releases the lock first; both success paths commit before
emitting; the catch rolls back only if a transaction is actually open — that
last detail matters because **the start/save block shares that catch's text and
uses no transaction**, which is also why the first patch attempt failed its own
uniqueness assertion instead of silently editing the wrong block.

Gate 41 **phase 3b** fires the reveals genuinely concurrently via `subprocess`
and asserts both the move arithmetic and that no write was lost. Measured:
**4 simultaneous reveals cost 4 moves**, all letters survived.

> **Honest limit:** a race is probabilistic, so a green here is strong evidence
> rather than proof. That is why the red-first matters more than usual on this
> one — if stripping the `FOR UPDATE` still passes, the test is not reproducing
> the race and the burst needs widening.

## 9. Suite state, and what is NOT ours

Full run: **43 gates, my four (37/40/41/42) all GREEN, zero FAIL lines.**

The suite banner is RED from **three gates this lane never touched** — gate 5
`looth-auth-issue`, gate 11 `shop-planner-url`, gate 30 `legacy-url-redirect` —
all failing with **HTTP 403**. Evidence they are flaky, not broken:

- green in **three earlier full runs the same day** (00:59, 01:24, 03:00), red at 03:39
- `shop-planner` standalone: **GREEN twice**, then **RED three times** minutes
  later, same command, no code change
- the URL itself served **200 on 20 of 20** direct curls using the gate's own
  internal-IP resolve and exact cookie
- `gate-env.sh` derived an **identical token 15/15**

So the request works, the token resolves, the page serves — and the gate still
intermittently sees 403. Not isolated further on purpose: it is another lane's
instrument. Same class as the known gate 1 / gate 17 load flake.

Also 4 gates DEAD (exit 2) for environment reasons, incl. `featured-member`,
which wants `LG_GATE_HOST` + `LG_GATE_COOKIE`. **Only that gate reads
`LG_GATE_COOKIE`** — checked, so setting it did not cause the three 403s.

## 10. A test fixture became the leaderboard champion (read this one)

My gates take a per-run probe account and delete it at the **end** of a run —
which does nothing if the run is **killed**. One of mine was, mid red-first, and
it left its account *and* its row behind. That row was a **1-move hardcore win**,
so against the exact query `guitardle-board.php` runs it became the **only entry
on dev2's weekly board, at 20 points**. A test fixture had installed itself as
the Weekly Top 5 champion on the box Ian looks at — and nothing about it was
visible from a green gate, because the gate that created it reported GREEN and
cleaned up everything it *knew* about.

**Fixed:** all three account-using gates now **sweep on entry**. Anything
matching the probe prefix and registered more than 30 minutes ago loses its rows
and its account before the run starts. The 30-minute floor is what keeps it safe
to run two gates at once — a live run's account is minutes old and untouched.
Proven by planting exactly what a killed run leaves, then repeated over two more
independent cycles.

> **House-rule candidate:** cleanup that only runs on the SUCCESS path is not
> cleanup. Any gate that writes member-visible rows should assume it will be
> killed and repair on **entry**, not trust its own exit.

### Two instrument lessons, both of which cost more than the bugs

1. **I reported the sweep broken when it was not.** I filtered the gate through
   `grep -E "swept|GATE 41"` and read a missing line as "it did not run". A grep
   tells you a string was absent, never *why*, and hides everything you did not
   think to ask for. Ungrepped, the line was right there.
2. **The burst test for the reveal race passed on broken code** — each probe
   spends ~2.5s booting WordPress against a sub-millisecond critical section.
   **Launching** concurrently is not **overlapping**.

Both times the code was fine and the way I was looking at it was not.

## 5. Open

1. **Gate number for 24** — keeper. Blocks the push.
2. **Backlog 25 scope** — keeper/Ian: Option A or B.
3. **The answer-key exposure probably deserves its own backlog number** — it is
   a distinct defect from "the inputs are client-supplied" and outlives whichever
   option is chosen.
4. Phase 2's live migration + flag flips — still Ian, unchanged.
