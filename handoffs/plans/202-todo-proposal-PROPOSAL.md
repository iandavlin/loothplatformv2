# PROPOSAL — #202 · the todo page, rebuilt

Lane `202-todo-proposal`. **Design seat: pictures and a plan, zero build.**
Nothing in `tools/lanes-page.py`, `lanes.json`, `lanes-status.sh` or the timer
was touched, and nothing was written to `/var/www/dev`.

**The pictures:** <https://dev2.loothgroup.com/footer-mockups/202-todo-proposal/>

---

## 1. What I measured before drawing anything

Fetched the live page through the dev gate over loopback at 16:43 and parsed the
rendered `Your list` accordion. Then read the same three sources the page reads
— `lanes --json`, the GitHub issues, and the `TEST-URL`/`ACTION` records — using
**`lanes-page.py`'s own record parser, imported from the file**, so what follows
is what the real renderer would emit today, not an approximation of it.

| | |
|---|---|
| bullets on his list | **11** |
| of those, with a working door | **3** (`#148` `#107` `#93`) |
| of those, rendering *"no test link yet — ask keeper for one"* | **8** |
| items owed by Ian that **do not appear at all** | **10** |
| of those ten, that **already have a door written** | **6** |

### The headline: the list froze on 20 August and nothing said so

Every one of the 11 items on his list became his on **19–20 August** — measured
from the `merged`/`built` label event on each issue's timeline, not from
`created_at` (these were bulk-imported from a ledger on 8/19, so age-since-created
reads ~3 days for almost everything and carries no information).

Everything finished since — **#179 #183 #186 #187 #189 #191 #194 #197 #199 #200**
— is invisible to it, although each one's lane parked itself with a reason in its
own words that names him:

    #189  "merged; no-modal uploader live on dev2, awaiting Ian's look at the picture page"
    #197  "script+runbook merged; window is Ian's, keeper verifies each step"
    #200  "merged; …; consent local HELD pending Ian"

**Six of those ten already have a `TEST-URL` written**, sitting in commit bodies
on `main` right now — #179 #186 #187 #189 #191 #199. The machinery #172 built
works. It is pointed at the wrong set.

> **So the page is showing him the 8 items that have no button, and hiding the 6
> that do.** That is the whole of *"to do still isn't quite useful yet"*, and it
> is not a styling problem.

### Why it froze

`build_todo` selects on the **`merged` / `built` label** — a hand-applied label,
part of keeper's merge ritual. The lanes write the truth into their park reason
in the ordinary course of parking, and **the page already loads those reasons —
it just quotes them and never asks them anything.** When the label ritual
slipped, the list quietly shortened, and a frozen list is indistinguishable from
a short one.

That is this page's oldest law failing in the one direction it is written to
prevent: *silence must only ever mean healthy.* Here silence meant
"nobody remembered a label".

### Three smaller findings, each measured

1. **There is no ranking.** The list is in descending **issue-number** order.
   The five longest-waiting items (`#81 #84 #87 #88 #104`, **3.7 days**) are at
   the *bottom*; the freshest is at the top.
2. **The deploy strip prints raw SHAs** — `main 19b43dd  dev2 19b43dd  live
   2163c08 ← differs` — against Ian's own standing format law (*plain English,
   no hashes, no branch names*) that PAGE.md records for every word this page
   shows him.
3. **The page has no light theme.** `body{background:#14161a}` is hardcoded and
   `prefers-color-scheme` appears **zero times** in its CSS, so a viewer in
   daylight gets the dark page regardless of the phone's setting.

---

## 2. The two shapes, drawn

Both are rendered from the **real** 21 items, both themes, phone and desktop.

### Option A — one thing up top, the rest ranked below · **RECOMMENDED**

`option-a.html` — the most-blocking item gets a hero card; everything else is one
compact line, grouped into three plain-English bands, longest-waiting first
inside each:

- **Nothing else moves until you say** — a live deploy, a cutover, a window
  that is his (3 items)
- **One word and members see it** — a flag flip on something already built (9)
- **Just needs your eyes** — a look (9)

Agents, seats, old desks, parked branches, cleanup and the 7-day strip collapse
into **one drawer** at the bottom. The loud layer stays at accordion-depth zero,
as PAGE.md requires.

### Option B — one at a time

`option-b.html` — exactly one card, full size, with `1 of 21` and a skip.
Nothing to parse at all.

**Why A.** PAGE.md's standing answer to *why a page at all* is that it does the
one thing chat cannot: a snapshot he can open cold. B is better for *getting
started* and destroys the snapshot. A keeps both, and answers "what should I do
right now?" in the first 200 pixels.

### What is new in both

- **Ranked by what is stuck behind it, then by how long it has waited.**
- **Every item names what is waiting behind it** in one line — `Behind it: 3
  published posts on live still show the contradictory terms`. Where an item
  blocks nothing but his own eyes, the row carries **the lane's verbatim
  sentence** instead. Nothing is invented for a card that has nothing behind it.
- **#178's confirm button is drawn in place** — the `✓ Landed as expected`
  control, and row 17 is drawn in the state *just after* he taps it
  (struck through, *"you said this landed — keeper is closing it out"*), so the
  parked plan can be judged on what it does rather than on its label.
- **A light theme**, and **plain English** in the deploy line.

---

## 3. What a build seat would do — and the one real decision in it

**The data rule is the whole of the fix.** An item belongs on his list when
**a merged branch is parked with a reason that is still owed to him**, whether
or not anyone applied a label. That is derived from `lanes --json`, which the
page already loads. **No new store, no new endpoint, no new file to maintain** —
which is #202's own constraint, and PAGE.md's rule that a record is something a
lane already writes in the ordinary course of work.

⚠️ **The one thing that needs Ian, not a builder.** A park reason is prose, and
telling *"awaiting Ian's look"* from *"Ian confirmed the token link works"*
is a text judgement. My first pass got it wrong — it read three past-tense
mentions (#173, #174, #180) as pending work. The tightened rule keys on
forward-looking phrasing only, and it is right on today's 31 branches, but **it
is a heuristic and it will mis-read a sentence eventually.** Two ways to close
it, and this is a decision, not a detail:

- **(a)** the page keeps the heuristic, and a wrong call shows as one extra or
  one missing bullet;
- **(b)** lanes write an explicit record — `NEEDS-IAN: look at the picture page`
  — exactly like `TEST-URL`, and the heuristic becomes the fallback for lanes
  that did not.

**I recommend (b) with (a) underneath it**: the explicit line is exact, it costs
a lane one line it is already writing, and the heuristic stops anything from
going silent while the convention spreads. Nothing goes dark on day one.

### Also worth a build seat's time, in order

1. **Write the missing `TEST-URL`/`ACTION` records** for the 8 doorless items.
   Not code — records. This is the single largest gain per minute of work.
2. Rank + band + fold, as drawn.
3. Plain-English deploy line; light theme.
4. #178's button — **its plan is already written and approved-shaped**
   (`handoffs/plans/178-confirm-button-PLAN.md`), and it is the only part of
   this that needs an endpoint. It can land separately.

**Gate 77 would need extending, not renumbering** — the truth rules it asserts
(§ *a seat with a live session is never finished*, § *AT RISK requires unique>0*)
all still hold; the new assertions are about the ranking being derived and about
the *"I could not look"* / *"there is nothing"* distinction surviving the new
data source.

---

## 4. What this seat did NOT do

- **No build.** No renderer, no endpoint, no `lanes.json` change, no timer change.
- Nothing written to `/var/www/dev` — the mock is published through the
  established symlink at `~/projects/footer-mockups/`.
- The proposal's numbers are **baked** into `tools/proposals/202-todo/data.py`
  with per-field provenance, so the pictures are stable for Ian to look at and
  reproducible later. The live page keeps moving; a mock that moved under him
  would be useless.

## 5. Verification of the pictures themselves

`tools/proposals/202-todo/shots.py`, one persistent CDP session (a per-command
socket drops the device-metrics override and photographs a **desktop** page as a
phone). Every shot is gated before it counts:

- **liveness** — the hero's own sentence must be on the page. A locked-out
  browser serves a styled 403 that is identical in light and dark at every
  width and passes a visual suite having measured nothing.
- **counts** — 20 rows, 1 hero, 9 NEW badges, per shot.
- **the light/dark delta** — the two themes must render different backgrounds,
  or one theme was photographed twice.
- **the injected tabbar must be `display:none`** — `/pwa.js` is injected into
  every `text/html` response on this host and has covered an earlier lane's mock.
- **the shared chrome profile is unchanged** — read before and after, never
  written. The mock takes its theme from `?theme=`, never from `localStorage`.

8/8 green. The tab it opened was closed.

**Two defects were found by LOOKING at the pictures, and neither was reachable
by any assertion above**: the masthead printed `&middot;` as literal text (its
own markup was being HTML-escaped), and the gallery's full-page phone captures —
4,900px tall in a 158px column — dragged every grid row down and left the page
full of holes. That is now four separate times on this page that only the
rendered picture has caught the defect.
