<?php
// Mint a REAL WP session for a screenshot run — wp_generate_auth_cookie alone is
// not enough: WP validates the session TOKEN against user meta, so the token has
// to be registered through WP_Session_Tokens or every request reads as logged out.
$uid = (int)getenv("LG_SHOT_UID");   // wp-cli owns $argv; an env var is unambiguous
$u = get_user_by('id', $uid);
if (!$u) { fwrite(STDERR, "no such user\n"); exit(1); }
$exp = time() + 3600;
$manager = WP_Session_Tokens::get_instance($uid);
$token = $manager->create($exp);
echo json_encode([
  'user'   => $u->user_login,
  'name'   => $u->display_name,
  'cookie' => LOGGED_IN_COOKIE,
  'value'  => wp_generate_auth_cookie($uid, $exp, 'logged_in', $token),
]), "\n";
