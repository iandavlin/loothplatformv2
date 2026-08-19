#!/usr/bin/env bash
# approve-issue.sh <n> — apply the 'approved' label to an issue.
#
# RUN ONLY on Ian's literal "SPIN <n>" (his single verbal door, ruling 8/19).
# Normally Ian clicks the label himself on GitHub; this script exists so the
# SPIN phrase still leaves a record on the issue of who approved and when
# (the label event is stamped by the token's owner — Ian's own fine-grained
# token). There is exactly one door; this is a key to that same door, never
# a second door. "Sounds good" / "go ahead" / anything else runs NOTHING.
set -euo pipefail
N="${1:?usage: approve-issue.sh <issue-number>}"
TOKEN="$(grep '^LG_GITHUB_ISSUES_TOKEN=' /etc/looth/env | cut -d= -f2)"
[[ -n "$TOKEN" ]] || { echo "approve-issue: no token in /etc/looth/env" >&2; exit 1; }
curl -sf -X POST -H "Authorization: Bearer $TOKEN" \
    -d '{"labels":["approved"]}' \
    "https://api.github.com/repos/iandavlin/loothplatformv2/issues/$N/labels" >/dev/null
echo "issue #$N: 'approved' applied — on the record, through the one door"
