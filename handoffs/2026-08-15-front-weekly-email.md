# SESSION HANDOFF — lane `guitardle-fairness`, branch `front-weekly-email`

**Written 2026-08-15. Assume you are a fresh seat with zero context — this is your charter.**

| | |
|---|---|
| Branch | `front-weekly-email`, **pushed**, tip **`d2cb18c`** |
| Base | `origin/main` merged in at `fa89a8a` |
| State | **BUILT, flag OFF, waiting on keeper's merge.** Nothing uncommitted. |
| Gate | **54** (from keeper). Green, 22 assertions, **red-first proven on 11 mutations** |
| Ian | ruled 2026-08-15: *"build it and let me see it on dev2."* He has **not** seen the built thing yet |
| Live | **untouched, and must stay so.** The repo flag is `false` |

---

## 1. The charter, and where it got to

Backlog 8 — Ian, 2026-07-30: *surface the most-recent weekly email on the FRONT
PAGE for logged-out visitors.* Unowned since July.

Mock-first, published at `/footer-mockups/weekly-front/`, two options drawn in
the real front page at both widths and both themes. Ian ruled **Option A** (the
issue's contents as the page's own cards), **his recommended placement** (under
the welcome video, above the featured member) and **member-only cards shown with
their padlock**. Built. Option B — the rendered email in a frame — is drawn,
costed, and deliberately **not built**; it is one ruling away.

## 2. What is blocking, in one line

> **Keeper's merge.** Keeper reported it merged; it is not — none of the seven
> commits is an ancestor of `origin/main`, and main contains zero of the feature
> files. See §6 for how that check misread, because it will misread again.

Once merged: `bash tools/preview/weekly-front-flip-dev2.sh`, then
`python3 tools/preview/weekly-front-shots.py --url https://dev2.loothgroup.com/`,
then board-ping Ian. The flip script **refuses** until the code is really on the
box, so it is safe to run early.

## 3. The store — the part worth not re-deriving

Full write-up: `docs/BACKLOG-8-STORE-AND-BUILD-PLAN.md`. The short of it:

> **`LG_WD_Query::build_payload_from_issue()`**, fed by
> `LG_WD_Issue::latest_sent_id()`. The same resolver the email goes through.

`LG_WD_Front_Feed` is a thin public projection of it over `admin-ajax` (nopriv),
and does four things the email must not:

| | why |
|---|---|
| strips gated excerpts **inside WordPress** | an endpoint that emits gated prose and trusts its caller to hide it is one CSS mistake from a leak |
| maps the tier slug | the taxonomy says `looth-lite`, the CSS says `.rcard--gated-lite`. Unmapped ⇒ a member-only card with **no padlock**, reading as free. Nothing throws |
| drops archived posts | the resolver deliberately returns `post_status=archived` — right for a record of what was sent, wrong for a shop window. Live's August issue contains one (72616) |
| skips the events section | an issue's events are what was upcoming **at send time**; the front page has its own live events row |

Measured facts that shaped all of it: an issue's items span **two card types from
two tables** (forum topics are WP posts but are *not* in `content_item`); card
items carry **no excerpt at all** (layout-v2 keeps content in meta), so the prose
is only in the forum section and manual items; and `normalize_post()` emits **no
author**, so a byline must come from `content_item`.

## 4. Files

```
platform/config/weekly-front.php                        the flag, false
lg-weekly-digest/includes/class-lg-wd-front-feed.php    the nopriv feed
lg-weekly-digest/includes/class-lg-wd-issue.php         latest_sent_id() promoted
                                                        + fires lg_wd_issue_saved
lg-weekly-digest/includes/class-lg-wd-signup-page.php   now delegates
archive-poc/web/index.php                               flag read, cached loopback,
                                                        inline emit above the fm band
archive-poc/web/_render-weekly-issue.php                the block
archive-poc/web/archive.css                             .wkiss, token-driven
tools/gates/weekly-front-gate.py                        gate 54
tools/gates/weekly-front-redfirst.sh                    11 mutations
tools/preview/weekly-front-shots.py                     contrast, mock + --url
tools/preview/weekly-front-flip-dev2.sh                 the dev2-only flip
footer-mockups/weekly-front/                            the mock + decision record
```

**No deploy coupling.** Both new PHP files land in directories already real in
the serving checkout, and `lg-weekly-digest` is symlinked as a whole directory.
One pull, no symlink work.

## 5. What is verified, and what is not

**Verified.** Flag OFF is **byte-identical** — the front page rendered from this
worktree and from the serving checkout have the same byte count and the only ten
differing lines are asset cache-busting stamps from file mtimes. The ON path was
rendered end-to-end on the front-page half by seeding the cache: one block, three
padlocks for three lite items, no raw `looth-*` slug, the issue's own date,
correct order, five images through the resizer with srcset and dimensions, zero
PHP diagnostics. Gate 54 green and red-first proven. Craft gate green standalone.

**NOT verified, and do not claim it is.** The full suite has never completed on
this branch — it died partway into gate 36 with memory tight. The WordPress half
(`serve()` over HTTP, the 404-when-off, the caching) has never run, because the
plugin loads from the serving checkout and the code is not there yet: **that is
the first thing to check after the merge.** The member-viewer path is guarded by
two independent conditions but was never exercised against a real logged-in
session — CLI cannot make one.

## 6. Traps this seat hit, so the next one does not

1. **`git merge-base A B` always prints a SHA.** It is the common ancestor, not
   an answer. Here it printed `origin/main`'s own tip — because main had been
   merged *into* this branch — which is the most convincing possible wrong
   answer. Only `git merge-base --is-ancestor` (exit 0) means merged.
2. **A backgrounded suite's exit code is the launcher's, not the suite's.**
   `nohup … &` reported exit 0 while `run-all.sh` was still on gate 2.
3. **Stamping `data-lguser-theme` poisons the shared chrome profile** —
   `app-settings.js` persists it, and every lane's browser went dark for a run.
   Pin tokens on the mock's own body instead. Saved as a memory.
4. **An assertion on a shared resource must test the delta you caused**, not the
   absolute value: other lanes write that key too, and the first version reddened
   9 of 12 states on an innocent panel.
5. **Grepping source text is not asserting behaviour.** Two gate assertions were
   satisfied by the file's own docblock and stayed green when the filters were
   deleted. Both are callable predicates now.
6. **An absence assertion needs a liveness assertion.** The leak check tested a
   card type that renders no prose at all, so it passed however the guard was
   broken.
7. **dev2 and live disagree about anon reachability, in both directions.** Saved
   as a memory; see §7.

## 7. Open items that are NOT this lane's to fix

- **The public sign-up page is behind a login wall on LIVE.** `/weekly-email-sign-up/`
  bounces logged-out visitors to `wp-login.php`; it is missing from the 67-entry
  `bp-enable-private-network-public-content` allow-list, which *does* contain
  `/looth-group-weekly/`. **Both options' CTA points at that page**, so the
  allow-list entry must land before this feature is switched on for live. Ian's;
  no code, no deploy.
- **The email builder dates every render by the clock.**
  `class-lg-wd-email-builder.php:40` uses `date_i18n('F j, Y')`, so dev2 currently
  serves the July 13 issue under *"Week of August 15, 2026"* — visible to Ian,
  where the preview flag is on. Lines 160 and 249 have the same shape.
- **The sign-up page's preview cache has the same one-hour staleness** this lane
  just fixed for its own feed, and is now one `add_action('lg_wd_issue_saved', …)`
  away from being correct. Left alone on purpose: Ian is testing that page.

## 8. Guitardle, answered for keeper on 2026-08-15 (not this charter, keep the answer)

Cross-device **resume ships with `LG_GUITARDLE_DAILY_CLAIM`**, which is already
on live, and `SERVER_PLAY` is **not** the switch — with it on, `save` is refused
outright (`409 server_owns_state`) because the server then owns the position.

Ian's own row settles his case: user 1, 2026-08-15, phrase 180, unfinished,
`resume_state` **populated** with a six-move position and five revealed letters.
The mirror worked; nothing server-side is broken. A fresh board on his phone
therefore points at the client conditions — and **signed-out fails two of them at
once**, since an anon player has no pending row *and* plays a different phrase
track. Caveat kept honest: the whole live table has only two claimed rows, both
from today, so "his save landed" is solid but "the mirror is healthy in general"
is not yet earned.

---

# ADDENDUM — 2026-08-15 late, the seat that inherited this charter

Read §1–§7 above first; they are still accurate. This records what changed.

## A. The suite now COMPLETES on this branch — the §5 blocker is cleared

§5 said "the full suite has never completed on this branch." It has now.
Branch merged up to `origin/main`, **zero conflicts**, tip **`28c91e9`**, pushed.

**56 gates, ZERO no-verdict, exactly ONE red — `compose` — and it is not ours.**

Gate 54 GREEN in-suite (22 assertions). Red-first **re-run after the merge**:
11/11 mutations caught, so the gate is real against the *merged* tree, not only
the tree it was written on.

## B. The `compose` red is BOX STATE. Do not spend a session on it.

Proven three ways, in ascending strength:

1. It reds identically on `guitardle-phrase-dupe` and on this branch — two
   unrelated branches, same gate, same two findings.
2. **`compose-gate.py` run standalone against PRISTINE MAIN** in `~/keeper-repo`,
   clean tree: **RED, exit 1, same two findings.** No lane branch involved.
3. Cause: dev2 FPM carries `env[LG_FC_PREVIEW] = 1`
   (`/etc/php/8.3/fpm/pool.d/looth-dev.conf` — find it with `grep -R`, **`grep -r`
   misses it**, the pool file is a symlink into the serving checkout) while the
   tracked `platform/config/frontend-compose.php` says `enabled => false`.
   Gate 35 reads the **tracked** flag (OFF) then measures the **served** page
   (ON) — it compares two different flag states.

Keeper's to resolve (drop the pool env, or teach gate 35 the same override
channel it measures). **Shared state — a lane must not touch either side.**

## C. The OFF path was re-proven by hand, not inherited

The merge moved the baseline, so §5's byte-identity claim was re-earned:

- All three `index.php` hunks are **purely additive — zero deleted lines.**
- Two are inert function definitions.
- The third is the *only* unconditional call, `lg_weekly_front_flush_refresh()`,
  and it **returns on its first line** when the flag is off: the global it keys
  on (`$GLOBALS['LG_WEEKLY_FRONT_REFRESH']`) is set only *inside*
  `lg_weekly_front_payload()`, **after** the flag check.
- So OFF makes no loopback fetch, touches no cache file, emits no markup. The
  emit site is double-guarded besides (logged-out only, non-empty sections only).

`weekly-front-flip-dev2.sh --dry-run` **correctly refuses** today (exit 2, names
the serving checkout's commit). The guard was tested before being relied on.

`weekly-front-shots.py` was **pre-tested on the mock**: 12 states, all clear AA,
exit 0, light/dark backgrounds correctly different — so the tooling is known-good
*before* it is needed on the real page. chrome-dev, the gate token and the
`websocket` module were all confirmed live.

## D. THE ONLY REMAINING BLOCKER IS KEEPER'S MERGE

Everything below is structurally impossible until the code is in the serving
checkout, which is why the flip script refuses. **Runbook, in order:**

```
git -C ~/loothplatformv2-clean pull --ff-only origin main
bash tools/preview/weekly-front-flip-dev2.sh            # --dry-run first
python3 tools/preview/weekly-front-shots.py --url https://dev2.loothgroup.com/
```
Then verify on the REAL page, not the mock:
- the anon view actually carries the block;
- **the logged-in-member negative case** (§5's untested half — use
  `tools/preview/mint-wp-session.php`; the block must NOT render for a member);
- a **liveness assertion**, because a locked-out browser photographs a styled 403
  identically in every state and passes having measured nothing;
- then board-post the URL. **Ian asked for this look twice on 8/15.**

## E. Job 1 (the Guitardle repeat) — done, and NOT by this seat

Charter job 1 was already built by a sibling seat in a **second worktree the
charter never named**: `~/worktrees/guitardle-dupe`, branch
`guitardle-phrase-dupe`, committed **unpushed** two minutes before this session
opened, with no live session left on it. **This seat pushed it** (origin,
`64e2597`) and verified rather than rebuilt it — gate 55 green, 13/13 mutations
bite, and, strongest of all, pointing gate 55 at **main's unfixed library** goes
RED naming member days `[12, 65]` = Ian's 23 June and 15 August exactly.

Lesson saved to memory: run `git worktree list`, `git branch -a | grep <topic>`
and `git log --oneline -5 origin/main` **before** starting any chartered job.

## F. Gate numbering — 54 is safe, and main's ledger comment lies

Enumerated across every branch: main registers up to 45/48/49/50/53; in flight
are 46+47 (compose), 51 (profiles-alive), 52 (notif-quickreply), **54 (this
branch)**, 55 (guitardle-phrase-dupe). No collision.

⚠️ `run-all.sh`'s ledger comment on main still reads **`NEXT FREE: 45`** while
that same file registers 45, 48, 49, 50 and 53. Reported to keeper; **not edited
from this branch** — the block says "keeper mints, lanes never," and it is a line
every branch touches.

## G. Backlog 28 (the charter's fallback) needs nothing

`admin-no-offline-shell` @ `464c9e0` is **already merged into main**. No work
there.
