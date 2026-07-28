#!/usr/bin/env bash
# PROOF 1 — the 422 under a REAL over-cap request, against SHIPPED code on dev2.
# No overlay, no restore, no fpm reload. The code under test IS the serve.
set -uo pipefail
CK="$1"; NONCE="$2"; TOPIC=72212
URL=https://dev2.loothgroup.com/bb-mirror-api/v0/reply
OUT=/tmp/claude-1000/-home-ubuntu-worktrees-reply-images-count/b1612745-d576-42be-9768-5a19991f4207/scratchpad
: > "$OUT/proof1.jsonl"

call(){ # name method json expect
  local name="$1" method="$2" data="$3" expect="$4" code body
  body=$(curl -s -o /tmp/p1.body -w '%{http_code}' \
      --resolve dev2.loothgroup.com:443:127.0.0.1 \
      -X "$method" "$URL" \
      -H "Cookie: $CK" -H "X-WP-Nonce: $NONCE" -H 'Content-Type: application/json' \
      --data "$data")
  code="$body"; body=$(cat /tmp/p1.body)
  local verdict="FAIL"; [ "$code" = "$expect" ] && verdict="PASS"
  printf '%-4s %-6s expect %-3s got %-3s  %s\n' "$name" "$method" "$expect" "$code" "$verdict"
  printf '   %s\n' "$(head -c 220 <<<"$body")"
  python3 -c "
import json,sys
print(json.dumps({'leg':'$name','method':'$method','expect':'$expect','got':'$code','verdict':'$verdict','request':json.loads('''$data'''),'response':'''$body'''}))" >> "$OUT/proof1.jsonl" 2>/dev/null
  echo "$code"
}

echo "=================== POST — the create door ==================="
echo "--- A1: 7 images via media_ids (over cap)"
call A1 POST "{\"topic_id\":$TOPIC,\"content\":\"cap probe A1\",\"media_ids\":[1,2,3,4,5,6,7]}" 422 >/dev/null

echo "--- A2: 7 images via bbp_media — THE SHAPE COMPOSER V2 ACTUALLY SENDS"
call A2 POST "{\"topic_id\":$TOPIC,\"content\":\"cap probe A2\",\"bbp_media\":[1,2,3,4,5,6,7]}" 422 >/dev/null

echo "--- A3: 6 images — THE BOUNDARY PARTNER, one image fewer, must be 200"
call A3 POST "{\"topic_id\":$TOPIC,\"content\":\"cap probe A3 boundary\",\"media_ids\":[1,2,3,4,5,6]}" 200 >/dev/null

echo
echo "=================== PUT — the edit door ==================="
NEW=$(python3 -c "
import json
for l in open('$OUT/proof1.jsonl'):
    d=json.loads(l)
    if d['leg']=='A3':
        try: print(json.loads(d['response']).get('reply_id',0))
        except: print(0)
")
echo "    (reply created by A3 = $NEW)"

echo "--- A4: keep 4 + add 3 = 7 on the new reply (over cap)"
call A4 PUT "{\"reply_id\":$NEW,\"content\":\"cap probe A4\",\"keep_media_ids\":[1,2,3,4],\"media_ids\":[8,9,10]}" 422 >/dev/null

echo "--- A5: reply 58510 (4 REAL stored images), add 3, NO keep set"
echo "        = the add-only branch that must count what is ALREADY stored"
call A5 PUT "{\"reply_id\":58510,\"content\":\"cap probe A5\",\"media_ids\":[91,92,93]}" 422 >/dev/null

echo "--- A6: keep 3 + add 3 = 6 on the new reply — boundary partner, must be 200"
call A6 PUT "{\"reply_id\":$NEW,\"content\":\"cap probe A6 boundary\",\"keep_media_ids\":[1,2,3],\"media_ids\":[11,12,13]}" 200 >/dev/null

echo "$NEW" > "$OUT/created-reply.txt"
