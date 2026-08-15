# SESSION HANDOFF — lane `guitardle-score-integrity` (phase 3)

**Written 2026-08-15. Assume you are a fresh lane with zero context.**
Phase 1 + 2 shipped and merged; see `handoffs/2026-08-15-guitardle-fairness.md`.

| | |
|---|---|
| Branch | `guitardle-score-integrity`, tip **`73df469`**, **NOT pushed** |
| Base | `912f161` (my merged phase-2 tip). `origin/main` did **not** carry keeper's merge when last fetched — **rebase onto main before pushing** |
| Backlog 24 | **BUILT, GREEN, proven end-to-end.** Blocked only on a gate number |
| Backlog 25 | **NOT STARTED — scope ruling outstanding with keeper** |
| Flags | `LG_GUITARDLE_SCORE_RETRY` added, OFF. Joins `_DAILY_CLAIM` and `_HOW_TO_PLAY`, all OFF, all independent |

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

## 3. Backlog 25 — DO NOT BUILD BEFORE READING THIS

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

**Option A (recommended)** — the answer stops reaching the client. Reveals go
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

## 5. Open

1. **Gate number for 24** — keeper. Blocks the push.
2. **Backlog 25 scope** — keeper/Ian: Option A or B.
3. **The answer-key exposure probably deserves its own backlog number** — it is
   a distinct defect from "the inputs are client-supplied" and outlives whichever
   option is chosen.
4. Phase 2's live migration + flag flips — still Ian, unchanged.
