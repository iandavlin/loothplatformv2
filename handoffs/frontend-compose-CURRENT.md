# HANDOFF — lane `frontend-compose` — CURRENT

*Lives in `handoffs/` per the 2026-08-15 convention. Supersede by rotating this to
`handoffs/YYYY-MM-DD-<suffix>.md` and writing a fresh CURRENT.*

**Written 2026-08-16 by a fresh seat that was told to trust nothing remembered —
and was right not to. The previous CURRENT was two days stale and described a
DIFFERENT LANE (layout-v2, branch `frontend-compose` @ `e2c19e4`, "root disk
100% full"). That is why this file leads with what is TRUE NOW.**

| | |
|---|---|
| Branch | **`compose-draft-first`**, tip `074a656`, pushed, 0 behind main, merges CLEAN |
| Worktree | ⚠️ **TWO.** `~/worktrees/compose-draft-first` is the live one. `~/worktrees/frontend-compose` is still parked on the SUPERSEDED `compose-loothprint-modal` |
| Flags | `platform/config/frontend-compose.php` → `'enabled' => false` (tracked). dev2 is ON via the untracked `.local.php` — see below |
| Merge | Waiting on keeper. Full suite result pending at time of writing |
| Also mine | `notif-quickreply-v2` @ `8b988b5` (merges clean, gate 52 free) · `seo-canonical-hub` @ `a9251ae` (**MERGE READY**) |

---

## THE THREE BRANCHES THIS LANE OWNS, and which are dead

- **`compose-draft-first`** — LIVE. Draft-first media model + gates 46/47.
- **`seo-canonical-hub`** @ `a9251ae` — **MERGE READY**, verified 8/16. Bare hub
  declares its own canonical + gate 20 covers the static sitemap.
- **`notif-quickreply-v2`** @ `8b988b5` — merges clean, gate 52 free on main.
- **`compose-loothprint-modal`** — ⚠️ **DEAD, DO NOT REVIVE.** Superseded: main
  deleted the modal it was built around. Its subtitle trim (`b457cd3`) keys on
  `#lpm-body`, which is **zero** on main. See below.

## IAN'S 8/15 "REDUNDANT TEXT" ITEM IS CLOSED — and nothing of ours fixed it

He approved the dark compose modal with one fix: *"there is redundant text in the
beginning"*. The trim WAS built, and it must NOT ship: Ian's own later ruling
(`4dbb192`, *the form LEAVES the modal*) deleted the surface the defect lived on.
The duplication only ever happened in the modal, because `loadForm()` copies
`<link>` and `<script>` but not `<style>`, so the route's inline CSS never arrived.
On the standalone page that CSS is in the same document as the markup.

**MEASURED on the serve 8/16, not reasoned** — real Chrome, allowed member,
liveness asserted first (a locked-out browser renders a styled 403 that is
identical at every width and would score a clean "1 subtitle"):

```
desktop 1280  visible subtitles = 1   narrow computed display:none
phone    390  visible subtitles = 1   wide   computed display:none
page HTTP 200, 184,627 B, title "Share a Loothprint", form present at both widths
```

Header reads title → ONE sentence → first field. Probe kept at
`tools/frontend-compose/` pattern; it reads **computed style, not class names**,
because the recorded trap is that a class-name assertion passes on the very defect.

## THE FLAG — read this before touching how compose is switched on

`lg_fc_enabled()` reads, in order: `LG_FC_PREVIEW` (getenv **and** `$_SERVER` — a
fastcgi_param lands only in the latter), then the tracked config, then the
**box-local `platform/config/frontend-compose.local.php`**, which wins.

- dev2 is ON **only** via that `.local.php`. Verified: `lg_fc_enabled()` true.
- **Live is protected by ABSENCE.** No code asks which box it is on.
- ⚠️ **ORDER:** the READER merges and the serve pulls BEFORE the `.local.php` is
  placed. Reversed on 8/15 and compose went dark — the file existed, nothing read
  it, the pool env was removed in the same change, and `/compose/` answered 404 to
  an allowed admin. `.gitignore` now globs `platform/config/*.local.php`.
- The pool-env mechanism is **dead**. Anything still naming `env[LG_FC_PREVIEW]`
  as how dev2 is on is stale — it survives ONLY as the lane-preview override.

## WHAT I FOUND IN THE SALVAGE (and the lesson)

The 13:05 salvage reported fixing the exit-3 trap in gate 46. **Gate 47 —
registered eleven lines away, the other half of the same pair — still returned 3
on all three CANNOT RUN paths.** `run-all.sh` reads anything but 0/2 as RED, so it
would have reddened the suite **for every lane**. Fixed in `074a656`, proven both
directions through run-all's own case statement:

```
exit 3 -> RED (exit 3)          red=1 dead=0   <- blocks the train
exit 2 -> NO VERDICT (exit 2)   red=0 dead=1
```

Same file also still DROVE the deleted modal (clicked `#ntm-typetoggle`, waited for
`#lpm-body .acf-field`) — both zero on main, so 40s of dead wait per run at both
widths. Excised.

**THE LESSON, which is the reusable part: a commit message saying a file was
repointed is not evidence that it was finished. Read the file.**

⚠️ And on my own process: my first assertion (`no lpm-body anywhere`) FAILED
because it matched the explanatory comment that exists to tell the next reader why
the selector changed. That is the assert-matches-prose trap. Assertions now target
the live QUERY, and one asserts the COMMENT SURVIVES.

## OPEN — who unblocks what

| Item | Who |
|---|---|
| Merge `compose-draft-first` @ `074a656`, `seo-canonical-hub` @ `a9251ae`, `notif-quickreply-v2` @ `8b988b5` | keeper |
| Ruling on gates **46/47 self-minted** by the predecessor (they do not collide: main uses 1–32, 34–45, 48–51, 53) | keeper |
| **Serving checkout is DIRTY** — `loothplatformv2-clean` has a modified `platform/fpm/dev2/looth-dev.conf`. Latent, not blocking (incoming main does not touch it) | keeper |
| `FLAGS.md` **back-pill row says dev2 OFF; it is ON** via `back-pill.local.php`. Not mine to rewrite | keeper |
| Gate 46 reports CANNOT RUN until merge + serve pull — by design, the docroot symlinks to the serving checkout | — |

## BOX TRAPS THAT COST ME TIME TODAY

1. **`2>&1 | wc -l` counts the warning.** A missing file "found 1 occurrence".
2. **`git diff main..branch` on a branch behind main** shows main's newer work as
   deletions — `seo-canonical-hub` looked like −6,811 lines. Diff from the
   **merge-base**, and test the real merge.
3. **`wp` needs `--path=/var/www/dev`** and a user who can read
   `/etc/looth/live-wp-keys.php` (`looth-dev`, not `www-data`).
4. **A gate resolves `gate-env.sh` beside itself** — copying one gate to a
   scratchpad makes it CANNOT RUN. Use a worktree.
