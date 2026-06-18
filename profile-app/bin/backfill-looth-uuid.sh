#!/usr/bin/env bash
#
# One-time idempotent backfill of the `_looth_uuid` usermeta from the
# authoritative profile-app Postgres users.uuid.
#
#   bin/backfill-looth-uuid.sh [--dry-run]
#
# Two stages, because no single OS user has both PG-read on the profile_app
# tables AND write on the WP DB:
#   1. dump (wp_user_id, uuid) as the `profile-app` role (peer auth)
#   2. apply via `wp eval-file` (proper update_user_meta + object cache)
#
# Ends on the WP applier's GATE: exits non-zero unless every bridged live-WP
# user has _looth_uuid == users.uuid.
set -euo pipefail
cd "$(dirname "$0")/.."

DRY=""
[ "${1:-}" = "--dry-run" ] && DRY="dry-run"   # dashless token: WP-CLI eats --flags
WP_PATH="${WP_PATH:-/var/www/dev}"

TSV="$(mktemp /tmp/looth-uuid-backfill.XXXXXX.tsv)"
chmod 644 "$TSV"
trap 'rm -f "$TSV"' EXIT

# Stage 1 — authoritative PG snapshot (profile-app role = peer auth).
sudo -u profile-app psql -d profile_app -tAF $'\t' \
  -c "SELECT b.wp_user_id, u.uuid
        FROM wp_user_bridge b
        JOIN users u ON u.id = b.user_id
       ORDER BY b.wp_user_id" > "$TSV"
echo "dumped $(wc -l < "$TSV") bridged identities"

# Stage 2 — apply + gate via WP (WP DB creds, cache-correct).
sudo -u www-data wp --path="$WP_PATH" eval-file bin/backfill-looth-uuid.php "$TSV" $DRY
