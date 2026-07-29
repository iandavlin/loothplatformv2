# Work finished, but not deployed

Keeper maintains this at every merge. Rule (Ian 2026-07-29): he gets reminded
whenever this file is non-empty — see it, clear it, feel good. Newest on top.
"Deployed" means live is actually running it / the script actually ran.

## Waiting on Ian's `lg-deploy` (one pull clears the section)

- (section clear)

## Staged scripts awaiting Ian's hands (not pull-shaped)

- (section clear — nothing staged)
- **Re-materialize replies 71678 + 71723** — the two members' answers nobody
  ever saw; `bb-mirror/bin/rematerialize.php` per its header.

## dev2 serve (keeper's window, not Ian's)

- dev2's serving checkout is at `0995f2b`; main is ~30 commits ahead (all of
  today's merges). Keeper pulls in a coordinated serve window — parked until
  the working lanes don't have in-flight verification against the serve.

## Cleared

- 2026-07-29 23:2x: **rematerialize RUN — Karl Borum's and Robert Owens' replies
  restored**; keeper verified both stores agree (71484: 9/9, 71649: 4/4).
  ALL mirror-orphans repairs now complete on live.

- 2026-07-29 23:1x: **attachment purge RUN on live (DELETE 24, triggers
  installed)** and **bookmark rewind RUN** (was 1785366600 → 1780272000; that
  old value is the rollback number). Timer re-walk fixes the drift.

- 2026-07-29 late: **chip accordions + connection-remove confirm DEPLOYED to
  live** — Ian ran the pull and verified both working. Live current with main.

- 2026-07-29 22:33: **Minton merged + verified on live** — 30/30 ruled pairs now
  applied; twin drained, survivor holds both halves, duplicate signature 0.

- 2026-07-29 morning `lg-deploy` (57a9ee7): duplicate alarm, member email
  notice, profile email-change hook + nginx reload, merger toolkit, orphan
  delete-path fix. 2026-07-29 14:21: the 29-pair merge applied + verified.
