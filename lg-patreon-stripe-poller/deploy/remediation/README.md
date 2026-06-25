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

## After applying

- Smoke a couple of affected users' `/whoami` and confirm a single tier role.
- The hourly sweep takes over from here: it now backfills the Patreon-ID link on
  any email-matched account and mirrors the email every pass, so this backfill is
  a one-time catch-up, not a recurring need.
- **Note on the 30 blanks & login:** accounts created *before* the
  password-at-onboarding change carry an old random password, so email/password
  login won't work for them until a password reset — but **Patreon OAuth login
  still covers them** (they log in with "Log in with Patreon"), so no action is
  required for access; only note it if one asks to use email/password.
