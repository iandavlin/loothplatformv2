#!/usr/bin/env bash
# ENSURE PG ROLES — the roles and grants the apps connect as, as a repo artifact.
# Idempotent, DRY-RUN by default, and it has a --check mode that only reports.
#
#   bash cutover/ensure-pg-roles.sh            # DRY RUN — prints state + the SQL
#   bash cutover/ensure-pg-roles.sh --apply    # create missing roles + grants
#   bash cutover/ensure-pg-roles.sh --check    # REPORT ONLY, exit 1 if anything is
#                                              # missing. This is the deploy preflight.
#
# ─── WHY THIS EXISTS ─────────────────────────────────────────────────────────────
# 2026-07-31, live deploy. Every load of the Manage Account page logged
#   FATAL:  role "membership" does not exist
# and the page failed. The membership FPM pool runs as OS user `membership` and
# bb-mirror's DSN is `pgsql:host=/var/run/postgresql;dbname=looth` with NO user and
# NO password — so Postgres peer-auths it as the OS user. dev2 had that role; live
# never did. cutover/topic-follow-migrate.sh created the TABLE and granted looth_ro,
# but it never checked THE ROLE THE APP CONNECTS AS EXISTS.
#
# Two people spent the outage reasoning about code while the answer sat in
# /var/log/php8.3-fpm.log. A role that exists on one box and not the other is
# invisible to `git diff` — which is exactly why it belongs in a file.
#
# Ian's mandate: deploy is ONE PULL. A role is not a file, so it cannot literally
# arrive by pull — but the ARTIFACT that creates it can, and the deploy can refuse
# to proceed when the box does not match it. That is what this is.
#
# ─── THE FAILURE MODE THIS GUARDS ────────────────────────────────────────────────
# A missing role does not fail at deploy time. It fails at RUNTIME, per request, in
# a log nobody is reading, and the page just breaks. --check turns that into a loud
# refusal before the pull, which is the whole point.
set -euo pipefail

MODE=dryrun
for a in "${@:-}"; do
  case "$a" in
    --apply) MODE=apply ;;
    --check) MODE=check ;;
    "") ;;
    *) echo "unknown argument: $a" >&2; exit 2 ;;
  esac
done

# ─── THE MANIFEST ────────────────────────────────────────────────────────────────
# role|database|why
#
# Derived from, and cross-checked against, three independent sources:
#   1. `user =` in platform/fpm/{live,dev2}/*.conf  — the OS user peer auth presents
#   2. the pgsql DSNs in the tree                    — which apps open a PG socket
#   3. pg_roles on live and dev2                     — both boxes, 2026-07-31
# All three agree. Roles are LOGIN and passwordless: peer auth over the unix socket
# is the only path, so there is no password to manage or leak.
#
# DELIBERATELY ABSENT: `events` and `tool-dev` run FPM pools but their apps open no
# Postgres connection, so they need no role. That is a finding, not an oversight —
# recorded here so nobody "fixes" it later by adding roles that grant real access
# for no reason.
ROLES="
membership|looth|membership-pages Manage Account: reads the Following section via bb-mirror's connection builder (membership-pages/lib/following-data.php:121 requires bb-mirror/config.php). THE ONE THAT WAS MISSING ON LIVE 2026-07-31.
bb-mirror|looth|the forum mirror itself; owns schema forums
profile-app|profile_app|the profile app; also reads schema forums
archive-poc|looth|article archive; reads forums.{forum,topic,person}
looth-dev|looth|WordPress (looth-dev pool) + the mu-plugin sync bridges
looth_ro|looth|read-only auditing role (ssh live-ro)
"

# role|database|schema|privilege|tables|grantor
# GRANT is idempotent in Postgres, so re-running is free.
#
# ⚠️ THE GRANTOR COLUMN IS NOT DECORATION. Postgres records WHO granted inside the
# ACL: dev2 and live both read `membership=r/"bb-mirror"`. Granting the same
# privilege as `postgres` instead would write `membership=r/postgres` — the same
# access, but the two boxes would no longer be byte-identical, and byte-identical is
# exactly what the comparisons in docs/runbooks/live-topic-follow-migration.md
# assert. Grant as the object OWNER.
#
# ⚠️ looth-dev's INSERT/UPDATE/DELETE are NOT granted here and must not be. They
# arrive from schema `forums`' DEFAULT PRIVILEGES, which are identical on both boxes
# (see docs/runbooks/live-topic-follow-migration.md). Granting them by hand here
# would paper over a broken default instead of surfacing it.
GRANTS="
membership|looth|forums|SELECT|forum,topic,topic_follow|bb-mirror
"

PSQL_SUPER=(sudo -u postgres psql -v ON_ERROR_STOP=1 -tAc)

say() { printf '%s\n' "$*"; }
hr()  { printf '%s\n' "────────────────────────────────────────────────────────────────"; }

say "MODE=$MODE   box=$(hostname -f 2>/dev/null || hostname)"
hr

# ─── 1. ROLES ────────────────────────────────────────────────────────────────────
MISSING_ROLES=()
say "ROLES the apps peer-auth as:"
while IFS='|' read -r role db why; do
  [ -z "${role:-}" ] && continue
  exists="$("${PSQL_SUPER[@]}" "select 1 from pg_roles where rolname='$role';" 2>/dev/null || echo)"
  if [ "$exists" = "1" ]; then
    canlogin="$("${PSQL_SUPER[@]}" "select rolcanlogin from pg_roles where rolname='$role';" 2>/dev/null || echo)"
    if [ "$canlogin" = "t" ]; then
      say "  OK       $role (db $db)"
    else
      say "  NOLOGIN  $role — EXISTS BUT CANNOT LOG IN; peer auth will still fail"
      MISSING_ROLES+=("$role")
    fi
  else
    say "  MISSING  $role (db $db)"
    say "           → $why"
    MISSING_ROLES+=("$role")
  fi
done <<< "$ROLES"

# ─── 2. GRANTS ───────────────────────────────────────────────────────────────────
hr
MISSING_GRANTS=()
say "GRANTS (checked with has_table_privilege, run as superuser):"
say ""
say "  Two wrong ways to ask this, both of which this script has now been bitten by:"
say "   - information_schema.role_table_grants FILTERS BY THE QUERYING ROLE, so it"
say "     reports phantom gaps (the topic-follow runbook lost hours to exactly that)."
say "   - grepping pg_class.relacl for '\"role\"=r' MISSES every role whose name needs"
say "     no quoting: the ACL text is 'membership=r/\"bb-mirror\"' — bb-mirror is quoted"
say "     because of its hyphen, membership is not. That pattern false-reported three"
say "     present grants as MISSING on dev2, 2026-07-31."
say "  has_table_privilege asks the question the app actually asks: can this role read"
say "  this table — by any route, direct grant or default privilege."
while IFS='|' read -r role db schema priv tables grantor; do
  [ -z "${role:-}" ] && continue
  IFS=',' read -ra TBLS <<< "$tables"
  for t in "${TBLS[@]}"; do
    present="$(sudo -u postgres psql -d "$db" -tAc \
      "select has_table_privilege('$role', '$schema.$t', '$priv');" 2>/dev/null || echo)"
    if [ "$present" = "t" ]; then
      say "  OK       $role $priv on $schema.$t"
    else
      relexists="$(sudo -u postgres psql -d "$db" -tAc \
        "select to_regclass('$schema.$t') is not null;" 2>/dev/null || echo)"
      if [ "$relexists" != "t" ]; then
        say "  NOTABLE  $schema.$t does not exist yet — run the migration first"
      else
        say "  MISSING  $role $priv on $schema.$t"
      fi
      MISSING_GRANTS+=("$role|$db|$schema|$priv|$t|${grantor:-postgres}")
    fi
  done
done <<< "$GRANTS"

# ─── 3. ACT ──────────────────────────────────────────────────────────────────────
hr
NMISS=$(( ${#MISSING_ROLES[@]} + ${#MISSING_GRANTS[@]} ))

if [ "$NMISS" -eq 0 ]; then
  say "NOTHING MISSING — this box matches the manifest."
  exit 0
fi

if [ "$MODE" = check ]; then
  say "❌ PREFLIGHT FAILED — ${#MISSING_ROLES[@]} role(s), ${#MISSING_GRANTS[@]} grant(s) missing."
  say ""
  say "   Do NOT deploy past this. A missing role does not fail the deploy; it fails"
  say "   every request afterwards, with a FATAL in /var/log/php8.3-fpm.log and a"
  say "   broken page. Fix it first:  bash cutover/ensure-pg-roles.sh --apply"
  exit 1
fi

say "SQL to run ($([ "$MODE" = apply ] && echo APPLYING || echo 'dry run — nothing written')):"
say ""
for role in "${MISSING_ROLES[@]:-}"; do
  [ -z "${role:-}" ] && continue
  # CREATE ROLE has no IF NOT EXISTS; the DO block is the idempotent form. NOSUPERUSER
  # NOCREATEDB NOCREATEROLE are spelled out rather than left to defaults so the role's
  # power is visible in the file that creates it.
  sql="DO \$\$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '$role') THEN
    CREATE ROLE \"$role\" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOINHERIT;
  ELSE
    ALTER ROLE \"$role\" LOGIN;
  END IF;
END \$\$;"
  say "  -- role $role"
  say "$sql" | sed 's/^/  /'
  if [ "$MODE" = apply ]; then
    sudo -u postgres psql -v ON_ERROR_STOP=1 -q -c "$sql"
    say "  ✔ applied"
  fi
  say ""
done

for g in "${MISSING_GRANTS[@]:-}"; do
  [ -z "${g:-}" ] && continue
  IFS='|' read -r role db schema priv t grantor <<< "$g"
  relexists="$(sudo -u postgres psql -d "$db" -tAc "select to_regclass('$schema.$t') is not null;" 2>/dev/null || echo)"
  if [ "$relexists" != "t" ]; then
    say "  -- SKIP $schema.$t — table absent; migration has not run"
    continue
  fi
  sql="GRANT USAGE ON SCHEMA \"$schema\" TO \"$role\";
GRANT $priv ON \"$schema\".\"$t\" TO \"$role\";"
  say "  -- grant $role $priv on $db:$schema.$t (as ${grantor:-postgres})"
  say "$sql" | sed 's/^/  /'
  if [ "$MODE" = apply ]; then
    # As the OWNER, not postgres — the grantor is recorded in the ACL.
    sudo -u "${grantor:-postgres}" psql -d "$db" -v ON_ERROR_STOP=1 -q -c "$sql"
    say "  ✔ applied (granted by ${grantor:-postgres})"
  fi
  say ""
done

if [ "$MODE" = apply ]; then
  hr
  say "RE-VERIFYING (querying the store, not trusting the writes above):"
  exec bash "$0" --check
fi

hr
say "DRY RUN — nothing was written. Re-run with --apply."
