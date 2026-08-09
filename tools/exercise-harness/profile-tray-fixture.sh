#!/usr/bin/env bash
# Fixture for backlog 4.4 — DEV2 ONLY, and exactly reversible.
#
# Social.php:46 only renders the "Message" button when the viewer and the profile
# are CONNECTED (state 'accepted'). The headless QA account (WP 1912 =
# profile-app claude-admin-qa) has exactly one edge and it is 'pending', so it has
# no Message button anywhere and 4.4 cannot be reproduced as it stands.
#
# This flips that ONE existing row between pending and accepted. It creates
# nothing, deletes nothing, and touches no other member.
#
#   apply   -> status = accepted   (Message button renders)
#   revert  -> status = pending    (back to how the box was found)
#   show    -> print the row
#
# NEVER run against live. All live writes are Ian's.
set -euo pipefail

QA='4e9620c9-42eb-59ca-b350-85dceca5e801'   # claude-admin-qa  (WP uid 1912)
PEER='502849b6-cccb-5e29-b1b2-1691436e3c4d' # the-guitar-specialist

# Refuse to run anywhere that is not dev2 — the row ids differ per box and a
# blind UPDATE on the wrong one is exactly the accident this guard exists for.
host=$(hostname -f 2>/dev/null || hostname)
case "$host" in
  *dev2*|ip-172-31-78-94*) ;;
  *) echo "REFUSING: this fixture is dev2-only, but hostname is '$host'" >&2; exit 2 ;;
esac

q() { sudo -u profile-app psql -d profile_app -v ON_ERROR_STOP=1 "$@"; }

show() {
  q -c "SELECT c.id, c.status, ru.slug AS requester, au.slug AS addressee, c.updated_at
        FROM connections c
        JOIN users ru ON ru.uuid = c.requester_uuid
        JOIN users au ON au.uuid = c.addressee_uuid
        WHERE (c.requester_uuid = '$PEER' AND c.addressee_uuid = '$QA')
           OR (c.requester_uuid = '$QA'   AND c.addressee_uuid = '$PEER');"
}

case "${1:-show}" in
  apply)
    q -c "UPDATE connections SET status='accepted', updated_at=now()
          WHERE requester_uuid='$PEER' AND addressee_uuid='$QA' AND status='pending';"
    show ;;
  revert)
    q -c "UPDATE connections SET status='pending', updated_at=now()
          WHERE requester_uuid='$PEER' AND addressee_uuid='$QA' AND status='accepted';"
    show ;;
  show) show ;;
  *) echo "usage: $0 {apply|revert|show}" >&2; exit 1 ;;
esac
