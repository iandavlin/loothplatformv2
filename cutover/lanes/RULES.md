# Lane Operating Rules — read this FIRST, every lane, every session

The "how to work without breaking stuff" manual. If you are a lane chat, read this
and your charter (`cutover/lanes/lane-<you>.md`) before touching anything.

## 0. Where you are
- `curl ifconfig.me` → **50.19.198.38 = you ARE the dev box.** Act locally with `sudo`.
  NEVER `ssh` to "dev" / dev.loothgroup.com / claude.loothgroup.com — that's a loopback to yourself.
- `54.157.13.77` is LIVE production — a different box you cannot reach from here. Don't try.

## ⚠️ DANGER — destructive commands (incident 2026-06-13)
- **`wp user delete` is an IRREVERSIBLE CROSS-STORE NUKE on this box.** It fires the `deleted_user`
  hook → `UserLifecycle::teardown(NUKE)`, erasing the user across WP + profile-app PG + BuddyPress +
  discovery. A lane testing the poller nuked real user 1000 this way (a `UID=$(...)` trap — `UID` is a
  read-only bash builtin, so it stayed `1000` and the delete hit a real human). Recovered from backup,
  but: **NEVER use `wp user delete` for test cleanup — tear down test users with direct SQL only.**
  Same warning on live. Make scratch users in a throwaway DB, or clean up by SQL, never via WP hooks.

## ⚠️ COMMIT ROUTING — where YOUR commits go (incident 2026-06-15: brothers clobbering `main` + each other)
- **Each lane commits to ITS OWN branch — NEVER `main`.** Hub = `bespoke-cutover`. Every other lane =
  its own `lane-<name>` branch (create one if you don't have it). Commit small by pathspec, push **only
  your branch**, report the SHA to the coordinator. Your work is not lost — it's on your branch.
- **`main` is PRODUCTION — the cut tracks it. DO NOT push to `main`.** Only the **coordinator**
  consolidates lane branches → `main`, deliberately + audited (the A.2 pattern: merge in a clean
  throwaway worktree, gates GREEN, fast-forward only, tag a `pre-*` rollback anchor first). A lane
  pushing straight to `main` is what broke this — `main` jumped commits behind everyone's back.
- **Do NOT push to shared branches you didn't create** (`dev-snapshot-*`, `handoff-*`). Not yours.
- **`cutover/lanes/HANDOFF.md` has ONE owner — the coordinator.** Do NOT co-edit it; concurrent edits
  clobber. Got an update? *Report it* to the coordinator; don't touch the file.
- **No push without Ian's review** (present commits + diffstat). Gates green first. This is absolute.

## 1. Stay in your lane
- Edit ONLY the paths your charter lists as yours. Everything else is **READ-ONLY**:
  other lanes' `/srv` apps, the bb-mirror worktree, `/var/www` WP, `/etc/nginx`, `tools/gates/run-all.sh`.
- The SERVED tree is not always the obvious one:
  - **bb-mirror** is served from `/home/ubuntu/worktrees/bespoke-cutover/bb-mirror` (via `/srv/bb-mirror`), **NOT** `projects/bb-mirror` (older copy).
  - archive-poc / profile-app / lg-shared are served via `/srv` symlinks into `projects/`. Edit the served path.

## 2. Docs lie. Gates are truth.
- Do NOT trust `.md` / SESSION-HANDOFF / CHECKLIST / briefing files — they drift. The audit that
  created these lanes ignored them on purpose. **Your charter + the gates are the source of truth.**
- If you change behavior, encode it as a GATE, not prose. CRAFT law (docs/CRAFT-STANDARD.md):
  a defect class found twice MUST become a gate before the second fix.

## 3. Commit + push discipline
- Commit in clean, **TESTED** increments. **Commit ≠ push.** Don't stack a big uncommitted pile.
- Before ANY push: run `tools/gates/run-all.sh`. **RED = DO NOT PUSH.**
- RED is absolute — even if "the red isn't mine." Never push over an unrelated red (it trains
  everyone to ignore RED). Escalate to the coordinator to get it green.
- **Never push without coordinator + Ian commit review.**
- git on lane-owned subdirs (owned by archive-poc / looth-dev / www-data): `sudo git` half-fails.
  Clean main via `git cat-file | sudo tee` + `sudo rm` — never `sudo git`.

## 4. Cross-lane = escalate, never freelance
If your change touches a shared contract, a shared file, another lane's data, or has an ORDERING
dependency — STOP and report to the coordinator. Known shared seams:
- **The login-identity chain (`_looth_uuid`):** poller writes/backfills `_looth_uuid` → THEN
  profile-auth.php consumes it. Reverse the order → fresh logins mint nothing → **SITEWIDE LOGIN BREAK.**
- **The canonical header:** `/srv/lg-shared/site-header.php` is the ONE header. No lane forks it;
  consumers only populate `$ctx` from `/whoami`.
- **/whoami contract:** tier is server-side from the poller; the `lg_tier` cookie is DISPLAY-ONLY,
  never a gate; anon fails closed.
- **run-all.sh:** coordinator-owned. Lanes write gate scripts; the coordinator wires them. A flaky
  gate stays OUT of the runner until hardened.
- **Shared composer markup (`#ntm-form`):** desktop + mobile read the same DOM — announce changes.

## 5. Don't break the gate suite
- The gates that hit live HTTP/CDP (craft-gate, forum-visibility, anon-leak) **flake under load** and
  under the new `/whoami` rate-limit (their own SSR calls come from loopback and self-throttle).
  Don't run them in tight loops. If one flakes RED, re-run once standalone before believing it.

## 6. Report back (paste to coordinator)
```
LANE: <name> | STATUS: <in-progress | blocked | ready-for-review | pushed>
DID: <finding + one line each>
COMMITS: <sha — subject — diffstat>   GATES: <run-all result; gate added?>
BLOCKERS / SCOPE-Qs: <for coordinator/Ian>   LEFT: <what remains>
```
