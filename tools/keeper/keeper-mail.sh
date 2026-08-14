#!/usr/bin/env bash
# keeper-mail "Subject" "Body..." — emails Ian THROUGH WordPress' Fluent/SES
# stack via the CLI-only keeper pass in lg-dev-mail-containment.php.
# (Ian 8/14: work-done emails; ruled "Send THROUGH WordPress" — no raw key on
#  the box.) The pass is double-locked: env var = CLI-only, and the containment
# still traps any recipient except ian.davlin@gmail.com alone.
# USE FOR work-done moments only — never routine progress.
set -euo pipefail
SUBJ="${1:?usage: keeper-mail \"Subject\" \"Body\"}"
BODY="${2:?usage: keeper-mail \"Subject\" \"Body\"}"
S="$SUBJ" B="$BODY" sudo -u looth-dev LG_KEEPER_MAIL_PASS=1 S="$SUBJ" B="$BODY" \
  wp eval 'exit(wp_mail("ian.davlin@gmail.com", getenv("S"), getenv("B")) ? 0 : 1);' \
  --path=/var/www/dev 2>/dev/null && echo "mailed: $SUBJ"
