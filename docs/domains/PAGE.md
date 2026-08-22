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

---

## ⚠️ #186 wears the `page` label and is NOT a lanes-page issue — the FOURTH in four days

After #171 (Patreon/join dark mode → MEMBERSHIP.md), #179 (the Loothprint bundle)
and #185 (the compose write-up editor). **#186 is compose uploads** — limits, the
publish-time cleanup, and the write-up becoming required. Nothing in it touches
`/lanes/`, `tools/lanes-page.py`, `lanes.json` or the timer. Recorded here rather
than silently relabelled, because the domain rule says a domain-labelled issue
updates its domain file in the same commit — so this line IS that update.

**Four in four days is no longer a footnote.** Three separate lanes have now each
spent a paragraph explaining why the `page` label did not mean what it says. The
label needs Ian's ruling, not a fifth footnote.

### The one thing from #186 a lanes-page reader might actually want

**A gate can load a BRANCH's mu-plugin without touching the serving checkout**,
and gate 88 does it: mirror `/var/www/dev/wp-content/mu-plugins` into a per-run
directory with the one file swapped, then `wp --require=<file>` a bootstrap that
`define()`s `WPMU_PLUGIN_DIR` at the mirror — core only sets that constant
`if ( ! defined(...) )`, so the bootstrap wins. Real WordPress, real DB, branch
code, nothing on the serve modified. It is the general answer to "the serve only
carries merged code" for anything that runs server-side, and it is the same
question the deploy-gap strip on the lanes page exists to surface.

⚠️ **And it hands you both flag states for free.** `lg_fc_enabled()` resolves its
config relative to the mu-plugin FILE, and the `enabled => true` override lives in
a **gitignored `.local.php` that exists only in the serving checkout** — so a
mirror pointed at a worktree reads the TRACKED default (false). That is the #185
trap turned into an asset: the OFF state is what a branch renders by default, and
`LG_FC_PREVIEW=1` (through `env`, because **sudo strips the environment**) arms
the ON state. A gate that runs the same build twice this way reads the flag
instead of hardcoding a state.

### Reported by #186, not fixed — three findings that outlive the issue

1. **`member_cookies()` in `loothprint-paywall-gate.py` does not mint a member.**
   It mints a session for `qa-disposable`, which is `administrator` +
   `bbp_keymaster` + `looth1`. Any gate that copies it and calls the result "as a
   real member" is measuring the ADMIN path. On #186's own feature the difference
   was 5MB versus 5GB. Real member roles on this box are `looth1`–`looth4`,
   `bbp_participant` and `subscriber`.
2. **`tuxedo-big-file-uploads` is active and replaces the uploader**, chunking
   around PHP's `upload_max_filesize` entirely (which is how a 128MB ZIP exists on
   a box whose FPM caps at 64M). Its `by_role` table lists none of the `looth*`
   roles, so members fall through to its `all` bucket: **5,242,880,000 bytes**.
   Any reasoning about upload sizes that starts from `php.ini` is wrong here.

   ⚠️ **AND THAT IS AN AVAILABILITY RISK, NOT JUST A WRONG NUMBER.**
   `wp-content/uploads` is a **symlink to `/mnt/loothgroup-uploads-dev`**, an
   rclone FUSE mount of Cloudflare R2 — so member files never land on this box.
   But the chunker's **spool does**: `wp-content/bfu-temp` is on the root
   filesystem, measured 2026-08-21 at **29G, 84% used, 4.6G free**. A 5GB allowed
   upload against 4.6G free means **one member uploading one large file can fill
   the box's root disk**, through wp-admin or any other form the chunker serves.
   #186 caps compose uploads at the first chunk that crosses the limit, but that
   guard is deliberately scoped to its own post types — everything else on the box
   still spools against 4.6G. Wants a `bfu_temp_dir` relocation or a sane
   `by_role` entry; nobody has taken it.

   Two smaller ones from the same read: BFU's `.part` path is
   `sha1($fileName)` with **no user or session in it**, so two members uploading
   files with the same name share one spool file and interleave — which is why a
   size guard must never `unlink()` the part on refusal. And BFU only reaps
   `.part` files older than 24 hours, and only on chunk 0.
3. **ACF's `max_size` / `mime_types` are inert on any form this chunker serves**,
   because the chunker calls `media_handle_upload()` with
   `overrides['action'] = 'wp_handle_sideload'` and core dispatches the prefilter
   dynamically as `"{$action}_prefilter"`, while ACF only listens on
   `wp_handle_upload_prefilter`. Proof that needs no reading: the print-file field
   declares `mime_types = zip` and holds **48 `.stl` files**.

---

## ⚠️ #189 wears the `page` label and is NOT a lanes-page issue — the FIFTH in five days

After #171 (Patreon/join dark mode → MEMBERSHIP.md), #179 (the Loothprint
bundle), #185 (the compose write-up editor) and #186 (compose uploads). **#189 is
the compose form's own uploader** — drop-zone, thumbnails, 1-in-1-out, and no
media modal. Nothing in it touches `/lanes/`, `tools/lanes-page.py`, `lanes.json`
or the timer. Recorded here rather than silently relabelled, because the domain
rule says a domain-labelled issue updates its domain file in the same commit — so
this line IS that update.

**Five in five days. Four separate lanes have now each spent a paragraph
explaining why the `page` label did not mean what it says.** It needs Ian's
ruling, not a sixth footnote.

### The two things a lanes-page reader might actually want

**1. A branch can be served to a real browser, not just to wp-cli.** Gate 88
already loaded a branch's mu-plugin under wp-cli by mirroring the mu-plugin dir
with one file swapped and defining `WPMU_PLUGIN_DIR` first (core sets it with
`if ( ! defined(...) )`). #189 made that reusable **over HTTP**:
`tools/preview/mu-mirror.sh` builds the mirror, `tools/preview/mu-mirror-boot.php`
is an nginx `SCRIPT_FILENAME` shim that defines the constant and then requires the
serve's own `index.php`. Real WordPress, real DB, real theme, one branch file,
nothing on the serve modified.

⚠️ The existing `lane-preview-frontend-compose.conf` does **not** do this — it
points at `/var/www/dev/index.php`, so it arms the flag and renders **main**. A
lane copying it for a mu-plugin change would verify main and call it verified.
That is the same question the deploy-gap strip on the lanes page exists to
surface.

**Repointing the one symlink inside that mirror is the cheapest main-vs-branch
attribution tool on this box** — two fetches, byte counts, done. #189 used it to
prove a TinyMCE regression was its own and a hero-picker defect was main's.

**2. An author `display` beats the UA's `[hidden]{display:none}` outright**,
whatever the specificity. It bit three controls in one lane, one of them
pre-existing on main. Any surface that hides a control by setting `hidden` from
script needs `[hidden]{display:none}` in its own stylesheet, or the attribute
does nothing.

### Two more from #189 that reach every surface on this box

**3. Setting `data-lguser-theme` is NOT setting the theme.** Dark is applied by
`app-settings.js` **re-pointing the `--lg-*` tokens as inline style on `<html>`**,
and only then stamping the attribute. A harness that stamps the attribute alone
photographs a **light page wearing a dark attribute** — every
`var(--lg-…, <light fallback>)` stays light — and the result reads as a defect in
whatever is drawn on top. Read the palette out of `webroot/app-settings.js` and
apply it inline; never write `lg-set-theme` to localStorage, which persists on
the **shared** chrome profile and takes every other lane's browser dark. Assert
the delta (a card colour that changes) before believing any dark shot.

⚠️ And the corollary: **a colour token with no dark value silently stays light.**
Auditing the compose stylesheet that way found three — `--lg-card` (used by the
type toggle since before #189), `--lg-error`, `--lg-ink-soft`. Gate 88 §K now
checks that class automatically for that file; **any other stylesheet on this box
has the same exposure and nothing checking it.**

**4. `pgrep -f '<your command>'` matches the harness WRAPPER.** The bracket trick
(`foo[-]bar`) stops `pgrep` matching itself; it does **not** stop it matching the
shell wrapper that carries your command text verbatim. #189 watched the wrapper,
it exited at once, and a 35-minute run was reported "finished" on its first step.
Capture `$!`, or pick the interpreter line out of `ps -eo pid,cmd`.

### Reported by #189, not fixed

- **Gate 88 §E was RED on main** and would have blocked every lane: it required
  zero stamped attachments outside its own fixtures, which stopped being true the
  moment a member used the compose form (measured: 11 stamped files on one
  member's live auto-draft). Restated in #189 to assert the property that
  actually matters — a stamp agrees with its `post_parent`, and nothing that
  predates the feature carries one. **A gate whose green depends on nobody using
  the feature is a gate that goes red on success.**
- No cancel on an upload in flight; the 5GB/4.6G spool risk from #186 is still
  open; the print-file field's `mime_types = zip` is still unenforced.

---

## ⚠️ #187 wears the `page` label and is NOT a lanes-page issue — the SIXTH in six days

After #171 (Patreon/join dark mode → MEMBERSHIP.md), #179 (the Loothprint
bundle), #185 (the compose write-up editor), #186 (compose uploads) and #189 (the
form's own uploader). **#187 is image delivery on the article pages** — the
resizer, `srcset` and dimensions on every managed-CPT single. Nothing in it
touches `/lanes/`, `tools/lanes-page.py`, `lanes.json` or the timer. Recorded
here rather than silently relabelled, because the domain rule says a
domain-labelled issue updates its domain file in the same commit — so this line
IS that update.

**Six in six days, five different lanes.** The label has now cost more paragraphs
explaining what it does not mean than it has ever saved. It needs Ian's ruling.

### ⚠️ THE ONE THING EVERY LANE ON THIS BOX SHOULD READ: `transferSize` IS 0 CROSS-ORIGIN

The craft gate computes `PAGE-IMG-BUDGET`, `PAGE-BUDGET` and every KB it prints
from `performance.getEntriesByType('resource')` → `transferSize`. **That field is
0 for a cross-origin response with no `Timing-Allow-Origin` header.**

MEASURED 2026-08-21 on `/post-imgcap/68-jazz-bass-truss-rod-reclamation/`:
**28 of its 35 images are stored with the LIVE host** in their URL, so the gate
reported the page's images at **222KB when the true weight was 5,730KB** — a
26× understatement, in the direction that looks healthy.

Two consequences, both live today:

- **The gate's weight numbers are a floor, not a measurement, on any page that
  pulls from another host.** 436 posts on this box store media on
  `loothgroup.com` (1,217 media rows; 265 posts / 697 rows are on dev2's own
  host, 2 host-relative). None of that weight is visible to the gate.
- **Fixing such a page reads as a regression.** #187 cut that article's images
  49% and the gate's own number went *up* 63%, because the images only started
  being counted once they became same-origin. A lane that trusts the gate here
  will revert a genuine improvement.

To measure honestly: read each `<img>`'s `currentSrc` in the browser at the
viewport under test, then fetch each URL and sum. `tools/gates/craft-gate.py`
was deliberately **not** changed to do this — it would redden main for
pre-existing weight on surfaces nobody has been asked to fix.

### The image law, and where it is now enforced

`CLAUDE.md`: *"Images: always the resizer (`/img.php?w=`) + `srcset` +
width/height — never raw uploads, never one-size"*. Until #187 the CPT singles —
the pages members actually read — were the one family the craft gate had never
looked at, so the law had never been evaluated there. `/loothprint/fret-sander-v2/`
shipped **11 `<img>` tags, 0 through the resizer, 17 raw uploads URLs and 1
`srcset`**, with a 2000px hero in a 780px slot, downloaded twice (the embed
poster reuses the same file).

Now in `craft-gate.py` PAGES: `loothprint` (anon + member), `article`, `video`.
**Three, not nine.** All nine managed CPT singles are served by the same
`archive-poc/standalone/render.php` and the same block set
(`platform/nginx/strangler-archive-poc.conf`), so nine entries would cost every
lane ~9s of shared gate time to re-prove one code path. The emitters were fixed
for **every** variant including the sponsor hero that no gate page exercises —
uncovered-and-fixed, not uncovered-and-broken.

### Delivery lives in ONE class now: `archive-poc/standalone/engine/src/Img.php`

`Img::src()` / `srcset()` / `dims()`, mirroring the trio already proven in
`bb-mirror/web/forums/_feed.php`. Two rules in it are load-bearing:

1. **Dimensions come from the blob's `sizes` metadata, never the filesystem.**
   The feed's `lg_cover_dims()` calls `getimagesize()` on the R2 mount and that
   was the hub's #1 server cost. The standalone renderer does not need to: the
   materializer already baked width and height for every variant. Where a bare
   URL carries no metadata (the related-post cards), the **variant filename**
   `-768x576.webp` supplies them.
2. **It resizes from the widest uncropped VARIANT, not the original** — see the
   trap below.

Nothing is guessed: a crop-only `sizes` map (`thumbnail` is 150x150 on a 16:9
photo) yields **no** dimensions rather than a square hero, and no derivable
source width yields **no** `srcset` rather than candidates whose widths we
invented.

**Five emitters, not four.** `gallery` (71 blocks in use) was handing each tile
the full-size original into a ~245px slot — `/loothprint/neck-side-crack-jig/`
measured 1,093.6 → 299.4 KB at phone width, −73%. `callout` is the most-used
block on the box (890) and needed **nothing**: zero callout items carry an
`image_url` anywhere in the corpus, so its `<img>` branch is dead in practice.
Ask the corpus which blocks are actually used before deciding a sweep is
complete — and ask it with the RIGHT PROP NAME, since a query for `image`
rather than `image_url` returns 0 for the wrong reason and reads identical.

### ⚠️ Trap: rewriting a stored URL host-relative can 404 a photo that works today

PAGE.md's own ruling (8/21) is that same-site URLs go host-relative at render
time — `loothgroup.com`, `www.` and `dev2.` are all "our hosts"
(`lg_bb_self_relative_url`). Applying it to **images** is not free, because a
missing link is a wrong destination and a missing image is a hole in the page.

MEASURED: of **1,196** distinct media files stored with the live host, **11 do
not exist on dev2 in any form** (dev2 holds a differently-named import,
`i_bridge-wing-flattener-hero-*.jpg`, not the `.webp` the blob names). Those 11
sit on **4 posts** — `bridge-wing-flattener` (8 images), plus the heroes of
`a-cowboys-dream-gretsch-roundup-pt-1`,
`loothing-for-dollars-moses-mckinley-and-guitar-czar-repair` and
`somogyi-neck-reset-with-jonathan-scott`. They render today only because the page
fetches them from production; host-relative they 404 **on dev2 only** (on live
the files exist, so live is unaffected).

Shipped anyway, deliberately, and the reasoning is the part worth keeping: the
alternative — leaving the live host on — produces a **FALSE GREEN**. The craft
gate's collector filters images to same-origin
(`i.src.startsWith(location.origin)`), so those 436 posts' raw full-size images
are invisible to it. Keeping the host would have left the real defect unfixed on
the majority of articles *and* made the gate report success. A visible hole on 4
dev2 pages beats an invisible defect on 436.

**The remedy is data, not code**: copy those 11 files into the dev uploads
bucket, or re-materialize those 4 posts on dev2.

**Related, and NOT caused by #187:** `/loothprint/fret-sander-v2/`'s
related-post card for `bridge-wing-flattener` is **already broken on main** — it
stores a `dev2.loothgroup.com` URL for a file dev2 does not have, and 404s
today. Same data gap, different field.

### Two more things #187 proved, cheaply

- **`tools/preview/lane-preview.sh` gives the standalone renderer a real browser
  on a BRANCH**, and it is the only way to measure this kind of change: the
  `/loothprint/` route serves `/srv/archive-poc`, a symlink into the serving
  checkout, so "weighed the page on dev2" otherwise means "weighed main".
  Verified both directions in the same minute — main's route emitted 0 `img.php`
  while the preview emitted 27.
- **Gate 69's `--path` is a TEMPLATE (`/loothprint/{slug}/`), not a prefix.**
  Passing a bare prefix makes it request a slugless URL and report *"the sticky
  dock renders no .lg-standalone-dock"* four times — which reads exactly like a
  lane having deleted the dock. And once pointed correctly it fails a *second*
  way, on the Edit href, because `platform/config/frontend-compose.local.php` is
  gitignored and exists **only in the serving checkout** (the #185/#186 trap): a
  worktree preview reads the tracked default and the Edit pill falls back to
  `?lg_edit=1`. Copy the `.local.php` into the worktree and it is green (49
  checks).

### Reported by #187, not fixed

- **The craft gate cannot see a broken image.** `check()` skips any `<img>` with
  `naturalWidth == 0`, so a 404 photo is silently not a violation. That is how
  the related-card hole above has sat on main unnoticed.
- **`PAGE-IMG-BUDGET` also misses never-loaded lazy images**, so it is a
  transferred-bytes assertion, not a page-weight one. The 35-image article is
  2,901KB of real images after the fix and the gate scores it at 293KB.
- Author **avatars** and the shell's own header/footer logos stay raw uploads
  (10.5KB and 39KB). The footer logo is hardcoded to `https://loothgroup.com`
  and the header avatars are served over **`http://`** — mixed content on an
  https page. Left alone: they are site chrome and one of them stores a host
  whose file dev2 lacks, so rewriting it would break a working image to save
  nothing.

---

## ⚠️ #191 wears the `page` label and is NOT a lanes-page issue — the SEVENTH in seven days

After #171 (Patreon/join dark mode → MEMBERSHIP.md), #179 (the Loothprint
bundle), #185 (the compose write-up editor), #186 (compose uploads), #189 (the
form's own uploader) and #187 (article image delivery). **#191 is the compose
form's licence control** — a mislabelled Creative Commons option, and an ⓘ that
shows the real terms. Nothing in it touches `/lanes/`, `tools/lanes-page.py`,
`lanes.json` or the timer. Recorded here rather than silently relabelled, because
the domain rule says a domain-labelled issue updates its domain file in the same
commit — so this line IS that update.

**Seven in seven days, five different lanes.** Every one of them has now spent a
paragraph explaining what the label does not mean. It needs Ian's ruling, and an
eighth footnote is not it.

### What #191 was

One of four licence options read *"BY ND NC (Credit given to creator, No
Derivatives, **Adaptations shared with same terms**)"* — and those two clauses
contradict each other, the second belonging to Share-Alike. Members were choosing
legal terms off that sentence; three published loothprints stored it, on both
boxes. The letters were always right (BY-NC-ND is real); the English beside them
was not.

### ⚠️ THE ONE THING EVERY LANE SHOULD TAKE: ASK THE DATABASE WHICH KEYS HOLD IT

The migration was written for **two** stores — the postmeta and the licence
sentence baked into `_lg_layout_v2` (only 4 of 172 loothprints are synthesized at
render; the rest have it baked). It ran, it verified, and its sweep — which asked
about *the two keys it already knew about* — reported **zero left**.

There was a **third**. `_lg_layout_v2_rendered_html`, WpRenderer's anon render
cache, still held the contradictory sentence on one of the three. Keeper found it.

Two things make this general:

- **`invalidate_render_cache()` deletes only the TIMESTAMP** (`Plugin.php`, one
  line: `delete_post_meta($post_id, LG_LAYOUT_V2_RENDERED_AT_META)`). The cached
  HTML body stays in the row for ever. The cache was correctly *not being served*
  — `cached_html()` returns null once the stamp is gone — but the stale bytes
  survive every invalidation this platform performs. **133 posts carry one of
  these rows.** Any audit that asks "is this string still stored anywhere" and
  reads only the source-of-truth keys will be wrong on all 133.
- **The cure is a one-line change of question.** Not `WHERE meta_key = …` for
  keys you thought of, but `SELECT meta_key, COUNT(*) … WHERE meta_value LIKE …
  GROUP BY meta_key`. That found the third store by itself, and also surfaced 17
  rows in `_elementor_data` (old templates and revisions — out of scope, recorded
  so nobody re-finds them and panics). `tools/migrations/191-licence-label.php`
  now sweeps that way and labels each key as in-scope or not.
- And a derived-data rule: the cache is **deleted, not patched**. A hand-edited
  cache agrees with nothing and can silently disagree with its source later.

### ⚠️ FIXING THE WORDING BROKE A DIFFERENT FILE, SILENTLY

There are **two licence tables on this box**, and they cannot be merged:

| | |
|---|---|
| `lg_fc_licences()` in `platform/mu-plugins/lg-frontend-compose.php` | what the compose form **offers**, and therefore what gets **stored** |
| `Licenses::ACF_CHOICES` in `lg-layout-v2/src/Licenses.php` | what the layout engine **recognises** when it reads that stored value |

The compose form is an mu-plugin and must not depend on a regular plugin's class
being loaded, so the duplication is deliberate.

**Correcting the fourth choice's wording broke the second one.**
`Licenses::from_exact_prose()` matches the ACF choice string **exactly** — on
purpose, because a loose match there would rewrite an author's prose — so every
post saved after the fix stopped being recognised, and
`upgrade_license_callouts()` would simply walk past them and never render the
licence block. **Nothing errors.** Measured on main:
`from_exact_prose('BY NC ND (…Non-Commercial only, No Derivatives)')` → `''`.

Both spellings now live in `ACF_CHOICES` and both are load-bearing: without the
new one the migrated posts break; without the old one **live** breaks, because
its values are unchanged until Ian runs the migration, and so does any box cut
from live.

Two general points:

- **It was found by grepping the repo for the old string, which is not a
  method.** Gate 92 §F now asserts the two tables agree — the honest answer to
  duplication you cannot remove is to gate the agreement rather than to remember
  it.
- ⚠️ **§F has to load the BRANCH's copy explicitly.** Under WordPress the
  autoloader resolves `Licenses` out of the **serving checkout** — `lg-layout-v2`
  is symlinked there — which is main, and main is the broken state being tested
  for. So the probe runs as **plain PHP with an absolute `require`** and echoes
  back the file it actually loaded, and the gate asserts that path. Any gate
  comparing a plugin class against a branch has this problem.

### Minting a gate number from MAIN is blind to a concurrent lane

`run-all.sh` on main stopped at 90, so 91 looked free. **It was not** — lane
192-dash-health already had `GATE 91` on its branch, unmerged and therefore
invisible to main. Diffing *every live worktree* against main is what showed it:

    for w in ~/worktrees/*/; do git -C "$w" diff --name-only main...HEAD \
        | grep -c tools/gates/run-all.sh; done

**Second near-collision in two days.** #191 took **92**, from keeper. The same
sweep is also the cheap way to answer "does my change overlap another lane's
files" — it showed no other live lane touching `lg-frontend-compose.php`.

### Three smaller ones, each paid for

1. **`acf/get_field_label` is the ONLY seam for markup in an ACF field label.**
   `acf_get_field_label()` runs `esc_html()` over `$field['label']` *before* any
   filter sees it, so appending markup in `acf/prepare_field` renders as visible
   text. What the filter returns is then passed through `acf_esc_html()` —
   `wp_kses` with `$allowedposttags` — which keeps `<button>` with its `type`,
   `id`, `class` and `aria-*` intact (verified against the exact markup, not read
   off the allow-list). And for a radio, ACF renders the label with **no `for`
   attribute**, so a button inside it activates nothing else.
2. **An ACF radio's choice KEY is its stored value**, so correcting a label
   orphans every row holding the old one — and whether that is a blanked value or
   a locked-out member depends on `required`. Measured: this field is
   `required => 1`, so ACF **refuses the save** rather than storing the emptiness,
   and the member cannot edit their own post without changing its licence.
   Forwarding the stored value at render (`lg_fc_licence_forward()`) is what
   closes it, and it stays after the migration because live keeps the old values
   until Ian runs the command and a fresh cut of dev2 reintroduces them.
3. **A "no external host" assertion must be about REQUESTS, not words.** The
   gate's first version looked for the string `creativecommons.org` and went
   **red on a correct build** — the CC legal code names that host in its own
   prose. It now asserts that nothing in the modal *loads* or *links*, and
   separately that the prose IS there, which is the pair that tells "held
   offline" apart from "not there at all". Its region is bounded at `</dialog>`:
   run it to the end of the document and it measures the page footer's scripts.

### And one about gates that abort

`compose-licence-gate.py` raised `CannotRun` at its browser leg and printed one
line, **throwing away fifteen failures it had already recorded** in the curl leg.
That made two halves of the same run look like they disagreed, and a paragraph
was written about a disagreement that never happened. A gate that aborts must
print what it already measured.

### Reported by #191, not fixed

- **wp-admin still offers the wrong label.** The fix is a code override at
  `acf/prepare_field`, deliberately, so it survives a wp-admin edit and reaches
  live by pull — but ACF field `field_6564e26df56ba` in the DB is untouched, so
  anyone editing a loothprint in wp-admin still sees the contradictory option.
- **Live's three posts stay wrong** until Ian runs the handed command. Live is in
  the same state dev2 was, and sitewide it is exactly 3 and 3, so the id list is
  complete there too.
- ACF's required-field refusal names the **raw** stored label ("Creative Commons
  Use License (leave default if unsure) value is required"), not the relabelled
  "Licence", because validation does not run `acf/prepare_field`. Near-unreachable
  now that the forward map exists, but it is the wrong words if it ever shows.
