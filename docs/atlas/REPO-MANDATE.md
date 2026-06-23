# REPO-MANDATE.md — Repo-first + env-file hygiene (binding policy)

**Status: authoritative (Ian + keeper, 2026-06-23).** The keeper enforces this at the merge
gate (see GIT-PROTOCOL.md "Definition of Done"). Applies to every lane and every feature.

---

## 1. Repo-first

Every feature lands in the monorepo **complete**: code, config, wiring, provisioning/deploy
steps, and docs. "Done" is NOT "it works on a box" — done = **a clean box can be brought to
that state from the repo alone.**

The ONLY things allowed to live outside git:
- **Secrets** — app passwords, tokens, keys, certs.
- **Per-box runtime data** — DB contents, uploads, render caches, sqlite mirrors.

…and even those carry a **repo obligation**: an **idempotent provisioning script/recipe** to
(re)create them, plus a **documented pointer** to where the real value lives (secrets manager,
Google Script Properties, `/etc/looth/env`). **Secret VALUE out; secret RECIPE in.**

Motivating failures (do not repeat):
- The `lg-article-materializer` mu-plugin was **missing on dev2** — no managed CPT
  auto-rendered on the standalone path. It "worked when hand-installed," but was never
  reproducible from the repo.
- `sheets-bot` (Sheet→WP bridge user) + its Application Password are box-local DB/secret state;
  they vanished on a DB reload with **no repo recipe** to recreate them.

---

## 2. Env-file hygiene

All config that **differs between boxes** (dev2 ↔ live) comes from the single `/etc/looth/env`
knob, read via `/srv/lg-shared/lg-env.php` (`lg_env()`) — **never hardcoded** in code, FPM
pools, or nginx.

- The repo carries **`env.template`** (root): every key, which app uses it, and dev-vs-live
  example values. Secret VALUES redacted; secret KEYS listed.
- Adding an env-varying knob in a feature = **add it to `env.template` + document it**.
- **Promotion dev→live = swap `/etc/looth/env` values only. ZERO code edits.**

Open debt this targets (SYSTEM-MAP §3):
- The standalone apps pin `LG_<APP>_PUBLIC_HOST` in **6 FPM pool lines** instead of reading the
  one env — changing the public host is a 7-point edit. They must derive from `LG_PUBLIC_HOST`.
- The showrunner bridge's materialize call hardcodes a `dev2.loothgroup.com` fallback host —
  must come from `LG_PUBLIC_HOST`.

---

## 3. Definition of Done (the keeper merge gate)

A lane's work merges to `main` only when ALL of these hold (the keeper verifies before merge):

- [ ] Code + wiring (symlinks / FPM pools / nginx) are **in repo** — no standalone,
      hand-placed files on the box.
- [ ] Every box-varying value reads from `/etc/looth/env` via `lg_env()` — **nothing
      hardcoded** for a specific box/host.
- [ ] **`env.template` updated** for any new or changed key.
- [ ] Secrets / runtime data are **out of git**, each with an idempotent **provisioning recipe
      + a pointer** to where the real value lives.
- [ ] Docs updated **in the same change** (rewire → SYSTEM-MAP).
- [ ] **A clean box could be brought to this state from the repo alone.**

A lane that does not meet this bar does not merge.

Cross-refs: `GIT-PROTOCOL.md` (review & merge gate), `SYSTEM-MAP.md` §2–3 (the env knob),
`env.template` (the key contract).
