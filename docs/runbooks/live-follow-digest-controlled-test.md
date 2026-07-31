# Controlled live test — the follow digest, to Ian only

**Question this answers:** *"Does the batcher work?"* — not "did one email render", which
is what the 7/31 one-off proved. This exercises the **scheduled loop**: cron hook →
select by cadence → batch followed-thread replies since the watermark → mail the
recipient set. With the recipient set hard-locked to one person.

**Status: dev2 side is PROVEN. Live side is a set of commands for Ian — nothing has
been run on live, and nothing here changes live until he runs it.**

---

## 1. The short version

| | |
|---|---|
| What is new | A recipient **allowlist** that fails closed to NOBODY, enforced at the send layer |
| Tracked config | `platform/config/follow-digest.php` — arrives by `git pull`, no reload, no root |
| Value shipped | `enabled => false`, `allowlist => '1:ian.davlin@gmail.com'` |
| Live blast radius of the test | **One address.** The flag is set on the command line, so it is on for that one process — no cron armed, no member's page changes, nothing persists |
| Gate | `tools/gates/follow-digest-gate.py` — GATE 13/14, green in both flag states |

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

Everything below runs from `~/loothplatformv2-clean` on **live**.

**Step 1 — look, send nothing.** Prints the real resolver's output and what would go out.

```bash
sudo -u looth-dev env LG_FOLLOW_DIGEST=1 php platform/bin/follow-digest-live-oneshot.php
```

**Step 2 — give yourself a window.** You currently hold no cadence on live, and
`lg_fd_set_cadence()` stamps the watermark to *now* (the flood guard — it is what stops a
first digest being the entire history of every thread you follow). So a backdate is
required, and it is deliberately not defaulted:

```bash
sudo -u looth-dev env LG_FOLLOW_DIGEST=1 php platform/bin/follow-digest-live-oneshot.php \
  --arm --since=2026-07-20
```

**Step 3 — send it, to you.**

```bash
sudo -u looth-dev env LG_FOLLOW_DIGEST=1 php platform/bin/follow-digest-live-oneshot.php --send
```

**Step 4 — put your account back.**

```bash
sudo -u looth-dev env LG_FOLLOW_DIGEST=1 php platform/bin/follow-digest-live-oneshot.php --disarm
```

The script refuses to do anything unless: it is live (by `home_url()`, **not** `LG_ENV`);
nothing is intercepting `wp_mail`; the allowlist resolves to **exactly** `1:ian.davlin@gmail.com`;
and the resolver returns nobody but you. Any other allowlist — `1` unpinned, `1,7`,
`all-members`, malformed — and it exits without sending.

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
live's mu-plugins. So there is nothing on live that could swallow a send and report
success — the condition the mailpit-zero check exists to detect cannot arise. The script
re-checks `has_filter('pre_wp_mail')` at run time anyway and refuses if anything is
intercepting.

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
3. `allowlist => 'all-members'` is the only value that reaches the membership, and the
   gate goes **RED** on it by design — so general release changes the gate in the same
   commit. That is intended: one visible, reviewable diff rather than a silent widening.

**Nothing above happens as a side effect of the §4 test.**
