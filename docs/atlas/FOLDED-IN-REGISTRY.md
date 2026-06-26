# Folded-in registry

Single list of out-of-repo code/glue **folded into the monorepo**, with provenance and a
**review-after** date. Convention: every fold-in carries `@lg-folded-in <date> review-after <+12mo>`
in its file header (or is logged here when a header isn't possible). On the review date, confirm the
fold-in is still wanted / still matches the box, or decommission it.

> New fold-ins append a row. Keep newest at top.

## 2026-06-26 — poller-monorepo-reconcile lane

The poller running on dev2 + prod was a **standalone repo** checkout
(`iandavlin/lg-patreon-stripe-poller` @ `1486bc8`) + uncommitted hand-patches, deployed by in-place
patchers; the monorepo poller was an orphan. This lane made the monorepo the single source.

| Fold-in | From | Into | Decommission / review |
|---|---|---|---|
| Poller runtime hand-patches (≈18 files) | standalone repo box-uncommitted + prior captures | `lg-patreon-stripe-poller/` on `main` (already present — P1 confirmed byte-equal to running prod, **zero missing**) | review-after 2027-06-26; superseded once standalone repo is archived |
| `poller-role-fix` (single-tier enforcement, Patreon-ID bridge, email mirror, failure notices, `deploy/remediation/*`) | branch `poller-role-fix` `3daa257` | lane branch `poller-monorepo-reconcile` (cherry-pick `a6af334`) — **not yet merged to main** | folds clean, net-new to main, no dup; keeper sequences merge |
| `poller-annual-cadence` (Patreon `pledge_cadence` → annual display) | branch `poller-annual-cadence` `0bfd681` | lane branch (cherry-pick `963d6cb`) — **not yet merged** | folds clean; order vs role-fix irrelevant (A==B identical trees) |
| `lg-poller-mail-killswitch.php` (dev2-ONLY) | deployed-but-unrepo'd dev2 `wp-content/mu-plugins/` | `platform/mu-plugins/` | `@lg-dev-only`; review-after 2027-06-26; **excluded from live deploy** (Q7 marker filter) |
| `lg-dev-disable-looth1-bounce.php` (dev2-ONLY) | deployed-but-unrepo'd dev2 `wp-content/mu-plugins/` | `platform/mu-plugins/` | `@lg-dev-only`; review-after 2027-06-26; **excluded from live deploy** (Q7 marker filter) |

### 2026-06-26 audit-remediation (poller-monorepo-reconcile, post-audit) — on lane branch, NOT merged
Closes the audit blockers Q5 + Q7 and the mail posture (#3); lifecycle proven on the dev DB.

| Change | Files | Note |
|---|---|---|
| **Q5** identity uuid auto-stamp | `includes/class-lgpo-sync-engine.php` (`stamp_looth_uuid` + call in `sync_wp_email`), `deploy/remediation/backfill-blank-emails.php` | freezes `_looth_uuid` after a real email lands so `/whoami` resolves; canonical `looth_auth_compute_uuid` + identical v5 fallback; **immutable** (never re-derives on an email change). Derivation proven byte-equal to the stored uuid; full anon to resolve to email-change lifecycle proven on dev (reversible test acct). |
| **Q7** dev-only deploy exclusion | `deploy/deploy.sh` (marker-driven), `platform/mu-plugins/{lg-dev2-power,lg-secrets-dash,lg-dev-mail-containment}.php` (`@lg-dev-only`) | 5 dev-only plugins excluded from the live sync (dry-run verified; 20 legit ship). Also tagged `lg-dev-mail-containment.php` (was untagged) so the net-result list holds. |
| **#3** mail OFF on live via flag | `src/Plugin.php` (`gateOutboundMail` pre_wp_mail gate), `deploy/remediation/README.md` | poller reads `lgms_poller_mail_enabled` at runtime (fail-closed; intent-tagged notices bypass); flip ON at launch with no redeploy. Replaces the hardcoded dev killswitch (now `@lg-dev-only`/excluded). |

### Reconciled — already in repo (NOT re-folded)
- `lg-bug-report.php` — already repo'd at `bug-report/lg-bug-report.php` (served via symlink); deployed == repo.
- `lg-weekly-email-bridge.php` — already repo'd at `events/lg-weekly-email-bridge.mu-plugin.php` (events lane owns it); deployed == repo (0 diff). Provision from `events/`.

### Not folded (deliberate)
- `buddyboss-performance-api.php`, `burst_rest_api_optimizer.php` — third-party perf mu-plugins;
  already in `deploy/MANIFEST.md` "Excluded (deliberately)". Vendor/box-managed, not repo code.

### Retire (Ian / keeper action)
- Standalone repo `iandavlin/lg-patreon-stripe-poller` → archive read-only on GitHub. Bundle kept:
  `~/backups/poller-standalone-repo-20260626.bundle`.
- In-place patchers `deploy/patch-sync-report.py` + `deploy/patch-tier-truth.py` → retire once the
  file-sync deploy (`lg-patreon-stripe-poller/docs/DEPLOY-ORIGIN.md`) is adopted (main==prod makes
  them an obsolete foot-gun).

### Open verification (needs live access — keeper)
- Cross-check `main` == prod against the PROD snapshot
  (`LIVE:~/backups/poller-PROD-deployed-20260626.tgz` + `…-uncommitted-…diff`, 16 files). This lane
  reconstructed prod-truth from the dev2 deployed tree + `.bak` twins because the live deploy key was
  rotated at the cut (origin behind Cloudflare). Expected: prod's 16 == dev2's 18 minus the 2
  cadence-only edits. No regression risk either way (capturing all non-lane dev2 behavior ⊇ prod).
- Confirm whether the **role-fix Arbiter change is already deployed to prod** or is dev2-only
  in-flight (decides whether folding role-fix to main leaves main *ahead of* or *equal to* prod).
