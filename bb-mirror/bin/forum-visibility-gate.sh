#!/bin/bash
# forum-visibility-gate.sh — bb-mirror forum-visibility / author-leak gate.
#
# Encodes the defect classes fixed 2026-06-13 (CRAFT law, docs/CRAFT-STANDARD.md
# — a defect class found twice MUST become a gate before the second fix):
#   C2  anon GET /hub/?body=<topic in a HIDDEN forum>     -> 404  (no body leak)
#   C2  anon GET /hub/?replies=<topic in a HIDDEN forum>  -> 404  (no reply leak)
#   H6  anon single-topic of a MEMBER-visibility author   -> author masked
#        ("Private member", no /u/<slug> link, real name absent)
#
# Wired into tools/gates/run-all.sh (GATE 3). Hits the SERVED dev URLs as an
# anonymous viewer (dev cookie-gate only, NO WP login) so it tests the real
# end-to-end behavior the way a logged-out visitor sees it. Fails LOUD (never a
# silent green): if it can't reach /hub/ it errors instead of skipping.
#
# Runs as the dev sysadmin (ubuntu) under run-all.sh: psql via `sudo -u bb-mirror`
# (peer auth) and the dev token from the nginx conf. Override the token with
# LOOTHDEV_TOKEN to run elsewhere. At cut the dev gate drops; pass it empty then.
set -uo pipefail

HOST="dev.loothgroup.com"
BASE="https://$HOST"
RES="--resolve $HOST:443:127.0.0.1"
PSQL="sudo -u bb-mirror psql -d looth -tAqc"
fail=0
note(){ printf '  %s\n' "$1"; }

# Dev cookie-gate token (drops at cut). env override > nginx conf.
TOKEN="${LOOTHDEV_TOKEN:-}"
if [ -z "$TOKEN" ]; then
  TOKEN=$(sudo grep -oP 'set \$loothdev_token "\K[^"]+' "/etc/nginx/sites-available/$HOST.conf" 2>/dev/null | head -1)
fi
COOKIE=()
[ -n "$TOKEN" ] && COOKIE=(-H "Cookie: loothdev_auth=$TOKEN")

code(){   curl -sk -o /dev/null -w '%{http_code}' $RES "${COOKIE[@]}" "$1"; }
bodyof(){ curl -sk $RES "${COOKIE[@]}" "$1"; }

# Sanity: an anon-but-dev-gated viewer must be able to reach /hub/, else every
# assertion below is meaningless. Fail loud rather than skip.
hub_code=$(code "$BASE/hub/")
if [ "$hub_code" = "403" ]; then
  note "FAILED: cannot pass dev gate to reach /hub/ (set LOOTHDEV_TOKEN) — gate cannot verify"
  exit 1
fi

# ── C2: a topic in a HIDDEN (non-public) forum must 404 on body + replies ────
HID=$($PSQL "SELECT t.id FROM forums.topic t JOIN forums.forum f ON f.id=t.forum_id
             WHERE f.visibility<>'public' AND t.status='publish' ORDER BY t.id LIMIT 1;" \
      2>/dev/null | tr -d '[:space:]')
if [ -z "$HID" ]; then
  note "WARN: no hidden-forum topic present — C2 body/replies leak check not exercised"
else
  c=$(code "$BASE/hub/?body=$HID")
  [ "$c" = "404" ] && note "PASS  C2 body    hidden topic $HID -> 404" \
                    || { note "FAILED: C2 body hidden topic $HID -> $c (expect 404)"; fail=1; }
  c=$(code "$BASE/hub/?replies=$HID")
  [ "$c" = "404" ] && note "PASS  C2 replies hidden topic $HID -> 404" \
                    || { note "FAILED: C2 replies hidden topic $HID -> $c (expect 404)"; fail=1; }
fi

# ── H6: a member-visibility discussion author must be masked on the permalink ─
read -r MID MFS MTS MSLUG < <(
  $PSQL "SELECT t.id||'|'||f.slug||'|'||t.slug||'|'||p.slug
           FROM forums.topic t
           JOIN forums.forum  f ON f.id=t.forum_id
           JOIN forums.person p ON p.id=t.author_id
          WHERE f.visibility='public' AND t.status IN ('publish','closed')
            AND COALESCE(p.discussion_visibility,'member')='member' AND p.slug IS NOT NULL
          ORDER BY t.id LIMIT 1;" 2>/dev/null | tr '|' ' '
)
if [ -z "${MID:-}" ]; then
  note "WARN: no member-visibility discussion present — H6 author-mask check not exercised"
else
  html=$(bodyof "$BASE/hub/$MFS/$MTS/")
  if echo "$html" | grep -q 'Private member' && ! echo "$html" | grep -q "/u/$MSLUG"; then
    note "PASS  H6 single-topic $MID author masked (Private member, no /u/$MSLUG link)"
  else
    note "FAILED: H6 single-topic $MID author NOT masked (want 'Private member', no /u/$MSLUG)"
    fail=1
  fi
fi

if [ "$fail" -ne 0 ]; then echo "  ###### forum-visibility gate RED ######"; exit 1; fi
echo "  forum-visibility gate GREEN"
exit 0
