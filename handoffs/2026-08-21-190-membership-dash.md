# 190-membership-dash — one membership dash, tester tab first

**Branch** `190-membership-dash` · **HEAD** at write time `36153d2` (+ this commit)
· pushed, 0 ahead of upstream · **Gate 90** (93 assertions), **red-first 35/35**
· **Gate 85** re-run GREEN (116).

Ian approved the tester tab from the screenshots: **"That works awesome."**

---

## What shipped

**1. The Testers tab** (the existing `stripe_cohort` tab, relabelled — the slug
stays, because three redirect helpers address it by that name). The unlock link
in full with a Copy button, Rotate, Turn off, and state in plain words, sitting
directly above the invite panel and the test-group list, which are unchanged.

**2. The dash is top-level** with its own icon, per Ian's ruling. The old
Settings URL redirects rather than dying.

**3. Affiliates folded in** — it was a second top-level menu in the same file.
Its old URL redirects too.

---

## The three things worth not re-deriving

### The store had to be split in two, and both constraints were measured

Rotate has to write the unlock's hash. Neither obvious home could take it:

* `platform/config/` in the serving checkout is `ubuntu:ubuntu 0755`, and
  WordPress runs as FPM pool **looth-dev** — the dash **cannot** write
  `tester-unlock.local.php`, where #180 put the hash.
* The hash **cannot** become a `wp_option`: `lg-shared/tester-unlock.php` is
  required by `site-header.php`, which renders on **seven apps under seven
  different unix users and has no database at all**. They share no group either,
  so the store must be plain world-readable. That is why #180 used a file, and
  it is still true.

So: **raw token → `wp_options`** (for the dash to show), **`sha256` + `enabled` →
`/srv/lg-shared-state/tester-unlock.json`** (for the seven apps to read). JSON
rather than PHP, because a web-writable file that seven apps `include` is RCE
across all seven. Outside the serving checkout, because that checkout only ever
pulls.

**With the state file absent the header renders byte-for-byte what `origin/main`
renders** — this change's equivalent of a flag defaulted OFF, and what makes the
merge harmless. Gate 90 §B9 compares the branch's resolver against main's across
three config shapes; gate 85's own 18-way `cmp` matrix still passes.

### Two orderings that look like detail and are not

The operator store is read **after** the `.local.php`, and Turn-it-off **writes
`enabled => false` rather than deleting the file**. Both for one reason: an
absent store applies *nothing*, and "nothing applied" on a box carrying an armed
`tester-unlock.local.php` — **which dev2 does right now** — means **still armed**.
Either mistake makes the button lie. Gate 90 §B7/§B7b assert it against a real
hand-placed box file.

### A menu promotion moves three things, and one fails silently

The registration, `PARENT_FILE` (now one constant behind seven redirect targets),
and the **enqueue hook prefix**. `settings_page_` never fires again once the page
is top-level, and the Welcome Email tab's media uploader just quietly stops
loading — no error anywhere. Nothing but a person clicking it would have caught
that. Gate 90 §G3 does now.

---

## What Ian sees

<https://dev2.loothgroup.com/footer-mockups/lg190-testers/index.html>

Rendered inside **real WordPress on dev2** — real options, the real cohort list,
real escaping — with the store pointed at a throwaway file, so dev2 itself was
never armed and the pictured link arms nothing.

**dev2 renders the `foreign` state**: the box is armed by keeper's hand-placed
`tester-unlock.local.php`, the dash holds no token for it, and a hash cannot be
turned back into a link — so it shows **no link at all** and says why. Rotate
takes ownership. A dash that showed a dead link there would have cost a confused
tester and an hour.

---

## Not reached — the honest list

* **The health panel.** Webhook-secret agreement between
  `lgms_stripe_webhook_secret` and the billing app's env; the same for
  `lgms_shared_secret`; test-vs-live mode; does the catalogue resolve to tiers;
  when a webhook last arrived. This is the part of #190 with the most
  operational value and it is **untouched**.
* **Other membership screens still have their own homes**: `UserLifecycleAdmin`,
  `MembershipGuide` (`add_options_page` `lgms-guide`) and `lg-patreon-onboard`.
* **Two member-facing links still point at the old Affiliates URL** —
  `membership-pages/web/affiliate-earnings.php:119` and
  `lg-patreon-stripe-poller/src/Wp/Shortcodes.php:6082`. They **work**, via the
  redirect; they were left deliberately so this diff never reached into
  member-facing files.
* **The invite panel puts its raw token in a redirect query arg**
  (`lgms_invite_link`), so it lands in the admin URL, browser history and any
  onward Referer. Observed while building; a different token and not this issue.
  The tester token deliberately does not copy that shape (gate 90 §E5).
* **The sidebar entry is still called "LG Member Sync."** Renaming it is Ian's
  call — the name is the vocabulary of MEMBERSHIP.md and every handoff.

## Nothing is owed operationally

No `.local.php` to place. `/srv/lg-shared-state` already exists on dev2 (keeper,
8/21). **Live has no such directory**, and there the tab correctly reports that
it cannot store a link and names the one-time fix, rather than offering a Rotate
button that half-works:

```
install -d -o looth-dev -g looth-dev -m 755 /srv/lg-shared-state
```

Live writes remain Ian's.

## Files touched

```
lg-patreon-stripe-poller/src/TesterUnlock.php        (new)
lg-patreon-stripe-poller/src/TesterUnlockPanel.php   (new)
lg-patreon-stripe-poller/src/Admin.php
lg-patreon-stripe-poller/src/Plugin.php
lg-shared/tester-unlock.php
platform/mu-plugins/lg-admin-tools.php
tools/gates/tester-dash-gate.php                     (new)
tools/gates/tester-dash-redfirst.py                  (new)
tools/gates/run-all.sh
docs/CRAFT-STANDARD.md
docs/domains/MEMBERSHIP.md
handoffs/2026-08-21-190-membership-dash.md           (this file)
```

All were in the approved plan except `Plugin.php` (its action link pointed at
the old Settings URL and would have died with the move) and this handoff.
