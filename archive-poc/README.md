# archive-poc — Scope A

Throwaway-grade smoke test of an out-of-WordPress archive index. See
`/home/ubuntu/projects/docs/archive-poc-plan.md` for the source-of-truth plan
and `archive-redesign-conversation.md` for the design context.

## Layout

```
archive-poc/
├── schema.sql          ← SQLite DDL
├── index.sqlite        ← built index (not committed)
├── bin/
│   ├── backfill.php    ← one-shot WP → SQLite walker (boots WP for DB + helpers)
│   └── verify-thumbs.php  ← HEAD-check pass for R2 graveyard
├── api/v0/
│   ├── search.php      ← /archive-api/v0/search
│   └── item.php        ← /archive-api/v0/item/{id}
├── web/
│   ├── index.html      ← Variant C frontend
│   ├── archive.js
│   ├── archive.css
│   └── placeholders/   ← kind-default thumbnails
└── nginx-snippet.conf  ← location blocks for the dev site conf
```

## Rebuild from scratch

```bash
cd /home/ubuntu/projects/archive-poc
rm -f index.sqlite
sqlite3 index.sqlite < schema.sql
php bin/backfill.php
php bin/verify-thumbs.php
```

## URL surface

- `/archive-poc/`        — static frontend (cookie-gated)
- `/archive-api/v0/*`    — read-only JSON API (cookie-gated)
- `/archive`             — old Elementor archive, UNTOUCHED

## Guardrails

- Read-only on `looth_dev` (`SELECT` only).
- All new files live under this directory + a single nginx-snippet patch.
- No plugin deactivation. Search & Filter Pro stays on.
- nginx changes get a backup before patch + an explicit sign-off.
