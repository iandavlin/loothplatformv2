#!/bin/bash
# serve-tree.sh <tree-root> <port> — stand up ONE instance of the events app out of
# an arbitrary checkout, on a loopback port, WITHOUT touching the serve.
#
# Faithfulness notes (this is the whole point of the harness):
#   * runs as uid `events`, the SAME user php-fpm's events pool runs as, so the real
#     config.php reads the real /etc/lg-events-db at the real hardcoded path. No byte
#     of the tree under test is modified — not even the config.
#   * hits the REAL dev2 WP database (looth_import). Same store the serve reads.
#   * /srv/lg-shared chrome is included read-only, same as the serve does.
#   * nginx is never touched and the serving checkout is never written to.
set -uo pipefail
TREE="$1"; PORT="$2"
[ -d "$TREE/events/web" ] || { echo "no events/web under $TREE" >&2; exit 1; }
exec sudo -n -u events php -S "127.0.0.1:$PORT" -t "$TREE/events/web" "$TREE/events/web/index.php"
