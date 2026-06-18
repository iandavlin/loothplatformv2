# Kickoff prompt for terminal Claude

Paste everything below the `---` line into the new `claude --dangerously-skip-permissions` session.

How to start the session:

```bash
cd /home/ubuntu/projects
claude --dangerously-skip-permissions
```

Then paste the prompt below.

---

You are picking up an in-flight project. Don't re-design — just execute. Two docs you must read first, in order:

1. `/home/ubuntu/projects/docs/archive-poc-plan.md` — the **scope-A plan you're executing**. This is the source of truth for what to build, schema, sequence, definition of success.
2. `/home/ubuntu/projects/docs/archive-redesign-conversation.md` — the long design conversation that produced the plan. Skim, don't memorize. Use it as a reference when something in the plan is unclear.

Also worth knowing:

- Memory at `/home/ubuntu/.claude/projects/-home-ubuntu-projects/memory/MEMORY.md` — read at session start (your usual practice).
- We are on the dev box; `whoami`=ubuntu, `curl ifconfig.me`=50.19.198.38. Don't SSH anywhere — act locally with sudo.
- Mockups for the UI (Variant C is the chosen direction) are at `/var/www/dev/mockups/archive.html`, served at `https://dev.loothgroup.com/mockups/archive.html` (cookie-gated, you have access).
- Headless chrome is at `127.0.0.1:9222` via the `chrome-dev.service` systemd unit. Browser-facing launcher at `https://dev.loothgroup.com/cdp/`. The `chrome-dev-login` skill is up to date — use it when you need UI verification.
- Project-local `defaultMode = bypassPermissions` is set in `.claude/settings.json`. You're in bypass mode. **Despite that, hold the guardrails the plan doc commits to** — especially: read-only on `looth_dev` (MySQL `SELECT` only), all new files under `/home/ubuntu/projects/archive-poc/`, no plugin deactivation, no nginx changes without backup. Surface destructive ideas before doing them, per the plan.

## What to do this session

Start at **step 1 of the plan's "Sequence of work"** and work through the steps in order. Stop and check in with the user at the natural checkpoints called out in the plan:

- After step 3 (backfill + thumb resolution complete, row counts visible) — show counts per kind, broken-thumb count.
- After step 5 (API + frontend working end-to-end against the index) — show the URL to open and a screenshot.
- Before step 6 (nginx wiring) — pause for explicit sign-off, since this touches `/etc/nginx/sites-available/dev.loothgroup.com.conf`. Always back up before patching.

Don't ship a single end-of-session writeup. Give one short update per checkpoint as you hit it. The user will redirect if needed.

## Working principles for this build

- **Throwaway code** is fine. The plan says scope A is throwaway-grade. Don't gold-plate. SQLite, vanilla JS, single-file scripts. No build steps.
- **Don't introduce abstractions** the plan doesn't call for. No router framework, no ORM, no TypeScript, no test harness beyond a smoke `curl` script. If you want one, ask first.
- **Don't add features beyond scope A.** Bookmarks, history, discovery rows, person table population, `save_post` sync — all explicitly out of scope. Bias toward "the smallest thing that proves the architecture."
- **Performance is the primary success metric.** The original archive is the comparison baseline. After step 5, capture page weight + time-to-interactive + time-to-filter for both — that's most of step 8.
- **Stable URL surface from day 1.** New page at `/archive-poc/`. New API under `/archive-api/v0/`. Do NOT touch `/archive` (the old Elementor page).

## First action

Read the two docs above. Then start step 1: scaffold `/home/ubuntu/projects/archive-poc/` per the file layout in the plan and write `schema.sql`. Surface anything in the plan that's underspecified or that you'd push back on before you start writing code.

If you finish a step and aren't sure whether to continue or pause, prefer to pause and check in.

Good luck.
