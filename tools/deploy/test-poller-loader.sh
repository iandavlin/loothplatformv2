#!/usr/bin/env bash
# TEST — is the poller mu-plugin loader SYMLINK-SAFE?
#
#   bash tools/deploy/test-poller-loader.sh [path/to/lg-patreon-stripe-poller.php]
#
# ─── THE OUTAGE THIS ENCODES, 2026-07-31 ────────────────────────────────────────
# WordPress only auto-loads .php in the mu-plugins ROOT, so folder-structured plugins
# ship a thin loader that requires its code from a sibling directory. The loader used
#     $lgpo_dir = __DIR__ . '/lg-patreon-stripe-poller';
#
# PHP RESOLVES SYMLINKS BEFORE COMPUTING __DIR__. So when symlink-farm converted the
# loader itself into a symlink into the repo, __DIR__ stopped being the docroot and
# became the repo. The loader could not read its main file, and — because it
# `return`s rather than fatalling — the plugin was simply NEVER REGISTERED. No fatal,
# no 500, no error page. The poller's REST route did not exist, whoami could not read
# member tiers, every member and admin computed as `public`, and the site paywalled
# itself.
#
# Scenario B below is that exact configuration. It must PASS, or the trap is still
# live and one `symlink-farm --apply` away from repeating.
#
# Runs entirely in a mktemp dir against a stubbed WordPress. Touches no real box.
set -uo pipefail

LOADER="${1:-$(cd "$(dirname "$0")/../.." && pwd)/lg-patreon-stripe-poller.php}"
[ -r "$LOADER" ] || { echo "cannot read loader: $LOADER" >&2; exit 2; }

T="$(mktemp -d /tmp/lgd-loader.XXXXXX)"
trap 'rm -rf "$T"' EXIT
pass=0; fail=0

# Stubs just enough WordPress for the loader to run, then reports whether the real
# main file was reached. The stub main file sets a sentinel; absence of the sentinel
# is exactly the silent non-registration that took the site down.
make_wp_stub() {
  cat > "$T/wp-stub.php" <<'PHP'
<?php
define('ABSPATH', '/fake/wp/');
define('WPMU_PLUGIN_DIR', getenv('STUB_MU'));
function trailingslashit($s){ return rtrim($s, '/\\') . '/'; }
function plugins_url($p='', $f=''){ return 'https://example.test/mu/' . ltrim($p,'/'); }
$GLOBALS['LOADED_FROM'] = null;
require getenv('STUB_LOADER');
echo ($GLOBALS['LOADED_FROM'] === null) ? "NOT-LOADED\n" : ("LOADED:" . $GLOBALS['LOADED_FROM'] . "\n");
PHP
}

# Builds a scenario and returns the loader's verdict on stdout.
#   $1 = scenario name
#   $2 = "realfile" | "symlink"   — how the LOADER is deployed into the docroot
#   $3 = "both" | "docroot-only"  — where the CODE FOLDER exists
run_scenario() {
  local name="$1" how="$2" where="$3"
  local root="$T/$name"; rm -rf "$root"
  mkdir -p "$root/docroot/mu-plugins" "$root/repo"

  # The code folder + a stub main file that records that it was reached.
  mk_code() { mkdir -p "$1/lg-patreon-stripe-poller"
              printf '<?php $GLOBALS["LOADED_FROM"] = %s;\n' "'$2'" \
                > "$1/lg-patreon-stripe-poller/lg-patreon-onboard.php"; }
  mk_code "$root/docroot/mu-plugins" docroot
  [ "$where" = both ] && mk_code "$root/repo" repo

  cp "$LOADER" "$root/repo/lg-patreon-stripe-poller.php"
  if [ "$how" = symlink ]; then
    ln -s "$root/repo/lg-patreon-stripe-poller.php" "$root/docroot/mu-plugins/lg-patreon-stripe-poller.php"
  else
    cp "$LOADER" "$root/docroot/mu-plugins/lg-patreon-stripe-poller.php"
  fi

  STUB_MU="$root/docroot/mu-plugins" \
  STUB_LOADER="$root/docroot/mu-plugins/lg-patreon-stripe-poller.php" \
    php "$T/wp-stub.php" 2>/dev/null | tail -1
}

expect() { # expect <name> <how> <where> <expected-prefix> <why>
  local got; got="$(run_scenario "$1" "$2" "$3")"
  if [[ "$got" == "$4"* ]]; then
    printf '  ✔ %-46s %s\n' "$5" "$got"; pass=$((pass+1))
  else
    printf '  ❌ %-46s got %s, wanted %s*\n' "$5" "${got:-<nothing>}" "$4"; fail=$((fail+1))
  fi
}

make_wp_stub
echo "testing loader: $LOADER"
echo

# A — how live is deployed today (Ian's restore). Must keep working.
expect A realfile both        "LOADED:docroot" "A real-file loader loads its code"

# B — THE OUTAGE. Loader symlinked into the repo, code folder present ONLY in the
#     docroot. With __DIR__ this resolves into the repo, finds nothing, and returns
#     silently. This is the scenario that paywalled the site.
expect B symlink  docroot-only "LOADED:docroot" "SYMLINKED loader still finds docroot code"

# C — loader symlinked and the folder exists in BOTH trees. Whichever it picks it
#     must be the DOCROOT copy: that is the tree WordPress deployed and the only one
#     guaranteed to carry box-local files (vendor/, .env).
expect C symlink  both         "LOADED:docroot" "SYMLINKED loader prefers the DOCROOT tree"

echo
echo "═══════════════════════════════════════════"
echo " $pass passed, $fail failed"
[ $fail -eq 0 ] || exit 1
