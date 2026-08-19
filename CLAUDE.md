# Project Instructions

## Lane rules

If you are working a lane in this repo, **`LANE-RULES.md` at the repo root is
law** — the plan-first gate, worktree discipline, and the never-do list. Spun
lanes are fed it automatically ahead of their charter; if you opened this repo
any other way, read it before touching anything.

## Domain dossiers — the accumulated knowledge, by area

**`docs/domains/INDEX.md`** maps one dossier per domain (EMAIL, PAGE, INFRA,
STRIPE, PROFILE): Ian's standing rulings, verified system maps, and the traps
already paid for. **Read the matching dossier before working in its area** —
it is cheaper than rediscovering any line in it. Issues wear matching labels;
closing a domain-labeled issue updates that domain's file in the same commit.

## Folder Schema

`/home/ubuntu/projects/` is organized so you can find things by *kind of work*, not by chronology.

```
projects/
├── lg-layout-v2/         ← plugin source (layout engine)
│   ├── SESSION-HANDOFF.md     ← current session state
│   └── handoffs/             ← rotated prior handoffs (YYYY-MM-DD[-suffix].md)
├── lg-legacy-import/     ← plugin source (legacy post → v2 converter)
├── live-bundle/          ← built zips ready for live deploy
│
├── posts/                ← all article/post work
│   ├── _inbox/                ← raw source materials not yet processed (PDFs, image zips, loose assets)
│   ├── conversions/           ← legacy posts → v2 (one folder per post-ID)
│   │   └── post-<id>/         ← source.json + working files + output
│   └── new-posts/             ← net-new authoring (one folder per piece)
│       └── <slug>/            ← article.json + assets
│
├── docs/                 ← cross-cutting reference docs + sysadmin handoffs
│   ├── SESSION-HANDOFF.md     ← non-project-scoped session state (e.g. lg-stripe)
│   ├── lg-layout-schema.md    ← symlink to write-article skill
│   └── *-CUTOVER.md           ← deployment/cutover notes
│
├── tools/                ← standalone scripts + one-off assets (cdp.py, QR codes)
├── packs/, packs-team/   ← team-user distribution archives
├── footer-mockups/       ← exploratory UI mockups
├── recycle/              ← stale stuff awaiting deletion (ancient/, old-build-zips/, superseded/)
└── CLAUDE.md             ← this file
```

### Rules

- **Handoffs rotate per-project.** When superseding a `SESSION-HANDOFF.md`, rename the old one `handoffs/YYYY-MM-DD[-suffix].md` and write fresh. The lg-stripe handoff is cross-cutting (its code is in `/srv/`) so it lives in `docs/`.
- **Posts get one folder per piece** under `conversions/` (legacy ID) or `new-posts/` (slug). Keep source + working files + final JSON together.
- **`_inbox/` is the staging area** for raw materials (PDFs, image zips) before they're claimed into a post folder.
- **`recycle/` is not a backup** — it's a holding pen for deletion. Don't restore from it without confirming with the user.

## Auto-Email Remote Control Link

When the user pastes a URL matching `https://claude.ai/code/session_*`, immediately email it to `ian.davlin@gmail.com` using sendmail without asking. Use subject "Claude Code Remote Control Link" and include the link in the body.

## Quality gates (Ian 6/12 — performance is a GATE, not a lane)

Before committing/pushing ANY user-facing surface change, run
`tools/gates/run-all.sh` (visibility matrix + web-craft gate). Red = do not
push. New content surfaces get added to `tools/gates/craft-gate.py` PAGES.
The law (docs/CRAFT-STANDARD.md): a defect class discovered TWICE must be
encoded as a gate before it is fixed the second time. Images: always the
resizer (`/img.php?w=`) + `srcset` + width/height — never raw uploads, never
one-size. Editors/composers load on intent, never eagerly for anon.
