# Work finished, but not deployed

Keeper maintains this at every merge. Rule (Ian 2026-07-29): he gets reminded
whenever this file is non-empty. "Deployed" means live is actually running it.

## Waiting on Ian's `lg-deploy` — ⚠ HOLD, do not pull yet

Main is ahead of live with tonight's merges: **events-mobile** (mobile tap
navigates — Ian-verified on dev2), **thread-follow** (follow toggles,
default-off), the gate harness generation, and the buck-conf disclosure fix.

**HOLD because:** thread-follow has Ian-reported defects on the hub feed card
(dead buttons, two glyphless squares, rough mobile layout) — member-visible if
pulled now. The lane is fixing on its branch. When its fix merges and Ian
re-passes dev2, ONE lg-deploy carries everything, plus:
- thread-follow's DB migrations must run live-side in the same window (keeper
  stages the exact commands here before the pull).

## Staged scripts awaiting Ian's hands

- (none)

## dev2 serve (keeper's)

- Current with main (e84dae7), baseline re-cut. All mirror repairs complete on
  live; reconcile-service failure still undiagnosed (journalctl owed by Ian).

## Cleared (2026-07-29→30 highlights)

- Slug backfill APPLIED live: 1,634 → 140 patreon URLs, 1,496 living 301s.
- All mirror repairs run + verified (purge 24, rewind, 2 replies restored).
- 30/30 duplicate pairs merged + verified; alarm + email notice live.
- Accordions + connection-confirm deployed, Ian-verified.
