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

- **Gate 77 GREEN — 179 checks**, up from 130. **Extended, never renumbered**
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

### 7. A lane verifying on dev2 is testing main — this time through systemd

The first live end-to-end probe queued correctly, the path unit fired, the spool
drained, and **nothing was marked.** `lanes-poke.service` runs
`~/keeper-repo/tools/lanes-poke-worker.sh` — keeper's clone, on **main**, which
has no `decide` verb. Main's worker split the line, failed the seat charset on
the spaces, and dropped it.

That is exactly the back-compat behaviour designed and gated for — but it means
**the decide verb starts working only when keeper's clone pulls**, not when this
merges. Worth saying out loud in the merge note.

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

1. `git -C ~/keeper-repo pull` — **the renderer and the worker both run from
   there.** Until this happens the button cannot appear and an answer cannot be
   delivered.
2. `bash tools/lanes-poke-install.sh` (as ubuntu) — creates `~/.lg-decisions`.
   **Already done on dev2** by this lane.
3. `sudo ~/loothplatformv2-clean/webroot/install-symlinks.sh --new-only` — the
   two new endpoints. **Not yet done** (they cannot exist until this is in the
   serving checkout).
4. `bash tools/lanes-poke-install.sh --verify` — read what it prints.

Current live state, measured:

    spool / stamps / store / path unit / poke symlink     OK
    docroot lanes-decisions.php, lanes-decide.php         MISSING (step 3)
    web user can read store / cannot write it             OK / OK

Take the preview down when it is no longer wanted:
`sudo bash tools/preview/lane-preview.sh down 202-web-decision-box`.

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
