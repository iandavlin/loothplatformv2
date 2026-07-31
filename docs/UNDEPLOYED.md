# Work finished, but not deployed

Keeper maintains this at every merge. Rule (Ian 2026-07-29): he gets reminded
whenever this file is non-empty. "Deployed" means live is actually running it.

## Waiting on Ian's `lg-deploy` — ⚠ HELD by Ian for the consolidation modal

Ian 7/30: "add that modal to follow notifs/email/save before we ship to live."
So the whole follow/edit/signup deploy WAITS until thread-follow builds the
consolidated action-row modal (notify+email+frequency+save behind one control).
Mocks → Ian picks → build → then ONE deploy carries everything.

Main is ahead of live: events-mobile, thread-follow (mobile long-press fix +
feed-card fixes), slug tooling, gate harness. Follow controls need a live DB
migration IN THE SAME WINDOW as the pull:
- **forums.topic_follow table** — staged by thread-follow at
  ~/lane-outbox/thread-follow-LIVE-topic-follow-*.sql (forward + ROLLBACK).
  Run as `sudo -u bb-mirror psql -d looth` on LIVE. Without it, follow toggles
  render but can't persist (the exact dev2 symptom, now fixed on dev2 because
  keeper is applying the table there).
- **`membership` Postgres role on LIVE** — needed by Manage Account's
  "Discussions you're following" (account-following lane). SEPARATE from the
  migration above and NOT covered by it: that script grants `looth_ro` only,
  while this section runs on the `membership` FPM pool, which authenticates by
  unix-socket peer auth and so needs a role of its own name. Read-only:

  ```sql
  CREATE ROLE "membership" LOGIN;
  GRANT CONNECT ON DATABASE looth TO "membership";
  GRANT USAGE ON SCHEMA forums TO "membership";
  GRANT SELECT ON forums.topic_follow, forums.topic, forums.forum TO "membership";
  ```

  Applied on dev2 2026-07-30 and verified read-only there (DELETE on
  topic_follow denied; SELECT on forums.reply denied). Rollback:
  `DROP OWNED BY "membership"; DROP ROLE "membership";`
  ORDER: run it AFTER the topic_follow migration — granting SELECT on a table
  that does not exist yet is an error, and the table is created above.
  Without it the section still renders and "Stop all" still works, but it
  cannot show WHICH discussions they are.


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
