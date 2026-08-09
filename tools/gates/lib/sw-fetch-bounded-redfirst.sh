#!/bin/bash
# sw-fetch-bounded-redfirst — break each assertion in sw-fetch-bounded-gate and prove
# it goes RED FOR THE REASON IT CLAIMS. An assertion that has never failed is decoration.
#
# Restores from a SNAPSHOT, never `git checkout --`: checkout-from-HEAD silently discards
# work newer than the last commit, and that once turned one harness bug into ten false
# "the assertion is decoration" verdicts on another lane.
set -uo pipefail
B="$(cd "$(dirname "$0")/../../.." && pwd)"
SNAP=/tmp/sw-redfirst-snap
cd "$B" || exit 1
GATE="python3 tools/gates/sw-fetch-bounded-gate.py"

FILES=(
  webroot/sw.js
  webroot/pwa-loader.php
  platform/config/pwa-sw.php
  tools/gates/lib/sw-handler-harness.js
)
rm -rf "$SNAP"; mkdir -p "$SNAP"
for f in "${FILES[@]}"; do mkdir -p "$SNAP/$(dirname "$f")"; cp "$f" "$SNAP/$f"; done
restore() { for f in "${FILES[@]}"; do cp "$SNAP/$f" "$f"; done; }
trap restore EXIT

mutate() { python3 -c "
import sys
p, old, new = sys.argv[1], sys.argv[2], sys.argv[3]
s = open(p).read()
if old not in s:
    print('  MUTATION DID NOT APPLY: pattern absent from ' + p); sys.exit(9)
open(p, 'w').write(s.replace(old, new, 1))
" "$@"; }

case_run() {   # case_run <label> <expect-substring>
  local label="$1" expect="$2" out rc fails
  out=$($GATE 2>&1); rc=$?
  fails=$(printf '%s\n' "$out" | grep -c "^  FAIL")
  printf '%-54s exit=%s fails=%-3s ' "$label" "$rc" "$fails"
  if [ "$rc" = "1" ] && printf '%s\n' "$out" | grep -q "FAIL.*$expect"; then
    echo "RED as intended"
  else
    echo "*** NOT RED FOR '$expect' -- ASSERTION IS DECORATION ***"
    printf '%s\n' "$out" | grep -E "^  (FAIL|CANNOT)" | head -4
  fi
  restore
}

control_run() {   # control_run <label> — a mutation that must NOT change the verdict
  local label="$1" out rc
  out=$($GATE 2>&1); rc=$?
  printf '%-54s exit=%s ' "$label" "$rc"
  if [ "$rc" = "0" ]; then echo "still GREEN as intended (assertion is not over-coupled)"
  else
    echo "*** WENT RED -- the gate is coupled to something it should not be ***"
    printf '%s\n' "$out" | grep "^  FAIL" | head -4
  fi
  restore
}

S=webroot/sw.js
H=tools/gates/lib/sw-handler-harness.js
C=platform/config/pwa-sw.php

echo "baseline (unmutated) must be GREEN:"
$GATE >/dev/null 2>&1 && echo "  green, exit 0" || echo "  *** BASELINE NOT GREEN ***"
echo

# 1. THE DECISIVE ONE: remove the deadline, so a hang strands again even with the flag on.
mutate "$S" "    const half = Math.max(1200, Math.floor(NAV_TIMEOUT_MS / 2));" \
            "    const half = 100000000;" \
  && case_run "1. deadline removed (hang strands with flag ON)" "HUNG fetch SETTLES"

# 2. The retry traded away for the deadline — slow-then-ok must not regress.
mutate "$S" "        .catch(() => new Promise((res) => setTimeout(res, 350))
          .then(() => fetchWithDeadline(req, NAV_TIMEOUT_MS - half)))" \
            "" \
  && case_run "2. the one retry deleted" "retry STILL wins a transient blip"

# 3. Dev-path bypass removed -> the SW intercepts /footer-mockups/ again.
mutate "$S" "  if (reqUrl && isBypassed(reqUrl)) return;" "" \
  && case_run "3. dev-path bypass removed" "dev-gated path is NOT intercepted"

# 4. The claim prompt stops offering the door.
mutate "$S" "'<a class=\"lo-btn\" href=\"/claim\">Open the claim page</a>' +" "" \
  && case_run "4. claim prompt drops the /claim link" "offers the /claim door"

# 5. The 403 is handed over raw again.
mutate "$S" "          if (resp && resp.status === 403 && IS_DEV2) return claimPrompt(reqUrl);" "" \
  && case_run "5. gate 403 handed over raw" "becomes a page, not a raw nginx 403"

# 6. Install back to all-or-nothing.
# The FIRST attempt at this mutation was a bad mutation, not a weak assertion: it left
# `.then(onOk, onRejected)` in place, so install still RESOLVED via the rejection handler
# and the defect was never reintroduced. Drop the handler as well.
mutate "$S" "    )).then(() => self.skipWaiting(), () => self.skipWaiting())" \
            "    )).then(() => self.skipWaiting())" \
  && mutate "$S" "caches.open(CACHE).then((c) => Promise.all(SHELL.map((u) =>
      fetch(u, { cache: 'reload' })
        .then((r) => (r && r.ok ? c.put(u, r) : null))
        .catch(() => null)
    ))" "caches.open(CACHE).then((c) => c.addAll(SHELL)" \
  && case_run "6. install back to all-or-nothing" "install SURVIVES a 403 shell asset"

# 7. dev2-only guard inverted -> a claim prompt could reach LIVE members.
mutate "$S" "const IS_DEV2 = self.location.hostname === 'dev2.loothgroup.com';" \
            "const IS_DEV2 = self.location.hostname !== 'nonexistent.example';" \
  && control_run "7. CONTROL: dev2 hostname test widened"

# 8. HARNESS FIDELITY: drop setTimeout from the stubbed scope. This is the one that
#    silently made a browser-impossible path look like a pass.
mutate "$H" "    setTimeout, clearTimeout, setInterval, clearInterval," \
            "    clearTimeout, setInterval, clearInterval," \
  && case_run "8. harness scope loses setTimeout" "setTimeout is real"

# 9. HARNESS FIDELITY: drop URL, so the flag reads as absent and every ON case is vacuous.
mutate "$H" "    URL, URLSearchParams, Response, Request, Headers, AbortController," \
            "    URLSearchParams, Response, Request, Headers, AbortController," \
  && case_run "9. harness scope loses URL (flag unreadable)" "URL/searchParams are real"

# 10. The flag shipped ARMED with no recorded decision.
mutate "$C" "'resilient_fetch' => false," "'resilient_fetch' => true," \
  && case_run "10. flag ARMED with no recorded decision" "recorded in the audit doc"

# 11. The gate must read the CONTENT, not the truncated display field. Shrinking the
#     display field must NOT break the claim assertions — that bug shipped once.
mutate "$H" "    out.served = text === null ? '(empty)' : flat.slice(0, 90);" \
            "    out.served = text === null ? '(empty)' : flat.slice(0, 5);" \
  && control_run "11. CONTROL: display field truncated to 5 chars"

echo
restore
echo "tree restored; git status:"; git -C "$B" status --short -- "${FILES[@]}"
