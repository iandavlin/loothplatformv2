# SESSION HANDOFF — lane `guitardle-fairness`, 2026-08-16 (successor seat)

**Assume zero context; this is your charter.** Three branches, all pushed, all
merge-clean against main. Gate numbers are ASSIGNED (59 letter-state, 60
phrase-dupe, 54 weekly-front). Keeper ruled 2026-08-16 that all three ride the
**next merge train**, and that the TRAIN's own full suite re-validates them — so
do NOT burn the shared lock re-running a suite to prove what the train reproves.
The READY-for-Ian post is written (see §9) and **keeper sends it after the train
lands and the serve pulls** — not before, because dev2 serves `main`.

| branch | tip | state |
|---|---|---|
| `guitardle-letter-state` | `3335ccb` | **P0 DONE.** 4 defects fixed, **gate 59** = 63 assertions, proven RED→GREEN |
| `guitardle-phrase-dupe` | `c7e9a89` | **MERGE READY**, gate **60**, 0 behind main, merges clean |
| `front-weekly-email` | `f690f33` | gate 54, 0 behind main, merges clean. **Flip blocked on keeper's merge** |

**Run this first** — it is 10 seconds and it caught a live collision today:
```
git worktree list ; git log --oneline -3 origin/main
for w in ~/worktrees/*/; do (cd $w && git diff origin/main...HEAD -- tools/gates/run-all.sh \
  | grep -oiE 'GATE [0-9]+'); done | sort -u
```

---

## 1. WHAT I DID THAT THE PREVIOUS HANDOFF DOES NOT COVER

The predecessor's fix and gate were **already built and pushed** when I arrived —
the charter said "BUILD" but the work existed. I verified instead of rebuilding,
and that produced two things.

### A fourth defect, and it is the worst of the four

The first three made the board **fail to show** something. This one makes it
**assert something false**: a letter the player got RIGHT is redrawn as a
struck-through MISS with nothing painted.

**Reproduce it (no DB surgery, ordinary member path):**
1. open `/archive-poc/guitardle/index.html` directly → **LEGACY** (`?sp=1` is
   added *only* by the front-page block). Tap a hit and a miss. Legacy knows the
   phrase, so it writes a local snapshot.
2. enter via the **front-page block** → server play.
3. before the fix: `C -> 'miss.used'`, `B -> 'miss.used'`, `painted=[]`.

**Mechanism — a PHP/JS boundary trap.** The handshake builds the position map by
looping over `revealed[]`, so a claimed row with nothing revealed encodes as
PHP's `[]` — a JSON **array** — and **an empty array is TRUTHY in JS**. So
`if (serverPlay && !serverPositionMap())` could never fire in the state it was
written for. Every lookup then fell through to `PHRASE_LETTERS`, which is
**always `''`** under server play (backlog 25), so `hit=false` for everything.
Saved as memory `trap-php-empty-array-is-truthy-in-js`.

### The gate I wrote for it PASSED ON IT first

Worth more than the defect. My first phase 6 built the state with a **DB rewind**
and asserted *"no key is drawn as a MISS"*. It went **green on the unfixed tree**,
because under server play the client writes **no localStorage at all**
(`serverReveal` calls no `saveGame`) — so there was no snapshot to replay and the
assertion ran against an **empty board**. A key with *no* class satisfies
"not a miss" exactly as well as a correct key does.

I only caught it because I **predicted RED and got GREEN and went looking**.
Phase 6 now asserts the snapshot **exists and names the hit** before judging any
paint, and builds the state the way a user does rather than by editing the DB
into a shape the app never produces.

---

## 2. STATE OF EACH BRANCH

### `guitardle-letter-state` @ `fa5c6e9` — the P0
- Gate 57: **63 assertions, six phases**, GREEN on this tree.
- **Proven both directions on the SAME tree**: without the fix, RED on exactly
  one assertion; with it, GREEN — **62 assertions unmoved in both runs**, which
  is what makes it a real red-first rather than a coincidence.
- `LG_GDLE_SERVED=1` (measures dev2's served client = main) reddens **24**.
- Phase 5 (vowels) closed the predecessor's own flagged gap: bought on tap 1
  (purchased, not resolved, **still tappable**), resolved on tap 2.

### `guitardle-phrase-dupe` @ `64e2597` — built by a sibling seat, verified by me
- id 233 retexted `Dan Erlewine` → `notched straightedge`. I checked the **whole
  library**, not the one row: 285 rows, **zero** duplicate texts, `Dan Erlewine`
  now at exactly one id, and the new text occurs once (a retext fix can trivially
  create the defect it removes).
- Gate green (15 assertions, walks a full 285-day cycle on **both** tracks).
  Red-first **13/13 mutations bite**, with an unmutated control.

### `front-weekly-email` @ `4d40686` — mechanism converted
- The open pool-env-vs-`.local.php` ruling **did not need a ruling**: the pool
  files *are* symlinks into the serving checkout, so the old flip modified
  tracked files there. Measured, then converted.
- **Both readers** carry the override — the front page (archive-poc pool) and the
  feed (looth-dev pool) run as different users, and one alone is a HALF-ON state
  that renders as an enabled page fetching a 404.
- Gate 54: 22 → **31** assertions. Red-first **15/15**.
- The flip script **refuses today**, correctly, and names the reader. Ian's URL is
  behind keeper's merge, not behind any work.

---

## 3. OPEN WITH KEEPER (the only blockers)

1. **GATE 57 IS DOUBLE-ALLOCATED.** featured-members `1fac8ba` registers 57 and
   58 as "keeper-allocated"; my `3bb0db3` registers 57, also keeper-assigned.
   They are merge-pinged, so it lands in main on the next merge. I offered to be
   the one who moves — `scratchpad/renumber.sh` is staged.
2. Numbers for **phrase-dupe** (55, collides with featured-members) and
   **weekly-front** (54, free but not mine to mint).

## 4. FLAGGED, NOT FIXED — deliberately not a lane's to touch
- **The serving checkout is dirty**: `platform/fpm/dev2/looth-dev.conf`, a stray
  trailing newline from an earlier flip/unflip cycle. Can block `pull --ff-only`.
- **Disk at 90%** (2.9G free).
- **A routing question for Ian**: a member who plays the game page directly then
  enters via the front page **loses those taps**. Correct (the server never saw
  them), but whether a member should reach the legacy board at all is his call.
- **A pre-existing contrast defect I did NOT silently fix** (inherited note): the
  hit/`used` key is white on `#9FAC8C` = **2.40:1**, failing AA. It is Ian's
  palette. The new `.miss` is 8.42:1.

## 5. TRAPS THIS SEAT PAID FOR
1. **PHP `[]` is truthy in JS** — see §1.
2. **An absence assertion needs the STATE to exist**, not just a live detector.
3. **A red-first that goes green is a finding.** Both times today it was.
4. **Asserting a string that also lives in PROSE cuts both ways.** My weekly-front
   precedence assertion went **RED on correct code** by matching `getenv` in a
   docblock. Anchor on something only real code contains.
5. **Never edit the worktree while a suite runs.** The suite records the tip
   before and re-checks tip + cleanliness after, and prints TREE WAS STILL
   THROUGHOUT or says it is not attributable.
6. **`set -u` expands `$loc` in a double-quoted shell string** before perl sees
   it — a red-first mutation aborted with "unbound variable". Single-quote perl
   programs and avoid literal quote chars inside them.

---

## 6. WHAT THE FULL SUITE FOUND — worth more than the P0 fix itself

The first full suite came back **RED**, tree still throughout, and both findings
were real. Neither is discoverable by reading code.

### A gate had been GREEN ON TOP OF IAN'S BUG the whole time
`guitardle-claim` (gate 37) asserted both restores call `revealTiles(letter)` —
a **proxy** for "a letter comes back in ALL its positions", because it cannot
run a browser. Under server play tiles carry `data-i` and `revealTiles()`
selects `[data-letter]`, so **the assertion was satisfied by a call that matched
ZERO tiles**. It passed while a resumed board came back blank — Ian's exact
symptom, sitting under a green gate.

Repointed to the new shared primitive and made *stronger* (2 assertions → 4),
proven to bite by mutating `replayPosition` to reveal only the first position.

**The lesson:** a proxy assertion outlives the thing it stands for. When the
implementation it names is legitimately replaced, the gate does not just fail —
it may have been *passing for the wrong reason* long before.

### 🚨 GATE 59 DID NOT RUN, AND THE SUITE DID NOT SAY SO — still live for others
`run-all.sh` has a **terminal `exit 1` in the middle of the file**. Anything
registered after it never runs when an earlier gate is red, and the summary
names only the red gate — never a line saying gates were skipped. My gate was
appended after it, so on the run that found the above it executed **zero**
assertions while the suite still printed a verdict. Mine is moved.

**Gates 50 (work-board) and 56 (board committer) STILL sit after that exit on
main.** Any red silently skips them for every lane. Reported to keeper; not
restructured, because changing which gates run mid-train has blast radius.
This is CRAFT-STANDARD's "no silent caps" failing *inside the harness that
enforces it*.

## 7. THE TRAP THAT BIT ME TWICE IN ONE SESSION
**Identifying a process by a substring of a command line matches the command you
are typing right now.** Two forms, hours apart:
- `until ! pgrep -f "TIP_BEFORE"; do sleep 60; done` — the watcher's own cmdline
  contains `TIP_BEFORE`, so it **can never exit**. Nine immortal shells, and
  every liveness check built on it answered about the watchers, not the job.
  It told me the suite was dead while it was running.
- a kill loop grepping `tools/gates/run-all.sh` — the shell running it matched
  itself. Exit 144, killed my own caller.

Bracket trick works; **`/proc/PID/cwd` works better; waiting on an ARTIFACT the
job writes (`until grep -q '^EXIT=' out`) works best.**

## 8. GATE NUMBERS: three collisions in one day
46/47, 55, and 57 were each allocated twice, and **every one was caught by a lane
reading another lane's `run-all.sh` diff against main** — never by the
allocation. "Keeper said so" is not proof a number is free. Ten-second check:
```
for w in ~/worktrees/*/; do (cd $w && git diff origin/main...HEAD -- \
  tools/gates/run-all.sh | grep -oiE 'GATE [0-9]+'); done | sort -u
```
Mine ended at **59** (letter-state) and **60** (phrase-dupe) after 57 went to
featured-members.

---

## 9. THE READY-FOR-IAN POST — written, verified, NOT YET SENT

Keeper holds it and sends it once the train lands and the serve pulls. Full text
was posted to the board 2026-08-16; the essentials, if it needs rewriting:

- **Lead with WHEN.** He must not test before the merge — dev2 serves `main`, so
  testing early shows him the exact bug he reported and reads as "nothing done".
- **NOTHING WAS EVER LOST.** Say it first and plainly. The server's record was
  complete throughout, proven mechanically against the UNFIXED client (phase 3
  passes on main too). His scores and streaks were never at risk.
- **Four defects, in his own words**, not ours: "only stays lit for a correct
  letter" (miss filed as a vowel purchase, invisible and still tappable);
  "refreshing lights all letters but the correct letter isn't there" (resumed
  board painted nothing while lighting misses); hit and miss looked identical —
  **on live too**; and the fourth he had not hit yet, the board redrawing a
  letter he GOT RIGHT as a struck-through miss.
- **It is NOT desktop-vs-mobile.** No width branching exists; both widths measure
  identically. What differs is STATE. Worth saying because the obvious
  desktop-vs-mobile test would have PASSED.
- **Two things reserved to him**: whether members should reach the legacy board
  at all (they lose those taps now — correct, but a routing decision), and the
  pre-existing hit-key contrast of **2.40:1** (white on #9FAC8C, fails AA at any
  size; the new `.miss` is 8.42:1). Both measured, neither silently changed —
  it is his palette.
- No backticks and no `$` in the board text: `msg send` goes through bash and
  substitutes them away. Verified before sending.

