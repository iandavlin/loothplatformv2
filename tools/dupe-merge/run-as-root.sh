#!/usr/bin/env bash
# Wrapper for merge-dupes.php.
#
# The merge spans two MySQL databases and two Postgres databases. Postgres here
# is peer-auth only, and no single role owns both profile_app (owner
# `profile-app`) and looth (owner `bb-mirror`), so the tool has to run as the
# `postgres` OS user. That user cannot read wp-config.php, so this wrapper —
# which does run as root — lifts the MySQL credentials out and passes them
# through the environment.
#
#   sudo tools/dupe-merge/run-as-root.sh --dry-run --all
#   sudo tools/dupe-merge/run-as-root.sh --apply --pair="larry dent"
#   sudo tools/dupe-merge/run-as-root.sh --rollback --journal=/path/to.json
#
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
[[ $EUID -eq 0 ]] || { echo "must run as root (it reads wp-config.php)" >&2; exit 2; }

WP_PATH="$(sed -n 's/^LG_WP_PATH=//p' /etc/looth/env 2>/dev/null || true)"
WP_PATH="${WP_PATH:-/var/www/dev}"
CFG="$WP_PATH/wp-config.php"
[[ -r "$CFG" ]] || { echo "cannot read $CFG" >&2; exit 2; }

grab() { sed -n "s/.*define(\s*['\"]$1['\"]\s*,\s*['\"]\(.*\)['\"]\s*).*/\1/p" "$CFG" | head -1; }

LG_MY_NAME="$(grab DB_NAME)"
LG_MY_USER="$(grab DB_USER)"
LG_MY_PASS="$(grab DB_PASSWORD)"
LG_MY_HOST="$(grab DB_HOST)"
export LG_MY_NAME LG_MY_USER LG_MY_PASS LG_MY_HOST

# journal must survive as root-owned; postgres needs to write into it
JDIR="${LG_MERGE_JOURNAL_DIR:-$HERE/journal}"
mkdir -p "$JDIR"; chown postgres "$JDIR"; chmod 750 "$JDIR"

exec sudo -u postgres --preserve-env=LG_MY_NAME,LG_MY_USER,LG_MY_PASS,LG_MY_HOST \
     php "$HERE/merge-dupes.php" --journal-dir="$JDIR" "$@"
