#!/usr/bin/env bash
# mirror-reconcile-poison-redfirst.sh — break every assertion in
# tools/gates/mirror-reconcile-poison-gate.php, one at a time, and prove each one
# actually goes RED. A gate nobody has seen fail is decoration.
#
# It earned its keep on the first run: it caught TWO decorative assertions in the
# gate it was written to check —
#   * "the bookmark write is present" was a str_contains('last_reconcile_at'),
#     which passes happily on 'last_reconcile_at_DISABLED'. Now lexed as an exact
#     string literal.
#   * "the walk survives a throwing row" let the exception escape the gate itself,
#     so the run died at exit 255 with a stack trace instead of reporting a
#     finding. Now caught and reported.
#
# House rules this obeys (keeper memory, each learned the hard way):
#   * SNAPSHOT, never `git checkout --`. Checkout-from-HEAD wipes uncommitted work
#     under test and turns one harness bug into a pile of false verdicts. We copy
#     the files aside and copy them back, so a dirty tree survives intact.
#   * A NO-OP MUTATION FAILS LOUD. If the edit did not change the file, the gate
#     staying green means nothing — that is a harness failure, not a pass.
#   * MUTATIONS STAY VALID PHP. `php -l` must pass, or we are testing the parser
#     instead of the assertion.
#
# Exit: 0 = every assertion reddened, 1 = at least one stayed green (the finding),
#       2 = the harness could not run.

set -uo pipefail
cd "$(dirname "$0")/../.." || exit 2

GATE=tools/gates/mirror-reconcile-poison-gate.php
MAT=bb-mirror/lib/materializers.php
REC=bb-mirror/bin/reconcile.php

for f in "$GATE" "$MAT" "$REC"; do
    [ -r "$f" ] || { echo "CANNOT RUN: unreadable $f"; exit 2; }
done

SNAP=$(mktemp -d /tmp/mirror-redfirst.XXXXXX) || exit 2
trap 'cp -p "$SNAP/mat" "$MAT" 2>/dev/null; cp -p "$SNAP/rec" "$REC" 2>/dev/null; rm -rf "$SNAP"' EXIT
cp -p "$MAT" "$SNAP/mat"
cp -p "$REC" "$SNAP/rec"

fails=0
restore() { cp -p "$SNAP/mat" "$MAT"; cp -p "$SNAP/rec" "$REC"; }

# The replacer takes its strings through argv, never through the shell's parser —
# PHP sigils and braces in a mutation must not need a second layer of escaping.
replace_py() {
    python3 -c '
import sys
path, old, new = sys.argv[1], sys.argv[2], sys.argv[3]
s = open(path).read()
if old not in s:
    sys.stderr.write("PATTERN NOT FOUND\n")
    sys.exit(3)
open(path, "w").write(s.replace(old, new, 1))
' "$@"
}

# mutate <label> <file> <from> <to>
mutate() {
    local label="$1" file="$2" from="$3" to="$4"
    printf '\n--- %s\n' "$label"

    local before after
    before=$(md5sum "$file" | cut -d' ' -f1)
    if ! replace_py "$file" "$from" "$to"; then
        echo "    HARNESS FAILURE: mutation pattern no longer matches the source — this assertion was NOT tested"
        fails=$((fails + 1)); restore; return
    fi
    after=$(md5sum "$file" | cut -d' ' -f1)

    if [ "$before" = "$after" ]; then
        echo "    HARNESS FAILURE: mutation changed nothing — the assertion was never tested"
        fails=$((fails + 1)); restore; return
    fi
    if ! php -l "$file" >/dev/null 2>&1; then
        echo "    HARNESS FAILURE: mutation produced invalid PHP — testing the parser, not the gate"
        fails=$((fails + 1)); restore; return
    fi

    php "$GATE" >/dev/null 2>&1
    local rc=$?
    if [ "$rc" -eq 1 ]; then
        echo "    RED as required (exit 1)"
    else
        echo "    STAYED GREEN (exit $rc) — THIS ASSERTION IS DECORATION"
        fails=$((fails + 1))
    fi
    restore
}

echo "=== red-first: mirror-reconcile-poison-gate ==="

# 1. The guard stops RECORDING skips: a swallowed row reads as a clean run.
mutate "walk swallows the bad row instead of recording it" "$MAT" \
'            $skipped[$id] = $e->getMessage();' \
'            unset($e);'

# 2. The guard stops GUARDING: back to the shape that wedged live on 2026-07-29.
mutate "walk re-throws the poisoned row (the 2026-07-29 shape)" "$MAT" \
'        } catch (Throwable $e) {' \
'        } catch (Throwable $e) { throw $e; } if (false) {'

# 3. A bare foreach around a materializer is reintroduced in reconcile.php.
mutate "a bare foreach around bb_mirror_upsert_reply reappears" "$REC" \
'// ---------- ghost sweep (the reverse pass) --------------------------------' \
'foreach ($rows as $id) { bb_mirror_upsert_reply((int)$id, $db); }
// ---------- ghost sweep (the reverse pass) --------------------------------'

# 4-6. A tail step escapes its try. These mutations ADD an unguarded call rather
#      than unwrapping the guarded one: converting `try {` to `if (true) {` strands
#      the `} catch` and yields invalid PHP, which tests the parser instead of the
#      assertion (the harness caught exactly that and refused to score it). An extra
#      unguarded occurrence is both valid PHP and precisely the regression — a call
#      that can throw where a throw skips the bookmark.
mutate "ghost sweep also called outside any try" "$REC" \
'// ---------- reply_count rollup --------------------------------------------' \
'bb_mirror_sweep_ghosts($db, fn(string $k) => [], false);
// ---------- reply_count rollup --------------------------------------------'

mutate "reply_count rollup also called outside any try" "$REC" \
'// ---------- rollup refresh ------------------------------------------------' \
'bb_mirror_refresh_all_reply_counts($db);
// ---------- rollup refresh ------------------------------------------------'

mutate "a raw ->exec() runs outside any try" "$REC" \
'// ---------- bookmark update -----------------------------------------------' \
'$db->exec("SELECT 1");
// ---------- bookmark update -----------------------------------------------'

# 7. The bookmark write is neutered — the window can never advance again.
#    NOTE this is the mutation that exposed the original str_contains() assertion:
#    it passed on 'last_reconcile_at_DISABLED' because the old name is a substring
#    of the new one. The assertion is now an exact-literal lex, so it fires.
mutate "bookmark key renamed (the window freezes)" "$REC" \
"\$upsert->execute(['last_reconcile_at', (string)\$now, bb_mirror_ts(\$now)]);" \
"\$upsert->execute(['last_reconcile_at_DISABLED', (string)\$now, bb_mirror_ts(\$now)]);"

# 8-11. Section 4: an unreadable post must be LOUD. Each mutation restores one
#       piece of the silent-drop shape that cost 11 replies their visibility.
mutate "the typed exception disappears" "$MAT" \
'if (!class_exists('"'"'BbMirrorUnreadable'"'"')) {
    class BbMirrorUnreadable extends RuntimeException {}
}' \
'if (!class_exists('"'"'BbMirrorUnreadableRenamed'"'"')) {
    class BbMirrorUnreadableRenamed extends RuntimeException {}
}
class_alias('"'"'RuntimeException'"'"', '"'"'BbMirrorUnreadableGone'"'"');'

mutate "upsert_reply returns quietly again (the ~16% drop, restored)" "$MAT" \
'            throw new BbMirrorUnreadable(
                "reply $id has no _bbp_topic_id/_bbp_forum_id even after a cache-bypassing re-read"
            );' \
'            return;'

mutate "upsert_topic returns quietly again" "$MAT" \
'            throw new BbMirrorUnreadable(
                "topic $id has no _bbp_forum_id even after a cache-bypassing re-read"
            );' \
'            return;'

mutate "upsert_reply stops re-reading past the caches" "$MAT" \
'        [, $m] = bb_mirror_reread_uncached($id);
        $topic_id = (int)($m['"'"'_bbp_topic_id'"'"'] ?? 0);
        $forum_id = (int)($m['"'"'_bbp_forum_id'"'"'] ?? 0);' \
'        $topic_id = (int)($m['"'"'_bbp_topic_id'"'"'] ?? 0);
        $forum_id = (int)($m['"'"'_bbp_forum_id'"'"'] ?? 0);'

printf '\n=== summary ===\n'
if [ "$fails" -ne 0 ]; then
    echo "$fails assertion(s) did not redden — the gate is not trustworthy yet"
    exit 1
fi
echo "every assertion reddened under mutation; the gate is real"
exit 0
