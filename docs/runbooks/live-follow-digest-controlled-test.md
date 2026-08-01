# Controlled live test — the follow digest, to Ian only

**Question this answers:** *"Does the batcher work?"* — not "did one email render", which
is what the 7/31 one-off proved. This exercises the **scheduled loop**: cron hook →
select by cadence → batch followed-thread replies since the watermark → mail the
recipient set. With the recipient set hard-locked to one person.

**Status: dev2 side is PROVEN. Live side is a set of commands for Ian — nothing has
been run on live, and nothing here changes live until he runs it.**

> ### ⚠️ 2026-07-31 — Ian ran it, it REFUSED, and it was right to (but for the wrong reason)
>
> The safety held: nothing was sent. But the refusal came from a guard that could
> **never** have passed on live, so it was not a verdict about this send at all.
>
> The one-shot refused on `has_filter('pre_wp_mail')` — *any* filter. On live,
> `lg-poller-mail-killswitch.php` is always registered (it self-disables only where
> `lgms_poller_mail_enabled` is truthy, and on live that option is **absent**). So the
> old guard was a wall, not a gate.
>
> And that killswitch is **selective**: read it — it returns `$short` untouched unless
> the call stack runs through `/lg-patreon-stripe-poller/`, and it exempts anything
> carrying `X-LG-Poller-Intent`. A follow-digest send matches neither. It would never
> have swallowed this mail. Live member mail is fine and was never at risk.
>
> `has_filter()` was **presence standing in for behaviour**, and it was wrong in both
> directions — it would equally have *passed* a box where containment was registered,
> and containment returns `true`, so `wp_mail()` reports success for a message that
> reached nobody. Replaced with a probe that measures the thing itself (§4a).

---

## 1. The short version

| | |
|---|---|
| What is new | A recipient **allowlist** that fails closed to NOBODY, enforced at the send layer |
| Tracked config | `platform/config/follow-digest.php` — arrives by `git pull`, no reload, no root |
| Value shipped | `enabled => false`, `allowlist => '1:ian.davlin@gmail.com'` |
| Live blast radius of the test | **One address.** The flag is set on the command line, so it is on for that one process — no cron armed, no member's page changes, nothing persists |
| Mail channel | A **behavioural probe** decides whether a send would be swallowed — not `has_filter()`, which could never pass on live (§4a) |
| Gate | `tools/gates/follow-digest-gate.py` — GATE 13/14, green in both flag states; `--prove-test-mode` adds the allowlist proof, its red-first, and the probe proof |

---

## 2. What is proven on dev2, and how

`platform/bin/follow-digest-allowlist-proof.php` — 19 assertions, all green. It puts two
members through **the real cron callback** (`do_action('lg_fd_send')`), both subscribed
to the same topic, same cadence, same watermark, so the allowlist is the only variable.

```
RUN 1  allowlist = Ian only
  ok  Ian received EXACTLY ONE digest — "4 new replies in a discussion you follow"
  ok  the canary received ZERO — and it qualified with 8 replies. THE ALLOWLIST HELD.
  ok  no message reached ANY address other than the allowlisted one
  ok  the blocked canary's watermark did NOT move
  ok  Ian is on Daily and IS served, so his per-reply mail is suppressed
  ok  the BLOCKED canary is on Daily but its per-reply mail is NOT suppressed

RUN 2 (THE CONTROL)  allowlist = Ian + the canary
  ok  the canary received a digest once allowlisted — so run 1's zero was caused by
      the ALLOWLIST and by nothing else
  ok  its watermark advanced only now — the 8 replies run 1 withheld arrived INTACT
  ok  Ian received NOTHING on the second tick — no double send
```

**Run 2 is the whole argument.** "The canary got no mail" is equally true of a canary
that never qualified, a flush that never ran, and a box with no WordPress. Run 2 is what
makes run 1 evidence.

### The attempt recorder — "nothing was even tried"

A send that containment swallows is still a send that would have reached SES on live, so
mailpit alone is not enough. A filter at `pre_wp_mail` **priority 0 — ahead of
containment's 1** — records every address `wp_mail()` is asked to send to, and passes it
through untouched. The blocked member's zero is therefore a direct observation, not an
inference about someone else's plugin:

```
ok  wp_mail() was NEVER INVOKED for the blocked canary — nothing to swallow, nothing to
    queue, and nothing that would have been an SES call on a box without containment
ok  wp_mail() was invoked EXACTLY ONCE for Ian — the allowlisted send really happened
```

### Red-first, end to end

The same driver is run against a build with `lg_fd_allowed()` forced to return `true` —
**one line different, nothing else** — and is required to *see the leak*. Otherwise a
"held" result would be indistinguishable from a driver that cannot detect a failure at
all. Same seed, same tick:

| build | `wp_mail()` attempts that tick |
|---|---|
| allowlist **removed** | `ian.davlin@gmail.com`, `follow-digest-canary@dev2.invalid` |
| allowlist **present** | `ian.davlin@gmail.com` |

Both halves are gated together:

```bash
python3 tools/gates/follow-digest-gate.py \
  --plugin platform/mu-plugins/lg-follow-digest.php --prove-test-mode
```

```
ok  RED-FIRST: with the allowlist stripped, the driver SEES the canary being mailed
ok  TEST MODE HOLDS: flag ON, allowlist=[1], a QUALIFYING non-allowlisted member
    received ZERO — and wp_mail() was never invoked for them
```

`--prove-test-mode` **mutates the DB** (seeds, then tears down — including on a fatal, via
a shutdown handler), so it is opt-in and `run-all.sh` does not use it. The non-mutating
assertions still run there as GATE 13/14.

⚠️ **The red-first build caught a real trap in its own first version.** The stripped copy
was written to a flat `/tmp` path, so `dirname(__DIR__, 2)` no longer found
`membership-pages/lib/following-data.php`, and the sender **correctly refused to send a
linkless digest — to everyone**. It mailed nobody for a reason that had nothing to do with
the allowlist. Had the driver only ever checked "the canary got nothing", that build would
have looked like a pass. The stripped copy now keeps the repo shape.

### ⚠️ …and then the identical trap came back through the fix for it (7/31)

The repo-shape fix created the `membership-pages` symlink `if not os.path.lexists(link)`.
**`lexists()` is `True` for a *dangling* symlink.** `/tmp` is shared by ~110 worktrees on
this box, so once it held a link to a worktree that had since been removed, it was never
rebuilt — the link store read as unreachable, the stripped sender refused **everybody**,
and the leak check reported *"the canary still received nothing"*. That reads as "no
leak". It actually meant "this build cannot send at all", and it made the entire
end-to-end proof vacuous. Reproduced deliberately, then fixed three ways:

1. **The symlink is always repointed**, and the target is proven readable *by the user
   that will read it* (`sudo -u looth-dev`) at build time, with a reason.
2. **The driver no longer trusts the harness.** Before any tick it exercises the real
   link path on the real seed topic and **aborts** if the build cannot produce a link:

   ```
   ok  LIVENESS: this build CAN send — the link store resolved topic 72330 to a real hub
       url, so a zero below is not "refused to send to everyone"
   ```

3. **The leak run splits three ways**, because the three causes need different fixes:
   canary silent + Ian mailed → a canary problem; canary silent + **Ian also silent** →
   a broken build, said in those words, not filed as "no leak".

**The positive control, in order** (keeper's framing): *Ian mailed* proves the machinery
delivers at all; *the canary mailed once the allowlist is removed* proves the allowlist
was the only thing that ever stopped it. Neither half means anything alone.

The recipient oracle is mailpit, and it is a faithful one: dev2's containment rewrites
the `From:` but preserves every `To:` — so *who a message was addressed to* is directly
observable. Only the **clock** is simulated (the tick flushes at 08:00 site-local and a
test cannot wait); if the simulation fails to take, the run aborts rather than reporting
a silent no-op as a pass.

Re-run it:

```bash
cd ~/worktrees/follow-digest
python3 tools/gates/follow-digest-gate.py --plugin platform/mu-plugins/lg-follow-digest.php
sudo -u looth-dev env LG_FOLLOW_DIGEST=1 LG_FD_MU_DIR=/tmp/lg-fd-gate-mu \
  php platform/bin/follow-digest-allowlist-proof.php
```

---

## 3. ⚠️ THE CRON COLLISION — READ BEFORE FLIPPING ANY TRACKED FLAG

**`lg_wd_send_digest` — the editorial weekly recap, a DIFFERENT system — is scheduled on
live for MONDAY 2026-08-03 13:00 UTC.**

**If `enabled` is ever set to `true` in the tracked config, `lg_fd_send` arms an hourly
schedule and `lg_fd_tick()` flushes the daily digest at 08:00 site-local = 12:00 UTC —
ONE HOUR BEFORE IT, every day, including that Monday.**

Nothing in this work rides that hook, registers on it, or modifies it — verified: live's
cron array holds `lg_wd_send_digest` exactly once and holds **no** `lg_fd_*` entry at all.
But the two would send an hour apart on 8/3.

**The test in §4 does not arm anything and does not go near it.** It puts the flag on one
command line, so the hourly schedule is never created. The collision only matters if the
tracked `enabled` is later flipped, which is a separate decision.

---

## 4. The live test — Ian's commands

Prerequisite: this branch is merged and pulled on live (`lg-deploy`, or
`git -C ~/loothplatformv2-clean pull --ff-only origin main`). **No FPM reload and no
symlink work is needed** — the config is a plain file read through the existing
mu-plugin symlink, and the flag is passed per-command.

⚠️ **A pull is genuinely required, even though the allowlist is already live.** Checked
2026-07-31: live already has `lg_fd_allowed`/`lg_fd_allowlist` in its deployed sender and
`platform/config/follow-digest.php` with `enabled => false`, `allowlist =>
'1:ian.davlin@gmail.com'`. What is **not** there yet is `platform/lib/lg-fd-mail-probe.php`
— the new file the probe lives in. Without it the one-shot exits at step 1 saying it
cannot read the probe library. It needs **no symlink of its own** (it is not a mu-plugin;
the one-shot reads it by `__DIR__`), so the pull alone is enough.

Everything below runs from `~/loothplatformv2-clean` on **live**.

**Step 1 — look, send nothing.** Prints the real resolver's output and what would go out.
It now also **lists every registered `pre_wp_mail` filter** — informationally. Seeing
`lg-poller-mail-killswitch.php` there is expected and is **not** a problem.

```bash
sudo -u looth-dev env LG_FOLLOW_DIGEST=1 php platform/bin/follow-digest-live-oneshot.php
```

**Step 2 — prove the channel. Sends ONE probe email to you and nothing else.**

```bash
sudo -u looth-dev env LG_FOLLOW_DIGEST=1 php platform/bin/follow-digest-live-oneshot.php --probe
```

This sends a real one-line email with a unique token in the subject. **If it lands in
your inbox, delivery is proven before a digest is ever built** — and if the chain
swallows it, the script refuses and tells you which filter did it. Safe to repeat.

**Step 3 — give yourself a window.** You currently hold no cadence on live, and
`lg_fd_set_cadence()` stamps the watermark to *now* (the flood guard — it is what stops a
first digest being the entire history of every thread you follow). So a backdate is
required, and it is deliberately not defaulted:

```bash
sudo -u looth-dev env LG_FOLLOW_DIGEST=1 php platform/bin/follow-digest-live-oneshot.php \
  --arm --since=2026-07-20
```

**Step 4 — send it, to you.** Re-runs the probe first, so you get **two** emails: the
probe, then the digest.

```bash
sudo -u looth-dev env LG_FOLLOW_DIGEST=1 php platform/bin/follow-digest-live-oneshot.php --send
```

**Step 5 — put your account back.**

```bash
sudo -u looth-dev env LG_FOLLOW_DIGEST=1 php platform/bin/follow-digest-live-oneshot.php --disarm
```

The script refuses to do anything unless: it is live (by `home_url()`, **not** `LG_ENV`);
**the channel probe came back clear**; the allowlist resolves to **exactly**
`1:ian.davlin@gmail.com`; and the resolver returns nobody but you. Any other allowlist —
`1` unpinned, `1,7`, `all-members`, malformed — and it exits without sending.

### 4a. What the channel probe actually asks

WordPress runs the filter as `$pre = apply_filters('pre_wp_mail', null, $atts);` and
treats **any non-null result as "already handled"**. `apply_filters()` runs *every*
callback regardless, each receiving the running value — so a callback registered **last,
at `PHP_INT_MAX`, sees exactly what WordPress is about to see.**

- value still `null` → nothing short-circuited → the message went on to real delivery.
- value anything else → something upstream swallowed it, and `wp_mail()` will return
  **`true`** anyway.

That is a direct observation of the real chain on the real box — not an inference from
which plugins happen to be loaded — and it answers correctly for filters nobody has
written yet. It **fails closed** on every ambiguity, including "the recorder never ran":
an unanswered question is not a yes.

Proven on dev2 in both directions (`platform/bin/follow-digest-mail-probe-proof.php`,
wired into the gate):

```
--- A: with dev2's real containment registered ---
      ran=yes swallowed=yes wp_mail_returned=true
 ok   CONTAINMENT IS DETECTED: the probe was short-circuited, so the one-shot REFUSES.
      Note wp_mail() returned true — which is exactly why returning true is not
      evidence of delivery.

--- B: killswitch only (live's real chain), containment removed IN-PROCESS ---
      pre_wp_mail chain now: lg-poller-mail-killswitch.php, Plugin.php
 ok   containment removed, killswitch STILL REGISTERED — this process now has exactly
      live's pre_wp_mail chain
      ran=yes swallowed=no wp_mail_returned=true
 ok   THE KILLSWITCH DOES NOT SWALLOW A DIGEST SEND: the probe passed the whole chain.
```

Scenario B is engineered, because dev2 always has containment: the callback is removed
**from that process only** (no file touched, nothing persisted), PHPMailer is repointed
at mailpit *first*, and the probe address is `…@dev2.invalid` — RFC 2606 guarantees it
can never resolve, so even a total escape reaches no person. The run asserts the
killswitch is *still registered* afterwards, or scenario B would be testing an empty
chain and proving nothing.

⚠️ **The probe owns a `wp_mail()` call, so it enforces the allowlist itself** and refuses
any address the digest would refuse. Otherwise the tool built to inspect the wall would
be a door through it. The gate now sweeps **every** file this lane owns for `wp_mail()`
call sites, not just the sender — it was blind to this one until it was told to look.

---

## 5. ⚠️ What your live recap actually contains — it is ONE reply

Read from live 2026-07-31. You follow **11 topics**; replies by other people in them:

| Window | Content |
|---|---|
| last 7 / 14 / 30 / 60 days | **1 reply, 1 thread** |
| last 90 days | 2 replies, 2 threads |

The one reply is **James Huntley** in **"Neck gap at heel"**, 2026-07-28 14:28 UTC.

So `--since=2026-07-20` produces a genuine one-reply digest — subject *"One new reply in
a discussion you follow"*, which is the n=1 wording. **This is real, and it is not seeded
or borrowed.** It is also small, and that is the honest state of your live follow list,
not a fault in the batcher. To see a fuller digest you would need `--since` back past
2026-05-02, and it still only reaches two replies.

---

## 6. What to check afterwards

**The safety proof — fully checkable, and it is the one that matters:**

- Step 1/3 print the resolver's output before anything sends; it must say `daily due
  after the allowlist: 1` and nothing else. The script exits rather than sending if any
  other uid appears.
- Nobody else on live holds a batched cadence at all — checked at run time and printed.

```bash
# independent confirmation, read-only
ssh live-ro 'mysql --defaults-file=/home/looth-ro/.my.cnf -N -B looth_import -e \
 "SELECT user_id, meta_value FROM wp_usermeta WHERE meta_key=\"lg_disc_email_cadence\""'
```

**The delivery proof — needs you:**

- `wp_mail()` returned without a `wp_mail_failed` error (the script asserts this).
- Your watermark advanced to exactly the newest reply included (the script prints it).
- **The mail is in your inbox.** That is the real proof.

**⚠️ There is no SES MessageId to hand back, and that is not an oversight.** The real
pipeline is `wp_mail()` → FluentSMTP → SES, and FluentSMTP only records the SES response
when its logging table exists — it does not, on live or dev2 (no `wp_fluentmail_*`
tables). The 7/31 email produced a MessageId because it called SES **directly, bypassing
`wp_mail`** — a different code path from the one under test, and the reason that send
could not answer "does the batcher work?". Getting a MessageId here would mean testing
the wrong thing.

**"mailpit shows zero":** mailpit is **not installed on live** (verified: inactive, no
listeners on 1025/8025), and `lg-dev-mail-containment.php` is **not** symlinked into
live's mu-plugins — re-verified over `ssh live-ro` on 2026-07-31, listing live's 39
mu-plugins directly. So there is nothing on live that could swallow a send and report
success.

That is a *structural* argument, though, and the script no longer relies on it: it runs
the §4a probe at run time and refuses if this send is actually intercepted — by anything,
including something added after this was written. **What it no longer does is refuse
merely because a filter exists**, which is what stopped Ian's 7/31 run and would have
stopped every future one.

**Live's mail chain, read read-only 2026-07-31:**

| | |
|---|---|
| `lg-dev-mail-containment.php` | **absent** — only the missing symlink protects live's mail |
| `lg-poller-mail-killswitch.php` | present, registered (`lgms_poller_mail_enabled` absent), **selective — ignores non-poller mail** |
| `Plugin.php` poller mail-gate | present, selective by the same backtrace test |
| `enabled` (tracked config) | `false` |
| `lg_fd_send` in live's cron array | **absent** — nothing is armed |
| members holding a batched cadence | **0** |

That last row is worth pausing on: **even if every guard failed open at once, the
resolver has nobody to return** — no member on live holds `daily` or `weekly`. The only
account that can receive anything is the one you arm in step 3.

---

## 7. If it works, what comes next — and what it costs

Turning the digest on for real means setting `'enabled' => true` in
`platform/config/follow-digest.php` and widening `allowlist`. Both are one-line tracked
diffs that arrive by pull. Three things happen at once, and they are worth saying out loud:

1. The hourly `lg_fd_send` cron arms — **see §3 about 12:00 UTC vs the Monday 13:00 UTC
   weekly recap.**
2. The Daily/Weekly control becomes visible to the allowlisted members, and **only** to
   them. It is per-member on purpose: switching the sender on for one person must not
   paint a control the other members can use but the sender would not serve.

   ⚠️ **NOT YET — and this row was wrong until 8/1.** The sender's half of that is
   correct and per-member (`lg_fd_cadence_ui_enabled()`), but the account page gates the
   control on its **own second switch**, `LG_FOLLOWING_CADENCE`
   (`manage-subscription.php:409`), defined from `$_SERVER` in
   `membership-pages/lib/following-data.php:145` and **set in no tracked config, no nginx
   conf and no fpm pool on either box**. So the control currently renders for *nobody*,
   including the allowlisted account. See §8.
3. `allowlist => 'all-members'` is the only value that reaches the membership, and the
   gate goes **RED** on it by design — so general release changes the gate in the same
   commit. That is intended: one visible, reviewable diff rather than a silent widening.

**Nothing above happens as a side effect of the §4 test.**

---

## 8. The footer promise, and the seam account-following owns

**Ian, 8/1:** *"the link to change frequency doesn't seem to have a setting on the manage
account page."* He was right. This is the UI-lies class in its worst venue — a **sent
email**, which cannot be edited after it leaves.

### What was wrong

The footer linked **"Change how often"** → `/manage-subscription/`. The frequency control
on that page renders only under `LG_FOLLOWING_CADENCE`, which is set nowhere on either
box. Every recipient who clicked it landed on a page with no such setting.

### Why the second switch is itself the bug

`lg_fd_cadence_ui_enabled()` is documented as **the single source of truth**, precisely so
"the control cannot become visible while the thing that honours it is off, because there
is only one switch." It is already **per-member allowlist-scoped**, so it asks the
question that matters: not *is the feature on* but *is it on for you*. The account page
checks its own condition instead — the exact drift that comment predicted.

### Why we did NOT just turn `LG_FOLLOWING_CADENCE` on

Turning it on globally paints Daily/Weekly for the **whole membership** while the sender
serves only the allowlist. Any member picking Daily would get a cadence written, their
instant mail suppressed, and then be blocked at the send layer — **receiving nothing from
a control they had just used.** That is the §15.4 lie, and the allowlist would have
*caused* it rather than prevented it. It is not merely "needs Ian's ok" — it is unsafe
while the allowlist is on.

### What shipped instead (already on the branch, no approval needed)

The footer promises the control **only when it actually renders for that member**. Today
that is nobody, so the footer reads **"Manage your discussion emails"** — a *different
claim that is true*, not a hedge on the same one. That page really does carry the ✉
discussion-email toggle, the followed-thread list and **Stop all**. **The link never
moved; only the dead promise went.**

The switch is one tracked constant, `LG_FD_CADENCE_CONTROL_SHIPPED`, living with the store
in `platform/mu-plugins/lg-follow-digest.php`, default `false`, consulted per-member
through `lg_fd_cadence_control_reachable()`.

> It deliberately does **not** read the page's constant. `$_SERVER` is empty under WP cron
> (`lg-wp-cron.service` carries no `Environment=`), so the sender's answer would be "off"
> whatever the page did — accidentally right today and **permanently wrong the day the
> control ships**.

### The handoff — account-following owns this, one line

```diff
- <?php if (LG_FOLLOWING_CADENCE): ?>
+ <?php if (function_exists('lg_fd_cadence_ui_enabled') && lg_fd_cadence_ui_enabled()): ?>
```

…and **in the same commit** flip `LG_FD_CADENCE_CONTROL_SHIPPED` to `true`. Then the
control renders for exactly the members the sender serves (today: Ian alone) and the
footer wording returns to "Change how often" on its own.

### It is gated as a biconditional, both directions

Because "don't promise what isn't there" alone lets the **opposite** defect ship in
silence — the control goes live and the footer keeps the generic wording, so the member
never learns the setting exists.

| tree state | gate |
|---|---|
| footer promises a frequency control, constant `false` | **RED** — promises a setting that renders for nobody |
| constant `true`, footer never mentions it | **RED** — the control ships and the digest never says so |
| both agree | green |
| constant renamed / unreadable | **CANNOT RUN** — never a quiet "off" |

The switch is **lexed, not grepped**: its name appears 3× in the plugin's own comment
explaining why it is off, so `grep -q` matches the prose and reports ON. Only the
`define()`'s captured value is consulted.

**Red-first, proven both ways:** run bare (serve WP = main's mu-plugins) the gate reports
*"the digest promises a setting that renders for NOBODY — anchor 'Change how often'"*; run
`--plugin` against this branch it is green. Two builds, one assertion, opposite verdicts.
