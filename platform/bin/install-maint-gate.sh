#!/usr/bin/env bash
# install-maint-gate.sh — box-local provisioning for the maintenance write-freeze gate.
# Drops the map (http) + the gate snippet (server) and wires the include into the
# site server block. Idempotent — re-run after every git pull. Must run as root.
#   sudo platform/bin/install-maint-gate.sh
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "must run as root (sudo)"; exit 1; }
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
install -m644 "$REPO/platform/nginx/lg-write-freeze-map.conf" /etc/nginx/conf.d/00-lg-write-freeze-map.conf
install -m644 "$REPO/platform/nginx/lg-write-freeze.conf"     /etc/nginx/snippets/lg-write-freeze.conf
# find the site server conf (the one that includes the strangler snippets) and wire the include once
CONF="$(grep -rl 'strangler-archive-poc.conf' /etc/nginx/sites-enabled/ /etc/nginx/sites-available/ 2>/dev/null | head -1)"
[ -n "$CONF" ] || { echo "could not find site server conf"; exit 1; }
CONF="$(readlink -f "$CONF")"
if ! grep -q 'snippets/lg-write-freeze.conf' "$CONF"; then
  cp -a "$CONF" "$CONF.bak-maintgate-$(date +%Y%m%d-%H%M%S)"
  sed -i '0,/^\s*root \/var\/www\/dev;/s//&\n    include \/etc\/nginx\/snippets\/lg-write-freeze.conf;/' "$CONF"
fi
nginx -t && systemctl reload nginx
echo "maint-gate installed. ON: touch /etc/nginx/lg-write-freeze.flag   OFF: rm it"
