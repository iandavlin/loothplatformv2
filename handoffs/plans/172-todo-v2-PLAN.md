# PLAN — #172 · todo list v2 + accordions

Lane `172-todo-v2`. Branch at parity with origin/main (0/0). Nothing written yet.

## What Ian sees today (measured, not remembered)

Fetched the live page through the dev gate at 17:38. **Your list has 12 bullets and
11 of them start with the word "Try" followed by a raw issue title.** Verbatim first
three:

    📱 Try Checkout is Patreon-blind: a live Patreon member can…  — it's merged; your look is the last thing left.
    📱 Try Dual holders: cancel Stripe while paying Patreon = membership…
    📱 Try Multiple tiers  — it's merged; your look is the last thing left.

No door, no conversation-starter, and the only control is `on GitHub ↗`. That is
exactly the complaint.

## The card, before and after

BEFORE (one bullet, as it renders now):

    📱 Try Checkout is Patreon-blind: a live Patreon member can… — it's merged; your
       look is the last thing left. — the lane said: "merged to main as 0496a73, flag
       lgms_double_pay_block OFF, awaiting Ian's phone check + flip decision"
       on GitHub ↗

AFTER:

    📱 Try to buy a membership while your Patreon is live — you should be warned
       merged; your look is the last thing left.
       the lane said: "flag lgms_double_pay_block OFF, awaiting your phone check"
       [ Do it ↗ ] /lgjoin/          [ Copy for keeper ]
       say: "150 good" · "150 not right"                        #150 on GitHub

## 1. Where the door comes from — the TEST-URL convention (spec pt 2)

**It does not exist yet.** I grepped the whole repo, every branch, and every commit
body on main: zero hits for `TEST-URL`. So this lane defines it, in ONE comment atop
the todo builder in `tools/lanes-page.py`, and nowhere else.

A **record** is a line in any of three places a lane or keeper already writes:

    TEST-URL: /lgjoin/                  ← applies to the issue this record is about
    TEST-URL #148: /lgjoin/             ← explicit, for a batched merge or a rider
    ACTION #148: Look at the join page — three tiers and their prices

Read from, in this precedence order (first hit wins, newest first within a source):

1. **the issue's own comments and body** — live, so a correction lands on the next
   5-minute redraw with no merge needed;
2. **commit bodies on main** (`git log main --since=45.days`, not `--first-parent`,
   so a LANE can write the record at build time, not only keeper at merge). Attributed
   by, in order: an explicit `#n`; the first `#n` in the commit subject
   (`merge #170: …`); a leading `<digits>:` in the subject (`170: close — …`);
3. **the park reason** — the lane's last words, already on the page.

`ACTION:` is the same parser, one extra key, and it is what stops the hand-map below
from growing forever. Both keys are optional; a record with neither is ignored.

**Safety: the URL lands in an `href`.** Only a same-site path (`/…`) or an
`https://dev2.loothgroup.com/…` URL is accepted. `javascript:`, `data:` and any
off-site host are dropped as if no record existed — asserted in the gate.

**No record ⇒ the card says so** ("no test link yet — ask keeper for one"). It never
substitutes the GitHub link, per the spec. And a GitHub read that FAILED says
"test link unknown — GitHub read failed", never "no test link yet": the page's own
law is that silence may only ever mean healthy.

### Seeding the four already-live items

I verified each against source, not against a status code — two of the four are
member/admin-only and answer anon with a 404/302 **by design**:

| issue | door | verified |
|---|---|---|
| #148 multi-tier | `/lgjoin/` | 200 anon |
| #129 composer redesign | `/hub/?compose=1` | 200; `bottom-nav.js:1821` reads `?compose=1` |
| #93 front-end compose | `/compose/?type=loothprint` | 404 for anon **by design** — `lg-frontend-compose.php:757` returns for a logged-out visitor; path is `compose`, type is the query arg. (`/compose/loothprint/` is NOT it — that 301s to the CPT archive.) |
| #107 featured members | `/wp-admin/admin.php?page=lg-featured-member` | 302 anon → login; slug from `FeaturedMemberDash.php:39`, parent `lg-layout-v2` |

**Seed delivery: a commit body on this branch**, carrying the four `TEST-URL #n:` +
`ACTION #n:` records. No GitHub writes, no rewritten history, and it arrives on main
by the same door every future record will use. To verify it before the merge, the
renderer gains `--repo DIR` (it currently hardcodes `~/keeper-repo`), which also lets
the gate test the git leg in a throwaway repo.

I am **not** seeding the other 8. I know those issues only by their titles, and a
confidently wrong action name is worse than an honest generic one. They get the
family fallback below, and my report names all 8 so keeper can add records at the
next touch.

## 2. Every card leads with an action (spec pt 1, 4, 5)

- **Action name**, in order: the `ACTION:` record → a family verb + the plainised
  title. Never the bare title. The fallbacks are real verbs, so an unseeded card
  still leads with an action:
  - `built` → **"Say GO to switch on** the sitemap**"**
  - `merged` → **"Look at** the composer redesign**"**
  - `plan-ready` → **"Say GO on** …**"** (unchanged)
- **Suggested replies**, derived from the family + the issue number, so they are
  always true and never invented: built → `GO on 81` · `hold 81`; merged →
  `81 good` · `81 not right`; plan-ready → `GO on 172` · `not yet`.
- **Copy for keeper** copies `Re #150 Try to buy a membership while your Patreon is
  live — ` followed by `[150 good / 150 not right]` so he pastes and deletes one.
- **`on GitHub` demoted** to fine print at the card's foot, after the buttons.
- **No-Ian-action cards excluded.** The only rule I can derive without guessing:
  `merged` + `infra` + NOT `built` = keeper tooling with no member-facing surface.
  Today that is exactly **#138** (the approve-watcher) and nothing else. It does not
  vanish — it drops to a one-line "landed, nothing for you to do: #138" inside the
  section, because silently dropping something from his list is the one failure this
  page must never have. **Say the word and I make it vanish entirely instead.**

## 3. Accordions (spec pt 6)

`<details data-acc="…">` + `<summary>`, no framework, matching the plan-text pattern
already on the page. Sections: Your list · Agents · In motion · Seats · Old desks ·
Parked · Landed on main · Cleanup. Summary = name + live count
(`Your list — 12 items`, `Agents — 3 · 1 working`, `Parked — 9 branches`).
Default **collapsed except Your list**; open state per section in
`localStorage['lg-lanes-acc:<id>']`, applied server-side-first so there is no flash.

**The loud layer never collapses** — deploy gap, AT RISK, UNBACKED, COLLISION,
"GitHub unreadable", APPROVED-NOT-STARTED, the capacity and resource strips. A
collapsed AT RISK is a hidden AT RISK. That is a gate assertion, not a note.

## 4. Verification

- Scratch-dir render against the REAL `lanes --json` and the REAL GitHub, diffed
  against the live page. **Nothing is written to /var/www/dev.**
- **The clipboard button is clicked for real** (chrome-dev CDP, permission granted,
  read back with `clipboard.readText`) — the charter asks for the button, not the
  markup. This runs once in the build and is **reported, not gated**: gate 77 is
  deliberately browser-free so it cannot flake under load or go vacuously green
  behind a locked-out session, and I will not spend that property.
- **Gate 77 is EXTENDED — no new number minted.** That also means **no edit to
  `tools/gates/run-all.sh`**, which is currently contested by `169-front-polish` and
  `emoji-picker-build`; the new leg's description goes in the gate's own docstring.
  New assertions: the record parser and its precedence; the href allow-list; no
  record ⇒ says so and never a GitHub substitute; a failed read ⇒ loud; every bullet
  leads with a verb; copy payload shape; replies per family; #138-shaped exclusion
  present as a line; every section is a `details` with a count; only Your list `open`;
  and **every loud block is outside every accordion**. Red-first mutations for each,
  against snapshots, plus no-ops that must stay green.
- I will offer Ian a **rendered preview** at `/footer-mockups/…` (that path symlinks
  to `~/projects/footer-mockups`, so it is a lane-publishable dev-gated URL and still
  not a write to /var/www) if he wants the picture before the merge.

## Files I expect to touch

    tools/lanes-page.py                        the work — records, cards, accordions, --repo
    tools/gates/lanes-page-truth-gate.py       gate 77, new leg (no new number)
    tools/gates/lanes-page-truth-redfirst.sh   its mutations
    docs/domains/PAGE.md                       required in the same commit (issue wears `page`)
    handoffs/plans/172-todo-v2-PLAN.md         this file
    handoffs/2026-08-20-172-todo-v2.md         the closing report

  Possible, flagged rather than assumed:
    tools/gates/run-all.sh                     ⚠ CONTESTED by 169-front-polish and
                                               emoji-picker-build. I intend NOT to touch
                                               it; if the gate description must live there
                                               I will say so before writing.
    tools/lanes-status.sh                      not expected — park + lane-state reasons are
                                               already in the JSON. Listed because the record
                                               parser reads them and I would rather over-declare.

## What I will NOT do without a further word

Post the seed as GitHub issue comments (12 writes to Ian's repo). The commit-body
seed above needs no external write, so I am not asking for that unless he prefers it.

## Fallback queue (from the charter)

1. Accordions alone are shippable if the TEST-URL plumbing stalls.
2. Blocked ⇒ park with the blocker stated.
