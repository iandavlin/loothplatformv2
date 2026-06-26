# Poller monorepo ↔ running-prod reconciliation map — 2026-06-26

> Lane: **poller-monorepo-reconcile** (make the monorepo the single source + deploy origin).
> Author: poller-reconcile lane. Branch: `poller-monorepo-reconcile` off `main` @ 06fd486.
> **Do not deploy / do not merge** — keeper + Ian gate.

## TL;DR

The monorepo poller (`loothplatformv2/lg-patreon-stripe-poller` @ `main`) is **already
byte-identical to running production** on every runtime file. **Zero prod hand-patches are
missing** — the earlier `da44885 "capture live hand-patches into git"` (plus the tier-truth
merge `412818f` / `0316f60`) already ported them. The drift the keeper flagged is a
**deployment/process** gap (prod is served from the standalone repo, not the monorepo), **not a
code gap.** Everything that still differs is either (a) unfolded **lane** work that prod also
lacks — P2, or (b) docs/scratch.

**Implication:** P1 needs **no new "capture-prod-patch" commits.** This document is the P1
deliverable (provenance + proof). The real work is P2 (fold the two lane branches), P3
(mu-plugins), P4 (deploy origin + retire standalone).

## Method (how prod-truth was reconstructed without live SSH)

The live origin was rebuilt at the cut and its deploy key is rotated, so the prod tgz could not
be pulled directly. Reconstruction used keeper ground truth + on-box artifacts instead:

- **Ground truth (keeper, 2026-06-26):** running prod = `standalone-base(1486bc8) + prod
  hand-patches`. dev2 = `prod + role-fix(lane) + annual-cadence(lane)` on top. ⇒ **dev2-deployed
  ⊇ prod.**
- **Inputs:** `poller-standalone-repo-20260626.bundle` (base @ `1486bc8`), `poller-dev2-deployed-
  20260626.tgz` (full running dev2 tree, carries its own `.git` @ `1486bc8` + 18 uncommitted M +
  untracked new files), and the per-file `.bak-pollerfix` / `.bak-pollerlane` / `.bak-cadence`
  twins on the box (each = the file's content **before** that lane edited it).
- **Peel:** for every lane-touched file, its pre-lane `.bak` == `base + prod-patches` == prod
  running code. For files no lane touched, the live `M` content == the prod patch itself.
- **Compare:** monorepo `main` vs dev2-deployed (source only; vendor/.git/cache/*.bak excluded),
  then monorepo vs each pre-lane `.bak` to separate the **prod layer** from the **lane layer**.

**Residual verification owed to keeper (needs live access):** confirm dev2 ⊇ prod by diffing the
PROD tgz / `poller-PROD-uncommitted-20260626.diff` (16 files) against the dev2 diff (18 files). I
could not reach `54.146.118.131` (deploy key rejected post-rebuild; origin behind Cloudflare).
Expected result: prod's 16 == dev2's 18 minus the 2 cadence-only files (`Schema.php`,
sync-engine `pledge_cadence`). **No regression risk** either way, because capturing all of
dev2-deployed's non-lane behavior is a superset of prod.

## Per-file reconciliation map

Legend — Decision: **IN-MONO** already captured, byte-identical · **AHEAD** mono has folded lane
work prod lacks (additive, no regression) · **→P2** unfolded lane delta · **DOC** docs/scratch.

| File | mono vs prod-truth | mono vs dev2-running | Source of the running-only delta | Decision |
|---|---|---|---|---|
| `includes/class-lgpo-sync-engine.php` | **role-fix already folded** (mono = pre-rolefix `.bak-pollerfix` + 118L role-fix; == `.bak-pollerlane`) | +10L `pledge_cadence` | annual-cadence lane | **AHEAD** (role-fix in main) **+ →P2** (cadence) |
| `lg-patreon-onboard.php` | **0** (mono == `.bak-pollerlane`) | +3L | annual-cadence lane | **IN-MONO + →P2** |
| `src/Arbiter.php` | **0** (mono == `.bak-pollerlane`) | +46L | role-fix lane | **IN-MONO + →P2** |
| `src/Membership.php` | **0** (mono == `.bak-cadence`) | +20L | annual-cadence lane | **IN-MONO + →P2** |
| `src/Schema.php` | **0** (mono == `.bak-cadence`) | +1L | annual-cadence lane | **IN-MONO + →P2** |
| `src/Wp/Shortcodes.php` | **0** (mono == `.bak-cadence`) | +3L | annual-cadence lane | **IN-MONO + →P2** |
| `src/Patreon/PatreonSourceReader.php` | **0** | (none) | — | **IN-MONO** |
| `src/PurgeNotifier.php` | **0** | (none) | — | **IN-MONO** |
| `src/UserLifecycle.php` | **0** | (none) | — | **IN-MONO** |
| `src/Wp/AdminRoleCapture.php` | **0** | (none) | — | **IN-MONO** |
| `src/Wp/InternalRestController.php` | **0** | (none) | — | **IN-MONO** |
| `src/Wp/UserLifecycleAdmin.php` | **0** | (none) | — | **IN-MONO** |
| `includes/campaign-filter.php` | **0** | (none) | — | **IN-MONO** |
| 11 other M files (Plugin, MemberTools, Stripe/*, Wp/Pages, Wp/RestController, Wp/TestChecklist, Wp/UserProvisioner, WelcomeMailer, README, welcome email tpl) | **0** | (none) | — | **IN-MONO** (pure prod patches, already captured) |
| `composer.json` | identical | identical | — | **IN-MONO** |
| `deploy/remediation/{README.md,dedupe-multirole.php,backfill-blank-emails.php}` | only-in-run | only-in-run | **poller-role-fix lane** deliverable ("the Arbiter fix in this branch"; PREPARED, not yet run on prod) | **→P2** (fold with role-fix) |
| `docs/ENV-AND-SECRETS.md` | only-in-mono | only-in-mono | monorepo-added doc (`79105e2`) | keep (additive) |
| `PICKUP.md` | differs | differs | stale scratch handoff doc | **DOC** (cruft; not synced) |

## Decisions called

1. **No P1 capture commits needed** — all prod runtime behavior is already in `main`.
2. **`deploy/remediation/*` → P2**, not P1: it is explicitly poller-role-fix lane output and is
   *prepared, not running* on prod. Fold it when poller-role-fix folds.
3. **sync-engine is AHEAD of prod** (role-fix already merged to main). P2 must **de-dup**: the
   `poller-role-fix` branch (`3daa257`) overlaps work already in `main`. Do not double-apply.
4. **`PICKUP.md` / `tick.log`** are box scratch — never synced from box → repo.
5. **`docs/ENV-AND-SECRETS.md`** stays (monorepo-only deploy/secrets doc).

## What this does NOT cover (downstream phases)
- **P2** — fold `poller-role-fix` (3daa257) + `poller-annual-cadence` (0bfd681) into main,
  de-duping role-fix against what main already carries; sequence per keeper. Includes
  `deploy/remediation/*`.
- **P3** — 6 deployed-but-unrepo'd mu-plugins.
- **P4** — monorepo→box deploy origin (file-sync from `loothplatformv2/lg-patreon-stripe-poller`,
  retire the standalone repo) + FOLDED-IN-REGISTRY.
