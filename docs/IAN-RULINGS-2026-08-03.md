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

Before flipping it, re-run the measurement that caught the black hole rather than
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
