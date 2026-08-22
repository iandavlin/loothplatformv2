# HANDOFF — #202 · the decision box on the web

Lane `202-web-decision-box`, 2026-08-22. Branch cut at current main.
Plan: `handoffs/plans/202-web-decision-box-PLAN.md`.
Domain knowledge: `docs/domains/PAGE.md`, section **#202**.

**Pictures (the real thing, not a mock):** `footer-mockups/202-decision-box/`
**Door for Ian:** <https://dev2.loothgroup.com/preview/202-web-decision-box/>

---

## What Ian asked for, and what he has

> *"I don't want the todo proposal. I want a button that opens up the decision
> box that we use here and have it communicate with you. Can we build that ?"*

The lanes page now carries a **Decisions waiting for you** block at the top. One
tap opens the same 2–4-option box keeper poses in chat; one click answers one
question; keeper is woken inside a minute with the answer framed as an Ian act.
Chat and page read one store, and **the first answer wins** whichever channel it
arrives through.

## The four pieces

| | |
|---|---|
| `~/.lg-decisions/` | the store — `0755 ubuntu:ubuntu`, `0644` files, one per question, plus an `<id>.claim` |
| `tools/decisions/lg-decide.py` | keeper's end: `ask` · `list` · `show` · `answer` · `pending-count` |
| `webroot/lanes-decisions.php` | **GET** the pending set, each option carrying its own nonce |
| `webroot/lanes-decide.php` | **POST** one answer onto the existing poke spool |

Delivery adds a second verb to the spool (`<ts> decide <id> <key>`) and rides
`lanes-poke.path` / `.service` / `lanes-poke-worker.sh` — **no new systemd
units**. It then writes a **separate** wake file `~/.keeper-decisions` with a
**separate** `ALERT ian-decision` in `stall-watchdog.sh`, because the poke alert
says *"Ian flagged these seats as IDLE"* and that would be a lie about a man
answering a question.

## Proof

- **Gate 77 GREEN — 183 checks**, up from 130. **Extended, never renumbered**
  (keeper's ruling): main's next-free line said 98 while lane 201 already held
  98 unmerged, and minting would have meant editing `CRAFT-STANDARD.md` and
  `run-all.sh`, both held by live lanes.
- **Red-first**: every new assertion has a mutation that reddens *it*, plus
  no-op mutations that must stay green.
- **Live, on the real box, over real HTTPS through the dev gate**: a POST to
  `/lanes-decide.php` → the spool → the worker → **the real board**:

      IAN ANSWERED A DECISION BOX ON THE LANES PAGE (ian-via-page), 20:25 UTC.
      Question: END-TO-END PROBE 2 … His answer: It arrived. …

- **A real browser** at 1280×900 and 390×844, hit-tested, tab closed after.

---

## ⚠️ THINGS FOUND ALONG THE WAY THAT OUTLIVE THIS ISSUE

### 1. The Poke keeper button had never been deployed — keeper's, not mine

`lanes-poke.path` and `lanes-poke.service` **did not exist on dev2**. No unit
files at all; `systemctl list-unit-files 'lanes-*'` listed only
`lanes-refresh.path` and `lanes-page.timer`. Everything *else* about #156 was
deployed — endpoint symlinked, spool `0666`, stamps `0777` — so a tap validated
its nonce, appended to a spool **nothing drained**, and printed *"keeper told
✓"* at Ian. Nothing had been lost only because it had apparently never been
pressed (spool 0 bytes, `~/.keeper-pokes` absent).

Boarded to keeper rather than fixed under them. Keeper installed it at 19:51;
I verified `active` + `enabled` rather than taking it on trust.

**The durable half:** `bash tools/lanes-poke-install.sh --verify` now measures
the whole chain and prints it. Run it after a deploy.

⚠️ It is deliberately **not** a gate. A gate that goes red because a box lacks a
systemd unit blocks every lane on that box for somebody else's install.

### 2. `LG_GATE_COOKIE` is the cookie's NAME, not its value — and loopback hides it

`/etc/looth/env` has `LG_GATE_COOKIE=loothdev_auth`. The **value** lives only in
`/etc/nginx/conf.d/loothdev-auth.conf`. And the mistake hides behind a second
one: **a curl with `--resolve …:127.0.0.1` passes the gate on
`$loothdev_src_local` whatever cookie it carries**, so a bogus token over
loopback returns 200 and looks exactly like a working cookie — while a real
browser (not loopback) gets *"This device isn't claimed"*. Only the shots'
liveness assertion caught it.

### 3. `/gatetest` is exempt and will tell you you are fine

It returned `auth=1 dev_ok=0` for a browser that could not load a single gated
page. `~^/gatetest` is in the exempt map. It answers about itself.

### 4. A class-name count counts the stylesheet and the script

`optbtn` appears **10** times in a correct page whose real markup holds
**zero** — the CSS and the JS both name it. An assertion written that way passes
on the very defect it is written against. The gate strips `<style>` **and**
`<script>` before counting anything.

### 5. Red-first found a weak assertion of mine, which is the point of it

*"Eight simultaneous answerers produce exactly one winner"* **passed with the
`O_EXCL` removed from the claim.** `answer()` short-circuits on an
already-answered body before it reaches the claim, and python's own startup
jitter let the first process finish before the eighth had imported `json` — so
the fast path was doing all the work and **the real first-answer-wins guard was
never under test.** The children now spin on a barrier file and enter `answer()`
in the same millisecond; removing `O_EXCL` then yields *"2 of 8 believed they had
answered it"*, and the assertion means something.

### 6. Two mutations named the wrong thing, and one aborted the suite

- Breaking the nonce formula in **one** endpoint breaks the two files'
  *agreement*, not the *binding*: nothing authenticates, every "…is refused"
  check passes vacuously, and the **happy path** is what reddens. Real and
  worth proving (it is #191's lesson made executable) — but it is not a test of
  what the nonce binds. Those two are renamed, and a **two-file** mutation was
  added that breaks the binding while keeping the files agreeing.
- One mutation string was double-quoted, so bash expanded `$ts` itself, the
  suite died with `ts: unbound variable`, **and every mutation after it silently
  never ran.** A harness that aborts mid-suite reports fewer proofs than it has,
  and the missing ones look like they were never written.

### 7. Queueing is not delivering — so the endpoint now refuses rather than lies

The one remaining way this button could have lied the way **Poke keeper** did.
`lanes-decide.php` only ever *appends* to a spool; the thing that *drains* it is
`lanes-poke-worker.sh`, run out of **`~/keeper-repo`** — a clone carrying
**main**, not whatever branch introduced the verb. So an answer tapped before
keeper's clone pulls would be validated, queued, dropped on the seat-name
charset, and reported to Ian as *"keeper has been told"*.

The endpoint now greps the deployed worker for the marker
**`LG_DECIDE_WORKER_V1`** and refuses **503** with a sentence naming the fix. An
*unreadable* worker refuses too, with its **own** sentence — *"I could not
check"* and *"it is not deployed"* are different answers and neither is *"sent"*.

Four gate checks, and the fourth is the one that matters: **the same answer goes
through once the worker knows the verb.** Without that pairing the two refusals
above would be satisfied by an endpoint that refuses everything.

`--verify` reports it too, and says **no** today, correctly, because the box is
still draining with main's worker.

### 8. A lane verifying on dev2 is testing main — this time through systemd

The first live end-to-end probe queued correctly, the path unit fired, the spool
drained, and **nothing was marked.** `lanes-poke.service` runs
`~/keeper-repo/tools/lanes-poke-worker.sh` — keeper's clone, on **main**, which
has no `decide` verb. Main's worker split the line, failed the seat charset on
the spaces, and dropped it.

That is exactly the back-compat behaviour designed and gated for — but it means
**the decide verb starts working only when keeper's clone pulls**, not when this
merges. Worth saying out loud in the merge note.

### 9. A mutation that only SOMETIMES reddens is worse than no mutation

*"Remove `O_EXCL` from the claim"* reddened the race check in one run and left
the gate green in the next. The cause is that **first-answer-wins has two
guards and only one is the guarantee**: `answer()` short-circuits on an
already-answered *body* before it ever reaches the claim. That fast path is an
optimisation; the claim is the promise. Remove only the claim and the fast path
still catches most racers — so the result depends on how the scheduler felt.

A flaky mutation reports a healthy tree as broken on a bad night, which is the
one thing a red-first must never do. Replaced with a **deterministic pair** that
also says something the single mutation never could:

| mutation | expected | measured |
|---|---|---|
| remove the fast path **alone** | **GREEN** | GREEN, 183 checks |
| remove the fast path **and** `O_EXCL` | **RED** | *"8 of 8 believed they had answered it"* |

The green half is the interesting one: it is a **positive proof that the claim
is carrying the guarantee**, not the fast path.

### 10. A gate check that CRASHES throws away every check after it

The mutation *"the worker stops marking the store"* reddened the wrong things —
and the reason was a bug in my own check:

    json.loads(...).get("answered", {}).get("via")

`answered` **exists with the value `null`** on an unanswered question, so the
`{}` default never fires, `.get` is called on `None`, and the gate raises
`AttributeError` mid-leg. Every check after that line never ran, so the mutation
looked as though it reddened unrelated assertions. `or {}` instead of a default,
and it now fails cleanly with *"the store says nothing"*.

This is #191's lesson arriving in a new costume: **a gate that aborts must still
report what it already measured.** Here it did not even know it had aborted.

### 11. The red-first harness reported a GOOD assertion as broken — a latent SIGPIPE

The one worth carrying furthest, because it is not about this feature at all.

    printf '%s\n' "$out" | grep -q "FAIL  $want"      # under set -o pipefail

`grep -q` exits the moment it matches → the pipe closes → `printf` dies with
**SIGPIPE (141)** → `pipefail` reports **141 for the pipeline**. A *successful*
match returns failure, and the harness prints *"RED, but NOT for the stated
reason"* followed by the exact line it just failed to find.

**Size-dependent, so it lay dormant.** While gate 77's output fitted the
65,536-byte pipe buffer, `printf` finished first and nothing happened. #202's
checks pushed it past that, and a **pre-existing, perfectly good** #159 mutation
started failing. Proved deliberately rather than guessed:

    printf '%s\n' "$small" | grep -q target   → 0
    printf '%s\n' "$big"   | grep -q target   → 141

Fixed with a herestring. **It gets worse as any gate grows, and it lies in the
direction that invents findings** — I nearly spent the evening hunting a defect
in somebody else's working code.

**Swept box-wide and measured, not assumed:** 18 files on main pair `pipefail`
with a pipe into `grep -q`. Only >64KB input can trip it. The two that would
matter are both in this domain and both **measured safe**:
`tools/lanes-status.sh:66` (the working-detector — a false negative there reads
a WORKING lane as PARKED; real panes are **1,327–1,455 bytes** against 65,536,
~45× headroom) and `tools/approved-watcher.sh:203` (a list of session names).
Neither changed. Full reasoning in PAGE.md.

---

## ⚠️ DEPLOY ORDERING — OR THE BUTTON LIES EXACTLY LIKE THE OLD ONE DID

`lanes-poke.service` runs **`~/keeper-repo`'s** worker. Until keeper's clone
pulls, that worker is main's and has no `decide` verb: a tap will validate its
nonce, queue a line, **and be silently dropped**, while the browser shows
*"answered ✓ — keeper has been told"*. That is the identical failure this lane
found in the Poke keeper button an hour earlier, and it is reachable the moment
a question exists in the store on an un-pulled box.

**So the order matters, and only keeper can get it wrong:**

1. `git -C ~/keeper-repo pull` **first**,
2. then `install-symlinks.sh --new-only`,
3. then `--verify`,
4. and only then pose a question anybody might answer.

**Nothing is pending on an un-deployed box unless keeper puts it there**, which
is the practical safeguard — the button cannot appear with an empty store. Two
questions ARE in the store right now (below), posed deliberately for Ian on a
box where the preview serves the branch's own worker.

## Deploy — the steps a `git pull` does not do

**Everything a lane can do alone is done.** What is left needs the merge,
because both remaining steps read from checkouts a lane must never write.

    # after the merge to main, in this order:
    git -C ~/loothplatformv2-clean pull --ff-only origin main   # the SERVING checkout
    git -C ~/keeper-repo pull                                   # the renderer AND the worker
    sudo ~/loothplatformv2-clean/webroot/install-symlinks.sh --new-only
    bash ~/keeper-repo/tools/lanes-poke-install.sh              # idempotent; store already made
    bash ~/keeper-repo/tools/lanes-poke-install.sh --verify      # read what it prints

⚠️ **`keeper-repo` before anyone taps anything.** `lanes-poke.service` runs
*that* clone's worker. The endpoint now refuses rather than queueing into a
worker that would drop the answer, so the failure is loud rather than silent —
but a refused tap is still a tap that did not work.

Current live state, measured at 21:16:

    spool / stamps / store / path unit / poke symlink     OK
    web user can read store / cannot write it             OK / OK
    delivery worker understands answers                   no    ← keeper-repo pull
    docroot lanes-decisions.php, lanes-decide.php         MISSING ← install-symlinks

Those last two are **expected** before the merge and are exactly what `--verify`
is for. `install-symlinks.sh --new-only` picks the two endpoints up on its own —
it scans `webroot/` — so there is no per-file step to remember.

### ⚠️ SO A TAP ON THE PREVIEW IS REFUSED TONIGHT, AND THAT IS CORRECT

Measured just now against a real pending question over real HTTPS:

    {"ok":false,"error":"the answer path is not deployed yet — keeper's
     checkout still has the old delivery worker, which would drop this
     silently. Nothing was sent. Ask keeper to pull."}

and it left **zero trace** — spool 0 bytes, no duplicate-guard stamp, the
question still pending. The refusal happens before anything is written.

**This is the feature working, not the feature broken.** The preview really
cannot deliver an answer until keeper's clone pulls, and the alternative — the
behaviour this endpoint had two hours ago — is a green tick over a dropped
answer. The full delivery path *was* proven end to end earlier, before the check
existed: a real browser click at 20:37 reached the board as `ian-via-page`.

After `git -C ~/keeper-repo pull`, taps go through with no further change.

Take the preview down when it is no longer wanted:
`sudo bash tools/preview/lane-preview.sh down 202-web-decision-box`.

## ⚠️ IAN'S QUESTION, 8/22 evening: what would it take to guide lanes from the page?

Answered from what is actually on the page, not from what could be:

**What he can do from the page after this merge — four verbs, all one-tap:**

| | |
|---|---|
| **approve a plan** | `lanes-approve.php` (#139) — adds `approved`, which is what spin-lane needs |
| **poke keeper** about a seat that looks idle | `lanes-poke.php` (#156) — *and it only started working today; it had never been deployed* |
| **redraw the page** | `lanes-refresh.php` (#143) |
| **answer a decision** | `lanes-decide.php` (#202) — **new**, and the only one that carries a choice rather than a signal |

**So most of "guiding" already reduces to a structured question**, and keeper can
pose one about anything: which of two designs, whether to flip a flag, whether a
thing landed as expected (#178's Landed button is folded in as exactly that).
Ian answers with one tap; keeper is woken inside a minute; the box closes in
both channels.

**The one thing he still cannot do from the page is send a SENTENCE.** A lane's
free-text question (todo family 1) and any correction in his own words still go
through **Copy for keeper** and a paste into chat. That is a deliberate gap, not
an oversight: a text box that reaches a lane is a different security posture
(free text into a board message into an agent's context) and a different design
conversation. **If Ian wants it, that is the next issue, and it should be its own
seat.**

⚠️ And the honest caveat, because it is the difference between "guiding lanes"
and "guiding keeper": **every verb above talks to KEEPER, never to a lane
directly.** Nothing on this page touches another seat's tmux session — that is
LANE-RULES, and it is what stops the page becoming a way to corrupt a running
agent's context. Ian steers; keeper drives.

## Keeper's half of the contract, now recorded as law in PAGE.md

**Every box posed in chat is also written to the store while unanswered, and
answered there the moment Ian answers in chat.** Without it the two channels
disagree and the page's silence stops meaning anything — which is the same
failure #202 was opened to fix.

## Two questions posed in the store for Ian, right now

Both are live in the box and are the two calls the plan flagged as his:

- *Where should the decisions button sit on the lanes page?* — top (recommended)
  / inside Your list
- *When you answer a box, should the page leave a trace?* — just clear it
  (recommended) / leave a quiet line

**Answering either one is itself the demonstration**: keeper gets it on the
board within the minute, and the box clears in both channels.

## Reported, not fixed

- **The `page` label.** #202 is the **first of the last ten** `page`-labelled
  issues that genuinely is a lanes-page issue. It needs Ian's ruling, not an
  eleventh footnote.
- **The todo list still selects on a hand-applied label** and was measured
  frozen since 20 August (11 bullets, 8 doorless, 10 owed items absent, 6 of
  those already carrying a `TEST-URL`). The decision box does not fix that — it
  gives keeper a channel that does not depend on the ritual.
- **The lanes page still has no light theme** (`body{background:#14161a}`
  hardcoded, `prefers-color-scheme` appears zero times) and **the deploy strip
  still prints raw SHAs** against Ian's own format law. Both were the design
  seat's findings; both are still open.
- At 360×640 the dialog's bottom clears the injected tabbar by **4px**. Fine
  today, and the first thing to check if the box ever grows furniture.
