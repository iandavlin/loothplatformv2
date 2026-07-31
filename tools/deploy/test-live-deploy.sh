#!/usr/bin/env bash
# TEST HARNESS for tools/deploy/live-deploy.sh.
#
#   bash tools/deploy/test-live-deploy.sh
#
# Builds a throwaway git remote + serving checkout in /tmp that REPRODUCES the
# 2026-07-31 deploy window — a new nginx snippet, four new mu-plugins, a new webroot
# file, a new migration script, a REMOVED mu-plugin — and asserts the script sees
# each one and refuses in the right places.
#
# ─── WHY THIS EXISTS ────────────────────────────────────────────────────────────
# "A deploy script that has never seen a deploy fail is not tested." The real box
# cannot be used for this: dev2 currently carries undeclared divergence (by design,
# until the §1.1/§1.4 restores run), so the real drift gate correctly refuses before
# the interesting code is ever reached. A scratch tree is the only way to exercise
# the diff-driven half.
#
# Touches NOTHING outside its own mktemp dir. Never runs --apply against a real box.
set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
SCRIPT="$HERE/live-deploy.sh"
T="$(mktemp -d /tmp/lgd-test.XXXXXX)"
trap 'rm -rf "$T"' EXIT

pass=0; fail=0
check() { # check <description> <haystack-file> <needle>
  if grep -qF -- "$3" "$2"; then echo "  ✔ $1"; pass=$((pass+1));
  else echo "  ❌ $1"; echo "      expected to find: $3"; fail=$((fail+1)); fi
}
check_not() {
  if grep -qF -- "$3" "$2"; then echo "  ❌ $1"; echo "      should NOT contain: $3"; fail=$((fail+1));
  else echo "  ✔ $1"; pass=$((pass+1)); fi
}

echo "═══ building a scratch deploy window in $T ═══"
export GIT_AUTHOR_NAME=test GIT_AUTHOR_EMAIL=t@t GIT_COMMITTER_NAME=test GIT_COMMITTER_EMAIL=t@t

# --- the "origin" ---
git init -q --bare "$T/origin.git"

# --- a working tree to build history in ---
git init -q -b main "$T/build"
cd "$T/build"
mkdir -p platform/nginx platform/mu-plugins platform/fpm/dev2 webroot cutover
echo "# existing" > platform/nginx/strangler-membership.conf
echo "<?php // existing" > platform/mu-plugins/lg-existing.php
echo "<?php // to be removed" > platform/mu-plugins/lg-doomed.php
echo "[dev2]" > platform/fpm/dev2/membership.conf
# A real serving checkout always carries this; the wrapper (correctly) treats its
# absence as a failure, so the fixture must have it or the test is unfaithful.
printf '#!/usr/bin/env bash\necho "install-symlinks stub: $*"\n' > webroot/install-symlinks.sh
chmod +x webroot/install-symlinks.sh
git add -A && git commit -qm "base"
git remote add origin "$T/origin.git" && git push -q origin main

OLD_SHA="$(git rev-parse HEAD)"

# --- the deploy window: exactly the shape of 2026-07-31 ---
cat > platform/nginx/strangler-shop-planner.conf <<'X'
location ^~ /shop-layout-planner/ { fastcgi_pass unix:/run/php/fake.sock; }
X
echo "<?php // new" > platform/mu-plugins/lg-discussion-unsub.php
echo "<?php // new" > platform/mu-plugins/lg-discussion-group-gate.php
echo "<?php // new" > platform/mu-plugins/lg-follow-digest.php
echo "<?php // new" > platform/mu-plugins/lg-author-socials.php
git rm -q platform/mu-plugins/lg-doomed.php
echo "<?php // new page" > webroot/shop-layout-planner.php
cat > cutover/topic-follow-migrate.sh <<'X'
#!/usr/bin/env bash
echo "MIGRATION RAN — if you see this in the test output, the wrapper executed it"
X
chmod +x cutover/topic-follow-migrate.sh
echo "CREATE TABLE t();" > cutover/2026-07-31-follow.sql
git add -A && git commit -qm "the deploy window"
git push -q origin main
NEW_SHA="$(git rev-parse HEAD)"

# --- the "serving checkout": at the OLD sha, clean, with origin set ---
git clone -q "$T/origin.git" "$T/serve"
git -C "$T/serve" checkout -q "$OLD_SHA"
git -C "$T/serve" branch -q -f main "$OLD_SHA" 2>/dev/null
git -C "$T/serve" checkout -q main
mkdir -p "$T/wp/wp-content/mu-plugins"

echo
echo "═══ TEST 1 — dry run must SEE the whole window and change nothing ═══"
SERVE="$T/serve" WP="$T/wp" bash "$SCRIPT" --skip-gates > "$T/out1" 2>&1
sed 's/^/    /' "$T/out1" | grep -E "ROLLBACK|new nginx|new mu|REMOVED|new webroot|new .sql|new migration|\+ |- |RESULT|dry-run.*ln" | head -25
echo
check "records the rollback SHA first"            "$T/out1" "$OLD_SHA"
check "counts the new nginx snippet"              "$T/out1" "new nginx snippets           1"
check "counts the four new mu-plugins"            "$T/out1" "new mu-plugins               4"
check "notices the REMOVED mu-plugin"             "$T/out1" "REMOVED mu-plugins           1"
check "counts the new webroot file"               "$T/out1" "new webroot files            1"
check "counts the new .sql"                       "$T/out1" "new .sql                     1"
check "counts the new migration script"           "$T/out1" "new migration scripts        1"
check "names the new snippet"                     "$T/out1" "platform/nginx/strangler-shop-planner.conf"
check "would link the snippet into /etc/nginx"    "$T/out1" "/etc/nginx/snippets/strangler-shop-planner.conf"
check "dry run says it changed nothing"           "$T/out1" "DRY RUN complete — nothing was changed"
check "reports what it did NOT do"                "$T/out1" "WHAT THIS DID NOT DO"
check "warns the feature is not tested"           "$T/out1" "THE FEATURE IS NOT TESTED"
# THE CRITICAL ONE: a deploy wrapper must never run a migration unattended.
check_not "did NOT execute the migration"         "$T/out1" "MIGRATION RAN"
check "extracted + listed the migration instead"  "$T/out1" "NOT executed (by design)"
# The serving checkout must not have moved during a dry run.
[ "$(git -C "$T/serve" rev-parse HEAD)" = "$OLD_SHA" ] \
  && { echo "  ✔ serving checkout did not move"; pass=$((pass+1)); } \
  || { echo "  ❌ serving checkout MOVED during a dry run"; fail=$((fail+1)); }

echo
echo "═══ TEST 2 — a DIRTY serving checkout must stop the deploy ═══"
echo "hand-edited" >> "$T/serve/platform/nginx/strangler-membership.conf"
SERVE="$T/serve" WP="$T/wp" bash "$SCRIPT" --skip-gates > "$T/out2" 2>&1
check "refuses on a dirty serve"                  "$T/out2" "the serving checkout is DIRTY"
check "explains why that matters"                 "$T/out2" "It only ever pulls"
check "still prints the rollback line"            "$T/out2" "$OLD_SHA"
git -C "$T/serve" checkout -q -- . 2>/dev/null

echo
echo "═══ TEST 3 — nothing to deploy is a clean exit, not a no-op deploy ═══"
git -C "$T/serve" merge -q --ff-only origin/main 2>/dev/null
SERVE="$T/serve" WP="$T/wp" bash "$SCRIPT" --skip-gates > "$T/out3" 2>&1
check "detects it is already up to date"          "$T/out3" "nothing to deploy"
check_not "does not print a deploy plan"          "$T/out3" "DIFF-DRIVEN PLAN"

echo
echo "═══ TEST 4 — a non-fast-forward origin must be refused ═══"
git -C "$T/build" reset -q --hard "$OLD_SHA"
echo "divergent" > "$T/build/divergent.txt"
git -C "$T/build" add -A && git -C "$T/build" commit -qm "rewritten history"
git -C "$T/build" push -q -f origin main
SERVE="$T/serve" WP="$T/wp" bash "$SCRIPT" --skip-gates > "$T/out4" 2>&1
check "refuses a non-fast-forward"                "$T/out4" "NOT a fast-forward"

echo
echo "═══════════════════════════════════════════════════════════"
echo " $pass passed, $fail failed"
[ $fail -eq 0 ] || exit 1
