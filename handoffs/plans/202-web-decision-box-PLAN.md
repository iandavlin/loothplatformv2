# PLAN — #202 · the decision box, on the web

Lane `202-web-decision-box`. Branch cut at current main, **0/0**, tree clean.
Nothing written yet outside this plan file.

## Ian's ask, verbatim (8/22 evening, the rescope)

> "I don't want the todo proposal. I want a button that opens up the decision
> box that we use here and have it communicate with you. Can we build that ?"

The two drawn shapes from the design seat are dead. This is one thing: **the
decision box he answers in chat, rendered on the lanes page, answering back to
keeper.**

---

## 1. What I measured before designing anything

Every number below was read off this box in the last twenty minutes, not
remembered.

### The write path — the constraint the charter told me to solve

| thing | measured | consequence |
|---|---|---|
| `/srv/lg-shared-state` | `drwxr-xr-x+ looth-dev:looth-dev`, **empty**, ACL grants `looth-ro` r-x only | keeper (`ubuntu`) **cannot write it**. Confirmed, not assumed. |
| `/home/ubuntu` | `drwxr-x--x` | the web user can **traverse** it but not list or write it |
| a `0755` dir + `0644` files under it | `sudo -u looth-dev cat` → **succeeded**; `sudo -u looth-dev touch` → **Permission denied** | a store there is **readable by the endpoint and writable only by keeper**, which is exactly the asymmetry this feature needs |
| `/etc/looth/env` | `sudo -u looth-dev head` → **succeeded** | the endpoints can mint and verify HMAC nonces server-side, as `lanes-poke.php` already does |
| board DB `/var/lib/devmsg/messages.db` | `0660 root:devmsg`; `getent group devmsg` → **`ubuntu` alone**; `id looth-dev` → `looth-dev,www-data,loothdevs` | the web user **physically cannot write the board**. It must queue; something running as ubuntu must deliver. |

**So the store is `~/.lg-decisions/`, `0755 ubuntu:ubuntu`, files `0644`.**
Keeper writes it directly. The renderer (`lanes-page.service`, `User=ubuntu`,
`ExecStart=…/keeper-repo/tools/lanes-page.py` — verified from the installed
unit) reads it. The endpoints read it. Nothing but ubuntu ever writes it.
**`/srv/lg-shared-state` is not touched, not loosened, and not used** — the
tester-unlock hash's store stays exactly as it is.

### ⚠️ A FINDING IN THE MACHINERY I WAS TOLD TO REUSE — it is not installed

`lanes-poke.path` and `lanes-poke.service` **do not exist on this box.**

    systemctl list-unit-files 'lanes-*'
    lanes-refresh.path   enabled
    lanes-page.service   static
    lanes-page.timer     enabled          ← lanes-poke.* absent entirely
    ls /etc/systemd/system/lanes-poke.*   → No such file or directory

The rest of #156 **is** deployed: `/var/www/dev/lanes-poke.php` is symlinked,
the spool `~/.lanes-poke-request` exists at `0666`, the stamps dir exists at
`0777`. So today a tap on **Poke keeper** validates its nonce, appends a line to
a spool **nothing drains**, and the browser prints *"keeper told ✓"*.

It has not lost a poke yet — the spool is **0 bytes**, mtime `Aug 20 12:01`
(the installer's own `: >`), the stamps dir is empty, and `~/.keeper-pokes` does
not exist — so the button has apparently never been pressed. It would be lost
today. The deploy step exists in the repo (`sudo bash
tools/lanes-poke-install.sh`) and only its systemd half was never run.

This is load-bearing for me because **my delivery reuses that path unit.**
Boarded to keeper as its own finding rather than fixed under it — it is keeper's
tooling, not mine.

✅ **FIXED BY KEEPER, 8/22 19:51, and verified by me rather than taken on
trust:** `lanes-poke.path` is now `active` + `enabled`, `lanes-poke.service`
`linked`, both symlinked out of the serving checkout. Keeper's ruling: *"that
gate leg is the difference between this button and the lie it was an hour ago."*
So the liveness check in §5 stays, and it now has something true to protect.

### The rest of the ground truth

- The page is healthy right now: `200`, 44,701 bytes over loopback,
  **11 todo cards, 7 accordions, 7 poke buttons**, generated 19:45.
- nginx needs **no change**: `if ($loothdev_is_authorized = 0) { return 403; }`
  is server-level (line 114 of the running conf) so every URI is gated, and the
  bottom `location ~ \.php$` (line 455) hands any docroot `.php` to the
  `looth-dev` FPM pool. A symlink is the whole deploy.
- `msg`'s sender is `getpass.getuser()`, so a board line from the worker is
  always literally from `ubuntu`. **`ian-via-page` can only ever be a
  convention in the message text**, exactly as `ian-via-board` was on the wip
  board. I will not pretend otherwise.
- `stall-watchdog.sh` wakes keeper **by exiting** with an `ALERT` line; the
  `~/.keeper-pokes` + `~/.keeper-poke-mark` watermark pattern is already there
  to copy.
- There is no existing decision/question store anywhere in the repo.
  `.lane-state/QUESTION` is a **lane's free-text sentence** and already renders
  as todo family 1 — a different thing, and I am not folding it in.

### The gate number — 98 is spoken for

`docs/CRAFT-STANDARD.md` on main says *next free: 98*. The worktree sweep says
otherwise: lane **201-secret-status holds `GATE 98`** on an unmerged branch and
has already bumped that line to 99 on its own copy. **Main tells you what has
landed, never what is spoken for** — the fifth time in six days.

201 also holds **`docs/CRAFT-STANDARD.md` and `tools/gates/run-all.sh`**, and
`emoji-picker-build` holds `run-all.sh` too. Minting a number means editing two
files two other live lanes are holding.

**Recommendation: extend GATE 77 with a new leg, mint nothing, touch neither
file.** That is what #172 did for the same reason, what PAGE.md recommends for
lanes-page work, and what the parked #178 plan proposed for this very endpoint.

✅ **RULED BY KEEPER, 8/22 19:5x — EXTEND-77.** No number is minted; the
next-free counter stays 201's to bump at its merge. `docs/CRAFT-STANDARD.md` and
`tools/gates/run-all.sh` are therefore **not** in my file list.

---

## 2. The shape

Four pieces. Nothing renders that keeper did not pose; nothing is answered
twice.

### (a) The store — `~/.lg-decisions/`, one file per question

    ~/.lg-decisions/<id>.json          0644, written atomically (temp + rename)
    ~/.lg-decisions/<id>.claim         the first-answer-wins token (O_EXCL)

```json
{ "id": "d20260822-7f3a1c",
  "created": 1755890000,
  "asked_by": "keeper",
  "issue": 202,
  "question": "Gate number for the decision box?",
  "options": [
    {"key": "extend", "label": "Extend gate 77",
     "description": "No new number; touches neither run-all.sh nor CRAFT-STANDARD.",
     "recommended": true},
    {"key": "mint",   "label": "Mint 99", "description": "…"}
  ],
  "answered": null }
```

`answered` becomes `{"at":…, "key":"extend", "label":"…", "via":"page"|"chat"}`.
**A question is never deleted and never expires** — a silent disappearance is
the one failure this page must not have (#172's ruling). It leaves the pending
set only by being answered, and the card then shows what was chosen.

**2–4 options, enforced at author time**, because that is the shape of the box
he already answers in chat.

### (b) The CLI — `tools/decisions/lg-decide.py`, keeper's end

    lg-decide ask --question "…" --option key:Label:Description[:rec] …  → prints the id
    lg-decide list                      pending only
    lg-decide show <id>
    lg-decide answer <id> <key> --via chat

`answer` is the **only** mutation and it is atomic: `O_CREAT|O_EXCL` on
`<id>.claim`. The first writer wins; the loser exits non-zero saying who won and
through which channel. The worker calls this same code path, so page and chat
race through one door rather than two that agree by luck.

**Keeper's contract** (recorded as keeper law in PAGE.md in the same commit,
per the domain rule): *every box posed in chat is also written to the store
while unanswered, and answered there the moment Ian answers in chat.*

### (c) Two one-verb servants in the docroot

Modelled line for line on `lanes-approve.php` / `lanes-poke.php`: standalone, no
`wp-load` (trap 7), constants overridable so a gate can drive them in a sandbox,
per-day HMAC nonce derived from the token server-side, token never in the
browser.

| file | verb | can it write? |
|---|---|---|
| `webroot/lanes-decisions.php` | **GET** — return the pending questions, each option carrying its own nonce | no. Reads the store, reads the token, writes nothing. |
| `webroot/lanes-decide.php` | **POST** — queue exactly one answer | only one appended line in the existing `0666` spool |

**Nonce: `HMAC(decide:<id>:<key>:<utc-date>, token)`, minted per option.** A
digest is therefore proof that *keeper posed this option on this question
today* — a browser cannot fabricate an option keeper did not offer, which is the
rescope's explicit constraint. The POST endpoint **also** re-reads the store and
re-checks the id, the key and the unanswered state, because a nonce is a claim
about the past and the store is the present.

⚠️ **The page itself carries no nonce and no question text.** The modal fetches
on open. That means a page cached from yesterday still works (fresh nonces), and
a question answered in chat thirty seconds ago is already gone from the box. A
baked page could not have either property. The renderer bakes only the **count**,
as the button's snapshot line.

### (d) Delivery — the existing spool, path unit and worker

    spool line today:   <ts> <seat>                    ← unchanged, still a poke
    spool line new:     <ts> decide <id> <key>

Reusing `lanes-poke.path` / `lanes-poke.service` / `lanes-poke-worker.sh` costs
**zero new systemd steps**, which is #178's Decision 3 and the right call on a
box whose scar tissue is mostly missing deploy steps. Back-compat is free by
construction: today's worker splits `<ts> <rest>` and validates `rest` against a
name charset that rejects spaces, so an old worker silently ignores a decide
line rather than mis-delivering it.

On a `decide` line the worker, as ubuntu:

1. **claims and marks** the store via `lg-decide answer … --via page`;
2. **posts to the board** — the question, the chosen option's **label**, the
   issue if it has one, and the words *"Ian answered this on the lanes page
   (ian-via-page)"*. No backticks, no dollar signs, ever;
3. **appends to `~/.keeper-decisions`** — a new wake file with its own
   watermark and its **own** `ALERT ian-decision` sentence in
   `stall-watchdog.sh`. Deliberately not folded into `~/.keeper-pokes`, whose
   alert says *"flagged these seats as idle"* — that would be a lie about an
   answer, and this page's oldest law is that two different things must never
   render alike.
4. **If the claim was already taken** it posts *"Ian answered X on the page but
   it had already been answered in chat — no action taken"* rather than
   dropping the line. Nothing vanishes silently, including a lost race.

### (e) On the page

- **A button at accordion depth ZERO**, directly above *Your list* and below the
  risk blocks: `Decisions waiting for you (2)`. Depth zero because a pending
  decision is precisely the class PAGE.md forbids burying — *a collapsed AT RISK
  is a hidden AT RISK* — and below the risk blocks because a decision is a
  request, not a failure, and must not shout over one.
- Tapping opens a `<dialog>` that **fetches the live set** and draws one card per
  question: the question, then its 2–4 options as buttons with their
  descriptions, the recommended one marked. **One click answers one question**;
  that card collapses to *"answered ✓ — keeper has been told"* and the others
  stay.
- **Nothing pending ⇒ the button is absent.** Quiet when healthy.
- ⚠️ **The store or the fetch unreadable ⇒ a LOUD line, never absence**:
  *"Decisions unknown — I could not read the question list; that is not the same
  as none."* This is the inversion of the quiet rule that PAGE.md exists to
  protect, and it is the assertion in this build most worth a red-first.
- `lanes.json` gains a `decisions` block (ids, questions, options, **no
  nonces**) so a machine consumer sees them too.

### The Landed button (#178), folded in

Per the rescope, **it becomes just another question type**: keeper poses a
question bound to an issue whose options are *"Landed as expected"* / *"Not
right"*. No auto-derivation from the `look` family — which structurally
resolves #178's own caveat (*"a Landed tap on a card whose real ask is a
decision must ask, not clear"*), because a posed question can only exist where
keeper decided it is the real ask. #178's plan file stays where it is; its lane
is parked and holds only that file, so there is no collision.

---

## 3. What I am deliberately NOT doing

- **Not touching `/srv/lg-shared-state`**, its perms, or the tester-unlock store.
- **Not closing or labelling GitHub issues.** An answer is a sentence to keeper,
  not a state change on a card. `lanes-approve.php` keeps its monopoly on labels.
- **Not auto-deriving questions** from labels, park reasons or `.lane-state`.
  Every box is posed by keeper. Boxes never fabricate options.
- **Not rebuilding the todo list**, not ranking it, not theming the page, not
  the plain-English deploy line. Those are the dead proposal's; the rescope
  replaced them and I am not smuggling them back in.
- **Not touching another lane's worktree or tmux session.**
- **Not touching `run-all.sh` or `CRAFT-STANDARD.md`** if keeper says extend 77.

---

## 4. Files I expect to touch

Guessed wider rather than narrower, and checked against every live worktree.

| file | what |
|---|---|
| `tools/decisions/lg-decide.py` | **new** — the store + its only mutation |
| `webroot/lanes-decisions.php` | **new** — GET the pending set + nonces |
| `webroot/lanes-decide.php` | **new** — POST one answer to the spool |
| `tools/lanes-page.py` | the button, the dialog, its CSS + JS, the baked count, the unreadable-store line, the `lanes.json` block |
| `tools/lanes-poke-worker.sh` | the `decide` verb, the claim, the board line, the wake file |
| `tools/lanes/stall-watchdog.sh` | `ALERT ian-decision` on `~/.keeper-decisions` |
| `tools/lanes-poke-install.sh` | pre-create the store dir; **install the missing path unit**; the two new symlinks; a `--verify` mode |
| `tools/gates/lanes-page-truth-gate.py` | **extended** — new leg, no number minted (pending keeper) |
| `tools/gates/lanes-page-truth-redfirst.sh` | the red-first mutations for that leg |
| `docs/domains/PAGE.md` | the domain rule — same commit |
| `handoffs/plans/202-web-decision-box-PLAN.md` | this file |
| `handoffs/2026-08-22-202-web-decision-box.md` | **new** — the handoff |
| `footer-mockups/202-decision-box/` | **new** — the shots of the real thing, committed to the branch |

**Collision check, run against all nine live worktrees just now.** Nobody else
holds any file above. The two files I am avoiding — `docs/CRAFT-STANDARD.md`
and `tools/gates/run-all.sh` — are held by `201-secret-status` (both) and
`emoji-picker-build` (the second). That is the whole reason for the extend-77
recommendation.

---

## 5. How I will prove it

Gate 77 grows a leg, **browser-free like the rest of it** so it cannot flake
under load or go vacuously green behind a locked-out session. Fixtures and
injected constants throughout; **the real store, the real spool and the real
board are never touched.**

**Store + CLI** — `ask` refuses 1 option and refuses 5; the file lands `0644` in
a `0755` dir; **two concurrent `answer` calls produce exactly one winner** and
the loser names the winner's channel; an unknown id and an unknown option key
both fail; `list` shows only unanswered.

**The GET endpoint** — an answered question is **absent**; every option carries
a nonce that verifies; **a nonce minted for option A does not verify for option
B, nor for another question's id** (the two cross-checks that make the
anti-fabrication claim real); POST to it is refused; and the token string does
**not** appear anywhere in the response body — asserted positively.

**The POST endpoint** — GET refused · forged nonce refused · cross-option nonce
refused · cross-question nonce refused · unknown id refused · **already-answered
refused** · a missing spool fails **loudly** rather than reporting success. On
success: exactly one line, of exactly the documented shape, and the spool keeps
its `0666` mode.

**The worker** — a decide line marks the store, reaches the board naming the
chosen **label**, and appends to the wake file; **a poke line still delivers as
it did** (the back-compat liveness, without which every absence assertion here
is vacuous); no backticks in either message; an already-claimed decide line
posts the no-action line instead of vanishing.

**The watchdog** — `ALERT ian-decision` fires, says *decision* and not *idle*,
and does not re-alarm once the watermark advances.

**The render** — pending ⇒ the button exists **at `<details>` depth zero**
(walked, not eyeballed, as gate 77 already does for the loud layer) with the
live count · none pending ⇒ absent · **store unreadable ⇒ the loud line, and
never silence** · the rendered HTML contains **no decision nonce and no question
text**, paired with the liveness that the GET does return them.

Every assertion gets a **red-first mutation against a snapshot** (never
`git checkout --`, which has wiped uncommitted work under test), plus a **no-op
mutation that must stay green**, so a red-first that stays green is itself
reported as the finding.

⚠️ **Deploy liveness is deliberately NOT in the gate.** A gate that goes red
because a box lacks a systemd unit would block every lane on this box for
somebody else's install. It goes in `tools/lanes-poke-install.sh --verify`
instead — unit installed and enabled, spool present and `0666`, store dir
present and `0755`, both symlinks resolved — and I will run it and paste its
real output into the handoff. A deploy step in the repo is traceable to a
commit; a remembered one is not.

**And one thing I will report rather than gate:** I will drive the real button
once in a real browser over loopback (CDP, hit-tested with `elementFromPoint`
first, because a blind click lands on the fixed tabbar and still passes),
against a scratch store and a scratch spool, and **commit the shots to the
branch** — both themes, phone and desktop. Four times on this page only the
rendered picture has caught the defect, and no assertion above would catch a
fifth.

---

## 6. The one thing I need from Ian

Nothing blocking — the design is settled by measurement. But two calls are
yours if you want them, and I will proceed on my recommendation otherwise:

1. **Where the button sits.** My recommendation: depth zero, above *Your list*,
   below AT RISK. The alternative is inside *Your list* itself, which is tidier
   and buries it.
2. **Whether an answered question leaves a trace on the page.** My
   recommendation: it disappears from the box (it is answered; keeper has it)
   and nothing else changes. The alternative is #172's quiet line — *"you
   answered these"* — which is safer but adds a second thing to read.

## 7. State, printed rather than guessed

    branch  202-web-decision-box   worktree ~/worktrees/202-web-decision-box
    git rev-list --left-right --count origin/main...HEAD   →   0   0
    git status                                             →   clean
