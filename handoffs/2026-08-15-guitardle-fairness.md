# SESSION HANDOFF — lane `guitardle-fairness`

**Written 2026-08-15. Assume you are a fresh lane with zero context — this is your charter.**

| | |
|---|---|
| Branch | `guitardle-fairness`, **pushed**, tip **`613c0ce`** |
| Base | rebased on `origin/main` `b9c48ba` (after gate 38 was minted; 37 is in the ledger there) |
| State | **MERGE-READY. Both flags OFF.** Nothing uncommitted, nothing session-only. |
| Gates | **ALL GATES GREEN** — full `run-all.sh`, exit 0, zero NO-VERDICT. Gate 37 is this lane's, red-first proven. |
| Ian | ruled 4× (8/14 backlog item, 8/15 "Agree — relabel only", the in-game line, the Hardcore instructions). Preview URL given; **not yet seen it.** |
| Live | **needs the migration applied before the flag is ever flipped.** Ian's action. |

---

## 1. What this lane found, in one breath

**The stated premise was half wrong, and correcting it is the whole value.**
Attempts were *not* purely device-local: `guitardle_results` has carried
`UNIQUE (wp_user_id, play_date)` + `ON CONFLICT DO NOTHING` since June. Measured
on live over 7 days: **93 successful POSTs → exactly 93 rows.** Device-hopping
*after finishing* was already blocked, and a gate asserting "a member cannot
record two results in one day" would have been **green on the defect**.

The leak was **ABANDONING**. Nothing was written until `handleWin`/`handleLoss`,
there is no forfeit (retired 6/12), and the mid-game snapshot lived in
`localStorage` — per DEVICE. So: reveal letters until the phrase is readable,
close the tab (no row, no lock, **no trace**), reopen in incognito, guess it in
one move for 10 points — 20 with hardcore. One POST, one row, indistinguishable
from honest play.

**Evidence it was used.** WP user 197: 27 plays, 27 wins, *every* win ≤4 moves,
**7 of them in a single move** (guessed cold, zero reveals). The field's best
average is 4.1; 197 sits at 2.7. Nobody else has that shape — 206 (34/34) and
728 (17/17) average 6.5 and 7.1, which is normal, and I would not flag them.

## 2. What shipped — TWO independent flags, both OFF

`LG_GUITARDLE_DAILY_CLAIM` — the fairness change (1–4 below).
`LG_GUITARDLE_HOW_TO_PLAY` — the rules overlay (5), split out by keeper on
8/15 so members need not wait for the fairness flip to read the instructions.
Gate 37 drives **all four combinations**; "independent" is only a word until
both crossed states are exercised.

1. **Claim at START.** New `start` action inserts `(user, play_date, phrase_id)`
   with the result NULL. The **existing** unique constraint does the work — no
   new constraint, no new index, **no ON CONFLICT arbiter change** (that would
   have thrown 42P10 on every statement still on the old predicate). Finishing
   `UPDATE`s only `WHERE moves IS NULL`, so *first finished result still wins*.
2. **Resume.** Position mirrored server-side, returned as `pending`. A second
   device picks the game up rather than being locked out. This is deliberate:
   the honest case this meets most often is a phone dying mid-game, and a bare
   lock would read to Ian as a bug.
3. **Relabel** (Ian: "Agree — relabel only"). Promo card says "sign in to claim"
   to logged-out, "play to claim" to members. Anon *already* could not claim a
   spot (`uid<=0` → 401; the board reads only `guitardle_results`), so this is
   honesty about an existing constraint, not new tracking.
4. **In-game line**: "Playing for fun — sign in to compete for the Weekly Top 5."
5. **A How to Play overlay — which had to be BUILT.** The instruction was "add a
   Hardcore section to the How to Play overlay"; there was no such overlay. The
   served game had **no rules surface at all** (the prototype's was dropped;
   `assets/icon-question.svg` shipped unreferenced), and Hardcore — which
   **doubles** weekly points — was explained only by a `title=` tooltip, which
   touch never renders, i.e. invisible on the platform the brief calls primary.

## 3. Traps this lane hit (each cost real time)

- **Chrome served a cached preview shim** and reported flag-OFF revealing both
  new surfaces — i.e. that OFF was not a no-op. It was cache. `setCacheDisabled`
  + `clearBrowserCache` + clearing `localStorage` between runs gave the true
  reading. I nearly reported a defect that did not exist.
- **Shell env does not persist between tool calls**, so `$LG_GATE_TOKEN` from
  `gate-env.sh` was empty and the dev gate returned a 403 that looked exactly
  like the known cookie trap. Only the liveness assertion in the screenshot
  script stopped a styled 403 being screenshotted and called green.
- **`gate-env.sh`'s token was not the armed one anyway** — the live value is in
  box-local `/etc/nginx/conf.d/loothdev-auth.conf` (`loothdev_auth`, dotted domain).
- **The publish path injects app chrome.** Loaded top-level, `/footer-mockups/`
  gets the tabbar and the big green "+" straight over the overlay, and the font
  falls back to serif. None of it happens in the real embed — `pwa.js` bails on
  `window.top !== window.self`. The preview is therefore an **iframe wrapper**,
  so what Ian sees is production shape. Verify in the iframe, not top-level.
- **`json.dumps` for shell args ate `$vars`** in PHP snippets passed to `wp eval`.
  Use `shlex.quote`.
- **psql renders a boolean as `true`, not `t`,** when concatenated with `||`.

## 4. Gate 37 — `tools/gates/guitardle-claim-gate.py`

Registered in `run-all.sh`; `docs/CRAFT-STANDARD.md` row 37 added.

- **Red-first is real**: `LG_GDLE_ENDPOINT=<origin/main's endpoint>` re-runs it,
  and the starred assertion *"device B gets NO fresh allowance"* fails there
  along with 6 others. It was broken before it was trusted.
- **Drives the WORKING TREE** via `guitardle-claim-probe.php` with a real WP
  session cookie. curl would reach `/srv`, i.e. the serving checkout, and test
  `main` — the classic lane self-deception.
- **Phase 0 is a liveness assertion** before any "no row was written" is believed.
- **It caught two of my own mistakes**: a JS constant my first promo patch emitted
  in the OFF state (so OFF was not byte-identical to `origin/main`), and a
  *vacuous PASS* — a member-vs-anon comparison passing on two empty strings while
  the renderer was fataling on an undefined `h()`.
- Creates/reuses a `gdle_gate_probe` subscriber on dev2 WP; deletes every row it
  writes and **asserts the cleanup**.

## 5. Open, and NOT mine to close

1. **LIVE MIGRATION.** `archive-poc/sql/guitardle-claim.pg.sql` is applied on
   dev2, **not live**. It must land *before* the flag is flipped there. Ian's.
2. **ROLLBACK IS TWO STEPS.** Flag false, **then**
   `DELETE FROM guitardle_results WHERE moves IS NULL;`. One step alone silently
   eats that day's results for anyone mid-game. Documented at the foot of the
   migration and gated.
3. ~~Top-level `/guitardle` retirement~~ — **DONE** in `613c0ce` (12 files,
   2777 lines), on keeper's instruction under Ian's standing prune mandate.
   Evidence re-measured at deletion time, not quoted from notes.
4. ~~Split the overlay onto its own flag~~ — **DONE** in `613c0ce`.
5. **Not fixed, reported: ~8% of honest results are silently discarded.** 8 of
   101 finished games POSTed and got 403 on an expired WP nonce, across 8 distinct
   IPs and 6 days, and `postScore` ends in `.catch(() => {})` — the player sees
   their win card and it never counted. Fix is small (refetch the nonce on 403,
   retry once) but it is a behaviour change outside this charter.
6. **Not fixed, reported: the score is self-reported.** `moves`, `won` and
   `hardcore` all come from the client body and `hardcore` **doubles** points, so
   anyone with their own nonce can POST a 20-point day. Closing the device hole
   does not touch this; it is the ceiling on how fair the board can be.

## 6. Ian's look-ruling, 8/15

*"can we spread the instructions a little"* — the panel read as a cramped narrow
column on desktop. Now: wider panel and more space as the screen grows, phones
untouched (measured 340px/14px/9px before **and** after). Spread means SPACE,
not smaller type — text goes **up** to 15px where there is room.

**Explicit grid, not `columns: 2`.** Automatic balancing was measured first and
looked like a bug: it packed "The basics" alone into column one, stacked the
other two in column two, left a third of the panel empty and still overflowed
the fold. The two long sections now sit side by side with the short one spanning
beneath. The first list also gained the heading it never had, so the three
sections read as peers.

Verified in a browser at 390 / 700 / 1280 through the real iframe shape, and the
Done button **hit-tests as itself** at all three — the panel is taller than a
phone viewport, so "present" was not good enough.

## 7. Preview

<https://dev2.loothgroup.com/footer-mockups/guitardle-claim-preview/> — iframed
to match production. `?preview=on` on the inner frame forces the flag-ON
surfaces; without it you see the OFF state, which is what merges.
Source: `/home/ubuntu/projects/footer-mockups/guitardle-claim-preview/`.
