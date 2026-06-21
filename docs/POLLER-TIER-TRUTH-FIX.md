# Poller tier-truth fix — Patreon sweep now applies upgrades AND downgrades
**Lane:** `poller-arbiter-tier-truth` off `main` · dev2 · own worktree · fix commit `0316f60`
**Date:** 2026-06-21 · verified on dev2 (sim ON + no MTA — asserted; nothing emailed)

## Problem
The Patreon sweep could only ever **downgrade**, never **upgrade**, because the Patreon source read
was circular:
- `src/Patreon/PatreonSourceReader.php::readForUser` derived `tier` from **`$user->roles`** (the role
  the member ALREADY had, lines ~44-51) and read `lgpo_patreon_tier_id` but never used it.
- `src/RoleSourceWriter.php::readAllForUser` then **overwrote** the sweep-persisted `lg_role_sources`
  `patreon` row with that role-mirror read.
- Net: `Arbiter::sync` always heard "Patreon = your current role", so an upgrade could only ever
  confirm the status quo. This is the true cause of the old "Applied: 7, 0 actual changes".

The sweep already has the truth: `class-lgpo-sync-engine.php` fetches `currently_entitled_tiers`,
maps tier_ids→role via `lgpo_tier_map`, calls `RoleSourceWriter::report('patreon', $tier)` (persists
the API tier to `lg_role_sources`), and writes `lgpo_patreon_tier_id`.

## Fix (2 files, commit `0316f60`)
1. **`PatreonSourceReader::readForUser`** — derive the tier from the API truth the sweep persists:
   `lgpo_patreon_tier_id` mapped through `lgpo_tier_map`. Removed the `$user->roles` read. A
   patreon-managed member with no mapped **paid** tier (lapsed / declined / free looth1) resolves to
   **null**, so a real downgrade happens instead of pinning them at their current role. Only `looth2`
   / `looth3` are asserted by this source (looth1 = free floor → null; looth4 = comp/manual → never
   granted via Patreon).
2. **`RoleSourceWriter::readAllForUser`** — **stop overwriting** the persisted `patreon` row. The
   sweep is the authority; the adapter is now only a **fallback** when no row exists yet (fresh
   onboard before first sweep).

**Single source of truth (documented):** the sweep-maintained `lg_role_sources.patreon` row is
authoritative for the Arbiter; `lgpo_patreon_tier_id`→`lgpo_tier_map` is the fallback the adapter
derives from when no row exists.

## Care point preserved — Stripe precedence (NOT touched)
`Arbiter::computeWinningTier` is unchanged: it still takes the **max** across sources (skipping null).
So Patreon reporting its own (possibly lower) truth cannot strip a member a **higher Stripe source**
governs. Verified below.

## Verification on dev2 (deployed onto the running poller, ran the real sweep, then restored)
Safety asserted first: `simulate_emails=yes`, **no MTA**. Diverged test users via direct
`wp_capabilities` (bypassing `set_role`/AdminRoleCapture), ran `LGPO_Sync_Engine::run()`, captured the
report via a `wp_mail` filter, restored.

| test | user | source(s) | orig | diverged to | after sweep | expect | verdict |
|---|---|---|---|---|---|---|---|
| **A upgrade** | full_gainer@yahoo.com (699) | patreon=looth3 | looth3 | **looth1** | **looth3** | looth3 | ✅ PASS |
| **B downgrade** | jtallen116@gmail.com (1143) | patreon=looth2 | looth2 | **looth3** | **looth2** | looth2 | ✅ PASS |
| **C stripe-held** | swisherguitars (1862), jscatches (1884), dlaup2 (1894) | patreon=looth2, stripe=looth3 | looth3 | — | **looth3** | looth3 | ✅ PASS |

- **A** proves upgrades now apply (impossible before). **B** proves downgrades still apply.
- **C**: with the fix, Patreon now honestly derives **looth2** for these members (was the circular
  looth3), yet their role **stays looth3** because the Stripe source outranks it — the Patreon
  downgrade does **not** strip a Stripe-paying member.
- **Report** (served already runs the report-fix `efa7970`): `Applied: 4, Errors: 0`, full stats
  (Fetched 3334 / Matched 1501 / Unchanged 1480 / Skipped…), and both A and B listed under **Applied**
  — the now-honest reader produces genuine applieds. `emails` captured = simulated only; MTA absent.
- dev2 served was **restored** to its pre-test state (original reader files) after the run; the fix is
  NOT left deployed (keeper owns deploy). The sweep did apply real upgrades to other out-of-sync dev2
  members (expected — the fix working; sandbox, no prod impact).

## LIVE deploy (Ian/keeper-held — packaged, not run on live)
Poller is in `wp-content/plugins` (**not git-served**) → patch in place, not a git pull. The two files
(`src/Patreon/PatreonSourceReader.php`, `src/RoleSourceWriter.php`) are byte-identical between main and
live for the fix regions (no local drift), so the patcher applies cleanly.
- **`deploy/patch-tier-truth.py`** — self-verifying (each hunk must match exactly once or it aborts).
  Proven: `main + patcher == 0316f60` for both files.
- Steps on live:
  ```bash
  cd <LIVE_WEBROOT>/wp-content/plugins/lg-patreon-stripe-poller
  cp -p src/Patreon/PatreonSourceReader.php{,.bak-$(date +%s)}
  cp -p src/RoleSourceWriter.php{,.bak-$(date +%s)}
  python3 /path/to/patch-tier-truth.py "$(pwd)"     # prints "patched OK: …" x2 or "ABORT" (no write)
  php -l src/Patreon/PatreonSourceReader.php && php -l src/RoleSourceWriter.php
  sudo systemctl restart php8.3-fpm                  # live FPM unit/pool name
  ```
- Rollback: restore the `.bak-*` copies + restart fpm.
- Keep `simulate_emails` as-is; this change does not email. After deploy the next sweep will apply any
  previously-stuck **upgrades** — expected.
