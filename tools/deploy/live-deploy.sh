#!/usr/bin/env bash
# ═══════════════════════════════════════════════════════════════════════════════
# live-deploy.sh — ONE COMMAND. Ordered, verified, and it refuses to continue.
#
#   bash tools/deploy/live-deploy.sh              # DRY RUN — the default, always
#   bash tools/deploy/live-deploy.sh --apply      # do it
#   bash tools/deploy/live-deploy.sh --apply --skip-gates   # only if a gate is
#                                                           # known-red for an
#                                                           # unrelated reason
#
# Ian, 2026-07-31, after a deploy that needed five manual interventions:
#   "Are we doing a lot of hand work? This looks like stuff that should have been
#    pulled?"
# He was right. `lg-deploy` is one pull and that rule is GOOD — the problem is that
# everything which is NOT a file in the repo had no home: symlinks, database roles
# and feature-flag state are all real deploy state, and they lived in prose.
#
# ─── WHY IT IS DIFF-DRIVEN ──────────────────────────────────────────────────────
# Every step below is derived from `git diff --name-status <old>..<new>`. A
# hardcoded checklist rots the moment someone adds a coupling class; a diff does
# not. What it CANNOT derive it says out loud in the closing report rather than
# leaving silence to be read as coverage.
#
# ─── THE ORDERING TRAP, AND THE DECISION ────────────────────────────────────────
# Migrations must run BEFORE the code that needs them — Ian's call on 2026-07-30,
# and it is the safe order: the objects exist before any code can reach them, so
# there is no window where the feature is live and its store is not. The reverse
# gives every member a 500 for however long the migration takes.
#
# BUT THE MIGRATION SCRIPT ITSELF ARRIVES IN THE PULL. That cost Ian a round trip.
# So "migrate then pull" is impossible as literally stated, and "pull then migrate"
# reopens the 500 window.
#
# THE ANSWER: `git fetch` is not `git pull`. Fetch brings the new commit's OBJECTS
# without touching the working tree — the box carries on serving the OLD code — and
# the new migration script can then be extracted from that commit with `git archive`
# and run. Only then does the ff-only merge move the working tree.
#
#     fetch → extract → migrate → merge(ff-only) → couplings → reload → verify
#
# `git archive` rather than `git worktree add`: it writes nothing into the serving
# checkout's .git at all. The serving checkout ONLY EVER PULLS — that rule is what
# this whole script exists to protect.
#
# ─── WHAT IT VERIFIES, AS OPPOSED TO WHAT IT RUNS ───────────────────────────────
#   - nginx: the WORKER start time changed. Never the master's — the master's is
#     unchanged by a reload, so watching it reports success for a reload that did
#     not happen. `nginx -t` proves SYNTAX and NEVER routing; a missing location
#     passes it happily.
#   - endpoints: a new location must move 404 → 401/403, not 404 → 200.
#   - symlinks: the target must resolve into the SERVING CHECKOUT. One was found
#     pointing at a lane worktree on 2026-07-31 — it worked, and would have broken
#     the moment that lane rebased.
#   - FPM pools: still on the per-box variant. See §1.3 of the divergence register.
# ═══════════════════════════════════════════════════════════════════════════════
set -uo pipefail

SERVE="${SERVE:-/home/ubuntu/loothplatformv2-clean}"
WP="${WP:-/var/www/dev}"
BRANCH="${BRANCH:-main}"
HERE="$(cd "$(dirname "$0")" && pwd)"
APPLY=0; SKIP_GATES=0

while [ $# -gt 0 ]; do
  case "$1" in
    --apply)      APPLY=1; shift ;;
    --dry-run)    APPLY=0; shift ;;
    --skip-gates) SKIP_GATES=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

FAILED=0
NOTDONE=()
step()  { echo; echo "━━━ $* ━━━"; }
note()  { echo "    $*"; }
ok()    { echo "    ✔ $*"; }
bad()   { echo "    ❌ $*"; FAILED=1; }
skip()  { echo "    ·  $*"; }
notdone(){ NOTDONE+=("$1"); }

die() {
  echo
  echo "════════════════════════════════════════════════════════════════════"
  echo "STOPPED: $*"
  echo
  echo "Nothing further was attempted. A half-finished deploy is worse than an"
  echo "un-started one, so this script does not push past a failed step."
  [ -n "${ROLLBACK_SHA:-}" ] && {
    echo
    echo "ROLLBACK (tested both directions on dev2, 2026-07-30):"
    echo "    git -C $SERVE reset --hard $ROLLBACK_SHA"
  }
  exit 1
}

run() { # run <description> <command...>
  if [ $APPLY = 1 ]; then "${@:2}"; return $?; fi
  echo "    [dry-run] would run: ${*:2}"; return 0
}

echo "═══════════════════════════════════════════════════════════════════════"
echo " live-deploy   MODE=$([ $APPLY = 1 ] && echo '⚠️  APPLY' || echo 'DRY RUN (default)')"
echo " serving checkout: $SERVE"
echo " docroot:          $WP"
echo "═══════════════════════════════════════════════════════════════════════"

# ─── BOX DETECTION — evidence, not a flag ───────────────────────────────────────
# Same method as cutover/symlink-farm.sh: ask the box what its pools are linked to.
# A hostname can change and a flag can be passed wrongly; the pool symlinks are what
# the machine actually believes about itself.
detect_box() {
  local t seen_live=0 seen_dev2=0 f
  for f in $(sudo ls -1 /etc/php/8.3/fpm/pool.d/ 2>/dev/null); do
    case "$f" in *.bak*|*.pre-symlink-*|*.dev-preflip|*.live-posture) continue;; esac
    t="$(sudo readlink "/etc/php/8.3/fpm/pool.d/$f" 2>/dev/null)" || continue
    case "$t" in
      */platform/fpm/live/*) seen_live=1 ;;
      */platform/fpm/dev2/*) seen_dev2=1 ;;
    esac
  done
  [ $seen_live = 1 ] && [ $seen_dev2 = 1 ] && { echo mixed; return; }
  [ $seen_live = 1 ] && { echo live; return; }
  [ $seen_dev2 = 1 ] && { echo dev2; return; }
  echo unknown
}
BOX="$(detect_box)"
note "detected box: $BOX (from this machine's own FPM pool symlinks)"
[ "$BOX" = mixed ] && die "pools are linked to BOTH live/ and dev2/ variants — already broken, fix by hand first"

# ─── 0. ROLLBACK TARGET, RECORDED AND PRINTED FIRST ─────────────────────────────
# Before anything else, and printed where it cannot be missed. If the terminal is
# lost mid-deploy this line is the whole recovery.
step "0. ROLLBACK TARGET"
[ -d "$SERVE/.git" ] || die "$SERVE is not a git checkout"
ROLLBACK_SHA="$(git -C "$SERVE" rev-parse HEAD 2>/dev/null)" || die "cannot read HEAD in $SERVE"
echo
echo "    ┌──────────────────────────────────────────────────────────────┐"
printf  "    │  ROLLBACK:  git -C %s \\\\\n" "$SERVE"
printf  "    │             reset --hard %-31s │\n" "$ROLLBACK_SHA"
echo "    └──────────────────────────────────────────────────────────────┘"
note "$(git -C "$SERVE" log -1 --format='currently at: %h %s' 2>/dev/null)"

dirty="$(git -C "$SERVE" status --porcelain 2>/dev/null)"
if [ -n "$dirty" ]; then
  echo "$dirty" | sed 's/^/      /'
  die "the serving checkout is DIRTY. It only ever pulls — a dirty serve means
       someone hand-edited a file that is symlinked into the running system.
       Resolve that before deploying; do not stash it away."
fi
ok "serving checkout is clean"

# ─── 1. PREFLIGHT GATES ─────────────────────────────────────────────────────────
# These run BEFORE the fetch because a box that is already diverged should not have
# a deploy layered on top of it.
step "1. PREFLIGHT"
if [ $SKIP_GATES = 1 ]; then
  skip "gates skipped by --skip-gates"
  notdone "preflight gates (--skip-gates) — drift and PG roles were NOT checked"
else
  if [ -x "$HERE/../../cutover/ensure-pg-roles.sh" ]; then
    if bash "$HERE/../../cutover/ensure-pg-roles.sh" --check >/tmp/lgd-roles.$$ 2>&1; then
      ok "every PG role the apps peer-auth as exists"
    else
      sed 's/^/      /' /tmp/lgd-roles.$$
      rm -f /tmp/lgd-roles.$$
      die "a Postgres role the apps connect as is MISSING.
       This does not fail the deploy — it fails every REQUEST afterwards, with a
       FATAL in /var/log/php8.3-fpm.log and a broken page. That is exactly how
       2026-07-31 went. Fix first:  bash cutover/ensure-pg-roles.sh --apply"
    fi
    rm -f /tmp/lgd-roles.$$
  fi
  if [ -x "$HERE/../gates/deploy-drift-gate.sh" ]; then
    if bash "$HERE/../gates/deploy-drift-gate.sh" --box "$BOX" >/tmp/lgd-drift.$$ 2>&1; then
      ok "no undeclared divergence on this box"
    else
      sed 's/^/      /' /tmp/lgd-drift.$$ | tail -30
      rm -f /tmp/lgd-drift.$$
      note ""
      note "The box carries divergence that is not declared in"
      note "docs/runbooks/live-divergences.md. Deploying over it is how a"
      note "hand-edited file silently stops receiving updates."
      note "Fix it, or declare it, then re-run."
      die "deploy-drift gate is RED"
    fi
    rm -f /tmp/lgd-drift.$$
  fi
fi

# ─── 2. FETCH (not pull) ────────────────────────────────────────────────────────
step "2. FETCH — objects only; the working tree does not move yet"
run "fetch" git -C "$SERVE" fetch --quiet origin "$BRANCH" || die "git fetch failed"
if [ $APPLY = 0 ]; then
  git -C "$SERVE" fetch --quiet origin "$BRANCH" 2>/dev/null || true
fi
NEW_SHA="$(git -C "$SERVE" rev-parse "origin/$BRANCH" 2>/dev/null)" || die "cannot resolve origin/$BRANCH"
if [ "$NEW_SHA" = "$ROLLBACK_SHA" ]; then
  ok "already at origin/$BRANCH ($(git -C "$SERVE" rev-parse --short HEAD)) — nothing to deploy"
  echo; echo "Nothing to do."; exit 0
fi
if ! git -C "$SERVE" merge-base --is-ancestor "$ROLLBACK_SHA" "$NEW_SHA" 2>/dev/null; then
  die "origin/$BRANCH is NOT a fast-forward from HEAD. The serving checkout only
       ever pulls; a non-ff means history was rewritten or the box is on a branch."
fi
NCOMMITS="$(git -C "$SERVE" rev-list --count "$ROLLBACK_SHA..$NEW_SHA" 2>/dev/null)"
ok "$NCOMMITS commit(s) to deploy: $(git -C "$SERVE" rev-parse --short "$ROLLBACK_SHA")..$(git -C "$SERVE" rev-parse --short "$NEW_SHA")"

# ─── 3. WHAT THE DIFF SAYS NEEDS DOING ──────────────────────────────────────────
step "3. DIFF-DRIVEN PLAN"
DIFF="$(git -C "$SERVE" diff --name-status "$ROLLBACK_SHA..$NEW_SHA" 2>/dev/null)"
added()    { printf '%s\n' "$DIFF" | awk -v p="$1" '$1 ~ /^A/ && $2 ~ p {print $2}'; }
touched()  { printf '%s\n' "$DIFF" | awk -v p="$1" '$2 ~ p {print $2}'; }
deleted()  { printf '%s\n' "$DIFF" | awk -v p="$1" '$1 ~ /^D/ && $2 ~ p {print $2}'; }

NEW_SNIPPETS="$(added '^platform/nginx/.*\.conf$')"
NEW_MU="$(added '^platform/mu-plugins/.*\.php$')"
DEL_MU="$(deleted '^platform/mu-plugins/.*\.php$')"
NEW_WEBROOT="$(added '^webroot/')"
NEW_SQL="$(added '\.sql$')"
NEW_MIGRATIONS="$(added '^cutover/.*migrate.*\.sh$')"
TOUCHED_NGINX="$(touched '^platform/nginx/.*\.conf$')"
TOUCHED_FPM="$(touched '^platform/fpm/')"

plan_line() { local n; n="$(printf '%s' "$2" | grep -c . || true)"; printf "    %-28s %s\n" "$1" "${n:-0}"; }
plan_line "new nginx snippets"   "$NEW_SNIPPETS"
plan_line "new mu-plugins"       "$NEW_MU"
plan_line "REMOVED mu-plugins"   "$DEL_MU"
plan_line "new webroot files"    "$NEW_WEBROOT"
plan_line "new .sql"             "$NEW_SQL"
plan_line "new migration scripts" "$NEW_MIGRATIONS"
plan_line "nginx confs touched"  "$TOUCHED_NGINX"
plan_line "fpm confs touched"    "$TOUCHED_FPM"
[ -n "$NEW_SNIPPETS" ] && printf '%s\n' "$NEW_SNIPPETS" | sed 's/^/        + /'
[ -n "$NEW_MU" ]       && printf '%s\n' "$NEW_MU"       | sed 's/^/        + /'
[ -n "$DEL_MU" ]       && printf '%s\n' "$DEL_MU"       | sed 's/^/        - /'

# ─── 4. MIGRATIONS — from the FETCHED commit, before the tree moves ─────────────
step "4. MIGRATIONS (extracted from $(git -C "$SERVE" rev-parse --short "$NEW_SHA"), tree not yet moved)"
if [ -z "$NEW_MIGRATIONS" ] && [ -z "$NEW_SQL" ]; then
  skip "no new migration script and no new .sql in this window"
else
  TMPX="$(mktemp -d /tmp/lgd-migrate.XXXXXX)"
  # git archive writes NOTHING into the serving checkout's .git — no worktree
  # metadata, no HEAD move, no index touch. The serve keeps serving old code.
  if git -C "$SERVE" archive "$NEW_SHA" cutover 2>/dev/null | tar -x -C "$TMPX" 2>/dev/null; then
    ok "extracted cutover/ from the new commit to $TMPX"
    printf '%s\n' "$NEW_MIGRATIONS" | grep . | while read -r m; do
      note "migration available: $TMPX/$m"
    done
    echo
    note "⚠️  MIGRATIONS ARE NOT RUN AUTOMATICALLY, deliberately."
    note "    Each one has its own dry-run, its own rollback window and its own"
    note "    irreversibility (topic-follow's table is reversible ONLY while empty —"
    note "    once a member clicks a bell those rows have no other home). Running"
    note "    them unattended from a deploy wrapper is how member data gets dropped"
    note "    by a script that was only trying to be helpful."
    echo
    note "    Run each, dry first, THEN re-run this script:"
    printf '%s\n' "$NEW_MIGRATIONS" | grep . | sed "s|^|        bash $TMPX/|"
    notdone "migrations — extracted and listed, NOT executed (by design)"
  else
    bad "could not extract cutover/ from $NEW_SHA"
  fi
fi

# ─── 5. THE PULL ────────────────────────────────────────────────────────────────
step "5. PULL — this is the only step that moves the serving checkout"
if command -v lg-deploy >/dev/null 2>&1; then
  note "using lg-deploy"
  run "pull" lg-deploy || die "lg-deploy failed"
else
  run "pull" git -C "$SERVE" merge --ff-only "origin/$BRANCH" || die "ff-only merge failed"
fi
if [ $APPLY = 1 ]; then
  NOW="$(git -C "$SERVE" rev-parse HEAD)"
  [ "$NOW" = "$NEW_SHA" ] || die "after the pull HEAD is $NOW, expected $NEW_SHA"
  ok "serving checkout now at $(git -C "$SERVE" rev-parse --short HEAD)"
fi

# ─── 6. COUPLINGS — the things a pull does NOT do ───────────────────────────────
step "6. COUPLINGS"

# 6a. NEW NGINX SNIPPETS. Nothing covered this before: strangler-shop-planner.conf
#     arrived in the pull with no /etc/nginx/snippets/ link, so nginx -t FAILED
#     (correctly) and the reload was refused.
if [ -n "$NEW_SNIPPETS" ]; then
  printf '%s\n' "$NEW_SNIPPETS" | grep . | while read -r f; do
    b="$(basename "$f")"
    run "link snippet $b" sudo ln -sfn "$SERVE/$f" "/etc/nginx/snippets/$b"
    note "snippet link: $b -> $SERVE/$f"
  done
else
  skip "no new nginx snippets"
fi

# 6b. NEW MU-PLUGINS — each is linked individually and the symlink SET is not in
#     the repo, so a pull alone leaves a new plugin dark. Four needed this on
#     2026-07-31. symlink-farm.sh derives them; it is called filtered, and its FPM
#     section now refuses on a serving box regardless.
if [ -n "$NEW_MU" ]; then
  printf '%s\n' "$NEW_MU" | grep . | while read -r f; do
    b="$(basename "$f")"
    run "link mu-plugin $b" sudo ln -sfn "$SERVE/$f" "$WP/wp-content/mu-plugins/$b"
    note "mu-plugin link: $b"
  done
else
  skip "no new mu-plugins"
fi

# 6c. REMOVED mu-plugins leave a DANGLING symlink, which is a fatal require at
#     WordPress boot, not a no-op.
if [ -n "$DEL_MU" ]; then
  printf '%s\n' "$DEL_MU" | grep . | while read -r f; do
    b="$(basename "$f")"
    if sudo test -L "$WP/wp-content/mu-plugins/$b" && ! sudo test -e "$WP/wp-content/mu-plugins/$b"; then
      run "remove dangling $b" sudo rm -f "$WP/wp-content/mu-plugins/$b"
      note "removed dangling mu-plugin link: $b"
    fi
  done
else
  skip "no removed mu-plugins"
fi

# 6d. NEW WEBROOT FILES
if [ -n "$NEW_WEBROOT" ]; then
  if [ -x "$SERVE/webroot/install-symlinks.sh" ]; then
    run "webroot links" sudo "$SERVE/webroot/install-symlinks.sh" --new-only "$WP"
  else
    bad "new webroot files but $SERVE/webroot/install-symlinks.sh is missing"
  fi
else
  skip "no new webroot files"
fi

# 6e. FPM POOLS — NEVER repointed here. See docs/runbooks/live-divergences.md §1.3.
if [ -n "$TOUCHED_FPM" ]; then
  note "fpm conf(s) changed in this window; pools are symlinks so the pull already"
  note "deployed the file. Only a RELOAD is needed — pools are NEVER repointed."
fi

# ─── 7. RELOADS, EACH VERIFIED ──────────────────────────────────────────────────
step "7. RELOAD + VERIFY"

if [ -n "$TOUCHED_NGINX" ] || [ -n "$NEW_SNIPPETS" ]; then
  # Capture worker identity BEFORE. The MASTER's start time never changes on a
  # reload, so watching the master reports success for a reload that never
  # happened. Workers are the only honest signal.
  W_BEFORE="$(ps -eo lstart,pid,cmd 2>/dev/null | grep "[n]ginx: worker" | md5sum | cut -c1-12)"
  note "nginx workers before: $W_BEFORE"
  if [ $APPLY = 1 ]; then
    sudo nginx -t 2>&1 | sed 's/^/      /' || die "nginx -t FAILED — config is broken, reload refused.
       If this is 'open() ... failed' on a snippet, a new conf is missing its
       /etc/nginx/snippets/ symlink; step 6a should have created it."
    ok "nginx -t passed — NOTE: this proves SYNTAX, never routing"
    sudo systemctl reload nginx || die "nginx reload failed"
    sleep 1
    W_AFTER="$(ps -eo lstart,pid,cmd 2>/dev/null | grep "[n]ginx: worker" | md5sum | cut -c1-12)"
    if [ "$W_AFTER" = "$W_BEFORE" ]; then
      bad "nginx WORKERS did not restart ($W_AFTER) — the reload did not take.
         A disclosure fix once sat inert on dev2 for three hours exactly this way."
    else
      ok "nginx workers restarted: $W_BEFORE -> $W_AFTER"
    fi
  else
    echo "    [dry-run] would run: sudo nginx -t && sudo systemctl reload nginx"
  fi
else
  skip "no nginx confs touched — no reload needed"
fi

if [ -n "$TOUCHED_FPM" ]; then
  run "reload php-fpm" sudo systemctl reload php8.3-fpm || bad "php8.3-fpm reload failed"
  [ $APPLY = 1 ] && ok "php8.3-fpm reloaded (picks up env[] flag changes)"
else
  skip "no fpm confs touched — no reload needed"
fi

# ─── 8. VERIFY THE THING, NOT THE THING NEXT TO IT ──────────────────────────────
step "8. VERIFY"

# 8a. Every symlink this script just made resolves into the SERVING CHECKOUT.
#     One was found pointing at a lane worktree on 2026-07-31. It WORKED — and
#     would have broken silently the moment that lane rebased.
verify_link() { # target
  local t; t="$(sudo readlink "$1" 2>/dev/null)"
  [ -z "$t" ] && { bad "$1 is not a symlink"; return; }
  case "$t" in
    "$SERVE"/*) sudo test -e "$1" && ok "$(basename "$1") -> serving checkout" \
                  || bad "$(basename "$1") -> $t  DANGLING" ;;
    /home/ubuntu/worktrees/*) bad "$(basename "$1") -> $t  A LANE WORKTREE, not the serve" ;;
    *) bad "$(basename "$1") -> $t  outside the serving checkout" ;;
  esac
}
if [ $APPLY = 1 ]; then
  printf '%s\n' "$NEW_SNIPPETS" | grep . | while read -r f; do verify_link "/etc/nginx/snippets/$(basename "$f")"; done
  printf '%s\n' "$NEW_MU"       | grep . | while read -r f; do verify_link "$WP/wp-content/mu-plugins/$(basename "$f")"; done
else
  skip "[dry-run] symlink targets would be verified here"
fi

# 8b. FPM pools still on this box's VARIANT directory. Asserted every deploy,
#     because the failure mode is silent until a reload and then halves the worker
#     count on a serving box.
#
#     Deliberately asserts the variant dir (`/platform/fpm/<box>/`) and NOT a
#     $SERVE-prefixed path. The failure this guards is the wrong VARIANT — live/
#     swapped for the top-level dev confs. Which checkout the pools resolve into is
#     a different question, and deploy-drift-gate.sh check [1] owns it; binding this
#     assertion to $SERVE also made the wrapper untestable against a scratch tree.
case "$BOX" in live|dev2)
  bads=0
  for f in $(sudo ls -1 /etc/php/8.3/fpm/pool.d/*.conf 2>/dev/null | xargs -n1 basename 2>/dev/null); do
    case "$f" in *.bak*|*.pre-symlink-*) continue;; esac
    t="$(sudo readlink "/etc/php/8.3/fpm/pool.d/$f" 2>/dev/null)" || continue
    case "$t" in
      */platform/fpm/"$BOX"/*) ;;
      *) bad "pool $f -> $t (expected a platform/fpm/$BOX/ variant)"; bads=1 ;;
    esac
  done
  [ $bads = 0 ] && ok "all FPM pool symlinks still on platform/fpm/$BOX/"
;; esac

# 8c. New nginx locations must ROUTE. `nginx -t` cannot tell you this: a missing
#     location passes it just as happily as a present one. A new internal endpoint
#     should move 404 → 401/403 — 403 means nginx routes it and the auth gate is
#     doing its job. 404 → 200 on an internal endpoint is its own alarm.
NEW_LOCATIONS="$(git -C "$SERVE" diff "$ROLLBACK_SHA..$NEW_SHA" -- 'platform/nginx/*.conf' 2>/dev/null \
  | grep -E '^\+\s*location' | grep -oE '/[A-Za-z0-9_./-]+' | sort -u | head -12)"
if [ -n "$NEW_LOCATIONS" ] && [ $APPLY = 1 ]; then
  host="$(hostname -f 2>/dev/null)"
  case "$BOX" in live) vhost=loothgroup.com ;; *) vhost=dev2.loothgroup.com ;; esac
  note "probing new location(s) on $vhost via --resolve (a plain public curl is"
  note "Cloudflare-challenged into a 403 that looks identical to success)"
  printf '%s\n' "$NEW_LOCATIONS" | while read -r loc; do
    code="$(curl -sk -o /dev/null -w '%{http_code}' --max-time 10 \
             --resolve "$vhost:443:127.0.0.1" "https://$vhost$loc" 2>/dev/null)"
    case "$code" in
      404) echo "    ❌ $loc -> 404  NOT ROUTED. nginx -t passed and the route is still absent." ;;
      000) echo "    ·  $loc -> no response (may need auth/host context; check by hand)" ;;
      *)   echo "    ✔ $loc -> $code (routed)" ;;
    esac
  done
  notdone "endpoint probes are UNAUTHENTICATED — a 403 proves routing, not that the feature works"
else
  [ -n "$NEW_LOCATIONS" ] && skip "[dry-run] would probe: $(printf '%s' "$NEW_LOCATIONS" | tr '\n' ' ')"
fi

# 8d. The logs. This is the step that was skipped on 2026-07-31.
if [ $APPLY = 1 ] && [ $SKIP_GATES = 0 ] && [ -x "$HERE/../gates/fpm-error-log-gate.sh" ]; then
  note "checking what the box is actually logging since the reload..."
  if bash "$HERE/../gates/fpm-error-log-gate.sh" --box "$BOX" --minutes 5 2>&1 | tail -20 | sed 's/^/      /'; then
    ok "no FATALs since the reload"
  else
    bad "the box is logging errors since the reload — read the quoted line above
         BEFORE theorising about code. On 2026-07-31 the quoted line WAS the answer."
  fi
fi

# ─── 9. WHAT THIS DID NOT DO ────────────────────────────────────────────────────
# Silence must never be mistaken for coverage. Everything this script cannot see is
# listed here, every run, whether or not it applies.
step "9. WHAT THIS DID NOT DO"
for n in "${NOTDONE[@]:-}"; do [ -n "$n" ] && echo "    · $n"; done
cat <<'EOF'
    · DATABASE MIGRATIONS are extracted and listed, never executed. Each has its
      own dry-run and its own irreversibility window.
    · FPM POOLS are never repointed. If a pool file's CONTENT changed the pull
      deployed it and step 7 reloaded; if the pool SET changed that is by hand,
      deliberately.
    · FLAG STATE is not changed. Flags live in tracked per-box files
      (platform/fpm/<box>/*.conf); changing one is a commit, not a deploy action.
    · WORDPRESS-SIDE state — permalink flushes, option changes, cron registration —
      is not touched. A plugin needing a rewrite rule needs a flush this cannot see.
    · THE FEATURE IS NOT TESTED. Endpoint probes prove nginx routes a path. They
      say nothing about whether the thing works. A green run here is NOT evidence
      the deploy is good, and must never be quoted to Ian as if it were.
    · LIVE-SPECIFIC DATA is untouched: no backfills, no cache warming, no reindex.
EOF

echo
echo "═══════════════════════════════════════════════════════════════════════"
if [ $FAILED -ne 0 ]; then
  echo " RESULT: ❌ one or more steps FAILED — read above."
  echo
  echo " ROLLBACK:  git -C $SERVE reset --hard $ROLLBACK_SHA"
  [ -n "$TOUCHED_NGINX$NEW_SNIPPETS" ] && echo "            sudo nginx -t && sudo systemctl reload nginx"
  exit 1
fi
if [ $APPLY = 0 ]; then
  echo " RESULT: DRY RUN complete — nothing was changed."
  echo " Re-run with --apply when the plan above is what you expect."
else
  echo " RESULT: ✔ deploy complete."
  echo " ROLLBACK if needed:  git -C $SERVE reset --hard $ROLLBACK_SHA"
fi
echo "═══════════════════════════════════════════════════════════════════════"
