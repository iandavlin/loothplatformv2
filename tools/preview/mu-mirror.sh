#!/bin/bash
# mu-mirror.sh — build a mu-plugin directory that is the SERVE's, with ONE file
# swapped for a branch's copy. Nothing on the serve is touched.
#
#   tools/preview/mu-mirror.sh <branch-file> <out-dir>
#
# WHY THIS EXISTS. "The serve only carries merged code" is the reason unreviewed
# work keeps reaching Ian in production shape. It is true of the CHECKOUT and
# false of WordPress: core sets WPMU_PLUGIN_DIR with `if ( ! defined(...) )`, so
# anything that defines it first wins. Gate 88 already uses that for wp-cli; this
# is the same trick made reusable, so an nginx preview can serve a branch's
# mu-plugin over HTTP to a real browser.
#
# ⚠️ A PARTIAL MIRROR IS A DIFFERENT WORDPRESS. Every entry must link or this
# refuses — a mirror missing one mu-plugin would render a page whose differences
# have nothing to do with the branch.
#
# ⚠️ IT LINKS, IT DOES NOT COPY. The 42 other mu-plugins stay exactly the files
# the serve is running, so what you measure is one file's difference.
set -uo pipefail

MU_SRC="${LG_MU_SRC:-/var/www/dev/wp-content/mu-plugins}"
branch="${1:-}"; out="${2:-}"
[ -n "$branch" ] && [ -n "$out" ] || { echo "usage: $0 <branch-file> <out-dir>" >&2; exit 2; }
[ -r "$branch" ] || { echo "mu-mirror: cannot read $branch" >&2; exit 1; }

base="$(basename "$branch")"
branch="$(readlink -f "$branch")"

rm -rf "$out" || true
mkdir -p "$out" || { echo "mu-mirror: cannot create $out" >&2; exit 1; }
chmod 755 "$out"

mapfile -t entries < <(sudo find "$MU_SRC" -maxdepth 1 -mindepth 1)
[ "${#entries[@]}" -gt 0 ] || { echo "mu-mirror: $MU_SRC is empty — a mirror of it is a WordPress with no mu-plugins" >&2; exit 1; }

swapped=0 failed=0
for src in "${entries[@]}"; do
    b="$(basename "$src")"
    real="$(sudo readlink -f "$src")"
    [ -n "$real" ] || continue
    if [ "$b" = "$base" ]; then real="$branch"; swapped=1; fi
    ln -s "$real" "$out/$b" || { echo "mu-mirror: could not link $b" >&2; failed=$((failed+1)); }
done

[ "$failed" -eq 0 ] || { echo "mu-mirror: $failed link(s) failed — refusing a partial mirror" >&2; exit 1; }
[ "$swapped" -eq 1 ] || { echo "mu-mirror: $base is not among the mu-plugins in $MU_SRC, so the mirror would load it ALONGSIDE the serve's copy" >&2; exit 1; }

# ASSERTED, NOT ASSUMED. Every step above can succeed and still leave the mirror
# resolving to the serve's file — which is exactly how a preview shows main.
got="$(readlink -f "$out/$base")"
[ "$got" = "$branch" ] || { echo "mu-mirror: $out/$base resolves to $got, not $branch" >&2; exit 1; }

echo "mu-mirror: $out — ${#entries[@]} mu-plugins, $base -> $branch"
