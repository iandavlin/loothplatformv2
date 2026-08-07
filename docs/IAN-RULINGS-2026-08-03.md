# Ian's rulings — 2026-08-03

Four decisions taken in one sitting, plus one defect they surfaced. Recorded here
because they existed only in a chat transcript, and a lane that cannot quote the
decision it is building against will re-litigate it or guess.

Quote the relevant line in the commit body when you act on one of these.

---

## 1. Weekly recap scope — **"What you missed" (unread only)**

Ian chose to KEEP the current unread-only behaviour over time-windowing it.

He chose this having been shown the argument against, so do not re-open it as
though it were an oversight. It is an editorial call about what the word "recap"
means, and it is his to make.

### ⚠️ The defect it leaves open — NOT ruled on, and still real

`webroot/bottom-nav.js:1125` fires `markAllNotifsRead` 700ms after the mobile
notification sheet renders, POSTing `{action:'read_all'}` — **every** row, not the
visible eight. Under unread-only that empties the recap, and under "empty means send
no email" it cancels the member's digest entirely.

So the member most engaged with the bell is the member most reliably unmailed: a
weekly recap inversely correlated with engagement. That is also why Ian's own recap
came up empty — not a bridge defect and not a renderer defect.

The protection already exists one arm over: `Recap.php:110-113` refuses to consult
`is_read` for connection requests for exactly this reason. It was written and never
extended to hub rows.

**Ian picked the framing, not the bug.** The offered option that paired unread-only
WITH fixing the timer was not the one taken, so the timer stands. Filed as backlog
item 4.1. Fixing it makes his chosen framing behave the way the label promises.

---

## 2. DM emoji picker — **Variant 1**

From the two mocks at `/footer-mockups/emoji-picker/`. Branch `emoji-picker` holds
the mock (`ae29c0e`); no implementation yet.

⚠️ Confirm which variant the page labels "1" before building — the ruling is the
label Ian saw, not an index into any array.

---

## 3. Front-end compose — **Option A, single screen**

The lane's own recommendation, over Option C (the 3-step wizard approved for
discussions on 7/30). Mock at `/footer-mockups/frontend-compose/`, scope in
`docs/FRONTEND-COMPOSE-SCOPE.md`, branch `admin-edit-any`.

Note the re-scope that precedes this: Ian, earlier — *"I can currently edit on the
front end. That is fine. I need to be able to COMPOSE on the front end with a easy
front end form."* Editing was never the problem.

---

## 4. Follow-digest allowlist — **widen to all members** (ordered; NOT yet done)

Ian ruled widen. Measuring before flipping found the flip would have **silenced
follow email for all 1824 accounts** — suppressed by one code path, invisible to the
other. Three defects fixed in `f0943d6`, live at `0e80c5b`.

**The widening itself has NOT been applied.** It remains a one-line diff:

```php
// platform/config/follow-digest.php
'allowlist' => 'all-members',      // currently '1:ian.davlin@gmail.com'
```

### ✅ VERIFIED END-TO-END ON LIVE, 2026-08-07 — the sender is not the risk

Ian said email "didn't seem to be working". It was. Proven on live, in order:

  - `wp_mail()` -> Amazon SES delivers. Test message arrived in his inbox.
  - Resolver finds him: `daily due: 1`, and only him.
  - Content query found 4 qualifying replies (authors 627/160/665, his own excluded).
  - `[lg-fd] daily flush: 1 sent`, and **Ian confirmed the email arrived**.
  - Watermark advanced to `2026-08-07 17:42:10` — the timestamp of the LAST INCLUDED
    reply, not `now`, so nothing falls through the gap between query and send.

Two things that looked like bugs and were not:
  - A manual `wp cron event run lg_fd_send` sends NOTHING outside the window.
    `lg_fd_tick()` only flushes when the local hour equals LG_FD_DAILY_HOUR (8, i.e.
    08:00 America/New_York). The Aug 4 send was 12:58 UTC = 08:58 local. To test off
    -schedule call `lg_fd_flush('daily', 0)` directly.
  - `sudo wp --allow-root` FAILS: Postgres is peer-authed and `root` has no role, so
    the link store is unreachable. The sender then REFUSED to send and left the
    watermark unadvanced — the guard working. Run as `looth-dev`, the same user
    lg-wp-cron.service uses.

### ⚠️ THE REMAINING PRE-WIDENING BLOCKER: there is no delivery record

`fluentmail-settings` has `log_emails => yes` and `simulate_emails => no`, but the
table FluentSMTP logs INTO does not exist — only `wp_fluentform_*` tables are
present. So logging is on, writing nowhere, and silently.

With the allowlist pinned to one address that is survivable: "did it send?" is
answerable by grepping syslog for `[lg-fd]` and asking Ian. **At 384 members it is
not.** A failed run and a quiet run look identical.

Scope, measured: 384 members hold `wp__bbp_subscriptions`, 79 hold
`wp__bbp_forum_subscriptions`. Widening moves them from BuddyBoss per-reply mail to
one batched email at the weekly default — quieter, not louder, but a change none of
them asked for.

Also note the sender has a hard dependency on Postgres being reachable from the
sending process. At one recipient a failure is one delayed email; at 384 the whole
run refuses — correctly, but invisibly unless someone is reading syslog.

### Before flipping

Re-run the measurement that caught the black hole rather than
trusting that the fix held — under an all-members allowlist, a member with no
explicit cadence must come back BOTH suppressed AND due:

```bash
sudo -n env LG_FOLLOW_DIGEST_ALLOWLIST=all-members wp eval '...'   # see f0943d6 body
```

⚠️ `tools/gates/follow-digest-gate.py` asserts this file does NOT contain
`all-members`. That tripwire is deliberate. Flipping it will turn the gate red, and
the correct response is to convert the assertion into "all-members requires a
recorded decision" — citing this file — **not** to delete the check.

---

## Standing note on gate colour

`tools/gates/run-all.sh` currently ends RED on a **pre-existing** failure:
`FAIL finder/anon (2 violations, imgs 289KB)` — backlog 13.5, an image-weight
violation dating to Nov 2024. All 14 other gates are green.

Do not read that red as "this branch broke something", and do not read it as
permission to push past a red either. Check WHICH gate before either conclusion —
the suite prints per-page verdicts, and `hub/anon` and `hub/member` both pass.
