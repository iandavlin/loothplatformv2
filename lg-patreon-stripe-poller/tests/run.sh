#!/usr/bin/env bash
# Pure-logic regression tests for the poller lane (no WP, no PHPUnit).
# Exits non-zero on the first failing suite.
set -euo pipefail
cd "$(dirname "$0")/.."

fail=0
for t in tests/*_test.php; do
    echo "== $t =="
    if ! php "$t"; then
        fail=1
    fi
done

if [ "$fail" -ne 0 ]; then
    echo "POLLER TESTS: RED"
    exit 1
fi
echo "POLLER TESTS: GREEN"
