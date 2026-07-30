# KEEPER HANDOFF — 2026-07-30 (written at context exhaustion)

You are keeper on dev2. Read `~/.claude/CLAUDE.md` and the auto-memory index
first — they hold the standing law. This file is the SESSION state: what is in
flight, what Ian ruled today, and what he is waiting on.

---

## THE ONE THING IAN IS WAITING FOR

**thread-follow's consolidated action-row MOCKS.** Ian ruled 7/30:
> "I'd like to add that modal to follow notifs/email/save before we ship to live."

So **the entire live deploy is HELD** until that modal is picked and built. The
lane was told to publish TWO static-HTML variants behind the dev gate:
- (a) everything in the modal (notify + email + frequency + save)
- (b) Save stays a one-tap on the row, the rest consolidate
(Save is tap-often, follow/email are set-once — that's the tension to resolve.)
When the URL lands → give it to Ian → he picks → lane BUILDS the chosen one →
then ONE deploy carries everything. `docs/UNDEPLOYED.md` records the hold.

---

## BOX STATE (changed today)

**Ian resized dev2 to t3a.xlarge — 4 vCPU / 16GB** (was 2/3.8GB). His words:
"We need to chug to make it worth it." So run a FLEET, not a trickle.
- New ceiling ~8 working lanes. Memory is ample (10GB+ free at 7 lanes, swap 0).
- CPU is now the tight resource; load ~6-12 on 4 cores is NORMAL, not a problem.
- The old 3-lane cap in memory `lane-cap-two-working` is SUPERSEDED — its
  update note says so. Two lockups happened on the OLD box; that risk is gone.
- `tools/keeper/lane-watch.sh N` = a FREE bash heartbeat that loops and wakes
  keeper on: a lane parking, swap pressure, or a new ~/lane-outbox file.
  Relaunch it after every wake: `nohup ~/keeper-repo/tools/keeper/lane-watch.sh 7 > ~/.lane-watch.log 2>&1 &`
- **Do NOT use a Haiku subagent as the watcher** — measured today: it returns in
  ~40s instead of looping, costing ~25k tokens for what one free bash command
  gives. The bash heartbeat is the watcher.

---

## THE FLEET (7 lanes, all WORKING at handoff)

Each has pushed work; branches are safe. `~/lane-prompts/spin-<name>.sh` respawns.

| lane | doing | state |
|---|---|---|
| **thread-follow** | consolidation modal mocks (THE deploy gate) | 2 ahead |
| **edit-post-parity** | per-viewport composer reuse + fresh-reply-prefill bug | 3 ahead |
| **shorty-react** | P0 live reaction-400 bug | 2 ahead |
| **profile-social-links** | P0 stale social links on posts/events | 1 ahead |
| **discussion-card-video** | P1★ play video inline on discussion cards | 1 ahead |
| **stripe-audit** | Stripe audit → phased build plan | 1 ahead |
| **weekly-recap** | digest follow-ups | 1 ahead |

Every one of those pushes needs keeper review → gate → merge. **Gate before every
merge**: `tools/gates/run-all.sh` (8 gates; ALL GREEN required). If the craft
gate hangs, `sudo systemctl restart chrome-dev.service` and re-run — that's a
known flake, not a real red.

---

## GIT / DEPLOY POSITIONS

- **main**: `6dc65a1`
- **dev2 serve**: `91cabef` (behind main by docs-only commits — pull when convenient:
  `git -C ~/loothplatformv2-clean pull --ff-only origin main` then `tools/keeper/recut-baseline.sh`)
- **LIVE**: `c57b70f` — **a full day of merged work is NOT on live** (held, above):
  the whole follow/notifications feature, the edit-discussion 4-step wizard,
  events-mobile tap-through, the public weekly-signup page.

**⚠ DEPLOY COUPLING — do not miss this:** the follow feature needs a live DB
migration IN THE SAME WINDOW as the pull:
`~/lane-outbox/thread-follow-LIVE-topic-follow.sql` (+ `-ROLLBACK.sql`), run on
LIVE as `sudo -u bb-mirror psql -d looth`. Without it the toggles render but
cannot persist — the exact dev2 symptom that was fixed by adding the table.

---

## IAN'S RULES ADDED TODAY (all now in auto-memory)

1. **Every command he runs gets a COPY BUTTON** — a fenced code block, ONE
   command per block, no prose inside the fence. He has said this twice.
2. **Never park blocked-on-Ian without a decision box** — the moment work needs
   him, raise the box that same turn. A conditional approval he already gave
   self-executes; do not re-ask.
3. **End every report with a lanes sweep** — a parked lane with work remaining
   gets fed or gets a stated reason. Don't report before checking.
4. **Remind him of undeployed work** — `docs/UNDEPLOYED.md` is the ledger.
5. **Merges need a quotable Ian decision in the commit body** (after the 7/29
   fp-save rider incident).
6. **Nothing built outside the monorepo without asking.**

---

## BACKLOG — NOW RANKED

`docs/BACKLOG.md` opens with a **PRIORITY INDEX (P0–P3)**. Ian re-ranks by
saying "bump X"; keeper edits that one line. Current P0s: shorty react-400,
fresh-reply-prefill, profile social links (all three now have lanes). P3 holds
the big builds: front-end authoring for all post types (★ vision), Stripe.

**Items Ian added today that are NOT yet laned:** admin edits any post w/ full
functionality; notif quick-reply modal (default modal + full-post link inside);
front page shows latest weekly email to logged-out users; adv-search dynamic
facet narrowing; resizable add-discussion modal w/ text scaling; PWA launch
animation; group-chat header collapse.

---

## FINISHED TODAY (do not redo)

- **30/30 duplicate account pairs merged + verified on live** — the whole saga
  closed; alarm in the poller guards against recurrence.
- **Slug backfill applied live**: 1,634 → 140 patreon URLs, 1,496 living 301s.
- **All mirror repairs run + verified on live** (purge 24 orphans, bookmark
  rewind, 2 lost replies restored).
- **Follow/notification feature works end-to-end on dev2** — Ian's screenshot
  confirmed notifications firing. Mobile long-press, desktop topic page, desktop
  feed card, orange ON state: all fixed and merged.
- events-mobile (tap navigates), edit-discussion wizard, public signup page.

## STILL OPEN, UNOWNED

- **live reconcile service is failing** — `journalctl -u bb-mirror-reconcile.service`
  on live is owed by Ian; keeper can't read it from the read-only seat.
- Ian's **layoutv2-ian** session (his own, for classic-editor/add-block pain) is
  NOT currently running — respawn: `~/lane-prompts/spin-layoutv2-ian.sh`, he
  attaches with `tmux attach -t layoutv2-ian`. Handoff for it:
  `~/projects/lg-layout-v2/handoffs/2026-07-30-classic-editor-add-block.md`.

---

## FIRST FIVE MOVES FOR THE NEXT KEEPER

1. `for s in $(tmux list-sessions -F '#{session_name}'); do ...` — sweep the fleet.
2. Relaunch the bash heartbeat (command above).
3. Check each lane branch for pushes → gate → merge what's ready (with Ian's
   quotable approval in the commit body).
4. Get thread-follow's mock URL to Ian — it unblocks the live deploy.
5. Keep the fleet full from the priority index; the box can take it now.

---

## LATE ADDITION (at handoff time)

**stripe-audit COMPLETED its audit and parked with FOUR questions for Ian (§9 of
its doc).** The one it flags hardest: **three over-tiered members** — grandfather
them at looth3 or correct to looth2, and notify or not? Its own recommendation:
grandfather, but delete the rows either way so the state stops being accidental.
Read `docs/atlas/STRIPE-MEMBERSHIP-AUDIT.md` on branch `stripe-audit`, then put
all four to Ian as decision boxes. It also confirmed the §3 finding: an
email-keyed minter would run if Stripe onboarding is switched on.
