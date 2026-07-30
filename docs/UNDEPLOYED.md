# Work finished, but not deployed

Keeper maintains this at every merge. Rule (Ian 2026-07-29): he gets reminded
whenever this file is non-empty. "Deployed" means live is actually running it.

## Waiting on Ian's `lg-deploy` — tonight's merges

Main is ahead of live: events-mobile, thread-follow (mobile long-press fix +
feed-card fixes), slug tooling, gate harness. Follow controls need a live DB
migration IN THE SAME WINDOW as the pull:
- **forums.topic_follow table** — staged by thread-follow at
  ~/lane-outbox/thread-follow-LIVE-topic-follow-*.sql (forward + ROLLBACK).
  Run as `sudo -u bb-mirror psql -d looth` on LIVE. Without it, follow toggles
  render but can't persist (the exact dev2 symptom, now fixed on dev2 because
  keeper is applying the table there).


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
