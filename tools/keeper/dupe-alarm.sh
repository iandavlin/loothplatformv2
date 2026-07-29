#!/usr/bin/env bash
# dupe-alarm — daily duplicate-Patreon-ID check against LIVE (read-only).
# The poller bug's signature is one Patreon id attached to two WP accounts;
# this is the definition-level test from docs/atlas/POLLER-ONBOARDING-AUDIT.md §1.6.
# Mails Ian ONLY when it finds one, or when the check itself fails to run —
# a dead alarm must never read as "all clear".
# --selftest sends a test mail to prove the delivery path end to end.
set -uo pipefail

TO="ian.davlin@gmail.com"

if [ "${1:-}" = "--selftest" ]; then
    printf 'Subject: [dupe-alarm] test — delivery path works\n\nThis is the one-time self-test of the daily duplicate alarm on dev2.\nIf you are reading this, alarm mail reaches you. No duplicates were involved.\n' | sendmail "$TO"
    echo "selftest mail handed to sendmail"
    exit 0
fi

SQL="SELECT meta_value, COUNT(*) c FROM wp_usermeta WHERE meta_key='lgpo_patreon_user_id' AND meta_value<>'' GROUP BY meta_value HAVING c>1;"
OUT=$(ssh -o BatchMode=yes -o ConnectTimeout=20 live-ro "mysql --defaults-file=/home/looth-ro/.my.cnf -N -B looth_import -e \"$SQL\"" 2>&1)
RC=$?

if [ $RC -ne 0 ]; then
    printf 'Subject: [dupe-alarm] CHECK FAILED to run\n\nThe daily duplicate check could not query live (exit %s):\n\n%s\n' "$RC" "$OUT" | sendmail "$TO"
    echo "dupe-alarm: FAILED to query live (rc=$RC)" >&2
    exit 1
fi

if [ -n "$OUT" ]; then
    printf 'Subject: [dupe-alarm] DUPLICATE Patreon id on live\n\nOne Patreon id is attached to more than one WP account (id<TAB>count):\n\n%s\n\nThis is the exact signature of the old poller duplicate bug. Tell keeper.\n' "$OUT" | sendmail "$TO"
    echo "dupe-alarm: DUPLICATES FOUND"
    exit 2
fi

echo "dupe-alarm: clean"
exit 0
