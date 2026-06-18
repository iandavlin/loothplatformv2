# Coordinator → cutover: Ian's decisions (2026-05-28)

Four decisions ratified. One is a plan rewrite.

---

## 1. Postgres: on-box ✅

Confirmed on-box. Migrate to RDS later if mobile load demands it. Step 1
provisioning commands can lock in against the new EC2 (see #2).

## 2. Cutover approach: blue-green, NOT in-place ⚠️ PLAN REWRITE

Ian's decision: **spin up a fresh EC2, build the full stack there, swing DNS
when ready.** "Relaxed" — no hard maintenance window. Old box stays up
through DNS propagation as natural fallback.

**What this changes:**

The current CUTOVER-PLAN.md is written as in-place surgery on live
(54.157.13.77): install postgres, deploy apps, flip nginx, etc. under a 4-hour
window with rollback steps. That model is obsolete.

**New model:**

1. Provision new EC2 (clone of dev box config — same nginx, PHP, postgres,
   systemd units)
2. Build + test the full strangler stack on the new box (profile-app,
   bb-mirror, archive-poc, poller plugin, all nginx routes)
3. Point DNS at new box when stack is verified clean
4. Old box remains live through propagation — natural rollback is "point DNS
   back"
5. Decommission old box after soak period

**Implications:**

- Most in-place rollback steps in the plan become "point DNS back" — much
  simpler
- The "maintenance window" pressure disappears — build can take days
- No `wp_loothgroup` DB migration surgery under live traffic — instead,
  export from old box, import to new box before DNS swing
- The "code-snippet #90 log-out-looth1 must disable" and similar live-state
  risks are now non-issues (new box starts clean)
- `pdo_pgsql` install, nginx snippet pattern, per-app secrets — all go on
  new box at build time, not under time pressure

**Rewrite scope:** CUTOVER-PLAN.md needs a v0.3 that reflects this model.
The prereq table (P1–P11) is largely unchanged — those are still things that
need to exist before DNS swing. The 13 steps need a rewrite from "in-place
surgery" to "new-box build + DNS swing."

Coordinator will not touch CUTOVER-PLAN.md — that's cutover chat's document.
This note is the spec for the rewrite.

## 3. User comms: skip ✅

Not needed. Remove step 10 comms wording from the plan. No banner, no email,
no Slack. DNS swing is the only user-visible event.

## 4. Cloudflare cache purge: skip at launch ✅

No CF API token at launch. Remove steps 3, 10, 12 CF purge commands from the
plan (or mark them as "manual via CF dashboard if needed post-swing"). On a
DNS swing to a fresh box, CF will miss naturally on first request anyway.

---

## What to do next

1. **Rewrite CUTOVER-PLAN.md to v0.3** — blue-green model. Keep the prereq
   table (P1–P11 are still valid). Rewrite the 13 steps as:
   - New EC2 provisioning
   - Full stack build + smoke on new box
   - DB export/import (wp_loothgroup + any sqlite → pg migrations)
   - DNS swing
   - Soak + decommission
2. Apply P3 owner fix (lg-shell), flip P9 ✅, memory cleanups — unchanged
   from prior reply.
3. Remove comms step. Remove CF purge steps.
4. When v0.3 is drafted, surface it for coord review.

— coordinator
