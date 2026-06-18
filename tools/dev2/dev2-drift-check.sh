#!/usr/bin/env bash
# dev2-drift-check.sh — READ-ONLY drift report for dev2.loothgroup.com (the prod-candidate box).
#
# Run ON dev2 as:   bash dev2-drift-check.sh      (NOT with `set -e`)
# It compares dev2's live state against the known-good expectations derived from dev1
# (claude.loothgroup.com, 50.19.198.38) and prints a per-category PASS / FAIL / DRIFT verdict
# plus an "X of Y aligned" summary and a biggest-gaps list.
#
# This script CHANGES NOTHING: no writes, no git mutations, only reads / SELECTs / curls / nginx -T.
# Baked-in expectations (from dev1, 2026-06-16):
#   origin/main tip ............ 9f990b4343767828254a394f8f43db71b7969901
#   canonical mu-plugins ....... 17 files (platform/mu-plugins/*.php)
#   poller onboard markers ..... grep -c 'patreon-password|wp_set_auth_cookie' >= 2  (dev1=3)
#
# It uses sudo for root-only reads (mu-plugins dir, secrets, pool confs, postgres, mysql).
# Run it where you have passwordless sudo (ubuntu on dev2).

# ---- DO NOT use set -e: a no-match grep would log you out of a pasted shell. ----

EXPECT_MAIN="9f990b4343767828254a394f8f43db71b7969901"
CLONE="/home/ubuntu/git/looth-platform"
WPROOT="/var/www/dev"
MU="$WPROOT/wp-content/mu-plugins"
POLLER="$WPROOT/wp-content/plugins/lg-patreon-stripe-poller"
HOSTHDR="dev2.loothgroup.com"
RESOLVE="--resolve dev2.loothgroup.com:443:127.0.0.1"

# Canonical 17 mu-plugins (from dev1 projects/platform/mu-plugins/*.php).
CANON_MU="archive-poc-sync.php bb-forum-author-delete.php bb-mirror-sync.php lg-admin-tools.php lg-article-materializer.php lg-comments-frame.php lg-error-pages.php lg-event-reminders.php lg-membership-chrome.php lg-viewer-tier.php lgms-admin-view-as-toggle.php lgpo-set-password.php looth-auth-issue.php loothdev-sheets-bridge.php profile-auth.php profile-sync.php profile-whoami-shim.php"

PASS=0; FAIL=0; DRIFT=0
declare -a GAPS

c_green() { printf '\033[32m%s\033[0m' "$1"; }
c_red()   { printf '\033[31m%s\033[0m' "$1"; }
c_yel()   { printf '\033[33m%s\033[0m' "$1"; }

ok()   { PASS=$((PASS+1)); printf '  [%s] %s\n' "$(c_green PASS)" "$1"; }
bad()  { FAIL=$((FAIL+1)); printf '  [%s] %s\n' "$(c_red FAIL)" "$1"; GAPS+=("$1"); }
warn() { DRIFT=$((DRIFT+1)); printf '  [%s] %s\n' "$(c_yel DRIFT)" "$1"; }
note() { printf '        %s\n' "$1"; }
hdr()  { printf '\n=== %s ===\n' "$1"; }

# sudo wrapper that never prompts interactively (returns empty on failure rather than hanging)
S() { sudo -n "$@" 2>/dev/null; }

printf '######################################################################\n'
printf '#  dev2 drift report  —  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')"
printf '#  read-only; expectations baked from dev1; origin/main=%s\n' "${EXPECT_MAIN:0:11}"
printf '######################################################################\n'

# ---------------------------------------------------------------------------
hdr "1. Host sanity"
MYIP="$(curl -s --max-time 5 ifconfig.me 2>/dev/null)"
note "public IP reported: ${MYIP:-<unknown>}"
if [ "$MYIP" = "50.19.198.38" ]; then
  printf '  [%s] This is dev1 (50.19.198.38), NOT dev2. ABORTING — run this ON dev2.\n' "$(c_red ABORT)"
  exit 2
elif [ "$MYIP" = "34.193.244.53" ]; then
  ok "host IP = 34.193.244.53 (dev2)"
else
  warn "host IP is '${MYIP:-unknown}', expected dev2 34.193.244.53 (IP may have changed on relaunch; continuing)"
fi
HN="$(hostname 2>/dev/null)"
case "$HN" in
  dev2*) ok "hostname = $HN" ;;
  *)     warn "hostname = '$HN' (expected dev2.loothgroup.com)" ;;
esac

# ---------------------------------------------------------------------------
hdr "2. Git clone ($CLONE)"
if [ -d "$CLONE/.git" ]; then
  BR="$(git -C "$CLONE" rev-parse --abbrev-ref HEAD 2>/dev/null)"
  HEAD="$(git -C "$CLONE" rev-parse HEAD 2>/dev/null)"
  ORIGIN="$(git -C "$CLONE" rev-parse origin/main 2>/dev/null)"
  DIRTY="$(git -C "$CLONE" status --porcelain 2>/dev/null | head -1)"
  [ "$BR" = "main" ] && ok "on branch main" || bad "branch is '${BR:-?}' (expected main — a lane branch silently loses main-only map work)"
  if [ "$HEAD" = "$EXPECT_MAIN" ]; then
    ok "HEAD == dev1 origin/main ($EXPECT_MAIN)"
  else
    bad "HEAD=${HEAD:-?} != dev1 origin/main $EXPECT_MAIN (run git fetch; compare)"
    note "local origin/main = ${ORIGIN:-?}"
  fi
  [ -z "$DIRTY" ] && ok "working tree clean" || warn "working tree DIRTY (first: $DIRTY)"
else
  bad "clone $CLONE missing or not a git repo"
fi

# ---------------------------------------------------------------------------
hdr "3. /srv symlinks resolve into the clone or projects"
check_link() {
  local name="$1" want="$2" p="/srv/$1" tgt
  if [ -L "$p" ]; then
    tgt="$(readlink -f "$p" 2>/dev/null)"
    if [ -n "$tgt" ] && [ -d "$tgt" ]; then
      case "$tgt" in
        *"$want"*) ok "/srv/$name -> $tgt" ;;
        *)         warn "/srv/$name -> $tgt (expected to contain '$want')" ;;
      esac
    else
      bad "/srv/$name -> $(readlink "$p" 2>/dev/null) (dangling — target missing)"
    fi
  elif [ -d "$p" ]; then
    warn "/srv/$name is a real dir, not a symlink (archive-poc is plain on dev2 by design; others should symlink)"
  else
    bad "/srv/$name missing"
  fi
}
# bb-mirror should resolve into the git clone; the rest into the clone or projects tree
check_link bb-mirror "looth-platform"
check_link archive-poc ""        # plain dir on dev2 by design
check_link profile-app ""
check_link events ""
check_link lg-shared ""

# ---------------------------------------------------------------------------
hdr "4. mu-plugins (17 canonical, served at $MU)"
# The dir is not ubuntu-readable without sudo; the glob fails silently otherwise.
MU_LIST="$(S ls -1 "$MU" 2>/dev/null)"
if [ -z "$MU_LIST" ]; then
  bad "cannot list $MU (missing, or sudo unavailable)"
else
  MISS=""
  for f in $CANON_MU; do
    if printf '%s\n' "$MU_LIST" | grep -qx "$f"; then :; else MISS="$MISS $f"; fi
  done
  if [ -z "$MISS" ]; then
    ok "all 17 canonical mu-plugins present"
  else
    bad "mu-plugins MISSING:$MISS"
  fi
  # Specifically called out by the brief:
  if printf '%s\n' "$MU_LIST" | grep -qx "looth-auth-issue.php"; then
    ok "looth-auth-issue.php present"
  else
    bad "looth-auth-issue.php MISSING (the /looth-auth/issue route handler)"
  fi
  if printf '%s\n' "$MU_LIST" | grep -qx "looth-vendor"; then
    ok "looth-vendor/ present"
  elif S test -d "$MU/looth-vendor"; then
    ok "looth-vendor/ present (dir)"
  else
    bad "looth-vendor/ MISSING"
  fi
fi

# ---------------------------------------------------------------------------
hdr "5. Poller (Stripe/Patreon onboard)  [note: parked lane — absence may be expected]"
if S test -d "$POLLER"; then
  ok "poller dir present"
  # active?
  if S test -f "$WPROOT/wp-config.php"; then
    ACT="$(S -u looth-dev wp --path="$WPROOT" plugin is-active lg-patreon-stripe-poller 2>/dev/null; echo "rc=$?")"
    case "$ACT" in
      *rc=0*) ok "poller plugin ACTIVE" ;;
      *)      warn "poller plugin not active (parked lane — may be intentional)" ;;
    esac
  fi
  OB="$POLLER/lg-patreon-onboard.php"
  if S test -f "$OB"; then
    N="$(S grep -cE 'patreon-password|wp_set_auth_cookie' "$OB" 2>/dev/null)"
    N="${N:-0}"
    if [ "$N" -ge 2 ] 2>/dev/null; then
      ok "onboard work present (markers=$N, dev1=3)"
    else
      bad "STALE poller onboard: markers=$N (<2) — lacks wp_set_auth_cookie / patreon-password redirect"
    fi
  else
    warn "lg-patreon-onboard.php absent"
  fi
else
  warn "poller NOT installed (per dev2 build checklist, Stripe is PARKED — likely expected; not a cut blocker)"
fi

# ---------------------------------------------------------------------------
hdr "6. Identity / auth routes (gated curl + nginx -T)"
# Grab the gate token from dev2's site conf at runtime (do not hardcode).
TOK="$(S grep -rhoE 'loothdev_token[^"]*"[A-Za-z0-9]+"' /etc/nginx/sites-available/ 2>/dev/null | grep -oE '"[A-Za-z0-9]+"' | tr -d '"' | head -1)"
if [ -z "$TOK" ]; then
  warn "could not grep gate token from /etc/nginx/sites-available — curls will likely 403"
else
  note "gate token found (len=${#TOK})"
fi
CURL="curl -s -o /dev/null -w %{http_code} --max-time 8 $RESOLVE --cookie loothdev_auth=$TOK"

code_auth=$($CURL "https://$HOSTHDR/looth-auth/issue?return=/front-page/" 2>/dev/null)
case "$code_auth" in
  302|303|301) ok "/looth-auth/issue?return=/front-page/ -> $code_auth (redirect, route live)" ;;
  404)         bad "/looth-auth/issue -> 404 (route MISSING — looth-auth-issue.php / nginx route)" ;;
  *)           warn "/looth-auth/issue -> ${code_auth:-no-response} (expected 302)" ;;
esac

code_pat=$($CURL "https://$HOSTHDR/patreon-connect/" 2>/dev/null)
case "$code_pat" in
  302|303|301) ok "/patreon-connect/ -> $code_pat (redirect)" ;;
  404)         warn "/patreon-connect/ -> 404 (poller parked — may be expected)" ;;
  *)           warn "/patreon-connect/ -> ${code_pat:-no-response} (expected 302)" ;;
esac

NGX="$(S nginx -T 2>/dev/null)"
if [ -z "$NGX" ]; then
  warn "nginx -T produced no output (sudo/nginx?) — skipping route-config check"
else
  if printf '%s' "$NGX" | grep -q 'profile-api/v0/internal'; then
    ok "nginx config carries /profile-api/v0/internal route"
  else
    bad "nginx config MISSING /profile-api/v0/internal (slug-lookup internal route)"
  fi
fi

# ---------------------------------------------------------------------------
hdr "7. Secrets (presence + mode/owner + ACLs; contents NEVER printed)"
sec_check() {
  local path="$1" want_owner="$2"
  if S test -e "$path"; then
    local meta; meta="$(S stat -c '%a %U:%G' "$path" 2>/dev/null)"
    ok "$path present ($meta)"
  else
    bad "$path MISSING"
  fi
}
sec_check /etc/looth/jwt-private.pem
sec_check /etc/looth/jwt-public.pem
sec_check /etc/lg-internal-secret
sec_check /etc/lg-membership-db

acl_has() {
  local path="$1" user="$2"
  if S test -e "$path"; then
    if S getfacl -p "$path" 2>/dev/null | grep -qE "^user:$user:r"; then
      ok "ACL: $user can read $path"
    else
      bad "ACL MISSING: setfacl -m u:$user:r $path (reader has no grant)"
    fi
  fi
}
acl_has /etc/looth/jwt-private.pem profile-app
acl_has /etc/lg-internal-secret profile-app
acl_has /etc/lg-membership-db membership

# ---------------------------------------------------------------------------
hdr "8. Data / grants (read-only checks)"
# Postgres front-page grant: archive-poc role USAGE on forums + SELECT on forums.topic
PG_FORUMS="$(S -u postgres psql -d looth -tAc "SELECT has_schema_privilege('archive-poc','forums','USAGE') AND has_table_privilege('archive-poc','forums.topic','SELECT') AND has_table_privilege('archive-poc','forums.forum','SELECT');" 2>/dev/null | tr -d '[:space:]')"
case "$PG_FORUMS" in
  t) ok "PG grant: archive-poc has forums USAGE + topic/forum SELECT (front-page row)" ;;
  f) bad "PG grant MISSING: archive-poc lacks forums USAGE/SELECT — front page 500s for members (tools/cut/forums-grant.sql)" ;;
  *) warn "PG forums-grant check inconclusive (psql unreachable / role missing)" ;;
esac

# profile_app sitemap grant: column-scoped — MUST use has_column_privilege, not table.
PG_SITEMAP="$(S -u postgres psql -d profile_app -tAc "SELECT has_database_privilege('archive-poc','profile_app','CONNECT') AND has_schema_privilege('archive-poc','public','USAGE') AND has_column_privilege('archive-poc','public.users','slug','SELECT');" 2>/dev/null | tr -d '[:space:]')"
case "$PG_SITEMAP" in
  t) ok "PG grant: archive-poc sitemap read on profile_app (CONNECT+USAGE+users.slug SELECT)" ;;
  f) bad "PG grant MISSING: sitemap profiles section empty (tools/cut/sitemap-grants.sql)" ;;
  *) warn "PG sitemap-grant check inconclusive (profile_app DB unreachable)" ;;
esac

# mysql lg_membership reachable
MY="$(S mysql -N -e "SELECT 1;" lg_membership 2>/dev/null | head -1)"
[ "$MY" = "1" ] && ok "mysql lg_membership reachable" || bad "mysql lg_membership NOT reachable (DB/creds)"

# ---------------------------------------------------------------------------
hdr "9. Host-pinned drift (dev. vs dev2. / loothgroup.com)"
# wp_posts content URLs still pointing at dev. (should be 0 after search-replace to dev2.)
if S test -f "$WPROOT/wp-config.php"; then
  DBNAME="$(S grep -oE "define\(\s*'DB_NAME'\s*,\s*'[^']+'" "$WPROOT/wp-config.php" 2>/dev/null | grep -oE "'[^']+'\$" | tr -d "'")"
  DBNAME="${DBNAME:-looth_import}"
  CNT_DEV="$(S mysql -N -e "SELECT COUNT(*) FROM wp_posts WHERE post_content LIKE '%dev.loothgroup.com%';" "$DBNAME" 2>/dev/null | head -1)"
  CNT_DEV2="$(S mysql -N -e "SELECT COUNT(*) FROM wp_posts WHERE post_content LIKE '%dev2.loothgroup.com%';" "$DBNAME" 2>/dev/null | head -1)"
  note "wp_posts ($DBNAME): dev.=${CNT_DEV:-?}  dev2.=${CNT_DEV2:-?}"
  if [ "${CNT_DEV:-x}" = "0" ]; then
    ok "no wp_posts content URLs pointing at dev.loothgroup.com (search-replace done — dev2-test hygiene)"
  elif [ -z "$CNT_DEV" ]; then
    warn "could not count wp_posts URLs (DB unreachable)"
  else
    warn "$CNT_DEV wp_posts still carry dev.loothgroup.com (dev2-test hygiene search-replace; THROWAWAY at cut — not a blocker)"
  fi
else
  warn "wp-config.php not readable — skipping wp_posts URL count"
fi

# bb-mirror _chrome.php logo host: LG_BB_MIRROR_ENV==='dev' ternary points at dev1's dev.loothgroup.com
CHROME="$CLONE/bb-mirror/web/_chrome.php"
if S test -f "$CHROME"; then
  if S grep -q "dev.loothgroup.com/wp-content/uploads/2024/05/Looth-Group-Logo" "$CHROME"; then
    warn "_chrome.php logo_url hardcodes dev.loothgroup.com when ENV=dev (line ~499) — header logo points at dev1; flag for cut"
  else
    ok "_chrome.php logo host not pinned to dev.loothgroup.com"
  fi
else
  warn "$CHROME not found (clone layout differs)"
fi

# any dev.loothgroup.com in served plugin files
PLUG_HITS="$(S grep -rl "dev.loothgroup.com" "$WPROOT/wp-content/plugins" 2>/dev/null | head -5)"
if [ -z "$PLUG_HITS" ]; then
  ok "no served plugin file references dev.loothgroup.com"
else
  warn "served plugin files reference dev.loothgroup.com:"
  printf '%s\n' "$PLUG_HITS" | while read -r l; do note "$l"; done
fi

# ---------------------------------------------------------------------------
hdr "10. FPM pool env (LG_*_ENV / LG_*_PUBLIC_HOST)  [dev2 NEEDS these; dev1 does not]"
POOLDIR="$(S bash -c 'ls -d /etc/php/*/fpm/pool.d 2>/dev/null | head -1')"
POOLDIR="${POOLDIR:-/etc/php/8.3/fpm/pool.d}"
pool_env() {
  local pool="$1" var="$2" conf="$POOLDIR/$pool.conf"
  if S test -f "$conf"; then
    if S grep -qE "^env\[$var\]" "$conf"; then
      ok "$pool: env[$var] set"
    else
      bad "$pool: env[$var] MISSING (dev2 host falls to 'live' branch / loopback fails-open)"
    fi
  else
    warn "pool conf $conf not found"
  fi
}
pool_env looth-dev  LG_BB_MIRROR_ENV
pool_env looth-dev  LG_ARCHIVE_POC_ENV
pool_env looth-dev  LG_EVENTS_ENV
pool_env bb-mirror  LG_BB_MIRROR_ENV
pool_env bb-mirror  LG_BB_MIRROR_PUBLIC_HOST
pool_env archive-poc LG_ARCHIVE_POC_ENV
pool_env archive-poc LG_ARCHIVE_POC_PUBLIC_HOST
pool_env events     LG_EVENTS_ENV
pool_env events     LG_EVENTS_PUBLIC_HOST
pool_env membership LG_MEMBERSHIP_ENV
# bb private REST option (BuddyBoss): brief expects 0 (open). dev1 = 1 + a public-content allowlist.
if S test -f "$WPROOT/wp-config.php"; then
  BBPR="$(S -u looth-dev wp --path="$WPROOT" option get bb-enable-private-rest-apis 2>/dev/null)"
  if [ "$BBPR" = "0" ]; then
    ok "bb-enable-private-rest-apis = 0 (REST open)"
  elif [ -z "$BBPR" ]; then
    warn "bb-enable-private-rest-apis not set / wp unreachable"
  else
    warn "bb-enable-private-rest-apis = $BBPR (brief expects 0; dev1 runs 1 WITH a looth public-content allowlist — verify the allowlist exists, see project_whoami)"
  fi
fi

# ---------------------------------------------------------------------------
hdr "11. Cut-critical gotchas (these live in no config)"
# /home/ubuntu must be o+x (0751) so FPM pools traverse into /srv symlinks
HMODE="$(stat -c '%a' /home/ubuntu 2>/dev/null)"
case "$HMODE" in
  751|755|711) ok "/home/ubuntu mode $HMODE (others have +x → apps can traverse)" ;;
  *)           bad "/home/ubuntu mode $HMODE (needs o+x / 0751 — else every FPM pool 403s)" ;;
esac

# www-data in looth-dev group (nginx must traverse wp-content 2770)
if id www-data 2>/dev/null | grep -q 'looth-dev'; then
  ok "www-data is in the looth-dev group (nginx can traverse wp-content)"
else
  bad "www-data NOT in looth-dev group (usermod -aG looth-dev www-data) — images/uploads 403"
fi

# uploads must be a symlink to the R2 mount, not a real dir
UP="$WPROOT/wp-content/uploads"
if S test -L "$UP"; then
  UPT="$(S readlink -f "$UP" 2>/dev/null)"
  ok "wp-content/uploads is a symlink -> ${UPT:-?}"
else
  if S test -d "$UP"; then
    bad "wp-content/uploads is a REAL dir, not a symlink to the R2 mount (rm -rf + ln -sfn /mnt/loothgroup-uploads)"
  else
    warn "wp-content/uploads not found"
  fi
fi

# ---------------------------------------------------------------------------
TOTAL=$((PASS+FAIL+DRIFT))
printf '\n######################################################################\n'
printf '#  SUMMARY:  %s aligned · %s drift · %s failed   (of %s checks)\n' \
  "$(c_green "$PASS")" "$(c_yel "$DRIFT")" "$(c_red "$FAIL")" "$TOTAL"
printf '######################################################################\n'
if [ "$FAIL" -gt 0 ]; then
  printf '\nBIGGEST GAPS (FAILs — fix before the cut):\n'
  for g in "${GAPS[@]}"; do printf '  - %s\n' "$g"; done
else
  printf '\nNo hard FAILs. Review any DRIFT lines above (host-pinned / cut-time items).\n'
fi
printf '\n(DRIFT = expected-at-cut or informational; FAIL = real gap vs dev1 known-good.)\n'
