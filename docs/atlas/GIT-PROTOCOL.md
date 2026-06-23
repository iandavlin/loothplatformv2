# GIT-PROTOCOL.md — How we use git for loothplatformv2

**Status:** the filled sections (Decided, Branch naming, Commit message style, Review &
merge gate, What is / isn't tracked, Multi-box / worktree handling) are authoritative. The
remaining `_(to fill)_` headers fill as decisions land.

---

## Decided (2026-06-18, Ian)

Repo `loothplatformv2`, remote `github-hub:iandavlin/loothplatformv2.git`, branch `main`.
Canonical clone = `dev2:~/loothplatformv2`. Deploy to a box = `git pull`. Secrets / data /
WP-core / uploads are gitignored and box-local.

**Workflow = branch-per-task + Ian approves merge** (Ian is a git novice and wants to be
walked through each gate):

1. **Edit in a per-lane git worktree off `main`** — never the legacy `dev1:~/projects`
   tree, and never the canonical clone's own working tree directly (refined 6/19 — see
   *Multi-box / worktree handling* below).
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
Short kebab-case, one per task, named for the work (`avatar-v3`, `secrets-dash`,
`serve-assets-fix`). Branch off `main`. The keeper deletes branches once merged
(`git branch -d`). No long-lived feature branches — keep them short so `main` is the
shared truth.

## Commit message style
Area-prefix, imperative: `profile-app:`, `platform:`, `archive-poc:`, `docs(atlas):`,
`gitignore:`. One logical, tested change per commit (commit ≠ push). End every commit with
the `Co-Authored-By:` trailer.

## Review & merge gate
Before any push or merge to `main`: show Ian the commits + `git diff --stat`. Merge with
`--no-ff` (one clear merge commit per lane) only after his OK; delete the branch after.
Never silent-push. Boxes only ever *pull* `main`, so a mistake kept off `main` reaches no box.

**Definition of Done (the keeper checks this before merge):** see `REPO-MANDATE.md` §3 — repo-first (code+wiring+provisioning+docs all in repo), env-hygiene (box-varying values via `/etc/looth/env`, `env.template` updated), secrets out with a recipe + pointer, and “a clean box reproduces this from the repo alone.” A lane that doesn’t meet it doesn’t merge.

## What is / isn't tracked
Source is tracked; **runtime + secrets are not** (see `.gitignore` + per-dir ignores):
- archive-poc `index.sqlite` (box-local revert mirror) and `web/assets/*.css|*.js`
  (lazily-generated content-hashed bundles) — gitignored; the serve tree must make these
  paths **writable by the `archive-poc` user** (`chown archive-poc:www-data` + `chmod 2775`,
  or ACL). A non-writable `web/assets` makes the renderer inline ~105 KB CSS/page instead of
  linking a cached bundle (heavier, not broken).
- `config.json` is tracked but app-writable (ACL); a `git pull` that would clobber it must
  stash/restore.
- Secrets (`/etc/looth/*`, R2 tokens, JWT keys, certs) — never in git; provisioned per box.
- **Rule:** a fresh clone must REPRODUCE the live render. If it doesn't, the gap is a
  gitignored runtime dependency or a non-writable runtime dir — provision it, don't commit it.

## Deploy from git
_(to fill: the `git pull` ritual per box, symlink-farm repoint, what restart/cache-flush
follows a pull)_

## Secrets & box-local files
`/etc/looth/*`, R2 tokens, JWT keys, certs, DB contents, uploads, render caches — **never in git**. But per the repo-first mandate (`REPO-MANDATE.md`), each carries a repo obligation: an idempotent provisioning script/recipe to (re)create it + a documented pointer to where the real value lives. **Secret VALUE out; secret RECIPE in.** Box-varying config comes from `/etc/looth/env` via `lg_env()` — never hardcoded; the repo’s `env.template` documents every key. **Promotion dev→live = swap env values only, zero code edits.**

## Multi-box / worktree handling (decided 6/19)
**One canonical clone, per-lane worktrees, a separate serve clone.**
- **Canonical clone** `dev2:~/loothplatformv2` stays checked out on `main`, clean. It is the
  keeper's reference + the merge target. **No lane edits it directly** — that caused the
  shared-tree collisions (live edits leaking to the served site; commits landing on whatever
  branch happened to be checked out).
- **Per-lane worktree** — each task gets its own working tree off `main`:
  `git worktree add ~/worktrees/lpv2-<task> -b <task> main`. Edit + commit there; the keeper
  reviews + merges to `main`; then `git worktree remove ~/worktrees/lpv2-<task>`. Worktrees
  share the one `.git` (cheap, no full clone) but each has its own branch + working tree, so
  lanes never step on each other or the canonical clone. (The keeper already uses this for
  doc edits while the clone sits on another branch.)
- **Serving** comes from a SEPARATE pristine clone on `main` (never a lane/edit tree), so the
  live site never serves uncommitted work — see SYSTEM-MAP §13.
- dev3 = deploy-test box with its own clone; every box only *pulls* `main`.

## Cut / live procedures
_(to fill: Phase-11 in-place swaps on dev2, DNS flip — cross-ref docs/PHASE-11-CUT-RUNBOOK.md)_

## Recovery / rollback
_(to fill: reverting a bad merge on main, restoring a box)_
