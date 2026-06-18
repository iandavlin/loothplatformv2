#!/bin/bash
# test-wp-minter-sub.sh — ACCEPTANCE test for Decision 2 option (b).
#
# Asserts the LIVE WP minter (profile-auth.php) sets the looth_id `sub` to the
# STORED users.uuid (via wp_user_bridge), NOT to UUIDv5(current_email). It drives
# the real WP minter with an in-memory email OVERRIDE so the email-recompute bug
# manifests even for a member who never changed their address — making the test
# RED on the buggy code and GREEN once option (b) lands. No data is mutated: the
# override lives only inside the eval; the user's real email is untouched.
#
# Runs as a sudo-capable user (the gate runner / ubuntu): it shells to
# `sudo -u www-data wp eval` (WP mint) and `sudo -u profile-app psql`
# (stored uuid). Counterpart to the in-process contract guard in
# bin/test-identity.php.
set -uo pipefail
WP_PATH=/var/www/dev

# A real bridged user (defaults to the lowest bridged wp_user_id present in WP).
WP_USER_ID="${1:-$(sudo -u profile-app psql -d profile_app -tAc \
  "SELECT b.wp_user_id FROM wp_user_bridge b ORDER BY b.wp_user_id LIMIT 1" | tr -d '[:space:]')}"
if [ -z "$WP_USER_ID" ]; then echo "FAIL: no bridged user to test"; exit 1; fi

STORED_UUID=$(sudo -u profile-app psql -d profile_app -tAc \
  "SELECT lower(u.uuid::text) FROM users u JOIN wp_user_bridge b ON b.user_id=u.id \
   WHERE b.wp_user_id=${WP_USER_ID}" | tr -d '[:space:]')
if [ -z "$STORED_UUID" ]; then echo "FAIL: wp_user_id ${WP_USER_ID} not bridged"; exit 1; fi

# Mint via the real WP minter with an email the stored uuid was NOT seeded from.
OVERRIDE_EMAIL="acceptance-override-${WP_USER_ID}@example.invalid"
TOKEN=$(sudo -u www-data wp --path="$WP_PATH" eval "
  \$u = new WP_User(${WP_USER_ID});
  if (!\$u || !\$u->ID) { fwrite(STDERR, 'no such WP user'); exit(2); }
  \$u->user_email = '${OVERRIDE_EMAIL}';          // in-memory only, never saved
  echo looth_auth_mint_jwt(\$u);
" 2>/dev/null)
if [ -z "$TOKEN" ]; then echo "FAIL: WP minter produced no token"; exit 1; fi

# Decode the JWT payload (middle segment) and read `sub`.
MINTED_SUB=$(php -r '
  $p = explode(".", $argv[1]);
  $b = $p[1] ?? "";
  $b = strtr($b, "-_", "+/");
  $j = json_decode(base64_decode($b . str_repeat("=", (4 - strlen($b) % 4) % 4)), true);
  echo strtolower((string)($j["sub"] ?? ""));
' "$TOKEN")

echo "wp_user_id=${WP_USER_ID}"
echo "  stored uuid (bridge) : ${STORED_UUID}"
echo "  minted sub (WP token): ${MINTED_SUB}"
if [ "$MINTED_SUB" = "$STORED_UUID" ]; then
  echo "[OK] WP minter sub == stored uuid — email change cannot drift sub (option (b) landed)"
  exit 0
fi
echo "[FAIL] WP minter sub != stored uuid — sub is email-derived; option (b) NOT landed"
echo "       (expected once profile-auth.php resolves sub via the bridge / uuid mirror)"
exit 1
