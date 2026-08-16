#!/bin/bash
# lane-preview.sh — give a BRANCH a URL Ian can click, on the vhost he is
# already signed into. Idempotent up / down / status.
#
#   tools/preview/lane-preview.sh up      account-following
#   tools/preview/lane-preview.sh status  account-following
#   tools/preview/lane-preview.sh down    account-following
#
# WHY THIS EXISTS. "You cannot preview a branch" was believed to be a fact on
# this box, and believing it is what got unreviewed work merged so that Ian found
# the defects in production-shaped code. It is not a fact. :443 is already
# listening, already cookie-gated, already holds a valid cert and Ian's session —
# a branch only needs a PATH on it.
#
# WHAT IT DOES, EXACTLY, AND NOTHING ELSE
#   1. copies platform/nginx/lane-preview-<lane>.conf to /etc/nginx/snippets/
#   2. adds ONE include line to the dev2 server block, between two markers
#   3. nginx -t — and on failure RESTORES THE VHOST FROM BACKUP BEFORE ANYTHING
#      ELSE, so a bad snippet can never leave :443 unable to reload
#   4. reloads, then proves the WORKERS actually restarted
#
# It never touches ~/loothplatformv2-clean, never re-points a symlink the running
# system uses, never edits an existing location, and never creates an FPM pool.
#
# ADOPTING IT FOR ANOTHER LANE is one file: write
# platform/nginx/lane-preview-<lane>.conf with your locations, all under
# /preview/<lane>/, and run this. Read the account-following one first — the two
# `^~` prefixes in it are load-bearing, not decoration.
set -uo pipefail

VHOST="/etc/nginx/sites-enabled/dev2.loothgroup.com.conf"
SNIPDIR="/etc/nginx/snippets"
REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
BEGIN="# >>> lane-preview BEGIN (tools/preview/lane-preview.sh) — REMOVABLE"
END="# <<< lane-preview END"
# GLOB include, not a per-lane one. The vhost carries ONE permanent
# `include .../lane-preview-*.conf;` between the markers (this is what actually
# picks up every lane's snippet now) — a prior version of this script instead
# grep/sed'd in a SEPARATE, lane-specific include line on every `up`. That
# double-includes the file the moment the glob is also present: nginx reads the
# same locations twice and refuses with "duplicate location". Found 2026-08-16
# when featured-members' own `up` started failing that way on a vhost that
# already had the glob (someone had already landed it centrally, fixing the
# "include keeps dropping" problem this file's own header used to warn about;
# the add/remove logic here just never caught up). BOX-WIDE: every lane's `up`
# hits this on next run until this file is updated on their branch too.
inc="    include $SNIPDIR/lane-preview-*.conf;"

cmd="${1:-}"; lane="${2:-}"
[ -n "$cmd" ] && [ -n "$lane" ] || { echo "usage: $0 {up|down|status} <lane>"; exit 2; }

src="$REPO/platform/nginx/lane-preview-$lane.conf"
dst="$SNIPDIR/lane-preview-$lane.conf"

die() { echo "lane-preview: $*" >&2; exit 1; }

# The include block lives just before the server block's closing brace. Finding
# that line rather than hardcoding one is what keeps this working after the vhost
# is edited by someone else. Inserts the GLOB include (see $inc above) — a lane
# never needs its own include line, only its snippet file present in $SNIPDIR.
ensure_markers() {
    grep -q "$BEGIN" "$VHOST" && return 0
    local last
    last=$(grep -n '^}' "$VHOST" | tail -1 | cut -d: -f1)
    [ -n "$last" ] || die "cannot find the vhost's closing brace"
    sudo -n sed -i "${last}i\\
$BEGIN\\
$inc\\
$END" "$VHOST" || die "could not add markers"
}

reload_or_revert() {
    local backup="$1"
    if ! sudo -n nginx -t >/tmp/lane-preview-nginx-t.log 2>&1; then
        echo "nginx -t FAILED — restoring the vhost before anything else:"
        sudo -n cp "$backup" "$VHOST"
        sudo -n nginx -t >/dev/null 2>&1 \
            && echo "  vhost restored, config valid again. :443 was never reloaded." \
            || echo "  ⚠️ RESTORE DID NOT VALIDATE — DO NOT RELOAD. Backup: $backup"
        sed 's/^/    /' /tmp/lane-preview-nginx-t.log
        exit 1
    fi
    # Workers before, workers after. A conf that passed -t but was never reloaded
    # is the classic "I deployed it" that did not: same worker PIDs, old config.
    local before after
    before=$(pgrep -f '[n]ginx: worker' | sort | tr '\n' ' ')
    sudo -n systemctl reload nginx || die "reload failed"
    sleep 1
    after=$(pgrep -f '[n]ginx: worker' | sort | tr '\n' ' ')
    if [ "$before" = "$after" ]; then
        echo "⚠️ WORKERS DID NOT RESTART (same PIDs: $after)"
        echo "   The config on disk is not the config being served. Investigate."
        return 1
    fi
    echo "workers restarted: $before -> $after"
}

case "$cmd" in
up)
    [ -f "$src" ] || die "no snippet at $src — write it first (see the account-following one)"
    sudo -n nginx -t >/dev/null 2>&1 || die "nginx config was ALREADY broken before this ran; fix that first"
    backup="/tmp/lane-preview-vhost-$(id -u).bak"
    sudo -n cp "$VHOST" "$backup"

    sudo -n cp "$src" "$dst" || die "could not install the snippet"
    ensure_markers
    reload_or_revert "$backup" || exit 1
    echo "UP: https://dev2.loothgroup.com/preview/$lane/"
    ;;
down)
    backup="/tmp/lane-preview-vhost-$(id -u).bak"
    sudo -n cp "$VHOST" "$backup"
    # The glob include (see $inc above) is shared by every lane and stays;
    # only this lane's own snippet file is removed.
    sudo -n rm -f "$dst"
    reload_or_revert "$backup" || exit 1
    echo "DOWN: /preview/$lane/ removed"
    ;;
status)
    echo "snippet installed : $([ -f "$dst" ] && echo yes || echo no)"
    echo "include present   : $(grep -qF "$inc" "$VHOST" && echo yes || echo no)"
    printf 'serves            : '
    curl -s -o /dev/null -w '%{http_code}\n' --resolve dev2.loothgroup.com:443:127.0.0.1 \
        "https://dev2.loothgroup.com/preview/$lane/" 2>/dev/null || echo "(curl failed)"
    ;;
*)
    echo "usage: $0 {up|down|status} <lane>"; exit 2;;
esac
