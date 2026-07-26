#!/usr/bin/env bash
# install-symlinks.sh — convert the docroot's webroot static layer to symlinks into
# this repo directory, so DEPLOY = GIT PULL (deploy-one-pull 2026-07-26). Replaces
# the old rsync-copier deploy.sh, which is what institutionalized the copy layer.
#
#   sudo ./install-symlinks.sh [--new-only] [WEBROOT]
#     WEBROOT     target docroot (default: /var/www/dev)
#     --new-only  only create links for repo files with NO docroot entry (safe fast
#                 path lg-deploy runs after every pull so new assets need no step)
#
# Safety model (same on dev2 and live):
#   - A real docroot file is converted ONLY if byte-identical (md5) to the repo copy.
#     On ANY mismatch the file is SKIPPED and reported loudly — the box may carry
#     newer bytes than the repo (reverse drift) or a lane overlay; never clobber.
#   - Originals are MOVED to a timestamped dir under /home/ubuntu/deploy-backups/
#     (never left in the docroot — backups in a public docroot are fetchable).
#     Rollback for any file f:  mv <BACKUP>/f <WEBROOT>/f
#   - Idempotent: correct links are left alone; wrong-target links are repaired
#     (old target recorded in the backup dir's RELINKED.txt).
# Exit codes: 0 clean, 2 if any file was skipped on mismatch.
set -euo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)"
REPO="$(dirname "$SRC")"
NEW_ONLY=0
[ "${1:-}" = "--new-only" ] && { NEW_ONLY=1; shift; }
WEBROOT="${1:-/var/www/dev}"
[ -d "$WEBROOT" ] || { echo "ERROR: webroot '$WEBROOT' does not exist" >&2; exit 1; }

STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="/home/ubuntu/deploy-backups/webroot-symlinks-$STAMP"
skipped=0 converted=0 linked=0 repaired=0

bk() { mkdir -p "$BACKUP"; }

# link TARGET as WEBROOT/NAME with the full safety model
link_one() {
    local name="$1" target="$2" t
    t="$WEBROOT/$name"
    if [ -L "$t" ]; then
        if [ "$(readlink -f "$t")" = "$(readlink -f "$target")" ]; then return 0; fi
        bk; echo "$t was -> $(readlink "$t")" >> "$BACKUP/RELINKED.txt"
        ln -sfn "$target" "$t"; repaired=$((repaired+1))
        echo "REPAIRED  $name (wrong link target; old target recorded)"
    elif [ -e "$t" ]; then
        [ "$NEW_ONLY" = 1 ] && return 0
        if [ -d "$t" ]; then
            if diff -rq "$t" "$target" >/dev/null 2>&1; then
                bk; mv "$t" "$BACKUP/$name"; ln -s "$target" "$t"; converted=$((converted+1))
                echo "CONVERTED $name/ (dir, contents identical; original in $BACKUP)"
            else
                skipped=$((skipped+1))
                echo "SKIPPED   $name/ — dir differs from repo (extra/changed files):"
                diff -rq "$t" "$target" 2>&1 | sed 's/^/            /' | head -10
            fi
        else
            local a b
            a=$(md5sum "$t" | cut -d' ' -f1); b=$(md5sum "$target" | cut -d' ' -f1)
            if [ "$a" = "$b" ]; then
                bk; mv "$t" "$BACKUP/$name"; ln -s "$target" "$t"; converted=$((converted+1))
                echo "CONVERTED $name (original in $BACKUP)"
            else
                skipped=$((skipped+1))
                echo "SKIPPED   $name — docroot=$a repo=$b (box differs from repo; capture or overlay in progress?)"
            fi
        fi
    else
        ln -s "$target" "$t"; linked=$((linked+1))
        echo "LINKED    $name (new)"
    fi
}

# Top-level repo-managed files (everything here except repo meta files)
for f in "$SRC"/*; do
    name="$(basename "$f")"
    case "$name" in README.md|install-symlinks.sh|.gitignore) continue ;; esac
    [ -f "$f" ] || continue
    link_one "$name" "$f"
done

# Repo-managed subdirectories (whole-dir links, the lg-shared/lg-snippets pattern)
for d in icons lib push sponsors-deck; do
    [ -d "$SRC/$d" ] && link_one "$d" "$SRC/$d"
done

# img.php is git-homed in bb-mirror (see README) — deployed from there, not here
[ -f "$REPO/bb-mirror/web/img.php" ] && link_one "img.php" "$REPO/bb-mirror/web/img.php"

echo "----"
echo "converted=$converted linked=$linked repaired=$repaired skipped=$skipped"
[ -d "$BACKUP" ] && echo "originals: $BACKUP (rollback: mv $BACKUP/<f> $WEBROOT/<f>)"
[ "$skipped" -gt 0 ] && exit 2
exit 0
