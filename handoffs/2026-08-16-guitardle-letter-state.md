# SESSION HANDOFF — lane `guitardle-fairness`, 2026-08-16

**Three workstreams, all pushed, all waiting on keeper's merge.** Assume zero
context; this is your charter.

| branch | tip | state |
|---|---|---|
| `guitardle-letter-state` | `3bb0db3` | **P0, Ian's own play.** Fix + gate 57, both proven. Wants merge |
| `front-weekly-email` | `c26dc40` | Merge-ready, suite-complete. Ian has asked to see it **twice** |
| `guitardle-phrase-dupe` | `64e2597` | Verified + pushed (built by a sibling seat, not this one) |

**Before starting anything, run this** — it is 10 seconds and it has already
saved this lane one full duplicate build:
```
git worktree list ; git branch -a | grep -i <topic> ; git log --oneline -5 origin/main
```

---

## 1. THE P0 — Ian's letter-state bug (backlog: his 8/16 play)

Ian, playing on dev2: *"On desktop it's keeping track of letters that are in the
puzzle, but not the guesses that were misses. The letter only stays lit for a
correct letter."* Then: *"refreshing the page on dt lights all letters, but the
correct letter just selected from mobile isn't there."*

### NOTHING WAS EVER LOST — say this to Ian plainly

Keeper's opening hypothesis was a lost move / last-writer-wins clobber. It does
not hold, and I went looking for it specifically. Measured with two devices
interleaving, the row read `{"moves":3,"revealed":["C","K","B"]}` **on main as
well as on the fix**. The reveal endpoint appends under `SELECT … FOR UPDATE`,
and `save` is refused `409 server_owns_state` under server play, so the client
*cannot* overwrite the row. **It was only ever paint.** Gate 57 phase 3 asserts
the server's record permanently, so a real scoring regression would be caught.

### Three defects, one fix (`067180d`)

1. **A miss was filed as a vowel purchase.** `positions=[]` means *two different
   things* — a vowel's first tap and a miss — and the client branched on
   `positions.length`. The response already distinguishes them (`j.purchased`),
   so it branches on that now. The old path also left the key **enabled**.
2. **A resumed board painted nothing.** Server play draws tiles with `data-i`
   only (the client never learns the phrase — backlog 25); legacy uses
   `data-letter`; **both** restores called the `data-letter` painter. Zero
   matches, zero paint — while the same loop lit every letter *including misses*.
   Both restores now go through one `replayPosition()`, fed by the handshake's
   authoritative letter→positions map.
3. **Hit and miss wore the same class** — *including on legacy, which is what
   LIVE runs today.* Live is not correct either; it just fails less visibly.
   `markKeyResolved()` owns the invariant on both paths; `.miss` has its own
   style, with a strikethrough so the two are not separable by colour alone.

### ⚠️ It is NOT a desktop-vs-mobile split — tell Ian this

There is no width branching anywhere in this code, and both widths measure
identically. What differs is **state**: live play shows defect 1; a refresh or a
second device shows 2 and 3. He met one on each device and reasonably read it as
a device difference. **A desktop-vs-mobile gate would have been the wrong axis
and would have passed.**

### Gate 57 (keeper-assigned)

`tools/gates/guitardle-letter-state-gate.py`, registered in `run-all.sh`, row in
`CRAFT-STANDARD.md`. Four phases: live play (both widths) · refresh · two
devices interleaved · legacy.

```
python3 tools/gates/guitardle-letter-state-gate.py                 # GREEN, exit 0
LG_GDLE_SERVED=1 python3 tools/gates/guitardle-letter-state-gate.py  # RED, 19 assertions, exit 1
```
`LG_GDLE_SERVED=1` measures dev2's **served** client (main) instead of this
tree's — that is the red-first direction and how it was proven. Default
substitutes this tree's `game.js`/`style.css` over CDP, because **dev2 serves
main**, so a browser test here otherwise measures main and calls the fix broken.

---

## 2. Traps this cost real time on — do not re-pay them

1. **A class-name assertion can PASS on the very defect.** My first
   "hit and miss are distinguishable" compared class strings: `'used'` vs
   `'purchased'` differ, so it passed — while `.purchased` is styled for
   **vowels only**, so a missed *consonant* had no rule at all and rendered
   exactly like an untouched key. Assert `getComputedStyle`, and compare against
   a **third, untouched control**: two states can differ from each other while
   one is identical to "nothing happened". Saved as a memory.
2. **A repro can exercise the wrong path and look completely plausible.**
   Server play is switched on by **`?sp=1`** (added only for members by the
   front-page block). Loading the game page directly ran **legacy** and produced
   reasonable-looking output that said nothing about the bug. Assert
   `serverPlay === true` before believing anything.
3. **Two tabs are not two devices.** They share cookies *and* localStorage,
   which hides the cross-device bug. Use `Target.createBrowserContext` per
   device — and give each its **own freshly minted** session token; one shared
   token did not survive the second context's reload and the run then measured a
   signed-out page.
4. **Chrome maps dev2 to the INTERNAL ip** (`--host-resolver-rules=MAP
   dev2.loothgroup.com 172.31.78.94`), which is gated — so without the gate
   cookie every page is a styled 403 that looks identical in every state.
   **Liveness assertion first, always.**
5. **A plain public curl to dev2 gets Cloudflare's `Just a moment...` 403** on
   every URL and reads as an outage. Use `--resolve …:127.0.0.1`.
6. **`pkill -f` self-matches even with the bracket trick** when your own command
   line contains the path (a heredoc naming the file is enough). Killed the
   calling shell, exit 144.
7. Both harness bugs I hit — a repo-root path one `dirname` short, and a
   dev-gate token stripped of quotes *before* being split into lines (so it kept
   a trailing quote, 41 chars, and every request was refused) — were caught by
   the **liveness assertion**, which reported CANNOT RUN rather than a
   misleading red. That is the argument for having one.

---

## 3. Open with keeper (nothing is blocked on Ian)

- **Merge all three branches.** Live still carries the Guitardle duplicate
  phrase; it reaches members only on Ian's next paste of the two asset files.
- **A ruling I asked for and did not get: pool-env vs `.local.php`** for the
  weekly-front dev2 flip. Evidence for `.local.php`: dev2's FPM pool files are
  **symlinks into the serving checkout**, so the flip as written leaves the serve
  dirty in two tracked files for as long as Ian is looking, and a dirty serve can
  make a later `pull --ff-only` refuse. It cannot be patched around (env must
  live in its own pool section; two `.conf` files cannot define one pool). If the
  answer is `.local.php`, **the reader must merge before the file is placed** —
  reversed, that is exactly how compose went dark on 8/16.
- **A pre-existing contrast defect I did NOT silently fix**, because it is Ian's
  palette: the existing hit/`used` key is white on `#9FAC8C` = **2.40:1**, which
  fails AA at any size. The new `.miss` is 8.42:1.

## 4. Process note worth keeping

Keeper assigned gate **57**; I had written 56 as a "working placeholder" and was
corrected — 56 was taken by the board committer at the stripe merge. **A
placeholder is still self-minting.** Ask, then write.
