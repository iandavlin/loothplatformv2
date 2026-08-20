#!/usr/bin/env bash
# projects-rescue-push — issue #132 / ledger 47.
#
# Pushes the local-only work in ~/projects (iandavlin/looth-platform) to its
# remote, then PROVES each push landed. Safe to re-run: every refspec is a
# fast-forward or a brand-new ref, and the script refuses to force anything.
#
# BLOCKED until deploy key `looth-platform-deploy-dev2` has WRITE on
# github.com/iandavlin/looth-platform. This script checks that first and exits
# 2 (CANNOT RUN, not a finding — the box convention) if the grant is missing.
#
# Why lane-profile-app goes to a NEW ref: local is 3 ahead / 22 BEHIND the
# remote branch of the same name. Pushing it to its namesake is rejected, and
# forcing it would destroy 22 commits this disk has never seen.
set -u

REPO=/home/ubuntu/projects
SSH_URL=github-looth:iandavlin/looth-platform.git
RESCUE_REF=dev2-rescue/lane-profile-app-20260820

# branch:remote-ref
PUSHES=(
  "bespoke-cutover:bespoke-cutover"
  "lane-wp-auth:lane-wp-auth"
  "lane-profile-app:${RESCUE_REF}"
)

[ -d "$REPO/.git" ] || { echo "CANNOT RUN: $REPO is not a git repo"; exit 2; }

echo "== write-access probe (read-only) =="
probe=$(ssh -o BatchMode=yes -o ConnectTimeout=10 github-looth \
          "git-receive-pack 'iandavlin/looth-platform.git'" 2>&1 | head -c 200)
case "$probe" in
  *denied*|*"Permission denied"*|*ERROR*)
    echo "CANNOT RUN: no write access yet."
    echo "  $probe"
    echo "  Fix: add ~/.ssh/looth_platform_deploy.pub as a deploy key WITH WRITE at"
    echo "  https://github.com/iandavlin/looth-platform/settings/keys/new"
    exit 2 ;;
esac
echo "  write access OK"

rc=0
echo
echo "== push =="
for p in "${PUSHES[@]}"; do
  br=${p%%:*}; dst=${p##*:}
  local_sha=$(git -C "$REPO" rev-parse "$br" 2>/dev/null) || {
    echo "FAIL $br: no such local branch"; rc=1; continue; }
  echo "-- $br ($local_sha) -> $dst"
  # no --force, ever: a rejection here is the guard working.
  if ! git -C "$REPO" push "$SSH_URL" "refs/heads/$br:refs/heads/$dst" 2>&1 | sed 's/^/   /'; then
    echo "FAIL $br: push rejected"; rc=1
  fi
done

echo
echo "== verify: remote sha must equal local sha =="
for p in "${PUSHES[@]}"; do
  br=${p%%:*}; dst=${p##*:}
  local_sha=$(git -C "$REPO" rev-parse "$br" 2>/dev/null)
  remote_sha=$(git -C "$REPO" ls-remote "$SSH_URL" "refs/heads/$dst" | awk '{print $1}')
  if [ -n "$remote_sha" ] && [ "$local_sha" = "$remote_sha" ]; then
    echo "  OK   $dst = ${local_sha:0:10}"
  else
    echo "  FAIL $dst: local=${local_sha:0:10} remote=${remote_sha:0:10}"; rc=1
  fi
done

echo
echo "== clone spot-check (round trip, bespoke-cutover) =="
tmp=$(mktemp -d /tmp/rescue-verify-XXXXXX)
if git clone -q --branch bespoke-cutover --single-branch "$SSH_URL" "$tmp/c" 2>/dev/null; then
  got=$(git -C "$tmp/c" rev-parse HEAD)
  want=$(git -C "$REPO" rev-parse bespoke-cutover)
  [ "$got" = "$want" ] && echo "  OK   clone HEAD = ${got:0:10}" \
                       || { echo "  FAIL clone=${got:0:10} want=${want:0:10}"; rc=1; }
else
  echo "  FAIL clone did not complete"; rc=1
fi
rm -rf "$tmp"

echo
[ $rc -eq 0 ] && echo "ALL GREEN — the at-risk work exists off this disk." \
              || echo "RED — see FAIL lines above."
exit $rc
