# PLAN — #178 · a Landed button on Ian's todo cards

Lane `178-confirm-button`. Branch at parity with origin/main (0/0). Nothing
written yet outside this plan file.

## Ian's ask, verbatim (8/20 evening, right after confirming #129 by eye)

> "We might add a button for my to confirm the fix or featured landed as
> expected so I can clear it and notif you and seat."

Three things, in his order: **clear it**, **notify keeper**, **notify the seat**.

## What his list looks like right now (measured 22:55, not remembered)

12 bullets. **7 of them are the `look` family** — "merged; your look is the last
thing left" — which is exactly "the fix or featured landed as expected":

    #170 #150 #149 #148 #129 #107 #93     ← look   (would get the button)
    #104 #88  #87  #84  #81               ← flip   ("say GO to switch it on")
    #138                                  ← the quiet line

Today each look card ends with `say: "148 good" · "148 not right"` and a
**Copy for keeper** button. The loop closes by him pasting that into chat.
This lane puts the "good" half on the card itself.

## The shape

**One new dev-gated endpoint, `webroot/lanes-confirm.php`**, modelled line for
line on `lanes-approve.php` (#139) and `lanes-poke.php` (#156):
POST-only, per-day HMAC nonce derived from the GitHub token server-side, no
token in the browser, server-side re-check before it acts.

On a tap it does three things, in this order:

1. **writes the record on the issue** — a comment
   (`Confirmed landed as expected by Ian from the lanes page, <UTC>`) plus the
   label **`confirmed`**;
2. **queues one line to the existing poke spool** so keeper is told;
3. answers the browser, which flips the button to `confirmed ✓`.

The next 5-minute redraw sees `confirmed` and drops the card. **That is the
whole clearing mechanism** — no second source of truth, the same GitHub state
the list is already built from.

### Decision 1 — it labels, it never closes

Keeper's ritual today is *comment, then close*. I am deliberately **not** giving
this button the close verb:

- Ian's own ask says "notif you and seat", which means keeper still has work
  after the tap — tell the seat, update the domain dossier, decide about live.
  Closing is the last act **after** that, and it is keeper's.
- `lanes-approve.php`'s whole warrant is that it "cannot close, edit, comment or
  read anything out". A one-verb servant that can end an issue is a different
  kind of thing. This one gets exactly two writes, both additive and both
  reversible by removing a label.
- Several look-family issues (#148/#149/#150) are merged to main but **not yet
  on live** — live deploys are Ian's. Closing those on a dev2 confirmation
  would lose the live half.

**A `confirmed` card does not silently vanish.** It moves to a second quiet line
under his list — `you confirmed these; keeper is closing them out: #129` —
which is #172's ruling applied again (*a wrong quiet line is recoverable, a
wrong disappearance is not*) and doubles as keeper's visible nag. The line
disappears when keeper closes the issue.

### Decision 2 — the `look` family only

`flip` cards ("say GO to switch it on") are asking for a **flip**, not a
confirmation; the confirmation comes after. `plan` cards want a GO. `question`
cards want a sentence. So the button renders on `look` cards and nowhere else.

⚠️ **A caveat I found while measuring, and am not hiding**: #129's `ACTION:`
record has been rewritten to *"say 'flip 129' when you want the new composer on
for everyone on live"* — a look-family card whose real remaining ask is a live
flip. A tap there would clear a card that is not finished. I am **not** adding
machinery to guess at that; the mitigation is that the button asks first
("Confirm #129 landed as expected? This clears it from your list and tells
keeper.") and that keeper sees every tap on the board and can put it back.

### Decision 3 — no new deploy machinery

The confirm reuses **the poke spool, the poke path unit and the poke worker**
that are already installed and running. A `git pull` plus one symlink is the
entire deploy. The alternative — a second spool file, a second `.path`, a second
`.service` — is three more steps a pull does not do, and this box's scar tissue
is mostly missing deploy steps.

- spool line today: `<ts> <seat>` → **unchanged**, still a poke
- spool line new:  `<ts> confirm <issue>` → a confirm
- the worker keeps its name (`lanes-poke-worker.sh`) because
  `lanes-poke.service` names that path; renaming it would need a systemd edit
  and a `daemon-reload`, i.e. exactly the deploy step I am avoiding. The docblock
  will say so.

**No nginx change.** The gate is a server-level
`if ($loothdev_is_authorized = 0) { return 403; }` in the running conf, so every
URI on the host is gated, and the bottom `location ~ \.php$` already hands any
docroot `.php` to FPM — verified on the running conf, not the repo's gate-free
copy.

### The seat half

Routed through keeper exactly as the charter requires — this lane never touches
another lane's tmux session or worktree. The **worker** resolves the seat itself
(it runs as ubuntu): `~/worktrees/<n>-*`, else a `<n>-*` branch in keeper-repo,
else honestly "no seat found — the branch may be retired". It then:

- posts to the board (no backticks, no dollar signs — it has gutted two lanes);
- appends to **`~/.keeper-confirms`**, a new wake file with its own watermark and
  its **own** `ALERT ian-confirm` sentence in `stall-watchdog.sh`. Not folded
  into `~/.keeper-pokes`: that alert says "flagged these seats as **idle**",
  which would be a lie about a confirmation, and this page's oldest law is that
  two different things must never render alike.

## A latent bug I will fix in passing (in scope, flagged)

`if todo:` guards the whole "Your list" accordion, and the quiet line renders
**inside** it. So if every bullet is cleared, today's #138 quiet line vanishes
silently. My feature makes that state reachable in one evening of tapping. The
section will render when `todo or quiet or confirmed`.

## The question for Ian — the "not right" sibling

**Not in your words, so not built unless you say so.** My recommendation is to
leave it off, because "not right" needs a *sentence* (what's wrong), and a
button cannot carry one — the card already has **Copy for keeper**, which hands
you `Re #148 … — [148 good / 148 not right]` ready to paste with the wrong half
deleted. That is the better tool for the unhappy path. Say the word and I will
add it as a second button that queues "Ian says #n is not right" to keeper
without touching the issue.

## Files I expect to touch

Guessing wider rather than narrower. Checked against live lanes: **none of these
are in the collision list** (`docs/CRAFT-STANDARD.md` and
`tools/gates/run-all.sh` are held by 162/166/emoji-picker — I touch neither).

| file | what |
|---|---|
| `webroot/lanes-confirm.php` | **new** — the endpoint |
| `tools/lanes-page.py` | the Landed button, the confirmed-quiet line, skip `confirmed` in the todo families **and** in the seat-card label upgrade, the JS handler, the empty-list guard |
| `tools/lanes-poke-worker.sh` | the second verb + seat resolution + the board post |
| `tools/lanes/stall-watchdog.sh` | the `ian-confirm` wake trigger |
| `tools/lanes-poke-install.sh` | note the new symlink; create the `confirmed` label idempotently |
| `tools/gates/lanes-page-truth-gate.py` | gate 77 leg [7] — **extended, no new gate number minted** |
| `tools/gates/lanes-page-truth-redfirst.sh` | the mutations for leg [7] |
| `docs/domains/PAGE.md` | the domain rule: a `page`-labelled issue updates it in the same commit |
| `handoffs/2026-08-20-178-confirm-button.md` | **new** — the handoff |
| `handoffs/plans/178-confirm-button-PLAN.md` | **new** — this file |

`tools/gates/run-all.sh` is **deliberately not touched** — gate 77 is already
registered there, and three other lanes hold that file tonight.

Repo metadata, not a file: the **`confirmed` label does not exist yet** and will
be created once via the API (idempotent, and recorded in the install script so
it is traceable to a commit rather than remembered).

## How I will prove it

Gate 77 grows a leg [7], browser-free like the rest of it so it cannot flake
under load or go vacuously green behind a locked-out session:

- **the endpoint's refusals**, driven through real `php` with the GitHub caller
  injected (`function_exists` shim, the same trick leg [5] uses for constants),
  so no network and no real issue is ever touched: GET refused · forged nonce
  refused · **a nonce minted for another issue refused on this one** · a closed
  issue refused · an issue without `merged` refused · a `built` issue refused ·
  an already-`confirmed` issue refused (that is the double-tap guard, which is
  why this needs no debounce file);
- **what it actually wrote**: exactly one comment, exactly the `confirmed`
  label, **and no close call, ever** — asserted positively, because "it does not
  close" is the claim most worth a red-first;
- **the worker**: a `confirm` line reaches keeper naming the issue and the seat;
  a poke line still delivers as it did (the back-compat assertion); no backticks
  in either; the spool drains and keeps its mode;
- **the watchdog**: `ALERT ian-confirm` fires, says *confirmed* and not *idle*,
  and does not re-alarm once the watermark advances;
- **the render**: a look card carries the button with a per-issue nonce; flip,
  plan and question cards do **not**; a `confirmed` issue leaves the list **and
  appears in the quiet confirmed line**; its seat stops saying "waiting on your
  check"; the list renders when only the quiet lines remain.

Every one gets a red-first mutation against a **snapshot** (never
`git checkout --`), plus a no-op mutation that must stay green. Absences are
paired with liveness: "flip cards have no button" is worthless unless a look
card in the same fixture still has one.

I will drive the real button once in a browser over loopback (CDP, hit-tested
with `elementFromPoint` first) against a scratch render and a shimmed endpoint,
and **report** that rather than gate it — same call #172 made, for the same
reason.

## Not doing

- Not closing issues. Not touching another lane's tmux session or worktree.
- Not a "not right" button unless Ian says so above.
- Not a button on flip/plan/question cards.
- Not touching `run-all.sh`, `CRAFT-STANDARD.md`, or any nginx conf.
