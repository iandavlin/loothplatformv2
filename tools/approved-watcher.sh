#!/usr/bin/env bash
# approved-watcher — #138 phase A (Ian approved 8/19): the approved label
# becomes a doorbell. Every 5 minutes (platform/systemd/approved-watcher.timer,
# dev2 only): any OPEN issue newly carrying `approved` rings Ian's bell file
# and posts a board line for keeper's next pass. State = one issue number per
# line in ~/.approved-acks, so nothing double-fires. Phase B (auto-spin) is
# deliberately NOT here — it waits out A's probation, per the plan.
set -euo pipefail

TOKEN="$(grep '^LG_GITHUB_ISSUES_TOKEN=' /etc/looth/env | cut -d= -f2)"
[[ -n "$TOKEN" ]] || exit 0
STATE="/home/ubuntu/.approved-acks"
touch "$STATE"

curl -s -m 20 -H "Authorization: Bearer $TOKEN" \
    'https://api.github.com/repos/iandavlin/loothplatformv2/issues?labels=approved&state=open&per_page=50' \
| python3 -c 'import json,sys
for i in json.load(sys.stdin):
    print(i["number"], i["title"][:70])' \
| while read -r n title; do
    grep -qx "$n" "$STATE" && continue
    echo "$n" >> "$STATE"
    # board posts are shell-evaluated — strip the characters that execute
    safe_title=$(printf '%s' "$title" | tr -d '`$"\\')
    msg send ubuntu "approved-watcher: issue #$n is APPROVED and awaiting keeper — $safe_title" 2>/dev/null || true
    touch /tmp/claude-ian-action
done
