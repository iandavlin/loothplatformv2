# LANE-RULES.md

Rules for any agent working a lane in this repo. Short on purpose. If a rule here
conflicts with something you were told in-session, stop and ask.

## What the tooling already handles

`spin-lane` sets these up. Don't work around them:

- Your worktree folder is named for your branch. They always match.
- Your branch is pushed to GitHub the moment the lane opens, so your work exists in
  two places from the first commit.
- Your git identity is set per lane, so commits are attributable.

## Where you work

- Work **only** inside your own worktree. Other worktrees belong to other lanes.
- Never `git checkout` a different branch inside your worktree. The folder name would
  stop matching the branch, and config symlinks elsewhere on the box read from the
  main checkout.
- Before your first action, run `git status` and confirm the branch is the one you
  were assigned. If it isn't, stop and report it.

## Before you write code

- Write the plan first. Do not touch code until Ian approves it.
- Every plan includes a **Files I expect to touch** section. This is how overlap with
  other live lanes gets caught before it happens, so guess wider rather than narrower.
- If the plan turns out to be wrong once you start, stop and say so. Don't quietly
  adopt a different plan than the one that was approved.

## While you work

- Commit as you go and push each commit. Don't batch a day's work into one push.
- Run `git diff --name-only main...HEAD` before you finish. If it includes files that
  weren't in your plan, flag them in your report.
- Never edit anything under the config directory without saying so explicitly. Config
  is symlinked out to live services from the main checkout.

## Things you do not do

- **Don't rebase.** If your branch has drifted far behind main, report the number and
  stop. A branch hundreds of commits behind gets re-cut from current main, not
  rebased, and that's a decision for Ian.
- **Don't create backup branches.** No `pre-rebuild`, `pre-reset`, `pre-anything`. If
  you feel the need for one, that's the signal to stop and ask instead.
- **Don't delete branches or worktrees**, including your own, including after a merge.
  Cleanup is a sweep Ian authorises.
- **Don't force-push.** Ever, on any branch.
- **Don't merge your own lane to main.**

## When you finish

Report: branch name, commit count, files touched, and anything you noticed but
didn't fix. Then stop. Leave the worktree where it is.

If the lane is being **stood down** instead of finished, the final commit subject
starts with the exact prefix `STOOD DOWN: ` — capitals, colon, space. The status
tooling keys on that prefix; any other phrasing reads as a live lane forever.

If the lane is being **parked** — branch kept, seat freed, work to resume later —
the final commit subject starts with the exact prefix `PARKED: ` (capitals, colon,
space) followed by the reason. Same rule: the tooling keys on the exact prefix.
Parking is a keeper/Ian action done with `spin-lane.sh park <lane> "<reason>"`,
which refuses dirty trees and pushes before removing the worktree. Lanes never
park themselves — that would violate "don't delete worktrees."

## When you're unsure about repo state

Print it, don't guess. `git status`, `git log --oneline -10`,
`git rev-list --left-right --count origin/main...HEAD`. Put the output in your report
and let a human read it. A wrong guess about state is how branches multiply.
