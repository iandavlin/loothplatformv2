#!/usr/bin/env bash
# lanes-poke-install — the deploy steps a `git pull` cannot do, in the repo so
# they are traceable to a commit instead of being remembered (#156).
#
#   bash tools/lanes-poke-install.sh          # the part ubuntu can do
#   sudo bash tools/lanes-poke-install.sh     # everything, including systemd
#
# Idempotent. Safe to re-run after every deploy.
set -euo pipefail

SPOOL=/home/ubuntu/.lanes-poke-request
STAMPS=/home/ubuntu/.lanes-poke
REPO=/home/ubuntu/loothplatformv2-clean

# 1. the spool and the debounce stamps. The web user (looth-dev) can traverse
#    /home/ubuntu but cannot write it, so both must exist ALREADY and be
#    world-writable — the endpoint refuses loudly when they are not, because an
#    un-debounced poke button is a board flood.
[ -e "$SPOOL" ] || : > "$SPOOL"
chmod 0666 "$SPOOL"
mkdir -p "$STAMPS"
chmod 0777 "$STAMPS"
echo "spool:  $SPOOL  ($(stat -c %a "$SPOOL"))"
echo "stamps: $STAMPS  ($(stat -c %a "$STAMPS"))"

if [ "$(id -u)" -ne 0 ]; then
    echo
    echo "NOT ROOT — these two steps still need doing, by root:"
    echo "  sudo $REPO/webroot/install-symlinks.sh --new-only   # /var/www/dev/lanes-poke.php"
    echo "  sudo systemctl enable --now lanes-poke.path         # after linking the unit files"
    exit 0
fi

# 2. the unit files, linked out of the serving checkout like every other unit
for u in lanes-poke.path lanes-poke.service; do
    ln -sf "$REPO/platform/systemd/$u" "/etc/systemd/system/$u"
done
systemctl daemon-reload
systemctl enable --now lanes-poke.path
systemctl is-active lanes-poke.path

# 3. the docroot symlink for the endpoint
"$REPO/webroot/install-symlinks.sh" --new-only >/dev/null
ls -l /var/www/dev/lanes-poke.php
