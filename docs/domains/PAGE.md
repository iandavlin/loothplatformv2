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

Since #172 every *section* is a `<details class="acc" data-acc="…">` whose
closed line is **name + live count**, so the default view of the page is a
snapshot. Default collapsed except **Your list**; open state per section in
`localStorage['lg-lanes-acc:<id>']`, applied over the server-rendered default so
a section he keeps open does not flash shut on the 5-minute redraw. **The loud
layer is never inside an accordion** — deploy gap, AT RISK, UNBACKED, COLLISION,
the GitHub-unreadable banner and APPROVED-NOT-STARTED all render at
accordion-depth zero, because a collapsed AT RISK is a hidden AT RISK. Gate 77
asserts that by walking `<details>` depth, not by eye.

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

## The TEST-URL convention (#172) — a door on every bullet

Ian 8/20 on the shipped list: *"the list for me isn't super useful. Can we get
links and copy and paste so I can talk to you about them?… This is hard for me
to parse or get started with."* Measured before building: **12 bullets, 11 of
them the word "Try" followed by a raw issue title**, one control each
(`on GitHub`), no door and no way to reply.

**A record is one line**, written by a lane or keeper in the ordinary course of
work. Two keys, both optional:

    TEST-URL: /lgjoin/                the dev2 door where the thing happens
    TEST-URL #148: /lgjoin/           explicit — batched merge, or a rider
    ACTION #148: Look at the join page — three tiers and their prices

**Where the page looks**, first hit wins, newest first inside each source:
1. the issue's own **comments**, then its **body** — live, so a correction
   reaches him on the next redraw with nothing to merge;
2. **commit bodies on main**, deliberately **not** `--first-parent`, so a lane
   can write the record in its own commit at build time and not only keeper at
   merge time. Attributed by an explicit `#n`, else the first `#n` in the
   SUBJECT (`merge #170: …`), else a leading number (`170: close — …`). The
   subject is never scanned for records, only for that number.
3. the **park reason**.

**The convention is defined in exactly one place** — the comment block above
`build_todo` in `tools/lanes-page.py`. There is no map of issue numbers in that
file and there must never be one; the four already-live doors were seeded as
records in a commit body, which is the same door every future record uses.

⚠ **Three rules that are load-bearing, each paid for:**
- **A record is a STRUCTURED LINE, never prose with a path in it.** The whole
  remainder after the colon is the value and `safe_url()` rejects any value
  containing a space, so `TEST-URL: /lgjoin/` is a record and
  `TEST-URL: /lgjoin/ — try it signed out` is not. This was found the honest
  way: the commit that added the feature quotes an example record in its own
  message, and a first-token reading handed **#172** a door pointing at the join
  page.
- **The href is untrusted input.** It arrives from an issue comment or a commit
  body and lands in an anchor. Same-site paths and `dev2.loothgroup.com` /
  `loothgroup.com` only; `javascript:`, `data:`, protocol-relative `//host` and
  every third-party host are dropped exactly as a malformed line is.
- **No record is not a GitHub link.** The card says "no test link yet — ask
  keeper for one". And a source that FAILED to read says
  *"test link unknown — a GitHub read failed"*: `records_ok` is ANDed with
  `gh_ok`, because "there isn't one" and "I could not look" must never render
  alike.

**Keeper carries this forward at merge time** — one `TEST-URL:` line in the
merge body and Ian gets a working door on that bullet, with no page edit.

**Seeded 2026-08-20** (each verified against SOURCE, not an anon status code —
two of the four answer anon with a 404/302 *by design* and a naive 200-check
calls them broken): #148 `/lgjoin/` · #129 `/hub/?compose=1` · #93
`/compose/?type=loothprint` (the path is `compose`, the type is the query arg;
`/compose/loothprint/` is the CPT archive and 301s away) · #107
`/wp-admin/admin.php?page=lg-featured-member`.

## The card, and the fifth family (#172)

Every bullet leads with a plain-words **ACTION** — an `ACTION:` record if one
exists, else a real verb from the family with the plainised title as its object
(`Say GO to switch on …` / `Look at …` / `Say GO on …`). **Never the bare
title.** It carries the **Do-it** link, the **suggested one-word replies**
derived from family + issue number (`GO on 81` · `hold 81`; `81 good` ·
`81 not right`) so they are always true and never guessed from a title, and a
**Copy for keeper** button whose payload is exactly
`Re #<n> <action> — [reply / reply]`. `on GitHub` is demoted to fine print.

**The fifth family — Ian's ruling 8/20.** `merged` + `infra` + NOT `built` is
keeper's own tooling: no member-facing surface to look at, no flag to say GO to.
Those drop out of the bullets to **one quiet line** (`landed, nothing for you to
do: #138`) rather than vanishing. He was offered both and chose the quiet line:
*a wrong quiet line is recoverable and a wrong disappearance is not.* Today the
rule matches exactly #138 and nothing else.

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
Also closed 8/20, by 172-todo-v2: **#172** todo v2 + accordions — the TEST-URL
convention and the card, both above. Gate 77 was EXTENDED for it: **no new
number was minted, and `run-all.sh` was deliberately not touched**, because two
other lanes held that file.
Gate 77 covers all six. Open: #145 (composer discussion input, scope from Ian).

⚠️ **#171 wears the `page` label but is NOT a lanes-page issue.** It is the
Patreon/join funnel dark-mode contrast pass (Ian 8/20: *"dark mode is sucking on
the patreon stuff"*), worked on `169-front-polish` alongside #169. Its findings,
its map and its traps live in **MEMBERSHIP.md**, where the next person touching
those surfaces will actually look — putting them here would bury lanes-page
knowledge under a stylesheet audit. Recorded rather than silently relabelled: the
domain rule says a domain-labelled issue updates that domain's file in the same
commit, so this line IS that update, and the label itself is flagged to Ian for a
ruling. Gate 80 covers the behaviour.

## Trap: mirror rows store ABSOLUTE permalinks from sync time (8/21)
A box cut from live carries the OTHER box's host in every pre-cut mirror
row's stored URL — measured: 46/60 loothprint cards on dev2's hub linked
live, silently shipping a signed-in member to a site where they are a
stranger (read as "the edit button vanished" + "Sign in" header). Fix
posture: same-site links go HOST-RELATIVE at render time
(`lg_bb_self_relative_url()`, bb-mirror/config.php) — reload-proof; never
"fix the data", a fresh cut reintroduces it. Foreign hosts pass untouched.
If a surface shows stored URLs, check its emitter uses the helper.

## ⚠️ #179 wears the `page` label and is NOT a lanes-page issue either (8/21)

The second one, after #171. **#179 is the Loothprint bundle** — one Edit door in
the article page's floating dock, one pill family at every width, and a member
paywall toggle on the compose form. Nothing in it touches `/lanes/`,
`tools/lanes-page.py`, `lanes.json` or the timer.

Recorded here rather than silently relabelled, because the domain rule says a
domain-labelled issue updates its domain file in the same commit, so this line IS
that update — and flagged to Ian for a ruling on the label. Its knowledge lives
where the next person will look for it: the standalone renderer, the compose
form, gate 69 and gate 35b. Two `page`-labelled issues in two days have belonged
to other domains, which is itself worth someone's attention.

### The one thing from #179 a lanes-page reader might want

`tools/preview/lane-preview.sh` really does give a BRANCH a clickable URL, and
#179 used it for `archive-poc/standalone/render.php`:
`platform/nginx/lane-preview-179-loothprint-bundle.conf`. That matters here
because it is the general answer to "the serve only carries merged code, so how
was this verified before merge" — the same question the deploy-gap strip on the
lanes page exists to surface. Gate 69 grew a `--path` flag so it can measure the
preview instead of main.

### Reported, NOT fixed by #179 — the dock already covers body text at 641–700px

Measured 2026-08-21 on main, signed in, on an authored loothprint, using Range
rects over real text nodes (not element boxes, which carry padding):

    width   leftmost body text x   dock right edge   clearance
    641     41.6                   82                −40.4   ← overlapping today
    700     44.0                   82                −38.0   ← overlapping today
    900     98.5                   82                +16.5
    1100    118.5                  82                +36.5
    1280    176.5                  82                +94.5
    1500    286.5                  82                +204.5

So GH #53 / HK-027 — "the floating dock must not sit on the article" — **is not
actually fixed at the bottom of its own media query**. #179 did not cause it and
did not fix it (keeper's posture: report, do not fix); #179's dock is *narrower*
than before (64 → 54 at 900), so the overlap is unchanged at 641–700 and better
everywhere above. Gate 69 asserts clearance at 900 and 1280 and deliberately
does **not** assert 641–700, because a gate that goes red for somebody else's
open defect blocks every lane.

Fixing it means moving the article's left padding or the dock's x at those
widths — a layout change that deserves Ian's eyes on its own.

---

## ⚠️ #185 wears the `page` label and is NOT a lanes-page issue either (8/21)

**The third in three days**, after #171 (Patreon/join dark mode → MEMBERSHIP.md)
and #179 (the Loothprint bundle). #185 is the **compose form's write-up editor**:
nothing in it touches `/lanes/`, `tools/lanes-page.py`, `lanes.json` or the timer.
Recorded here rather than silently relabelled, because the domain rule says a
domain-labelled issue updates its domain file in the same commit — so this line
IS that update. **Three `page`-labelled issues in three days have belonged to
other domains; the label needs Ian's ruling, not another footnote.**

### What #185 actually was, and the one thing worth carrying forward

Ian screenshotted the form showing a grey bar reading *"Click to initialize
TinyMCE"* above his write-up rendered as literal `<p>test</p>`. **Two fixes had
already been attempted and neither reached the served bytes.**

The cause was **our own file**: `lg_fc_relabel()`'s `_post_content` block in
`platform/mu-plugins/lg-frontend-compose.php` sets `$field['delay'] = 1`
explicitly. ACF's own default is `0` and the pseudo-field registration sets none,
so that assignment was the only thing that ever turned it on.

**THE TRANSFERABLE LESSON — how to find this class of thing in one pass.** Both
earlier attempts adjusted filters *blind* and were reasoned about rather than
measured. What settled it in minutes was **bisecting the filter chain by
priority**: register a probe at a ladder of priorities on the same hook and print
the value at each rung.

    prio 19 → delay=0  label="Content"
    prio 21 → delay=1  label="Tell people about it"

The only callback between those rungs was ours. When a value "keeps coming back",
do not hunt for who restores it — **bracket it**, and the bracket names the
culprit even when the culprit is the fix you already wrote. It also answered the
question the issue opened with (*does `acf/prepare_field` fire at all for the
pseudo `_post_content` field?*) as a by-product: **it does.**

Both dead attempts were deleted — lane 179's `delay = 0` at the top of
`lg_fc_relabel` (overwritten ~40 lines below, in its own function) and keeper's
`lg_fc_no_delay` on `acf/prepare_field/type=wysiwyg` at 99 (the type-scoped
variation is dispatched from `_acf_apply_hook_variations` at **generic priority
10**, so it fired *before* `lg_fc_relabel` at 20). ⚠️ **A type-scoped ACF filter
is not "later" than a generic one** — it runs inside the generic hook's priority
10 slot. That is a general fact about ACF, not a fact about this form.

### Two traps this lane paid for that the next lane on ANY surface will meet

1. **A flag that lives in a gitignored `.local.php` makes a branch render
   NOTHING.** `lg_fc_enabled()` fail-closes when its config is unreadable, and the
   `enabled` switch is in `platform/config/frontend-compose.local.php`, which
   exists **only in the serving checkout**. Rendering a worktree copy therefore
   produced a 0-byte page in which "0 placeholders" was perfectly true and
   perfectly meaningless. Only a **liveness assertion** ("the form arrived")
   caught it. Arm the flag (`LG_FC_PREVIEW=1`, and remember `sudo` strips the env
   — use `sudo -u looth-dev env VAR=1 wp …`).
2. **You CAN render a branch's mu-plugin without touching the serve.** WP-CLI's
   `--require=<file>` runs before WordPress boots, so a file that does
   `define('WPMU_PLUGIN_DIR', '/tmp/…')` redirects the whole mu-plugin set. Mirror
   the serve's symlinks into that dir, swap the ONE file for the branch's, and the
   identical render path runs the branch's code — `ReflectionFunction` proves which
   file was loaded. This is the answer to "the serve only carries merged code" for
   anything that renders server-side, and it is non-mutating: nothing on the serve
   changes. It does **not** substitute for a browser: `user_can_richedit()` is
   false under curl and wp-cli, so ACF renders `html-active` where a real browser
   gets `tmce-active` — assert something that does not depend on it.
