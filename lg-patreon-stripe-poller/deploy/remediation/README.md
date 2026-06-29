# Poller role-fix — LIVE remediation plans (PREPARED, do NOT auto-run)

Three one-time scripts that clean up the existing data damage on **live**. They are
**not** wired into cron and are **not** run by the lane — Ian runs them by hand on
live after the engine code they depend on is deployed. All default to a
**dry-run / review** mode and only mutate when passed `apply`.

Run as the live WP system user, from the plugin's `deploy/remediation/` dir:

```bash
# on live (system user = looth-live; confirm with `id`)
cd /var/www/<live>/wp-content/plugins/lg-patreon-stripe-poller/deploy/remediation

# ALWAYS dry-run first, read the output, then apply
sudo -u looth-live wp eval-file dedupe-multirole.php          # review
sudo -u looth-live wp eval-file dedupe-multirole.php apply    # execute

sudo -u looth-live wp eval-file backfill-blank-emails.php       # review
sudo -u looth-live wp eval-file backfill-blank-emails.php apply # execute

sudo -u looth-live wp eval-file reconcile-patreon-skeletons.php             # review
sudo -u looth-live wp eval-file reconcile-patreon-skeletons.php apply       # execute (prints a batch id)
sudo -u looth-live wp eval-file reconcile-patreon-skeletons.php revert <id> # undo that batch
```

Take a DB backup first (`wp db export` of `wp_usermeta`, `wp_users`, and
`lg_role_sources`; for the reconciler also dump `lg_patreon_members` from the
`lg_membership` DB). None of these scripts delete or create users.

---

## 1. `dedupe-multirole.php` — the 15 double-role users

Cleans up users holding **2+** `looth1..4` roles at once (background: 11
`looth1+looth3`, 4 `looth1+looth2`). The Arbiter fix in this branch prevents new
double-roles; this fixes the historical ones.

Per user, role-source-aware, ending in exactly ONE tier role:

| Situation | Action |
|---|---|
| holds `looth4` | keep `looth4`, strip `looth1/2/3` (comp/manual is protected & wins) |
| has any `lg_role_sources` / patreon row | `\LGMS\Arbiter::sync()` — arbiter de-dupes to the winning source tier |
| no source rows at all | keep the single highest tier role, strip the rest |

Idempotent — re-running after a clean pass is a no-op. Non-tier roles
(administrator, bbp_*, customer) are never touched.

## 2. `backfill-blank-emails.php` — the 30 blank + 2 mismatched patrons

Links the legacy active-patron accounts the old email-only bridge could never
match (~30 with a blank WP email, 2 with a different email).

It fetches the Patreon roster (`LGPO_Sync_Engine::fetch_member_roster()`), and for
each active patron **not already matched by Patreon-ID or email**, name-matches
against WP `display_name`/`user_login` (blank-email accounts preferred when a name
repeats). On a **unique** name match it proposes a link; ambiguous (0 or 2+)
matches are printed for manual review and never auto-applied.

On `apply`, for each proposed link it:

1. stamps `lgpo_patreon_user_id` (so all future sweeps key on the stable ID),
2. sets `user_email` to the Patreon email **only if** no different WP user already
   holds it — on a uniqueness collision it keeps the link, leaves the email, and
   alerts via `lgpo_notify_failure` (member + Ian),
3. writes the membership snapshot and applies the tier via the arbiter.

Safe because login is Patreon OAuth — rewriting `user_email` cannot lock anyone
out. Review the proposal list before applying; hand-resolve the ambiguous block.

## 3. `reconcile-patreon-skeletons.php` — the ~80 hard-locked + ~149 mis-linked skeletons

Fixes the **skeleton accounts** a bulk import / DB-reload created: `user_login =
patreon_<id>` (the Patreon user id is IN the username) but the
`lgpo_patreon_user_id` meta was never written and `user_email` left blank. The
hourly sweep keys on that META (then email), so these are invisible to it — never
linked, never get their email mirrored, never get a role → hard-locked out. This
recovers the Patreon id **deterministically** by parsing the username (it is *not*
the fuzzy name-match of #2 — that handles the non-skeleton legacy accounts), then
roster-confirms it.

**Prerequisite:** the deployed plugin must carry `LGPO_Sync_Engine::fetch_member_roster()`
**and** `::stamp_looth_uuid()` (the re-key-on-Patreon-ID engine work). Verify before
running:

```bash
sudo -u looth-live wp eval 'var_dump(
  method_exists("LGPO_Sync_Engine","fetch_member_roster"),
  method_exists("LGPO_Sync_Engine","stamp_looth_uuid"));'   # must be true, true
```

Per skeleton account (`user_login REGEXP '^patreon_[0-9]+$'` AND missing meta;
admins + non-matching placeholders are excluded by construction):

| Situation | Action |
|---|---|
| id **in** the active roster | **LINK**: stamp `lgpo_patreon_user_id`, mirror `user_email` from the roster (uniqueness-guarded; freezes `_looth_uuid` so `/whoami` resolves), write the `lg_patreon_members` row → Manage Account renders **ACTIVE** immediately |
| id **not** in roster, **blank** email | **FLAG** (lapsed / left Patreon) — writes nothing; never blind-links |
| id **not** in roster, email **present** (the ~149) | **STAMP-META** only — stamps `lgpo_patreon_user_id` so every future sweep keys on the stable id |

The **role is intentionally not applied here.** Once the meta is stamped the next
hourly sweep sees the account and grants the correct tier — which keeps this
script's writes (meta + email + row) exactly the set the journal can reverse.

**Reversible.** `apply` records a per-account journal under a printed **batch id**
(stored in the `lgpo_reconcile_journal_<batch>` / `lgpo_reconcile_batches`
options, autoload off). `revert <batch-id>` restores each account exactly — clears
the meta it stamped (only where it was absent before), restores the prior
`user_email` (a blank prior restores to blank), and deletes the `lg_patreon_members`
row **only if** that apply inserted it. Revert is idempotent (re-running a
reverted batch is a no-op) and interruption-safe (a killed revert re-runs cleanly).
Run `revert` with no batch id to list known batches and their state. Revert does
**not** undo a tier role a later sweep may have granted from the now-cleared meta —
if a sweep already ran, settle roles afterward with `dedupe-multirole.php` / the
arbiter.

### Live runbook

1. **Backup** (see top) — include `lg_patreon_members` from the `lg_membership` DB.
2. **Prerequisite check** (the `method_exists` snippet above) — both `true`.
3. **Review:** `… reconcile-patreon-skeletons.php`. Sanity-check the counts — `LINK`
   should ≈ the active blank-email patrons (~80), `STAMP-META` ≈ 149, `FLAG` = the
   lapsed ones, `EDGE` = the 2 non-skeleton oddballs (left untouched — see below).
4. **Apply:** `… reconcile-patreon-skeletons.php apply`. **Record the batch id it
   prints** (needed for revert).
5. **Grant roles:** let the next hourly sweep run, or trigger it, so the `LINK`
   accounts get their tier (the script deliberately leaves roles to the sweep).
6. **Smoke `/whoami` on EVERY linked account** — each must resolve to a real member
   identity (not anon) with a single tier role. A still-anon result means the uuid
   didn't land for that user.
7. **If anything is wrong:** `… reconcile-patreon-skeletons.php revert <batch-id>`
   (ideally before the sweep grants roles, for a clean undo).

> **Mail note:** mirroring a blank → real `user_email` makes WP core try to send its
> "email changed" notice to the *old* (blank) address, which fails harmlessly (a
> `503 Bad sequence` to the local mail sink on dev). On live, member/billing mail is
> gated OFF by `lgms_poller_mail_enabled`, and the reconciler sends nothing
> intentionally — so this is noise, not a member-facing email.

### The two EDGE accounts (investigated — left untouched)

The reconciler enumerates strictly `^patreon_[0-9]+$`, so neither of the two
"blank-email + unlinked" oddballs is auto-linked; both are listed under **EDGE** for
manual review:

- **`670aa65904420`** — *not* a `patreon_<id>` account: the login is a **hex Unix
  timestamp** (`0x670aa659` = `1728743001` ≈ its `user_registered` of 2024-10-12),
  i.e. a fallback username minted when an import had no Patreon id / name / email to
  work from. Blank email, no recoverable id → there is nothing to deterministically
  link it to. Leave it for a hand merge (or delete if confirmed junk); it is **not**
  one of the locked-out patrons.
- **`deleted-member`** — a placeholder/tombstone login, explicitly skipped.

## After applying — finish the IDENTITY step, then smoke the REAL accounts

The backfill seeds the role + email bridge AND now freezes the identity uuid.
Finish identity and verify on the actual accounts (not "a couple"):

1. **`_looth_uuid` is auto-stamped by this script.** Right after it sets
   `user_email` it calls `LGPO_Sync_Engine::stamp_looth_uuid()`, which freezes
   `_looth_uuid` = UUIDv5(LOOTH_IDENTITY_NAMESPACE / LOOTH_AUTH_NAMESPACE,
   `lower(trim(email))`) — the exact value the JWT minter reads. Without it a
   re-keyed/backfilled account has a role but `/whoami` is **anon**. The hourly
   sweep's `sync_wp_email` stamps the same way the first time it mirrors an email,
   so future re-keys self-heal. The stamp is **immutable** — a later Patreon email
   change does NOT re-derive it (the frozen uuid must keep matching profile-app's
   `users.uuid`).
2. **Run the authoritative reconciler** `profile-app/bin/backfill-looth-uuid.php`
   AFTER this script, as a belt step — it reads `users.uuid` straight from Postgres
   and is the source of truth for any account whose frozen uuid came from an
   earlier/different email than the one just mirrored.
3. **Smoke `/whoami` on EVERY backfilled account** (each `linked #N` in the apply
   log, not a sample): each must resolve to a real member identity (NOT anon) with
   a single tier role. A still-anon result means the uuid didn't land — re-check
   steps 1–2 for that user before relying on it.
4. The hourly sweep takes over: it backfills the Patreon-ID link on any
   email-matched account and mirrors the email + stamps the uuid every pass, so
   this backfill is a one-time catch-up, not a recurring need.

- **Note on the 30 blanks & login:** accounts created *before* the
  password-at-onboarding change carry an old random password, so email/password
  login won't work for them until a password reset — but **Patreon OAuth login
  still covers them** (they log in with "Log in with Patreon"), so no action is
  required for access; only note it if one asks to use email/password.

## Mail posture on live — held OFF by a FLAG, not a hardcoded file

Member/billing mail (welcome / membership / the hourly sync report) is held OFF on
live while Stripe is in R&D — via the runtime option **`lgms_poller_mail_enabled`**,
NOT the dev-only `lg-poller-mail-killswitch.php` mu-plugin (that file is
`@lg-dev-only` and is excluded from the live deploy by `deploy/deploy.sh`'s
marker-driven filter).

The poller reads the flag at runtime (`Plugin::gateOutboundMail`, a `pre_wp_mail`
gate), so it flips at launch with **no redeploy**:
- **Option absent / `0` → poller bulk mail is suppressed** (fail-closed; the
  posture we want now).
- **Intentional notices** — provision/bridge/role failure alerts + the member
  "we're aware" note, tagged `X-LG-Poller-Intent: notify` — **always send**; they
  must reach members + Ian regardless of the flag.
- **At launch (Ian, on live):** `wp option update lgms_poller_mail_enabled 1` to
  turn member/billing mail ON; set back to `0` (or delete) to hold it again.

Non-poller site mail is never touched (the gate only fires on mail originating in
this plugin). On dev, `lg-dev-mail-containment` additionally routes anything that
does send to mailpit.
