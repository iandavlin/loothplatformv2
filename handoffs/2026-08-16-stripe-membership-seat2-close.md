# stripe-membership seat 2 — close-out, 2026-08-16 night

**Branch `stripe-membership`, clean, 0 unpushed, current with main.**
Gates: committer **113** · relay **35** · board **165** · 34b **116** — all green.

> **READ THIS FIRST: BACKLOG 41 IS COMPLETE — all four parts.** A charter saying
> "continue 41" is stale. Verify before rebuilding: a fresh seat once rebuilt a
> finished, already-pushed feature from exactly this kind of lag.

| 41 part | State |
|---|---|
| (a) compact desk + modal bodies | **DONE** — one line per item (seat · type · snippet · age); body in the modal |
| (b) mechanical retirement | **DONE** — decision answered *after* the ask, seat's branch merged *after* the ask, or committed `desk_dismiss` |
| (c) hand-curation audit | **DONE** — `docs/BACKLOG-41-HAND-CURATION-AUDIT.md` |
| (d) done-ledger | **DONE** — `tools/keeper/board-done-ledger.php` writes `docs/DONE.md`; the board renders it |

## The one thing that makes 41(d) real, and it is NOT code

The ledger fires **only** on `Closes-Backlog: N` trailers in landed commits.
Without them it is correct and silent forever. **This branch already carries
trailers for 39 and 41**, so the first real entries write themselves at its
merge. Keep adding them at merge time or the file stays empty and the feature
quietly isn't one.

## Where the bodies are buried

- **Write before delete.** The ledger writes its `DONE.md` line *before* removing
  the `BACKLOG.md` index line. Worst case is a duplicate record, never lost work.
  Gated; swapping the order reddens it.
- **The timestamp guard.** A decision answered *before* an ask must not retire
  it, or an old ruling silently closes a fresh question — the desk would eat the
  newest asks from seats with history. Gated.
- **`.git` is a FILE in a worktree.** The ledger's `is_dir` check refused to run
  anywhere a lane works. Fixed to `file_exists`; the gate now creates a real
  worktree. *Any* new keeper tool has this trap.
- **Retired is marked, not deleted** — the snapshot carries retired desk items
  with their reason, so history shows what was dealt with.
- **The relay computes retirement once per pass.** It shells out to git; it was
  briefly running twice every 30 seconds on a two-core box.
- **Region renderers are shared** between first paint and live tick. Two
  renderers drift, and the first thing to drift is the empty/absent states, which
  carry the honesty.

## Outstanding, none of it blocking

1. **`Closes-Backlog:` merge discipline** — keeper's, above.
2. **Backlog 40's other two parts** — filter-space containment and the
   duplicate-canonical sweep. Only the 403 lead was mine and it is answered
   (`docs/BACKLOG-40-GOOGLEBOT-403S.md`): the 403s are **Cloudflare's, not ours**
   — origin never 403s Googlebot. Fix is Ian's dashboard.
3. **Live billing** — `docs/LIVE-BILLING-PREFLIGHT-2026-08-16.md`. Live has the
   same alias bug; the fix is **`lg-deploy`, not an edit** (that conf is a
   symlink into the pull-only checkout).
4. **Live arming** — `docs/LIVE-ARMING-RUNBOOK-2026-08-16.md`, measured state.
   The Phase 0 retraction is **already done**; do not re-run it.
5. **Invites ship OFF** — `wp option update lgms_stripe_invites_on 1` arms them,
   and only around an actual rehearsal.

## The lesson I would keep if only one survived

Roughly a dozen assertion bugs in my own gates tonight, **every one caught by
red-firsting rather than by reading**: seven matched prose instead of code, three
confused a name for an enforcement, one interpolated a variable into nothing, and
two mutations hit something adjacent to what they named. Also three corrections
to diagnoses handed to me — each measured before acting, and in two cases the
proposed fix would have changed working code.

**Verify the mutation landed where you think before believing what it tells you.**
