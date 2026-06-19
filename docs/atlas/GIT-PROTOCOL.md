# GIT-PROTOCOL.md — How we use git for loothplatformv2

**Status:** seeded skeleton. Sections below are headers to fill over time as decisions
land. Only the **Decided** section is authoritative today.

---

## Decided (2026-06-18, Ian)

Repo `loothplatformv2`, remote `github-hub:iandavlin/loothplatformv2.git`, branch `main`.
Canonical clone = `dev2:~/loothplatformv2`. Deploy to a box = `git pull`. Secrets / data /
WP-core / uploads are gitignored and box-local.

**Workflow = branch-per-task + Ian approves merge** (Ian is a git novice and wants to be
walked through each gate):

1. **Edit only in the repo clone** (`dev2:~/loothplatformv2`) — never the legacy
   `dev1:~/projects` tree.
2. **One short-lived branch per task** (e.g. `avatar-consolidation`, `sacred-docs`).
3. **Commit small, TESTED increments** on the branch. Commit ≠ push.
4. **Before any push/merge, show Ian the commits + diffstat** for joint review. No silent
   pushes.
5. **Merge to `main` only after Ian OKs.** `main` stays always-deployable so a bad push
   never auto-reaches other boxes.

Why: `main` is the deploy branch (other boxes pull it); keeping mistakes off `main`
protects every box.

---

## Branch naming
_(to fill: convention for task branches, who deletes merged branches)_

## Commit message style
_(to fill: prefix-by-area convention, e.g. `profile-app: …`, `platform: …`, `docs: …`)_

## Review & merge gate
_(to fill: exact "show commits + diffstat" ritual, merge vs rebase, fast-forward policy)_

## What is / isn't tracked
_(to fill: pointer to `.gitignore`; runtime files that are tracked-but-locally-modified
(e.g. archive-poc config.json/index.sqlite) and how `git pull` handles them — stash/restore)_

## Deploy from git
_(to fill: the `git pull` ritual per box, symlink-farm repoint, what restart/cache-flush
follows a pull)_

## Secrets & box-local files
_(to fill: `/etc/looth/*`, R2 tokens, JWT keys, certs — never in git; per-box provisioning)_

## Multi-box / worktree handling
_(to fill: dev2 canonical vs dev3 deploy-test; the bb-mirror bespoke-cutover worktree
de-fork; how clones stay in sync)_

## Cut / live procedures
_(to fill: Phase-11 in-place swaps on dev2, DNS flip — cross-ref docs/PHASE-11-CUT-RUNBOOK.md)_

## Recovery / rollback
_(to fill: reverting a bad merge on main, restoring a box)_
