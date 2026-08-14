#!/usr/bin/env bash
# keeper-mail "Subject" "Body..." — emails Ian via the WP Fluent/SES stack
# (Ian 8/14: "when work is done. Can you email me at ian.davlin@gmail.com?"
#  + "you can use the wordpress ses fluent setup").
# dev2 has NO local MTA (verified 7/29) — WP's configured mailer is the ONE
# working transport on this box. wp_mail returning true = handed to the stack.
# USE FOR: work-done moments (deliverable ready, decision waiting, batch
# landed/live-ready). NOT routine progress — an inbox ping that teaches Ian
# to ignore pings is worse than none.
set -euo pipefail
SUBJ="${1:?usage: keeper-mail \"Subject\" \"Body\"}"
BODY="${2:?usage: keeper-mail \"Subject\" \"Body\"}"
S="$SUBJ" B="$BODY" sudo -u looth-dev -E wp eval \
  'exit(wp_mail("ian.davlin@gmail.com", getenv("S"), getenv("B")) ? 0 : 1);' \
  --path=/var/www/dev 2>/dev/null && echo "mailed: $SUBJ"
