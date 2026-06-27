# Poller role-fix — LIVE remediation plans (PREPARED, do NOT auto-run)

Two one-time scripts that clean up the existing data damage on **live**. They are
**not** wired into cron and are **not** run by the lane — Ian runs them by hand on
live after the code in branch `poller-role-fix` is deployed. Both default to a
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
```

Take a DB backup first (`wp db export` of `wp_usermeta`, `wp_users`, and
`lg_role_sources`). Neither script deletes or creates users.

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

---

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
