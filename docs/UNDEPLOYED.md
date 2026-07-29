# Work finished, but not deployed

Keeper maintains this at every merge. Rule (Ian 2026-07-29): he gets reminded
whenever this file is non-empty — see it, clear it, feel good. Newest on top.
"Deployed" means live is actually running it / the script actually ran.

## Waiting on Ian's `lg-deploy` (one pull clears the section)

- (section clear — Ian should run one `lg-deploy` to print state: it will either
  say already-up-to-date or land safe doc/tooling riders.)

## Staged scripts awaiting Ian's hands (not pull-shaped)

- **Mirror attachment purge** — `sudo -u bb-mirror psql -d looth -v ON_ERROR_STOP=1 -f bb-mirror/deploy/2026-07-28-attachment-purge-live.sql`
  (24 orphan rows; rollback file beside it; already on live via this morning's pull).
- **Reconcile bookmark rewind to 2026-06-01** — one statement, fixes 5 drifted
  rows incl. the miscredited post; command in the mirror-orphans handoff.
- **Re-materialize replies 71678 + 71723** — the two members' answers nobody
  ever saw; `bb-mirror/bin/rematerialize.php` per its header.

## dev2 serve (keeper's window, not Ian's)

- dev2's serving checkout is at `0995f2b`; main is ~30 commits ahead (all of
  today's merges). Keeper pulls in a coordinated serve window — parked until
  the working lanes don't have in-flight verification against the serve.

## Cleared

- 2026-07-29 22:33: **Minton merged + verified on live** — 30/30 ruled pairs now
  applied; twin drained, survivor holds both halves, duplicate signature 0.

- 2026-07-29 morning `lg-deploy` (57a9ee7): duplicate alarm, member email
  notice, profile email-change hook + nginx reload, merger toolkit, orphan
  delete-path fix. 2026-07-29 14:21: the 29-pair merge applied + verified.
