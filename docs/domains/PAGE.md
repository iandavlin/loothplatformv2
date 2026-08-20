# PAGE — the lanes status page

## The map
https://dev2.loothgroup.com/lanes/ (+lanes.json). Renderer:
tools/lanes-page.py, run by lanes-page.timer every 5 min AS ubuntu **from
`~/keeper-repo`, not the serving checkout** — so a page change goes live when
keeper's clone pulls, and only then. Data from `lanes --json`
(tools/lanes-status.sh) + GitHub issues (token in /etc/looth/env,
server-side only).

Sections in order: capacity · resource strip · deploy gap · AT RISK /
UNBACKED / COLLISION · **Your list** · **Agents** · In motion (`investigating`
label) · **Seats** · old desks · reconciliation · parked · cleanup · 7-day
shipped strip.

## Rules that are load-bearing
- **Quiet-when-healthy**: sections are ABSENT when clean; silence only ever
  means healthy — failures render LOUD (UNKNOWN live read, GitHub unreadable).
  *"Nothing waits on you"* and *"I could not look"* must never render alike.
- The page prints its generation time: an old timestamp = dead timer.
- **No token in the browser, ever** (Ian's ruling): the page reads via the
  box; acting goes through one-verb endpoints — lanes-approve.php (add
  `approved`), lanes-poke.php (tell keeper a seat looks idle), each POST-only
  behind a per-day HMAC nonce derived from the token.
- Static regen only: the web user can't run git (dubious ownership) — that's
  WHY it's a timer, not on-request.
- **Ian's format laws reach every word the page shows him**: plain English, no
  hashes, no branch names, no jargon. Git numbers are rendered as words
  ("3 commits of its own · in step with main"), issue titles are plainised
  (ledger prefix stripped, SHOUTING sentence-cased, trailing attributions
  dropped) — conservatively, because a mangled title is worse than a long one.

## The truth rules (#151, and why they exist)
Three misreads in twelve hours, 2026-08-19: a working lane shown parked, a
brand-new lane shown *finished & freeable*, an approved-and-running issue shown
*APPROVED, NOT STARTED*. **All three were one line** — `unique == 0` meant
`done` in lanes-status.sh. A branch cut minutes ago has zero unique commits, so
it read as finished, LEFT the seats table, and its issue then had no seat.

- **tmux ground truth outranks every derived guess.** A session with an active
  spinner is WORKING, full stop. The probe lives in ONE function in
  lanes-status.sh so nothing can drift from it.
- A seat with a live session is **never** *finished & freeable*; nor is a
  branch we positively know is younger than an hour. Unknown age reads as OLD,
  so the guard fires only on real knowledge.
- **AT RISK requires unique > 0.** It was reachable by an empty fresh branch,
  and the page rendered *"has 0 commit(s) on one disk only"*.
- An issue has a seat if a seat **carries its number, rides on another seat, or
  has a parked branch**. "Nothing started" is a claim about history, and the
  branch is the history. Batching four issues onto one lane once printed all
  four as unstarted while that lane was building them.

## The four chips (#159 — Ian's ruling 8/20, "It feels like a chip")
**working · needs you · needs keeper · retired.** A six-state taxonomy was
proposed and REJECTED: fewer states that are always true beat rich states that
are sometimes wrong. Each chip is followed by its **verbatim** reason where one
exists — the lane's own `.lane-state` line, or the `PARKED:` / `STOOD DOWN:`
subject remainder. Every needs-you chip is mirrored as a todo bullet with its
action named.

Derived in lanes-status.sh from box truth (tmux · `.lane-state/{BLOCKED,
QUESTION,DONE}` · the tip subject · unique+age); the renderer adds only the
layer the shell cannot see — an issue wearing `merged` or `built` is waiting on
Ian, so its seat says so, but **never over a live worker**.

## Your list (#155) and Agents (#164)
- **Your list** is a checklist, not a dashboard: one bullet, one action, plain
  words. Four families, ordered by what they unblock — a lane's question, a
  plan awaiting GO (`plan-ready` sans `approved`, carrying the Approve button),
  a `built` issue one flip from members, a `merged` issue awaiting his look.
  Derived from labels and `.lane-state`, **never hand-maintained**. It carries
  the lane's verbatim park reason where one exists.
- **Agents** is the WORKERS view and is deliberately not a second description
  of the desks — Ian: *"No seats, no branches, no git in this section."* One
  line per LIVE agent: ordinal, issue, casual descriptor, and the live spinner
  verb. An agent alive but at a prompt says what it waits FOR ("waiting for the
  keeper to merge"); *"parked"* is not information.

## ⚠ The working-detector drifts, and it has bitten twice in one day
The CLI's spinner shape is not stable. 2026-08-20 morning it dropped
`esc to interrupt`; the same afternoon a raised thinking effort began appending
`· thinking with xhigh effort` INSIDE the parens, so a pattern anchored on
`tokens\)` read every deep-thinking lane as idle. **Neither the detector nor
the extractor may require the closing paren** — match the token clause and stop
there. And a no-match `grep` exits 1 under `set -e` + `pipefail`: that killed
the whole script, so `lanes --json` printed NOTHING and the page failed for a
reason nowhere near the pane it was reading. Gate 77's tmux leg exists for this.

## Deploy couplings a `git pull` does NOT do
1. `~/keeper-repo` must pull — the timer runs the renderer from there.
2. `/var/www/dev/lanes-poke.php` needs its symlink (`install-symlinks.sh --new-only`).
3. `lanes-poke.path` + `.service` need linking and `systemctl enable --now`.
4. `~/.lanes-poke-request` (0666) and `~/.lanes-poke/` (0777) must be pre-created —
   the web user can traverse `/home/ubuntu` but cannot write it.
`bash tools/lanes-poke-install.sh` does 2–4 (all of them under sudo), which is
why it exists: a deploy step in the repo is traceable to a commit; a remembered
one is not.

## Issue history
#133 copy buttons · #137 In-motion · #139 approve button · #140 new-tab links ·
#143 resource strip + refresh button (all closed 8/19). Closed 8/20 by the
155-page-train: #155 Your list · #151 chips that never lie · #156 poke keeper ·
#159 the four chips · #160 spinner verb + one card per seat · #164 Agents.
Gate 77 covers all six. Open: #145 (composer discussion input, scope from Ian).
