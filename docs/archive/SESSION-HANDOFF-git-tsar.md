# Git-tsar session handoff — 2026-06-04

Role + rules: `docs/briefing-git-tsar.md`. You own worktrees, branches, merges to `main`, pushes (Ian-signed). Buck branches belong to the Buck sub-coord — don't double-own.

## Done this session
- Stood up 5 worktrees under `/home/ubuntu/worktrees/` (branches cut from `main` @ 2b99f48):
  | lane | path | branch | uncommitted changes moved in |
  |---|---|---|---|
  | archive-poc | worktrees/archive-poc | lane/archive-poc | 27 (stream/likes/download/comments + tools/perf) |
  | lg-layout-v2 | worktrees/lg-layout-v2 | lane/lg-layout-v2 | 24 |
  | membership-pages | worktrees/membership-pages | lane/membership-pages | 11 (+ platform/mu-plugins/lg-membership-chrome.php) |
  | posts | worktrees/posts | lane/posts | 2 (new-posts/f5-mandolin + tools/article-parse.php) |
  | bb-mirror | worktrees/bb-mirror | lane/bb-mirror | 0 — HELD (tangle) |
- Work was **relocated uncommitted** (lanes commit in their worktree per cadence). Transfer = `git stash push -u -- <paths>` in main → `stash pop` in worktree. For lane-owned subdirs (archive-poc) `main` cleanup needed `git reset` + `git cat-file -p HEAD:$p | sudo tee $p` + `sudo rm` (see memory `project_shared_tree_lane_owned_subdirs`). NEVER `sudo git` in this repo.
- Pushed `main` 9778151..d3801f7 (11 commits: user-lifecycle teardown + archive-poc events/taxonomy + article parser + docs) on Ian's review-before-push sign-off.

## Open / next
1. **bb-mirror hand-separation** (blocked): 3 interleaved lanes in web/{forums.js,forums.css,_chrome.php} + forums/{_feed,_reply-render}.php. Need each sub-lane to confirm dev-tested, then hand-separate + commit in order: compact-view → posting-gate → hub-UI. Ref: `docs/coord-bb-mirror-hub-ui-commit-blocker.md`.
2. **dash-theme.json** (`archive-poc/standalone/dash-theme.json`): owned/live-edited by `looth-dev`, swept into lane/archive-poc by broad pathspec but isn't stream work. Re-dirty in `main` (live editor). Needs an owner/lane decision — likely pull out of lane/archive-poc + give looth-dev its own lane.
3. **0842006 profile-public-pro-gate**: Ian APPROVED (privacy gate). Buck sub-coord cherry-picks onto local `main`; push is a SEPARATE later step (git tsar pushes on review-before-push).
4. **Lane "work in your worktree now" relays** still need to land with archive-poc / lg-layout-v2 / membership-pages / posts (via Ian) so they stop editing `main`.

## main leftovers (intentional)
`.claude/settings.local.json` (local), `docs/briefing-*.md` + `docs/handoff-conversion-2026-06-04.md` (coord/docs), bb-mirror/** (held), dash-theme.json (flag #2).

Note: stray worktree `/tmp/pg-test-wt` (detached HEAD) exists — not git-tsar's; leave unless asked. `bk/*` branches are Buck's.
